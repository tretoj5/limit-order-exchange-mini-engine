# Limit Order Exchange

Laravel API and Vue SPA for a limit-order exchange, backed by PostgreSQL. The app provides Sanctum authentication, a wallet and orderbook, full-match order execution, order history and cancellation, plus private realtime match updates.

## Requirements

- Docker Engine with Docker Compose
- A Pusher application for realtime credentials (required for live event delivery)
- A modern browser (Chrome, Firefox, Safari, or Edge)

## Start the backend

```sh
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env
docker compose run --rm --no-deps backend php artisan key:generate
docker compose up -d --build db db_test backend queue
docker compose exec backend php artisan migrate --seed
docker compose up -d frontend
```

The API is available at `http://localhost:8000` and the SPA at `http://localhost:5173`. Set your Pusher app key and cluster in `frontend/.env` to enable live updates. Configure `VITE_API_BASE_URL` and `VITE_BROADCAST_AUTH_URL` there as well if the backend uses a different host or port.

To run the PostgreSQL-backed backend tests and frontend tests/build:

```sh
docker compose exec backend php artisan test
docker compose exec frontend npm test
docker compose exec frontend npm run build
```

If port 8000 is already in use, set `BACKEND_PORT` when starting Compose, then update both frontend URLs in `frontend/.env` to use that port. For example, with `BACKEND_PORT=8001`, set the API URL to `http://localhost:8001/api` and broadcast auth URL to `http://localhost:8001/broadcasting/auth`.

To stop the services:

```sh
docker compose down
```

## Demo account

The database seeder creates `demo@exchange.test` with password `password`. Seeded wallets include USD and BTC/ETH balances.

## Configuration

`backend/.env.example` contains the PostgreSQL settings and server-side Pusher variables. Set `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET`, `PUSHER_APP_CLUSTER`, and `BROADCAST_CONNECTION=pusher`. Set the matching public `VITE_PUSHER_APP_KEY` and `VITE_PUSHER_APP_CLUSTER` values in `frontend/.env`; configure `VITE_API_BASE_URL`, `VITE_BROADCAST_AUTH_URL`, and backend `FRONTEND_ORIGINS` if using a different local origin. The `queue` Compose service processes broadcast jobs. The test runner uses the isolated `db_test` service and the `exchange_test` database from `backend/phpunit.xml`.

The stack is PHP 8.3, Laravel 13, PostgreSQL 16, Sanctum 4, Pusher PHP SDK 7, Vue 3, TypeScript, Tailwind CSS 4, Pinia, Vue Router, Laravel Echo, and Pusher JS.

## Authentication and profile API

- `POST /api/register` accepts `name`, `email`, `password`, and `password_confirmation`.
- `POST /api/login` accepts `email` and `password`.
- Both endpoints return a Sanctum bearer token and the new/authenticated user's public fields.
- Use `Authorization: Bearer <token>` for authenticated requests; `POST /api/logout` revokes that token.
- `GET /api/profile` (Sanctum-authenticated; decimal balances are strings)
- Registration and login are limited to five attempts per minute.

The profile response includes the user's id, name, email, USD balance, and assets. All balance and quantity values are decimal strings with eight fractional digits; assets are sorted by symbol.

Example:

```json
{
  "id": 1,
  "name": "Demo Buyer",
  "email": "demo@exchange.test",
  "balance": "100000.00000000",
  "assets": [
    {
      "symbol": "BTC",
      "amount": "0.50000000",
      "locked_amount": "0.00000000"
    },
    {
      "symbol": "ETH",
      "amount": "5.00000000",
      "locked_amount": "0.00000000"
    }
  ]
}
```

## Order API

All order endpoints require a Sanctum bearer token. Supported symbols are configured in `backend/config/exchange.php` (`BTC`, `ETH`).

- `GET /api/orders?symbol=BTC` returns open buy/sell orders sorted by price-time priority. The payload contains no user IDs.
- `GET /api/orders/mine?symbol=BTC&side=buy&status=1` returns the authenticated user's paginated history; each filter is optional.
- `POST /api/orders` accepts `symbol`, `side`, `price`, and `amount`, with price and amount supplied as decimal strings. It returns the new order and a trade summary or `null` when the order rests.
- `POST /api/orders/{id}/cancel` cancels an owned open order and releases its reserved USD or assets. Filled/cancelled orders cannot be cancelled.

Order creation reserves the full limit-price value plus the 1.5% maximum buyer fee for buy orders. Sell orders move available assets into `locked_amount`. Matching is full-amount only, requires a crossing price, chooses the best-priced then oldest counter order, and executes at the resting order's price. Price improvement and unused fee reserve return to the buyer. The buyer pays the fee in USD; the seller receives the full trade volume.

## Realtime broadcasts

On each successful match, Laravel queues one `OrderMatched` broadcast for each party to the private `user.{id}` channel (Pusher channel name `private-user.{id}`). Broadcast authorization is served at `POST /broadcasting/auth` and requires the authenticated user's Sanctum bearer token; users may authorize only their own channel. Broadcast jobs are deferred until the surrounding database transaction commits. The Compose `queue` service runs `php artisan queue:work` to deliver those jobs.

Each event contains the recipient's own side, matched order ID/status, updated USD balance, and asset balances, plus the trade details. It does not include the other party's user ID. Configure the Pusher credentials in `backend/.env` before expecting live Pusher delivery.

## Financial design decisions

- Money and quantities use PostgreSQL fixed-point decimals with scale 8; PHP calculations use BCMath strings.
- Database CHECK constraints reject negative balances and asset quantities, combined asset amounts above their supported precision, invalid order sides/statuses, non-positive order prices/amounts, and invalid trade values.
- Order status values are `1` open, `2` filled, and `3` cancelled; order sides are `buy` and `sell`.
- Supported symbols are `BTC` and `ETH`.
- Matching requires an open counter-order for the same symbol with a crossing price and exactly the same amount; partial fills are not supported.
- Counter-order priority is best price, then oldest `created_at` and `id`; execution uses the resting order's price.
- The buyer pays a 1.5% commission in USD, rounded half-up to 8 decimal places. Buy orders reserve the limit-price value plus maximum fee; unused price improvement and fee headroom are refunded at settlement.
- Order notional must remain nonzero at 8-decimal USD precision; reserves and settlement amounts are checked against their PostgreSQL column limits.
- Sell placement moves the amount from available assets to `locked_amount`; cancellation releases the corresponding USD or asset hold.
- Orderbook reads return open orders only; the user's order history includes open, filled, and cancelled orders.
- Each place/match/cancel operation uses one retryable transaction. A transaction-scoped PostgreSQL advisory lock serializes changes per symbol; row locks then follow the fixed order: users by id, assets by `(user_id, symbol)`, then orders by id.

The PostgreSQL feature suite includes USD/asset conservation checks and multi-process race tests for shared-balance placement, double fills, and cancel-versus-match behavior. The frontend includes client-side unit tests for exact decimal previews and idempotent realtime store updates.
