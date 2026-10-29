<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

/**
 * Recorded income in the PDF and the XLSX.
 *
 * Recorded income is the sum of the Income account events of a month. The
 * figure exists only for a month that has at least one Income event, so a
 * month without one prints no income figure and no income difference at all:
 * unreconciled income never reads as zero. Planned income and recorded income
 * stay separate numbers.
 */
final class ReportRecordedIncomeTest extends DatabaseTestCase
{
    private string|false $previousToday;

    protected function setUp(): void
    {
        $this->previousToday = getenv('FIN_TODAY');
        putenv('FIN_TODAY=2026-08-14');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->previousToday === false) putenv('FIN_TODAY');
        else putenv('FIN_TODAY=' . $this->previousToday);
    }

    public function testMonthWithIncomeEventsShowsRecordedIncomeAndIncomeDifference(): void
    {
        $monthId = $this->createMonth(2026, 8);
        $this->db->prepare('UPDATE months SET salary = 10000 WHERE id = ?')->execute([$monthId]);
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $this->fundedAllocation($monthId, $daily, 1000, '2026-08-01');
        $this->transaction($monthId, $daily, 'Envelope A', 300, '2026-08-05', 'Daily spending');

        $account = $this->createAccount('Account Alpha', 'ALPHA-1');
        $reserve = $this->createAccount('Account Beta', 'BETA-1');
        record_account_event($this->db, 'income', 25000, '2026-08-05', null, $account, null, 'August salary');
        record_account_event($this->db, 'income', 750, '2026-08-10', null, $account, null, 'August invoice');
        // Transfer, Refund and Expense events are ordinary account events too
        // and never count as Income.
        record_account_event($this->db, 'transfer', 1000, '2026-08-10', $account, $reserve, null, 'Transfer to reserve');
        record_account_event($this->db, 'refund', 250, '2026-08-12', null, $account, null, 'Refund of purchase');
        record_account_event($this->db, 'expense', 100, '2026-08-13', $account, null, null, 'Expense event');

        $report = get_report_data($this->db, '2026-08', '2026-08');
        $model = build_pdf_report_model($this->db, $report);
        $overview = $model['months'][0]['overview'];

        // Planned and recorded income are separate numbers.
        self::assertEqualsWithDelta(10000, $overview['planned_income'], 0.001);
        self::assertEqualsWithDelta(25750, $overview['recorded_income'], 0.001);

        $html = build_report_pdf_html($model);
        self::assertStringContainsString('Recorded income', $html);
        self::assertStringContainsString('Income difference', $html);
        self::assertStringContainsString('25.750,00 DKK', $html);
        self::assertStringContainsString('15.750,00 DKK', $html);
        self::assertStringNotContainsString('Actual income', $html);

        $rows = $this->xlsxRows(build_report_xlsx($report));
        self::assertEqualsWithDelta(25750, (float)$this->xlsxRowValue($rows, 'Recorded income', 'C'), 0.001);
        self::assertEqualsWithDelta(15750, (float)$this->xlsxRowValue($rows, 'Recorded income', 'F'), 0.001);
        self::assertEqualsWithDelta(10000, (float)$this->xlsxRowValue($rows, 'Income', 'C'), 0.001);
    }

    public function testMonthWithoutIncomeEventsPrintsNoIncomeFigure(): void
    {
        $monthId = $this->createMonth(2026, 8);
        $this->db->prepare('UPDATE months SET salary = 10000 WHERE id = ?')->execute([$monthId]);
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $this->fundedAllocation($monthId, $daily, 1000, '2026-08-01');

        $account = $this->createAccount('Account Alpha', 'ALPHA-1');
        // An ordinary account event that is not an Income event.
        record_account_event($this->db, 'expense', 100, '2026-08-13', $account, null, null, 'Expense event');

        $report = get_report_data($this->db, '2026-08', '2026-08');
        $model = build_pdf_report_model($this->db, $report);

        self::assertNull($model['months'][0]['overview']['recorded_income']);

        $html = build_report_pdf_html($model);
        self::assertStringNotContainsString('Recorded income', $html);
        self::assertStringNotContainsString('Income difference', $html);
        self::assertStringContainsString('Planned income', $html);

        $rows = $this->xlsxRows(build_report_xlsx($report));
        self::assertNull($this->xlsxRowValue($rows, 'Recorded income', 'C'));
        self::assertNull($this->xlsxRowValue($rows, 'Recorded income', 'A'));
    }

    public function testMonthWithPlannedIncomeButNoIncomeEventHasNoRecordedIncome(): void
    {
        $monthId = $this->createMonth(2026, 8);
        $this->db->prepare('UPDATE months SET salary = 12000 WHERE id = ?')->execute([$monthId]);
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $this->fundedAllocation($monthId, $daily, 1000, '2026-08-01');

        $model = build_pdf_report_model($this->db, get_report_data($this->db, '2026-08', '2026-08'));
        $overview = $model['months'][0]['overview'];

        self::assertEqualsWithDelta(12000, $overview['planned_income'], 0.001);
        self::assertNull($overview['recorded_income']);

        $html = build_report_pdf_html($model);
        self::assertStringContainsString('12.000,00 DKK', $html);
        self::assertStringNotContainsString('Recorded income', $html);
        self::assertStringNotContainsString('Income difference', $html);
    }

    public function testFutureDatedIncomeEventIsNotYetActual(): void
    {
        $monthId = $this->createMonth(2026, 8);
        $futureMonthId = $this->createMonth(2026, 9);
        $this->db->prepare('UPDATE months SET salary = 10000 WHERE id = ?')->execute([$monthId]);
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $this->fundedAllocation($monthId, $daily, 1000, '2026-08-01');

        $account = $this->createAccount('Account Alpha', 'ALPHA-1');
        record_account_event($this->db, 'income', 25000, '2026-08-10', null, $account, null, 'August salary');
        // Later than the effective date: not yet actual.
        record_account_event($this->db, 'income', 900, '2026-08-20', null, $account, null, 'Future invoice');
        record_account_event($this->db, 'income', 800, '2026-09-01', null, $account, null, 'September salary');

        $report = get_report_data($this->db, '2026-08', '2026-09');
        $model = build_pdf_report_model($this->db, $report);

        self::assertCount(2, $model['months']);
        self::assertEqualsWithDelta(25000, $model['months'][0]['overview']['recorded_income'], 0.001);
        self::assertNull($model['months'][1]['overview']['recorded_income']);

        $html = build_report_pdf_html($model);
        self::assertStringContainsString('25.000,00 DKK', $html);
        self::assertStringNotContainsString('900,00 DKK', $html);
        self::assertStringNotContainsString('800,00 DKK', $html);
        // The difference uses the recorded income of the month only.
        self::assertStringContainsString('15.000,00 DKK', $html);
    }

    public function testRecordedIncomeUsesTheSameBucketingAsTheHistoryApi(): void
    {
        $monthId = $this->createMonth(2026, 8);
        $this->db->prepare('UPDATE months SET salary = 10000 WHERE id = ?')->execute([$monthId]);
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $this->fundedAllocation($monthId, $daily, 1000, '2026-08-01');

        $account = $this->createAccount('Account Alpha', 'ALPHA-1');
        record_account_event($this->db, 'income', 25000, '2026-08-05', null, $account, null, 'August salary');
        record_account_event($this->db, 'income', 750, '2026-08-10', null, $account, null, 'August invoice');

        $bucketed = report_income_events_by_month($this->db, '2026-08', '2026-08');
        self::assertArrayHasKey('2026-08', $bucketed);
        self::assertCount(2, $bucketed['2026-08']);
        self::assertEqualsWithDelta(25750, (float)array_sum(array_column($bucketed['2026-08'], 'amount')), 0.001);

        $history = build_history_response($this->db, '2026-08', '2026-08');
        self::assertEqualsWithDelta(25750, $history['months'][0]['income']['total'], 0.001);

        $model = build_pdf_report_model($this->db, get_report_data($this->db, '2026-08', '2026-08'));
        self::assertEqualsWithDelta($history['months'][0]['income']['total'], $model['months'][0]['overview']['recorded_income'], 0.001);
    }

    public function testIncomeDifferenceExistsOnlyWhenBothFiguresExist(): void
    {
        self::assertNull(report_income_difference(null, 10000.0));
        self::assertNull(report_income_difference(25000.0, null));
        self::assertNull(report_income_difference(null, null));
        self::assertEqualsWithDelta(15000.0, report_income_difference(25000.0, 10000.0), 0.001);
        self::assertEqualsWithDelta(-500.0, report_income_difference(9500.0, 10000.0), 0.001);
    }

    private function createAccount(string $name, string $number): int
    {
        return save_account_definition($this->db, ['name' => $name, 'account_number' => $number, 'currency' => 'CURRENCY']);
    }

    private function fundedAllocation(int $monthId, int $envelopeId, float $amount, string $date): int
    {
        return save_entry($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'type' => 'expense',
            'amount' => $amount,
            'status' => 'paid',
            'due_date' => $date,
        ]);
    }

    private function transaction(int $monthId, int $envelopeId, string $envelope, float $amount, string $date, string $note): int
    {
        return save_transaction($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'envelope' => $envelope,
            'amount' => $amount,
            'spent_date' => $date,
            'note' => $note,
        ]);
    }

    /**
     * Read the exported worksheet as rows of column => value. The export ZIP
     * stores its parts uncompressed, so the sheet XML is readable in place.
     */
    private function xlsxRows(string $xlsx): array
    {
        $start = strpos($xlsx, '<sheetData>');
        self::assertNotFalse($start, 'The exported sheet data was not found');
        $end = strpos($xlsx, '</sheetData>');
        self::assertNotFalse($end, 'The exported sheet data was not closed');
        $xml = substr($xlsx, $start, $end - $start);
        $rows = [];
        preg_match_all('/<row r="(\d+)">(.*?)<\/row>/s', $xml, $rowMatches, PREG_SET_ORDER);
        foreach ($rowMatches as $rowMatch) {
            $cells = [];
            preg_match_all('/<c r="([A-Z]+)\d+"[^>]*>(.*?)<\/c>/s', $rowMatch[2], $cellMatches, PREG_SET_ORDER);
            foreach ($cellMatches as $cellMatch) {
                $value = '';
                if (preg_match('/<v>(.*?)<\/v>/s', $cellMatch[2], $number)) $value = $number[1];
                elseif (preg_match('/<is><t>(.*?)<\/t><\/is>/s', $cellMatch[2], $text)) $value = html_entity_decode($text[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                $cells[$cellMatch[1]] = $value;
            }
            $rows[(int)$rowMatch[1]] = $cells;
        }
        return $rows;
    }

    private function xlsxRowValue(array $rows, string $label, string $column): ?float
    {
        foreach ($rows as $cells) {
            if (($cells['A'] ?? '') !== $label) continue;
            return ($cells[$column] ?? '') === '' ? null : (float)$cells[$column];
        }
        return null;
    }
}
