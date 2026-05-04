<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\BillingCalculator;
use App\InvoiceRepository;
use App\Logger;

// Usage: php generate.php <customer_id> <YYYY-MM> [--log=path/to/file.log]
if ($argc < 3) {
    fwrite(STDERR, "Usage: php generate.php <customer_id> <YYYY-MM> [--log=path/to/file.log]\n");
    exit(1);
}

$customerId = (int) $argv[1];
$period     = $argv[2];

// Optional --log argument redirects log output to a file in addition to stderr.
foreach (array_slice($argv, 3) as $arg) {
    if (str_starts_with($arg, '--log=')) {
        Logger::setLogFile(substr($arg, 6));
    }
}

// Input validation
if ($customerId <= 0) {
    fwrite(STDERR, "Error: customer_id must be a positive integer\n");
    exit(1);
}

if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
    fwrite(STDERR, "Error: period must be in YYYY-MM format (e.g. 2026-01)\n");
    exit(1);
}

$db = Database::connect();

// Verify the customer exists before running the billing logic.
// Without this check, an unknown customer_id would silently produce an empty invoice.
$stmt = $db->prepare("SELECT id FROM customers WHERE id = :id");
$stmt->execute(['id' => $customerId]);
if (!$stmt->fetch()) {
    fwrite(STDERR, "Error: customer {$customerId} not found\n");
    Logger::error('customer not found', ['customer_id' => $customerId]);
    exit(1);
}

$calculator = new BillingCalculator($db);
$repo       = new InvoiceRepository($db);

$start   = microtime(true);
$invoice = $calculator->generate($customerId, $period);
$result  = $repo->save($invoice);
$elapsed = round((microtime(true) - $start) * 1000);

// Log differently depending on whether the invoice was just created or already existed.
if ($result['created']) {
    Logger::info('invoice generated', [
        'invoice_id'     => $result['id'],
        'customer_id'    => $customerId,
        'period'         => $period,
        'subtotal_cents' => $invoice['subtotal_cents'],
        'lines'          => count($invoice['lines']),
        'ms'             => $elapsed,
    ]);
} else {
    Logger::info('invoice already exists, skipped', [
        'invoice_id'  => $result['id'],
        'customer_id' => $customerId,
        'period'      => $period,
    ]);
}

echo "\n";
echo "=======================================================\n";
echo "  INVOICE #{$result['id']}\n";
echo "  Customer : {$invoice['customer_id']}\n";
echo "  Period   : {$invoice['period']}\n";
echo "=======================================================\n\n";

foreach ($invoice['lines'] as $line) {
    $type   = str_pad($line['line_type'], 20);
    $amount = number_format($line['amount_cents'] / 100, 2);
    echo "  [{$type}]  {$line['description']}\n";
    echo "                         {$line['start_date']} -> {$line['end_date']}\n";
    echo "                         EUR {$amount}\n\n";
}

$subtotal = number_format($invoice['subtotal_cents'] / 100, 2);
echo "-------------------------------------------------------\n";
echo "  SUBTOTAL : EUR {$subtotal}  ({$invoice['subtotal_cents']} cents)\n";
echo "=======================================================\n\n";