<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Asset;
use App\Models\Order;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Concerns\AssertsExchangeConservation;
use Tests\TestCase;

class OrderConcurrencyTest extends TestCase
{
    use AssertsExchangeConservation;
    use DatabaseMigrations;

    public function test_parallel_buy_orders_cannot_double_fill_one_sell_order(): void
    {
        $seller = $this->createUser('race-seller@example.test');
        $firstBuyer = $this->createUser('race-buyer-one@example.test');
        $secondBuyer = $this->createUser('race-buyer-two@example.test');
        $totalsBefore = $this->exchangeTotals();

        $restingSell = $this->withToken($seller->createToken('api')->plainTextToken)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side' => 'sell',
                'price' => '95000',
                'amount' => '1',
            ])
            ->assertCreated()
            ->json('order.id');

        $responses = $this->runConcurrentRequests([
            $this->request($firstBuyer, 'buy'),
            $this->request($secondBuyer, 'buy'),
        ]);

        $this->assertSame([201, 201], array_column($responses, 'status'));
        $this->assertSame(1, Trade::query()->count());
        $this->assertSame(
            OrderStatus::Filled,
            Order::query()->findOrFail($restingSell)->status,
        );
        $buyOrders = Order::query()->where('side', 'buy')->orderBy('id')->get();
        $this->assertSame(2, $buyOrders->count());
        $this->assertSame(
            [OrderStatus::Filled, OrderStatus::Open],
            $buyOrders->pluck('status')->all(),
        );
        $this->assertNoNegativeBalances();
        $this->assertExchangeTotalsUnchanged($totalsBefore);
    }

    public function test_parallel_orders_cannot_overdraw_a_users_usd_balance(): void
    {
        $buyer = $this->createUser('race-shared-buyer@example.test');
        $totalsBefore = $this->exchangeTotals();

        $responses = $this->runConcurrentRequests([
            $this->request($buyer, 'buy', '70000'),
            $this->request($buyer, 'buy', '70000'),
        ]);
        $statuses = array_column($responses, 'status');
        sort($statuses);

        $this->assertSame([201, 422], $statuses);
        $this->assertSame(1, Order::query()->where('user_id', $buyer->id)->count());
        $this->assertSame('28950.00000000', $buyer->fresh()->balance);
        $this->assertNoNegativeBalances();
        $this->assertExchangeTotalsUnchanged($totalsBefore);
    }

    public function test_cancel_and_match_race_has_one_valid_outcome(): void
    {
        $seller = $this->createUser('race-cancel-seller@example.test');
        $buyer = $this->createUser('race-cancel-buyer@example.test');
        $totalsBefore = $this->exchangeTotals();

        $restingSell = $this->withToken($seller->createToken('api')->plainTextToken)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side' => 'sell',
                'price' => '95000',
                'amount' => '1',
            ])
            ->assertCreated()
            ->json('order.id');
        $responses = $this->runConcurrentRequests([
            $this->request($seller, 'cancel', orderId: $restingSell),
            $this->request($buyer, 'buy'),
        ]);

        $cancelResponse = $responses[0];
        $buyResponse = $responses[1];
        $this->assertSame(201, $buyResponse['status']);

        if ($cancelResponse['status'] === 200) {
            $this->assertSame(OrderStatus::Cancelled, Order::query()->findOrFail($restingSell)->status);
            $this->assertSame(0, Trade::query()->count());
            $this->assertSame(OrderStatus::Open->value, $buyResponse['body']['order']['status']);
        } else {
            $this->assertSame(422, $cancelResponse['status']);
            $this->assertSame(OrderStatus::Filled, Order::query()->findOrFail($restingSell)->status);
            $this->assertSame(1, Trade::query()->count());
            $this->assertSame(OrderStatus::Filled->value, $buyResponse['body']['order']['status']);
        }

        $this->assertNoNegativeBalances();
        $this->assertExchangeTotalsUnchanged($totalsBefore);
    }

    /**
     * @return array{token: string, body: array<string, string>, uri: string}
     */
    private function request(User $user, string $side, string $price = '95000', ?int $orderId = null): array
    {
        return [
            'token' => $user->createToken('concurrency-test')->plainTextToken,
            'body' => $orderId === null ? [
                'symbol' => 'BTC',
                'side' => $side,
                'price' => $price,
                'amount' => '1',
            ] : [],
            'uri' => $orderId === null ? '/api/orders' : "/api/orders/{$orderId}/cancel",
        ];
    }

    /**
     * @param  array<int, array{token: string, body: array<string, string>, uri: string}>  $requests
     * @return array<int, array{status: int, body: array<string, mixed>}>
     */
    private function runConcurrentRequests(array $requests): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'exchange-race-');
        if ($barrier === false) {
            $this->fail('Could not create a concurrency-test barrier file.');
        }
        unlink($barrier);

        $runner = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
Illuminate\Support\Facades\DB::connection()->getPdo();
$deadline = microtime(true) + 20;
while (! is_file($argv[1])) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Timed out waiting for the concurrency barrier.");
        exit(2);
    }
    usleep(1000);
}
$request = Illuminate\Http\Request::create(
    $payload['uri'],
    'POST',
    [],
    [],
    [],
    [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$payload['token'],
        'CONTENT_TYPE' => 'application/json',
    ],
    json_encode($payload['body'], JSON_THROW_ON_ERROR),
);
$response = $kernel->handle($request);
echo json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode($response->getContent(), true),
], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
PHP;

        $database = config('database.connections.pgsql');
        $environment = [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'BROADCAST_CONNECTION' => 'null',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $database['host'],
            'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => (string) $database['database'],
            'DB_USERNAME' => (string) $database['username'],
            'DB_PASSWORD' => (string) $database['password'],
            'DB_URL' => '',
            'QUEUE_CONNECTION' => 'database',
            'SESSION_DRIVER' => 'array',
        ];
        $children = [];

        try {
            foreach ($requests as $payload) {
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, '-r', $runner, $barrier],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    base_path(),
                    $environment,
                );

                if (! is_resource($process)) {
                    $this->fail('Could not start a concurrent request process.');
                }

                $children[] = [$process, $pipes];
                $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
                if (fwrite($pipes[0], $encodedPayload) !== strlen($encodedPayload)) {
                    $this->fail('Could not send a request to a concurrent process.');
                }
                fclose($pipes[0]);
            }

            if (file_put_contents($barrier, 'start') === false) {
                $this->fail('Could not release the concurrency-test barrier.');
            }

            $responses = [];
            foreach ($children as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = proc_close($process);
                $this->assertSame(0, $exitCode, $stderr.$stdout);
                $responses[] = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
            }

            return $responses;
        } finally {
            foreach ($children as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    foreach ($pipes as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    proc_close($process);
                }
            }
            if (is_file($barrier)) {
                unlink($barrier);
            }
        }
    }

    private function assertNoNegativeBalances(): void
    {
        $this->assertSame(0, User::query()->where('balance', '<', '0')->count());
        $this->assertSame(0, Asset::query()
            ->where(fn ($query) => $query->where('amount', '<', '0')->orWhere('locked_amount', '<', '0'))
            ->count());
    }

    private function createUser(string $email, string $balance = '100000.00000000'): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'balance' => $balance,
        ]);

        foreach (['BTC' => '10.00000000', 'ETH' => '100.00000000'] as $symbol => $amount) {
            Asset::query()->create([
                'user_id' => $user->id,
                'symbol' => $symbol,
                'amount' => $amount,
                'locked_amount' => '0.00000000',
            ]);
        }

        return $user;
    }
}
