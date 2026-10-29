<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class ExpensePaymentAccountTest extends DatabaseTestCase
{
    public function testAssetAndLiabilityPaidExpensesCreateOneActualAndCorrectAccountEffect(): void
    {
        $month = $this->createMonth(2026, 9);
        $asset = $this->account('Test Bank', 'asset');
        $liability = $this->account('Test Card', 'liability');
        $health = save_envelope($this->db, ['name' => 'Envelope K', 'account' => 'Test Bank legacy', 'role' => 'reserved', 'default_account_id' => $asset]);

        $assetTransaction = save_transaction($this->db, [
            'month_id' => $month, 'envelope_id' => $health, 'payment_account_id' => $asset,
            'amount' => 600, 'spent_date' => '2026-09-03',
        ]);
        $cardTransaction = save_transaction($this->db, [
            'month_id' => $month, 'envelope_id' => $health, 'payment_account_id' => $liability,
            'amount' => 800, 'spent_date' => '2026-09-04',
        ]);

        self::assertSame(2, (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
        self::assertSame(2, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE type='expense'")->fetchColumn());
        self::assertSame(2, (int)$this->db->query("SELECT COUNT(*) FROM account_events WHERE event_type='expense'")->fetchColumn());
        self::assertEqualsWithDelta(-600, account_balance_as_of($this->db, $asset, '2026-09-04'), 0.001);
        self::assertEqualsWithDelta(-800, account_balance_as_of($this->db, $liability, '2026-09-04'), 0.001);
        self::assertEqualsWithDelta(-1400, envelope_balance_as_of($this->db, $health, '2026-09-04'), 0.001);
        self::assertSame($asset, (int)$this->db->query("SELECT payment_account_id FROM transactions WHERE id=$assetTransaction")->fetchColumn());
        self::assertSame($liability, (int)$this->db->query("SELECT payment_account_id FROM transactions WHERE id=$cardTransaction")->fetchColumn());
    }

    public function testEditingPaidFromMovesOneStableAccountEffectWithoutDuplicatingActual(): void
    {
        $month = $this->createMonth(2026, 9);
        $asset = $this->account('Test Bank', 'asset');
        $liability = $this->account('Test Card', 'liability');
        $health = $this->createEnvelope('Envelope K', 'reserved');
        $transactionId = save_transaction($this->db, [
            'month_id' => $month, 'envelope_id' => $health, 'payment_account_id' => $asset,
            'amount' => 750, 'spent_date' => '2026-09-05', 'note' => 'Dentist',
        ]);
        $eventId = (int)$this->db->query("SELECT id FROM account_events WHERE transaction_id=$transactionId")->fetchColumn();
        $movementId = (int)$this->db->query("SELECT id FROM account_movements WHERE event_id=$eventId")->fetchColumn();

        save_transaction($this->db, [
            'envelope_id' => $health, 'payment_account_id' => $liability,
            'amount' => 750, 'spent_date' => '2026-09-05', 'note' => 'Dentist',
        ], $transactionId);
        save_transaction($this->db, [
            'envelope_id' => $health, 'payment_account_id' => $liability,
            'amount' => 750, 'spent_date' => '2026-09-05', 'note' => 'Dentist',
        ], $transactionId);

        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE transaction_id=$transactionId")->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM account_events WHERE transaction_id=$transactionId")->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM account_movements WHERE event_id=$eventId")->fetchColumn());
        self::assertSame($eventId, (int)$this->db->query("SELECT id FROM account_events WHERE transaction_id=$transactionId")->fetchColumn());
        self::assertSame($movementId, (int)$this->db->query("SELECT id FROM account_movements WHERE event_id=$eventId")->fetchColumn());
        self::assertEqualsWithDelta(0, account_balance_as_of($this->db, $asset, '2026-09-05'), 0.001);
        self::assertEqualsWithDelta(-750, account_balance_as_of($this->db, $liability, '2026-09-05'), 0.001);
        self::assertEqualsWithDelta(-750, envelope_balance_as_of($this->db, $health, '2026-09-05'), 0.001);

        save_transaction($this->db, [
            'envelope_id' => $health, 'payment_account_id' => null,
            'amount' => 750, 'spent_date' => '2026-09-05', 'note' => 'Dentist',
        ], $transactionId);
        self::assertNull($this->db->query("SELECT payment_account_id FROM transactions WHERE id=$transactionId")->fetch()['payment_account_id']);
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM account_events WHERE transaction_id=$transactionId")->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn());
        self::assertEqualsWithDelta(-750, envelope_balance_as_of($this->db, $health, '2026-09-05'), 0.001);
    }

    public function testChangingEnvelopeDefaultDoesNotRewriteTransactionOrAccountHistory(): void
    {
        $month = $this->createMonth(2026, 9);
        $testBank = $this->account('Test Bank', 'asset');
        $testWallet = $this->account('Test Wallet', 'asset');
        $testCard = $this->account('Test Card', 'liability');
        $health = save_envelope($this->db, ['name' => 'Envelope K', 'role' => 'reserved', 'default_account_id' => $testBank]);
        $transactionId = save_transaction($this->db, [
            'month_id' => $month, 'envelope_id' => $health, 'payment_account_id' => $testCard,
            'amount' => 900, 'spent_date' => '2026-09-06',
        ]);
        $before = [
            $this->db->query("SELECT * FROM transactions WHERE id=$transactionId")->fetch(),
            $this->db->query("SELECT * FROM account_events WHERE transaction_id=$transactionId")->fetch(),
            $this->db->query('SELECT * FROM account_movements')->fetchAll(),
            $this->db->query('SELECT * FROM envelope_movements')->fetchAll(),
        ];

        save_envelope($this->db, ['id' => $health, 'name' => 'Envelope K', 'role' => 'reserved', 'default_account_id' => $testWallet]);

        self::assertSame($before, [
            $this->db->query("SELECT * FROM transactions WHERE id=$transactionId")->fetch(),
            $this->db->query("SELECT * FROM account_events WHERE transaction_id=$transactionId")->fetch(),
            $this->db->query('SELECT * FROM account_movements')->fetchAll(),
            $this->db->query('SELECT * FROM envelope_movements')->fetchAll(),
        ]);
        $envelope = array_values(array_filter(get_envelopes($this->db), fn(array $row): bool => $row['id'] === $health))[0];
        self::assertSame($testWallet, $envelope['default_account_id']);
        self::assertArrayNotHasKey('account', $envelope);
        self::assertSame($testCard, (int)$this->db->query("SELECT payment_account_id FROM transactions WHERE id=$transactionId")->fetchColumn());
    }

    public function testUnknownAndKnownPaymentAccountsUseOneExpensePostingModel(): void
    {
        $month = $this->createMonth(2026, 9);
        $asset = $this->account('Test Wallet', 'asset');
        $household = save_envelope($this->db, ['name' => 'Envelope J', 'role' => 'daily', 'default_account_id' => $asset]);
        $unknownId = save_transaction($this->db, [
            'month_id' => $month, 'envelope_id' => $household, 'amount' => 100, 'spent_date' => '2026-09-07',
        ]);
        $knownId = save_transaction($this->db, [
            'month_id' => $month, 'envelope_id' => $household, 'payment_account_id' => $asset,
            'amount' => 200, 'spent_date' => '2026-09-08',
        ]);
        $this->db->exec("UPDATE envelope_movements SET updated_at='2000-01-01 00:00:00' WHERE transaction_id=$knownId");
        maintain_current_data_model($this->db);

        self::assertSame('2000-01-01 00:00:00', $this->db->query("SELECT updated_at FROM envelope_movements WHERE transaction_id=$knownId")->fetchColumn());
        self::assertNull($this->db->query("SELECT payment_account_id FROM transactions WHERE id=$unknownId")->fetchColumn());
        self::assertSame($asset, (int)$this->db->query("SELECT payment_account_id FROM transactions WHERE id=$knownId")->fetchColumn());
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM account_events')->fetchColumn());
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE transaction_id=$unknownId")->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE transaction_id=$knownId")->fetchColumn());
        self::assertEqualsWithDelta(-300, envelope_balance_as_of($this->db, $household, '2026-09-08'), 0.001);
        self::assertEqualsWithDelta(-200, account_balance_as_of($this->db, $asset, '2026-09-08'), 0.001);
    }

    private function account(string $name, string $type): int
    {
        $accountId = save_account_definition($this->db, ['name' => $name, 'account_number' => 'TEST-' . strtoupper($name), 'currency' => 'CURRENCY']);
        record_account_balance_observation($this->db, $accountId, 0, '2026-09-01', 'Synthetic zero Balance');
        return $accountId;
    }
}
