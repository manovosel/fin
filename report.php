<?php

declare(strict_types=1);

/**
 * Build the human-readable PDF domain model without changing the detailed XLSX
 * export model. Plans, funding, transactions, and balances remain distinct.
 */
function build_pdf_report_model(PDO $db, array $report): array
{
    $today = effective_today();
    $envelopes = $db->query('SELECT id, name, role, sort_order FROM envelopes ORDER BY sort_order, name')->fetchAll();
    $normalizedEnvelopes = array_map(static fn(array $envelope): array => [
        'id' => (int)$envelope['id'],
        'name' => (string)$envelope['name'],
        'role' => in_array((string)$envelope['role'], ['daily', 'reserved', 'savings'], true) ? (string)$envelope['role'] : 'daily',
        'sort_order' => (int)$envelope['sort_order'],
    ], $envelopes);

    $months = [];
    foreach ($report['months'] as $month) {
        $monthStart = sprintf('%04d-%02d-01', (int)$month['year'], (int)$month['month']);
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $cutoff = min($monthEnd, $today);
        $period = $monthStart > $today ? 'future' : ($monthEnd < $today ? 'past' : 'current');
        $balances = envelope_balances_as_of($db, $cutoff);
        $entries = array_values(array_filter($month['entries'], static fn(array $entry): bool => ($entry['type'] ?? '') !== 'income'));
        $fundingByEntry = report_funding_by_entry_as_of($db, $entries, $cutoff);
        $roleTables = report_role_tables($normalizedEnvelopes, $entries, $month['transactions'], $fundingByEntry, $balances);
        $statusEnvelopes = array_map(static function (array $envelope) use ($balances): array {
            $envelope['balance'] = (float)($balances[$envelope['id']] ?? 0.0);
            return $envelope;
        }, $normalizedEnvelopes);
        $status = report_month_status($month, $month['entries'], $month['transactions'], $statusEnvelopes, $today);
        $adjustments = report_human_adjustments($month['balance_movements'] ?? []);

        $months[] = [
            'id' => (int)$month['id'],
            'year' => (int)$month['year'],
            'month' => (int)$month['month'],
            'label' => month_label($month),
            'period' => $period,
            'cutoff' => $cutoff,
            'exchange_rate' => $month['exchange_rate'] === null ? null : (float)$month['exchange_rate'],
            'currency' => (string)$report['currency'],
            'helper_currency' => (string)$report['helper_currency'],
            'is_as_of' => $period !== 'past',
            'overview' => [
                'planned_income' => (float)$month['summary']['income'],
                'recorded_income' => isset($month['recorded_income']) ? (float)$month['recorded_income'] : null,
                'planned_allocations' => (float)$month['summary']['expenses'],
                'unallocated_plan_balance' => (float)$month['summary']['expected_leftover'],
                'actual_expenses' => (float)$month['summary']['actual_spent'],
                'status' => $status,
            ],
            'daily' => $roleTables['daily'],
            'reserved' => $roleTables['reserved'],
            'savings' => $roleTables['savings'],
            'actual_expenses' => array_values($month['transactions']),
            'transfers_adjustments' => $adjustments['events'],
            'technical_adjustments_omitted' => $adjustments['technical_omitted'],
        ];
    }

    return [
        'from' => (string)$report['from'],
        'to' => (string)$report['to'],
        'created_at' => (string)$report['created_at'],
        'currency' => (string)$report['currency'],
        'helper_currency' => (string)$report['helper_currency'],
        'generated_date' => $today,
        'months' => $months,
        'range' => report_range_totals($months),
    ];
}

/**
 * Range totals for a multi-month report, computed from the already-built
 * month models. No new query and no new derivation: every figure is a sum of
 * figures the report already prints.
 *
 * The headline totals are the sums of the per-month headline figures, so a
 * range total always equals the sum of the month figures a reader can see.
 * The per-role rows are the sums of the per-month role tables. History whose
 * Envelope no longer exists has no role, so it is part of the headline totals
 * but not of a role row.
 */
function report_range_totals(array $months): ?array
{
    if (count($months) < 2) return null;
    $roles = ['daily', 'reserved', 'savings'];
    $total = ['planned' => 0.0, 'funded' => 0.0, 'actual' => 0.0];
    $byRole = [];
    foreach ($roles as $role) $byRole[$role] = ['planned' => 0.0, 'funded' => 0.0, 'actual' => 0.0];
    foreach ($months as $month) {
        $total['planned'] += (float)($month['overview']['planned_allocations'] ?? 0.0);
        $total['actual'] += (float)($month['overview']['actual_expenses'] ?? 0.0);
        foreach ($roles as $role) {
            $roleTotals = $month[$role]['totals'] ?? [];
            foreach (['planned', 'funded', 'actual'] as $key) {
                $value = (float)($roleTotals[$key] ?? 0.0);
                $byRole[$role][$key] += $value;
                if ($key === 'funded') $total['funded'] += $value;
            }
        }
    }
    $total['difference'] = $total['planned'] - $total['actual'];
    return ['total' => $total, 'roles' => $byRole];
}

