# Reservation concurrency benchmarks

## Setup

- Local Docker environment with Nginx, 8 PHP-FPM workers and PostgreSQL.
- k6: 100 virtual users, one request per user.
- One product with 10 available units; quantity 1 per request.
- Demo reset before each run.
- Database stock balance checked after each run.

## Results

| Scenario | Run | Created | Replayed | Rejected | Avg ms | p95 ms | Requests/s |
|---|---:|---:|---:|---:|---:|---:|---:|
| Unique keys | 1 | 10 | 0 | 90 | 189.83 | 296.71 | 317.32 |
| Unique keys | 2 | 10 | 0 | 90 | 151.13 | 258.07 | 361.02 |
| Unique keys | 3 | 10 | 0 | 90 | 154.35 | 263.58 | 356.28 |
| Same key | 1 | 1 | 99 | 0 | 213.77 | 349.47 | 268.04 |
| Same key | 2 | 1 | 99 | 0 | 176.07 | 301.49 | 307.07 |
| Same key | 3 | 1 | 99 | 0 | 211.74 | 367.04 | 257.52 |

All runs completed 100 requests with zero unexpected responses.

After every unique-key run:
- Available quantity: 0.
- Reservation count and reserved quantity: 10.

After every same-key run:
- Available quantity: 9.
- Reservation count and reserved quantity: 1.

Every database check confirmed:
available_quantity + SUM(reservation quantities) = initial_stock.

## Interpretation and limitations

No overselling or duplicate reservations occurred in these runs.

Expected HTTP 409 responses are counted by k6's default
http_req_failed metric. The script separately verifies their error code.

These are short burst tests, not measurements of sustained capacity.
Latency includes waiting for available PHP workers and database locks.
The measurements do not isolate the contribution of either.

The tests exercise concurrent clients against a multi-worker server;
they do not guarantee that every request reaches the database simultaneously.