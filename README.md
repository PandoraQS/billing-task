# Seat-based Subscription Billing

Calculates monthly invoices for seat-based subscriptions with proration support.

## Run

```bash
docker compose run --rm php composer install
docker compose up -d mysql

# Generate invoice for a specific customer and period
docker compose run --rm php php public/generate.php 1 2026-01

# With log file
docker compose run --rm php php public/generate.php 1 2026-01 --log=logs/billing.log

# Run tests
docker compose run --rm php vendor/bin/phpunit
```

## Assumptions

- Periods are half-open: `[period_start, period_end)`. Covered days for `[Jan 10, Feb 1)` = 22, not 23.
- Downgrades don't reduce charges in the current month. Two counters are tracked: `$billingSeats` (never decreases, used for upgrade deltas) and `$actualSeats` (reflects reality).
- Same-day events are processed in ascending `id` order. A single proration line is emitted for the net peak delta, not one line per event.
- Rounding uses `PHP_ROUND_HALF_UP` as specified.
- Events with `effective_date >= period_end` are excluded.
- Calling `generate()` twice for the same customer and period always returns the same invoice id (idempotent).