/**
 * Actual Income events only, bucketed by the calendar month of event_date.
 * Transfer and Refund events are ordinary account events too and never count
 * as Income. The same effective-to-date rule as the report applies, so a
 * future-dated Income event is not yet actual.
 *
 * Shared by the report exports and the read-only history API, so both bucket
 * Income exactly the same way. This is the one query; the rule lives here.
 */
function report_income_events_by_month(PDO $db, string $from, string $to): array
{
    $fromDate = $from . '-01';
    $toDate = date('Y-m-t', strtotime($to . '-01'));
    $effectiveToDate = min($toDate, effective_today());
    if ($fromDate > $effectiveToDate) return [];

    $stmt = $db->prepare("SELECT event_date, amount, note FROM account_events WHERE event_type = 'income' AND event_date BETWEEN :from_date AND :to_date ORDER BY event_date, id");
    $stmt->execute([':from_date' => $fromDate, ':to_date' => $effectiveToDate]);
    $byMonth = [];
    foreach ($stmt->fetchAll() as $row) {
        $byMonth[substr((string)$row['event_date'], 0, 7)][] = [
            'date' => (string)$row['event_date'],
            'amount' => (float)$row['amount'],
            'note' => (string)$row['note'],
        ];
    }
    return $byMonth;
}

/**
 * The income difference between the recorded Income of a month and the planned
 * income of the same month.
 *
 * It exists only when both figures exist. A month without a recorded Income
 * event has no recorded income figure at all, so no difference is derived from
 * it: unreconciled income never reads as zero. Planned and recorded income
 * stay separate numbers; nothing is netted or inferred from one another.
 */
function report_income_difference(?float $recordedIncome, ?float $plannedIncome): ?float
{
    if ($recordedIncome === null || $plannedIncome === null) return null;
    return $recordedIncome - $plannedIncome;
}

/**
 * The month overview figures, in presentation order.
 *
 * Recorded income exists only for a month with at least one Income event, so
 * a month without one prints no income figure and no income difference at all.
 */
function report_overview_cells(array $overview): array
{
    $plannedIncome = isset($overview['planned_income']) ? (float)$overview['planned_income'] : null;
    $recordedIncome = isset($overview['recorded_income']) ? (float)$overview['recorded_income'] : null;
    $cells = [['label' => 'Planned income', 'value' => (float)$plannedIncome]];
    if ($recordedIncome !== null) {
        $cells[] = ['label' => 'Recorded income', 'value' => $recordedIncome];
        $difference = report_income_difference($recordedIncome, $plannedIncome);
        if ($difference !== null) $cells[] = ['label' => 'Income difference', 'value' => $difference];
    }
    $cells[] = ['label' => 'Plan', 'value' => (float)($overview['planned_allocations'] ?? 0.0)];
    $cells[] = ['label' => 'Plan difference', 'value' => (float)($overview['unallocated_plan_balance'] ?? 0.0)];
    $cells[] = ['label' => 'Actual spending', 'value' => (float)($overview['actual_expenses'] ?? 0.0)];
    return $cells;
}

/** The month overview box; the cells share the width evenly. */
function report_overview_html(array $overview): string
{
    $cells = report_overview_cells($overview);
    $width = 100 / max(1, count($cells));
    $html = '<section class="overview">';
    foreach ($cells as $cell) {
        $html .= '<div style="width:' . number_format($width, 4, '.', '') . '%"><span>' . report_html((string)$cell['label']) . '</span><strong>' . report_money((float)$cell['value']) . '</strong></div>';
    }
    return $html . '</section>';
}

function report_funding_by_entry_as_of(PDO $db, array $entries, string $cutoff): array
{
    $ids = array_values(array_filter(array_map(static fn(array $entry): int => (int)($entry['id'] ?? 0), $entries)));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT entry_id, SUM(amount) AS amount FROM envelope_movements WHERE type = 'funding' AND movement_date <= ? AND entry_id IN ($placeholders) GROUP BY entry_id");
    $stmt->execute(array_merge([$cutoff], $ids));
    $result = [];
    foreach ($stmt->fetchAll() as $row) $result[(int)$row['entry_id']] = (float)$row['amount'];
    return $result;
}

