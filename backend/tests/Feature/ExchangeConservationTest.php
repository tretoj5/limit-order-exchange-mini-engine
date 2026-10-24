<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Concerns\AssertsExchangeConservation;
use Tests\TestCase;

class ExchangeConservationTest extends TestCase
{
    use AssertsExchangeConservation;
    use DatabaseMigrations;

    public function test_usd_and_asset_totals_are_conserved_through_matching_and_cancellation(): void
    {
        $seller = $this->createUser('conservation-seller@example.test');
        $buyer = $this->createUser('conservation-buyer@example.test', '200000.00000000');
        $trader = $this->createUser('conservation-trader@example.test');
        $totalsBefore = $this->exchangeTotals();

        $this->withUserToken($seller)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side' => 'sell',
                'price' => '95000',
                'amount' => '1',
            ])
            ->assertCreated();
        $this->assertExchangeTotalsUnchanged($totalsBefore);

        $this->withUserToken($buyer)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side' => 'buy',
                'price' => '100000',
                'amount' => '1',
            ])
            ->assertCreated()
            ->assertJsonPath('trade.fee', '1425.00000000');
        $this->assertExchangeTotalsUnchanged($totalsBefore);

        $traderToken = $trader->createToken('api')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $openBuy = $this->withToken($traderToken)
            ->postJson('/api/orders', [
                'symbol' => 'ETH',
                'side' => 'buy',
                'price' => '1000',
                'amount' => '1',
            ])
            ->assertCreated()
            ->json('order.id');
        $this->assertExchangeTotalsUnchanged($totalsBefore);

        $this->app['auth']->forgetGuards();
        $this->withToken($traderToken)
            ->postJson("/api/orders/{$openBuy}/cancel")
            ->assertOk();
        $this->assertExchangeTotalsUnchanged($totalsBefore);
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

    private function withUserToken(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('api')->plainTextToken);
    }
}
