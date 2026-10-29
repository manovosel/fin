<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class BsAutomaticPaymentTest extends DatabaseTestCase
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

    public function testFundingBsAllocationCreatesFundingAndAutomaticExpenseAtomically(): void
    {
        $monthId = $this->createMonth(2026, 3);
        $envelopeId = $this->createEnvelope('Insurance', 'reserved');
        $entryId = save_entry($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'type' => 'expense',
            'amount' => 1000,
            'status' => 'planned',
            'due_date' => '2026-03-15',
            'is_bs' => 1,
        ]);

        set_allocation_status($this->db, $monthId, $envelopeId, 'paid');
        set_allocation_status($this->db, $monthId, $envelopeId, 'paid');

        self::assertSame('paid', (string)$this->db->query("SELECT status FROM entries WHERE id = $entryId")->fetchColumn());
        self::assertEqualsWithDelta(0, $this->envelopeBalance($envelopeId), 0.001);
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE entry_id = $entryId AND type = 'funding'")->fetchColumn());
        $transaction = $this->db->query("SELECT * FROM transactions WHERE entry_id = $entryId AND is_automatic = 1")->fetch();
        self::assertIsArray($transaction);
        self::assertEqualsWithDelta(1000, (float)$transaction['amount'], 0.001);
        self::assertSame('2026-03-15', $transaction['spent_date']);
        self::assertSame('Direct debit', $transaction['note']);
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM transactions WHERE entry_id = $entryId AND is_automatic = 1")->fetchColumn());
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM envelope_movements WHERE transaction_id = ' . (int)$transaction['id'] . " AND type = 'expense'")->fetchColumn());

        set_allocation_status($this->db, $monthId, $envelopeId, 'planned');

        self::assertSame('planned', (string)$this->db->query("SELECT status FROM entries WHERE id = $entryId")->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM transactions WHERE entry_id = $entryId AND is_automatic = 1")->fetchColumn());
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE entry_id = $entryId AND type = 'funding'")->fetchColumn());
        self::assertNotNull($this->db->query("SELECT bs_expense_finalized_at FROM entries WHERE id=$entryId")->fetchColumn());
        self::assertEqualsWithDelta(-1000, $this->envelopeBalance($envelopeId), 0.001);
        set_allocation_status($this->db, $monthId, $envelopeId, 'skipped');
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM transactions WHERE entry_id=$entryId")->fetchColumn());
    }

    public function testChangingBsToNormalFundingRemovesExpenseAndKeepsFundingDate(): void
    {
        $monthId = $this->createMonth(2026, 4);
        $envelopeId = $this->createEnvelope('Subscription', 'reserved');
        $entryId = save_entry($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'type' => 'expense',
            'amount' => 600,
            'status' => 'paid',
            'is_bs' => 1,
        ]);
        self::assertEqualsWithDelta(0, $this->envelopeBalance($envelopeId), 0.001);
        self::assertSame('2026-04-01', (string)$this->db->query("SELECT due_date FROM entries WHERE id = $entryId")->fetchColumn());
        self::assertSame('2026-04-01', (string)$this->db->query("SELECT spent_date FROM transactions WHERE entry_id = $entryId AND is_automatic = 1")->fetchColumn());
        $fundingDate = (string)$this->db->query("SELECT movement_date FROM envelope_movements WHERE entry_id = $entryId AND type = 'funding'")->fetchColumn();

        save_entry($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'type' => 'expense',
            'amount' => 600,
            'status' => 'paid',
            'is_bs' => 0,
        ], $entryId);

        self::assertEqualsWithDelta(0, $this->envelopeBalance($envelopeId), 0.001);
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM transactions WHERE entry_id = $entryId AND is_automatic = 1")->fetchColumn());
        self::assertSame($fundingDate, (string)$this->db->query("SELECT movement_date FROM envelope_movements WHERE entry_id = $entryId AND type = 'funding'")->fetchColumn());
    }

    public function testChangingOrdinaryFundingToBsPreservesFundingAndAddsPayment(): void
    {
        $monthId = $this->createMonth(2026, 8);
        $envelopeId = $this->createEnvelope('Loan', 'reserved');
        $entryId = save_entry($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'amount' => 700,
            'status' => 'paid',
            'due_date' => '2026-08-10',
            'is_bs' => 0,
        ]);
        self::assertEqualsWithDelta(700, $this->envelopeBalance($envelopeId), 0.001);

        save_entry($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'amount' => 700,
            'status' => 'paid',
            'due_date' => '2026-08-12',
            'is_bs' => 1,
        ], $entryId);

        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE entry_id = $entryId AND type = 'funding'")->fetchColumn());
        self::assertSame('2026-08-10', (string)$this->db->query("SELECT movement_date FROM envelope_movements WHERE entry_id = $entryId AND type = 'funding'")->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM transactions WHERE entry_id = $entryId AND is_automatic = 1")->fetchColumn());
        self::assertEqualsWithDelta(0, $this->envelopeBalance($envelopeId), 0.001);
    }

    public function testFutureBsExpenseRemainsControlledByPlanning(): void
    {
        $monthId = $this->createMonth(2026, 8);
        $envelopeId = $this->createEnvelope('Future insurance', 'reserved');
        $entryId = save_entry($this->db, [
            'month_id' => $monthId, 'envelope_id' => $envelopeId, 'amount' => 1000,
            'status' => 'paid', 'due_date' => '2026-09-15', 'is_bs' => 1,
        ]);
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM transactions WHERE entry_id=$entryId")->fetchColumn());

        set_allocation_status($this->db, $monthId, $envelopeId, 'planned');

        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM transactions WHERE entry_id=$entryId")->fetchColumn());
    }

    public function testEffectiveBsExpenseSurvivesAllocationDeletionAndExplicitExpenseDeletionWorks(): void
    {
        $monthId = $this->createMonth(2026, 7);
        $envelopeId = $this->createEnvelope('Paid insurance', 'reserved');
        $entryId = save_entry($this->db, [
            'month_id' => $monthId, 'envelope_id' => $envelopeId, 'amount' => 700,
            'status' => 'paid', 'due_date' => '2026-08-01', 'is_bs' => 1,
        ]);
        $transactionId = (int)$this->db->query("SELECT id FROM transactions WHERE entry_id=$entryId")->fetchColumn();

        delete_allocation_entry($this->db, $entryId);

        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM transactions WHERE id=$transactionId")->fetchColumn());
        self::assertNull($this->db->query("SELECT entry_id FROM transactions WHERE id=$transactionId")->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE transaction_id=$transactionId")->fetchColumn());

        delete_transaction($this->db, $transactionId);
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM transactions WHERE id=$transactionId")->fetchColumn());
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE transaction_id=$transactionId")->fetchColumn());
    }

    public function testEffectiveBsExpenseSurvivesTargetMonthPlanReplacement(): void
    {
        $sourceMonth = $this->createMonth(2026, 6);
        $targetMonth = $this->createMonth(2026, 7);
        $envelopeId = $this->createEnvelope('Monthly service', 'reserved');
        save_entry($this->db, ['month_id' => $sourceMonth, 'envelope_id' => $envelopeId, 'amount' => 500, 'status' => 'planned', 'is_bs' => 1]);
        $targetEntry = save_entry($this->db, [
            'month_id' => $targetMonth, 'envelope_id' => $envelopeId, 'amount' => 500,
            'status' => 'paid', 'due_date' => '2026-08-01', 'is_bs' => 1,
        ]);
        $actualId = (int)$this->db->query("SELECT id FROM transactions WHERE entry_id=$targetEntry")->fetchColumn();

        duplicate_month($this->db, 2026, 6, 2026, 7);

        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM transactions WHERE id=$actualId")->fetchColumn());
        self::assertNull($this->db->query("SELECT entry_id FROM transactions WHERE id=$actualId")->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE transaction_id=$actualId")->fetchColumn());
    }

    public function testExplicitDeletionOfLinkedEffectiveBsExpensePreventsRecreation(): void
    {
        $monthId = $this->createMonth(2026, 7);
        $envelopeId = $this->createEnvelope('Deleted payment', 'reserved');
        $entryId = save_entry($this->db, [
            'month_id' => $monthId, 'envelope_id' => $envelopeId, 'amount' => 300,
            'status' => 'paid', 'due_date' => '2026-08-01', 'is_bs' => 1,
        ]);
        $transactionId = (int)$this->db->query("SELECT id FROM transactions WHERE entry_id=$entryId")->fetchColumn();

        delete_transaction($this->db, $transactionId);
        sync_bs_automatic_transaction($this->db, $entryId);

        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM transactions WHERE entry_id=$entryId")->fetchColumn());
        self::assertNotNull($this->db->query("SELECT bs_expense_finalized_at FROM entries WHERE id=$entryId")->fetchColumn());
    }

    public function testBsDefaultUsesFirstDayOfItsPlanMonthRatherThanTodayOrTheFollowingMonth(): void
    {
        $monthId = $this->createMonth(2026, 10);
        $envelopeId = $this->createEnvelope('October subscription', 'reserved');
        $entryId = save_entry($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'type' => 'expense',
            'amount' => 100,
            'status' => 'paid',
            'is_bs' => 1,
        ]);

        self::assertSame('2026-10-01', (string)$this->db->query("SELECT due_date FROM entries WHERE id = $entryId")->fetchColumn());
        self::assertSame('2026-10-01', (string)$this->db->query("SELECT spent_date FROM transactions WHERE entry_id = $entryId AND is_automatic = 1")->fetchColumn());
        self::assertEqualsWithDelta(100, $this->envelopeBalance($envelopeId), 0.001);
        self::assertEqualsWithDelta(0, envelope_balance_as_of($this->db, $envelopeId, '2026-10-01'), 0.001);
    }

    public function testMonthCopyPreservesBsButResetsItToPlanned(): void
    {
        $sourceMonthId = $this->createMonth(2026, 5);
        $envelopeId = $this->createEnvelope('Union fee', 'reserved');
        save_entry($this->db, [
            'month_id' => $sourceMonthId,
            'envelope_id' => $envelopeId,
            'type' => 'expense',
            'amount' => 450,
            'status' => 'paid',
            'is_bs' => 1,
        ]);

        duplicate_month($this->db, 2026, 5, 2026, 6);

        $copied = $this->db->query("SELECT e.* FROM entries e JOIN months m ON m.id = e.month_id WHERE m.year = 2026 AND m.month = 6 AND e.envelope_id = $envelopeId")->fetch();
        self::assertIsArray($copied);
        self::assertSame(1, (int)$copied['is_bs']);
        self::assertSame('planned', $copied['status']);
        self::assertNull($copied['due_date']);
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM transactions WHERE entry_id = ' . (int)$copied['id'] . ' AND is_automatic = 1')->fetchColumn());
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE entry_id = " . (int)$copied['id'])->fetchColumn());

        set_allocation_status($this->db, (int)$copied['month_id'], $envelopeId, 'paid');
        self::assertSame('2026-06-01', (string)$this->db->query('SELECT due_date FROM entries WHERE id = ' . (int)$copied['id'])->fetchColumn());
        self::assertSame('2026-08-14', (string)$this->db->query("SELECT movement_date FROM envelope_movements WHERE entry_id = " . (int)$copied['id'] . " AND type = 'funding'")->fetchColumn());
    }
}
