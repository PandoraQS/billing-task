<?php
namespace Tests;

class BillingCalculatorTest extends BillingTestCase
{
    // Full scenario from the seed data - customer active the entire month,
    // with an upgrade, a downgrade, and a second upgrade.
    public function test_acme_full_january(): void
    {
        $cId = $this->seedCustomer('Acme AB');
        $pId = $this->seedProduct(1000);
        $sId = $this->seedSubscription($cId, $pId, '2026-01-01');

        $this->seedEvent($sId, '2026-01-01', 10);
        $this->seedEvent($sId, '2026-01-10', 15);
        $this->seedEvent($sId, '2026-01-20', 12); // downgrade, no credit
        $this->seedEvent($sId, '2026-01-25', 20); // delta is 20-15=5, not 20-12
        $this->seedEvent($sId, '2026-02-01', 25); // boundary event, must be excluded

        $invoice  = $this->calc->generate($cId, '2026-01');
        $base     = $this->linesOf('BASE', $invoice);
        $upgrades = $this->linesOf('UPGRADE_PRORATION', $invoice);

        $this->assertSame(14677, $invoice['subtotal_cents']);

        $this->assertCount(1, $base);
        $this->assertSame(10000, $base[0]['amount_cents']);
        $this->assertSame('2026-01-01', $base[0]['start_date']);
        $this->assertSame('2026-02-01', $base[0]['end_date']);

        $this->assertCount(2, $upgrades);
        $this->assertSame(3548, $upgrades[0]['amount_cents']); // +5 seats x 22 days
        $this->assertSame('2026-01-10', $upgrades[0]['start_date']);
        $this->assertSame(1129, $upgrades[1]['amount_cents']); // +5 seats x 7 days
        $this->assertSame('2026-01-25', $upgrades[1]['start_date']);
    }

    // Customer starting mid-month, with two events on the same date:
    // an upgrade followed by a downgrade. The upgrade still gets billed
    // (peak reached that day), and the downgrade takes effect next period.
    public function test_beta_mid_month_and_same_day_events(): void
    {
        $cId = $this->seedCustomer('Beta ApS');
        $pId = $this->seedProduct(1000);
        $sId = $this->seedSubscription($cId, $pId, '2026-01-12');

        $this->seedEvent($sId, '2026-01-12', 5);
        $this->seedEvent($sId, '2026-01-20', 8); // upgrade
        $this->seedEvent($sId, '2026-01-20', 7); // downgrade same day, higher id wins as final
        $this->seedEvent($sId, '2026-01-28', 9); // delta is 9-8=1, not 9-7

        $invoice  = $this->calc->generate($cId, '2026-01');
        $base     = $this->linesOf('BASE', $invoice);
        $upgrades = $this->linesOf('UPGRADE_PRORATION', $invoice);

        $this->assertSame(4516, $invoice['subtotal_cents']);

        $this->assertCount(1, $base);
        $this->assertSame(3226, $base[0]['amount_cents']); // 5 seats x 20 days
        $this->assertSame('2026-01-12', $base[0]['start_date']);

        $this->assertCount(2, $upgrades);
        $this->assertSame(3, $upgrades[0]['seats']);    // peak was 8, delta = 8-5 = 3
        $this->assertSame(1161, $upgrades[0]['amount_cents']);
        $this->assertSame(1, $upgrades[1]['seats']);    // 9-8 = 1 (not 9-7)
        $this->assertSame(129, $upgrades[1]['amount_cents']);
    }

    // Verifies that PHP_ROUND_HALF_UP is used instead of PHP's default banker's rounding.
    public function test_proration_uses_round_half_up(): void
    {
        $cId = $this->seedCustomer();
        $pId = $this->seedProduct(1000);
        $sId = $this->seedSubscription($cId, $pId, '2026-01-31');
        $this->seedEvent($sId, '2026-01-31', 2);

        $invoice = $this->calc->generate($cId, '2026-01');
        $base    = $this->linesOf('BASE', $invoice);

        // 2 x 1000 x 1 / 31 = 64.516... -> 65
        $this->assertSame(65, $base[0]['amount_cents']);
    }

    // Verifies that end_date is respected and the active interval is clamped correctly.
    public function test_subscription_ending_mid_month(): void
    {
        $cId = $this->seedCustomer();
        $pId = $this->seedProduct(1000);
        $sId = $this->seedSubscription($cId, $pId, '2026-01-01', '2026-01-16');
        $this->seedEvent($sId, '2026-01-01', 10);

        $invoice = $this->calc->generate($cId, '2026-01');
        $base    = $this->linesOf('BASE', $invoice);

        $this->assertCount(1, $base);
        $this->assertSame('2026-01-16', $base[0]['end_date']);
        $this->assertSame(4839, $base[0]['amount_cents']); // 10 x 1000 x 15 / 31
    }

