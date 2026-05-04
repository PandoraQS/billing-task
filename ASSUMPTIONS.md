# Assumptions and Trade-offs

**Billing seat count vs actual seat count**
The trickiest part. A downgrade does not reduce charges in the current month, but a later upgrade still needs to know the right baseline to compute the delta. I ended up tracking two counters: `$billingSeats` (the highest count charged so far, never goes down) and `$actualSeats` (the real current count). Upgrade deltas are always computed against `$billingSeats`, so a downgrade from 15 to 12 followed by an upgrade to 20 correctly charges +5, not +8.

**Same-day events**
When two events fall on the same date, the one with the higher id is treated as the last one applied. For billing purposes though, I look at the highest seat count reached during that day, not just the final one. This matters when an upgrade and a downgrade happen on the same date: the upgrade still gets charged (prorated for the remaining days of the month), and the downgrade takes effect from the next period.

**Half-open intervals**
All intervals are [start, end). This avoids off-by-one issues when counting days. As an example, covered days for [Jan 10, Feb 1) is 22, not 23.

**Rounding**
I used `PHP_ROUND_HALF_UP` as the spec requires. PHP's default `round()` uses banker's rounding which rounds 0.5 to the nearest even number, which would give different results.

**Idempotency**
Calling `generate()` twice for the same customer and period always returns the same invoice id. I handle this in InvoiceRepository by checking for an existing invoice before inserting. I noticed the provided schema does not have a `UNIQUE` constraint on `(customer_id, period_yyyymm)`. Ideally that guarantee would live at the DB level too, but I did not modify the provided schema for the clarity of the given task and resources.

**Tests**
I used SQLite in-memory so the test suite runs without Docker or a real database. PHPUnit as the unit test library. Fast and no setup needed.

**Logging**
Writing to stderr for simplicity. In production I would use something like Monolog with a proper handler. I could write `docker compose run --rm php php public/generate.php 2 2026-01 > file.log` to redirect the stderr to a log file.
