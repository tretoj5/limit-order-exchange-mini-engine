<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Events\MarketDepthUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CancelOrderRequest;
use App\Http\Requests\Order\OrderbookRequest;
use App\Http\Requests\Order\OrdersMineRequest;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Models\Asset;
use App\Models\Order;
use App\Models\Trade;
use App\Models\User;
use App\Services\MatchingEngine;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MatchingEngine $matchingEngine,
    ) {}

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $userId = $request->user()->id;

        $result = DB::transaction(function () use ($validated, $userId): array {
            $symbol = $validated['symbol'];
            $side = OrderSide::from($validated['side']);
            $this->matchingEngine->lockSymbol($symbol);

            $counterCandidate = $this->matchingEngine->findCounterOrder(
                $symbol,
                $side,
                $validated['price'],
                $validated['amount'],
            );

            $userIds = [$userId];
            if ($counterCandidate !== null) {
                $userIds[] = $counterCandidate->user_id;
            }
            $userIds = array_values(array_unique($userIds));
            sort($userIds, SORT_NUMERIC);

            $lockedUsers = User::query()
                ->whereIn('id', $userIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $user = $lockedUsers->get($userId);

            if (! $user instanceof User) {
                abort(404);
            }

            $lockedAssets = Asset::query()
                ->whereIn('user_id', $userIds)
                ->where('symbol', $symbol)
                ->orderBy('user_id')
                ->orderBy('symbol')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (Asset $asset): string => $this->matchingEngine->assetKey($asset->user_id, $asset->symbol));

            if ($counterCandidate !== null) {
                $buyerId = $side === OrderSide::Buy ? $userId : $counterCandidate->user_id;
                $buyerAssetKey = $this->matchingEngine->assetKey($buyerId, $symbol);

                if (! $lockedAssets->has($buyerAssetKey)) {
                    $buyerAsset = Asset::query()->firstOrCreate(
                        ['user_id' => $buyerId, 'symbol' => $symbol],
                        ['amount' => '0.00000000', 'locked_amount' => '0.00000000'],
                    );
                    $lockedAssets->put($buyerAssetKey, $buyerAsset);
                }
            }

            $userAsset = $lockedAssets->get($this->matchingEngine->assetKey($userId, $symbol));

            if ($side === OrderSide::Buy) {
                $holdAmount = $this->matchingEngine->reservedUsd($validated['price'], $validated['amount']);

                if (bccomp($user->balance, $holdAmount, MatchingEngine::SCALE) < 0) {
                    throw ValidationException::withMessages([
                        'balance' => ['Insufficient USD balance for this order, including the fee reserve.'],
                    ]);
                }

                $user->balance = bcsub($user->balance, $holdAmount, MatchingEngine::SCALE);
                $user->save();
            } else {
                if (! $userAsset instanceof Asset || bccomp($userAsset->amount, $validated['amount'], MatchingEngine::SCALE) < 0) {
                    throw ValidationException::withMessages([
                        'asset' => ['Insufficient asset balance for this order.'],
                    ]);
                }

                $assetTotal = bcadd($userAsset->amount, $userAsset->locked_amount, MatchingEngine::SCALE);
                if (bccomp($assetTotal, MatchingEngine::MAX_ASSET_AMOUNT, MatchingEngine::SCALE) > 0) {
                    throw ValidationException::withMessages([
                        'asset' => ['The total asset balance exceeds the supported precision.'],
                    ]);
                }

                $userAsset->amount = bcsub($userAsset->amount, $validated['amount'], MatchingEngine::SCALE);
                $userAsset->locked_amount = bcadd($userAsset->locked_amount, $validated['amount'], MatchingEngine::SCALE);
                $userAsset->save();
            }

            $counterOrder = $this->matchingEngine->findCounterOrder(
                $symbol,
                $side,
                $validated['price'],
                $validated['amount'],
                lock: true,
            );

            if ($counterOrder !== null && ! $lockedUsers->has($counterOrder->user_id)) {
                throw new \LogicException('The order book changed while its symbol lock was held.');
            }

            $order = Order::query()->create([
                'user_id' => $user->id,
                'symbol' => $symbol,
                'side' => $side,
                'price' => $validated['price'],
                'amount' => $validated['amount'],
                'status' => OrderStatus::Open,
            ]);

            $trade = $this->matchingEngine->matchOrder($order);
            $order->refresh();
            if ($trade === null) {
                event(new MarketDepthUpdated($symbol));
            }

            return [
                'order' => $this->formatOrder($order),
                'trade' => $trade === null ? null : $this->formatTrade($trade, $userId),
            ];
        }, attempts: 3);

        return response()->json($result, 201);
    }

    public function orderbook(OrderbookRequest $request): JsonResponse
    {
        $symbol = $request->validated('symbol');

        $buyOrders = Order::query()
            ->where('symbol', $symbol)
            ->where('side', OrderSide::Buy->value)
            ->where('status', OrderStatus::Open->value)
            ->orderByDesc('price')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'symbol', 'side', 'price', 'amount', 'status', 'created_at'])
            ->map(fn (Order $order): array => $this->formatOrder($order))
            ->values();

        $sellOrders = Order::query()
            ->where('symbol', $symbol)
            ->where('side', OrderSide::Sell->value)
            ->where('status', OrderStatus::Open->value)
            ->orderBy('price')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'symbol', 'side', 'price', 'amount', 'status', 'created_at'])
            ->map(fn (Order $order): array => $this->formatOrder($order))
            ->values();

        return response()->json([
            'symbol' => $symbol,
            'buy' => $buyOrders,
            'sell' => $sellOrders,
        ]);
    }

    public function mine(OrdersMineRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $query = Order::query()->where('user_id', $request->user()->id);

        if (isset($filters['symbol'])) {
            $query->where('symbol', $filters['symbol']);
        }

        if (isset($filters['side'])) {
            $query->where('side', $filters['side']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $orders = $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->through(fn (Order $order): array => $this->formatOrder($order));

        return response()->json($orders);
    }

    public function cancel(CancelOrderRequest $request, Order $order): JsonResponse
    {
        $cancelledOrder = DB::transaction(function () use ($order): Order {
            $this->matchingEngine->lockSymbol($order->symbol);

            $user = User::query()
                ->whereKey($order->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $asset = null;
            if ($order->side === OrderSide::Sell) {
                $asset = Asset::query()
                    ->where('user_id', $order->user_id)
                    ->where('symbol', $order->symbol)
                    ->lockForUpdate()
                    ->first();
            }

            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorize('cancel', $lockedOrder);

            if ($lockedOrder->status !== OrderStatus::Open) {
                throw ValidationException::withMessages([
                    'order' => ['Order is not open and cannot be cancelled.'],
                ]);
            }

            if ($lockedOrder->side === OrderSide::Buy) {
                $user->balance = bcadd(
                    $user->balance,
                    $this->matchingEngine->reservedUsd($lockedOrder->price, $lockedOrder->amount),
                    MatchingEngine::SCALE,
                );
                $user->save();
            } else {
                if (! $asset instanceof Asset || bccomp($asset->locked_amount, $lockedOrder->amount, MatchingEngine::SCALE) < 0) {
                    throw new \LogicException('The sell order does not have enough locked assets to cancel.');
                }

                $asset->amount = bcadd($asset->amount, $lockedOrder->amount, MatchingEngine::SCALE);
                $asset->locked_amount = bcsub($asset->locked_amount, $lockedOrder->amount, MatchingEngine::SCALE);
                $asset->save();
            }

            $lockedOrder->status = OrderStatus::Cancelled;
            $lockedOrder->save();
            event(new MarketDepthUpdated($lockedOrder->symbol));

            return $lockedOrder->refresh();
        }, attempts: 3);

        return response()->json(['order' => $this->formatOrder($cancelledOrder)]);
    }

    /**
     * @return array{id: int, symbol: string, side: string, price: string, amount: string, status: int, created_at: ?string}
     */
    private function formatOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'symbol' => $order->symbol,
            'side' => $order->side->value,
            'price' => $order->price,
            'amount' => $order->amount,
            'status' => $order->status->value,
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, symbol: string, price: string, amount: string, usd_volume: string, fee: string, side: string, created_at: ?string}
     */
    private function formatTrade(Trade $trade, int $currentUserId): array
    {
        return [
            'id' => $trade->id,
            'symbol' => $trade->symbol,
            'price' => $trade->price,
            'amount' => $trade->amount,
            'usd_volume' => $trade->usd_volume,
            'fee' => $trade->fee,
            'side' => $trade->buyer_id === $currentUserId ? OrderSide::Buy->value : OrderSide::Sell->value,
            'created_at' => $trade->created_at?->toIso8601String(),
        ];
    }
}
