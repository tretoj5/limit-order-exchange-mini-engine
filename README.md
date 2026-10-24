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

`backend/.env.example` contains the PostgreSQL settings and Pusher variables. Set `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET`, and `PUSHER_APP_CLUSTER` for a Pusher application. The test runner uses the isolated `db_test` service and the `exchange_test` database from `backend/phpunit.xml`.

## Phase 1 API

- `POST /api/register`
- `POST /api/login`
- `POST /api/logout` (Sanctum-authenticated)
- `GET /api/profile` (Sanctum-authenticated; decimal balances are strings)

## Financial design decisions

- Money and quantities use PostgreSQL fixed-point decimals with scale 8; PHP calculations use BCMath strings.
- Database CHECK constraints reject negative balances and asset quantities, invalid order sides/statuses, and non-positive order prices/amounts.
- Order status values are `1` open, `2` filled, and `3` cancelled; order sides are `buy` and `sell`.
- Order placement requires an exact full-amount match; partial fills are not supported.
- Counter-orders are selected by best price, then oldest `created_at` and `id`; execution uses the resting order's price.
- The buyer pays a 1.5% commission in USD, rounded half-up to 8 decimal places. Buy orders reserve the limit-price value plus maximum fee; unused price improvement and fee headroom are refunded at settlement.

Order placement, matching, cancellation, realtime broadcasting, concurrency tests, and the Vue frontend are planned for later phases and are not implemented in Phase 1.