function report_role_tables(array $envelopes, array $entries, array $transactions, array $fundingByEntry, array $balances): array
{
    $entriesByEnvelope = [];
    foreach ($entries as $entry) {
        if (($entry['status'] ?? '') === 'skipped') continue;
        $envelopeId = (int)($entry['envelope_id'] ?? 0);
        if ($envelopeId <= 0) continue;
        $entriesByEnvelope[$envelopeId][] = $entry;
    }

    $envelopeByName = [];
    foreach ($envelopes as $envelope) $envelopeByName[mb_strtolower(trim($envelope['name']), 'UTF-8')] = (int)$envelope['id'];
    $actualByEnvelope = [];
    foreach ($transactions as $transaction) {
        $envelopeId = (int)($transaction['envelope_id'] ?? 0);
        if ($envelopeId <= 0) $envelopeId = $envelopeByName[mb_strtolower(trim((string)($transaction['envelope'] ?? '')), 'UTF-8')] ?? 0;
        if ($envelopeId > 0) $actualByEnvelope[$envelopeId] = ($actualByEnvelope[$envelopeId] ?? 0.0) + transaction_spending_amount($transaction);
    }

    $tables = [];
    foreach (['daily', 'reserved', 'savings'] as $role) {
        $rows = [];
        foreach ($envelopes as $envelope) {
            if ($envelope['role'] !== $role) continue;
            $envelopeEntries = $entriesByEnvelope[$envelope['id']] ?? [];
            $planned = array_sum(array_map(static fn(array $entry): float => (float)$entry['amount'], $envelopeEntries));
            $funded = 0.0;
            foreach ($envelopeEntries as $entry) $funded += (float)($fundingByEntry[(int)$entry['id']] ?? 0.0);
            $actual = (float)($actualByEnvelope[$envelope['id']] ?? 0.0);
            $balance = (float)($balances[$envelope['id']] ?? 0.0);
            if (abs($planned) < 0.005 && abs($funded) < 0.005 && abs($actual) < 0.005 && abs($balance) < 0.005) continue;
            $rows[] = [
                'envelope_id' => (int)$envelope['id'],
                'envelope' => (string)$envelope['name'],
                'planned' => $planned,
                'funded' => $funded,
                'actual' => $actual,
                'difference' => $planned - $actual,
                'balance' => $balance,
            ];
        }
        $tables[$role] = [
            'rows' => $rows,
            'totals' => [
                'planned' => array_sum(array_column($rows, 'planned')),
                'funded' => array_sum(array_column($rows, 'funded')),
                'actual' => array_sum(array_column($rows, 'actual')),
                'difference' => array_sum(array_column($rows, 'difference')),
                'balance' => array_sum(array_column($rows, 'balance')),
            ],
        ];
    }
    return $tables;
}

/**
 * The safe-to-spend rule, shared by the report model and the history API.
 *
 * Port of Finance.safeToSpend and Finance.remainingDaysInMonth in finance.js:
 * only Daily spend envelopes contribute, their total is spread over the days
 * that remain in the Month (today included), and the result is rounded down to
 * a whole 10. The rule never produces a negative figure.
 *
 * `zero_reason` names the condition behind a 0 result:
 * `negative_daily_total` (the Daily total is below zero, the label is
 * "over plan"), `zero_daily_total` (the Daily total is exactly zero), and
 * `below_rounding_step` (the per-day amount is positive but smaller than the
 * rounding step of 10).
 *
 * @return array{total_days:int,remaining_days:int,raw_per_day:float,rounded_amount:float,amount:float,label:string,zero_reason:?string}
 */
function report_safe_to_spend(float $dailyRemaining, int $year, int $month, string $today): array
{
    $totalDays = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
    $todayDate = new DateTimeImmutable($today);
    $isCurrentMonth = (((int)$todayDate->format('Y') * 12) + (int)$todayDate->format('n')) === (($year * 12) + $month);
    $remainingDays = $isCurrentMonth ? max(1, $totalDays - (int)$todayDate->format('j') + 1) : $totalDays;
    $rawPerDay = $dailyRemaining / $remainingDays;
    $roundedAmount = $rawPerDay > 0 ? floor($rawPerDay / 10) * 10 : 0.0;
    $amount = max(0.0, $roundedAmount);
    $label = $rawPerDay < 0 ? 'over plan' : ($roundedAmount > 0 ? 'safe to spend today' : '');
    $zeroReason = null;
    if ($amount === 0.0) {
        if ($dailyRemaining < 0) $zeroReason = 'negative_daily_total';
        elseif ($dailyRemaining === 0.0) $zeroReason = 'zero_daily_total';
        else $zeroReason = 'below_rounding_step';
    }
    return [
        'total_days' => $totalDays,
        'remaining_days' => $remainingDays,
        'raw_per_day' => $rawPerDay,
        'rounded_amount' => $roundedAmount,
        'amount' => $amount,
        'label' => $label,
        'zero_reason' => $zeroReason,
    ];
}

