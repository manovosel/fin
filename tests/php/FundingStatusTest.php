<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class FundingStatusTest extends DatabaseTestCase
{
    public function testIndividualStatusChangesFundingMovement(): void
    {
        $monthId = $this->createMonth();
        $envelopeId = $this->createEnvelope();
        $entryId = $this->saveAllocation($monthId, $envelopeId, 1000, 'planned');

        self::assertSame($entryId, set_allocation_status($this->db, $monthId, $envelopeId, 'paid'));
        self::assertSame('paid', (string)$this->db->query("SELECT status FROM entries WHERE id = $entryId")->fetchColumn());
        self::assertEqualsWithDelta(1000, $this->envelopeBalance($envelopeId), 0.001);

        set_allocation_status($this->db, $monthId, $envelopeId, 'skipped');
        self::assertSame('skipped', (string)$this->db->query("SELECT status FROM entries WHERE id = $entryId")->fetchColumn());
        self::assertEqualsWithDelta(0, $this->envelopeBalance($envelopeId), 0.001);

        set_allocation_status($this->db, $monthId, $envelopeId, 'planned');
        self::assertSame('planned', (string)$this->db->query("SELECT status FROM entries WHERE id = $entryId")->fetchColumn());
        self::assertEqualsWithDelta(0, $this->envelopeBalance($envelopeId), 0.001);
    }

    public function testStatusToggleCreatesAZeroPlanWhenTheRowWasVirtual(): void
    {
        $monthId = $this->createMonth();
        $envelopeId = $this->createEnvelope();

        $entryId = set_allocation_status($this->db, $monthId, $envelopeId, 'skipped');

        self::assertGreaterThan(0, $entryId);
        self::assertSame('skipped', (string)$this->db->query("SELECT status FROM entries WHERE id = $entryId")->fetchColumn());
        self::assertEqualsWithDelta(0, $this->envelopeBalance($envelopeId), 0.001);
    }
}
