<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class MonthCopyTest extends DatabaseTestCase
{
    public function testCopyResetsFundingAndPreservesTargetExpenses(): void
    {
        $sourceId = $this->createMonth(2026, 1);
        $targetId = $this->createMonth(2026, 2);
        $envelopeAId = $this->createEnvelope('Envelope A');
        $travelId = $this->createEnvelope('Travel');
        $this->saveAllocation($sourceId, $envelopeAId, 1000, 'paid');
        $this->saveAllocation($sourceId, $travelId, 400, 'skipped');
        $this->saveAllocation($targetId, $envelopeAId, 200, 'paid');
        save_transaction($this->db, [
            'month_id' => $targetId,
            'envelope_id' => $envelopeAId,
            'amount' => 75,
            'spent_date' => '2026-02-01',
        ]);

        duplicate_month($this->db, 2026, 1, 2026, 2);

        $rows = $this->db->query("SELECT envelope_id, amount, status, due_date FROM entries WHERE month_id = $targetId ORDER BY envelope_id")->fetchAll();
        self::assertCount(2, $rows);
        self::assertSame('planned', $rows[0]['status']);
        self::assertSame('skipped', $rows[1]['status']);
        self::assertNull($rows[0]['due_date']);
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM transactions WHERE month_id = $targetId")->fetchColumn());
        self::assertEqualsWithDelta(925, $this->envelopeBalance($envelopeAId), 0.001, 'Source funding and target expense must both remain in the persistent balance.');
    }
}