/** Port of Finance.monthStatus for report use; no report-specific status rules. */
function report_month_status(array $month, array $entries, array $transactions, array $envelopes, string $today): array
{
    $todayDate = new DateTimeImmutable($today);
    $selectedKey = ((int)$month['year'] * 12) + (int)$month['month'];
    $todayKey = ((int)$todayDate->format('Y') * 12) + (int)$todayDate->format('n');
    $period = $selectedKey < $todayKey ? 'past' : ($selectedKey > $todayKey ? 'future' : 'current');
    $prefix = sprintf('%04d-%02d', (int)$month['year'], (int)$month['month']);
    $roles = ['daily', 'reserved', 'savings'];
    $envelopeById = [];
    $envelopeByName = [];
    foreach ($envelopes as $envelope) {
        $envelopeById[(int)$envelope['id']] = $envelope;
        $envelopeByName[mb_strtolower(trim((string)$envelope['name']), 'UTF-8')] = $envelope;
    }
    $roleTotals = array_fill_keys($roles, ['planned' => 0.0, 'funded' => 0.0, 'overdue' => 0.0]);
    $defaultDueDate = date('Y-m-t', strtotime($prefix . '-01'));
    foreach ($entries as $entry) {
        if (($entry['type'] ?? '') === 'income' || ($entry['status'] ?? '') === 'skipped' || (float)($entry['amount'] ?? 0) <= 0) continue;
        $envelope = $envelopeById[(int)($entry['envelope_id'] ?? 0)] ?? null;
        if (!$envelope) continue;
        $role = in_array($envelope['role'], $roles, true) ? $envelope['role'] : 'daily';
        $amount = (float)$entry['amount'];
        $roleTotals[$role]['planned'] += $amount;
        if (($entry['status'] ?? '') === 'paid') $roleTotals[$role]['funded'] += $amount;
        elseif ($period === 'current' && (string)($entry['due_date'] ?: $defaultDueDate) <= $today) $roleTotals[$role]['overdue'] += $amount;
    }

    $spentByRole = array_fill_keys($roles, 0.0);
    foreach ($transactions as $transaction) {
        $spentDate = (string)($transaction['spent_date'] ?? '');
        if ($spentDate === '' || !str_starts_with($spentDate, $prefix) || $spentDate > $today) continue;
        $envelope = $envelopeById[(int)($transaction['envelope_id'] ?? 0)]
            ?? $envelopeByName[mb_strtolower(trim((string)($transaction['envelope'] ?? '')), 'UTF-8')]
            ?? null;
        if (!$envelope) continue;
        $role = in_array($envelope['role'], $roles, true) ? $envelope['role'] : 'daily';
        $spentByRole[$role] += transaction_spending_amount($transaction);
    }

    $dailyEnvelopes = array_values(array_filter($envelopes, static fn(array $envelope): bool => ($envelope['role'] ?? 'daily') === 'daily'));
    $dailyRemaining = array_sum(array_map(static fn(array $envelope): float => (float)($envelope['balance'] ?? 0.0), $dailyEnvelopes));
    $safe = report_safe_to_spend($dailyRemaining, (int)$month['year'], (int)$month['month'], $today);
    $totalDays = $safe['total_days'];
    $remainingDays = $safe['remaining_days'];
    $rawPerDay = $safe['raw_per_day'];
    $dailyDifference = $roleTotals['daily']['planned'] - $spentByRole['daily'];
    $dailyPool = $dailyRemaining + $spentByRole['daily'];
    $moneyRemainingFraction = $dailyPool > 0 ? $dailyRemaining / $dailyPool : 1.0;
    $monthRemainingFraction = $remainingDays / $totalDays;
    $paceRatio = $monthRemainingFraction > 0 ? $moneyRemainingFraction / $monthRemainingFraction : 1.0;
    $dailyState = 'on-track';
    if ($period === 'current' && $dailyEnvelopes) {
        if ($dailyRemaining < 0) $dailyState = 'needs-attention';
        elseif ($dailyRemaining == 0.0) $dailyState = (int)$todayDate->format('j') === $totalDays ? 'on-track' : 'needs-attention';
        elseif ($paceRatio < 0.75) $dailyState = 'getting-tight';
    } elseif ($period === 'past' && $dailyDifference < 0) {
        $dailyState = 'needs-attention';
    }

    $reservedPending = max(0.0, $roleTotals['reserved']['planned'] - $roleTotals['reserved']['funded']);
    $savingsPending = max(0.0, $roleTotals['savings']['planned'] - $roleTotals['savings']['funded']);
    $reservedState = (($period === 'past' && $reservedPending > 0) || ($period === 'current' && $roleTotals['reserved']['overdue'] > 0)) ? 'needs-attention' : 'on-track';
    $savingsState = (($period === 'past' && $savingsPending > 0) || ($period === 'current' && $roleTotals['savings']['overdue'] > 0)) ? 'needs-attention' : 'on-track';
    $severity = ['on-track' => 0, 'getting-tight' => 1, 'needs-attention' => 2];
    $state = 'on-track';
    if ($period === 'future') $state = 'not-started';
    else foreach ([$dailyState, $reservedState, $savingsState] as $candidate) if ($severity[$candidate] > $severity[$state]) $state = $candidate;
    $labels = ['on-track' => 'On track', 'getting-tight' => 'Getting tight', 'needs-attention' => 'Needs attention', 'not-started' => 'Not started'];

    return [
        'period' => $period,
        'state' => $state,
        'label' => $labels[$state],
        'daily' => [
            'planned' => $roleTotals['daily']['planned'],
            'spent' => $spentByRole['daily'],
            'remaining' => $dailyRemaining,
            'difference' => $dailyDifference,
            'pool' => $dailyPool,
            'moneyRemainingFraction' => $moneyRemainingFraction,
            'monthRemainingFraction' => $monthRemainingFraction,
            'paceRatio' => $paceRatio,
            'safe' => ['remainingDays' => $remainingDays, 'rawPerDay' => $rawPerDay, 'amount' => $safe['amount'], 'label' => $safe['label']],
            'state' => $dailyState,
        ],
        'reserved' => $roleTotals['reserved'] + ['pending' => $reservedPending, 'state' => $reservedState],
        'savings' => $roleTotals['savings'] + ['pending' => $savingsPending, 'state' => $savingsState],
    ];
}

