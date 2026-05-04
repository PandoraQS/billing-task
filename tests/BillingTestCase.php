<?php
namespace Tests;

use App\BillingCalculator;
use PDO;
use PHPUnit\Framework\TestCase;

// Base class for all billing tests.
// Uses SQLite in-memory so the suite runs without Docker or a real database.
abstract class BillingTestCase extends TestCase
{
    protected PDO $db;
    protected BillingCalculator $calc;

    // Creates a fresh in-memory database and a new BillingCalculator before each test.
    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->createSchema();
        $this->calc = new BillingCalculator($this->db);
    }

    // Creates a minimal schema that matches the relevant columns from schema.sql.
    private function createSchema(): void
    {
        $this->db->exec("
            CREATE TABLE customers (
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL
            );

            CREATE TABLE products (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                unit_price_cents INTEGER NOT NULL
            );

            CREATE TABLE subscriptions (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_id  INTEGER NOT NULL,
                product_id   INTEGER NOT NULL,
                start_date   TEXT NOT NULL,
                end_date     TEXT NULL
            );

            CREATE TABLE seat_change_events (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                subscription_id   INTEGER NOT NULL,
                effective_date    TEXT NOT NULL,
                new_license_count INTEGER NOT NULL
            );
        ");
    }

    // Inserts a customer and returns its id.
    protected function seedCustomer(string $name = 'Test Customer'): int
    {
        $this->db->prepare("INSERT INTO customers (name) VALUES (?)")->execute([$name]);
        return (int) $this->db->lastInsertId();
    }

    // Inserts a product with the given price and returns its id.
    protected function seedProduct(int $unitPriceCents = 1000): int
    {
        $this->db->prepare("INSERT INTO products (unit_price_cents) VALUES (?)")->execute([$unitPriceCents]);
        return (int) $this->db->lastInsertId();
    }

    // Inserts a subscription and returns its id.
    protected function seedSubscription(int $customerId, int $productId, string $startDate, ?string $endDate = null): int
    {
        $this->db->prepare("INSERT INTO subscriptions (customer_id, product_id, start_date, end_date) VALUES (?, ?, ?, ?)")
            ->execute([$customerId, $productId, $startDate, $endDate]);
        return (int) $this->db->lastInsertId();
    }

    // Inserts a seat change event for a subscription.
    protected function seedEvent(int $subscriptionId, string $effectiveDate, int $newLicenseCount): void
    {
        $this->db->prepare("INSERT INTO seat_change_events (subscription_id, effective_date, new_license_count) VALUES (?, ?, ?)")
            ->execute([$subscriptionId, $effectiveDate, $newLicenseCount]);
    }

    // Filters invoice lines by type and re-indexes the result.
    protected function linesOf(string $type, array $invoice): array
    {
        return array_values(array_filter($invoice['lines'], fn($l) => $l['line_type'] === $type));
    }
}