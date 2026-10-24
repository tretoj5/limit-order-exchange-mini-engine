# Limit Order Exchange

Laravel API for a limit-order exchange, backed by PostgreSQL. Phase 1 provides the database foundation, Sanctum authentication, and profile endpoint.

## Requirements

- Docker Engine with Docker Compose
- A Pusher application for realtime credentials (optional until broadcasting is implemented)

## Start the backend

```sh
cp backend/.env.example backend/.env
docker compose run --rm --no-deps backend php artisan key:generate
docker compose up -d --build db db_test backend
docker compose exec backend php artisan migrate --seed
```

The API is available at `http://localhost:8000`. To run the PostgreSQL-backed feature tests:

```sh
docker compose exec backend php artisan test
```

If port 8000 is already in use, set `BACKEND_PORT` when starting and when running Compose commands, for example `BACKEND_PORT=8001 docker compose up -d --build db db_test backend`.

To stop the services:

```sh
docker compose down
```

## Demo account

The database seeder creates `demo@exchange.test` with password `password`. Seeded wallets include USD and BTC/ETH balances.

## Configuration

`backend/.env.example` contains the PostgreSQL settings and Pusher variables. Set `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET`, `PUSHER_APP_CLUSTER`, and the matching `VITE_PUSHER_APP_KEY` / `VITE_PUSHER_APP_CLUSTER` values for a Pusher application; broadcasting is configured with `BROADCAST_CONNECTION=pusher`. The test runner uses the isolated `db_test` service and the `exchange_test` database from `backend/phpunit.xml`.

The backend stack is PHP 8.3, Laravel 13, PostgreSQL 16, Sanctum 4, and the Pusher PHP SDK 7.

## Phase 1 API

- `POST /api/register` accepts `name`, `email`, `password`, and `password_confirmation`.
- `POST /api/login` accepts `email` and `password`.
- Both endpoints return a Sanctum bearer token and the new/authenticated user's public fields.
- Send the token as `Authorization: Bearer <token>`; `POST /api/logout` revokes that token.
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

## Financial design decisions

- Money and quantities use PostgreSQL fixed-point decimals with scale 8; PHP calculations use BCMath strings.
- Database CHECK constraints reject negative balances and asset quantities, invalid order sides/statuses, non-positive order prices/amounts, and invalid trade values.
- Order status values are `1` open, `2` filled, and `3` cancelled; order sides are `buy` and `sell`.
- Supported symbols are `BTC` and `ETH`.
- Planned matching requires an open counter-order for the same symbol with a crossing price and exactly the same amount; partial fills are not supported.
- Planned counter-order priority is best price, then oldest `created_at` and `id`; execution uses the resting order's price.
- The buyer pays a 1.5% commission in USD, rounded half-up to 8 decimal places. Buy orders reserve the limit-price value plus maximum fee; unused price improvement and fee headroom are refunded at settlement.
- Sell placement moves the amount from available assets to `locked_amount`; cancellation releases the corresponding USD or asset hold.
- Planned orderbook reads return open orders only; the user's order history includes open, filled, and cancelled orders.
- Planned state changes use one retryable transaction and a fixed row-lock order: users by id, assets by `(user_id, symbol)`, then orders by id. Match broadcasts happen after commit.

Order placement, matching, cancellation, realtime broadcasting, order endpoints, concurrency tests, and the Vue frontend are planned for later phases and are not implemented in Phase 1.
