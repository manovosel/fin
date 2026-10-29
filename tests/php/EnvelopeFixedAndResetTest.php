<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

/**
 * The two independent Envelope attributes:
 *
 * - amount source: `history` (the default) or `fixed`. A Fixed Envelope keeps
 *   the monthly amount its owner set; FIN never suggests a different one.
 * - month-end behavior: `accumulate` (the default) or `reset`. A Reset
 *   Envelope starts the new month with exactly that month's allocation: the
 *   balance it carried over is dropped.
 */
final class EnvelopeFixedAndResetTest extends DatabaseTestCase
{
    private string|false $previousToday;

    protected function setUp(): void
    {
        $this->previousToday = getenv('FIN_TODAY');
        putenv('FIN_TODAY=2026-02-05');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->previousToday === false) putenv('FIN_TODAY');
        else putenv('FIN_TODAY=' . $this->previousToday);
    }

    public function testEveryCombinationKeepsItsOwnBalanceBehaviourAcrossTheRollover(): void
    {
        $january = $this->createMonth(2026, 1);
        $february = $this->createMonth(2026, 2);
        $envelopes = [
            'fixed-accumulate' => $this->createEnvelopeWith('Fixed accumulate', 'fixed', 'accumulate'),
            'fixed-reset' => $this->createEnvelopeWith('Fixed reset', 'fixed', 'reset'),
            'history-accumulate' => $this->createEnvelopeWith('History accumulate', 'history', 'accumulate'),
            'history-reset' => $this->createEnvelopeWith('History reset', 'history', 'reset'),
        ];

        foreach ($envelopes as $envelopeId) {
            $this->fund($january, $envelopeId, 1000, '2026-01-05');
            $this->spend($january, $envelopeId, 400, '2026-01-20');
        }
        self::assertEqualsWithDelta(600, $this->balance($envelopes['history-reset']), 0.001);

        // The rollover: February's allocation is funded.
        foreach ($envelopes as $envelopeId) {
            $this->fund($february, $envelopeId, 1200, '2026-02-05');
        }

        self::assertEqualsWithDelta(1800, $this->balance($envelopes['fixed-accumulate']), 0.001, 'A Fixed Envelope still accumulates its balance');
        self::assertEqualsWithDelta(1200, $this->balance($envelopes['fixed-reset']), 0.001, 'A Reset Envelope starts the month with exactly its allocation');
        self::assertEqualsWithDelta(1800, $this->balance($envelopes['history-accumulate']), 0.001, 'An accumulating Envelope keeps its balance');
        self::assertEqualsWithDelta(1200, $this->balance($envelopes['history-reset']), 0.001, 'A Reset Envelope starts the month with exactly its allocation');

        // Only the Reset Envelopes carry a reset event.
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE type = 'month_reset' AND envelope_id = " . $envelopes['fixed-accumulate'])->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE type = 'month_reset' AND envelope_id = " . $envelopes['fixed-reset'])->fetchColumn());
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE type = 'month_reset' AND envelope_id = " . $envelopes['history-accumulate'])->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE type = 'month_reset' AND envelope_id = " . $envelopes['history-reset'])->fetchColumn());
    }

    public function testAnAmountMovedForwardForALaterMonthLeavesTheCarriedOverBalanceAlone(): void
    {
        $january = $this->createMonth(2026, 1);
        $february = $this->createMonth(2026, 2);
        $envelopeId = $this->createEnvelopeWith('Envelope A', 'history', 'reset');

        $this->fund($january, $envelopeId, 1000, '2026-01-05');
        $this->spend($january, $envelopeId, 400, '2026-01-20');
        // February's allocation is funded on the last day of January, so it is
        // not the rollover of February and resets nothing.
        $this->fund($february, $envelopeId, 1200, '2026-01-31');

        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE type = 'month_reset'")->fetchColumn());
        self::assertEqualsWithDelta(1800, $this->balance($envelopeId), 0.001);
    }

    public function testTheResetIsDatedWithTheFundingItBelongsTo(): void
    {
        $january = $this->createMonth(2026, 1);
        $february = $this->createMonth(2026, 2);
        $envelopeId = $this->createEnvelopeWith('Envelope A', 'history', 'reset');

        $this->fund($january, $envelopeId, 1000, '2026-01-05');
        $this->spend($january, $envelopeId, 400, '2026-01-20');
        $this->fund($february, $envelopeId, 1200, '2026-02-05');

        $reset = $this->db->query("SELECT amount, movement_date, month_id, entry_id FROM envelope_movements WHERE type = 'month_reset' AND envelope_id = " . $envelopeId)->fetch();
        self::assertEqualsWithDelta(-600, (float)$reset['amount'], 0.001, 'The carried-over balance is dropped');
        self::assertSame('2026-02-05', (string)$reset['movement_date']);
        self::assertSame($february, (int)$reset['month_id']);
        self::assertNotNull($reset['entry_id']);
    }

    public function testResetDropsAPreviousNegativeBalanceInsteadOfInheritingIt(): void
    {
        $january = $this->createMonth(2026, 1);
        $february = $this->createMonth(2026, 2);
        $envelopeId = $this->createEnvelopeWith('Envelope A', 'history', 'reset');

        $this->fund($january, $envelopeId, 1000, '2026-01-05');
        $this->spend($january, $envelopeId, 1400, '2026-01-20');
        self::assertEqualsWithDelta(-400, $this->balance($envelopeId), 0.001);

        $this->fund($february, $envelopeId, 1200, '2026-02-05');

        self::assertEqualsWithDelta(1200, $this->balance($envelopeId), 0.001, 'No negative balance is inherited');
    }

    public function testThePreviousMonthResultStaysVisibleInHistory(): void
    {
        $january = $this->createMonth(2026, 1);
        $february = $this->createMonth(2026, 2);
        $envelopeId = $this->createEnvelopeWith('Envelope A', 'history', 'reset');

        $this->fund($january, $envelopeId, 1000, '2026-01-05');
        $this->spend($january, $envelopeId, 400, '2026-01-20');
        $this->fund($february, $envelopeId, 1200, '2026-02-05');

        $model = build_pdf_report_model($this->db, get_report_data($this->db, '2026-01', '2026-02'));
        $januaryRow = $this->roleRow($model['months'][0]['daily'], 'Envelope A');
        $februaryRow = $this->roleRow($model['months'][1]['daily'], 'Envelope A');

        self::assertEqualsWithDelta(1000, (float)$januaryRow['planned'], 0.001);
        self::assertEqualsWithDelta(400, (float)$januaryRow['actual'], 0.001);
        self::assertEqualsWithDelta(600, (float)$januaryRow['balance'], 0.001, 'The previous month keeps its own result');
        self::assertEqualsWithDelta(1200, (float)$februaryRow['planned'], 0.001);
        self::assertEqualsWithDelta(1200, (float)$februaryRow['funded'], 0.001);
        self::assertEqualsWithDelta(1200, (float)$februaryRow['balance'], 0.001);
    }

    public function testTheRecommenderSkipsFixedEnvelopesAndStillRecommendsHistoryBasedOnes(): void
    {
        $fixed = $this->createEnvelopeWith('Fixed obligation', 'fixed', 'accumulate');
        $fixedReset = $this->createEnvelopeWith('Fixed reset obligation', 'fixed', 'reset');
        $history = $this->createEnvelopeWith('History obligation', 'history', 'accumulate');
        $historyReset = $this->createEnvelopeWith('History reset obligation', 'history', 'reset');

        $months = [];
        foreach (range(9, 12) as $month) $months[] = $this->createMonth(2025, $month);
        $months[] = $this->createMonth(2026, 1);
        $months[] = $this->createMonth(2026, 2);
        foreach ($months as $index => $monthId) {
            $spent = sprintf('%04d-%02d-05', $index < 4 ? 2025 : 2026, $index < 4 ? $index + 9 : $index - 3);
            foreach ([$fixed, $fixedReset, $history, $historyReset] as $envelopeId) {
                $this->spend($monthId, $envelopeId, 1000, $spent);
            }
        }

        $data = build_funding_recommendations($this->db, 2026, 2, '2026-02-05');
        $ids = array_map(static fn(array $row): int => (int)$row['envelope_id'], $data['recommendations']);

        self::assertNotContains($fixed, $ids, 'A Fixed Envelope is skipped by the recommender');
        self::assertNotContains($fixedReset, $ids, 'A Fixed Envelope is skipped by the recommender');
        self::assertContains($history, $ids);
        self::assertContains($historyReset, $ids, 'A history-based Reset Envelope is still recommended');

        foreach ($data['recommendations'] as $row) {
            if ((int)$row['envelope_id'] !== $historyReset) continue;
            self::assertEqualsWithDelta(1050, (float)$row['amount'], 0.001);
            self::assertSame('actual', $row['basis']);
        }
    }

    public function testChangingTheAllocationAmountKeepsTheResetConsistent(): void
    {
        $january = $this->createMonth(2026, 1);
        $february = $this->createMonth(2026, 2);
        $envelopeId = $this->createEnvelopeWith('Envelope A', 'history', 'reset');

        $this->fund($january, $envelopeId, 1000, '2026-01-05');
        $this->spend($january, $envelopeId, 400, '2026-01-20');
        $entryId = $this->fund($february, $envelopeId, 1200, '2026-02-05');
        self::assertEqualsWithDelta(1200, $this->balance($envelopeId), 0.001);

        save_entry($this->db, [
            'month_id' => $february,
            'envelope_id' => $envelopeId,
            'type' => 'expense',
            'amount' => 1500,
            'status' => 'paid',
            'due_date' => '2026-02-05',
        ], $entryId);

        self::assertEqualsWithDelta(1500, $this->balance($envelopeId), 0.001, 'The envelope holds the changed allocation');
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE type = 'month_reset'")->fetchColumn());
    }

    public function testUnfundingRemovesTheResetWithTheFundingItBelongsTo(): void
    {
        $january = $this->createMonth(2026, 1);
        $february = $this->createMonth(2026, 2);
        $envelopeId = $this->createEnvelopeWith('Envelope A', 'history', 'reset');

        $this->fund($january, $envelopeId, 1000, '2026-01-05');
        $this->spend($january, $envelopeId, 400, '2026-01-20');
        $entryId = $this->fund($february, $envelopeId, 1200, '2026-02-05');
        self::assertEqualsWithDelta(1200, $this->balance($envelopeId), 0.001);

        save_entry($this->db, [
            'month_id' => $february,
            'envelope_id' => $envelopeId,
            'type' => 'expense',
            'amount' => 1200,
            'status' => 'planned',
            'due_date' => '2026-02-05',
        ], $entryId);

        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE type = 'month_reset'")->fetchColumn());
        self::assertEqualsWithDelta(600, $this->balance($envelopeId), 0.001, 'Without the funding the envelope keeps its carried-over balance');
    }

    public function testEnvelopeAttributesDefaultToHistoryAndAccumulatedAndArePreservedWhenUnchanged(): void
    {
        $envelopeId = $this->createEnvelopeWith('Envelope A', 'history', 'accumulate');
        $stored = $this->db->query('SELECT amount_source, month_end_behavior FROM envelopes WHERE id = ' . $envelopeId)->fetch();
        self::assertSame('history', (string)$stored['amount_source']);
        self::assertSame('accumulate', (string)$stored['month_end_behavior']);

        // A save that does not mention the attributes keeps them.
        save_envelope($this->db, [
            'id' => $envelopeId,
            'name' => 'Envelope A',
            'role' => 'daily',
            'currency' => 'CURRENCY',
            'amount_source' => 'fixed',
            'month_end_behavior' => 'reset',
        ]);
        save_envelope($this->db, [
            'id' => $envelopeId,
            'name' => 'Envelope A and household',
            'role' => 'daily',
            'currency' => 'CURRENCY',
        ]);
        $stored = $this->db->query('SELECT name, amount_source, month_end_behavior FROM envelopes WHERE id = ' . $envelopeId)->fetch();
        self::assertSame('Envelope A and household', (string)$stored['name']);
        self::assertSame('fixed', (string)$stored['amount_source']);
        self::assertSame('reset', (string)$stored['month_end_behavior']);
    }

    private function createEnvelopeWith(string $name, string $amountSource, string $monthEndBehavior): int
    {
        return save_envelope($this->db, [
            'name' => $name,
            'role' => 'daily',
            'currency' => 'CURRENCY',
            'amount_source' => $amountSource,
            'month_end_behavior' => $monthEndBehavior,
        ]);
    }

    private function fund(int $monthId, int $envelopeId, float $amount, string $fundingDate): int
    {
        return save_entry($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'type' => 'expense',
            'amount' => $amount,
            'status' => 'paid',
            'due_date' => $fundingDate,
        ]);
    }

    private function spend(int $monthId, int $envelopeId, float $amount, string $date): int
    {
        $stmt = $this->db->prepare('INSERT INTO transactions (month_id,envelope_id,envelope,amount,note,spent_date,is_automatic) VALUES (:month_id,:envelope_id,:envelope,:amount,\'\',:spent_date,0)');
        $stmt->execute([
            ':month_id' => $monthId,
            ':envelope_id' => $envelopeId,
            ':envelope' => (string)$this->db->query('SELECT name FROM envelopes WHERE id = ' . $envelopeId)->fetchColumn(),
            ':amount' => $amount,
            ':spent_date' => $date,
        ]);
        $id = (int)$this->db->lastInsertId();
        sync_transaction_envelope_movement($this->db, $id);
        return $id;
    }

    private function balance(int $envelopeId): float
    {
        return envelope_balance_as_of($this->db, $envelopeId, effective_today());
    }

    private function roleRow(array $table, string $envelope): array
    {
        foreach ($table['rows'] as $row) if ((string)$row['envelope'] === $envelope) return $row;
        self::fail('Envelope ' . $envelope . ' is missing from the report table');
    }
}
