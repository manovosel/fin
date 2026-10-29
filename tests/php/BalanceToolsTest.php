<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class BalanceToolsTest extends DatabaseTestCase
{
    private string|false $previousToday;

    protected function setUp(): void
    {
        $this->previousToday = getenv('FIN_TODAY');
        putenv('FIN_TODAY=2026-09-10');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->previousToday === false) putenv('FIN_TODAY');
        else putenv('FIN_TODAY=' . $this->previousToday);
    }

    public function testEnvelopeBalanceObservationsAreAbsoluteAndDeletable(): void
    {
        $this->createMonth(2026, 9);
        $envelopeId = $this->createEnvelope('Envelope A');
        putenv('FIN_TODAY=2026-09-05');

        record_envelope_balance_observation($this->db, $envelopeId, 500, '2026-09-01');
        self::assertEqualsWithDelta(500, $this->envelopeBalance($envelopeId), 0.001);
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM envelope_balance_observations WHERE envelope_id=$envelopeId")->fetchColumn());

        $transferId = transfer_envelope_balance($this->db, [
            'source_envelope_id' => $envelopeId,
            'destination_envelope_id' => $this->createEnvelope('Emergency fund'),
            'amount' => 175,
            'transfer_date' => '2026-09-02',
            'note' => 'September top-up',
        ]);
        self::assertEqualsWithDelta(325, $this->envelopeBalance($envelopeId), 0.001);

        $observationsBeforeRevision = (int)$this->db->query("SELECT COUNT(*) FROM envelope_balance_observations WHERE envelope_id=$envelopeId AND effective_date='2026-09-01'")->fetchColumn();
        $firstId = (int)$this->db->query("SELECT id FROM envelope_balance_observations WHERE envelope_id=$envelopeId AND effective_date='2026-09-01'")->fetchColumn();
        record_envelope_balance_observation($this->db, $envelopeId, 325.50, '2026-09-01', 'Bank reconciliation');
        self::assertSame($observationsBeforeRevision, (int)$this->db->query("SELECT COUNT(*) FROM envelope_balance_observations WHERE envelope_id=$envelopeId AND effective_date='2026-09-01'")->fetchColumn(), 'Same-date Envelope Balance observations must be upserted');
        self::assertEqualsWithDelta(325.50, (float)$this->db->query("SELECT balance FROM envelope_balance_observations WHERE id=$firstId")->fetchColumn(), 0.001);

        record_envelope_balance_observation($this->db, $envelopeId, 100, '2026-09-04');
        self::assertEqualsWithDelta(100, $this->envelopeBalance($envelopeId), 0.001, 'Movements strictly after the latest Envelope Balance must still contribute');

        $newId = (int)$this->db->query("SELECT id FROM envelope_balance_observations WHERE envelope_id=$envelopeId AND effective_date='2026-09-04'")->fetchColumn();
        delete_envelope_balance_observation($this->db, $envelopeId, $newId);
        self::assertEqualsWithDelta(150.50, $this->envelopeBalance($envelopeId), 0.001, 'After deletion, balance must fall back to earlier observation plus movements strictly after it');

        $movementsAfterDelete = (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE transfer_id=$transferId")->fetchColumn();
        self::assertSame(2, $movementsAfterDelete, 'Deleting an Envelope Balance observation must not touch movements');

        $this->expectException(InvalidArgumentException::class);
        record_envelope_balance_observation($this->db, $envelopeId, 0, '2026-09-10');
    }

    public function testEnvelopeTransferCreatesLinkedZeroSumMovementsAndCanBeUndone(): void
    {
        $this->createMonth(2026, 9);
        $sourceId = $this->createEnvelope('Travel');
        $destinationId = $this->createEnvelope('Envelope A');
        putenv('FIN_TODAY=2026-09-04');
        record_envelope_balance_observation($this->db, $sourceId, 1000, '2026-09-01');
        record_envelope_balance_observation($this->db, $destinationId, 100, '2026-09-01');

        $transferId = transfer_envelope_balance($this->db, [
            'source_envelope_id' => $sourceId,
            'destination_envelope_id' => $destinationId,
            'amount' => 250,
            'transfer_date' => '2026-09-03',
            'note' => 'Cover spending',
        ]);

        self::assertEqualsWithDelta(750, envelope_balance_as_of($this->db, $sourceId, '2026-09-03'), 0.001);
        self::assertEqualsWithDelta(350, envelope_balance_as_of($this->db, $destinationId, '2026-09-03'), 0.001);
        self::assertEqualsWithDelta(0, (float)$this->db->query("SELECT SUM(amount) FROM envelope_movements WHERE transfer_id = $transferId")->fetchColumn(), 0.001);
        self::assertSame(2, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE transfer_id = $transferId")->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE transfer_id = $transferId AND type = 'transfer_out'")->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE transfer_id = $transferId AND type = 'transfer_in'")->fetchColumn());

        $state = get_state($this->db, 2026);
        $stateTransfer = array_values(array_filter($state['movements'], fn(array $movement): bool => $movement['transfer_id'] === $transferId));
        self::assertCount(2, $stateTransfer);
        self::assertSame('Travel', $stateTransfer[0]['source_envelope']);
        self::assertSame('Envelope A', $stateTransfer[0]['destination_envelope']);

        $report = get_report_data($this->db, '2026-09', '2026-09');
        self::assertCount(2, $report['months'][0]['balance_movements'], 'After Envelope Balance observations replaced opening_balance movements, only the transfer pair should appear in balance_movements');

        undo_envelope_transfer($this->db, $transferId);
        self::assertEqualsWithDelta(1000, $this->envelopeBalance($sourceId), 0.001);
        self::assertEqualsWithDelta(100, $this->envelopeBalance($destinationId), 0.001);
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM envelope_transfers WHERE id = $transferId AND reversed_at IS NOT NULL")->fetchColumn());
        self::assertSame(4, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE transfer_id = $transferId")->fetchColumn());
        self::assertEqualsWithDelta(0, (float)$this->db->query("SELECT SUM(amount) FROM envelope_movements WHERE transfer_id = $transferId")->fetchColumn(), 0.001);
    }

    public function testConfirmedCrossAccountTransferSnapshotsBothAccounts(): void
    {
        $this->createMonth(2026, 9);
        $sourceAccount = save_account_definition($this->db, ['name' => 'Source Account', 'account_number' => 'BALANCE-SOURCE', 'currency' => 'CURRENCY']);
        $destinationAccount = save_account_definition($this->db, ['name' => 'Destination Account', 'account_number' => 'BALANCE-DESTINATION', 'currency' => 'CURRENCY']);
        $sourceId = save_envelope($this->db, ['name' => 'Bank money', 'role' => 'daily', 'default_account_id' => $sourceAccount]);
        $destinationId = save_envelope($this->db, ['name' => 'Card money', 'role' => 'daily', 'default_account_id' => $destinationAccount]);

        $transferId = transfer_envelope_balance($this->db, [
            'source_envelope_id' => $sourceId,
            'destination_envelope_id' => $destinationId,
            'amount' => 50,
            'transfer_date' => '2026-09-04',
            'negative_source_confirmed' => true,
            'cross_account_confirmed' => true,
        ]);

        $transfer = $this->db->query("SELECT * FROM envelope_transfers WHERE id = $transferId")->fetch();
        self::assertSame($sourceAccount, (int)$transfer['source_account_id']);
        self::assertSame($destinationAccount, (int)$transfer['destination_account_id']);
        self::assertEqualsWithDelta(-50, $this->envelopeBalance($sourceId), 0.001);
        self::assertEqualsWithDelta(50, $this->envelopeBalance($destinationId), 0.001);
    }
}
