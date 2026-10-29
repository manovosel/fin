<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class TransactionsApiTest extends DatabaseTestCase
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
        parent::tearDown();
    }

    public function testTransactionRangeCannotExceedThreeInclusiveCalendarMonths(): void
    {
        try {
            build_transactions_response($this->db, '2026-07', '2026-10');
            self::fail('A four-month transaction range must be rejected');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Transaction range cannot exceed 3 months', $error->getMessage());
        }

        $valid = build_transactions_response($this->db, '2026-07', '2026-09');
        self::assertSame('2026-07', $valid['from']);
        self::assertSame('2026-09', $valid['to']);
        self::assertSame([], $valid['transactions']);
    }

    public function testEnvelopeFilterAndRefundRuleMatchThePdfReportSelection(): void
    {
        $sourceMonth = $this->createMonth(2026, 1);
        $this->createMonth(2026, 7);
        $envelopeA = $this->createEnvelope('Envelope A', 'daily');
        $transport = $this->createEnvelope('Transport', 'daily');
        $account = save_account_definition($this->db, [
            'name' => 'Main Account',
            'account_number' => 'MAIN-1',
            'currency' => 'CURRENCY',
        ]);

        $expenseId = save_transaction($this->db, [
            'month_id' => $sourceMonth,
            'envelope_id' => $envelopeA,
            'envelope' => 'Envelope A',
            'payment_account_id' => $account,
            'amount' => 100,
            'spent_date' => '2026-07-05',
            'note' => 'Weekly shopping',
            'kind' => 'expense',
        ]);
        $refundId = save_transaction($this->db, [
            'month_id' => $sourceMonth,
            'envelope_id' => $envelopeA,
            'envelope' => 'Envelope A',
            'payment_account_id' => $account,
            'amount' => 25,
            'spent_date' => '2026-07-06',
            'note' => 'Returned shopping',
            'kind' => 'refund',
        ]);
        $otherId = save_transaction($this->db, [
            'month_id' => null,
            'envelope_id' => $transport,
            'envelope' => 'Transport',
            'payment_account_id' => null,
            'amount' => 50,
            'spent_date' => '2026-07-07',
            'note' => 'Bus ticket',
            'kind' => 'expense',
        ]);
        $futureId = save_transaction($this->db, [
            'month_id' => null,
            'envelope_id' => $envelopeA,
            'envelope' => 'Envelope A',
            'payment_account_id' => $account,
            'amount' => 999,
            'spent_date' => '2026-08-15',
            'note' => 'Future shopping',
            'kind' => 'expense',
        ]);

        $filtered = build_transactions_response($this->db, '2026-07', '2026-07', $envelopeA);
        self::assertCount(2, $filtered['transactions']);
        self::assertSame(
            ['id', 'date', 'envelope_id', 'envelope', 'description', 'amount', 'kind', 'account'],
            array_keys($filtered['transactions'][0])
        );
        self::assertSame($expenseId, $filtered['transactions'][0]['id']);
        self::assertSame('2026-07-05', $filtered['transactions'][0]['date']);
        self::assertSame($envelopeA, $filtered['transactions'][0]['envelope_id']);
        self::assertSame('Envelope A', $filtered['transactions'][0]['envelope']);
        self::assertSame('Weekly shopping', $filtered['transactions'][0]['description']);
        self::assertEqualsWithDelta(100.0, $filtered['transactions'][0]['amount'], 0.001);
        self::assertSame('expense', $filtered['transactions'][0]['kind']);
        self::assertSame('Main Account', $filtered['transactions'][0]['account']);

        self::assertSame($refundId, $filtered['transactions'][1]['id']);
        self::assertSame('2026-07-06', $filtered['transactions'][1]['date']);
        self::assertSame('Returned shopping', $filtered['transactions'][1]['description']);
        self::assertEqualsWithDelta(-25.0, $filtered['transactions'][1]['amount'], 0.001);
        self::assertSame('refund', $filtered['transactions'][1]['kind']);
        self::assertSame('Main Account', $filtered['transactions'][1]['account']);

        $all = build_transactions_response($this->db, '2026-07', '2026-07');
        self::assertCount(3, $all['transactions']);
        self::assertSame([$expenseId, $refundId, $otherId], array_column($all['transactions'], 'id'));
        self::assertNotContains($futureId, array_column($all['transactions'], 'id'));
        self::assertNull($all['transactions'][2]['account']);

        // The endpoint and the PDF source model select the same rows and use
        // the same signed Refund rule (Expense - Refund).
        $report = get_report_data($this->db, '2026-07', '2026-07');
        self::assertSame(
            [$expenseId, $refundId, $otherId],
            array_column($report['months'][0]['transactions'], 'id')
        );
        self::assertEqualsWithDelta(125.0, $report['months'][0]['summary']['actual_spent'], 0.001);
        self::assertEqualsWithDelta(125.0, array_sum(array_column($all['transactions'], 'amount')), 0.001);
    }
}
