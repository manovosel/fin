<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class ReportPdfTest extends DatabaseTestCase
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

    public function testPdfModelSeparatesPlanActualFundingSavingsUseAndFutureBs(): void
    {
        $monthId = $this->createMonth(2026, 8);
        $this->db->prepare('UPDATE months SET salary = 10000 WHERE id = ?')->execute([$monthId]);
        $daily = $this->createEnvelope('Envelope H', 'daily');
        $reserved = $this->createEnvelope('Envelope I', 'reserved');
        $savings = $this->createEnvelope('Envelope B', 'savings');
        $futureBs = $this->createEnvelope('Envelope D', 'reserved');

        $this->fundedAllocation($monthId, $daily, 1000, '2026-08-01');
        $this->fundedAllocation($monthId, $reserved, 500, '2026-08-02');
        $this->fundedAllocation($monthId, $savings, 2000, '2026-08-03');
        $this->fundedAllocation($monthId, $futureBs, 400, '2026-09-01', true);
        $this->transaction($monthId, $daily, 'Envelope H', 300, '2026-08-05', 'Fuel');
        $this->transaction($monthId, $reserved, 'Envelope I', 500, '2026-08-06', 'Protected bill');
        $this->transaction($monthId, $savings, 'Envelope B', 250, '2026-08-07', 'Protected money used');

        $report = build_pdf_report_model($this->db, get_report_data($this->db, '2026-08', '2026-08'));
        $month = $report['months'][0];

        self::assertEqualsWithDelta(10000, $month['overview']['planned_income'], 0.001);
        self::assertEqualsWithDelta(3900, $month['overview']['planned_allocations'], 0.001);
        self::assertEqualsWithDelta(6100, $month['overview']['unallocated_plan_balance'], 0.001);
        self::assertEqualsWithDelta(1050, $month['overview']['actual_expenses'], 0.001);
        self::assertEqualsWithDelta(1000, $month['daily']['totals']['planned'], 0.001);
        self::assertEqualsWithDelta(300, $month['daily']['totals']['actual'], 0.001);
        self::assertEqualsWithDelta(700, $month['daily']['totals']['difference'], 0.001);
        self::assertEqualsWithDelta(900, $month['reserved']['totals']['planned'], 0.001);
        self::assertEqualsWithDelta(900, $month['reserved']['totals']['funded'], 0.001);
        self::assertEqualsWithDelta(500, $month['reserved']['totals']['actual'], 0.001);
        self::assertEqualsWithDelta(2000, $month['savings']['totals']['planned'], 0.001);
        self::assertEqualsWithDelta(2000, $month['savings']['totals']['funded'], 0.001);
        self::assertEqualsWithDelta(250, $month['savings']['totals']['actual'], 0.001);
        self::assertCount(3, $month['actual_expenses']);
        self::assertSame('2026-08-14', $month['cutoff']);
        self::assertEqualsWithDelta(30, $month['overview']['status']['daily']['safe']['amount'], 0.001);
        self::assertSame(1, $this->transactionCountForEntryNamed('Envelope D'), 'Funded BS is stored as a scheduled transaction but is not actual yet');
    }

    public function testCrossMonthAndCrossYearBsExpenseAppearsExactlyOnceBySpentDate(): void
    {
        $decemberId = $this->createMonth(2025, 12);
        $januaryId = $this->createMonth(2026, 1);
        $reserved = $this->createEnvelope('Envelope I', 'reserved');
        $entryId = $this->fundedAllocation($decemberId, $reserved, 750, '2026-01-01', true);
        $this->db->prepare("UPDATE envelope_movements SET movement_date = '2025-12-20' WHERE entry_id = ? AND type = 'funding'")->execute([$entryId]);

        $model = build_pdf_report_model($this->db, get_report_data($this->db, '2025-12', '2026-01'));
        self::assertCount(2, $model['months']);
        self::assertCount(0, $model['months'][0]['actual_expenses']);
        self::assertCount(1, $model['months'][1]['actual_expenses']);
        self::assertSame('2026-01-01', $model['months'][1]['actual_expenses'][0]['spent_date']);
        self::assertEqualsWithDelta(750, $model['months'][0]['reserved']['totals']['funded'], 0.001);
        self::assertEqualsWithDelta(0, $model['months'][0]['reserved']['totals']['actual'], 0.001);
        self::assertEqualsWithDelta(750, $model['months'][1]['reserved']['totals']['actual'], 0.001);
        self::assertEqualsWithDelta(750, array_sum(array_map(static fn(array $month): float => (float)$month['overview']['actual_expenses'], $model['months'])), 0.001);
        self::assertSame($januaryId, $model['months'][1]['id']);
    }

    public function testHistoricalAndCurrentClosingBalancesUseTheirOwnCutoffs(): void
    {
        $julyId = $this->createMonth(2026, 7);
        $augustId = $this->createMonth(2026, 8);
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $this->movement($daily, $julyId, 'opening_balance', 100, '2026-07-01', 'Opening');
        $this->transaction($julyId, $daily, 'Envelope A', 20, '2026-07-10', 'July expense');
        $this->movement($daily, $augustId, 'adjustment', 500, '2026-08-01', 'August adjustment');
        $this->movement($daily, $augustId, 'adjustment', 1000, '2026-08-20', 'Future adjustment');

        $model = build_pdf_report_model($this->db, get_report_data($this->db, '2026-07', '2026-08'));
        self::assertSame('2026-07-31', $model['months'][0]['cutoff']);
        self::assertEqualsWithDelta(80, $model['months'][0]['daily']['totals']['balance'], 0.001);
        self::assertSame('2026-08-14', $model['months'][1]['cutoff']);
        self::assertEqualsWithDelta(580, $model['months'][1]['daily']['totals']['balance'], 0.001);
    }

    public function testTransfersAndTechnicalMovementsDoNotPolluteExpensesOrNormalAdjustments(): void
    {
        $monthId = $this->createMonth(2026, 8);
        $source = $this->createEnvelope('Envelope B', 'savings');
        $destination = $this->createEnvelope('Envelope A', 'daily');
        $this->movement($source, $monthId, 'opening_balance', 1000, '2026-08-01', 'Starting money');
        transfer_envelope_balance($this->db, [
            'source_envelope_id' => $source,
            'destination_envelope_id' => $destination,
            'amount' => 200,
            'transfer_date' => '2026-08-05',
            'note' => 'Cover spending',
        ]);
        $this->movement($source, $monthId, 'archive_rollup', 50, '2026-08-01', 'Internal rollup');

        $month = build_pdf_report_model($this->db, get_report_data($this->db, '2026-08', '2026-08'))['months'][0];
        self::assertEqualsWithDelta(0, $month['overview']['actual_expenses'], 0.001);
        self::assertCount(2, $month['transfers_adjustments']);
        self::assertSame('Starting balance', $month['transfers_adjustments'][0]['type']);
        self::assertSame('Move money', $month['transfers_adjustments'][1]['type']);
        self::assertSame('Envelope B → Envelope A', $month['transfers_adjustments'][1]['description']);
        self::assertSame(1, $month['technical_adjustments_omitted']);
        $html = build_report_pdf_html(['from' => '2026-08', 'to' => '2026-08', 'created_at' => '2026-08-14 10:00', 'currency' => 'DKK', 'helper_currency' => 'EUR', 'months' => [$month]]);
        self::assertStringContainsString('14/08/2026 10:00', $html);
        self::assertStringContainsString('01/08/2026', $html);
        self::assertStringContainsString('05/08/2026', $html);
        self::assertStringNotContainsString('01.08.2026', $html);
        self::assertStringNotContainsString('2026-08-05', $html);
        self::assertStringNotContainsString('archive_rollup', $html);
        self::assertStringNotContainsString('Internal rollup', $html);
    }

    public function testRangeChartUsesMonthlyDailyTableTotalsOnlyWhenRangeIsMeaningful(): void
    {
        $januaryId = $this->createMonth(2026, 1);
        $februaryId = $this->createMonth(2026, 2);
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $this->fundedAllocation($januaryId, $daily, 1000, '2026-01-01');
        $this->fundedAllocation($februaryId, $daily, 1200, '2026-02-01');
        $this->transaction($januaryId, $daily, 'Envelope A', 800, '2026-01-10', 'January spending');
        $this->transaction($februaryId, $daily, 'Envelope A', 900, '2026-02-10', 'February spending');

        $model = build_pdf_report_model($this->db, get_report_data($this->db, '2026-01', '2026-02'));
        $html = build_report_pdf_html($model);
        self::assertStringContainsString('Daily Spend — Plan vs Actual by month', $html);
        self::assertSame(1, substr_count($html, 'Daily Spend — Plan vs Actual by month'));
        self::assertSame(1, substr_count($html, 'Daily Spend — Plan vs Actual'));
        self::assertEqualsWithDelta(1000, $model['months'][0]['daily']['totals']['planned'], 0.001);
        self::assertEqualsWithDelta(800, $model['months'][0]['daily']['totals']['actual'], 0.001);
        self::assertEqualsWithDelta(1200, $model['months'][1]['daily']['totals']['planned'], 0.001);
        self::assertEqualsWithDelta(900, $model['months'][1]['daily']['totals']['actual'], 0.001);
        self::assertStringNotContainsString('Savings — Planned vs Contributed', $html);
    }

    public function testRefundUsesSignedPdfAndXlsxPresentation(): void
    {
        $monthId = $this->createMonth(2026, 8);
        $envelopeId = $this->createEnvelope('Refund envelope', 'daily');
        $this->movement($envelopeId, $monthId, 'opening_balance', 500, '2026-08-01', 'Opening');
        $this->transaction($monthId, $envelopeId, 'Refund envelope', 100, '2026-08-05', 'Purchase');
        save_transaction($this->db, [
            'month_id' => $monthId, 'envelope_id' => $envelopeId, 'amount' => 40,
            'spent_date' => '2026-08-06', 'note' => 'Merchant credit', 'kind' => 'refund',
        ]);

        $report = get_report_data($this->db, '2026-08', '2026-08');
        $model = build_pdf_report_model($this->db, $report);
        self::assertEqualsWithDelta(60, $model['months'][0]['overview']['actual_expenses'], 0.001);
        self::assertEqualsWithDelta(60, $model['months'][0]['daily']['totals']['actual'], 0.001);
        $html = build_report_pdf_html($model);
        self::assertStringContainsString('Actual spending', $html);
        self::assertStringContainsString('Actual transactions', $html);
        self::assertStringNotContainsString('<th>Type</th>', $html);
        self::assertStringContainsString('Purchase', $html);
        self::assertStringNotContainsString('<strong>Expense</strong>', $html);
        self::assertStringContainsString('<strong>Refund</strong>', $html);
        self::assertStringContainsString('.expenses th:nth-child(3), .expenses td:nth-child(3) { width: 42%; }', $html);
        self::assertStringNotContainsString('.expenses th:nth-child(5)', $html);
        self::assertStringContainsString('-40,00 DKK', $html);
        self::assertStringContainsString('TOTAL ACTUAL SPENDING', $html);

        $xlsx = build_report_xlsx($report);
        self::assertStringContainsString('Actual transactions', $xlsx);
        self::assertStringNotContainsString('<t>Expense</t>', $xlsx);
        self::assertStringContainsString('<t>Refund</t>', $xlsx);
        self::assertStringContainsString('06/08/2026 · Refund envelope', $xlsx);
        self::assertStringNotContainsString('2026-08-06 · Refund envelope', $xlsx);
        self::assertStringContainsString('<v>-40</v>', $xlsx);
        self::assertStringNotContainsString('Actual expense', $xlsx);
    }

    public function testXlsxLeavesHelperCellsBlankWhenExchangeRateIsUnavailable(): void
    {
        $monthId = save_month($this->db, ['year' => 2026, 'month' => 8, 'exchange_rate' => null]);
        $envelopeId = $this->createEnvelope('No-rate envelope', 'daily');
        save_entry($this->db, ['month_id' => $monthId, 'envelope_id' => $envelopeId, 'amount' => 100]);
        $this->transaction($monthId, $envelopeId, 'No-rate envelope', 20, '2026-08-05', 'Currency remains authoritative');

        $sheet = build_report_xlsx(get_report_data($this->db, '2026-08', '2026-08'));

        self::assertStringContainsString('<c r="C8" s="2"><v>100</v></c>', $sheet);
        self::assertStringContainsString('<c r="C11" s="2"><v>20</v></c>', $sheet);
        self::assertStringNotContainsString('<c r="B8"', $sheet);
        self::assertStringNotContainsString('<c r="B11"', $sheet);
        self::assertStringNotContainsString('INF', strtoupper($sheet));
        self::assertStringNotContainsString('NAN', strtoupper($sheet));
    }

    public function testBackendMonthStatusUsesSameSignedRefundPoolScenariosAsFrontend(): void
    {
        $status = static fn(array $transactions, float $balance): array => report_month_status(
            ['id' => 1, 'year' => 2026, 'month' => 8], [], $transactions,
            [['id' => 1, 'name' => 'Daily', 'role' => 'daily', 'balance' => $balance]], '2026-08-10'
        );
        $partial = $status([
            ['envelope_id' => 1, 'amount' => 100, 'kind' => 'expense', 'spent_date' => '2026-08-05'],
            ['envelope_id' => 1, 'amount' => 40, 'kind' => 'refund', 'spent_date' => '2026-08-06'],
        ], 440);
        $over = $status([
            ['envelope_id' => 1, 'amount' => 100, 'kind' => 'expense', 'spent_date' => '2026-08-05'],
            ['envelope_id' => 1, 'amount' => 150, 'kind' => 'refund', 'spent_date' => '2026-08-06'],
        ], 550);
        $only = $status([
            ['envelope_id' => 1, 'amount' => 50, 'kind' => 'refund', 'spent_date' => '2026-08-06'],
        ], 450);
        self::assertEqualsWithDelta(60, $partial['daily']['spent'], 0.001);
        self::assertEqualsWithDelta(500, $partial['daily']['pool'], 0.001);
        self::assertEqualsWithDelta(0.88, $partial['daily']['moneyRemainingFraction'], 0.001);
        self::assertEqualsWithDelta(-50, $over['daily']['spent'], 0.001);
        self::assertEqualsWithDelta(500, $over['daily']['pool'], 0.001);
        self::assertEqualsWithDelta(1.1, $over['daily']['moneyRemainingFraction'], 0.001);
        self::assertEqualsWithDelta(-50, $only['daily']['spent'], 0.001);
        self::assertEqualsWithDelta(400, $only['daily']['pool'], 0.001);
        self::assertEqualsWithDelta(1.125, $only['daily']['moneyRemainingFraction'], 0.001);
    }

    public function testUnicodeHumanReadablePdfAndLabels(): void
    {
        $monthId = $this->createMonth(2026, 8);
        $unicodeEnvelopes = [];
        foreach ([['Ångström', 'reserved'], ['Ökonomi', 'daily'], ['Zürich', 'savings'], ['Čaj', 'reserved']] as [$name, $role]) {
            $envelopeId = $this->createEnvelope($name, $role);
            $unicodeEnvelopes[$name] = $envelopeId;
            $this->fundedAllocation($monthId, $envelopeId, 100, '2026-08-01');
        }
        $this->transaction($monthId, $unicodeEnvelopes['Ökonomi'], 'Ökonomi', 10, '2026-08-02', 'Unicode expense');
        $model = build_pdf_report_model($this->db, get_report_data($this->db, '2026-08', '2026-08'));
        $html = build_report_pdf_html($model);
        foreach (['Ångström', 'Ökonomi', 'Zürich', 'Čaj'] as $name) self::assertStringContainsString($name, $html);
        self::assertStringContainsString('>Plan<', $html);
        self::assertStringContainsString('Plan difference', $html);
        self::assertStringContainsString('Actual transactions', $html);
        self::assertStringNotContainsString('Daily Spend — Plan vs Actual', $html);
        self::assertStringContainsString('<section class="keep-together"><h3>Savings</h3>', $html);
        self::assertStringNotContainsString('Difference = Plan − Actual.', $html);
        self::assertStringNotContainsString('Actual records protected money', $html);
        self::assertStringNotContainsString('Contributions are funding.', $html);
        self::assertStringNotContainsString('Expected leftover', $html);
        self::assertStringNotContainsString('Balance movements', $html);
        self::assertStringContainsString('<thead>', $html);

        $pdf = build_report_pdf($model);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(20000, strlen($pdf));
        self::assertStringContainsString('/ToUnicode', $pdf);
    }

    public function testXlsxMonthBlockCarriesActualSpendingAndPlanVsActualDifference(): void
    {
        $monthId = $this->createMonth(2026, 8);
        $this->db->prepare('UPDATE months SET salary = 10000 WHERE id = ?')->execute([$monthId]);
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $reserved = $this->createEnvelope('Envelope B', 'reserved');
        $this->fundedAllocation($monthId, $daily, 1000, '2026-08-01');
        $this->fundedAllocation($monthId, $reserved, 500, '2026-08-02');
        $this->transaction($monthId, $daily, 'Envelope A', 300, '2026-08-05', 'Daily spending');
        $this->transaction($monthId, $reserved, 'Envelope B', 100, '2026-08-06', 'Reserved spending');

        $report = get_report_data($this->db, '2026-08', '2026-08');
        $model = build_pdf_report_model($this->db, $report);
        $actual = (float)$model['months'][0]['overview']['actual_expenses'];
        self::assertEqualsWithDelta(400, $actual, 0.001);

        $rows = $this->xlsxRows(build_report_xlsx($report));
        self::assertEqualsWithDelta(1500, (float)$this->xlsxRowValue($rows, 'Plan', 'C'), 0.001);
        self::assertEqualsWithDelta($actual, (float)$this->xlsxRowValue($rows, 'Actual spending', 'C'), 0.001);
        self::assertEqualsWithDelta(1100, (float)$this->xlsxRowValue($rows, 'Plan vs Actual difference', 'C'), 0.001);

        $html = build_report_pdf_html($model);
        self::assertStringContainsString('400,00 DKK', $html);
    }

    public function testPdfDailySpendTableShowsTheFundedColumn(): void
    {
        $monthId = $this->createMonth(2026, 8);
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $this->fundedAllocation($monthId, $daily, 1000, '2026-08-01');
        $this->transaction($monthId, $daily, 'Envelope A', 400, '2026-08-05', 'Daily spending');

        $model = build_pdf_report_model($this->db, get_report_data($this->db, '2026-08', '2026-08'));
        self::assertEqualsWithDelta(1000, $model['months'][0]['daily']['totals']['funded'], 0.001);

        $dailySection = $this->pdfSection(build_report_pdf_html($model), 'Daily Spend');
        self::assertStringContainsString('<th class="number">Funded</th>', $dailySection);
        self::assertStringContainsString('1.000,00 DKK', $dailySection);
        self::assertStringContainsString('400,00 DKK', $dailySection);
        self::assertStringContainsString('600,00 DKK', $dailySection);
    }

    public function testPdfRangeSummaryTotalsEqualTheSumOfTheMonthFigures(): void
    {
        $januaryId = $this->createMonth(2026, 1);
        $februaryId = $this->createMonth(2026, 2);
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $reserved = $this->createEnvelope('Envelope B', 'reserved');
        $savings = $this->createEnvelope('Envelope C', 'savings');
        $this->fundedAllocation($januaryId, $daily, 1000, '2026-01-01');
        $this->fundedAllocation($januaryId, $reserved, 500, '2026-01-01');
        $this->fundedAllocation($februaryId, $daily, 1200, '2026-02-01');
        $this->fundedAllocation($februaryId, $savings, 300, '2026-02-01');
        $this->transaction($januaryId, $daily, 'Envelope A', 800, '2026-01-10', 'January spending');
        $this->transaction($februaryId, $daily, 'Envelope A', 900, '2026-02-10', 'February spending');
        $this->transaction($februaryId, $reserved, 'Envelope B', 200, '2026-02-11', 'February reserved spending');

        $model = build_pdf_report_model($this->db, get_report_data($this->db, '2026-01', '2026-02'));
        $planned = 0.0;
        $actual = 0.0;
        $funded = 0.0;
        foreach ($model['months'] as $month) {
            $planned += (float)$month['overview']['planned_allocations'];
            $actual += (float)$month['overview']['actual_expenses'];
            foreach (['daily', 'reserved', 'savings'] as $role) $funded += (float)$month[$role]['totals']['funded'];
        }
        self::assertEqualsWithDelta(3000, $planned, 0.001);
        self::assertEqualsWithDelta(1900, $actual, 0.001);
        self::assertEqualsWithDelta(3000, $funded, 0.001);

        $range = $model['range'];
        self::assertEqualsWithDelta($planned, $range['total']['planned'], 0.001);
        self::assertEqualsWithDelta($funded, $range['total']['funded'], 0.001);
        self::assertEqualsWithDelta($actual, $range['total']['actual'], 0.001);
        self::assertEqualsWithDelta($planned - $actual, $range['total']['difference'], 0.001);
        self::assertEqualsWithDelta(2200, $range['roles']['daily']['planned'], 0.001);
        self::assertEqualsWithDelta(2200, $range['roles']['daily']['funded'], 0.001);
        self::assertEqualsWithDelta(1700, $range['roles']['daily']['actual'], 0.001);
        self::assertEqualsWithDelta(500, $range['roles']['reserved']['planned'], 0.001);
        self::assertEqualsWithDelta(200, $range['roles']['reserved']['actual'], 0.001);
        self::assertEqualsWithDelta(300, $range['roles']['savings']['planned'], 0.001);
        self::assertEqualsWithDelta(300, $range['roles']['savings']['funded'], 0.001);
        self::assertEqualsWithDelta(0, $range['roles']['savings']['actual'], 0.001);

        $html = build_report_pdf_html($model);
        self::assertStringContainsString('Range totals — January 2026 → February 2026', $html);
        $rangeSection = $this->pdfSection($html, 'Range totals — January 2026 → February 2026');
        self::assertStringContainsString('3.000,00 DKK', $rangeSection);
        self::assertStringContainsString('1.900,00 DKK', $rangeSection);
        self::assertStringContainsString('1.100,00 DKK', $rangeSection);
        self::assertStringContainsString('Daily Spend', $rangeSection);
        self::assertStringContainsString('Reserved', $rangeSection);
        self::assertStringContainsString('Savings', $rangeSection);
        self::assertLessThan(strpos($html, 'Daily Spend — Plan vs Actual by month'), strpos($html, 'Range totals'));

        $single = build_pdf_report_model($this->db, get_report_data($this->db, '2026-01', '2026-01'));
        self::assertNull($single['range']);
        self::assertStringNotContainsString('Range totals', build_report_pdf_html($single));
    }

    private function fundedAllocation(int $monthId, int $envelopeId, float $amount, string $date, bool $isBs = false): int
    {
        return save_entry($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'type' => 'expense',
            'amount' => $amount,
            'status' => 'paid',
            'due_date' => $date,
            'is_bs' => $isBs ? 1 : 0,
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

    private function movement(int $envelopeId, int $monthId, string $type, float $amount, string $date, string $note): void
    {
        $stmt = $this->db->prepare('INSERT INTO envelope_movements (envelope_id, month_id, type, amount, note, movement_date) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$envelopeId, $monthId, $type, $amount, $note, $date]);
    }

    private function transactionCountForEntryNamed(string $name): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM transactions t JOIN entries e ON e.id=t.entry_id WHERE e.name=?');
        $stmt->execute([$name]);
        return (int)$stmt->fetchColumn();
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

    private function pdfSection(string $html, string $title): string
    {
        $start = strpos($html, '<h3>' . $title . '</h3>');
        self::assertNotFalse($start, 'The PDF section was not found: ' . $title);
        $end = strpos($html, '</section>', $start);
        self::assertNotFalse($end, 'The PDF section was not closed: ' . $title);
        return substr($html, $start, $end - $start);
    }
}
