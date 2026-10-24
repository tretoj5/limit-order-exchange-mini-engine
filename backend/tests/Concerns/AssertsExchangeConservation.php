<?php

namespace Tests\Concerns;

use App\Models\Asset;
use App\Models\Order;
use App\Models\Trade;
use App\Models\User;
use App\Services\MatchingEngine;

trait AssertsExchangeConservation
{
    /**
     * @return array{usd: string, assets: array<string, string>}
     */
    protected function exchangeTotals(): array
    {
        $usd = User::query()->pluck('balance')->reduce(
            fn (string $total, string $balance): string => bcadd($total, $balance, MatchingEngine::SCALE),
            '0.00000000',
        );

        foreach (Order::query()
            ->where('side', 'buy')
            ->where('status', 1)
            ->get(['price', 'amount']) as $order) {
            $reserved = app(MatchingEngine::class)->reservedUsd($order->price, $order->amount);
            $usd = bcadd($usd, $reserved, MatchingEngine::SCALE);
        }

        $usd = Trade::query()->pluck('fee')->reduce(
            fn (string $total, string $fee): string => bcadd($total, $fee, MatchingEngine::SCALE),
            $usd,
        );

        $assets = ['BTC' => '0.00000000', 'ETH' => '0.00000000'];
        foreach (Asset::query()->get(['symbol', 'amount', 'locked_amount']) as $asset) {
            $assets[$asset->symbol] = bcadd(
                $assets[$asset->symbol],
                bcadd($asset->amount, $asset->locked_amount, MatchingEngine::SCALE),
                MatchingEngine::SCALE,
            );
        }

        return ['usd' => $usd, 'assets' => $assets];
    }

    /**
     * @param  array{usd: string, assets: array<string, string>}  $expected
     */
    protected function assertExchangeTotalsUnchanged(array $expected): void
    {
        $this->assertSame($expected, $this->exchangeTotals());
    }
}
