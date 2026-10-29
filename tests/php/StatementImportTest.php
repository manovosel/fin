<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';
require_once __DIR__ . '/Fixtures/SyntheticStatementGenerator.php';

use Tests\Php\Fixtures\SyntheticStatementGenerator;

final class StatementImportTest extends DatabaseTestCase
{
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionNeutralImportProfiles();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) @unlink($file);
        parent::tearDown();
    }


    public function testEvidenceCurrencyIsIndependentOfNonDkkFinConfiguration(): void
    {
        $db = self::newDatabase();
        migrate($db, [
            ...app_config(),
            'currency' => 'USD',
            'helper_currency' => 'SEK',
        ]);
        self::seedNeutralImportProfiles($db);
        $accountId = save_account_definition($db, [
            'name' => 'Test Bank evidence',
            'account_number' => '53907547034',
            'type' => 'asset',
            'currency' => 'CURRENCY',
        ]);
        $contents = $this->workbook([
            ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn', 'Hovedkategori', 'Kategori', 'Kommentar'],
            ['46237', 'SOURCE DKK', '-10.00', '990.00', '', '53907547034', 'Privatkonto', '', '', ''],
        ]);

        $parsed = $this->parseWithProfile($db, 'signed-statement.xlsx', $contents);
        self::assertSame(['currency' => 'USD', 'helper_currency' => 'SEK'], currency_context($db));
        self::assertSame('DKK', $parsed['rows'][0]['currency']);

        import_statement_evidence($db, $accountId, 'signed-statement.xlsx', $contents);
        self::assertSame('DKK', $db->query('SELECT currency FROM statement_rows')->fetchColumn());
    }

    public function testFeeFormatNormalizesDatesAmountCurrencyAndDescription(): void
    {
        $rows = [
            ['Type', 'Produkt', 'Startdato', 'Fuldførelsesdato', 'Beskrivelse', 'Beløb', 'Gebyr', 'Valuta', 'State', 'Saldo'],
            ['Kortbetaling', 'Nuværende', '46237.25', '46238.5', 'GROCERY SHOP', '-125.50', '0', 'DKK', 'GENNEMFØRT', '874.50'],
        ];
        $parsed = $this->parseWithProfile($this->db, 'fee-statement.xlsx', $this->workbook($rows));
        $row = $parsed['rows'][0];

        self::assertSame('profile:test-wallet', (string)$parsed['evidence_namespace']);
        self::assertSame('2026-08-04', $row['booking_date']);
        self::assertSame('2026-08-03', $row['value_date']);
        self::assertSame(-125.5, $row['amount']);
        self::assertSame('DKK', $row['currency']);
        self::assertSame('GROCERY SHOP', $row['description_original']);
        self::assertSame('Kortbetaling', $row['provider_row_type']);
        self::assertSame('GENNEMFØRT', $row['raw_fields']['state']);
        self::assertSame('-125.50', $row['raw_fields']['beløb']);
        self::assertSame('0', $row['raw_fields']['gebyr']);
    }

    public function testMixedEscapedMojibakeDescriptionRemainsValidUtf8(): void
    {
        $rows = [
            ['Type', 'Produkt', 'Startdato', 'Fuldførelsesdato', 'Beskrivelse', 'Beløb', 'Gebyr', 'Valuta', 'State', 'Saldo'],
            ['Kortbetaling', 'Nuværende', '45689', '45689', 'Ã_x008e_le-de-France MobilitÃ©s', '-25.00', '0', 'DKK', 'GENNEMFØRT', '975.00'],
        ];

        $parsed = $this->parseWithProfile($this->db, 'fee-statement.xlsx', $this->workbook($rows));
        $description = $parsed['rows'][0]['description_original'];

        self::assertSame('Île-de-France Mobilités', $description);
        self::assertTrue(statement_text_is_valid_utf8($description));
        self::assertNotFalse(json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        self::assertSame(
            'Île-de-France Mobilités',
            statement_repair_text("\xCEle-de-France Mobilit\xC3\xA9s"),
            'Legacy evidence with the isolated Windows-1252 Î byte must be recoverable'
        );
    }

    public function testFeeEffectiveAmountIncludesProvenSeparateFeeAndMatchesBalanceDelta(): void
    {
        $rows = [
            ['Type', 'Produkt', 'Startdato', 'Fuldførelsesdato', 'Beskrivelse', 'Beløb', 'Gebyr', 'Valuta', 'State', 'Saldo'],
            ['Overførsel', 'Nuværende', '46023', '46023', 'Opening evidence', '1000.00', '0', 'DKK', 'GENNEMFØRT', '1000.00'],
            ['Kortbetaling', 'Nuværende', '46024', '46024', 'Ordinary no fee', '-100.00', '0', 'DKK', 'GENNEMFØRT', '900.00'],
            ['Kortbetaling', 'Nuværende', '46025', '46025', 'Outgoing with fee', '-90.34', '0.90', 'DKK', 'GENNEMFØRT', '808.76'],
            ['Veksling', 'Nuværende', '46026', '46026', 'Incoming with fee', '486.85', '4.87', 'DKK', 'GENNEMFØRT', '1290.74'],
            ['Opkræv', 'Nuværende', '46027', '46027', 'Fee only', '0.00', '74.99', 'DKK', 'GENNEMFØRT', '1215.75'],
        ];
        $parsed = $this->parseWithProfile($this->db, 'fee-statement.xlsx', $this->workbook($rows));
        $evidence = $parsed['rows'];

        self::assertSame([1000.0, -100.0, -91.24, 481.98, -74.99], array_column($evidence, 'amount'));
        for ($index = 1; $index < count($evidence); $index++) {
            $balanceDelta = round((float)$evidence[$index]['running_balance'] - (float)$evidence[$index - 1]['running_balance'], 2);
            self::assertSame((float)$evidence[$index]['amount'], $balanceDelta);
        }
        self::assertSame('-90.34', $evidence[2]['raw_fields']['beløb']);
        self::assertSame('0.90', $evidence[2]['raw_fields']['gebyr']);
        self::assertSame('0.00', $evidence[4]['raw_fields']['beløb']);
        self::assertSame('74.99', $evidence[4]['raw_fields']['gebyr']);
        self::assertSame('DKK', $evidence[4]['currency']);
    }


    public function testEffectiveFeeAmountFlowsThroughExpenseRefundTransferAndHelperEvidenceCreate(): void
    {
        $monthId = $this->createMonth(2026, 1);
        $envelopeId = $this->createEnvelope('Fee evidence');
        $statementAccount = $this->account('Statement Account');
        $otherAccount = $this->account('Other Account');
        $rows = [
            ['Type', 'Produkt', 'Startdato', 'Fuldførelsesdato', 'Beskrivelse', 'Beløb', 'Gebyr', 'Valuta', 'State', 'Saldo'],
            ['Kortbetaling', 'Nuværende', '46023', '46023', 'Expense fee', '-90.34', '0.90', 'DKK', 'GENNEMFØRT', '908.76'],
            ['Veksling', 'Nuværende', '46024', '46024', 'Refund fee', '100.00', '1.00', 'DKK', 'GENNEMFØRT', '1007.76'],
            ['Overførsel', 'Nuværende', '46025', '46025', 'Transfer evidence', '-10.00', '1.00', 'EUR', 'GENNEMFØRT', '996.76'],
            ['Opkræv', 'Nuværende', '46026', '46026', 'Helper fee only', '0.00', '10.00', 'EUR', 'GENNEMFØRT', '986.76'],
        ];
        $result = import_statement_evidence($this->db, $statementAccount, 'reconciliation-fees.xlsx', $this->workbook($rows));
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM account_events')->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM envelope_movements')->fetchColumn());
        self::assertSame(4, $result['preview']['new_rows']);

        $rowIds = $this->db->query('SELECT source_row_number,id FROM statement_rows ORDER BY source_row_number')->fetchAll(PDO::FETCH_KEY_PAIR);
        $expenseId = save_transaction($this->db, ['month_id' => $monthId, 'envelope_id' => $envelopeId, 'payment_account_id' => $statementAccount, 'amount' => 91.24, 'spent_date' => '2026-01-01', 'kind' => 'expense']);
        $refundId = save_transaction($this->db, ['month_id' => $monthId, 'envelope_id' => $envelopeId, 'payment_account_id' => $statementAccount, 'amount' => 99, 'spent_date' => '2026-01-02', 'kind' => 'refund']);
        $transferId = record_account_transfer($this->db, $statementAccount, $otherAccount, 82.06, '2026-01-03', 'Fee evidence transfer candidate');

        $expenseCandidates = find_reconciliation_candidates($this->db, (int)$rowIds[2])['candidates'];
        $refundCandidates = find_reconciliation_candidates($this->db, (int)$rowIds[3])['candidates'];
        $transferCandidates = find_reconciliation_candidates($this->db, (int)$rowIds[4])['candidates'];
        self::assertContains($expenseId, array_column($expenseCandidates, 'transaction_id'));
        self::assertContains($refundId, array_column($refundCandidates, 'transaction_id'));
        self::assertContains($transferId, array_column($transferCandidates, 'account_event_id'));

        $createdId = statement_reconciliation_create_expense($this->db, (int)$rowIds[5], [
            'envelope_id' => $envelopeId,
            'payment_account_id' => $otherAccount,
            'amount' => 1,
            'currency' => 'DKK',
            'spent_date' => '2026-01-04',
            'note' => 'Created from effective fee evidence',
        ]);
        $created = $this->db->query('SELECT * FROM transactions WHERE id=' . $createdId)->fetch();
        self::assertSame('expense', $created['kind']);
        self::assertSame(74.6, (float)$created['amount']);
        self::assertSame($statementAccount, (int)$created['payment_account_id']);
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM statement_reconciliations WHERE statement_row_id=' . (int)$rowIds[5] . ' AND transaction_id=' . $createdId)->fetchColumn());
    }

    public function testDebitCreditOoxmlXlsFormatNormalizesSignedRowsAndReference(): void
    {
        // The debit/credit export is an OOXML workbook carried in a .xls file
        // with a pre-header Account-number row.
        $path = SyntheticStatementGenerator::build(
            SyntheticStatementGenerator::FORMAT_DEBIT_CREDIT,
            sys_get_temp_dir(),
            'debit-credit-statement',
            [
                ['booking_date' => '2026-04-10', 'amount' => -23.0, 'description' => 'Synthetic payment', 'currency' => 'EUR', 'direction' => 'Isplata', 'counterparty' => 'Synthetic Recipient'],
                ['booking_date' => '2026-04-15', 'amount' => 50.0, 'description' => 'Synthetic refund', 'currency' => 'EUR', 'direction' => 'Uplata', 'counterparty' => 'Synthetic Payer'],
            ],
            ['account_number' => '0000123456', 'sheet_currency' => 'EUR']
        );
        $this->temporaryFiles[] = $path;
        $filename = basename($path);
        self::assertSame('debit-credit-statement.xls', $filename);

        $parsed = $this->parseWithProfile($this->db, $filename, (string)file_get_contents($path));

        self::assertSame('profile:test-foreign-bank', (string)$parsed['evidence_namespace']);
        self::assertSame('0000123456', (string)$parsed['file_account_number']);
        self::assertSame(-23.0, $parsed['rows'][0]['amount']);
        self::assertSame('EUR', $parsed['rows'][0]['currency']);
        self::assertStringContainsString('Synthetic payment', $parsed['rows'][0]['description_original']);
        self::assertStringContainsString('Synthetic Recipient', $parsed['rows'][0]['description_original']);
        self::assertNotNull($parsed['rows'][0]['provider_reference']);
        self::assertSame(50.0, $parsed['rows'][1]['amount']);
        self::assertSame('EUR', $parsed['rows'][1]['currency']);
    }

    public function testImportCreatesOnlyDisposableStatementEvidence(): void
    {
        [$accountId, $before] = $this->financialFixture();
        $contents = $this->workbook($this->signedRows([
            ['46237', 'Evidence only', '-75.25', '900.00'],
        ]));

        $reportBefore = get_report_data($this->db, '2026-08', '2026-08');
        $recommendationsBefore = build_funding_recommendations($this->db, 2026, 9, '2026-09-01');
        $result = import_statement_evidence($this->db, $accountId, 'Privatkonto.xlsx', $contents);

        self::assertSame(1, $result['preview']['new_rows']);
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM statement_imports')->fetchColumn());
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM statement_rows')->fetchColumn());
        self::assertSame($before, $this->financialSnapshot());
        self::assertSame($reportBefore, get_report_data($this->db, '2026-08', '2026-08'));
        self::assertSame($recommendationsBefore, build_funding_recommendations($this->db, 2026, 9, '2026-09-01'));

        $this->db->exec('DELETE FROM statement_imports');
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM statement_rows')->fetchColumn());
        self::assertSame($before, $this->financialSnapshot());
    }

    public function testSameFileAndOverlappingRangesAreIdempotent(): void
    {
        $accountId = $this->account();
        $first = $this->workbook($this->signedRows([
            ['46237', 'A', '-10', '990'],
            ['46238', 'B', '-20', '970'],
        ]));
        $overlap = $this->workbook($this->signedRows([
            ['46238', 'B', '-20', '970'],
            ['46239', 'C', '-30', '940'],
        ]));

        $initial = import_statement_evidence($this->db, $accountId, 'first.xlsx', $first);
        $duplicate = import_statement_evidence($this->db, $accountId, 'first.xlsx', $first);
        $preview = preview_statement_import($this->db, $accountId, 'overlap.xlsx', $overlap);
        $second = import_statement_evidence($this->db, $accountId, 'overlap.xlsx', $overlap);

        self::assertSame(2, $initial['preview']['new_rows']);
        self::assertTrue($duplicate['duplicate_file']);
        self::assertSame(1, $preview['known_rows']);
        self::assertSame(1, $preview['new_rows']);
        self::assertSame(1, $second['preview']['new_rows']);
        self::assertSame(2, (int)$this->db->query('SELECT COUNT(*) FROM statement_imports')->fetchColumn());
        self::assertSame(3, (int)$this->db->query('SELECT COUNT(*) FROM statement_rows')->fetchColumn());
    }

    public function testRepeatedIdenticalRowsRemainSeparate(): void
    {
        $accountId = $this->account();
        $contents = $this->workbook($this->signedRows([
            ['46237', 'Same merchant', '-50', '950'],
            ['46237', 'Same merchant', '-50', '900'],
        ]));
        import_statement_evidence($this->db, $accountId, 'repeated.xlsx', $contents);

        $rows = $this->db->query('SELECT occurrence_index,row_fingerprint FROM statement_rows ORDER BY occurrence_index')->fetchAll();
        self::assertSame([0, 1], array_map('intval', array_column($rows, 'occurrence_index')));
        self::assertNotSame($rows[0]['row_fingerprint'], $rows[1]['row_fingerprint']);
    }

    public function testMalformedRowsRemainProblemEvidenceAndCreateNoFinancialRecord(): void
    {
        [$accountId, $before] = $this->financialFixture();
        $contents = $this->workbook($this->signedRows([
            ['not-a-date', '', 'not-an-amount', ''],
        ]));
        $result = import_statement_evidence($this->db, $accountId, 'malformed.xlsx', $contents);

        self::assertSame(1, $result['preview']['parse_problem_count']);
        self::assertStringContainsString('Invalid booking date', (string)$this->db->query('SELECT parse_problem FROM statement_rows')->fetchColumn());
        self::assertSame($before, $this->financialSnapshot());
    }

    public function testImportAutomaticallyLinksExactlyOneExistingTransaction(): void
    {
        $account = $this->account('Auto import account');
        $envelope = $this->createEnvelope('Auto import purpose');
        $transaction = save_transaction($this->db, [
            'envelope_id' => $envelope,
            'payment_account_id' => $account,
            'amount' => 125,
            'spent_date' => '2026-08-03',
            'note' => 'Auto import exact match',
        ]);
        $contents = $this->workbook($this->signedRows([
            ['46237', 'Auto import exact match', '-125', '875'],
        ]));

        $result = import_statement_evidence($this->db, $account, 'auto-import.xlsx', $contents);

        self::assertSame('processed', $result['auto_processing'][0]['outcome']);
        self::assertSame('link_existing', $result['auto_processing'][0]['action']);
        self::assertSame($transaction, $result['auto_processing'][0]['target_id']);
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM statement_reconciliations')->fetchColumn());
    }



    /**
     * Parse a workbook through the generic Import Profile path. The saved
     * neutral profiles decide which structure applies; no hardcoded format
     * detector is involved.
     */
    private function parseWithProfile(PDO $db, string $filename, string $contents): array
    {
        return statement_parse_file_with_profile($db, $filename, $contents);
    }

    private function account(string $name = 'Statement account', string $currency = 'CURRENCY'): int
    {
        return save_account_definition($this->db, ['name' => $name, 'account_number' => 'TEST-' . strtoupper($name), 'currency' => $currency]);
    }

    private function financialFixture(): array
    {
        $monthId = $this->createMonth(2026, 8);
        $this->createMonth(2026, 9);
        $envelopeId = $this->createEnvelope('Envelope A');
        $accountId = $this->account('Test Bank');
        save_transaction($this->db, ['month_id' => $monthId, 'envelope_id' => $envelopeId, 'payment_account_id' => $accountId, 'amount' => 125, 'spent_date' => '2026-08-05']);
        return [$accountId, $this->financialSnapshot()];
    }

    private function financialSnapshot(): array
    {
        $snapshot = [];
        foreach (['months', 'entries', 'transactions', 'accounts', 'account_events', 'account_movements', 'envelopes', 'envelope_movements', 'envelope_transfers', 'recommendation_exclusions', 'envelope_recommendation_settings'] as $table) {
            $snapshot[$table] = $this->db->query("SELECT * FROM $table ORDER BY 1")->fetchAll();
        }
        return $snapshot;
    }

    private function signedRows(array $rows): array
    {
        $result = [['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn', 'Hovedkategori', 'Kategori', 'Kommentar']];
        foreach ($rows as $row) $result[] = [$row[0], $row[1], $row[2], $row[3], '', 'test', 'Privatkonto', '', '', ''];
        return $result;
    }

    private function workbook(array $rows): string
    {
        $path = sys_get_temp_dir() . '/fin-statement-test-' . bin2hex(random_bytes(6)) . '.zip';
        $this->temporaryFiles[] = $path;
        $archive = new PharData($path, 0, null, Phar::ZIP);
        $xmlRows = [];
        foreach ($rows as $rowIndex => $row) {
            $cells = [];
            foreach ($row as $columnIndex => $value) {
                $reference = $this->columnName($columnIndex) . ($rowIndex + 1);
                $escaped = htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $cells[] = '<c r="' . $reference . '" t="inlineStr"><is><t>' . $escaped . '</t></is></c>';
            }
            $xmlRows[] = '<row r="' . ($rowIndex + 1) . '">' . implode('', $cells) . '</row>';
        }
        $archive->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . implode('', $xmlRows) . '</sheetData></worksheet>');
        unset($archive);
        return (string)file_get_contents($path);
    }

    private function columnName(int $index): string
    {
        $name = '';
        for ($number = $index + 1; $number > 0; $number = intdiv($number - 1, 26)) $name = chr(65 + (($number - 1) % 26)) . $name;
        return $name;
    }
}
