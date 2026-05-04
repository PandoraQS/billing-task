<?php
namespace App;

class BillingCalculator
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    // Generates the invoice data for a single customer and billing period.
    // Returns an array with customer_id, period, subtotal_cents, and lines.
    public function generate(int $customerId, string $period): array
    {
        [$periodStart, $periodEnd, $periodDays] = $this->resolvePeriod($period);

        $subscriptions = $this->fetchSubscriptions($customerId, $periodStart, $periodEnd);

        $lines = [];
        foreach ($subscriptions as $sub) {
            $lines = array_merge($lines, $this->processSubscription($sub, $periodStart, $periodEnd, $periodDays));
        }

        return [
            'customer_id'    => $customerId,
            'period'         => $period,
            'subtotal_cents' => array_sum(array_column($lines, 'amount_cents')),
            'lines'          => $lines,
        ];
    }

    // Converts 'YYYY-MM' into period_start, period_end (half-open), and period_days.
    private function resolvePeriod(string $period): array
    {
        $periodStart = $period . '-01';
        $periodEnd   = date('Y-m-d', strtotime($periodStart . ' +1 month'));
        $periodDays  = (int) (new \DateTime($periodEnd))->diff(new \DateTime($periodStart))->days;

        return [$periodStart, $periodEnd, $periodDays];
    }

    // Fetches subscriptions active during the billing period.
    // A subscription overlaps if start_date < period_end AND (end_date IS NULL OR end_date > period_start).
    private function fetchSubscriptions(int $customerId, string $periodStart, string $periodEnd): array
    {
        $stmt = $this->db->prepare("
            SELECT
                s.id           AS subscription_id,
                s.start_date,
                s.end_date,
                p.unit_price_cents
            FROM subscriptions s
            JOIN products p ON p.id = s.product_id
            WHERE s.customer_id = :customer_id
              AND s.start_date  < :period_end
              AND (s.end_date IS NULL OR s.end_date > :period_start)
        ");
        $stmt->execute([
            'customer_id'  => $customerId,
            'period_end'   => $periodEnd,
            'period_start' => $periodStart,
        ]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // Fetches seat change events within the billing period, ordered by date and id.
    // Events on or after period_end are excluded (half-open interval).
    private function fetchSeatEvents(int $subscriptionId, string $periodStart, string $periodEnd): array
    {
        $stmt = $this->db->prepare("
            SELECT id, effective_date, new_license_count
            FROM seat_change_events
            WHERE subscription_id = :sub_id
              AND effective_date  >= :period_start
              AND effective_date  <  :period_end
            ORDER BY effective_date ASC, id ASC
        ");
        $stmt->execute([
            'sub_id'       => $subscriptionId,
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
        ]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // Builds the invoice lines for a single subscription.
    // Emits one BASE line for the initial seat count, and one UPGRADE_PRORATION line for each day where the seat count increased above the billing ceiling.
    private function processSubscription(array $sub, string $periodStart, string $periodEnd, int $periodDays): array
    {
        $subId     = (int) $sub['subscription_id'];
        $unitPrice = (int) $sub['unit_price_cents'];

        // Clamp the active interval to the billing period.
        $activeStart = max($sub['start_date'], $periodStart);
        $activeEnd   = $sub['end_date'] ? min($sub['end_date'], $periodEnd) : $periodEnd;

        if ($activeStart >= $activeEnd) {
            return [];
        }

        $lines     = [];
        $baseSeats = $this->resolveSeatsAt($subId, $activeStart);

        if ($baseSeats > 0) {
            $baseDays = $this->coveredDays($activeStart, $activeEnd);
            $lines[]  = [
                'line_type'    => 'BASE',
                'description'  => "Base charge: {$baseSeats} seats x {$baseDays} days",
                'amount_cents' => $this->prorateAmount($baseSeats, $unitPrice, $baseDays, $periodDays),
                'start_date'   => $activeStart,
                'end_date'     => $activeEnd,
                'seats'        => $baseSeats,
                'days'         => $baseDays,
            ];
        }

        $events = $this->fetchSeatEvents($subId, $periodStart, $periodEnd);

        // $billingSeats: the highest seat count charged so far this month (never decreases).
        // $actualSeats: the real current seat count (updated by every event, including downgrades).
        // Upgrade deltas are computed against $billingSeats, so a downgrade does not reduce the baseline for future upgrades within the same period.
        $billingSeats = $baseSeats;
        $actualSeats  = $baseSeats;

        $byDate = [];
        foreach ($events as $event) {
            $byDate[$event['effective_date']][] = $event;
        }

        foreach ($byDate as $date => $dayEvents) {
            if ($date <= $activeStart || $date >= $activeEnd) {
                continue;
            }

            // For same-day events, track both the peak count (for billing) and the final count (for actualSeats). 
            // Example: 5->8 then 8->7 on the same day: peak=8 triggers a +3 upgrade charge, final=7 becomes the new actual count.
            $peakCountForDay  = $billingSeats;
            $finalCountForDay = $actualSeats;
            foreach ($dayEvents as $evt) {
                $count = (int) $evt['new_license_count'];
                if ($count > $peakCountForDay) $peakCountForDay = $count;
                $finalCountForDay = $count;
            }

            if ($peakCountForDay > $billingSeats) {
                $delta       = $peakCountForDay - $billingSeats;
                $coveredDays = $this->coveredDays($date, $activeEnd);
                $lines[]     = [
                    'line_type'    => 'UPGRADE_PRORATION',
                    'description'  => "Upgrade +{$delta} seats from {$date} ({$coveredDays} days)",
                    'amount_cents' => $this->prorateAmount($delta, $unitPrice, $coveredDays, $periodDays),
                    'start_date'   => $date,
                    'end_date'     => $activeEnd,
                    'seats'        => $delta,
                    'days'         => $coveredDays,
                ];
                $billingSeats = $peakCountForDay;
            }

            $actualSeats = $finalCountForDay;
        }

        return $lines;
    }

    // Returns the seat count in effect at or before a given date.
    // Using effective_date <= $date handles both subscriptions that started before the period (picks up last event before period_start) and those that start mid-period (finds the baseline event on start_date).
    private function resolveSeatsAt(int $subId, string $date): int
    {
        $stmt = $this->db->prepare("
            SELECT new_license_count
            FROM seat_change_events
            WHERE subscription_id = :sub_id
              AND effective_date  <= :date
            ORDER BY effective_date DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute(['sub_id' => $subId, 'date' => $date]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? (int) $row['new_license_count'] : 0;
    }

    // Returns the number of days in a half-open interval [start, end).
    private function coveredDays(string $start, string $end): int
    {
        return (int) (new \DateTime($end))->diff(new \DateTime($start))->days;
    }

    // Calculates the prorated amount using round_half_up as specified.
    // Formula: round_half_up(seats * unit_price_cents * covered_days / period_days)
    private function prorateAmount(int $seats, int $unitPrice, int $coveredDays, int $periodDays): int
    {
        return (int) round($seats * $unitPrice * $coveredDays / $periodDays, 0, PHP_ROUND_HALF_UP);
    }
}