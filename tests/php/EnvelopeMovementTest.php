<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class EnvelopeMovementTest extends DatabaseTestCase
{
    public function testOnlyFundedAllocationsAddToBalanceAndExpensesSubtract(): void
    {
        $monthId = $this->createMonth();
        $envelopeId = $this->createEnvelope();
        $entryId = $this->saveAllocation($monthId, $envelopeId, 1000, 'planned');

        self::assertEqualsWithDelta(0, $this->envelopeBalance($envelopeId), 0.001);

        $this->saveAllocation($monthId, $envelopeId, 1000, 'paid', $entryId);
        self::assertEqualsWithDelta(1000, $this->envelopeBalance($envelopeId), 0.001);

        save_transaction($this->db, [
            'month_id' => $monthId,
            'entry_id' => $entryId,
            'envelope_id' => $envelopeId,
            'amount' => 250,
            'note' => 'Test expense',
            'spent_date' => '2026-01-06',
        ]);
        self::assertEqualsWithDelta(750, $this->envelopeBalance($envelopeId), 0.001);

        $this->saveAllocation($monthId, $envelopeId, 1000, 'skipped', $entryId);
        self::assertEqualsWithDelta(-250, $this->envelopeBalance($envelopeId), 0.001);
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE type = 'funding'")->fetchColumn());
    }

    public function testEditingExpenseUpdatesItsTransactionAndMovement(): void
    {
        $monthId = $this->createMonth(2026, 1);
        $envelopeAId = $this->createEnvelope('Envelope A');
        $travelId = $this->createEnvelope('Travel');
        $transactionId = save_transaction($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeAId,
            'amount' => 100,
            'note' => 'Original',
            'spent_date' => '2026-01-05',
        ]);

        save_transaction($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $travelId,
            'amount' => 150,
            'note' => 'Updated',
            'spent_date' => '2026-01-12',
        ], $transactionId);

        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE transaction_id = $transactionId AND type = 'expense'")->fetchColumn());
        $transaction = $this->db->query("SELECT * FROM transactions WHERE id = $transactionId")->fetch();
        self::assertSame($travelId, (int)$transaction['envelope_id']);
        self::assertSame('Travel', $transaction['envelope']);
        self::assertEqualsWithDelta(150, (float)$transaction['amount'], 0.001);
        self::assertSame('Updated', $transaction['note']);
        self::assertSame('2026-01-12', $transaction['spent_date']);
        self::assertEqualsWithDelta(0, $this->envelopeBalance($envelopeAId), 0.001);
        self::assertEqualsWithDelta(-150, $this->envelopeBalance($travelId), 0.001);
    }

    public function testHelperExpenseUsesEnvelopePreferenceAndMonthRate(): void
    {
        $monthId = save_month($this->db, ['year' => 2026, 'month' => 1, 'exchange_rate' => 7.5]);
        $envelopeId = $this->createEnvelope('Reserved test envelope', 'reserved', 'HELPER_CURRENCY');

        $transactionId = save_transaction($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'amount' => 20,
            'spent_date' => '2026-01-12',
        ]);

        $transaction = $this->db->query("SELECT amount FROM transactions WHERE id = $transactionId")->fetch();
        self::assertEqualsWithDelta(150, (float)$transaction['amount'], 0.001);
        self::assertEqualsWithDelta(-150, $this->envelopeBalance($envelopeId), 0.001);
    }

    public function testMovingExpenseToAnotherCurrencyUsesNewEnvelopePreference(): void
    {
        $monthId = save_month($this->db, ['year' => 2026, 'month' => 1, 'exchange_rate' => 7.5]);
        $currencyEnvelopeId = $this->createEnvelope('Envelope A', 'daily', 'CURRENCY');
        $helperEnvelopeId = $this->createEnvelope('Travel', 'reserved', 'HELPER_CURRENCY');
        $transactionId = save_transaction($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $currencyEnvelopeId,
            'amount' => 100,
            'spent_date' => '2026-01-05',
        ]);

        save_transaction($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $helperEnvelopeId,
            'amount' => 20,
            'spent_date' => '2026-01-06',
        ], $transactionId);

        $transaction = $this->db->query("SELECT amount FROM transactions WHERE id = $transactionId")->fetch();
        self::assertEqualsWithDelta(150, (float)$transaction['amount'], 0.001);
        self::assertEqualsWithDelta(0, $this->envelopeBalance($currencyEnvelopeId), 0.001);
        self::assertEqualsWithDelta(-150, $this->envelopeBalance($helperEnvelopeId), 0.001);
    }

    public function testBalancePersistsAcrossMonths(): void
    {
        $january = $this->createMonth(2026, 1);
        $february = $this->createMonth(2026, 2);
        $envelopeId = $this->createEnvelope();
        $this->saveAllocation($january, $envelopeId, 1200, 'paid');

        save_transaction($this->db, [
            'month_id' => $february,
            'envelope_id' => $envelopeId,
            'amount' => 300,
            'spent_date' => '2026-02-02',
        ]);

        self::assertEqualsWithDelta(900, $this->envelopeBalance($envelopeId), 0.001);
    }

    public function testDeletingEnvelopeRemovesAllocationsButPreservesTransactionName(): void
    {
        $january = $this->createMonth(2026, 1);
        $february = $this->createMonth(2026, 2);
        $envelopeId = $this->createEnvelope('Travel');
        $this->saveAllocation($january, $envelopeId, 500, 'paid');
        $this->saveAllocation($february, $envelopeId, 600, 'planned');
        save_transaction($this->db, [
            'month_id' => $january,
            'envelope_id' => $envelopeId,
            'envelope' => 'Travel',
            'amount' => 100,
            'spent_date' => '2026-01-12',
        ]);

        delete_envelope($this->db, $envelopeId);

        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM entries WHERE envelope_id = $envelopeId")->fetchColumn());
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM envelopes WHERE id = $envelopeId")->fetchColumn());
        $transaction = $this->db->query("SELECT envelope_id, envelope FROM transactions LIMIT 1")->fetch();
        self::assertNull($transaction['envelope_id']);
        self::assertSame('Travel', $transaction['envelope']);
    }
}
