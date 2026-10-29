<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class EnvelopeBalanceObservationTest extends DatabaseTestCase
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

    public function testBalanceUsesLatestObservationPlusLaterMovements(): void
    {
        $this->createMonth(2026, 9);
        $envelopeId = $this->createEnvelope('Envelope A');

        record_envelope_balance_observation($this->db, $envelopeId, 100, '2026-09-02');
        $this->insertMovement($envelopeId, $this->monthId(2026, 9), 'funding', 400, '2026-09-03');
        $this->insertMovement($envelopeId, $this->monthId(2026, 9), 'expense', -50, '2026-09-04');

        self::assertEqualsWithDelta(0, envelope_balance_as_of($this->db, $envelopeId, '2026-09-01'), 0.001);
        self::assertEqualsWithDelta(100, envelope_balance_as_of($this->db, $envelopeId, '2026-09-02'), 0.001);
        self::assertEqualsWithDelta(500, envelope_balance_as_of($this->db, $envelopeId, '2026-09-03'), 0.001);
        self::assertEqualsWithDelta(450, envelope_balance_as_of($this->db, $envelopeId, '2026-09-04'), 0.001);
    }

    public function testBalanceRecomputesWhenObservationDateAdvances(): void
    {
        $this->createMonth(2026, 9);
        $envelopeId = $this->createEnvelope('Travel');

        $this->insertMovement($envelopeId, $this->monthId(2026, 9), 'funding', 1000, '2026-09-01');
        $this->insertMovement($envelopeId, $this->monthId(2026, 9), 'expense', -200, '2026-09-02');
        $this->insertMovement($envelopeId, $this->monthId(2026, 9), 'expense', -50, '2026-09-03');

        record_envelope_balance_observation($this->db, $envelopeId, 900, '2026-09-02');

        self::assertEqualsWithDelta(900, envelope_balance_as_of($this->db, $envelopeId, '2026-09-02'), 0.001);
        self::assertEqualsWithDelta(850, envelope_balance_as_of($this->db, $envelopeId, '2026-09-03'), 0.001);
        self::assertEqualsWithDelta(1000, envelope_balance_as_of($this->db, $envelopeId, '2026-09-01'), 0.001, 'A later observation must not erase movement history strictly before it');
    }

    public function testBalanceWithoutObservationsRemainsMovementSum(): void
    {
        $this->createMonth(2026, 9);
        $envelopeId = $this->createEnvelope('Reserve');
        $this->insertMovement($envelopeId, $this->monthId(2026, 9), 'funding', 800, '2026-09-01');
        $this->insertMovement($envelopeId, $this->monthId(2026, 9), 'expense', -200, '2026-09-05');

        self::assertEqualsWithDelta(800, envelope_balance_as_of($this->db, $envelopeId, '2026-09-01'), 0.001);
        self::assertEqualsWithDelta(800, envelope_balance_as_of($this->db, $envelopeId, '2026-09-04'), 0.001);
        self::assertEqualsWithDelta(600, envelope_balance_as_of($this->db, $envelopeId, '2026-09-05'), 0.001);
    }

    public function testRecordingAndDeletingObservationIsRoundTripStable(): void
    {
        $this->createMonth(2026, 9);
        $envelopeId = $this->createEnvelope('Envelope A');
        $this->insertMovement($envelopeId, $this->monthId(2026, 9), 'funding', 300, '2026-09-01');

        $observationId = record_envelope_balance_observation($this->db, $envelopeId, 300, '2026-09-01');
        self::assertGreaterThan(0, $observationId);
        self::assertSame('manual', (string)$this->db->query('SELECT source FROM envelope_balance_observations WHERE id=' . $observationId)->fetchColumn());

        record_envelope_balance_observation($this->db, $envelopeId, 280, '2026-09-01', 'Correction');
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM envelope_balance_observations WHERE envelope_id=' . $envelopeId)->fetchColumn(), 'Same-date observation must update, not insert');
        self::assertEqualsWithDelta(280, (float)$this->db->query('SELECT balance FROM envelope_balance_observations WHERE id=' . $observationId)->fetchColumn(), 0.001);
        self::assertSame('Correction', (string)$this->db->query('SELECT note FROM envelope_balance_observations WHERE id=' . $observationId)->fetchColumn());

        delete_envelope_balance_observation($this->db, $envelopeId, $observationId);
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM envelope_balance_observations WHERE envelope_id=' . $envelopeId)->fetchColumn());
        self::assertEqualsWithDelta(300, envelope_balance_as_of($this->db, $envelopeId, '2026-09-01'), 0.001, 'After deletion, balance must fall back to the movement sum');
    }

    public function testObservationDateCannotBeInTheFuture(): void
    {
        $envelopeId = $this->createEnvelope('Envelope A');
        $this->expectException(InvalidArgumentException::class);
        record_envelope_balance_observation($this->db, $envelopeId, 100, '2026-12-31');
    }

    public function testDeletingMissingObservationThrows(): void
    {
        $envelopeId = $this->createEnvelope('Envelope A');
        $this->expectException(InvalidArgumentException::class);
        delete_envelope_balance_observation($this->db, $envelopeId, 99999);
    }

    public function testObservationCannotReferenceMissingEnvelope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        record_envelope_balance_observation($this->db, 99999, 100, '2026-09-05');
    }

    public function testStateExposesEnvelopeBalanceObservations(): void
    {
        $this->createMonth(2026, 9);
        $envelopeId = $this->createEnvelope('Envelope A');
        record_envelope_balance_observation($this->db, $envelopeId, 100, '2026-09-04', 'Test note');
        $state = get_state($this->db, 2026);
        self::assertArrayHasKey('envelope_balance_observations', $state);
        $matching = array_values(array_filter($state['envelope_balance_observations'], fn(array $row): bool => (int)$row['envelope_id'] === $envelopeId));
        self::assertCount(1, $matching);
        self::assertSame(100.0, (float)$matching[0]['balance']);
        self::assertSame('manual', $matching[0]['source']);
        self::assertSame('Test note', $matching[0]['note']);
    }

    private function monthId(int $year, int $month): int
    {
        $row = $this->db->prepare('SELECT id FROM months WHERE year=:year AND month=:month');
        $row->execute([':year' => $year, ':month' => $month]);
        return (int)$row->fetchColumn();
    }

    private function insertMovement(int $envelopeId, int $monthId, string $type, float $amount, string $date): void
    {
        $stmt = $this->db->prepare('INSERT INTO envelope_movements (envelope_id, month_id, type, amount, note, movement_date) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$envelopeId, $monthId, $type, $amount, '', $date]);
    }
}
