<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class StatementAutoProcessingTest extends DatabaseTestCase
{
    private string $currency;
    private string $helperCurrency;
    private int $sourceRow = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $context = currency_context($this->db);
        $this->currency = (string)$context['currency'];
        $this->helperCurrency = (string)$context['helper_currency'];
        ecb_reference_rate_set_fetcher(static function(): string {
            throw new RuntimeException('Unexpected ECB request; the test must install its deterministic fixture');
        });
    }

    protected function tearDown(): void
    {
        ecb_reference_rate_reset_test_fetcher();
        parent::tearDown();
    }

    public function testExactlyOneExistingTransactionIsLinkedWithoutDuplicate(): void
    {
        $account = $this->account('Payment Account', 'PAYMENT-EXACT');
        $envelope = $this->envelope('Known purpose');
        $transaction = save_transaction($this->db, [
            'envelope_id' => $envelope,
            'payment_account_id' => $account,
            'amount' => 125,
            'spent_date' => '2026-04-12',
            'note' => 'Exact purchase',
        ]);
        $row = $this->statementRow($account, '2026-04-12', -125, 'Exact purchase');

        $result = statement_reconciliation_auto_process_row($this->db, $row);

        self::assertSame('processed', $result['outcome']);
        self::assertSame('link_existing', $result['action']);
        self::assertSame($transaction, $result['target_id']);
        self::assertSame(1, $this->tableCount('transactions'));
        self::assertSame(1, $this->tableCount('statement_reconciliations'));
    }

    public function testExactlyOneExistingTransferIsLinkedWithoutDuplicate(): void
    {
        $source = $this->account('Source Account', 'TRANSFER-SOURCE');
        $destination = $this->account('Destination Account', 'TRANSFER-DESTINATION');
        $event = record_account_transfer($this->db, $source, $destination, 310, '2026-04-13', 'Owned transfer');
        $row = $this->statementRow($source, '2026-04-13', -310, 'Owned transfer');

        $result = statement_reconciliation_auto_process_row($this->db, $row);

        self::assertSame('processed', $result['outcome']);
        self::assertSame($event, $result['target_id']);
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM account_events WHERE event_type='transfer'")->fetchColumn());
        self::assertSame(2, $this->tableCount('account_movements'));
    }

    public function testExactPriorHistoryCreatesExpenseWithKnownEnvelope(): void
    {
        $account = $this->account('History Account', 'HISTORY-ACCOUNT');
        $envelope = $this->envelope('History purpose');
        $priorRow = $this->statementRow($account, '2026-03-01', -40, 'Exact Merchant');
        $priorTransaction = save_transaction($this->db, [
            'envelope_id' => $envelope,
            'payment_account_id' => $account,
            'amount' => 40,
            'spent_date' => '2026-03-01',
            'note' => 'Exact Merchant',
        ]);
        statement_reconciliation_link_insert($this->db, $priorRow, $priorTransaction);
        $row = $this->statementRow($account, '2026-04-01', -55, 'Exact Merchant');

        $result = statement_reconciliation_auto_process_row($this->db, $row);
        $created = $this->db->query('SELECT * FROM transactions WHERE id=' . (int)$result['target_id'])->fetch();

        self::assertSame('create_expense', $result['action']);
        self::assertSame($envelope, (int)$created['envelope_id']);
        self::assertSame($account, (int)$created['payment_account_id']);
        self::assertSame(55.0, (float)$created['amount']);
        self::assertSame('expense', $created['kind']);
    }

    public function testUnknownExpenseEnvelopeRemainsOnlyInExceptionQueue(): void
    {
        $account = $this->account('Exception Account', 'EXCEPTION-ACCOUNT');
        $row = $this->statementRow($account, '2026-04-02', -61, 'Unknown Merchant');

        $result = statement_reconciliation_auto_process_row($this->db, $row);
        $state = statement_reconciliation_load_for_account($this->db, $account);

        self::assertSame('exception', $result['outcome']);
        self::assertSame('missing_envelope', $result['code']);
        self::assertSame(0, $this->tableCount('transactions'));
        self::assertSame(0, $this->tableCount('statement_reconciliations'));
        self::assertCount(1, $state['unresolved']);
        self::assertSame('expense_envelope', $state['unresolved'][0]['batch_action']);
    }

    public function testCreditWithoutProfileBackedClassificationIsNotAutoProcessedAsIncome(): void
    {
        $account = $this->account('Income Account', 'INCOME-ACCOUNT');
        // A row that is not profile-backed carries no authoritative Income
        // classification, so it stays an unresolved exception for the owner.
        $row = $this->statementRow($account, '2026-04-03', 900, 'Salary credit', [
            'provider' => 'evidence:legacy-alpha',
            'provider_row_type' => 'Salary',
            'raw_fields' => ['Hovedkategori' => 'Indtægter'],
        ]);

        $result = statement_reconciliation_auto_process_row($this->db, $row);

        self::assertSame('exception', $result['outcome']);
        self::assertSame('credit_requires_classification', $result['code']);
        self::assertSame(0, $this->tableCount('account_events'));
        self::assertSame(0, $this->tableCount('transactions'));
        self::assertSame(0, $this->tableCount('statement_reconciliations'));
    }

    public function testProfileBackedIncomeClassificationCreatesIncomeEvent(): void
    {
        $account = $this->account('Classified Income Account', 'CLASSIFIED-INCOME');
        $profileId = statement_import_profile_create($this->db, [
            'name' => 'Classified Evidence Profile',
            'evidence_namespace' => 'evidence:classified-alpha',
            'schema_version' => 1,
            'definition' => [
                'signature' => [
                    'container' => 'ooxml',
                    'sheet' => 0,
                    'header' => [
                        ['column' => 0, 'key' => 'date'],
                        ['column' => 1, 'key' => 'description'],
                        ['column' => 2, 'key' => 'amount'],
                    ],
                    'markers' => [],
                ],
                'parser' => [
                    'booking_date' => ['sources' => ['date']],
                    'value_date' => null,
                    'amount' => ['mode' => 'signed', 'column' => 'amount'],
                    'currency' => ['mode' => 'constant', 'value' => 'DKK'],
                    'description' => ['column' => 'description', 'counterparty_by_sign' => null],
                    'running_balance' => null,
                    'reference' => null,
                    'row_type' => null,
                    'account_number' => ['mode' => 'none'],
                    'classification' => null,
                ],
            ],
        ]);
        $row = $this->statementRow($account, '2026-04-03', 900, 'Salary credit', [
            'provider' => 'evidence:classified-alpha',
            'import_profile_id' => $profileId,
            'classification' => 'income',
            'provider_row_type' => 'Salary',
        ]);

        $result = statement_reconciliation_auto_process_row($this->db, $row);
        $event = $this->db->query('SELECT * FROM account_events WHERE id=' . (int)$result['target_id'])->fetch();

        self::assertSame('create_income', $result['action']);
        self::assertSame('income', $event['event_type']);
        self::assertSame($account, (int)$event['destination_account_id']);
        self::assertSame(900.0, (float)$event['amount']);
        self::assertSame(0, $this->tableCount('transactions'));
        self::assertSame(1, $this->tableCount('statement_reconciliations'));
    }

    public function testExactCounterpartIdentifierCreatesInternalTransfer(): void
    {
        $source = $this->account('Exact Source', 'EXACT-SOURCE-ID');
        $destinationNumber = 'EXACT-DESTINATION-ID';
        $destination = $this->account('Exact Destination', $destinationNumber);
        $row = $this->statementRow($source, '2026-04-04', -275, 'Move owned money', [
            'raw_fields' => ['counterparty_identifier' => $destinationNumber],
        ]);

        $result = statement_reconciliation_auto_process_row($this->db, $row);
        $event = $this->db->query('SELECT * FROM account_events WHERE id=' . (int)$result['target_id'])->fetch();

        self::assertSame('create_transfer', $result['action']);
        self::assertSame('transfer', $event['event_type']);
        self::assertSame($source, (int)$event['source_account_id']);
        self::assertSame($destination, (int)$event['destination_account_id']);
        self::assertSame(0, $this->tableCount('transactions'));
    }

    public function testMultipleCompatibleMatchesRemainAmbiguousException(): void
    {
        $account = $this->account('Ambiguous Match Account', 'AMBIGUOUS-MATCH');
        $envelopeA = $this->envelope('Purpose A');
        $envelopeB = $this->envelope('Purpose B');
        foreach ([[$envelopeA, '2026-04-05'], [$envelopeB, '2026-04-06']] as [$envelope, $date]) {
            save_transaction($this->db, ['envelope_id' => $envelope, 'payment_account_id' => $account, 'amount' => 88, 'spent_date' => $date]);
        }
        $row = $this->statementRow($account, '2026-04-05', -88, 'Ambiguous payment');

        $result = statement_reconciliation_auto_process_row($this->db, $row);

        self::assertSame('exception', $result['outcome']);
        self::assertSame('ambiguous_match', $result['code']);
        self::assertCount(2, $result['candidates']);
        self::assertSame(2, $this->tableCount('transactions'));
        self::assertSame(0, $this->tableCount('statement_reconciliations'));
    }

    public function testMultipleExactCounterpartIdentifiersRemainException(): void
    {
        $statementAccount = $this->account('Ambiguous Account', 'AMBIGUOUS-STATEMENT');
        $firstNumber = 'COUNTERPART-FIRST';
        $secondNumber = 'COUNTERPART-SECOND';
        $this->account('First Counterpart', $firstNumber);
        $this->account('Second Counterpart', $secondNumber);
        $row = $this->statementRow($statementAccount, '2026-04-07', -99, 'Ambiguous transfer', [
            'raw_fields' => ['source_identifier' => $firstNumber, 'destination_identifier' => $secondNumber],
        ]);

        $result = statement_reconciliation_auto_process_row($this->db, $row);

        self::assertSame('exception', $result['outcome']);
        self::assertSame('ambiguous_account', $result['code']);
        self::assertSame(0, $this->tableCount('account_events'));
        self::assertSame(0, $this->tableCount('statement_reconciliations'));
    }

    public function testBatchEnvelopeAssignmentsUseAuthoritativeExpensePathAndReportPerRowResults(): void
    {
        $account = $this->account('Batch Account', 'BATCH-ACCOUNT');
        $envelopeA = $this->envelope('Batch purpose A');
        $envelopeB = $this->envelope('Batch purpose B');
        $rowA = $this->statementRow($account, '2026-04-08', -21, 'Batch first');
        $rowB = $this->statementRow($account, '2026-04-09', -22, 'Batch second');
        $rowInvalid = $this->statementRow($account, '2026-04-10', -23, 'Batch invalid');

        $results = statement_reconciliation_batch_expenses($this->db, $account, [
            ['statement_row_id' => $rowA, 'classification' => 'envelope:' . $envelopeA],
            ['statement_row_id' => $rowB, 'classification' => 'envelope:' . $envelopeB],
            ['statement_row_id' => $rowInvalid, 'classification' => 'envelope:999999'],
        ]);

        self::assertSame(['saved', 'saved', 'error'], array_column($results, 'status'));
        self::assertSame(2, $this->tableCount('transactions'));
        self::assertSame(2, $this->tableCount('statement_reconciliations'));
        self::assertSame(2, $this->tableCount('envelope_movements'));
        self::assertSame(2, (int)$this->db->query("SELECT COUNT(*) FROM account_events WHERE event_type='expense'")->fetchColumn());
        self::assertSame(2, $this->tableCount('account_movements'));
    }

    public function testRerunIsIdempotentAndAlreadyReconciledBatchRowIsSkipped(): void
    {
        $account = $this->account('Idempotent Account', 'IDEMPOTENT-ACCOUNT');
        $envelope = $this->envelope('Idempotent purpose');
        $row = $this->statementRow($account, '2026-04-11', -45, 'Idempotent expense');
        statement_reconciliation_batch_expenses($this->db, $account, [['statement_row_id' => $row, 'classification' => 'envelope:' . $envelope]]);
        $before = [$this->tableCount('transactions'), $this->tableCount('statement_reconciliations'), $this->tableCount('account_events')];

        $auto = statement_reconciliation_auto_process_row($this->db, $row);
        $batch = statement_reconciliation_batch_expenses($this->db, $account, [['statement_row_id' => $row, 'classification' => 'envelope:' . $envelope]]);

        self::assertSame('already_reconciled', $auto['outcome']);
        self::assertSame('already_reconciled', $batch[0]['status']);
        self::assertSame($before, [$this->tableCount('transactions'), $this->tableCount('statement_reconciliations'), $this->tableCount('account_events')]);
    }

    public function testHistoricalCrossCurrencyAutoExpenseUsesWeeklyEcbFallbackAndPreservesEvidence(): void
    {
        $account = $this->account('Historical Account', 'HISTORICAL-ACCOUNT');
        $envelope = $this->envelope('Historical purpose');
        $date = '2025-04-17';
        $rates = '<Cube currency="' . htmlspecialchars($this->currency, ENT_QUOTES) . '" rate="7.5"/>'
            . '<Cube currency="' . htmlspecialchars($this->helperCurrency, ENT_QUOTES) . '" rate="2.0"/>';
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">'
            . '<Cube><Cube time="' . $date . '">' . $rates . '</Cube></Cube></gesmes:Envelope>';
        ecb_reference_rate_set_fetcher(static fn(): string => $xml);

        $priorRow = $this->statementRow($account, '2025-03-17', -10, 'Historical Merchant', ['currency' => $this->helperCurrency]);
        $priorTransaction = save_transaction($this->db, [
            'envelope_id' => $envelope,
            'payment_account_id' => $account,
            'amount' => 1,
            'spent_date' => '2025-03-17',
            'note' => 'Historical Merchant',
        ], null, false, true);
        statement_reconciliation_link_insert($this->db, $priorRow, $priorTransaction);
        $row = $this->statementRow($account, $date, -10, 'Historical Merchant', ['currency' => $this->helperCurrency]);
        $evidenceBefore = $this->db->query("SELECT * FROM statement_rows WHERE id=$row")->fetch();

        $result = statement_reconciliation_auto_process_row($this->db, $row);
        $created = $this->db->query('SELECT amount FROM transactions WHERE id=' . (int)$result['target_id'])->fetchColumn();
        $evidenceAfter = $this->db->query("SELECT * FROM statement_rows WHERE id=$row")->fetch();
        $currencyReference = historical_reference_rate_for_currency($this->db, $this->currency, $date);
        $helperReference = historical_reference_rate_for_currency($this->db, $this->helperCurrency, $date);
        $expected = round(10 * ((float)$currencyReference['rate'] / (float)$helperReference['rate']), 2);

        self::assertSame('create_expense', $result['action']);
        self::assertEqualsWithDelta($expected, (float)$created, 0.001);
        self::assertSame($evidenceBefore, $evidenceAfter);
        self::assertSame($this->helperCurrency, $evidenceAfter['currency']);
        self::assertSame(-10.0, (float)$evidenceAfter['amount']);
    }

    public function testInvalidEvidenceRowStaysInExceptionQueueWithInvalidEvidenceCode(): void
    {
        $account = $this->account('Invalid Evidence Account', 'INVALID-EVIDENCE');
        $row = $this->statementRow($account, '2026-05-12', -25.0, 'Bad row', [
            'parse_problem' => 'Invalid amount',
            'raw_fields' => ['Tekst' => 'Bad row'],
        ]);
        $this->db->prepare("UPDATE statement_rows SET parse_problem = 'Invalid amount' WHERE id = :id")->execute([':id' => $row]);

        $assessment = statement_reconciliation_assess_row($this->db, $row);
        self::assertSame('exception', $assessment['outcome']);
        self::assertSame('invalid_evidence', $assessment['code']);
        self::assertSame(0, $this->tableCount('transactions'));
        self::assertSame(0, $this->tableCount('account_events'));
        self::assertSame(0, $this->tableCount('statement_reconciliations'));
    }

    public function testUnsupportedCurrencyRowStaysInExceptionQueueWithUnsupportedCurrencyCode(): void
    {
        $account = $this->account('Unsupported Currency Account', 'UNSUPPORTED-CURRENCY');
        $row = $this->statementRow($account, '2026-05-13', -75.0, 'Foreign bank', [
            'currency' => 'JPY',
            'raw_fields' => ['Tekst' => 'Foreign bank'],
        ]);

        $assessment = statement_reconciliation_assess_row($this->db, $row);
        self::assertSame('exception', $assessment['outcome']);
        self::assertSame('unsupported_currency', $assessment['code']);
        self::assertSame(0, $this->tableCount('transactions'));
        self::assertSame(0, $this->tableCount('account_events'));
        self::assertSame(0, $this->tableCount('statement_reconciliations'));
    }

    public function testAmbiguousPriorEnvelopeHistoryRequiresEnvelopeSelection(): void
    {
        $account = $this->account('Prior Ambiguity Account', 'PRIOR-AMBIGUITY');
        foreach ([$this->envelope('Prior A'), $this->envelope('Prior B')] as $index => $envelope) {
            $priorRow = $this->statementRow($account, '2026-03-0' . ($index + 1), -30 - $index, 'Repeated Merchant');
            $transaction = save_transaction($this->db, [
                'envelope_id' => $envelope,
                'payment_account_id' => $account,
                'amount' => 30 + $index,
                'spent_date' => '2026-03-0' . ($index + 1),
                'note' => 'Repeated Merchant',
            ]);
            statement_reconciliation_link_insert($this->db, $priorRow, $transaction);
        }
        $row = $this->statementRow($account, '2026-04-12', -32, 'Repeated Merchant');

        $assessment = statement_reconciliation_assess_row($this->db, $row);

        self::assertSame('exception', $assessment['outcome']);
        self::assertSame('ambiguous_envelope', $assessment['code']);
        self::assertSame('expense_envelope', $assessment['batch_action']);
    }

    private function account(string $name, string $number): int
    {
        return save_account_definition($this->db, [
            'name' => $name,
            'account_number' => $number,
            'currency' => 'CURRENCY',
        ]);
    }

    private function envelope(string $name): int
    {
        return save_envelope($this->db, ['name' => $name, 'role' => 'daily', 'currency' => 'CURRENCY']);
    }

    private function statementRow(int $accountId, string $date, float $amount, string $description, array $options = []): int
    {
        $this->sourceRow++;
        $provider = (string)($options['provider'] ?? 'test_provider');
        $currency = (string)($options['currency'] ?? $this->currency);
        $rawFields = (array)($options['raw_fields'] ?? []);
        $checksum = hash('sha256', $accountId . '|' . $this->sourceRow . '|' . $description);
        $insertImport = $this->db->prepare('INSERT INTO statement_imports
            (account_id,provider,original_filename,file_checksum,parser_version,row_count,valid_row_count,problem_count,import_profile_id)
            VALUES (:account_id,:provider,:filename,:checksum,:parser_version,1,1,0,:profile_id)');
        $insertImport->execute([
            ':account_id' => $accountId,
            ':provider' => $provider,
            ':filename' => 'fixture-' . $this->sourceRow . '.xlsx',
            ':checksum' => $checksum,
            ':parser_version' => STATEMENT_PARSER_VERSION,
            ':profile_id' => $options['import_profile_id'] ?? null,
        ]);
        $importId = (int)$this->db->lastInsertId();
        $insert = $this->db->prepare('INSERT INTO statement_rows
            (import_id,account_id,provider,source_row_number,booking_date,value_date,amount,currency,description_original,description_normalized,provider_row_type,row_fingerprint,occurrence_index,parse_problem,raw_fields_json,classification)
            VALUES (:import_id,:account_id,:provider,:source_row,:booking_date,:value_date,:amount,:currency,:description,:normalized,:row_type,:fingerprint,0,\'\',:raw,:classification)');
        $insert->execute([
            ':import_id' => $importId,
            ':account_id' => $accountId,
            ':provider' => $provider,
            ':source_row' => $this->sourceRow,
            ':booking_date' => $date,
            ':value_date' => $date,
            ':amount' => $amount,
            ':currency' => $currency,
            ':description' => $description,
            ':normalized' => statement_normalize_token($description),
            ':row_type' => (string)($options['provider_row_type'] ?? ''),
            ':fingerprint' => hash('sha256', $checksum . '|row'),
            ':raw' => json_encode($rawFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':classification' => $options['classification'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function tableCount(string $table): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }
}
