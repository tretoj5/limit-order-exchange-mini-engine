<?php

namespace Tests\Feature;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Models\Asset;
use App\Models\Order;
use App\Models\Trade;
use App\Models\User;
use App\Services\MatchingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    protected function createUserWithBalanceAndAssets(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'balance' => '100000.00000000',
        ], $overrides));

        foreach (['BTC' => '10.00000000', 'ETH' => '100.00000000'] as $symbol => $amount) {
            Asset::create([
                'user_id' => $user->id,
                'symbol' => $symbol,
                'amount' => $amount,
                'locked_amount' => '0.00000000',
            ]);
        }

        return $user;
    }

    private function withSanctumToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_place_buy_order_reserves_usd_including_fee(): void
    {
        $user = $this->createUserWithBalanceAndAssets();

        $token = $user->createToken('test')->plainTextToken;

        $this->withSanctumToken($token)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side' => 'buy',
                'price' => '50000.00000000',
                'amount' => '1.00000000',
            ])
            ->assertCreated()
            ->assertJsonStructure([
                'order' => ['id', 'symbol', 'side', 'price', 'amount', 'status', 'created_at'],
            ])
            ->assertJsonPath('trade', null);

        $user->refresh();
        // Hold = 1 * 50000 * 1.015 = 50750
        $this->assertSame('49250.00000000', $user->balance);
    }

    public function test_place_buy_order_rejects_insufficient_balance(): void
    {
        $user = $this->createUserWithBalanceAndAssets(['balance' => '1000.00000000']);

        $token = $user->createToken('test')->plainTextToken;

        $this->withSanctumToken($token)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side' => 'buy',
                'price' => '50000.00000000',
                'amount' => '1.00000000',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['balance']);

        $user->refresh();
        $this->assertSame('1000.00000000', $user->balance);
    }

    public function test_place_sell_order_locks_asset_amount(): void
    {
        $user = $this->createUserWithBalanceAndAssets();

        $token = $user->createToken('test')->plainTextToken;

        $this->withSanctumToken($token)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side' => 'sell',
                'price' => '50000.00000000',
                'amount' => '1.00000000',
            ])
            ->assertCreated()
            ->assertJsonStructure([
                'order' => ['id', 'symbol', 'side', 'price', 'amount', 'status', 'created_at'],
            ])
            ->assertJsonPath('trade', null);

        $asset = Asset::where('user_id', $user->id)->where('symbol', 'BTC')->firstOrFail();
        $this->assertSame('9.00000000', $asset->amount);
        $this->assertSame('1.00000000', $asset->locked_amount);
    }

    public function test_place_sell_order_rejects_insufficient_asset(): void
    {
        $user = $this->createUserWithBalanceAndAssets();

        $token = $user->createToken('test')->plainTextToken;

        $this->withSanctumToken($token)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side' => 'sell',
                'price' => '50000.00000000',
                'amount' => '20.00000000',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['asset']);

        $asset = Asset::where('user_id', $user->id)->where('symbol', 'BTC')->firstOrFail();
        $this->assertSame('10.00000000', $asset->amount);
        $this->assertSame('0.00000000', $asset->locked_amount);
    }

    public function test_place_order_validates_symbol(): void
    {
        $user = $this->createUserWithBalanceAndAssets();
        $token = $user->createToken('test')->plainTextToken;

        $this->withSanctumToken($token)
            ->postJson('/api/orders', [
                'symbol' => 'DOGE',
                'side' => 'buy',
                'price' => '1.00000000',
                'amount' => '1000.00000000',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['symbol']);
    }

    public function test_place_order_validates_side(): void
    {
        $user = $this->createUserWithBalanceAndAssets();
        $token = $user->createToken('test')->plainTextToken;

        $this->withSanctumToken($token)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side' => 'hold',
                'price' => '50000.00000000',
                'amount' => '1.00000000',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['side']);
    }

    public function test_place_order_validates_positive_price_and_amount(): void
    {
        $user = $this->createUserWithBalanceAndAssets();
        $token = $user->createToken('test')->plainTextToken;

        $this->withSanctumToken($token)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side' => 'buy',
                'price' => '0',
                'amount' => '1.00000000',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price']);

        $this->withSanctumToken($token)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side' => 'buy',
                'price' => '50000.00000000',
                'amount' => '0',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_orderbook_returns_open_orders_sorted_by_price_time(): void
    {
        $buyer = $this->createUserWithBalanceAndAssets([
            'email' => 'buyer@test',
            'balance' => '1000000.00000000',
        ]);
        $seller = $this->createUserWithBalanceAndAssets(['email' => 'seller@test', 'balance' => '50000.00000000']);

        // Place multiple buy orders at different prices
        $buyerToken = $buyer->createToken('test')->plainTextToken;
        $this->withSanctumToken($buyerToken)->postJson('/api/orders', [
            'symbol' => 'BTC', 'side' => 'buy', 'price' => '48000', 'amount' => '1',
        ]);
        $this->withSanctumToken($buyerToken)->postJson('/api/orders', [
            'symbol' => 'BTC', 'side' => 'buy', 'price' => '46000', 'amount' => '1',
        ]);
        $this->withSanctumToken($buyerToken)->postJson('/api/orders', [
            'symbol' => 'BTC', 'side' => 'buy', 'price' => '47000', 'amount' => '1',
        ]);

        // Place multiple sell orders
        $sellerToken = $seller->createToken('test')->plainTextToken;
        $this->withSanctumToken($sellerToken)->postJson('/api/orders', [
            'symbol' => 'BTC', 'side' => 'sell', 'price' => '51000', 'amount' => '1',
        ]);
        $this->withSanctumToken($sellerToken)->postJson('/api/orders', [
            'symbol' => 'BTC', 'side' => 'sell', 'price' => '51500', 'amount' => '1',
        ]);

        $response = $this->withSanctumToken($buyerToken)
            ->getJson('/api/orders?symbol=BTC')
            ->assertOk();

        $buyPrices = array_column($response['buy'], 'price');
        $this->assertSame(['48000.00000000', '47000.00000000', '46000.00000000'], $buyPrices);

        $sellPrices = array_column($response['sell'], 'price');
        $this->assertSame(['51000.00000000', '51500.00000000'], $sellPrices);
        $this->assertArrayNotHasKey('user_id', $response['buy'][0]);
    }

    public function test_orderbook_requires_auth(): void
    {
        $this->getJson('/api/orders?symbol=BTC')->assertUnauthorized();
    }

    public function test_browser_navigation_to_orderbook_returns_json_unauthorized_instead_of_redirecting(): void
    {
        $this->get('/api/orders?symbol=BTC')
            ->assertUnauthorized()
            ->assertHeader('content-type', 'application/json');
    }

    public function test_mine_returns_user_orders(): void
    {
        $user = $this->createUserWithBalanceAndAssets();
        $token = $user->createToken('test')->plainTextToken;

        $this->withSanctumToken($token)->postJson('/api/orders', [
            'symbol' => 'BTC', 'side' => 'buy', 'price' => '50000', 'amount' => '1',
        ]);
        $this->withSanctumToken($token)->postJson('/api/orders', [
            'symbol' => 'BTC', 'side' => 'sell', 'price' => '51000', 'amount' => '1',
        ]);

        $response = $this->withSanctumToken($token)
            ->getJson('/api/orders/mine')
            ->assertOk();

        $this->assertCount(2, $response['data']);
        $this->assertSame('sell', $response['data'][0]['side']);
        $this->assertSame('buy', $response['data'][1]['side']);
    }

    public function test_mine_supports_filters(): void
    {
        $user = $this->createUserWithBalanceAndAssets();
        $token = $user->createToken('test')->plainTextToken;

        $this->withSanctumToken($token)->postJson('/api/orders', [
            'symbol' => 'BTC', 'side' => 'buy', 'price' => '50000', 'amount' => '1',
        ]);
        $this->withSanctumToken($token)->postJson('/api/orders', [
            'symbol' => 'ETH', 'side' => 'sell', 'price' => '3000', 'amount' => '10',
        ]);

        $response = $this->withSanctumToken($token)
            ->getJson('/api/orders/mine?symbol=BTC')
            ->assertOk();

        $this->assertCount(1, $response['data']);
        $this->assertSame('BTC', $response['data'][0]['symbol']);

        $response = $this->withSanctumToken($token)
            ->getJson('/api/orders/mine?side=sell&status=1')
            ->assertOk();

        $this->assertCount(1, $response['data']);
        $this->assertSame('sell', $response['data'][0]['side']);
        $this->assertSame(OrderStatus::Open->value, $response['data'][0]['status']);
    }

    public function test_cancel_buy_order_refunds_hold(): void
    {
        $user = $this->createUserWithBalanceAndAssets();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withSanctumToken($token)
            ->postJson('/api/orders', [
                'symbol' => 'BTC', 'side' => 'buy', 'price' => '50000', 'amount' => '1',
            ])
            ->assertCreated();

        $orderId = $response['order']['id'];
        $user->refresh();
        $this->assertSame('49250.00000000', $user->balance); // Hold placed

        $this->withSanctumToken($token)
            ->postJson("/api/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('order.status', OrderStatus::Cancelled->value);

        $user->refresh();
        $this->assertSame('100000.00000000', $user->balance); // Hold refunded
    }

    public function test_cancel_sell_order_releases_locked_asset(): void
    {
        $user = $this->createUserWithBalanceAndAssets();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withSanctumToken($token)
            ->postJson('/api/orders', [
                'symbol' => 'BTC', 'side' => 'sell', 'price' => '50000', 'amount' => '1',
            ])
            ->assertCreated();

        $orderId = $response['order']['id'];
        $asset = Asset::where('user_id', $user->id)->where('symbol', 'BTC')->firstOrFail();
        $this->assertSame('1.00000000', $asset->locked_amount);

        $this->withSanctumToken($token)
            ->postJson("/api/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('order.status', OrderStatus::Cancelled->value);

        $asset->refresh();
        $this->assertSame('10.00000000', $asset->amount);
        $this->assertSame('0.00000000', $asset->locked_amount);
    }

    public function test_cancel_rejects_filled_order(): void
    {
        // Create buyer and seller
        $buyer = $this->createUserWithBalanceAndAssets(['email' => 'buyer@test']);
        $seller = $this->createUserWithBalanceAndAssets(['email' => 'seller@test']);

        // Seller places sell order first (resting)
        $sellerToken = $seller->createToken('test')->plainTextToken;
        $this->withSanctumToken($sellerToken)->postJson('/api/orders', [
            'symbol' => 'BTC', 'side' => 'sell', 'price' => '50000', 'amount' => '1',
        ]);

        // Buyer places matching buy order (crosses, fills immediately)
        $buyerToken = $buyer->createToken('test')->plainTextToken;
        $response = $this->withSanctumToken($buyerToken)
            ->postJson('/api/orders', [
                'symbol' => 'BTC', 'side' => 'buy', 'price' => '50000', 'amount' => '1',
            ])
            ->assertCreated();

        $orderId = $response['order']['id'];
        $this->assertSame(OrderStatus::Filled->value, $response['order']['status']);

        // Try to cancel the filled order
        $this->withSanctumToken($buyerToken)
            ->postJson("/api/orders/{$orderId}/cancel")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order']);
    }

    public function test_cancel_rejects_cancelled_order(): void
    {
        $user = $this->createUserWithBalanceAndAssets();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withSanctumToken($token)
            ->postJson('/api/orders', [
                'symbol' => 'BTC', 'side' => 'buy', 'price' => '50000', 'amount' => '1',
            ])
            ->assertCreated();

        $orderId = $response['order']['id'];

        // Cancel once
        $this->withSanctumToken($token)->postJson("/api/orders/{$orderId}/cancel")->assertOk();

        // Cancel again
        $this->withSanctumToken($token)
            ->postJson("/api/orders/{$orderId}/cancel")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order']);
    }

    public function test_cancel_rejects_non_owner(): void
    {
        $owner = $this->createUserWithBalanceAndAssets(['email' => 'owner@test']);
        $other = $this->createUserWithBalanceAndAssets(['email' => 'other@test']);

        $ownerToken = $owner->createToken('test')->plainTextToken;
        $response = $this->withSanctumToken($ownerToken)
            ->postJson('/api/orders', [
                'symbol' => 'BTC', 'side' => 'buy', 'price' => '50000', 'amount' => '1',
            ])
            ->assertCreated();

        $orderId = $response['order']['id'];

        $otherToken = $other->createToken('test')->plainTextToken;
        $this->assertNotSame($owner->id, $other->id);
        $this->withSanctumToken($otherToken)
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('id', $other->id);

        $this->withSanctumToken($otherToken)
            ->postJson("/api/orders/{$orderId}/cancel")
            ->assertForbidden();
    }

    public function test_cancel_requires_auth(): void
    {
        $user = $this->createUserWithBalanceAndAssets();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withSanctumToken($token)
            ->postJson('/api/orders', [
                'symbol' => 'BTC', 'side' => 'buy', 'price' => '50000', 'amount' => '1',
            ])
            ->assertCreated();

        $orderId = $response['order']['id'];

        $this->app['auth']->forgetGuards();
        $this->withoutHeader('Authorization')
            ->postJson("/api/orders/{$orderId}/cancel")
            ->assertUnauthorized();
    }

    public function test_cancel_returns_not_found_for_a_missing_order(): void
    {
        $user = $this->createUserWithBalanceAndAssets();
        $token = $user->createToken('test')->plainTextToken;

        $this->withSanctumToken($token)
            ->postJson('/api/orders/999999/cancel')
            ->assertNotFound();
    }

    public function test_matching_uses_resting_sell_price_and_conserves_usd_including_fee(): void
    {
        $buyer = $this->createUserWithBalanceAndAssets(['email' => 'matched-buyer@example.test']);
        $seller = $this->createUserWithBalanceAndAssets(['email' => 'matched-seller@example.test']);
        $initialUsd = bcadd($buyer->balance, $seller->balance, 8);

        $sellerToken = $seller->createToken('test')->plainTextToken;
        $resting = $this->withSanctumToken($sellerToken)->postJson('/api/orders', [
            'symbol' => 'BTC',
            'side' => 'sell',
            'price' => '49000',
            'amount' => '0.01',
        ])->assertCreated();

        $buyerToken = $buyer->createToken('test')->plainTextToken;
        $incoming = $this->withSanctumToken($buyerToken)->postJson('/api/orders', [
            'symbol' => 'BTC',
            'side' => 'buy',
            'price' => '50000',
            'amount' => '0.01',
        ])->assertCreated()
            ->assertJsonPath('order.status', OrderStatus::Filled->value)
            ->assertJsonPath('trade.price', '49000.00000000')
            ->assertJsonPath('trade.usd_volume', '490.00000000')
            ->assertJsonPath('trade.fee', '7.35000000');

        $this->assertSame(
            OrderStatus::Filled,
            Order::query()->findOrFail($resting['order']['id'])->status,
        );
        $this->assertSame(OrderSide::Buy->value, $incoming['trade']['side']);
        $this->assertSame('99502.65000000', $buyer->fresh()->balance);
        $this->assertSame('100490.00000000', $seller->fresh()->balance);

        $buyerAsset = Asset::query()->where('user_id', $buyer->id)->where('symbol', 'BTC')->firstOrFail();
        $sellerAsset = Asset::query()->where('user_id', $seller->id)->where('symbol', 'BTC')->firstOrFail();
        $this->assertSame('10.01000000', $buyerAsset->amount);
        $this->assertSame('9.99000000', $sellerAsset->amount);
        $this->assertSame('0.00000000', $sellerAsset->locked_amount);

        $finalUsd = bcadd($buyer->fresh()->balance, $seller->fresh()->balance, 8);
        $collectedFees = Trade::query()->sum('fee');
        $this->assertSame($initialUsd, bcadd($finalUsd, $collectedFees, 8));
        $this->assertSame('20.00000000', bcadd(
            bcadd($buyerAsset->amount, $buyerAsset->locked_amount, 8),
            bcadd($sellerAsset->amount, $sellerAsset->locked_amount, 8),
            8,
        ));
    }

    public function test_matching_uses_resting_buy_price_when_sell_arrives_after_buy(): void
    {
        $buyer = $this->createUserWithBalanceAndAssets(['email' => 'resting-buyer@example.test']);
        $seller = $this->createUserWithBalanceAndAssets(['email' => 'incoming-seller@example.test']);

        $buyerToken = $buyer->createToken('test')->plainTextToken;
        $this->withSanctumToken($buyerToken)->postJson('/api/orders', [
            'symbol' => 'ETH',
            'side' => 'buy',
            'price' => '3000',
            'amount' => '2',
        ])->assertCreated()->assertJsonPath('trade', null);

        $sellerToken = $seller->createToken('test')->plainTextToken;
        $this->withSanctumToken($sellerToken)->postJson('/api/orders', [
            'symbol' => 'ETH',
            'side' => 'sell',
            'price' => '2900',
            'amount' => '2',
        ])->assertCreated()
            ->assertJsonPath('trade.price', '3000.00000000')
            ->assertJsonPath('trade.usd_volume', '6000.00000000')
            ->assertJsonPath('trade.fee', '90.00000000');

        $this->assertSame('93910.00000000', $buyer->fresh()->balance);
        $this->assertSame('106000.00000000', $seller->fresh()->balance);
    }

    public function test_commission_example_charges_buyer_fourteen_dollars_and_twenty_five_cents(): void
    {
        $buyer = $this->createUserWithBalanceAndAssets(['email' => 'fee-buyer@example.test']);
        $seller = $this->createUserWithBalanceAndAssets(['email' => 'fee-seller@example.test']);
        $sellerToken = $seller->createToken('test')->plainTextToken;

        $this->withSanctumToken($sellerToken)->postJson('/api/orders', [
            'symbol' => 'BTC',
            'side' => 'sell',
            'price' => '95000',
            'amount' => '0.01',
        ])->assertCreated();

        $buyerToken = $buyer->createToken('test')->plainTextToken;
        $this->withSanctumToken($buyerToken)->postJson('/api/orders', [
            'symbol' => 'BTC',
            'side' => 'buy',
            'price' => '95000',
            'amount' => '0.01',
        ])->assertCreated()
            ->assertJsonPath('trade.usd_volume', '950.00000000')
            ->assertJsonPath('trade.fee', '14.25000000');

        $this->assertSame('99035.75000000', $buyer->fresh()->balance);
        $this->assertSame('100950.00000000', $seller->fresh()->balance);
    }

    public function test_fee_reserve_covers_half_up_rounding_at_eight_decimal_places(): void
    {
        $buyer = $this->createUserWithBalanceAndAssets(['email' => 'rounded-fee-buyer@example.test']);
        $seller = $this->createUserWithBalanceAndAssets(['email' => 'rounded-fee-seller@example.test']);

        $sellerToken = $seller->createToken('test')->plainTextToken;
        $this->withSanctumToken($sellerToken)->postJson('/api/orders', [
            'symbol' => 'BTC',
            'side' => 'sell',
            'price' => '1',
            'amount' => '0.000001',
        ])->assertCreated();

        $buyerToken = $buyer->createToken('test')->plainTextToken;
        $this->withSanctumToken($buyerToken)->postJson('/api/orders', [
            'symbol' => 'BTC',
            'side' => 'buy',
            'price' => '1',
            'amount' => '0.000001',
        ])->assertCreated()
            ->assertJsonPath('trade.usd_volume', '0.00000100')
            ->assertJsonPath('trade.fee', '0.00000002');

        $this->assertSame('99999.99999898', $buyer->fresh()->balance);
    }

    public function test_match_creates_a_missing_buyer_asset_row(): void
    {
        $buyer = User::factory()->create([
            'email' => 'new-asset-buyer@example.test',
            'balance' => '100000.00000000',
        ]);
        $seller = $this->createUserWithBalanceAndAssets(['email' => 'new-asset-seller@example.test']);
        $sellerToken = $seller->createToken('test')->plainTextToken;

        $this->withSanctumToken($sellerToken)->postJson('/api/orders', [
            'symbol' => 'ETH',
            'side' => 'sell',
            'price' => '1000',
            'amount' => '1',
        ])->assertCreated();

        $buyerToken = $buyer->createToken('test')->plainTextToken;
        $this->withSanctumToken($buyerToken)->postJson('/api/orders', [
            'symbol' => 'ETH',
            'side' => 'buy',
            'price' => '1000',
            'amount' => '1',
        ])->assertCreated()->assertJsonPath('order.status', OrderStatus::Filled->value);

        $this->assertDatabaseHas('assets', [
            'user_id' => $buyer->id,
            'symbol' => 'ETH',
            'amount' => '1.00000000',
            'locked_amount' => '0.00000000',
        ]);
    }

    public function test_matching_uses_best_price_then_oldest_counter_order(): void
    {
        $seller = $this->createUserWithBalanceAndAssets([
            'email' => 'priority-seller@example.test',
            'balance' => '1000000.00000000',
        ]);
        $buyer = $this->createUserWithBalanceAndAssets(['email' => 'priority-buyer@example.test']);
        $sellerToken = $seller->createToken('test')->plainTextToken;

        $higherPriced = $this->withSanctumToken($sellerToken)->postJson('/api/orders', [
            'symbol' => 'ETH',
            'side' => 'sell',
            'price' => '3100',
            'amount' => '1',
        ])->assertCreated();
        $firstAtBestPrice = $this->withSanctumToken($sellerToken)->postJson('/api/orders', [
            'symbol' => 'ETH',
            'side' => 'sell',
            'price' => '3000',
            'amount' => '1',
        ])->assertCreated();
        $secondAtBestPrice = $this->withSanctumToken($sellerToken)->postJson('/api/orders', [
            'symbol' => 'ETH',
            'side' => 'sell',
            'price' => '3000',
            'amount' => '1',
        ])->assertCreated();

        $buyerToken = $buyer->createToken('test')->plainTextToken;
        $matched = $this->withSanctumToken($buyerToken)->postJson('/api/orders', [
            'symbol' => 'ETH',
            'side' => 'buy',
            'price' => '3200',
            'amount' => '1',
        ])->assertCreated();

        $this->assertSame('3000.00000000', $matched['trade']['price']);
        $this->assertSame(OrderStatus::Filled->value, Order::query()->findOrFail($firstAtBestPrice['order']['id'])->status->value);
        $this->assertSame(OrderStatus::Open->value, Order::query()->findOrFail($secondAtBestPrice['order']['id'])->status->value);
        $this->assertSame(OrderStatus::Open->value, Order::query()->findOrFail($higherPriced['order']['id'])->status->value);
    }

    public function test_orders_do_not_match_if_amounts_differ_or_prices_do_not_cross(): void
    {
        $seller = $this->createUserWithBalanceAndAssets(['email' => 'nonmatch-seller@example.test']);
        $buyer = $this->createUserWithBalanceAndAssets([
            'email' => 'nonmatch-buyer@example.test',
            'balance' => '200000.00000000',
        ]);

        $sellerToken = $seller->createToken('test')->plainTextToken;
        $this->withSanctumToken($sellerToken)->postJson('/api/orders', [
            'symbol' => 'BTC',
            'side' => 'sell',
            'price' => '50000',
            'amount' => '1',
        ])->assertCreated();

        $buyerToken = $buyer->createToken('test')->plainTextToken;
        $this->withSanctumToken($buyerToken)->postJson('/api/orders', [
            'symbol' => 'BTC',
            'side' => 'buy',
            'price' => '49000',
            'amount' => '1',
        ])->assertCreated()->assertJsonPath('trade', null);

        $this->withSanctumToken($buyerToken)->postJson('/api/orders', [
            'symbol' => 'BTC',
            'side' => 'buy',
            'price' => '51000',
            'amount' => '2',
        ])->assertCreated()->assertJsonPath('trade', null);

        $this->assertSame(3, Order::query()->where('status', OrderStatus::Open->value)->count());
        $this->assertSame(0, Trade::query()->count());
    }

    public function test_orderbook_and_mine_validate_query_filters(): void
    {
        $user = $this->createUserWithBalanceAndAssets();
        $token = $user->createToken('test')->plainTextToken;

        $this->withSanctumToken($token)
            ->getJson('/api/orders?symbol=DOGE')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['symbol']);

        $this->withSanctumToken($token)
            ->getJson('/api/orders/mine?side=hold&status=9')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['side', 'status']);
    }

    public function test_order_inputs_must_be_decimal_strings_with_supported_precision(): void
    {
        $user = $this->createUserWithBalanceAndAssets();
        $token = $user->createToken('test')->plainTextToken;
        $payload = [
            'symbol' => 'BTC',
            'side' => 'buy',
            'price' => '1',
            'amount' => '1',
        ];

        $this->withSanctumToken($token)
            ->postJson('/api/orders', array_merge($payload, ['price' => 1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price']);

        $this->withSanctumToken($token)
            ->postJson('/api/orders', array_merge($payload, ['price' => '1e3']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price']);

        $this->withSanctumToken($token)
            ->postJson('/api/orders', array_merge($payload, ['amount' => '1.000000001']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_buy_order_rejects_reserves_that_exceed_usd_column_precision(): void
    {
        $user = $this->createUserWithBalanceAndAssets();
        $token = $user->createToken('test')->plainTextToken;

        $this->withSanctumToken($token)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side' => 'buy',
                'price' => '999999999999',
                'amount' => '99999999999999999999',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price']);

        $this->assertSame(0, Order::query()->count());
        $this->assertSame('100000.00000000', $user->fresh()->balance);
    }

    public function test_order_rejects_notional_that_rounds_to_zero_at_scale_eight(): void
    {
        $user = $this->createUserWithBalanceAndAssets();
        $token = $user->createToken('test')->plainTextToken;

        $this->withSanctumToken($token)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side' => 'buy',
                'price' => '0.00000001',
                'amount' => '0.00000001',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price']);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_match_rolls_back_if_seller_balance_would_overflow(): void
    {
        $buyer = $this->createUserWithBalanceAndAssets(['email' => 'overflow-buyer@example.test']);
        $seller = $this->createUserWithBalanceAndAssets([
            'email' => 'overflow-seller@example.test',
            'balance' => MatchingEngine::MAX_USD_BALANCE,
        ]);
        $sellerToken = $seller->createToken('test')->plainTextToken;

        $this->withSanctumToken($sellerToken)->postJson('/api/orders', [
            'symbol' => 'BTC',
            'side' => 'sell',
            'price' => '1',
            'amount' => '1',
        ])->assertCreated();

        $buyerToken = $buyer->createToken('test')->plainTextToken;
        $this->withSanctumToken($buyerToken)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side' => 'buy',
                'price' => '1',
                'amount' => '1',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order']);

        $this->assertSame(1, Order::query()->count());
        $this->assertSame('100000.00000000', $buyer->fresh()->balance);
        $this->assertSame(MatchingEngine::MAX_USD_BALANCE, $seller->fresh()->balance);
    }

    public function test_match_rolls_back_if_buyer_asset_would_overflow(): void
    {
        $buyer = $this->createUserWithBalanceAndAssets(['email' => 'asset-overflow-buyer@example.test']);
        $seller = $this->createUserWithBalanceAndAssets(['email' => 'asset-overflow-seller@example.test']);
        $buyerAsset = Asset::query()->where('user_id', $buyer->id)->where('symbol', 'BTC')->firstOrFail();
        $buyerAsset->amount = MatchingEngine::MAX_ASSET_AMOUNT;
        $buyerAsset->save();

        $sellerToken = $seller->createToken('test')->plainTextToken;
        $this->withSanctumToken($sellerToken)->postJson('/api/orders', [
            'symbol' => 'BTC',
            'side' => 'sell',
            'price' => '1',
            'amount' => '1',
        ])->assertCreated();

        $buyerToken = $buyer->createToken('test')->plainTextToken;
        $this->withSanctumToken($buyerToken)
            ->postJson('/api/orders', [
                'symbol' => 'BTC',
                'side' => 'buy',
                'price' => '1',
                'amount' => '1',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order']);

        $this->assertSame(1, Order::query()->count());
        $this->assertSame('100000.00000000', $buyer->fresh()->balance);
        $this->assertSame(MatchingEngine::MAX_ASSET_AMOUNT, $buyerAsset->fresh()->amount);
    }
}
