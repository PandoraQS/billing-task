-- Clean slate
TRUNCATE TABLE invoice_lines;
TRUNCATE TABLE invoices;
TRUNCATE TABLE seat_change_events;
TRUNCATE TABLE subscriptions;
TRUNCATE TABLE products;
TRUNCATE TABLE customers;

-- Customers
INSERT INTO customers (id, name) VALUES
(1, 'Acme AB'),
(2, 'Beta ApS');

-- Product
INSERT INTO products (id, sku, name, unit_price_cents, currency) VALUES
(1, 'PRO', 'Pro Plan', 1000, 'EUR');

-- Subscriptions
-- Customer 1: active entire month
INSERT INTO subscriptions
(id, customer_id, product_id, start_date, end_date, status)
VALUES
(101, 1, 1, '2026-01-01', NULL, 'ACTIVE');

-- Customer 2: starts mid-month
INSERT INTO subscriptions
(id, customer_id, product_id, start_date, end_date, status)
VALUES
(201, 2, 1, '2026-01-12', NULL, 'ACTIVE');

-- Seat change events
-- Baseline seats (must exist on subscription start_date)
INSERT INTO seat_change_events
(subscription_id, effective_date, new_license_count, external_id)
VALUES
(101, '2026-01-01', 10, 'acme-baseline'),
(201, '2026-01-12', 5,  'beta-baseline');

-- Customer 1: upgrade → downgrade → upgrade
-- Inclusive effective_date semantics
INSERT INTO seat_change_events
(subscription_id, effective_date, new_license_count, external_id)
VALUES
-- Upgrade from 10 → 15, effective Jan 10 (billed Jan 10–31 = 22 days)
(101, '2026-01-10', 15, 'acme-up-1'),

-- Downgrade from 15 → 12, effective Jan 20 (NO credit in Jan)
-- Billing seat count for January remains 15
(101, '2026-01-20', 12, 'acme-down-1'),

-- Upgrade again: billing seat count goes from 15 → 20
-- Effective Jan 25 (billed Jan 25–31 = 7 days)
(101, '2026-01-25', 20, 'acme-up-2');

-- Boundary event: must NOT affect January
INSERT INTO seat_change_events
(subscription_id, effective_date, new_license_count, external_id)
VALUES
(101, '2026-02-01', 25, 'acme-boundary');

-- Customer 2: same-date ordering test
-- Events share the same effective_date; higher id wins
INSERT INTO seat_change_events
(subscription_id, effective_date, new_license_count, external_id)
VALUES
-- Upgrade 5 → 8, effective Jan 20
(201, '2026-01-20', 8, 'beta-same-date-1'),

-- Downgrade 8 → 7, same date; later id means this is the final seat count
-- No credit in January; billing seat count stays at 8 for January upgrades
(201, '2026-01-20', 7, 'beta-same-date-2');

-- Later upgrade
INSERT INTO seat_change_events
(subscription_id, effective_date, new_license_count, external_id)
VALUES
-- Upgrade from billing seat count 8 → 9
-- Effective Jan 28 (billed Jan 28–31 = 4 days)
(201, '2026-01-28', 9, 'beta-up-1');