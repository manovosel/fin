<?php

declare(strict_types=1);

function funding_median(array $values): float
{
    if (!$values) return 0.0;
    sort($values, SORT_NUMERIC);
    $count = count($values);
    $middle = intdiv($count, 2);
    return $count % 2 ? (float)$values[$middle] : ((float)$values[$middle - 1] + (float)$values[$middle]) / 2;
}

function funding_quantile(array $values, float $quantile): float
{
    if (!$values) return 0.0;
    sort($values, SORT_NUMERIC);
    if (count($values) === 1) return (float)$values[0];
    $position = max(0.0, min(1.0, $quantile)) * (count($values) - 1);
    $lower = (int)floor($position);
    $upper = (int)ceil($position);
    if ($lower === $upper) return (float)$values[$lower];
    $fraction = $position - $lower;
    return (float)$values[$lower] + (((float)$values[$upper] - (float)$values[$lower]) * $fraction);
}

function funding_round_up(float $amount, int $increment): float
{
    if ($amount <= 0 || $increment <= 0) return 0.0;
    return ceil(($amount - 0.0000001) / $increment) * $increment;
}

function funding_month_key(int $year, int $month): string
{
    return sprintf('%04d-%02d', $year, $month);
}

function funding_month_sequence(string $from, string $to): array
{
    if ($from === '' || $to === '' || $from > $to) return [];
    $current = new DateTimeImmutable($from . '-01');
    $end = new DateTimeImmutable($to . '-01');
    $months = [];
    while ($current <= $end) {
        $months[] = $current->format('Y-m');
        $current = $current->modify('+1 month');
    }
    return $months;
}

function funding_previous_month(string $date): string
{
    return (new DateTimeImmutable(substr($date, 0, 7) . '-01'))->modify('-1 month')->format('Y-m');
}

function funding_adjust_observations(array $observations): array
{
    $values = array_map(fn(array $row): float => (float)$row['actual'], $observations);
    if (count($values) < 6) {
        foreach ($observations as &$row) {
            $row['adjusted'] = (float)$row['actual'];
            $row['outlier_limited'] = false;
        }
        unset($row);
        return $observations;
    }
    $q1 = funding_quantile($values, 0.25);
    $q3 = funding_quantile($values, 0.75);
    $iqr = $q3 - $q1;
    $lower = max(0.0, $q1 - (1.5 * $iqr));
    $upper = $q3 + (1.5 * $iqr);
    foreach ($observations as &$row) {
        $actual = (float)$row['actual'];
        $adjusted = min($upper, max($lower, $actual));
        $row['adjusted'] = round($adjusted, 2);
        $row['outlier_limited'] = abs($adjusted - $actual) > 0.004;
    }
    unset($row);
    return $observations;
}

/**
 * Whether the monthly spending of an Envelope is not a monthly recurrence.
 *
 * Spending that only happens in some months — a quarterly bill, an annual premium,
 * an irregular household cost — has no typical month. Its median month is then 0, or
 * its average month is more than twice its median month. Such spending is funded by
 * its average monthly cost, not by a typical month.
 */
function funding_spending_is_irregular(array $stats): bool
{
    $average = (float)$stats['average'];
    $median = (float)$stats['median'];
    if ($average <= 0) return false;
    if ($median <= 0) return true;
    return $average > 2 * $median;
}

function funding_statistics(array $observations): array
{
    $values = array_map(fn(array $row): float => (float)$row['actual'], $observations);
    $adjusted = array_map(fn(array $row): float => (float)($row['adjusted'] ?? $row['actual']), $observations);
    $count = count($values);
    if (!$count) return ['count' => 0, 'average' => 0.0, 'median' => 0.0, 'range_low' => 0.0, 'range_high' => 0.0, 'iqr_ratio' => null, 'weighted_mean' => 0.0, 'trend' => 'not available', 'outliers_limited' => 0];
    $weightedTotal = 0.0;
    $weightTotal = 0.0;
    foreach ($adjusted as $index => $value) {
        $weight = $count === 1 ? 1.0 : 1.0 + ($index / ($count - 1));
        $weightedTotal += $value * $weight;
        $weightTotal += $weight;
    }
    $median = funding_median($values);
    $q1 = funding_quantile($values, 0.25);
    $q3 = funding_quantile($values, 0.75);
    $trend = 'not available';
    if ($count >= 6) {
        $older = funding_median(array_slice($values, -6, 3));
        $newer = funding_median(array_slice($values, -3));
        if ($older > 0) {
            $change = ($newer - $older) / $older;
            $trend = $change > 0.10 ? 'increasing' : ($change < -0.10 ? 'decreasing' : 'stable');
        }
    }
    return [
        'count' => $count,
        'average' => round(array_sum($values) / $count, 2),
        'median' => round($median, 2),
        'range_low' => round(min($values), 2),
        'range_high' => round(max($values), 2),
        'iqr_ratio' => $median > 0 ? round(($q3 - $q1) / $median, 4) : null,
        'weighted_mean' => round($weightedTotal / max(1.0, $weightTotal), 2),
        'trend' => $trend,
        'outliers_limited' => count(array_filter($observations, fn(array $row): bool => !empty($row['outlier_limited']))),
    ];
}

