<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class DateEffectiveLedgerTest extends DatabaseTestCase
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

    public function testFutureMovementsExistButBecomeEffectiveOnlyOnTheirDates(): void
    {
        $august = $this->createMonth(2026, 8);
        $envelopeId = $this->createEnvelope('Envelope E', 'reserved');
        $entryId = save_entry($this->db, [
            'month_id' => $august,
            'envelope_id' => $envelopeId,
            'amount' => 3973,
            'status' => 'paid',
            'is_bs' => 1,
            'due_date' => '2026-09-01',
        ]);

        $funding = $this->db->query("SELECT * FROM envelope_movements WHERE entry_id = $entryId AND type = 'funding'")->fetch();
        $transaction = $this->db->query("SELECT * FROM transactions WHERE entry_id = $entryId AND is_automatic = 1")->fetch();
        $expense = $this->db->query('SELECT * FROM envelope_movements WHERE transaction_id = ' . (int)$transaction['id'] . " AND type = 'expense'")->fetch();

        self::assertSame('2026-08-14', $funding['movement_date']);
        self::assertEqualsWithDelta(3973, (float)$funding['amount'], 0.001);
        self::assertSame('2026-09-01', $transaction['spent_date']);
        self::assertSame('2026-09-01', $expense['movement_date']);
        self::assertEqualsWithDelta(-3973, (float)$expense['amount'], 0.001);
        self::assertEqualsWithDelta(0, envelope_balance_as_of($this->db, $envelopeId, '2026-08-13'), 0.001);
        self::assertEqualsWithDelta(3973, envelope_balance_as_of($this->db, $envelopeId, '2026-08-14'), 0.001);
        self::assertEqualsWithDelta(3973, envelope_balance_as_of($this->db, $envelopeId, '2026-08-31'), 0.001);
        self::assertEqualsWithDelta(0, envelope_balance_as_of($this->db, $envelopeId, '2026-09-01'), 0.001);
        self::assertEqualsWithDelta(3973, $this->envelopeBalance($envelopeId), 0.001);
    }

    public function testFutureBsExpenseMovesFromAugustPlanToSeptemberActualReport(): void
    {
        $august = $this->createMonth(2026, 8);
        $this->createMonth(2026, 9);
        $envelopeId = $this->createEnvelope('Envelope E', 'reserved');
        save_entry($this->db, [
            'month_id' => $august,
            'envelope_id' => $envelopeId,
            'amount' => 3973,
            'status' => 'paid',
            'is_bs' => 1,
            'due_date' => '2026-09-01',
        ]);

        $augustReport = get_report_data($this->db, '2026-08', '2026-08');
        self::assertEqualsWithDelta(3973, $augustReport['months'][0]['summary']['expenses'], 0.001);
        self::assertEqualsWithDelta(0, $augustReport['months'][0]['summary']['actual_spent'], 0.001);
        self::assertCount(0, $augustReport['months'][0]['transactions']);

        putenv('FIN_TODAY=2026-09-02');
        $range = get_report_data($this->db, '2026-08', '2026-09');
        self::assertCount(0, $range['months'][0]['transactions']);
        self::assertCount(1, $range['months'][1]['transactions']);
        self::assertEqualsWithDelta(3973, $range['months'][1]['summary']['actual_spent'], 0.001);
        self::assertSame('2026-09-01', $range['months'][1]['transactions'][0]['spent_date']);
    }

    public function testDecemberBsDefaultRemainsDecemberActualAcrossYears(): void
    {
        $december = $this->createMonth(2026, 12);
        $this->createMonth(2027, 1);
        $envelopeId = $this->createEnvelope('December subscription', 'reserved');
        save_entry($this->db, [
            'month_id' => $december,
            'envelope_id' => $envelopeId,
            'amount' => 100,
            'status' => 'paid',
            'is_bs' => 1,
        ]);

        putenv('FIN_TODAY=2027-01-02');
        $decemberReport = get_report_data($this->db, '2026-12', '2026-12');
        $januaryReport = get_report_data($this->db, '2027-01', '2027-01');
        self::assertCount(1, $decemberReport['months'][0]['transactions']);
        self::assertSame('2026-12-01', $decemberReport['months'][0]['transactions'][0]['spent_date']);
        self::assertSame($december, $decemberReport['months'][0]['transactions'][0]['month_id']);
        self::assertCount(0, $januaryReport['months'][0]['transactions']);
        $januaryState = get_state($this->db, 2027);
        self::assertCount(0, array_filter($januaryState['transactions'], fn(array $transaction): bool => $transaction['spent_date'] === '2027-01-01'));
    }

    public function testEditingFutureBsAmountAndPaymentDatePreservesFundingDate(): void
    {
        $august = $this->createMonth(2026, 8);
        $envelopeId = $this->createEnvelope('Envelope E', 'reserved');
        $entryId = save_entry($this->db, [
            'month_id' => $august,
            'envelope_id' => $envelopeId,
            'amount' => 3973,
            'status' => 'paid',
            'is_bs' => 1,
            'due_date' => '2026-09-01',
        ]);

        putenv('FIN_TODAY=2026-08-20');
        save_entry($this->db, [
            'month_id' => $august,
            'envelope_id' => $envelopeId,
            'amount' => 4200,
            'status' => 'paid',
            'is_bs' => 1,
            'due_date' => '2026-09-05',
        ], $entryId);

        $funding = $this->db->query("SELECT amount, movement_date FROM envelope_movements WHERE entry_id = $entryId AND type = 'funding'")->fetch();
        $transaction = $this->db->query("SELECT id, amount, spent_date FROM transactions WHERE entry_id = $entryId AND is_automatic = 1")->fetch();
        $expense = $this->db->query('SELECT amount, movement_date FROM envelope_movements WHERE transaction_id = ' . (int)$transaction['id'])->fetch();
        self::assertEqualsWithDelta(4200, (float)$funding['amount'], 0.001);
        self::assertSame('2026-08-14', $funding['movement_date']);
        self::assertEqualsWithDelta(4200, (float)$transaction['amount'], 0.001);
        self::assertSame('2026-09-05', $transaction['spent_date']);
        self::assertEqualsWithDelta(-4200, (float)$expense['amount'], 0.001);
        self::assertSame('2026-09-05', $expense['movement_date']);
        self::assertEqualsWithDelta(4200, envelope_balance_as_of($this->db, $envelopeId, '2026-09-04'), 0.001);
        self::assertEqualsWithDelta(0, envelope_balance_as_of($this->db, $envelopeId, '2026-09-05'), 0.001);
    }

    public function testEnvelopeBalanceObservationsIncludeSameDayMovementsAndIgnoreFuture(): void
    {
        $august = $this->createMonth(2026, 8);
        $envelopeId = $this->createEnvelope('Reserve', 'reserved');
        $this->db->prepare("INSERT INTO envelope_movements (envelope_id, month_id, type, amount, note, movement_date) VALUES (?, ?, 'expense', -50, 'Ordinary expense', '2026-08-14')")->execute([$envelopeId, $august]);
        $this->db->prepare("INSERT INTO envelope_movements (envelope_id, month_id, type, amount, note, movement_date) VALUES (?, ?, 'adjustment', 500, 'Future', '2026-09-01')")->execute([$envelopeId, $august]);

        record_envelope_balance_observation($this->db, $envelopeId, 100, '2026-08-14');
        self::assertEqualsWithDelta(100, envelope_balance_as_of($this->db, $envelopeId, '2026-08-14'), 0.001);
        self::assertEqualsWithDelta(600, envelope_balance_as_of($this->db, $envelopeId, '2026-09-01'), 0.001, 'Movements strictly after the observation date must still contribute, and future-date movements from before the observation remain in effect through their date');
    }
}