function report_human_adjustments(array $movements): array
{
    $events = [];
    $technicalOmitted = 0;
    foreach ($movements as $movement) {
        $type = (string)$movement['type'];
        if ($type === 'archive_rollup') {
            $technicalOmitted++;
            continue;
        }
        if (in_array($type, ['transfer_in', 'transfer_reversal_out'], true)) continue;
        if ($type === 'transfer_out') {
            $events[] = [
                'date' => (string)$movement['movement_date'],
                'type' => 'Move money',
                'description' => (string)($movement['source_envelope'] ?? $movement['envelope_name']) . ' → ' . (string)($movement['destination_envelope'] ?? ''),
                'amount' => abs((float)$movement['amount']),
                'note' => (string)$movement['note'],
            ];
            continue;
        }
        if ($type === 'transfer_reversal_in') {
            $events[] = [
                'date' => (string)$movement['movement_date'],
                'type' => 'Undo move money',
                'description' => (string)($movement['destination_envelope'] ?? '') . ' → ' . (string)($movement['source_envelope'] ?? $movement['envelope_name']),
                'amount' => abs((float)$movement['amount']),
                'note' => (string)$movement['note'],
            ];
            continue;
        }
        $labels = ['opening_balance' => 'Starting balance', 'adjustment' => 'Reconciliation', 'reversal' => 'Undo adjustment'];
        if (!isset($labels[$type])) continue;
        $events[] = [
            'date' => (string)$movement['movement_date'],
            'type' => $labels[$type],
            'description' => (string)$movement['envelope_name'],
            'amount' => (float)$movement['amount'],
            'note' => (string)$movement['note'],
        ];
    }
    return ['events' => $events, 'technical_omitted' => $technicalOmitted];
}

function export_report_pdf(PDO $db, array $report): never
{
    $filename = 'fin-report-' . $report['from'] . '-to-' . $report['to'] . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo build_report_pdf(build_pdf_report_model($db, $report));
    exit;
}

function build_report_pdf(array $report): string
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!class_exists(Dompdf\Dompdf::class)) {
        if (!is_file($autoload)) throw new RuntimeException('PDF renderer dependencies are not installed; run composer install');
        require_once $autoload;
    }
    $options = new Dompdf\Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', false);
    $options->set('isPhpEnabled', false);
    $options->set('isFontSubsettingEnabled', true);
    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml(build_report_pdf_html($report), 'UTF-8');
    $dompdf->render();
    $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
    $dompdf->getCanvas()->page_text(42, 816, 'FIN · Page {PAGE_NUM} of {PAGE_COUNT} · Generated ' . report_datetime_text((string)$report['created_at']), $font, 7.5, [0.35, 0.39, 0.44]);
    return $dompdf->output();
}

function build_report_pdf_html(array $report): string
{
    report_currency_code((string)$report['currency']);
    $months = '';
    foreach ($report['months'] as $index => $month) $months .= report_month_html($month, $index > 0);
    return '<!doctype html><html lang="en"><head><meta charset="UTF-8"><style>' . report_pdf_css() . '</style></head><body>'
        . '<header class="report-header"><div><h1>FIN financial report</h1><p>' . report_html($report['from']) . ' → ' . report_html($report['to']) . '</p></div><div class="generated">Generated<br><strong>' . report_html(report_datetime_text((string)$report['created_at'])) . '</strong></div></header>'
        . report_range_summary_html($report)
        . report_range_daily_chart_html($report['months'])
        . $months . '</body></html>'; 
}