function funding_variable_confidence(int $count, ?float $iqrRatio, string $role): string
{
    if ($role === 'daily') {
        if ($count < 3) return 'insufficient';
        if ($count >= 6 && $iqrRatio !== null && $iqrRatio <= 0.35) return 'good';
        if ($count >= 4 && $iqrRatio !== null && $iqrRatio <= 0.50) return 'moderate';
        return 'low';
    }
    if ($count < 4) return 'insufficient';
    if ($count >= 9 && $iqrRatio !== null && $iqrRatio <= 0.35) return 'good';
    if ($count >= 6 && $iqrRatio !== null && $iqrRatio <= 0.50) return 'moderate';
    return 'low';
}

function funding_confidence_label(string $confidence): string
{
    return match ($confidence) {
        'good' => 'Good',
        'moderate' => 'Moderate',
        'low' => 'Low',
        default => 'Insufficient history',
    };
}

function funding_plan_assessment(float $plan, float $reference, string $source): array
{
    if ($reference <= 0) return ['code' => 'none', 'label' => 'No comparison available', 'source' => 'none'];
    $tolerance = max(50.0, 0.10 * $reference);
    $difference = $plan - $reference;
    if (abs($difference) <= $tolerance) {
        $label = $source === 'baseline' ? 'Matches historical baseline' : 'Current funding looks appropriate';
        return ['code' => 'appropriate', 'label' => $label, 'source' => $source];
    }
    if ($difference < 0) {
        if ($source === 'baseline') return ['code' => $plan >= 0.8 * $reference ? 'slightly-under' : 'under', 'label' => 'Below historical baseline', 'source' => 'baseline'];
        return ['code' => $plan >= 0.8 * $reference ? 'slightly-under' : 'under', 'label' => $plan >= 0.8 * $reference ? 'Slightly under recommended' : 'Underfunded', 'source' => 'fin'];
    }
    if ($source === 'baseline') return ['code' => $plan <= 1.3 * $reference ? 'comfortable' : 'above', 'label' => 'Above historical baseline', 'source' => 'baseline'];
    return ['code' => $plan <= 1.3 * $reference ? 'comfortable' : 'above', 'label' => $plan <= 1.3 * $reference ? 'Comfortably funded' : 'Above normal estimate—review intent', 'source' => 'fin'];
}