    // Events after the subscription end_date must be ignored.
    public function test_upgrade_after_subscription_end_is_ignored(): void
    {
        $cId = $this->seedCustomer();
        $pId = $this->seedProduct(1000);
        $sId = $this->seedSubscription($cId, $pId, '2026-01-01', '2026-01-16');
        $this->seedEvent($sId, '2026-01-01', 5);
        $this->seedEvent($sId, '2026-01-20', 10); // after end_date

        $upgrades = $this->linesOf('UPGRADE_PRORATION', $this->calc->generate($cId, '2026-01'));

        $this->assertCount(0, $upgrades);
    }

    // Events on period_end must be excluded (half-open interval).
    public function test_event_on_period_end_is_excluded(): void
    {
        $cId = $this->seedCustomer();
        $pId = $this->seedProduct(1000);
        $sId = $this->seedSubscription($cId, $pId, '2026-01-01');
        $this->seedEvent($sId, '2026-01-01', 5);
        $this->seedEvent($sId, '2026-02-01', 99); // must not affect January

        $invoice  = $this->calc->generate($cId, '2026-01');
        $upgrades = $this->linesOf('UPGRADE_PRORATION', $invoice);

        $this->assertCount(0, $upgrades);
        $this->assertSame(5000, $invoice['subtotal_cents']);
    }

    // A downgrade must not produce a credit line or reduce the subtotal.
    public function test_downgrade_generates_no_credit_line(): void
    {
        $cId = $this->seedCustomer();
        $pId = $this->seedProduct(1000);
        $sId = $this->seedSubscription($cId, $pId, '2026-01-01');
        $this->seedEvent($sId, '2026-01-01', 10);
        $this->seedEvent($sId, '2026-01-15', 5);

        $invoice  = $this->calc->generate($cId, '2026-01');
        $upgrades = $this->linesOf('UPGRADE_PRORATION', $invoice);

        $this->assertCount(0, $upgrades);
        $this->assertSame(10000, $invoice['subtotal_cents']);
    }

    // A customer with no active subscriptions should produce a zero subtotal.
    public function test_no_subscriptions_returns_zero_subtotal(): void
    {
        $cId     = $this->seedCustomer();
        $invoice = $this->calc->generate($cId, '2026-01');

        $this->assertSame(0, $invoice['subtotal_cents']);
        $this->assertEmpty($invoice['lines']);
    }

    // Lines from multiple subscriptions are all included and summed correctly.
    public function test_multiple_subscriptions_are_summed(): void
    {
        $cId  = $this->seedCustomer();
        $pId  = $this->seedProduct(1000);

        $sId1 = $this->seedSubscription($cId, $pId, '2026-01-01');
        $this->seedEvent($sId1, '2026-01-01', 5);

        $sId2 = $this->seedSubscription($cId, $pId, '2026-01-01');
        $this->seedEvent($sId2, '2026-01-01', 3);

        $invoice = $this->calc->generate($cId, '2026-01');

        $this->assertSame(8000, $invoice['subtotal_cents']);
        $this->assertCount(2, $invoice['lines']);
    }

    // Calling save() twice for the same customer and period must return the same invoice id.
    public function test_saving_twice_returns_same_invoice_id(): void
    {
        $cId = $this->seedCustomer();
        $pId = $this->seedProduct(1000);
        $sId = $this->seedSubscription($cId, $pId, '2026-01-01');
        $this->seedEvent($sId, '2026-01-01', 5);

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS invoices (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_id    INTEGER NOT NULL,
                period_yyyymm  TEXT NOT NULL,
                currency       TEXT NOT NULL DEFAULT 'EUR',
                subtotal_cents INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS invoice_lines (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                invoice_id    INTEGER NOT NULL,
                line_type     TEXT NOT NULL,
                description   TEXT NOT NULL,
                amount_cents  INTEGER NOT NULL,
                metadata_json TEXT NOT NULL,
                start_date    TEXT NOT NULL,
                end_date      TEXT NULL
            );
        ");

        $repo    = new \App\InvoiceRepository($this->db);
        $invoice = $this->calc->generate($cId, '2026-01');

        $id1 = $repo->save($invoice)['id'];
        $id2 = $repo->save($invoice)['id'];

        $this->assertSame($id1, $id2);
    }
}