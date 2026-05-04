<?php
namespace App;

class InvoiceRepository
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    // Saves the invoice and its lines in a single transaction.
    // If an invoice already exists for this customer and period, returns it without inserting.
    // Returns ['id' => int, 'created' => bool].
    public function save(array $invoice): array
    {
        $existing = $this->findExisting($invoice['customer_id'], $invoice['period']);
        if ($existing !== null) {
            return ['id' => $existing, 'created' => false];
        }

        // Both the invoice header and all its lines are written in a single transaction.
        // If any insert fails, rollback ensures nothing is partially saved.
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare("
                INSERT INTO invoices (customer_id, period_yyyymm, currency, subtotal_cents)
                VALUES (:customer_id, :period, :currency, :subtotal_cents)
            ");
            $stmt->execute([
                'customer_id'    => $invoice['customer_id'],
                'period'         => $invoice['period'],
                'currency'       => 'EUR',
                'subtotal_cents' => $invoice['subtotal_cents'],
            ]);

            $invoiceId = (int) $this->db->lastInsertId();

            // Prepare the line insert once and reuse it for each line.
            $lineStmt = $this->db->prepare("
                INSERT INTO invoice_lines
                    (invoice_id, line_type, description, amount_cents, metadata_json, start_date, end_date)
                VALUES
                    (:invoice_id, :line_type, :description, :amount_cents, :metadata_json, :start_date, :end_date)
            ");

            foreach ($invoice['lines'] as $line) {
                $lineStmt->execute([
                    'invoice_id'    => $invoiceId,
                    'line_type'     => $line['line_type'],
                    'description'   => $line['description'],
                    'amount_cents'  => $line['amount_cents'],
                    'metadata_json' => json_encode(['seats' => $line['seats'], 'days' => $line['days']]),
                    'start_date'    => $line['start_date'],
                    'end_date'      => $line['end_date'],
                ]);
            }

            $this->db->commit();
            return ['id' => $invoiceId, 'created' => true];

        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // Checks if an invoice already exists for this customer and period.
    // Returns the invoice id if found, null otherwise.
    private function findExisting(int $customerId, string $period): ?int
    {
        $stmt = $this->db->prepare("
            SELECT id FROM invoices
            WHERE customer_id = :customer_id AND period_yyyymm = :period
            LIMIT 1
        ");
        $stmt->execute(['customer_id' => $customerId, 'period' => $period]);
        $row = $stmt->fetch();

        return $row ? (int) $row['id'] : null;
    }
}