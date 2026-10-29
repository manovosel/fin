<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class StatementFileDropTest extends DatabaseTestCase
{
    private string $dropDir = '';
    private string $importedDir = '';
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        $this->cleanupDir($this->dropDir);
        $this->cleanupDir($this->importedDir);
        foreach ($this->tempFiles as $f) @unlink($f);
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionNeutralImportProfiles();
        $base = sys_get_temp_dir() . '/fin-drop-' . bin2hex(random_bytes(6));
        $this->dropDir = $base . '/import';
        $this->importedDir = $base . '/import/imported';
        mkdir($this->importedDir, 0700, true);
    }

    public function testDefaultDropDirectoryIsUnderFinRoot(): void
    {
        self::assertSame(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'import', statement_drop_dir());
    }

    public function test1SignedOrdinaryFileIsDetectedAndImported(): void
    {
        $accountId = $this->createAccount('Generic-Bank-Account', '5390 7547034');
        $path = $this->dropFile('signed-statement.xlsx', $this->signedWorkbook([
            ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn'],
            ['46237', 'LØN', '32518.67', '33931.03', '', '5390 7547034', 'Privatkonto'],
            ['46237', 'Hæv', '-200', '33731.03', '', '5390 7547034', 'Privatkonto'],
        ]));
        $report = statement_process_dropped_files($this->db, $this->dropDir, $accountId);
        self::assertCount(1, $report);
        self::assertSame('IMPORTED', $report[0]['outcome']);
        self::assertSame('Generic-Bank-Account', $report[0]['account']);
        self::assertSame(2, $report[0]['rows']);
        self::assertFileExists($this->importedDir . '/signed-statement.xlsx');
        self::assertFileDoesNotExist($path);
    }

    public function test2SignedCardFileIsDetectedAndImported(): void
    {
        $accountId = $this->createAccount('Generic-Credit-Card', '8601 111794');
        // The saved Import Profile distinguishes the card variant through
        // its bounded marker in Kontonavn.
        $path = $this->dropFile('credit.xlsx', $this->signedWorkbook([
            ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn'],
            ['46254', 'CARD SHOP', '-82.5', '-25959.7', '', '8601 111794', 'Synthetic Card Account'],
        ]));
        $report = statement_process_dropped_files($this->db, $this->dropDir, $accountId);
        self::assertCount(1, $report);
        self::assertSame('IMPORTED', $report[0]['outcome']);
        self::assertSame('Generic-Credit-Card', $report[0]['account']);
    }

    public function test3DebitCreditFileIsDetectedAndImported(): void
    {
        $accountId = $this->createAccount('Test Bank', '0051863000160');
        $path = $this->dropFile('debit-credit-statement.xls', $this->buildWorkbook($this->debitCreditWorkbook()));
        $report = statement_process_dropped_files($this->db, $this->dropDir, $accountId);
        self::assertCount(1, $report);
        self::assertSame('IMPORTED', $report[0]['outcome']);
        self::assertSame('Test Bank', $report[0]['account']);
    }

    public function test4ExplicitAccountOverridesAFileIdentifierForAnotherAccount(): void
    {
        $selectedId = $this->createAccount('Salary', '5555 1111 2222');
        $this->createAccount('Card', '4111 1111 1111 1111');
        $path = $this->dropFile('card.xlsx', $this->signedWorkbook([
            ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn'],
            ['46237', 'A', '-50', '950', '', '4111111111111111', 'Privatkonto'],
        ]));
        $report = statement_process_dropped_files($this->db, $this->dropDir, $selectedId);
        self::assertSame('IMPORTED', $report[0]['outcome']);
        self::assertSame('Salary', $report[0]['account']);
    }

    public function test5SuccessfulFileMovesToImported(): void
    {
        $accountId = $this->createAccount('Generic-Bank-Account', '5390 7547034');
        $path = $this->dropFile('move-me.xlsx', $this->signedWorkbook([
            ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn'],
            ['46237', 'A', '-50', '950', '', '5390 7547034', 'Privatkonto'],
        ]));
        statement_process_dropped_files($this->db, $this->dropDir, $accountId);
        self::assertFileExists($this->importedDir . '/move-me.xlsx');
        self::assertFileDoesNotExist($path);
    }

    public function test6ImportedEvidenceIsFinanciallyInert(): void
    {
        $accountId = $this->createAccount('Generic-Bank-Account', '5390 7547034');
        $this->dropFile('s.xlsx', $this->signedWorkbook([
            ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn'],
            ['46237', 'A', '-50', '950', '', '5390 7547034', 'Privatkonto'],
        ]));
        $beforeTx = (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
        $beforeEnv = (int)$this->db->query('SELECT COUNT(*) FROM envelope_movements')->fetchColumn();
        $beforeAcc = (int)$this->db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn();
        $beforeAccEv = (int)$this->db->query('SELECT COUNT(*) FROM account_events')->fetchColumn();
        statement_process_dropped_files($this->db, $this->dropDir, $accountId);
        self::assertSame($beforeTx, (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
        self::assertSame($beforeEnv, (int)$this->db->query('SELECT COUNT(*) FROM envelope_movements')->fetchColumn());
        self::assertSame($beforeAcc, (int)$this->db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn());
        self::assertSame($beforeAccEv, (int)$this->db->query('SELECT COUNT(*) FROM account_events')->fetchColumn());
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM statement_rows')->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM statement_reconciliations')->fetchColumn());
    }

    public function test7DuplicateEvidenceIsNotCreated(): void
    {
        $accountId = $this->createAccount('Generic-Bank-Account', '5390 7547034');
        $this->dropFile('dup.xlsx', $this->signedWorkbook([
            ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn'],
            ['46237', 'A', '-50', '950', '', '5390 7547034', 'Privatkonto'],
        ]));
        statement_process_dropped_files($this->db, $this->dropDir, $accountId);
        $countAfterFirst = (int)$this->db->query('SELECT COUNT(*) FROM statement_imports')->fetchColumn();
        self::assertSame(1, $countAfterFirst);

        // Re-create the same-source file in import/ (must be byte-identical)
        // simulating a user re-dropping the same file.
        $this->dropFile('dup.xlsx', $this->signedWorkbook([
            ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn'],
            ['46237', 'A', '-50', '950', '', '5390 7547034', 'Privatkonto'],
        ]));
        $report = statement_process_dropped_files($this->db, $this->dropDir, $accountId);
        self::assertCount(1, $report);
        self::assertSame('ALREADY IMPORTED', $report[0]['outcome']);
        self::assertSame($countAfterFirst, (int)$this->db->query('SELECT COUNT(*) FROM statement_imports')->fetchColumn());
    }

    public function test8SafelyAlreadyImportedSourceIsMovedToImported(): void
    {
        $accountId = $this->createAccount('Generic-Bank-Account', '5390 7547034');
        $this->dropFile('src.xlsx', $this->signedWorkbook([
            ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn'],
            ['46237', 'A', '-50', '950', '', '5390 7547034', 'Privatkonto'],
        ]));
        $report = statement_process_dropped_files($this->db, $this->dropDir, $accountId);
        self::assertSame('IMPORTED', $report[0]['outcome']);
        self::assertFileExists($this->importedDir . '/src.xlsx');
    }

    public function test9UnsupportedFileRemainsInImport(): void
    {
        $accountId = $this->createAccount('Generic-Bank-Account', '5390 7547034');
        $path = $this->dropFile('junk.txt', "<html>not a real statement</html>");
        $report = statement_process_dropped_files($this->db, $this->dropDir, $accountId);
        self::assertCount(1, $report);
        self::assertSame('FAILED', $report[0]['outcome']);
        self::assertFileExists($path);
        self::assertFileDoesNotExist($this->importedDir . '/junk.txt');
    }

    public function test10ParseImportFailureLeavesFileInImport(): void
    {
        $accountId = $this->createAccount('Generic-Bank-Account', '5390 7547034');
        $path = $this->dropFile('broken.xlsx', "PK\x03\x04-not-a-real-zip");
        $report = statement_process_dropped_files($this->db, $this->dropDir, $accountId);
        self::assertSame('FAILED', $report[0]['outcome']);
        self::assertFileExists($path);
    }

    public function test11DroppedFileWithoutExplicitAccountLeavesFileInImport(): void
    {
        $this->createAccount('Generic-Bank-Account', '0000 1111111');
        $path = $this->dropFile('unknown.xlsx', $this->signedWorkbook([
            ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn'],
            ['46237', 'A', '-50', '950', '', '5390 7547034', 'Privatkonto'],
        ]));
        $report = statement_process_dropped_files($this->db, $this->dropDir);
        self::assertSame('NEEDS ACCOUNT', $report[0]['outcome']);
        self::assertSame('Select an Account.', $report[0]['reason']);
        self::assertFileExists($path);
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM statement_imports')->fetchColumn());
    }

    public function test12DroppedFileDoesNotInferAccountFromAnyStoredIdentifier(): void
    {
        $this->createAccount('Generic-Bank-Account', '9999');
        $this->dropFile('real.xlsx', $this->signedWorkbook([
            ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn'],
            ['46237', 'A', '-50', '950', '', '5390 7547034', 'Privatkonto'],
        ]));
        $report = statement_process_dropped_files($this->db, $this->dropDir);
        self::assertSame('NEEDS ACCOUNT', $report[0]['outcome']);
        self::assertSame('Select an Account.', $report[0]['reason']);
    }

    public function test13FileWithoutAccountIdentifierAlsoRequiresExplicitAccount(): void
    {
        $this->createAccount('Test Wallet', 'placeholder');
        $path = $this->dropFile('fee-statement.xlsx', $this->buildWorkbook($this->feeWorkbook()));
        $report = statement_process_dropped_files($this->db, $this->dropDir);
        self::assertSame('NEEDS ACCOUNT', $report[0]['outcome']);
        self::assertSame('Select an Account.', $report[0]['reason']);
        self::assertFileExists($path);
    }

    public function test14OneFailedFileDoesNotPreventOthers(): void
    {
        $accountId = $this->createAccount('Generic-Bank-Account', '5390 7547034');
        $this->dropFile('bad.xlsx', "garbage contents");
        $goodPath = $this->dropFile('good.xlsx', $this->signedWorkbook([
            ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn'],
            ['46237', 'A', '-50', '950', '', '5390 7547034', 'Privatkonto'],
        ]));
        $report = statement_process_dropped_files($this->db, $this->dropDir, $accountId);
        $outcomes = array_column($report, 'outcome');
        self::assertContains('FAILED', $outcomes);
        self::assertContains('IMPORTED', $outcomes);
        self::assertFileExists($this->importedDir . '/good.xlsx');
        self::assertFileDoesNotExist($goodPath);
    }

    public function test15DestinationFilenameCollisionNeverOverwritesDifferentFile(): void
    {
        $accountId = $this->createAccount('Generic-Bank-Account', '5390 7547034');
        $workbook = $this->signedWorkbook([
            ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn'],
            ['46237', 'A', '-50', '950', '', '5390 7547034', 'Privatkonto'],
        ]);
        $this->dropFile('col.xlsx', $workbook);
        statement_process_dropped_files($this->db, $this->dropDir, $accountId);
        self::assertFileExists($this->importedDir . '/col.xlsx');
        // Now drop a different-content file under the same name and process again.
        // The original destination keeps the byte-identical first file's bytes.
        $workbook2 = $this->signedWorkbook([
            ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn'],
            ['46237', 'A', '-77', '923', '', '5390 7547034', 'Privatkonto'],
        ]);
        $this->dropFile('col.xlsx', $workbook2);
        $report = statement_process_dropped_files($this->db, $this->dropDir, $accountId);
        self::assertCount(1, $report);
        self::assertSame('IMPORTED', $report[0]['outcome']);
        self::assertNotSame('col.xlsx', $report[0]['destination']);
        self::assertFileExists($this->importedDir . '/col.xlsx');
        self::assertFileExists($this->importedDir . '/' . $report[0]['destination']);
    }

    public function test16UiImportedSourceEndsInImported(): void
    {
        $this->createAccount('Generic-Bank-Account', '5390 7547034');
        $workbook = $this->signedWorkbook([
            ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn'],
            ['46237', 'A', '-50', '950', '', '5390 7547034', 'Privatkonto'],
        ]);
        // Simulate the UI flow: write via the same helper the HTTP action uses.
        $filename = 'ui-upload.xlsx';
        $contents = $workbook;
        $sourcePath = statement_write_to_drop_inbox($this->dropDir, $filename, $contents);
        $accountId = (int)$this->db->query('SELECT id FROM accounts LIMIT 1')->fetchColumn();
        $preview = preview_statement_import($this->db, $accountId, $filename, $contents);
        self::assertSame('Generic-Bank-Account', (string)$preview['account']['name']);
        $result = statement_process_one_dropped_file($this->db, $sourcePath, $this->importedDir, $accountId);
        self::assertSame('IMPORTED', $result['outcome']);
        self::assertFileExists($this->importedDir . '/ui-upload.xlsx');
        self::assertFileDoesNotExist($sourcePath);
    }

    public function test17PreviewWithoutExplicitAccountStopsBeforeParsing(): void
    {
        $this->createAccount('Generic-Bank-Account', '0000 9999999');
        $contents = $this->signedWorkbook([
            ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn'],
            ['46237', 'A', '-50', '950', '', '5390 7547034', 'Privatkonto'],
        ]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Select an Account.');
        preview_statement_import($this->db, null, 'ui-fallback.xlsx', $contents);
    }

    public function test18CliAndUiUseSameExplicitAccountIngestionSemantics(): void
    {
        $accountId = $this->createAccount('Generic-Bank-Account', '5390 7547034');
        $contents = $this->signedWorkbook([
            ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn'],
            ['46237', 'A', '-50', '950', '', '5390 7547034', 'Privatkonto'],
        ]);
        // CLI path:
        $cliPath = $this->dropFile('cli.xlsx', $contents);
        $cliReport = statement_process_dropped_files($this->db, $this->dropDir, $accountId);
        $cliImportId = (int)$cliReport[0]['import_id'];

        // UI path: write the same content (different filename, otherwise filename-keyed unique would always count as duplicate File).
        $uiPath = statement_write_to_drop_inbox($this->dropDir, 'ui-other.xlsx', $contents);
        $uiReport = statement_process_one_dropped_file($this->db, $uiPath, $this->importedDir, $accountId);
        $uiImportId = (int)$uiReport['import_id'];

        // Both paths use the existing import_statement_evidence path; check via the
        // statement_imports rows that they share the same ingestion semantics.
        $cliRow = $this->db->query('SELECT account_id, row_count FROM statement_imports WHERE id = ' . (int)$cliImportId)->fetch();
        $uiRow  = $this->db->query('SELECT account_id, row_count FROM statement_imports WHERE id = ' . (int)$uiImportId)->fetch();
        self::assertSame((int)$cliRow['account_id'], (int)$uiRow['account_id']);
        self::assertSame((int)$cliRow['row_count'], (int)$uiRow['row_count']);
    }

    public function test19ReconciliationIsNotTriggeredAutomatically(): void
    {
        $accountId = $this->createAccount('Generic-Bank-Account', '5390 7547034');
        $this->dropFile('no-recon.xlsx', $this->signedWorkbook([
            ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn'],
            ['46237', 'A', '-50', '950', '', '5390 7547034', 'Privatkonto'],
        ]));
        statement_process_dropped_files($this->db, $this->dropDir, $accountId);
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM statement_rows WHERE account_id = ' . (int)$accountId)->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM statement_reconciliations')->fetchColumn());
        $state = statement_reconciliation_load_for_account($this->db, $accountId);
        self::assertCount(1, $state['unresolved']);
        self::assertSame(0, count($state['reconciled']));
    }

    public function test20ExistingReconciliationBehaviorRemainsUnchanged(): void
    {
        $accountId = $this->createAccount('Generic-Bank-Account', '5390 7547034');
        $envelopeId = $this->createEnvelope('Envelope A');
        $monthId = $this->createMonth(2026, 8);
        $txId = save_transaction($this->db, [
            'month_id' => $monthId, 'envelope_id' => $envelopeId, 'payment_account_id' => $accountId,
            'amount' => 50, 'spent_date' => '2026-08-04',
        ]);
        $this->dropFile('match.xlsx', $this->signedWorkbook([
            ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn'],
            ['46237', 'A', '-50', '950', '', '5390 7547034', 'Privatkonto'],
        ]));
        $report = statement_process_dropped_files($this->db, $this->dropDir, $accountId);
        $state = statement_reconciliation_load_for_account($this->db, $accountId);
        self::assertCount(0, $state['unresolved']);
        self::assertCount(1, $state['reconciled']);
        self::assertSame($txId, (int)$state['reconciled'][0]['transaction_id']);
        $txAfter = $this->db->query("SELECT amount, payment_account_id FROM transactions WHERE id=$txId")->fetch();
        self::assertSame(50.0, (float)$txAfter['amount']);
        self::assertSame($accountId, (int)$txAfter['payment_account_id']);
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM envelope_movements WHERE transaction_id=$txId")->fetchColumn());
    }

    public function test21ImportRemainsFinanciallyInertAndPreservesOrdinaryData(): void
    {
        $accountId = $this->createAccount('Generic-Bank-Account', '5390 7547034');
        $monthId = save_month($this->db, ['year' => 2026, 'month' => 8, 'exchange_rate' => 7.46]);
        $envelopeId = save_envelope($this->db, ['name' => 'Envelope A', 'account' => 'Generic-Bank-Account', 'role' => 'daily', 'default_account_id' => $accountId]);
        $txId = save_transaction($this->db, [
            'month_id' => $monthId, 'envelope_id' => $envelopeId, 'payment_account_id' => $accountId,
            'amount' => 125, 'spent_date' => '2026-08-04',
        ]);
        $beforeAccount = account_balance_as_of($this->db, $accountId, '2026-08-04');
        $beforeEnvelope = envelope_balance_as_of($this->db, $envelopeId, '2026-08-04');
        $this->dropFile('keep-fin.xlsx', $this->signedWorkbook([
            ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn'],
            ['46237', 'A', '-50', '950', '', '5390 7547034', 'Privatkonto'],
        ]));
        statement_process_dropped_files($this->db, $this->dropDir, $accountId);
        // Statement import must remain financially inert: no observation created, balance stays Unknown.
        self::assertNull($beforeAccount);
        self::assertNull(account_balance_as_of($this->db, $accountId, '2026-08-04'));
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM account_balance_observations WHERE account_id=$accountId")->fetchColumn());
        self::assertSame($beforeEnvelope, envelope_balance_as_of($this->db, $envelopeId, '2026-08-04'));
        $txAfter = $this->db->query("SELECT amount, payment_account_id, envelope_id FROM transactions WHERE id=$txId")->fetch();
        self::assertSame(125.0, (float)$txAfter['amount']);
        self::assertSame($accountId, (int)$txAfter['payment_account_id']);
        self::assertSame($envelopeId, (int)$txAfter['envelope_id']);
    }

    private function createAccount(string $name, string $number): int
    {
        return save_account_definition($this->db, [
            'name' => $name, 'account_number' => $number, 'currency' => 'CURRENCY',
        ]);
    }

    private function dropFile(string $filename, string $contents): string
    {
        $path = rtrim($this->dropDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
        if (file_put_contents($path, $contents, LOCK_EX) === false) throw new RuntimeException('Could not write drop fixture');
        chmod($path, 0600);
        return $path;
    }

    private function signedWorkbook(array $rows): string
    {
        // Extend each row to the full 10-column signed header
        // layout (dato, tekst, beløb, saldo, afstemt, kontonummer, kontonavn,
        // hovedkategori, kategori, kommentar) so the matcher recognises the
        // saved neutral profile structure. The first row is the header and
        // gets the missing column names appended; data rows are padded with
        // empty trailing cells.
        $extended = [];
        foreach ($rows as $i => $row) {
            $values = array_values($row);
            if ($i === 0) {
                $missing = ['Hovedkategori', 'Kategori', 'Kommentar'];
                $values = array_merge($values, $missing);
            } else {
                while (count($values) < 10) $values[] = '';
            }
            $extended[] = $values;
        }
        return $this->buildWorkbook($extended);
    }

    private function feeWorkbook(): array
    {
        return [
            ['Type', 'Produkt', 'Startdato', 'Fuldførelsesdato', 'Beskrivelse', 'Beløb', 'Gebyr', 'Valuta', 'State', 'Saldo'],
            ['Kortbetaling', 'Nuværende', '46052.5', '46052.5', 'GROCERY SHOP', '-200.50', '0.0', 'DKK', 'GENNEMFØRT', '700.00'],
        ];
    }

    private function debitCreditWorkbook(): array
    {
        // Include all 26 expected debit/credit header keys so the matcher
        // recognises the saved neutral profile structure.
        $header = ['Datum knjiženja', 'Datum valute', 'Uplata/ isplata', 'Iznos isplate', 'Iznos uplate', 'Valuta', 'Broj transakcije', 'Status', 'IBAN platitelja', 'Naziv platitelja', 'Adresa platitelja', 'Sjedište platitelja', 'Poziv na broj platitelja', 'Stvarni dužnik', 'IBAN primatelja', 'Naziv primatelja', 'Adresa primatelja', 'Sjedište primatelja', 'Poziv na broj primatelja', 'Opis plaćanja', 'Krajnji primatelj', 'Šifra namjene', 'Originalni iznos isplate', 'Originalni iznos uplate', 'Originalna valuta', 'Tečaj'];
        $row = ['46246', '46246.5', 'Isplata', '23.00', '', 'EUR', 'TEST-OUT-000001', 'Provedeno', 'payer', 'Owner', '', '', '', '', 'recipient', 'Generic payee', '', '', '', 'Payment', '', '', '', '', '', ''];
        return [
            ['Transakcije'],
            ['Datum i vrijeme kreiranja: 22.08.2026. 07:51:12'],
            ['Račun: 0051863000160'],
            ['Vremensko razdoblje: 2026-01-01 - 2026-12-31'],
            [],
            $header,
            $row,
        ];
    }

    private function buildWorkbook(array $rows): string
    {
        $path = '/tmp/fin-drop-wb-' . bin2hex(random_bytes(6)) . '.zip';
        $this->tempFiles[] = $path;
        $archive = new PharData($path, 0, null, Phar::ZIP);
        $xmlRows = [];
        foreach ($rows as $rowIndex => $row) {
            $cells = [];
            foreach ($row as $columnIndex => $value) {
                $letter = '';
                $n = $columnIndex + 1;
                while ($n > 0) { $n--; $letter = chr(65 + ($n % 26)) . $letter; $n = intdiv($n, 26); }
                $reference = $letter . ($rowIndex + 1);
                $escaped = htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $cells[] = '<c r="' . $reference . '" t="inlineStr"><is><t>' . $escaped . '</t></is></c>';
            }
            $xmlRows[] = '<row r="' . ($rowIndex + 1) . '">' . implode('', $cells) . '</row>';
        }
        $archive->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . implode('', $xmlRows) . '</sheetData></worksheet>');
        unset($archive);
        return (string)file_get_contents($path);
    }

    private function cleanupDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) return;
        $entries = scandir($dir);
        if (!is_array($entries)) return;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) $this->cleanupDir($path);
            else @unlink($path);
        }
        @rmdir($dir);
    }
}