function report_month_html(array $month, bool $pageBreak): string
{
    $overview = $month['overview'];
    $status = $overview['status'];
    $asOf = $month['period'] === 'past' ? 'Closing balances at ' . report_date_text($month['cutoff']) : 'Values as of ' . report_date_text($month['cutoff']);
    $safe = $month['period'] === 'current' ? '<p class="safe-line"><strong>Safe to spend:</strong> ' . report_money((float)$status['daily']['safe']['amount'], 0) . '/day</p>' : '';
    $actualRows = '';
    foreach ($month['actual_expenses'] as $transaction) {
        $isRefund = ($transaction['kind'] ?? 'expense') === 'refund';
        $description = report_html((string)($transaction['note'] ?: '—'));
        if ($isRefund) $description = '<strong>Refund</strong>' . ((string)($transaction['note'] ?? '') !== '' ? ' · ' . $description : '');
        $actualRows .= '<tr class="transaction-' . ($isRefund ? 'refund' : 'expense') . '"><td>' . report_html(report_date_text((string)$transaction['spent_date'])) . '</td><td>' . report_html((string)$transaction['envelope']) . '</td><td>' . $description . '</td><td class="number">' . report_money(transaction_spending_amount($transaction)) . '</td></tr>';
    }
    $actualSection = $actualRows === ''
        ? ''
        : '<section><h3>Actual transactions</h3><table class="expenses"><thead><tr><th>Date</th><th>Envelope</th><th>Description</th><th class="number">Amount</th></tr></thead><tbody>' . $actualRows . '<tr class="total"><td colspan="3">TOTAL ACTUAL SPENDING</td><td class="number">' . report_money($overview['actual_expenses']) . '</td></tr></tbody></table></section>';
    $adjustmentSection = '';
    if ($month['transfers_adjustments']) {
        $rows = '';
        foreach ($month['transfers_adjustments'] as $event) {
            $rows .= '<tr><td>' . report_html(report_date_text($event['date'])) . '</td><td>' . report_html($event['type']) . '</td><td>' . report_html($event['description']) . '<small>' . report_html($event['note']) . '</small></td><td class="number">' . report_money((float)$event['amount']) . '</td></tr>';
        }
        $adjustmentSection = '<section><h3>Transfers &amp; adjustments</h3><table><thead><tr><th>Date</th><th>Event</th><th>Description</th><th class="number">Amount</th></tr></thead><tbody>' . $rows . '</tbody></table></section>';
    }

    return '<article class="month' . ($pageBreak ? ' new-page' : '') . '">'
        . '<div class="month-heading"><div><h2>' . report_html($month['label']) . '</h2><p>' . report_html($asOf) . '</p></div><span class="status status-' . report_html($status['state']) . '">' . report_html($status['label']) . '</span></div>'
        . report_overview_html($overview)
        . report_role_table_html('Daily Spend', $month['daily'], ['Envelope', 'Plan', 'Funded', 'Actual', 'Difference', 'Balance'], ['planned', 'funded', 'actual', 'difference', 'balance']) . $safe
        . report_role_table_html('Reserved', $month['reserved'], ['Envelope', 'Planned', 'Funded', 'Actual', 'Balance'], ['planned', 'funded', 'actual', 'balance'])
        . report_role_table_html('Savings', $month['savings'], ['Envelope', 'Planned contribution', 'Contributed', 'Used', 'Balance'], ['planned', 'funded', 'actual', 'balance'], 'keep-together')
        . $actualSection
        . $adjustmentSection . '</article>';
}

function report_role_table_html(string $title, array $table, array $headers, array $keys, string $class = ''): string
{
    $rows = '';
    foreach ($table['rows'] as $row) {
        $rows .= '<tr><td>' . report_html($row['envelope']) . '</td>';
        foreach ($keys as $key) $rows .= '<td class="number' . (($key === 'difference' && (float)$row[$key] < 0) ? ' negative' : '') . '">' . report_money((float)$row[$key]) . '</td>';
        $rows .= '</tr>';
    }
    if ($rows === '') $rows = '<tr><td colspan="' . count($headers) . '" class="empty">No activity or balance for this role.</td></tr>';
    $rows .= '<tr class="total"><td>TOTAL</td>';
    foreach ($keys as $key) $rows .= '<td class="number' . (($key === 'difference' && (float)$table['totals'][$key] < 0) ? ' negative' : '') . '">' . report_money((float)$table['totals'][$key]) . '</td>';
    $rows .= '</tr>';
    return '<section' . ($class !== '' ? ' class="' . report_html($class) . '"' : '') . '><h3>' . report_html($title) . '</h3><table><thead><tr>' . implode('', array_map(static fn(string $header): string => '<th' . ($header === 'Envelope' ? '' : ' class="number"') . '>' . report_html($header) . '</th>', $headers)) . '</tr></thead><tbody>' . $rows . '</tbody></table></section>';
}

/**
 * The compact range summary of a multi-month report: the range totals and the
 * same Plan / Funded / Actual split per role. A single-month export has no
 * range block.
 */
