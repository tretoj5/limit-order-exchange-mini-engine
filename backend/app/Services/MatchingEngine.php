<?php

namespace App\Services;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Events\OrderMatched;
use App\Models\Asset;
use App\Models\Order;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MatchingEngine
{
    public const int SCALE = 8;

    public const string MAX_USD_BALANCE = '999999999999.99999999';

    public const string MAX_ASSET_AMOUNT = '99999999999999999999.99999999';

    public function lockSymbol(string $symbol): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Order-book locks must be acquired inside a database transaction.');
        }

        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$symbol]);
    }

    public function findCounterOrder(
        string $symbol,
        OrderSide $incomingSide,
        string $price,
        string $amount,
        bool $lock = false,
    ): ?Order {
        $counterSide = $incomingSide === OrderSide::Buy ? OrderSide::Sell : OrderSide::Buy;

        $query = Order::query()
            ->where('symbol', $symbol)
            ->where('side', $counterSide->value)
            ->where('status', OrderStatus::Open->value)
            ->where('amount', $amount)
            ->when(
                $incomingSide === OrderSide::Buy,
                fn (Builder $query) => $query->where('price', '<=', $price),
                fn (Builder $query) => $query->where('price', '>=', $price),
            )
            ->orderByRaw($incomingSide === OrderSide::Buy
                ? 'price ASC, created_at ASC, id ASC'
                : 'price DESC, created_at ASC, id ASC');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function matchOrder(Order $order): ?Trade
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Order matching must run inside the order-placement transaction.');
        }

        $order = Order::query()->whereKey($order->id)->firstOrFail();

        if ($order->status !== OrderStatus::Open) {
            return null;
        }

        $counterCandidate = $this->findCounterOrder(
            $order->symbol,
            $order->side,
            $order->price,
            $order->amount,
        );

        if ($counterCandidate === null) {
            return null;
        }

        $orderIds = [$order->id, $counterCandidate->id];
        sort($orderIds, SORT_NUMERIC);

        $lockedOrders = Order::query()
            ->whereIn('id', $orderIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $order = $lockedOrders->get($order->id);
        $counterOrder = $lockedOrders->get($counterCandidate->id);

        if (! $order instanceof Order || ! $counterOrder instanceof Order) {
            return null;
        }

        if (
            $order->status !== OrderStatus::Open
            || $counterOrder->status !== OrderStatus::Open
            || $counterOrder->symbol !== $order->symbol
            || $counterOrder->amount !== $order->amount
            || $counterOrder->side === $order->side
        ) {
            return null;
        }

        $crosses = $order->side === OrderSide::Buy
            ? bccomp($counterOrder->price, $order->price, self::SCALE) <= 0
            : bccomp($counterOrder->price, $order->price, self::SCALE) >= 0;

        if (! $crosses) {
            return null;
        }

        $buyOrder = $order->side === OrderSide::Buy ? $order : $counterOrder;
        $sellOrder = $order->side === OrderSide::Sell ? $order : $counterOrder;
        $userIds = [$buyOrder->user_id, $sellOrder->user_id];
        sort($userIds, SORT_NUMERIC);

        // The controller acquired these rows before either order row was locked.
        $lockedUsers = User::query()
            ->whereIn('id', $userIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $lockedAssets = Asset::query()
            ->whereIn('user_id', $userIds)
            ->where('symbol', $order->symbol)
            ->orderBy('user_id')
            ->orderBy('symbol')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (Asset $asset): string => $this->assetKey($asset->user_id, $asset->symbol));

        $buyer = $lockedUsers->get($buyOrder->user_id);
        $seller = $lockedUsers->get($sellOrder->user_id);
        $buyerAsset = $lockedAssets->get($this->assetKey($buyOrder->user_id, $order->symbol));
        $sellerAsset = $lockedAssets->get($this->assetKey($sellOrder->user_id, $order->symbol));

        if (! $buyer instanceof User || ! $seller instanceof User || ! $buyerAsset instanceof Asset || ! $sellerAsset instanceof Asset) {
            throw new \LogicException('Matching participants and their asset rows must be locked before settlement.');
        }

        if (bccomp($sellerAsset->locked_amount, $sellOrder->amount, self::SCALE) < 0) {
            throw new \LogicException('The resting sell order does not have enough locked assets.');
        }

        $executionPrice = $counterOrder->price;
        $amount = $order->amount;
        $volume = bcmul($amount, $executionPrice, self::SCALE);
        $fee = $this->calculateFee($volume);
        $reservedUsd = $this->reservedUsd($buyOrder->price, $amount);
        $settlementUsd = bcadd($volume, $fee, self::SCALE);

        if (bccomp($reservedUsd, $settlementUsd, self::SCALE) < 0) {
            throw new \LogicException('The buy order reserve is insufficient for settlement.');
        }

        $buyerBalanceAfter = bcadd(
            $buyer->balance,
            bcsub($reservedUsd, $settlementUsd, self::SCALE),
            self::SCALE,
        );
        $sellerBalanceAfter = $buyer->id === $seller->id
            ? bcadd($buyerBalanceAfter, $volume, self::SCALE)
            : bcadd($seller->balance, $volume, self::SCALE);
        if ($buyer->id === $seller->id) {
            $buyerBalanceAfter = $sellerBalanceAfter;
        }

        $buyerAssetAmountAfter = bcadd($buyerAsset->amount, $amount, self::SCALE);
        $sellerLockedAfter = bcsub($sellerAsset->locked_amount, $amount, self::SCALE);
        $buyerAssetTotalAfter = bcadd(
            $buyerAssetAmountAfter,
            $buyer->id === $seller->id ? $sellerLockedAfter : $buyerAsset->locked_amount,
            self::SCALE,
        );

        if (bccomp($sellerBalanceAfter, self::MAX_USD_BALANCE, self::SCALE) > 0) {
            throw ValidationException::withMessages([
                'order' => ['The seller USD balance would exceed the supported precision.'],
            ]);
        }

        if (
            bccomp($buyerAssetAmountAfter, self::MAX_ASSET_AMOUNT, self::SCALE) > 0
            || bccomp($buyerAssetTotalAfter, self::MAX_ASSET_AMOUNT, self::SCALE) > 0
        ) {
            throw ValidationException::withMessages([
                'order' => ['The buyer asset balance would exceed the supported precision.'],
            ]);
        }

        $buyer->balance = $buyerBalanceAfter;
        $buyer->save();

        if ($buyer->id !== $seller->id) {
            $seller->balance = $sellerBalanceAfter;
            $seller->save();
        }

        $buyerAsset->amount = $buyerAssetAmountAfter;
        if ($buyer->id === $seller->id) {
            $buyerAsset->locked_amount = $sellerLockedAfter;
            $buyerAsset->save();
        } else {
            $buyerAsset->save();
            $sellerAsset->locked_amount = $sellerLockedAfter;
            $sellerAsset->save();
        }

        $buyOrder->status = OrderStatus::Filled;
        $buyOrder->save();

        $sellOrder->status = OrderStatus::Filled;
        $sellOrder->save();

        $trade = Trade::create([
            'buy_order_id' => $buyOrder->id,
            'sell_order_id' => $sellOrder->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'symbol' => $order->symbol,
            'price' => $executionPrice,
            'amount' => $amount,
            'usd_volume' => $volume,
            'fee' => $fee,
        ]);

        $this->broadcastMatch($trade, $buyOrder, $sellOrder, $buyer, $seller);

        return $trade;
    }

    public function reservedUsd(string $price, string $amount): string
    {
        $limitVolume = bcmul($amount, $price, self::SCALE);

        return bcadd($limitVolume, $this->calculateFee($limitVolume), self::SCALE);
    }

    private function calculateFee(string $volume): string
    {
        $feeAtTenPlaces = bcmul($volume, '0.015', self::SCALE + 2);
        $scaledFee = bcmul($feeAtTenPlaces, '100000000', self::SCALE + 2);
        $roundedInteger = bcadd($scaledFee, '0.5', 0);

        return bcdiv($roundedInteger, '100000000', self::SCALE);
    }

    public function assetKey(int|string $userId, string $symbol): string
    {
        return $userId.':'.$symbol;
    }

    private function broadcastMatch(
        Trade $trade,
        Order $buyOrder,
        Order $sellOrder,
        User $buyer,
        User $seller,
    ): void {
        $tradeData = [
            'id' => $trade->id,
            'symbol' => $trade->symbol,
            'price' => $trade->price,
            'amount' => $trade->amount,
            'usd_volume' => $trade->usd_volume,
            'fee' => $trade->fee,
        ];

        foreach ([
            [$buyer, $buyOrder, OrderSide::Buy],
            [$seller, $sellOrder, OrderSide::Sell],
        ] as [$user, $order, $side]) {
            $assets = Asset::query()
                ->where('user_id', $user->id)
                ->orderBy('symbol')
                ->get(['symbol', 'amount', 'locked_amount'])
                ->map(fn (Asset $asset): array => [
                    'symbol' => $asset->symbol,
                    'amount' => $asset->amount,
                    'locked_amount' => $asset->locked_amount,
                ])
                ->all();

            event(new OrderMatched(
                (int) $user->id,
                [
                    'trade' => [...$tradeData, 'side' => $side->value],
                    'order' => [
                        'id' => $order->id,
                        'symbol' => $order->symbol,
                        'side' => $order->side->value,
                        'price' => $order->price,
                        'amount' => $order->amount,
                        'status' => OrderStatus::Filled->value,
                        'created_at' => $order->created_at?->toISOString(),
                    ],
                    'balance' => $user->balance,
                    'assets' => $assets,
                ],
            ));
        }
    }
}
