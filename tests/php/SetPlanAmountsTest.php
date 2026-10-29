<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

/**
 * The `setPlanAmounts` action sets the planned amount of Plan rows for one
 * existing Month. It must never touch anything but the amount of the listed
 * rows.
 */
final class SetPlanAmountsTest extends DatabaseTestCase
{
    private int $monthId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->monthId = $this->createMonth(2026, 11);
    }

    public function testAMonthThatDoesNotExistIsRejectedAndNothingIsWritten(): void
    {
        $envelopeId = $this->createEnvelope('Envelope A');

        try {
            set_plan_amounts($this->db, ['year' => 2026, 'month' => 12, 'amounts' => [['envelope_id' => $envelopeId, 'amount' => 4000]]]);
            self::fail('A Month that does not exist must be rejected');
        } catch (PlanAmountException $error) {
            self::assertSame(404, $error->status());
            self::assertSame('Month not found', $error->getMessage());
        }

        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM entries')->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM months WHERE year = 2026 AND month = 12')->fetchColumn());
    }

    public function testAFundedRowIsRejectedAndKeepsItsAmount(): void
    {
        $envelopeId = $this->createEnvelope('Rent');
        $this->saveAllocation($this->monthId, $envelopeId, 5000, 'paid');

        try {
            set_plan_amounts($this->db, ['year' => 2026, 'month' => 11, 'amounts' => [['envelope_id' => $envelopeId, 'amount' => 4000]]]);
            self::fail('A Funded Plan row must be rejected');
        } catch (PlanAmountException $error) {
            self::assertSame(409, $error->status());
            self::assertStringContainsString('Funded', $error->getMessage());
            self::assertStringContainsString('Rent', $error->getMessage());
        }

        self::assertSame(5000.0, (float)$this->db->query('SELECT amount FROM entries')->fetchColumn());
        self::assertSame('paid', (string)$this->db->query('SELECT status FROM entries')->fetchColumn());
    }

    public function testASkippedRowIsRejectedAndKeepsItsAmount(): void
    {
        $envelopeId = $this->createEnvelope('Travel');
        $this->saveAllocation($this->monthId, $envelopeId, 1200, 'skipped');

        try {
            set_plan_amounts($this->db, ['year' => 2026, 'month' => 11, 'amounts' => [['envelope_id' => $envelopeId, 'amount' => 4000]]]);
            self::fail('A Skipped Plan row must be rejected');
        } catch (PlanAmountException $error) {
            self::assertSame(409, $error->status());
            self::assertStringContainsString('Skipped', $error->getMessage());
        }

        self::assertSame(1200.0, (float)$this->db->query('SELECT amount FROM entries')->fetchColumn());
        self::assertSame('skipped', (string)$this->db->query('SELECT status FROM entries')->fetchColumn());
    }

    public function testAPartialBatchChangesOnlyTheListedEnvelopes(): void
    {
        $envelopeA = $this->createEnvelope('Envelope A');
        $rent = $this->createEnvelope('Rent');
        $fuel = $this->createEnvelope('Fuel');
        $this->saveAllocation($this->monthId, $envelopeA, 1000);
        $this->saveAllocation($this->monthId, $rent, 5000);
        $this->saveAllocation($this->monthId, $fuel, 700);

        $result = set_plan_amounts($this->db, [
            'year' => 2026,
            'month' => 11,
            'amounts' => [
                ['envelope_id' => $envelopeA, 'amount' => 2500.55],
                ['envelope_id' => $fuel, 'amount' => 0],
            ],
        ]);

        self::assertTrue($result['ok']);
        self::assertCount(2, $result['rows']);
        self::assertSame(2500.55, (float)$this->db->query('SELECT amount FROM entries WHERE envelope_id = ' . $envelopeA)->fetchColumn());
        self::assertSame(5000.0, (float)$this->db->query('SELECT amount FROM entries WHERE envelope_id = ' . $rent)->fetchColumn());
        self::assertSame(0.0, (float)$this->db->query('SELECT amount FROM entries WHERE envelope_id = ' . $fuel)->fetchColumn());
    }

    public function testAnInvalidRowWritesNothing(): void
    {
        $envelopeA = $this->createEnvelope('Envelope A');
        $rent = $this->createEnvelope('Rent');
        $this->saveAllocation($this->monthId, $envelopeA, 1000);
        $this->saveAllocation($this->monthId, $rent, 5000);

        try {
            set_plan_amounts($this->db, [
                'year' => 2026,
                'month' => 11,
                'amounts' => [
                    ['envelope_id' => $envelopeA, 'amount' => 2500],
                    ['envelope_id' => $rent, 'amount' => -1],
                ],
            ]);
            self::fail('A negative amount must be rejected');
        } catch (PlanAmountException $error) {
            self::assertSame(400, $error->status());
            self::assertStringContainsString('Row 2', $error->getMessage());
            self::assertStringContainsString('zero or more', $error->getMessage());
        }

        self::assertSame(1000.0, (float)$this->db->query('SELECT amount FROM entries WHERE envelope_id = ' . $envelopeA)->fetchColumn());
        self::assertSame(5000.0, (float)$this->db->query('SELECT amount FROM entries WHERE envelope_id = ' . $rent)->fetchColumn());
    }

    public function testTheStatusStaysPlannedAndNoMovementOrTransactionAppears(): void
    {
        $envelopeA = $this->createEnvelope('Envelope A');
        $rent = $this->createEnvelope('Rent');
        $this->saveAllocation($this->monthId, $envelopeA, 1000);
        $this->saveAllocation($this->monthId, $rent, 5000);

        set_plan_amounts($this->db, [
            'year' => 2026,
            'month' => 11,
            'amounts' => [
                ['envelope_id' => $envelopeA, 'amount' => 2500],
                ['envelope_id' => $rent, 'amount' => 5500],
            ],
        ]);

        $statuses = $this->db->query('SELECT status FROM entries ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['planned', 'planned'], array_map('strval', $statuses));
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM envelope_movements')->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
        self::assertSame(0.0, $this->envelopeBalance($envelopeA));
        self::assertSame(0.0, $this->envelopeBalance($rent));
    }

    public function testAMissingRowIsCreatedAsAPlainPlannedRow(): void
    {
        $envelopeId = $this->createEnvelope('Envelope A');

        $result = set_plan_amounts($this->db, [
            'year' => 2026,
            'month' => 11,
            'amounts' => [['envelope_id' => $envelopeId, 'amount' => 4000]],
        ]);

        $entry = $this->db->query('SELECT * FROM entries')->fetch();
        self::assertNotFalse($entry);
        self::assertSame('planned', (string)$entry['status']);
        self::assertSame(0, (int)$entry['is_bs']);
        self::assertNull($entry['due_date']);
        self::assertNull($entry['bs_payment_account_id']);
        self::assertSame('', (string)$entry['note']);
        self::assertSame('expense', (string)$entry['type']);
        self::assertSame('Envelope A', (string)$entry['name']);
        self::assertSame(4000.0, (float)$entry['amount']);
        self::assertSame($this->monthId, (int)$entry['month_id']);
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM envelope_movements')->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());

        self::assertSame([[
            'envelope_id' => $envelopeId,
            'envelope' => 'Envelope A',
            'previous' => null,
            'amount' => 4000.0,
            'status' => 'planned',
        ]], $result['rows']);
    }

    public function testAnExistingRowKeepsItsOtherFieldsAndReportsItsPreviousAmount(): void
    {
        $envelopeId = $this->createEnvelope('Rent');
        $entryId = $this->saveAllocation($this->monthId, $envelopeId, 5000);
        $this->db->prepare("UPDATE entries SET note = 'Housing', due_date = '2026-11-03', is_recurring = 0 WHERE id = :id")->execute([':id' => $entryId]);

        $result = set_plan_amounts($this->db, [
            'year' => 2026,
            'month' => 11,
            'amounts' => [['envelope_id' => $envelopeId, 'amount' => 5200.25]],
        ]);

        $entry = $this->db->query('SELECT * FROM entries')->fetch();
        self::assertSame(5200.25, (float)$entry['amount']);
        self::assertSame('planned', (string)$entry['status']);
        self::assertSame('Housing', (string)$entry['note']);
        self::assertSame('2026-11-03', (string)$entry['due_date']);
        self::assertSame(0, (int)$entry['is_recurring']);
        self::assertSame(0, (int)$entry['is_bs']);
        self::assertSame(5000.0, $result['rows'][0]['previous']);
        self::assertSame(5200.25, $result['rows'][0]['amount']);
        self::assertSame('Rent', $result['rows'][0]['envelope']);
    }

    public function testADuplicateEnvelopeIdInOneRequestIsRejected(): void
    {
        $envelopeId = $this->createEnvelope('Envelope A');

        try {
            set_plan_amounts($this->db, [
                'year' => 2026,
                'month' => 11,
                'amounts' => [
                    ['envelope_id' => $envelopeId, 'amount' => 100],
                    ['envelope_id' => $envelopeId, 'amount' => 200],
                ],
            ]);
            self::fail('A duplicated Envelope must be rejected');
        } catch (PlanAmountException $error) {
            self::assertSame(400, $error->status());
            self::assertStringContainsString('more than once', $error->getMessage());
        }

        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM entries')->fetchColumn());
    }

    public function testAmountsMustBeNumbersWithAtMostTwoDecimals(): void
    {
        $envelopeId = $this->createEnvelope('Envelope A');
        $invalid = [
            'a string' => '4000',
            'three decimals' => 4000.555,
            'not a number' => true,
        ];
        foreach ($invalid as $label => $amount) {
            try {
                set_plan_amounts($this->db, ['year' => 2026, 'month' => 11, 'amounts' => [['envelope_id' => $envelopeId, 'amount' => $amount]]]);
                self::fail('An invalid amount must be rejected: ' . $label);
            } catch (PlanAmountException $error) {
                self::assertSame(400, $error->status(), $label);
                self::assertStringContainsString('Row 1', $error->getMessage(), $label);
            }
        }
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM entries')->fetchColumn());
    }

    public function testTheRequestNeedsAtLeastOneRowAndStaysBelowTheCap(): void
    {
        $envelopeId = $this->createEnvelope('Envelope A');

        try {
            set_plan_amounts($this->db, ['year' => 2026, 'month' => 11, 'amounts' => []]);
            self::fail('An empty request must be rejected');
        } catch (PlanAmountException $error) {
            self::assertSame(400, $error->status());
            self::assertStringContainsString('At least one', $error->getMessage());
        }

        $tooMany = [];
        for ($index = 0; $index <= FIN_PLAN_AMOUNT_MAX_ROWS; $index++) {
            $tooMany[] = ['envelope_id' => $envelopeId + $index, 'amount' => 1];
        }
        try {
            set_plan_amounts($this->db, ['year' => 2026, 'month' => 11, 'amounts' => $tooMany]);
            self::fail('A request above the cap must be rejected');
        } catch (PlanAmountException $error) {
            self::assertSame(400, $error->status());
            self::assertStringContainsString('at most', $error->getMessage());
        }
    }

    public function testAnUnknownEnvelopeIsRejected(): void
    {
        try {
            set_plan_amounts($this->db, ['year' => 2026, 'month' => 11, 'amounts' => [['envelope_id' => 987, 'amount' => 100]]]);
            self::fail('An unknown Envelope must be rejected');
        } catch (PlanAmountException $error) {
            self::assertSame(404, $error->status());
            self::assertStringContainsString('Row 1', $error->getMessage());
            self::assertStringContainsString('987', $error->getMessage());
        }
    }

    public function testTheMonthAndCurrencyAreReported(): void
    {
        $envelopeId = $this->createEnvelope('Envelope A');
        $result = set_plan_amounts($this->db, ['year' => 2026, 'month' => 11, 'amounts' => [['envelope_id' => $envelopeId, 'amount' => 10]]]);
        self::assertSame(2026, $result['year']);
        self::assertSame(11, $result['month']);
        self::assertSame((string)currency_context($this->db)['currency'], $result['currency']);
    }
}
