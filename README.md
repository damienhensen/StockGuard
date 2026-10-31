# StockGuard

StockGuard is a small Laravel API that handles stock reservations when
multiple clients request the same product at once.

The example is simple: a product has 10 units available and 100 clients
try to reserve one. Only 10 reservations should succeed. Retrying a
successful request should return the original reservation without
deducting stock again.

Built with PHP 8.5, Laravel 13, PostgreSQL 18, Nginx and PHP-FPM.
Docker Compose runs the local environment, PHPUnit covers the API
behaviour, and k6 tests concurrent requests.

## How it works

Each reservation runs inside a PostgreSQL transaction. The application
locks the product row with `SELECT ... FOR UPDATE`, checks whether the
idempotency key already exists, and then checks the available stock.

If stock is available, it decreases the quantity and creates the
reservation. Both changes commit together. If saving the reservation
fails, the stock change rolls back too.

Requests for the same product wait for the lock before checking stock.
This prevents them from making a decision based on the same outdated
quantity.

PostgreSQL also rejects negative stock, non-positive reservation
quantities and duplicate product/key combinations. The application
maintains this balance:

```text
available quantity + reserved quantity = initial stock
```

Idempotency keys belong to a product. Reusing a successful key with the
same quantity returns the original reservation. Using it with a different
quantity returns a conflict. Rejected reservations do not claim the key.

## Running locally

You need Linux, PHP 8.5 with PDO PostgreSQL support, Composer, and Docker
with Compose.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Set these values in `.env`:

```dotenv
APP_ENV=local
APP_DEBUG=false
APP_URL=http://127.0.0.1:8080

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5433
DB_DATABASE=stockguard
DB_USERNAME=stockguard
DB_PASSWORD=local_stockguard
```

Build the PHP image and give PHP-FPM access to Laravel's writable folders:

```bash
docker compose build app

docker compose run --rm --no-deps --user root app \
  chown -R "$(id -u):82" storage bootstrap/cache

chmod -R ug+rwX storage bootstrap/cache
```

Start the database, run migrations and seed the demo product:

```bash
docker compose up -d --wait postgres
php artisan config:clear
php artisan migrate
php artisan db:seed --class=ProductSeeder
docker compose up -d app nginx
```

The API runs at `http://127.0.0.1:8080`.

Artisan commands on the host connect through port 5433. Inside Docker,
the application uses `postgres:5432`. Avoid caching the configuration
in this local setup because those connection settings differ.

## Using the API

Get the demo product:

```bash
curl -i http://127.0.0.1:8080/api/products/1 \
  -H 'Accept: application/json'
```

Reserve one unit:

```bash
curl -i http://127.0.0.1:8080/api/products/1/reservations \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: order-001' \
  -d '{"quantity":1}'
```

A successful reservation returns HTTP 201:

```json
{
    "data": {
        "reservation_id": 42,
        "product_id": 1,
        "quantity": 1,
        "replayed": false
    }
}
```

Send the same request again and it returns HTTP 200 with the same
reservation ID and `replayed: true`.

| Request                                | Status |
| -------------------------------------- | -----: |
| New reservation                        |    201 |
| Replay of a successful reservation     |    200 |
| Insufficient stock                     |    409 |
| Existing key with a different quantity |    409 |
| Invalid input or missing key           |    422 |
| Unknown product with valid input       |    404 |

Quantity must be an integer from 1 to 10. The `Idempotency-Key` header
is required and accepts up to 255 characters.

The examples assume the demo product has ID 1. Adjust it if your seeded
product has a different ID.

## Tests

Create a separate test database once:

```bash
docker compose exec postgres \
  psql -U stockguard -d postgres \
  -c 'CREATE DATABASE stockguard_test;'
```

Check that `phpunit.xml` points to `stockguard_test`, then run:

```bash
php artisan config:clear
php artisan test
```

The tests cover reservations, validation, stock shortages, idempotency,
rollback and the demo reset. They also check that available and reserved
stock still add up to the initial quantity.

## Concurrency tests

The k6 script sends one request per virtual user to Nginx and eight
PHP-FPM workers. It supports unique keys and repeated requests with
the same key.

Reset the demo before each run. This deletes its reservations and
restores stock to 10:

```bash
php artisan stockguard:reset-demo
```

Run the unique-key scenario:

```bash
docker run --rm --network host \
  -v "$PWD/benchmarks:/scripts:ro" \
  grafana/k6 run \
  -e VUS=100 \
  -e MODE=unique \
  -e PRODUCT_ID=1 \
  -e RUN_ID=unique-1 \
  /scripts/reservations.js
```

For the retry scenario, reset again and use `MODE=same` and
`RUN_ID=same-1`. The documented baseline uses 100 VUs; make sure the
script uses that value if you have removed the configurable VU option.

After each run, check the database before resetting:

```bash
docker compose exec -T postgres \
  psql -U stockguard -d stockguard \
  < benchmarks/verify.sql
```

Unique keys should leave 0 available units and 10 reservation rows.
The same-key scenario should leave 9 available units and one reservation.
In both cases, `invariant_ok` should be true.

### Results

Each scenario was run three times with 100 VUs and a reset before
every run.

| Scenario    | Run | Created | Replayed | Rejected | Avg ms | p95 ms | Requests/s |
| ----------- | --: | ------: | -------: | -------: | -----: | -----: | ---------: |
| Unique keys |   1 |      10 |        0 |       90 | 189.83 | 296.71 |     317.32 |
| Unique keys |   2 |      10 |        0 |       90 | 151.13 | 258.07 |     361.02 |
| Unique keys |   3 |      10 |        0 |       90 | 154.35 | 263.58 |     356.28 |
| Same key    |   1 |       1 |       99 |        0 | 213.77 | 349.47 |     268.04 |
| Same key    |   2 |       1 |       99 |        0 | 176.07 | 301.49 |     307.07 |
| Same key    |   3 |       1 |       99 |        0 | 211.74 | 367.04 |     257.52 |

All six runs finished without unexpected responses, and every database
check matched the expected stock balance.

These results describe short bursts on a local machine. They do not
establish sustained capacity or guarantee that every request reached
the database at the same instant.

k6 counts the expected 409 responses in its default `http_req_failed`
metric. The script checks their error codes separately. Database
verification is currently a manual step, and replay IDs are not compared
across all virtual users.

## Scope

This version focuses on the reservation flow. It has no accounts,
payments, restocking, cancellations or reservation expiry.

Redis was considered but left out: the product endpoint already needs
PostgreSQL for current stock, and caching two small metadata fields had
no demonstrated benefit.

The API is intended for local use. Authentication, authorization,
rate limiting and deployment hardening would be needed before making
it public.

## Relevant code

- [Reservation controller](app/Http/Controllers/ReservationController.php)
- [Request validation](app/Http/Requests/StoreReservationRequest.php)
- [API error codes](app/Enums/ApiErrorCode.php)
- [Reservation tests](tests/Feature/ReservationTest.php)
- [Demo reset](app/Console/Commands/ResetDemo.php)
- [Reset tests](tests/Feature/ResetDemoTest.php)
- [k6 script](benchmarks/reservations.js)
- [Database check](benchmarks/verify.sql)
