<?php

namespace Tests\Feature;

use App\Events\OrderMatched;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RealtimeTest extends TestCase
{
    use DatabaseMigrations;

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

        $this->assertSame(2, DB::table('jobs')->count());
        $events = DB::table('jobs')
            ->orderBy('id')
            ->get()
            ->map(function (object $row): OrderMatched {
                $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                $job = unserialize($payload['data']['command'], ['allowed_classes' => true]);

                $this->assertInstanceOf(BroadcastEvent::class, $job);

                return $job->event;
            })
            ->sortBy('userId')
            ->values();

        $this->assertSame([$buyer->id, $seller->id], $events->pluck('userId')->all());

        $buyerEvent = $events->firstWhere('userId', $buyer->id);
        $sellerEvent = $events->firstWhere('userId', $seller->id);

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
            'order' => ['id' => 2, 'status' => 2],
            'balance' => '99035.75000000',
            'assets' => [
                ['symbol' => 'BTC', 'amount' => '1.01000000', 'locked_amount' => '0.00000000'],
            ],
        ], $buyerEvent->broadcastWith());
        $this->assertSame('sell', $sellerEvent->broadcastWith()['trade']['side']);
        $this->assertSame(['id', 'status'], array_keys($sellerEvent->broadcastWith()['order']));
        $this->assertArrayNotHasKey('buyer_id', $sellerEvent->broadcastWith()['trade']);
        $this->assertArrayNotHasKey('seller_id', $buyerEvent->broadcastWith()['trade']);
    }

    public function test_unmatched_order_does_not_queue_a_broadcast(): void
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

        $this->assertSame(0, DB::table('jobs')->count());
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