function report_range_summary_html(array $report): string
{
    $months = $report['months'] ?? [];
    $range = (isset($report['range']) && is_array($report['range'])) ? $report['range'] : report_range_totals($months);
    if (!$range) return '';
    $labels = ['daily' => 'Daily Spend', 'reserved' => 'Reserved', 'savings' => 'Savings'];
    $rows = '';
    foreach ($labels as $role => $label) {
        $roleTotals = $range['roles'][$role];
        $rows .= '<tr><td>' . report_html($label) . '</td><td class="number">' . report_money((float)$roleTotals['planned']) . '</td><td class="number">' . report_money((float)$roleTotals['funded']) . '</td><td class="number">' . report_money((float)$roleTotals['actual']) . '</td></tr>';
    }
    $total = $range['total'];
    $first = (string)($months[0]['label'] ?? $report['from'] ?? '');
    $last = (string)($months[count($months) - 1]['label'] ?? $report['to'] ?? '');
    return '<section class="range-summary keep-together"><h3>Range totals — ' . report_html($first) . ' → ' . report_html($last) . '</h3>'
        . '<div class="overview"><div><span>Plan</span><strong>' . report_money((float)$total['planned']) . '</strong></div>'
        . '<div><span>Funded</span><strong>' . report_money((float)$total['funded']) . '</strong></div>'
        . '<div><span>Actual spending</span><strong>' . report_money((float)$total['actual']) . '</strong></div>'
        . '<div><span>Difference</span><strong>' . report_money((float)$total['difference']) . '</strong></div></div>'
        . '<table><thead><tr><th>Role</th><th class="number">Plan</th><th class="number">Funded</th><th class="number">Actual</th></tr></thead><tbody>' . $rows . '</tbody></table></section>';
}

function report_range_daily_chart_html(array $months): string
{
    if (count($months) < 2) return '';
    $meaningfulStartedMonths = array_filter($months, static fn(array $month): bool => $month['period'] !== 'future'
        && (abs((float)$month['daily']['totals']['planned']) >= 0.005 || abs((float)$month['daily']['totals']['actual']) >= 0.005));
    if (count($meaningfulStartedMonths) < 2) return '';
    $rows = array_map(static fn(array $month): array => [
        'envelope' => (string)$month['label'],
        'planned' => (float)$month['daily']['totals']['planned'],
        'actual' => (float)$month['daily']['totals']['actual'],
    ], $months);
    return report_plan_actual_chart_html('Daily Spend — Plan vs Actual by month', $rows, 'range-chart');
}

function report_plan_actual_chart_html(string $title, array $rows, string $extraClass = ''): string
{
    if (!$rows) return '';
    $maximum = 0.0;
    foreach ($rows as $row) $maximum = max($maximum, abs((float)$row['planned']), abs((float)$row['actual']));
    if ($maximum <= 0) $maximum = 1.0;
    $body = '';
    foreach ($rows as $row) {
        $planWidth = max(0.0, min(100.0, (abs((float)$row['planned']) / $maximum) * 100));
        $actualWidth = max(0.0, min(100.0, (abs((float)$row['actual']) / $maximum) * 100));
        $body .= '<tbody class="chart-pair"><tr><th class="chart-label" rowspan="2">' . report_html((string)$row['envelope']) . '</th><td class="chart-series">P</td><td class="chart-track-cell"><span class="chart-track"><span class="chart-bar chart-plan" style="width:' . number_format($planWidth, 2, '.', '') . '%"></span></span></td><td class="number chart-value">' . report_money((float)$row['planned']) . '</td></tr>'
            . '<tr><td class="chart-series">A</td><td class="chart-track-cell"><span class="chart-track"><span class="chart-bar chart-actual" style="width:' . number_format($actualWidth, 2, '.', '') . '%"></span></span></td><td class="number chart-value">' . report_money((float)$row['actual']) . '</td></tr></tbody>';
    }
    return '<section class="bar-chart-section keep-together' . ($extraClass !== '' ? ' ' . report_html($extraClass) : '') . '"><div class="chart-heading"><h3>' . report_html($title) . '</h3><span><i class="legend-plan"></i>Plan&nbsp;&nbsp;<i class="legend-actual"></i>Actual</span></div><table class="bar-chart">' . $body . '</table></section>';
}