function funding_recommendation_from_observations(string $role, array $observations): array
{
    $limit = $role === 'daily' ? 6 : 12;
    $used = array_slice(array_values(array_filter($observations, fn(array $row): bool => empty($row['excluded']))), -$limit);
    $irregular = funding_spending_is_irregular(funding_statistics($used));
    // An irregular series has no typical month to compare against, so the outlier
    // limit would only erase the months that actually carry the spending.
    if (!$irregular) $used = funding_adjust_observations($used);
    $stats = funding_statistics($used);
    $amount = null;
    $confidence = 'insufficient';
    $pattern = $role === 'daily' ? 'variable monthly' : 'not enough history';
    $buffer = null;

    if ($role === 'daily') {
        $confidence = funding_variable_confidence(count($used), $stats['iqr_ratio'], 'daily');
        if ($confidence !== 'insufficient') {
            if ($irregular) {
                $pattern = 'irregular monthly';
                $amount = funding_round_up($stats['average'] * 1.05, 50);
            } else {
                $baseline = (0.5 * $stats['median']) + (0.5 * $stats['weighted_mean']);
                $amount = funding_round_up($baseline * 1.05, 50);
            }
            $buffer = 0.05;
        }
    } elseif ($role === 'reserved') {
        $positive = array_values(array_filter($used, fn(array $row): bool => (float)$row['actual'] > 0));
        $positiveRatio = count($used) ? count($positive) / count($used) : 0.0;
        $positiveValues = array_map(fn(array $row): float => (float)$row['actual'], $positive);
        $positiveMedian = funding_median($positiveValues);
        $mad = $positiveValues ? funding_median(array_map(fn(float $value): float => abs($value - $positiveMedian), $positiveValues)) : 0.0;
        $stable = count($used) >= 3
            && $positiveRatio >= 0.8
            && $positiveMedian > 0
            && (max($positiveValues) - min($positiveValues)) <= max(50.0, 0.05 * $positiveMedian)
            && (($mad * 1.4826) / $positiveMedian) <= 0.03;
        if ($stable) {
            $pattern = 'stable monthly';
            $amount = round($positiveMedian, 2);
            $confidence = count($used) >= 6 ? 'good' : 'moderate';
            $buffer = 0.0;
        } elseif ($positiveRatio < 0.5) {
            $pattern = 'irregular reserve';
            if (count($used) >= 12) {
                $amount = funding_round_up((array_sum(array_map(fn(array $row): float => (float)$row['actual'], $used)) / 12) * 1.10, 50);
                $confidence = 'low';
                $buffer = 0.10;
            }
        } else {
            $pattern = $irregular ? 'irregular monthly' : 'variable monthly';
            $confidence = funding_variable_confidence(count($used), $stats['iqr_ratio'], 'reserved');
            if ($confidence !== 'insufficient') {
                if ($irregular) {
                    $amount = funding_round_up($stats['average'] * 1.10, 50);
                } else {
                    $baseline = (0.5 * $stats['median']) + (0.5 * $stats['weighted_mean']);
                    $amount = funding_round_up(max($baseline * 1.10, funding_quantile(array_map(fn(array $row): float => (float)($row['adjusted'] ?? $row['actual']), $used), 0.75)), 50);
                }
                $buffer = 0.10;
            }
        }
    }

    $usedKeys = array_fill_keys(array_map(fn(array $row): string => (string)$row['month'], $used), true);
    foreach ($observations as &$row) {
        $row['used'] = isset($usedKeys[(string)$row['month']]) && empty($row['excluded']);
        $adjustedMatch = null;
        foreach ($used as $usedRow) if ($usedRow['month'] === $row['month']) $adjustedMatch = $usedRow;
        $row['outlier_limited'] = !empty($adjustedMatch['outlier_limited']);
        $row['adjusted'] = $adjustedMatch['adjusted'] ?? (float)$row['actual'];
    }
    unset($row);

    return [
        'amount' => $amount,
        'confidence' => $confidence,
        'confidence_label' => funding_confidence_label($confidence),
        'pattern' => $pattern,
        'basis' => $amount === null ? 'none' : 'actual',
        'representative_months' => count($used),
        'stats' => $stats,
        'buffer' => $buffer,
        'observations' => $observations,
    ];
}

/**
 * Monthly actual spending per Envelope, bucketed by the calendar month of
 * spent_date. A Refund subtracts from the Envelope's spending and a month's
 * total never falls below zero. A Transaction that carries only an Envelope
 * name is kept under that name, so an Envelope can still be resolved by name.
 *
 * Only spending that has actually happened is actual, so a Transaction dated
 * after the as-of date is not counted.
 *
 * The same source table and the same rules are used by the funding
 * recommendations and by the history endpoint, so both read one actual.
 *
 * @return array<string, array<string, array{actual: float}>>
 */
