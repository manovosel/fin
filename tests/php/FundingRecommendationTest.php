<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class FundingRecommendationTest extends DatabaseTestCase
{
    public function testManualBaselineIsReferenceMetadataAndDoesNotInfluenceFinRecommendation(): void
    {
        $envelopeId = $this->createEnvelope('Envelope A', 'daily');
        $this->saveBaseline($envelopeId, 'Envelope A', 'daily', 3500, 'Manual historical review');
        $months = $this->createMonths(1, 9);
        foreach (range(1, 6) as $month) $this->addExpense($months[$month], $envelopeId, 'Envelope A', 1000, sprintf('2026-%02d-05', $month));
        $this->saveAllocation($months[9], $envelopeId, 1000);

        $result = $this->recommendation($envelopeId, 9, '2026-08-14');

        self::assertEqualsWithDelta(1050, (float)$result['amount'], 0.001);
        self::assertSame('good', $result['confidence']);
        self::assertSame('actual', $result['basis']);
        self::assertEqualsWithDelta(3500, (float)$result['manual_baseline'], 0.001);
        self::assertSame('fin', $result['assessment']['source']);
        self::assertSame(6, $result['representative_months']);
        self::assertSame(6, (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
        self::assertSame(6, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE type='expense'")->fetchColumn());
    }

    public function testBaselineProvidesClearlyLabeledComparisonWhenHistoryIsInsufficient(): void
    {
        $envelopeId = $this->createEnvelope('Envelope A', 'daily');
        $this->saveBaseline($envelopeId, 'Envelope A', 'daily', 3500, 'Test Bank, Test Card and Test Wallet review');
        $months = $this->createMonths(1, 4);
        $this->addExpense($months[1], $envelopeId, 'Envelope A', 900, '2026-01-05');
        $this->saveAllocation($months[4], $envelopeId, 3500);

        $result = $this->recommendation($envelopeId, 4, '2026-03-14');

        self::assertNull($result['amount']);
        self::assertSame('insufficient', $result['confidence']);
        self::assertSame(2, $result['representative_months']);
        self::assertSame('baseline', $result['assessment']['source']);
        self::assertSame('Matches historical baseline', $result['assessment']['label']);
        self::assertSame('Test Bank, Test Card and Test Wallet review', $result['manual_baseline_note']);
    }

    public function testEnvelopeUpdateWithoutBaselineFieldsPreservesExistingReference(): void
    {
        $envelopeId = $this->createEnvelope('Envelope L', 'daily');
        $this->saveBaseline($envelopeId, 'Envelope L', 'daily', 1500, 'Manual historical review');

        save_envelope($this->db, [
            'id' => $envelopeId,
            'name' => 'Envelope L',
            'account' => 'Updated account',
            'role' => 'daily',
            'currency' => 'CURRENCY',
        ]);

        $settings = $this->db->query("SELECT manual_baseline, manual_baseline_note FROM envelope_recommendation_settings WHERE envelope_id = $envelopeId")->fetch();
        self::assertEqualsWithDelta(1500, (float)$settings['manual_baseline'], 0.001);
        self::assertSame('Manual historical review', $settings['manual_baseline_note']);
    }

    public function testClearingBaselineRemovesSettingsWithoutChangingFinancialHistory(): void
    {
        $envelopeId = $this->createEnvelope('Envelope L', 'daily');
        $this->saveBaseline($envelopeId, 'Envelope L', 'daily', 1500, 'Manual historical review');
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM envelope_recommendation_settings')->fetchColumn());

        $this->saveBaseline($envelopeId, 'Envelope L', 'daily', null, '');

        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM envelope_recommendation_settings')->fetchColumn());
        self::assertNull(array_values(array_filter(get_envelopes($this->db), fn(array $row): bool => $row['id'] === $envelopeId))[0]['manual_baseline']);
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM envelope_movements')->fetchColumn());
    }

    public function testEnvelopeMonthExclusionAffectsOnlyRecommendationStatistics(): void
    {
        $envelopeId = $this->createEnvelope('Envelope M', 'daily');
        $months = $this->createMonths(1, 7);
        foreach (range(1, 6) as $month) $this->addExpense($months[$month], $envelopeId, 'Envelope M', 1000, sprintf('2026-%02d-05', $month));
        $beforeTransactions = $this->db->query('SELECT * FROM transactions ORDER BY id')->fetchAll();
        $beforeMovements = $this->db->query('SELECT * FROM envelope_movements ORDER BY id')->fetchAll();

        save_recommendation_exclusion($this->db, $envelopeId, 2026, 6, true, 'Vacation');
        $result = $this->recommendation($envelopeId, 7, '2026-07-14');

        self::assertSame(5, $result['representative_months']);
        self::assertTrue($result['observations'][5]['excluded']);
        self::assertSame('Vacation', $result['observations'][5]['exclusion_reason']);
        self::assertSame($beforeTransactions, $this->db->query('SELECT * FROM transactions ORDER BY id')->fetchAll());
        self::assertSame($beforeMovements, $this->db->query('SELECT * FROM envelope_movements ORDER BY id')->fetchAll());

        save_recommendation_exclusion($this->db, $envelopeId, 2026, 6, false);
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM recommendation_exclusions')->fetchColumn());
    }

    public function testDailyAlgorithmLimitsObviousOutlierAndRetainsTrueActual(): void
    {
        $envelopeId = $this->createEnvelope('Envelope A', 'daily');
        $months = $this->createMonths(1, 7);
        foreach ([3000, 3100, 3200, 3300, 3400, 10000] as $index => $amount) {
            $month = $index + 1;
            $this->addExpense($months[$month], $envelopeId, 'Envelope A', $amount, sprintf('2026-%02d-05', $month));
        }

        $result = $this->recommendation($envelopeId, 7, '2026-07-14');

        self::assertSame(1, $result['stats']['outliers_limited']);
        self::assertLessThan(5000, (float)$result['amount']);
        self::assertEqualsWithDelta(10000, (float)$result['observations'][5]['actual'], 0.001);
        self::assertLessThan(10000, (float)$result['observations'][5]['adjusted']);
    }

    public function testStableReservedObligationUsesExactObservedAmountWithoutBuffer(): void
    {
        $envelopeId = $this->createEnvelope('Envelope D', 'reserved');
        $months = $this->createMonths(1, 7);
        foreach (range(1, 6) as $month) $this->addExpense($months[$month], $envelopeId, 'Envelope D', 2864, sprintf('2026-%02d-01', $month), true);

        $result = $this->recommendation($envelopeId, 7, '2026-07-14');

        self::assertSame('stable monthly', $result['pattern']);
        self::assertSame('good', $result['confidence']);
        self::assertEqualsWithDelta(2864, (float)$result['amount'], 0.001);
        self::assertEqualsWithDelta(0, (float)$result['buffer'], 0.001);
    }

    public function testStablePlanFallbackIsLowConfidenceAndOriginNeutral(): void
    {
        $envelopeId = $this->createEnvelope('Envelope D', 'reserved');
        $months = $this->createMonths(1, 7);
        foreach (range(1, 6) as $month) $this->saveAllocation($months[$month], $envelopeId, 2864, 'planned');
        $this->addExpense($months[6], $envelopeId, 'Envelope D', 2864, '2026-06-01', false);

        $result = $this->recommendation($envelopeId, 7, '2026-07-14');

        self::assertSame('plan-supported', $result['basis']);
        self::assertSame('low', $result['confidence']);
        self::assertEqualsWithDelta(2864, (float)$result['amount'], 0.001);
        self::assertSame('none', $result['assessment']['source']);
    }

    public function testEquivalentManualAndAutomaticHistoriesProduceIdenticalRecommendations(): void
    {
        $manual = $this->createEnvelope('Manual obligation', 'reserved');
        $automatic = $this->createEnvelope('Automatic obligation', 'reserved');
        $months = $this->createMonths(1, 7);
        foreach (range(1, 6) as $month) {
            $this->saveAllocation($months[$month], $manual, 1200, 'planned');
            $this->saveAllocation($months[$month], $automatic, 1200, 'planned');
            $this->addExpense($months[$month], $manual, 'Manual obligation', 1200, sprintf('2026-%02d-05', $month), false);
            $this->addExpense($months[$month], $automatic, 'Automatic obligation', 1200, sprintf('2026-%02d-05', $month), true);
        }

        $manualResult = $this->recommendation($manual, 7, '2026-07-14');
        $automaticResult = $this->recommendation($automatic, 7, '2026-07-14');
        foreach (['amount', 'confidence', 'basis', 'pattern', 'representative_months', 'observations'] as $field) {
            self::assertSame($manualResult[$field], $automaticResult[$field], $field);
        }
    }

    public function testRefundsReduceRecommendationObservationsAndNegativeNetIsBoundedAtZero(): void
    {
        $envelopeId = $this->createEnvelope('Refund observations', 'daily');
        $months = $this->createMonths(1, 6);
        $this->addExpense($months[1], $envelopeId, 'Refund observations', 100, '2026-01-05');
        $this->addRefund($months[1], $envelopeId, 'Refund observations', 40, '2026-01-06');
        $this->addExpense($months[2], $envelopeId, 'Refund observations', 100, '2026-02-05');
        $this->addRefund($months[2], $envelopeId, 'Refund observations', 100, '2026-02-06');
        $this->addExpense($months[3], $envelopeId, 'Refund observations', 100, '2026-03-05');
        $this->addRefund($months[3], $envelopeId, 'Refund observations', 150, '2026-03-06');
        $this->addRefund($months[4], $envelopeId, 'Refund observations', 50, '2026-04-06');

        $result = $this->recommendation($envelopeId, 6, '2026-06-14');
        self::assertSame(['2026-01', '2026-02', '2026-03', '2026-04', '2026-05'], array_column($result['observations'], 'month'));
        self::assertSame([60.0, 0.0, 0.0, 0.0, 0.0], array_column($result['observations'], 'actual'));
        self::assertEqualsWithDelta(60, (float)$result['stats']['range_high'], 0.001);
        self::assertEqualsWithDelta(0, (float)$result['stats']['range_low'], 0.001);
    }

    public function testSavingsNeverReceivesSpendingDerivedRecommendation(): void
    {

        $envelopeId = $this->createEnvelope('Envelope N', 'savings');
        $this->saveBaseline($envelopeId, 'Envelope N', 'savings', 170, 'Annual fee review');
        $months = $this->createMonths(1, 7);
        foreach (range(1, 6) as $month) $this->addExpense($months[$month], $envelopeId, 'Envelope N', 500, sprintf('2026-%02d-05', $month));

        $result = $this->recommendation($envelopeId, 7, '2026-07-14');

        self::assertNull($result['amount']);
        self::assertSame('goal-driven', $result['pattern']);
        self::assertSame('baseline', $result['assessment']['source']);
    }

    public function testIrregularDailySpendingIsFundedByItsAverageMonthlyCost(): void
    {
        $envelopeId = $this->createEnvelope('Envelope R', 'daily');
        $trackedEnvelope = $this->createEnvelope('Envelope T', 'daily');
        $months = $this->createMonths(1, 7);
        // FIN tracks from the first recorded month, so another Envelope keeps the
        // tracked window at six months for an Envelope that only spent in one of
        // them. The spending is not a monthly recurrence: its median month is 0.
        $this->addExpense($months[1], $trackedEnvelope, 'Envelope T', 10, '2026-01-05');
        $this->addExpense($months[4], $envelopeId, 'Envelope R', 2157.80, '2026-04-13');
        $this->saveAllocation($months[7], $envelopeId, 300);

        $result = $this->recommendation($envelopeId, 7, '2026-07-14');

        // The average monthly cost is 2157.80 / 6 = 359.63, buffered by 5% and
        // rounded up to 400. The recommendation is never 0 for an Envelope that
        // demonstrably spends.
        self::assertSame('irregular monthly', $result['pattern']);
        self::assertEqualsWithDelta(400, (float)$result['amount'], 0.001);
        self::assertEqualsWithDelta(359.63, (float)$result['stats']['average'], 0.001);
        self::assertSame(0, (int)$result['stats']['outliers_limited']);
        self::assertFalse((bool)$result['observations'][3]['outlier_limited']);
        self::assertEqualsWithDelta(2157.80, (float)$result['observations'][3]['actual'], 0.001);
    }

    public function testPeriodicSpikeDoesNotBecomeAMonthlyRequirement(): void
    {
        $envelopeId = $this->createEnvelope('Envelope P', 'reserved');
        $tracked = [['2025-12', $this->createMonth(2025, 12)]];
        $months = $this->createMonths(1, 12);
        foreach (range(1, 11) as $month) $tracked[] = [sprintf('2026-%02d', $month), $months[$month]];
        // Eight months of 250 and four months of 1450.
        $spikeMonths = ['2025-12', '2026-02', '2026-05', '2026-08'];
        foreach ($tracked as [$month, $monthId]) {
            $amount = in_array($month, $spikeMonths, true) ? 1450 : 250;
            $this->addExpense((int)$monthId, $envelopeId, 'Envelope P', $amount, $month . '-05');
        }
        $this->saveAllocation((int)$months[12], $envelopeId, 650);

        $result = $this->recommendation($envelopeId, 12, '2026-12-14');

        // The average monthly cost is 650, so the recommendation follows the
        // average and not the 1450 spike.
        self::assertSame('irregular monthly', $result['pattern']);
        self::assertEqualsWithDelta(750, (float)$result['amount'], 0.001);
        self::assertEqualsWithDelta(650, (float)$result['stats']['average'], 0.001);
        self::assertEqualsWithDelta(250, (float)$result['stats']['median'], 0.001);
        self::assertSame(12, (int)$result['representative_months']);
    }

    private function createMonths(int $from, int $to): array
    {
        $months = [];
        foreach (range($from, $to) as $month) $months[$month] = $this->createMonth(2026, $month);
        return $months;
    }

    private function addExpense(int $monthId, int $envelopeId, string $envelope, float $amount, string $date, bool $automatic = false): int
    {
        $stmt = $this->db->prepare('INSERT INTO transactions (month_id,envelope_id,envelope,amount,note,spent_date,is_automatic) VALUES (:month_id,:envelope_id,:envelope,:amount,\'\',:spent_date,:automatic)');
        $stmt->execute([':month_id' => $monthId, ':envelope_id' => $envelopeId, ':envelope' => $envelope, ':amount' => $amount, ':spent_date' => $date, ':automatic' => $automatic ? 1 : 0]);
        $id = (int)$this->db->lastInsertId();
        sync_transaction_envelope_movement($this->db, $id);
        return $id;
    }

    private function addRefund(int $monthId, int $envelopeId, string $envelope, float $amount, string $date): int
    {
        $stmt = $this->db->prepare("INSERT INTO transactions (month_id,envelope_id,envelope,amount,note,spent_date,is_automatic,kind) VALUES (:month_id,:envelope_id,:envelope,:amount,'',:spent_date,0,'refund')");
        $stmt->execute([':month_id' => $monthId, ':envelope_id' => $envelopeId, ':envelope' => $envelope, ':amount' => $amount, ':spent_date' => $date]);
        $id = (int)$this->db->lastInsertId();
        sync_transaction_envelope_movement($this->db, $id);
        return $id;
    }

    private function saveBaseline(int $envelopeId, string $name, string $role, ?float $amount, string $note): void
    {
        save_envelope($this->db, [
            'id' => $envelopeId,
            'name' => $name,
            'account' => 'Test account',
            'role' => $role,
            'currency' => 'CURRENCY',
            'manual_baseline' => $amount,
            'manual_baseline_note' => $note,
        ]);
    }

    private function recommendation(int $envelopeId, int $targetMonth, string $today): array
    {
        $data = build_funding_recommendations($this->db, 2026, $targetMonth, $today);
        foreach ($data['recommendations'] as $recommendation) if ($recommendation['envelope_id'] === $envelopeId) return $recommendation;
        self::fail('Recommendation not found');
    }
}