function report_pdf_css(): string
{
    return <<<'CSS'
@page { margin: 22mm 14mm 18mm; }
* { box-sizing: border-box; }
body { margin: 0; color: #1f2933; font-family: "DejaVu Sans", sans-serif; font-size: 9px; line-height: 1.35; }
.report-header { display: table; width: 100%; border-bottom: 2px solid #243447; padding-bottom: 8px; margin-bottom: 16px; }
.report-header > div { display: table-cell; vertical-align: bottom; }
.report-header h1 { margin: 0; font-size: 21px; color: #172331; }
.report-header p { margin: 2px 0 0; color: #627080; }
.generated { text-align: right; color: #627080; font-size: 8px; }
.month.new-page { page-break-before: always; }
.month-heading { display: table; width: 100%; margin: 0 0 10px; }
.month-heading > div { display: table-cell; }
.month-heading h2 { margin: 0; font-size: 18px; color: #172331; }
.month-heading p { margin: 2px 0 0; color: #627080; font-size: 8px; }
.status { display: table-cell; width: 100px; vertical-align: middle; text-align: center; border-radius: 12px; padding: 4px 8px; font-weight: bold; }
.status-on-track { color: #176c48; background: #e8f5ee; border: 1px solid #a8d8bf; }
.status-getting-tight { color: #8a5200; background: #fff4dc; border: 1px solid #e6c47a; }
.status-needs-attention { color: #9b2c2c; background: #fdecec; border: 1px solid #e5aaaa; }
.status-not-started { color: #52606d; background: #eef1f4; border: 1px solid #cbd2d9; }
.overview { display: table; table-layout: fixed; width: 100%; border: 1px solid #d9dfe5; background: #f7f9fb; margin-bottom: 12px; }
.overview > div { display: table-cell; width: 25%; padding: 7px 8px; border-right: 1px solid #d9dfe5; }
.overview > div:last-child { border-right: 0; }
.overview span { display: block; color: #627080; font-size: 7.5px; text-transform: uppercase; letter-spacing: .25px; }
.overview strong { display: block; margin-top: 2px; font-size: 10px; white-space: nowrap; }
section { margin: 0 0 9px; page-break-inside: auto; }
.keep-together { page-break-inside: avoid; }
h3 { margin: 0 0 4px; color: #243447; font-size: 11px; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
thead { display: table-header-group; }
tr { page-break-inside: avoid; }
th { background: #edf1f5; color: #44515f; border-bottom: 1px solid #aeb8c2; padding: 3.5px 5px; text-align: left; font-size: 7.5px; }
td { border-bottom: 1px solid #e0e5ea; padding: 3.5px 5px; vertical-align: top; overflow-wrap: break-word; }
th.number, td.number { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
td:first-child, th:first-child { width: 30%; }
.expenses th:nth-child(1), .expenses td:nth-child(1) { width: 15%; }
.expenses th:nth-child(2), .expenses td:nth-child(2) { width: 23%; }
.expenses th:nth-child(3), .expenses td:nth-child(3) { width: 42%; }
.expenses th:nth-child(4), .expenses td:nth-child(4) { width: 20%; }
td small { display: block; color: #6b7785; font-size: 7px; }
.total td { background: #f7f9fb; border-top: 1.5px solid #9ca8b4; border-bottom: 0; font-weight: bold; }
.negative { color: #9b2c2c; }
.empty { color: #73808d; font-style: italic; text-align: center; }
.bar-chart-section { margin: 2px 0 9px; padding: 6px 7px; border: 1px solid #d7dde3; background: #fafbfc; }
.chart-heading { display: table; width: 100%; margin-bottom: 3px; }
.chart-heading h3, .chart-heading > span { display: table-cell; }
.chart-heading > span { color: #596775; font-size: 7px; text-align: right; vertical-align: middle; }
.chart-heading i { display: inline-block; width: 11px; height: 5px; margin-right: 3px; vertical-align: middle; }
.legend-plan { background: #263746; }
.legend-actual { background: #aeb8c2; border: 1px solid #52606d; }
.bar-chart { table-layout: fixed; }
.bar-chart th, .bar-chart td { border: 0; background: transparent; padding: 1px 3px; vertical-align: middle; }
.bar-chart .chart-label { width: 22%; color: #2f3b47; font-size: 7.5px; font-weight: normal; text-align: left; }
.bar-chart .chart-series { width: 3%; color: #596775; font-size: 6.5px; font-weight: bold; text-align: center; }
.bar-chart .chart-track-cell { width: 55%; }
.bar-chart .chart-value { width: 20%; font-size: 7px; }
.chart-track { display: block; width: 100%; height: 6px; background: #e8ecf0; }
.chart-bar { display: block; height: 6px; }
.chart-plan { background: #263746; }
.chart-actual { height: 4px; border: 1px solid #52606d; background: #aeb8c2; }
.chart-pair { page-break-inside: avoid; border-bottom: 1px solid #e2e6ea; }
.range-chart { margin-bottom: 14px; }
.range-summary { margin-bottom: 12px; }
.range-summary .overview { margin-bottom: 6px; }
.safe-line { margin: -5px 0 9px; padding: 4px 7px; background: #edf6f1; color: #225b40; }
CSS;
}

function report_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

function report_currency_code(?string $set = null): string
{
    static $currency = 'DKK';
    if ($set !== null) $currency = currency_code($set, 'Currency');
    return $currency;
}

function report_money(float $value, int $decimals = 2): string
{
    return number_format($value, $decimals, ',', '.') . ' ' . report_currency_code();
}

function report_date_text(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed ? $parsed->format('d/m/Y') : $date;
}

function report_datetime_text(string $dateTime): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $dateTime);
    return $parsed ? $parsed->format('d/m/Y H:i') : $dateTime;
}
