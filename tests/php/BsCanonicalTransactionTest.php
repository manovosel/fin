<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class BsCanonicalTransactionTest extends DatabaseTestCase
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

    public function testManualAndBsAssetPaymentsHaveIdenticalEffects(): void
    {
        $month = $this->createMonth(2026, 8);
        $asset = $this->account('Test Wallet', 'asset', '2026-01-01');
        $manualEnvelope = $this->createEnvelope('Manual service', 'reserved');
        $bsEnvelope = $this->createEnvelope('BS service', 'reserved');
        $manualId = save_transaction($this->db, [
            'month_id' => $month, 'envelope_id' => $manualEnvelope, 'payment_account_id' => $asset,
            'amount' => 500, 'spent_date' => '2026-08-01',
        ]);
        $entryId = save_entry($this->db, [
            'month_id' => $month, 'envelope_id' => $bsEnvelope, 'amount' => 500,
            'status' => 'paid', 'is_bs' => 1, 'due_date' => '2026-08-01', 'bs_payment_account_id' => $asset,
        ]);
        $bsId = (int)$this->db->query("SELECT id FROM transactions WHERE entry_id=$entryId")->fetchColumn();

        self::assertEqualsWithDelta(-500, (float)$this->db->query("SELECT amount FROM envelope_movements WHERE transaction_id=$manualId")->fetchColumn(), 0.001);
        self::assertEqualsWithDelta(-500, (float)$this->db->query("SELECT amount FROM envelope_movements WHERE transaction_id=$bsId")->fetchColumn(), 0.001);
        self::assertEqualsWithDelta(-500, (float)$this->db->query("SELECT am.amount FROM account_movements am JOIN account_events ae ON ae.id=am.event_id WHERE ae.transaction_id=$manualId")->fetchColumn(), 0.001);
        self::assertEqualsWithDelta(-500, (float)$this->db->query("SELECT am.amount FROM account_movements am JOIN account_events ae ON ae.id=am.event_id WHERE ae.transaction_id=$bsId")->fetchColumn(), 0.001);
    }

    public function testManualAndBsLiabilityPaymentsHaveIdenticalEffects(): void
    {
        $month = $this->createMonth(2026, 8);
        $liability = $this->account('Test Card', 'liability', '2026-01-01');
        $manualEnvelope = $this->createEnvelope('Manual card fee', 'reserved');
        $bsEnvelope = $this->createEnvelope('BS card fee', 'reserved');
        save_transaction($this->db, [
            'month_id' => $month, 'envelope_id' => $manualEnvelope, 'payment_account_id' => $liability,
            'amount' => 400, 'spent_date' => '2026-08-01',
        ]);
        save_entry($this->db, [
            'month_id' => $month, 'envelope_id' => $bsEnvelope, 'amount' => 400,
            'status' => 'paid', 'is_bs' => 1, 'due_date' => '2026-08-01', 'bs_payment_account_id' => $liability,
        ]);

        self::assertEqualsWithDelta(-800, account_balance_as_of($this->db, $liability, '2026-08-01'), 0.001);
        self::assertSame(2, (int)$this->db->query("SELECT COUNT(*) FROM account_events WHERE event_type='expense'")->fetchColumn());
    }

    public function testManualAndBsUnknownPaymentCreateEnvelopeEffectsOnly(): void
    {
        $month = $this->createMonth(2026, 8);
        $manualEnvelope = $this->createEnvelope('Manual unknown', 'reserved');
        $defaultAccount = $this->account('Convenience default', 'asset', '2026-01-01');
        $bsEnvelope = save_envelope($this->db, ['name' => 'BS unknown', 'account' => 'Legacy label', 'role' => 'reserved', 'default_account_id' => $defaultAccount]);
        save_transaction($this->db, ['month_id' => $month, 'envelope_id' => $manualEnvelope, 'amount' => 250, 'spent_date' => '2026-08-01']);
        save_entry($this->db, [
            'month_id' => $month, 'envelope_id' => $bsEnvelope, 'amount' => 250,
            'status' => 'paid', 'is_bs' => 1, 'due_date' => '2026-08-01', 'bs_payment_account_id' => null,
        ]);

        self::assertSame(2, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE type='expense'")->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM account_events')->fetchColumn());
    }

    public function testEffectiveBsEditSynchronizesOneStableAccountEffect(): void
    {
        $month = $this->createMonth(2026, 8);
        $asset = $this->account('Test Wallet', 'asset', '2026-01-01');
        $liability = $this->account('Test Card', 'liability', '2026-01-01');
        $envelope = $this->createEnvelope('Editable BS', 'reserved');
        $entryId = save_entry($this->db, [
            'month_id' => $month, 'envelope_id' => $envelope, 'amount' => 300,
            'status' => 'paid', 'is_bs' => 1, 'due_date' => '2026-08-01', 'bs_payment_account_id' => $asset,
        ]);
        $transactionId = (int)$this->db->query("SELECT id FROM transactions WHERE entry_id=$entryId")->fetchColumn();
        $eventId = (int)$this->db->query("SELECT id FROM account_events WHERE transaction_id=$transactionId")->fetchColumn();
        $movementId = (int)$this->db->query("SELECT id FROM account_movements WHERE event_id=$eventId")->fetchColumn();

        save_transaction($this->db, [
            'month_id' => $month, 'entry_id' => $entryId, 'envelope_id' => $envelope,
            'payment_account_id' => $liability, 'amount' => 450, 'spent_date' => '2026-08-02', 'note' => 'Corrected',
        ], $transactionId);

        self::assertSame($eventId, (int)$this->db->query("SELECT id FROM account_events WHERE transaction_id=$transactionId")->fetchColumn());
        self::assertSame($movementId, (int)$this->db->query("SELECT id FROM account_movements WHERE event_id=$eventId")->fetchColumn());
        self::assertEqualsWithDelta(0, account_balance_as_of($this->db, $asset, '2026-08-02'), 0.001);
        self::assertEqualsWithDelta(-450, account_balance_as_of($this->db, $liability, '2026-08-02'), 0.001);
        self::assertEqualsWithDelta(-450, (float)$this->db->query("SELECT amount FROM envelope_movements WHERE transaction_id=$transactionId")->fetchColumn(), 0.001);
        self::assertNotNull($this->db->query("SELECT bs_expense_finalized_at FROM entries WHERE id=$entryId")->fetchColumn());
    }

    public function testBsPostingIsAllowedBeforeAnyBalanceObservation(): void
    {
        $month = $this->createMonth(2026, 8);
        $unknownAccount = $this->account('Unknown account', 'asset', '2026-09-01');
        $envelope = $this->createEnvelope('Valid BS', 'reserved');
        $entry = save_entry($this->db, [
            'month_id' => $month, 'envelope_id' => $envelope, 'amount' => 300,
            'status' => 'paid', 'is_bs' => 1, 'due_date' => '2026-08-01', 'bs_payment_account_id' => $unknownAccount,
        ]);

        self::assertGreaterThan(0, $entry);
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM account_events')->fetchColumn());
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn());
        self::assertNull(account_balance_as_of($this->db, $unknownAccount, effective_today()));
    }

    private function account(string $name, string $type, string $date): int
    {
        $accountId = save_account_definition($this->db, ['name' => $name, 'account_number' => 'TEST-' . strtoupper($name), 'currency' => 'CURRENCY']);
        if ($date <= effective_today()) record_account_balance_observation($this->db, $accountId, 0, $date, 'Synthetic zero Balance');
        return $accountId;
    }
}
