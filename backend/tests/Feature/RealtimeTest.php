<?php

namespace Tests\Feature;

use App\Events\OrderMatched;
use App\Events\MarketDepthUpdated;
use App\Models\Asset;
use App\Models\Order;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RealtimeTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        putenv('BROADCAST_CONNECTION=pusher');
        $_ENV['BROADCAST_CONNECTION'] = 'pusher';
        $_SERVER['BROADCAST_CONNECTION'] = 'pusher';

        parent::setUp();
        config(['queue.default' => 'database']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        putenv('BROADCAST_CONNECTION=null');
        $_ENV['BROADCAST_CONNECTION'] = 'null';
        $_SERVER['BROADCAST_CONNECTION'] = 'null';
    }

    public function test_match_is_queued_after_commit_for_each_party_with_private_user_payloads(): void
    {
        $buyer = $this->createUser('buyer@example.test');
        $seller = $this->createUser('seller@example.test');
        config(['queue.default' => 'database']);

        DB::beginTransaction();

        $this->withToken($seller->createToken('api')->plainTextToken)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side' => 'sell',
                'price' => '95000',
                'amount' => '0.01',
            ])
            ->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->withToken($buyer->createToken('api')->plainTextToken)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side' => 'buy',
                'price' => '95000',
                'amount' => '0.01',
            ])
            ->assertCreated();

        $this->assertSame(0, DB::table('jobs')->count());

        DB::commit();

        $this->assertSame(4, DB::table('jobs')->count());
        $events = DB::table('jobs')
            ->orderBy('id')
            ->get()
            ->map(function (object $row): object {
                $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                $job = unserialize($payload['data']['command'], ['allowed_classes' => true]);

                $this->assertInstanceOf(BroadcastEvent::class, $job);

                return $job->event;
            });
        $marketEvents = $events->filter(fn (object $event): bool => $event instanceof MarketDepthUpdated);
        $this->assertCount(2, $marketEvents);
        foreach ($marketEvents as $marketEvent) {
            $this->assertTrue($marketEvent->afterCommit);
            $this->assertSame('MarketDepthUpdated', $marketEvent->broadcastAs());
            $this->assertSame('private-market.BTC', $marketEvent->broadcastOn()[0]->name);
            $this->assertSame(['symbol' => 'BTC'], $marketEvent->broadcastWith());
        }

        $partyEvents = $events
            ->filter(fn (object $event): bool => $event instanceof OrderMatched)
            ->sortBy('userId')
            ->values();

        $this->assertSame([$buyer->id, $seller->id], $partyEvents->pluck('userId')->all());

        $buyerEvent = $partyEvents->firstWhere('userId', $buyer->id);
        $sellerEvent = $partyEvents->firstWhere('userId', $seller->id);

        $this->assertTrue($buyerEvent->afterCommit);
        $this->assertTrue($sellerEvent->afterCommit);
        $this->assertSame('OrderMatched', $buyerEvent->broadcastAs());
        $this->assertSame('private-user.'.$buyer->id, $buyerEvent->broadcastOn()[0]->name);
        $this->assertSame('private-user.'.$seller->id, $sellerEvent->broadcastOn()[0]->name);
        $this->assertSame([
            'trade' => [
                'id' => 1,
                'symbol' => 'BTC',
                'price' => '95000.00000000',
                'amount' => '0.01000000',
                'usd_volume' => '950.00000000',
                'fee' => '14.25000000',
                'side' => 'buy',
            ],
            'order' => [
                'id' => 2,
                'symbol' => 'BTC',
                'side' => 'buy',
                'price' => '95000.00000000',
                'amount' => '0.01000000',
                'status' => 2,
                'created_at' => Order::query()->findOrFail(2)->created_at?->toISOString(),
            ],
            'balance' => '99035.75000000',
            'assets' => [
                ['symbol' => 'BTC', 'amount' => '1.01000000', 'locked_amount' => '0.00000000'],
            ],
        ], $buyerEvent->broadcastWith());
        $this->assertSame('sell', $sellerEvent->broadcastWith()['trade']['side']);
        $this->assertSame(
            ['id', 'symbol', 'side', 'price', 'amount', 'status', 'created_at'],
            array_keys($sellerEvent->broadcastWith()['order']),
        );
        $this->assertArrayNotHasKey('buyer_id', $sellerEvent->broadcastWith()['trade']);
        $this->assertArrayNotHasKey('seller_id', $buyerEvent->broadcastWith()['trade']);
    }

    public function test_unmatched_order_broadcasts_market_depth_update_after_commit(): void
    {
        $user = $this->createUser('resting@example.test');
        config(['queue.default' => 'database']);

        $this->withToken($user->createToken('api')->plainTextToken)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side' => 'buy',
                'price' => '95000',
                'amount' => '0.01',
            ])
            ->assertCreated();

        $this->assertSame(1, DB::table('jobs')->count());
        $payload = json_decode(DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        $job = unserialize($payload['data']['command'], ['allowed_classes' => true]);

        $this->assertInstanceOf(BroadcastEvent::class, $job);
        $this->assertInstanceOf(MarketDepthUpdated::class, $job->event);
        $this->assertTrue($job->event->afterCommit);
        $this->assertSame('private-market.BTC', $job->event->broadcastOn()[0]->name);
    }

    public function test_rolled_back_match_does_not_queue_broadcasts(): void
    {
        $buyer = $this->createUser('rollback-buyer@example.test');
        $seller = $this->createUser('rollback-seller@example.test');
        config(['queue.default' => 'database']);

        DB::beginTransaction();

        $this->withToken($seller->createToken('api')->plainTextToken)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side' => 'sell',
                'price' => '95000',
                'amount' => '0.01',
            ])
            ->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->withToken($buyer->createToken('api')->plainTextToken)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side' => 'buy',
                'price' => '95000',
                'amount' => '0.01',
            ])
            ->assertCreated();

        $this->assertSame(0, DB::table('jobs')->count());
        DB::rollBack();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('trades')->count());
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_broadcast_channel_authenticates_only_its_user(): void
    {
        $user = $this->createUser('channel-owner@example.test');
        $otherUser = $this->createUser('channel-other@example.test');

        $response = $this->withToken($user->createToken('api')->plainTextToken)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-user.'.$user->id,
            ]);
        $response->assertOk()
            ->assertJsonStructure(['auth']);

        $this->app['auth']->forgetGuards();

        $this->withToken($otherUser->createToken('api')->plainTextToken)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-user.'.$user->id,
            ])
            ->assertForbidden();
    }

    public function test_authenticated_users_can_subscribe_to_supported_market_depth_channels(): void
    {
        $user = $this->createUser('market-listener@example.test');

        $this->withToken($user->createToken('api')->plainTextToken)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-market.BTC',
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->withToken($user->createToken('api')->plainTextToken)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-market.UNKNOWN',
            ])
            ->assertForbidden();
    }

    public function test_cancelling_an_open_order_broadcasts_market_depth_update(): void
    {
        $user = $this->createUser('market-cancel@example.test');
        $token = $user->createToken('api')->plainTextToken;

        $orderId = $this->withToken($token)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side' => 'sell',
                'price' => '95000',
                'amount' => '0.01',
            ])
            ->assertCreated()
            ->json('order.id');

        DB::table('jobs')->delete();

        $this->withToken($token)
            ->postJson("/api/orders/{$orderId}/cancel")
            ->assertOk();

        $this->assertSame(1, DB::table('jobs')->count());
        $payload = json_decode(DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        $job = unserialize($payload['data']['command'], ['allowed_classes' => true]);

        $this->assertInstanceOf(BroadcastEvent::class, $job);
        $this->assertInstanceOf(MarketDepthUpdated::class, $job->event);
        $this->assertSame(['symbol' => 'BTC'], $job->event->broadcastWith());
    }

    private function createUser(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'balance' => '100000.00000000',
        ]);

        Asset::query()->create([
            'user_id' => $user->id,
            'symbol' => 'BTC',
            'amount' => '1.00000000',
            'locked_amount' => '0.00000000',
        ]);

        return $user;
    }
}
