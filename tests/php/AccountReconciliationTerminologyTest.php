<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class AccountReconciliationTerminologyTest extends DatabaseTestCase
{
    private string|false $previousToday = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousToday = getenv('FIN_TODAY');
    }

    protected function tearDown(): void
    {
        if ($this->previousToday === false) putenv('FIN_TODAY');
        else putenv('FIN_TODAY=' . $this->previousToday);
        parent::tearDown();
    }

    public function testAccountBalanceToolUsesObservationLanguage(): void
    {
        $html = (string)file_get_contents(__DIR__ . '/../../index.html');
        self::assertStringContainsString('<summary>Record Balance</summary>', $html);
        self::assertStringContainsString('Absolute Balance', $html);
        self::assertStringContainsString('Record Balance</button>', $html);
        self::assertStringNotContainsString('<summary>Adjust balance</summary>', $html);
    }

    public function testStatementEvidenceWorkflowStillUsesReconcile(): void
    {
        $html = (string)file_get_contents(__DIR__ . '/../../index.html');
        self::assertStringContainsString('id="reconcileStatementBtn" class="ghost">Reconcile<', $html);
        self::assertStringContainsString('<h2>Reconcile</h2>', $html);
    }

    public function testAccountBalanceRecordingUsesDedicatedObservationApi(): void
    {
        $javascript = (string)file_get_contents(__DIR__ . '/../../app.js');
        self::assertStringContainsString("action: 'saveAccountBalanceObservation'", $javascript);
        self::assertStringContainsString("action: 'deleteAccountBalanceObservation'", $javascript);
        self::assertStringNotContainsString("action: 'reconcileAccount'", $javascript);
    }

    public function testRecordingBalanceCreatesNoCorrectionEventOrMovement(): void
    {
        $accountId = save_account_definition($this->db, [
            'name' => 'Test Bank', 'account_number' => 'ACCOUNT-TERM', 'currency' => 'CURRENCY',
        ]);
        $id = record_account_balance_observation($this->db, $accountId, 9400, '2026-04-20', 'Statement check');
        self::assertGreaterThan(0, $id);
        self::assertSame(9400.0, account_balance_as_of($this->db, $accountId, '2026-04-20'));
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM account_events')->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
    }

    public function testEnvelopeBalanceToolUsesObservationLanguageAndHasNoReconcileOrUndo(): void
    {
        $html = (string)file_get_contents(__DIR__ . '/../../index.html');
        $javascript = (string)file_get_contents(__DIR__ . '/../../app.js');
        self::assertStringContainsString('<summary>Record Balance</summary>', $html);
        self::assertStringContainsString('Absolute Balance', $html);
        self::assertStringNotContainsString('id="reconcileBalanceDetails"', $html);
        self::assertStringNotContainsString('id="reconcileBalanceForm"', $html);
        self::assertStringNotContainsString('Apply adjustment', $html);
        self::assertStringContainsString("action: 'saveEnvelopeBalanceObservation'", $javascript);
        self::assertStringContainsString("action: 'deleteEnvelopeBalanceObservation'", $javascript);
        self::assertStringNotContainsString("action: 'reconcileEnvelope'", $javascript);
        self::assertStringNotContainsString("action: 'undoEnvelopeMovement'", $javascript);
        self::assertStringNotContainsString('undoBalanceMovement(', $javascript);
        self::assertStringNotContainsString('reconcileCurrentEnvelope(', $javascript);
    }

    public function testRecordingEnvelopeBalanceCreatesNoAdjustmentMovement(): void
    {
        $envelopeId = $this->createEnvelope('Envelope A');
        putenv('FIN_TODAY=2026-09-05');
        $id = record_envelope_balance_observation($this->db, $envelopeId, 425.50, '2026-09-05', 'Bank check');
        self::assertGreaterThan(0, $id);
        self::assertEqualsWithDelta(425.50, envelope_balance_as_of($this->db, $envelopeId, '2026-09-05'), 0.001);
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM envelope_movements')->fetchColumn(), 'Recording an Envelope Balance observation must not create a movement');
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
        self::assertSame('manual', (string)$this->db->query('SELECT source FROM envelope_balance_observations WHERE id=' . (int)$id)->fetchColumn());
        delete_envelope_balance_observation($this->db, $envelopeId, (int)$id);
        self::assertEqualsWithDelta(0, envelope_balance_as_of($this->db, $envelopeId, '2026-09-05'), 0.001, 'After deletion the Envelope Balance must fall back to the movement sum from zero');
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM envelope_balance_observations WHERE envelope_id=' . (int)$envelopeId)->fetchColumn());
    }

    public function testDesktopAndMobileControlsExposeUnknownAndOptionalCreationBalance(): void
    {
        $html = (string)file_get_contents(__DIR__ . '/../../index.html');
        $javascript = (string)file_get_contents(__DIR__ . '/../../app.js');
        self::assertStringContainsString('Current Balance <span class="muted">(optional)</span>', $html);
        self::assertStringContainsString("return 'Unknown'", $javascript);
        self::assertStringContainsString("Balance date cannot be in the future", $javascript);
    }
}
