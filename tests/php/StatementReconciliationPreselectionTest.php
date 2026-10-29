<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class StatementReconciliationPreselectionTest extends DatabaseTestCase
{
    private string $currency;
    private int $sourceRow = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->currency = (string)currency_context($this->db)['currency'];
    }

    public function testSimilarityUsesWholeAndCompactLocalScoresWithShortStringSafety(): void
    {
        self::assertSame('weekly grocery market', statement_reconciliation_comparison_description("  WEEKLY,   grocery   market!!  "));
        self::assertSame(100.0, statement_reconciliation_description_similarity(
            'rujan 2025 · ENERGO METAN D.O.O.',
            'Energometan'
        ));
        self::assertSame(100.0, statement_reconciliation_description_similarity(
            'monthly transfer Energometan',
            'Energometan'
        ));
        self::assertSame(100.0, statement_reconciliation_description_similarity(
            'Energometan monthly charge',
            'Energometan'
        ));
        self::assertSame(100.0, statement_reconciliation_description_similarity('ENERGO METAN', 'Energometan'));
        self::assertSame(100.0, statement_reconciliation_description_similarity('ENERGO-METAN D.O.O.', 'energometandoo'));

        $tinyWholeStringScore = statement_reconciliation_levenshtein_score('prefix abc suffix', 'abc');
        self::assertEqualsWithDelta(17.6470588235, $tinyWholeStringScore, 0.0000001);
        self::assertSame(
            $tinyWholeStringScore,
            statement_reconciliation_description_similarity('prefix abc suffix', 'abc')
        );
        $unicodeTinyWholeStringScore = statement_reconciliation_levenshtein_score('prefix čćž suffix', 'čćž');
        self::assertSame(
            $unicodeTinyWholeStringScore,
            statement_reconciliation_description_similarity('prefix čćž suffix', 'čćž')
        );
    }

    public function testLoadingReconciliationRepairsOnlyPersistedMalformedDescriptions(): void
    {
        $account = $this->account('Account A');
        for ($index = 0; $index < 6; $index++) {
            $this->statementRow(
                $account,
                -10 - $index,
                "\xCEle-de-France Mobilit\xC3\xA9s"
            );
        }
        $before = $this->db->query('SELECT id, account_id, booking_date, amount, currency, parse_problem
            FROM statement_rows ORDER BY id')->fetchAll();

        $state = statement_reconciliation_load_for_account($this->db, $account);

        self::assertSame(6, $state['unresolved_count']);
        self::assertCount(6, $state['unresolved']);
        foreach ($state['unresolved'] as $row) {
            self::assertSame('Île-de-France Mobilités', $row['description_original']);
            self::assertTrue(statement_text_is_valid_utf8($row['description_original']));
        }
        self::assertSame($before, $this->db->query('SELECT id, account_id, booking_date, amount, currency, parse_problem
            FROM statement_rows ORDER BY id')->fetchAll());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM statement_reconciliations')->fetchColumn());
        self::assertSame(
            array_fill(0, 6, 'Île-de-France Mobilités'),
            $this->db->query('SELECT description_original FROM statement_rows ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)
        );
        self::assertNotFalse(json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function testRealisticOrdinaryTransactionLocalMatchBeatsAWeakerNewerCandidateAndRetainsCrossSourceTieBreaking(): void
    {
        $historyAccount = $this->account('History Account');
        $currentAccount = $this->account('Current Account');
        $ordinaryEnvelope = $this->createEnvelope('Ordinary Envelope');
        $reconciledEnvelope = $this->createEnvelope('Reconciled Envelope');

        $ordinaryTransaction = $this->ordinaryTransaction($historyAccount, $ordinaryEnvelope, 'Energometan');
        $reconciledRow = $this->statementRow($historyAccount, -11, 'Energo heating');
        $this->reconcile($historyAccount, $reconciledRow, 'envelope:' . $reconciledEnvelope);
        $reconciledTransaction = (int)$this->db->query("SELECT transaction_id FROM statement_reconciliations WHERE statement_row_id=$reconciledRow")->fetchColumn();
        $this->db->exec("UPDATE transactions SET created_at='2026-01-01 10:00:00' WHERE id=$ordinaryTransaction");
        $this->db->exec("UPDATE transactions SET created_at='2026-01-03 10:00:00' WHERE id=$reconciledTransaction");
        $this->db->exec("UPDATE statement_reconciliations SET created_at='2026-01-03 10:00:00' WHERE statement_row_id=$reconciledRow");

        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM statement_reconciliations WHERE transaction_id=$ordinaryTransaction")->fetchColumn());
        self::assertGreaterThan(
            statement_reconciliation_description_similarity('rujan 2025 · ENERGO METAN D.O.O.', 'Energo heating'),
            statement_reconciliation_description_similarity('rujan 2025 · ENERGO METAN D.O.O.', 'Energometan')
        );
        $similar = $this->statementRow($currentAccount, -12, 'rujan 2025 · ENERGO METAN D.O.O.');
        self::assertSame('envelope:' . $ordinaryEnvelope, $this->preselection($currentAccount, $similar));

        $ordinaryTieEnvelope = $this->createEnvelope('Ordinary Tie Envelope');
        $reconciledTieEnvelope = $this->createEnvelope('Reconciled Tie Envelope');
        $ordinaryTieTransaction = $this->ordinaryTransaction($historyAccount, $ordinaryTieEnvelope, 'Cross source tie');
        $reconciledTieRow = $this->statementRow($historyAccount, -13, 'Cross source tie');
        $this->reconcile($historyAccount, $reconciledTieRow, 'envelope:' . $reconciledTieEnvelope);
        $reconciledTieTransaction = (int)$this->db->query("SELECT transaction_id FROM statement_reconciliations WHERE statement_row_id=$reconciledTieRow")->fetchColumn();
        $this->db->exec("UPDATE transactions SET created_at='2026-01-01 10:00:00' WHERE id=$ordinaryTieTransaction");
        $this->db->exec("UPDATE transactions SET note='Unrelated generated transaction note', created_at='2026-01-02 10:00:00' WHERE id=$reconciledTieTransaction");
        $this->db->exec("UPDATE statement_reconciliations SET created_at='2026-01-02 10:00:00' WHERE statement_row_id=$reconciledTieRow");
        $crossSourceTie = $this->statementRow($currentAccount, -14, 'Cross source tie');
        self::assertSame('envelope:' . $reconciledTieEnvelope, $this->preselection($currentAccount, $crossSourceTie));
    }

    public function testThresholdAcceptsScoresAtOrAboveConfiguredValueAndRejectsScoresBelowIt(): void
    {
        $threshold = STATEMENT_RECONCILIATION_PRESELECTION_THRESHOLD;
        self::assertGreaterThan(0.0, $threshold);
        self::assertLessThanOrEqual(100.0, $threshold);

        $fixtureLength = 100;
        $eligibleMatches = (int)ceil($threshold);
        $ineligibleMatches = $eligibleMatches - 1;
        $eligibleDescription = str_repeat('a', $fixtureLength);
        $eligibleHistory = str_repeat('a', $eligibleMatches)
            . str_repeat('b', $fixtureLength - $eligibleMatches);
        $ineligibleDescription = str_repeat('c', $fixtureLength);
        $ineligibleHistory = str_repeat('c', $ineligibleMatches)
            . str_repeat('d', $fixtureLength - $ineligibleMatches);

        $account = $this->account('Statement Account');
        $eligibleEnvelope = $this->createEnvelope('Eligible Envelope');
        $ineligibleEnvelope = $this->createEnvelope('Ineligible Envelope');
        $this->ordinaryTransaction($account, $eligibleEnvelope, $eligibleHistory);
        $this->ordinaryTransaction($account, $ineligibleEnvelope, $ineligibleHistory);

        self::assertGreaterThanOrEqual(
            $threshold,
            statement_reconciliation_description_similarity($eligibleDescription, $eligibleHistory)
        );
        $eligible = $this->statementRow($account, -20, $eligibleDescription);
        self::assertSame('envelope:' . $eligibleEnvelope, $this->preselection($account, $eligible));

        self::assertLessThan(
            $threshold,
            statement_reconciliation_description_similarity($ineligibleDescription, $ineligibleHistory)
        );
        $ineligible = $this->statementRow($account, -21, $ineligibleDescription);
        self::assertNull($this->preselection($account, $ineligible));
    }

    public function testOrdinaryTransferNotesRespectStatementAccountDirectionAndSelectableCounterpart(): void
    {
        $statementAccount = $this->account('Statement Account');
        $otherStatementAccount = $this->account('Other Statement Account');
        $outgoingCounterpart = $this->account('Outgoing Counterpart');
        $incomingCounterpart = $this->account('Incoming Counterpart');
        $wrongAccountCounterpart = $this->account('Wrong Account Counterpart');
        $oppositeDirectionCounterpart = $this->account('Opposite Direction Counterpart');
        $archivedCounterpart = $this->account('Archived Counterpart');
        $reconciledCounterpart = $this->account('Reconciled Counterpart');

        record_account_transfer($this->db, $statementAccount, $outgoingCounterpart, 20, '2026-05-01', 'Transfer to household account');
        record_account_transfer($this->db, $incomingCounterpart, $statementAccount, 21, '2026-05-02', 'Transfer from reserve account');
        record_account_transfer($this->db, $otherStatementAccount, $wrongAccountCounterpart, 22, '2026-05-03', 'Different account history');
        record_account_transfer($this->db, $oppositeDirectionCounterpart, $statementAccount, 23, '2026-05-04', 'Opposite direction history');
        record_account_transfer($this->db, $statementAccount, $archivedCounterpart, 24, '2026-05-05', 'Unavailable transfer target');
        $reconciledRow = $this->statementRow($statementAccount, -25, 'Reconciled statement description');
        $this->reconcile($statementAccount, $reconciledRow, 'account:' . $reconciledCounterpart);
        $reconciledEvent = (int)$this->db->query("SELECT target_account_event_id FROM statement_reconciliations WHERE statement_row_id=$reconciledRow")->fetchColumn();
        $this->db->exec("UPDATE account_events SET note='Unrelated event note' WHERE id=$reconciledEvent");
        $this->db->exec("UPDATE accounts SET active=0 WHERE id=$archivedCounterpart");

        $outgoing = $this->statementRow($statementAccount, -26, 'Transfer to household account!', $this->accountNumber($outgoingCounterpart));
        $incoming = $this->statementRow($statementAccount, 27, 'Transfer from reserve account!', $this->accountNumber($incomingCounterpart));
        $differentAccount = $this->statementRow($statementAccount, -28, 'Different account history', $this->accountNumber($wrongAccountCounterpart));
        $oppositeDirection = $this->statementRow($statementAccount, -29, 'Opposite direction history', $this->accountNumber($oppositeDirectionCounterpart));
        $unavailable = $this->statementRow($statementAccount, -30, 'Unavailable transfer target', $this->accountNumber($archivedCounterpart));
        $reconciled = $this->statementRow($statementAccount, -31, 'Reconciled statement description!', $this->accountNumber($reconciledCounterpart));
        // The statement names no counterpart Account at all: the destination is the
        // owner's choice, so no earlier Transfer can fill it in.
        $unnamedCounterpart = $this->statementRow($statementAccount, -32, 'Transfer to household account!');

        self::assertSame('account:' . $outgoingCounterpart, $this->preselection($statementAccount, $outgoing));
        self::assertSame('account:' . $incomingCounterpart, $this->preselection($statementAccount, $incoming));
        self::assertNull($this->preselection($statementAccount, $differentAccount));
        self::assertNull($this->preselection($statementAccount, $oppositeDirection));
        self::assertNull($this->preselection($statementAccount, $unavailable));
        self::assertSame('account:' . $reconciledCounterpart, $this->preselection($statementAccount, $reconciled));
        self::assertNull($this->preselection($statementAccount, $unnamedCounterpart));
    }

    public function testWinningNoFinActionSuppressesAWeakerEnvelopeWithoutFallback(): void
    {
        $account = $this->account('Statement Account');
        $envelope = $this->createEnvelope('Mistaken Envelope');
        $currentDescription = str_repeat('a', 100);
        $weakerDescription = str_repeat('a', 80) . str_repeat('b', 20);

        $this->ordinaryTransaction($account, $envelope, $weakerDescription);
        $ignoredRow = $this->statementRow($account, -30, $currentDescription);
        statement_reconciliation_mark_no_fin_action($this->db, $ignoredRow);
        $currentRow = $this->statementRow($account, -31, $currentDescription);

        self::assertGreaterThan(
            statement_reconciliation_description_similarity($currentDescription, $weakerDescription),
            statement_reconciliation_description_similarity($currentDescription, $currentDescription)
        );
        self::assertNull($this->preselection($account, $currentRow));
    }

    public function testStrongerEnvelopeAndTransferStillWinAgainstWeakerNoFinActionHistory(): void
    {
        $account = $this->account('Statement Account');
        $counterpart = $this->account('Counterpart Account');
        $envelope = $this->createEnvelope('Correct Envelope');

        $envelopeDescription = str_repeat('c', 100);
        $weakerEnvelopeIgnore = str_repeat('c', 80) . str_repeat('d', 20);
        $ignoredEnvelopeRow = $this->statementRow($account, -32, $weakerEnvelopeIgnore);
        statement_reconciliation_mark_no_fin_action($this->db, $ignoredEnvelopeRow);
        $this->ordinaryTransaction($account, $envelope, $envelopeDescription);
        $currentEnvelopeRow = $this->statementRow($account, -33, $envelopeDescription);
        self::assertSame('envelope:' . $envelope, $this->preselection($account, $currentEnvelopeRow));

        $transferDescription = str_repeat('e', 100);
        $weakerTransferIgnore = str_repeat('e', 80) . str_repeat('f', 20);
        $ignoredTransferRow = $this->statementRow($account, -34, $weakerTransferIgnore);
        statement_reconciliation_mark_no_fin_action($this->db, $ignoredTransferRow);
        record_account_transfer($this->db, $account, $counterpart, 25, '2026-05-02', $transferDescription);
        $currentTransferRow = $this->statementRow($account, -35, $transferDescription, $this->accountNumber($counterpart));
        self::assertSame('account:' . $counterpart, $this->preselection($account, $currentTransferRow));
    }

    public function testNoFinActionUsesTheExistingRecencyTieBreakOnEqualScores(): void
    {
        $account = $this->account('Statement Account');
        $ignoreWinsEnvelope = $this->createEnvelope('Older Envelope');
        $envelopeWinsEnvelope = $this->createEnvelope('Newer Envelope');

        $ignoreWinsDescription = 'Equal score ignore winner';
        $olderTransaction = $this->ordinaryTransaction($account, $ignoreWinsEnvelope, $ignoreWinsDescription);
        $ignoreWinsRow = $this->statementRow($account, -36, $ignoreWinsDescription);
        $newerIgnore = statement_reconciliation_mark_no_fin_action($this->db, $ignoreWinsRow);
        $this->db->exec("UPDATE transactions SET created_at='2026-01-01 10:00:00' WHERE id=$olderTransaction");
        $this->db->exec("UPDATE statement_reconciliations SET created_at='2026-01-02 10:00:00' WHERE id=$newerIgnore");
        $currentIgnoreWinner = $this->statementRow($account, -37, $ignoreWinsDescription);
        self::assertNull($this->preselection($account, $currentIgnoreWinner));

        $envelopeWinsDescription = 'Equal score envelope winner';
        $envelopeWinsHistory = $this->ordinaryTransaction($account, $envelopeWinsEnvelope, $envelopeWinsDescription);
        $olderIgnoreRow = $this->statementRow($account, -38, $envelopeWinsDescription);
        $olderIgnore = statement_reconciliation_mark_no_fin_action($this->db, $olderIgnoreRow);
        $this->db->exec("UPDATE statement_reconciliations SET created_at='2026-01-01 10:00:00' WHERE id=$olderIgnore");
        $this->db->exec("UPDATE transactions SET created_at='2026-01-02 10:00:00' WHERE id=$envelopeWinsHistory");
        $currentEnvelopeWinner = $this->statementRow($account, -39, $envelopeWinsDescription);
        self::assertSame('envelope:' . $envelopeWinsEnvelope, $this->preselection($account, $currentEnvelopeWinner));
    }

    public function testMostlyIgnoredOccasionallyMiscategorizedDescriptionStaysNeutral(): void
    {
        $account = $this->account('Statement Account');
        $envelope = $this->createEnvelope('Occasionally Wrong Envelope');
        $description = 'Monthly cloud service subscription';
        $miscategorizedDescription = 'Monthly cloud serv1ce subscription';

        for ($index = 0; $index < 5; $index++) {
            $ignoredRow = $this->statementRow($account, -40 - $index, $description);
            statement_reconciliation_mark_no_fin_action($this->db, $ignoredRow);
        }
        $this->ordinaryTransaction($account, $envelope, $miscategorizedDescription);
        // The second identical record is deliberate history, not a new owner
        // action, so it is created with the duplicate confirmation.
        $this->ordinaryTransaction($account, $envelope, $miscategorizedDescription, true);
        $currentRow = $this->statementRow($account, -46, $description);

        self::assertGreaterThanOrEqual(
            STATEMENT_RECONCILIATION_PRESELECTION_THRESHOLD,
            statement_reconciliation_description_similarity($description, $miscategorizedDescription)
        );
        self::assertSame(5, (int)$this->db->query("SELECT COUNT(*) FROM statement_reconciliations WHERE disposition='no_fin_action'")->fetchColumn());
        self::assertNull($this->preselection($account, $currentRow));
    }

    public function testUnavailableEnvelopeTargetsRemainExcluded(): void
    {
        $account = $this->account('Statement Account');
        $envelope = $this->createEnvelope('Temporary Envelope');
        $transactionId = $this->ordinaryTransaction($account, $envelope, 'Unavailable envelope history');
        $this->db->exec("UPDATE transactions SET envelope_id=NULL WHERE id=$transactionId");
        $currentRow = $this->statementRow($account, -47, 'Unavailable envelope history!');

        self::assertNull($this->preselection($account, $currentRow));
    }

    public function testEqualBestScoresUseMostRecentOrdinaryRecord(): void
    {
        $account = $this->account('Statement Account');
        $olderEnvelope = $this->createEnvelope('Older Envelope');
        $newerEnvelope = $this->createEnvelope('Newer Envelope');
        $olderTransaction = $this->ordinaryTransaction($account, $olderEnvelope, 'Equal score merchant');
        $newerTransaction = $this->ordinaryTransaction($account, $newerEnvelope, 'Equal score merchant');
        $this->db->exec("UPDATE transactions SET created_at='2026-01-01 10:00:00' WHERE id=$olderTransaction");
        $this->db->exec("UPDATE transactions SET created_at='2026-01-02 10:00:00' WHERE id=$newerTransaction");

        $current = $this->statementRow($account, -42, 'Equal score merchant');
        self::assertSame('envelope:' . $newerEnvelope, $this->preselection($account, $current));
    }

    private function account(string $name): int
    {
        return save_account_definition($this->db, [
            'name' => $name,
            'account_number' => 'TEST-' . (++$this->sourceRow),
            'currency' => 'CURRENCY',
        ]);
    }

    private function ordinaryTransaction(int $accountId, int $envelopeId, string $note, bool $allowDuplicate = false): int
    {
        return save_transaction($this->db, [
            'envelope_id' => $envelopeId,
            'payment_account_id' => $accountId,
            'amount' => 10,
            'note' => $note,
            'spent_date' => '2026-05-01',
        ], null, false, false, $allowDuplicate);
    }

    private function statementRow(int $accountId, float $amount, string $description, string $counterpartAccountNumber = ''): int
    {
        $this->sourceRow++;
        $checksum = hash('sha256', $accountId . '|' . $this->sourceRow . '|' . $description);
        $insertImport = $this->db->prepare('INSERT INTO statement_imports
            (account_id,provider,original_filename,file_checksum,parser_version,row_count,valid_row_count,problem_count)
            VALUES (:account_id,\'test_provider\',:filename,:checksum,:parser_version,1,1,0)');
        $insertImport->execute([
            ':account_id' => $accountId,
            ':filename' => 'preselection-' . $this->sourceRow . '.xlsx',
            ':checksum' => $checksum,
            ':parser_version' => STATEMENT_PARSER_VERSION,
        ]);
        $rawFields = $counterpartAccountNumber === ''
            ? '{}'
            : (string)json_encode(['kontonummer' => $counterpartAccountNumber]);
        $insertRow = $this->db->prepare('INSERT INTO statement_rows
            (import_id,account_id,provider,source_row_number,booking_date,value_date,amount,currency,description_original,description_normalized,row_fingerprint,occurrence_index,parse_problem,raw_fields_json)
            VALUES (:import_id,:account_id,\'test_provider\',1,\'2026-06-01\',\'2026-06-01\',:amount,:currency,:description,:normalized,:fingerprint,0,\'\',:raw_fields)');
        $insertRow->execute([
            ':import_id' => (int)$this->db->lastInsertId(),
            ':account_id' => $accountId,
            ':amount' => $amount,
            ':currency' => $this->currency,
            ':description' => $description,
            ':normalized' => statement_normalize_token($description),
            ':fingerprint' => hash('sha256', $checksum . '|row'),
            ':raw_fields' => $rawFields,
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function accountNumber(int $accountId): string
    {
        return (string)$this->db->query("SELECT account_number FROM accounts WHERE id=$accountId")->fetchColumn();
    }

    private function reconcile(int $accountId, int $rowId, string $classification): void
    {
        $result = statement_reconciliation_batch_expenses($this->db, $accountId, [[
            'statement_row_id' => $rowId,
            'classification' => $classification,
        ]]);
        self::assertSame('saved', $result[0]['status']);
    }

    private function preselection(int $accountId, int $rowId): ?string
    {
        $rows = [statement_reconciliation_load_row($this->db, $rowId)];
        $preselections = statement_reconciliation_history_preselections($this->db, $accountId, $rows);
        return $preselections[0]['classification'] ?? null;
    }
}