function funding_actual_spending_by_month(PDO $db, string $fromMonth, string $toMonth, ?string $asOf = null): array
{
    $stmt = $db->prepare("SELECT envelope_id,lower(envelope) AS envelope_name,substr(spent_date,1,7) AS month_key,
            MAX(0, SUM(CASE WHEN kind='refund' THEN -ABS(amount) ELSE ABS(amount) END)) AS actual
        FROM transactions WHERE spent_date BETWEEN :from_date AND :to_date AND spent_date <= :as_of
        GROUP BY envelope_id,lower(envelope),month_key");
    $stmt->execute([
        ':from_date' => $fromMonth . '-01',
        ':to_date' => date('Y-m-t', strtotime($toMonth . '-01')),
        ':as_of' => $asOf ?: effective_today(),
    ]);
    $actuals = [];
    foreach ($stmt->fetchAll() as $row) {
        $key = ((int)($row['envelope_id'] ?? 0) > 0 ? 'id:' . (int)$row['envelope_id'] : 'name:' . (string)$row['envelope_name']);
        $actuals[$key][(string)$row['month_key']] = ['actual' => (float)$row['actual']];
    }
    return $actuals;
}

/**
 * The actual spending of one Envelope in one month. The Envelope's own
 * Transactions win; Transactions that only carry the Envelope's name are the
 * fallback, exactly as the funding-recommendation engine resolves them.
 */
function funding_actual_for_envelope(array $actuals, int $envelopeId, string $envelopeName, string $monthKey): float
{
    $idKey = 'id:' . $envelopeId;
    if (isset($actuals[$idKey][$monthKey])) return (float)$actuals[$idKey][$monthKey]['actual'];
    $nameKey = 'name:' . strtolower($envelopeName);
    if (isset($actuals[$nameKey][$monthKey])) return (float)$actuals[$nameKey][$monthKey]['actual'];
    return 0.0;
}

function funding_plan_supported_amount(PDO $db, int $envelopeId, string $historyEnd, float $matchingActual): ?float
{
    if ($matchingActual <= 0 || $historyEnd === '') return null;
    $stmt = $db->prepare("SELECT printf('%04d-%02d', m.year, m.month) AS month_key, e.amount
        FROM entries e JOIN months m ON m.id=e.month_id
        WHERE e.envelope_id=:envelope_id AND e.type<>'income' AND e.status<>'skipped'
          AND printf('%04d-%02d', m.year, m.month) <= :history_end AND e.amount > 0
        ORDER BY m.year, m.month");
    $stmt->execute([':envelope_id' => $envelopeId, ':history_end' => $historyEnd]);
    $plans = $stmt->fetchAll();
    if (count($plans) < 6) return null;
    $plans = array_slice($plans, -6);
    for ($index = 1; $index < count($plans); $index++) {
        if (funding_previous_month($plans[$index]['month_key'] . '-01') !== $plans[$index - 1]['month_key']) return null;
    }
    $values = array_map(fn(array $row): float => (float)$row['amount'], $plans);
    $median = funding_median($values);
    if ($median <= 0) return null;
    foreach ($values as $value) if (abs($value - $median) / $median > 0.02) return null;
    if (abs($matchingActual - $median) / $median > 0.02) return null;
    return round($median, 2);
}

function build_funding_recommendations(PDO $db, int $targetYear, int $targetMonth, ?string $today = null): array
{
    if ($targetYear < 1900 || $targetYear > 2200 || $targetMonth < 1 || $targetMonth > 12) throw new InvalidArgumentException('Invalid recommendation target month');
    $today = $today ?: effective_today();
    $targetKey = funding_month_key($targetYear, $targetMonth);
    $targetStmt = $db->prepare('SELECT id, exchange_rate FROM months WHERE year=:year AND month=:month');
    $targetStmt->execute([':year' => $targetYear, ':month' => $targetMonth]);
    $target = $targetStmt->fetch();
    if (!$target) throw new RuntimeException('Target month not found');

    $currentKey = substr($today, 0, 7);
    $historyEnd = funding_previous_month(($targetKey <= $currentKey ? $targetKey : $currentKey) . '-01');
    $firstTracked = (string)$db->query("SELECT COALESCE(substr(MIN(spent_date), 1, 7), '') FROM transactions WHERE spent_date <= " . $db->quote($today))->fetchColumn();
    $historyMonths = ($firstTracked !== '' && $firstTracked <= $historyEnd) ? funding_month_sequence($firstTracked, $historyEnd) : [];

    $actuals = $historyMonths ? funding_actual_spending_by_month($db, $historyMonths[0], $historyEnd, $today) : [];

    $targetPlans = [];
    $stmt = $db->prepare("SELECT envelope_id, amount, status FROM entries WHERE month_id=:month_id AND type<>'income'");
    $stmt->execute([':month_id' => (int)$target['id']]);
    foreach ($stmt->fetchAll() as $row) $targetPlans[(int)$row['envelope_id']] = $row;

    $exclusions = [];
    foreach ($db->query('SELECT envelope_id, year, month, reason FROM recommendation_exclusions ORDER BY year, month')->fetchAll() as $row) {
        $exclusions[(int)$row['envelope_id']][funding_month_key((int)$row['year'], (int)$row['month'])] = (string)$row['reason'];
    }

    $results = [];
    foreach (get_envelopes($db, $today) as $envelope) {
        // A Fixed envelope keeps the monthly amount its owner set. FIN never
        // derives a different amount for it and never suggests one.
        if ((string)$envelope['amount_source'] === 'fixed') continue;
        $envelopeId = (int)$envelope['id'];
        $role = (string)$envelope['role'];
        $observations = [];
        foreach ($historyMonths as $monthKey) {
            $actual = funding_actual_for_envelope($actuals, $envelopeId, (string)$envelope['name'], $monthKey);
            $reason = $exclusions[$envelopeId][$monthKey] ?? null;
            $observations[] = [
                'month' => $monthKey,
                'actual' => round($actual, 2),
                'excluded' => $reason !== null,
                'exclusion_reason' => $reason,
            ];
        }
        if ($role === 'savings') {
            $calculation = [
                'amount' => null,
                'confidence' => 'insufficient',
                'confidence_label' => 'Not applicable',
                'pattern' => 'goal-driven',
                'basis' => 'none',
                'representative_months' => 0,
                'stats' => funding_statistics([]),
                'buffer' => null,
                'observations' => $observations,
            ];
        } else {
            $calculation = funding_recommendation_from_observations($role, $observations);
            if ($role === 'reserved' && $calculation['amount'] === null) {
                $matchingActual = 0.0;
                foreach ($observations as $row) $matchingActual = max($matchingActual, (float)$row['actual']);
                $planSupported = funding_plan_supported_amount($db, $envelopeId, $historyEnd, $matchingActual);
                if ($planSupported !== null) {
                    $calculation['amount'] = $planSupported;
                    $calculation['confidence'] = 'low';
                    $calculation['confidence_label'] = 'Low';
                    $calculation['pattern'] = 'stable monthly';
                    $calculation['basis'] = 'plan-supported';
                    $calculation['buffer'] = 0.0;
                }
            }
        }
        $plan = $targetPlans[$envelopeId] ?? ['amount' => 0.0, 'status' => 'planned'];
        $planAmount = (float)$plan['amount'];
        $planStatus = (string)$plan['status'];
        $baseline = isset($envelope['manual_baseline']) ? $envelope['manual_baseline'] : null;
        if ($planStatus === 'skipped') {
            $assessment = ['code' => 'skipped', 'label' => 'Skipped this month', 'source' => 'none'];
        } elseif (in_array($calculation['confidence'], ['moderate', 'good'], true) && $calculation['amount'] !== null) {
            $assessment = funding_plan_assessment($planAmount, (float)$calculation['amount'], 'fin');
        } elseif ($baseline !== null) {
            $assessment = funding_plan_assessment($planAmount, (float)$baseline, 'baseline');
        } else {
            $assessment = ['code' => 'none', 'label' => $role === 'savings' ? 'Goal-driven—no historical recommendation' : 'Not enough history yet', 'source' => 'none'];
        }
        $minimum = $role === 'daily' ? 3 : ($role === 'reserved' ? 4 : null);
        $results[] = array_merge($calculation, [
            'envelope_id' => $envelopeId,
            'envelope_name' => (string)$envelope['name'],
            'role' => $role,
            'currency' => (string)$envelope['currency'],
            'current_plan' => round($planAmount, 2),
            'current_plan_status' => $planStatus,
            'manual_baseline' => $baseline,
            'manual_baseline_note' => (string)($envelope['manual_baseline_note'] ?? ''),
            'minimum_months' => $minimum,
            'months_needed' => $minimum === null ? null : max(0, $minimum - (int)$calculation['representative_months']),
            'assessment' => $assessment,
        ]);
    }

    return [
        'target' => ['year' => $targetYear, 'month' => $targetMonth, 'exchange_rate' => (float)$target['exchange_rate']],
        'tracking_start_month' => $firstTracked !== '' ? $firstTracked : null,
        'history_end_month' => $historyMonths ? $historyEnd : null,
        'recommendations' => $results,
    ];
}

function save_recommendation_exclusion(PDO $db, int $envelopeId, int $year, int $month, bool $excluded, string $reason = ''): void
{
    if ($envelopeId <= 0 || !(int)$db->query('SELECT COUNT(*) FROM envelopes WHERE id=' . $envelopeId)->fetchColumn()) throw new InvalidArgumentException('Envelope not found');
    if ($year < 1900 || $year > 2200 || $month < 1 || $month > 12) throw new InvalidArgumentException('Invalid exclusion month');
    if (!$excluded) {
        $stmt = $db->prepare('DELETE FROM recommendation_exclusions WHERE envelope_id=:envelope_id AND year=:year AND month=:month');
        $stmt->execute([':envelope_id' => $envelopeId, ':year' => $year, ':month' => $month]);
        return;
    }
    $stmt = $db->prepare('INSERT INTO recommendation_exclusions (envelope_id, year, month, reason) VALUES (:envelope_id,:year,:month,:reason)
        ON CONFLICT(envelope_id,year,month) DO UPDATE SET reason=excluded.reason');
    $stmt->execute([':envelope_id' => $envelopeId, ':year' => $year, ':month' => $month, ':reason' => trim($reason)]);
}
