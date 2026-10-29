<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class StatementExplicitAccountOwnershipTest extends DatabaseTestCase
{
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionNeutralImportProfiles();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) @unlink($file);
        parent::tearDown();
    }

    public function testAccountNumberIsMandatoryForNewlyCreatedAccount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Account number is required');
        save_account_definition($this->db, ['name' => 'Test Wallet', 'type' => 'asset', 'currency' => 'CURRENCY']);
    }

    public function testAccountNumberIsMandatoryWhenUpdatingAccount(): void
    {
        $id = save_account_definition($this->db, ['name' => 'Test Wallet', 'account_number' => 'TEST-1', 'type' => 'asset', 'currency' => 'CURRENCY']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Account number is required');
        save_account_definition($this->db, ['id' => $id, 'name' => 'Test Wallet', 'account_number' => '   ', 'type' => 'asset', 'currency' => 'CURRENCY']);
    }

    public function testAccountNumbersRemainUnique(): void
    {
        save_account_definition($this->db, ['name' => 'Test Wallet', 'account_number' => 'TEST-UNIQUE-1', 'type' => 'asset', 'currency' => 'CURRENCY']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An account with this Account number already exists');
        save_account_definition($this->db, ['name' => 'Test Bank', 'account_number' => 'TEST-UNIQUE-1', 'type' => 'asset', 'currency' => 'CURRENCY']);
    }

    public function testMigrationStillBackfillsUniqueTemporaryIdentifiers(): void
    {
        save_account_definition($this->db, ['name' => 'Test Wallet', 'account_number' => 'PRE-V210-A', 'type' => 'asset', 'currency' => 'CURRENCY']);
        save_account_definition($this->db, ['name' => 'Test Bank', 'account_number' => 'PRE-V210-B', 'type' => 'asset', 'currency' => 'CURRENCY']);
        $this->db->exec('DROP INDEX IF EXISTS idx_accounts_account_number');
        $this->db->exec('ALTER TABLE accounts DROP COLUMN account_number');
        migrate_to_v2_10($this->db);
        $rows = $this->db->query('SELECT id, account_number FROM accounts ORDER BY id')->fetchAll();
        self::assertCount(2, $rows);
        foreach ($rows as $row) self::assertSame('TEMP-' . $row['id'], (string)$row['account_number']);
    }

    public function testPreviewRequiresExplicitAccountEvenWhenFileIdentifierMatchesExactly(): void
    {
        $number = 'TEST-EXACT-001';
        $this->createAccount('Selected Account', $number);
        $contents = $this->signedWorkbook($number);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Select an Account.');
        preview_statement_import($this->db, null, 'statement.xlsx', $contents);
    }

    public function testImportRequiresExplicitAccount(): void
    {
        $contents = $this->signedWorkbook('TEST-FILE-001');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Select an Account.');
        import_statement_evidence($this->db, 0, 'statement.xlsx', $contents);
    }

    public function testSelectedAccountIsAuthoritativeWhenFileIdentifierBelongsToAnotherAccount(): void
    {
        $selectedId = $this->createAccount('Selected Account', 'TEST-SELECTED-001');
        $this->createAccount('Other Account', 'TEST-FILE-001');
        $preview = preview_statement_import($this->db, $selectedId, 'statement.xlsx', $this->signedWorkbook('TEST-FILE-001'));

        self::assertSame($selectedId, (int)$preview['account']['id']);
        self::assertSame('Selected Account', (string)$preview['account']['name']);
        self::assertSame('TEST-FILE-001', (string)$preview['file_account_number']);
        self::assertArrayNotHasKey('account_choice_required', $preview);
        self::assertArrayNotHasKey('account_candidates', $preview);
        self::assertArrayNotHasKey('account_match_reason', $preview);
    }

    public function testSelectedAccountIsAuthoritativeForPartialDomesticFileIdentifier(): void
    {
        $selectedId = $this->createAccount('Selected Account', 'FULL-ACCOUNT-IDENTIFIER-001');
        $preview = preview_statement_import($this->db, $selectedId, 'partial.xlsx', $this->signedWorkbook('7547034'));

        self::assertSame($selectedId, (int)$preview['account']['id']);
        self::assertSame('7547034', (string)$preview['file_account_number']);
        self::assertSame(1, (int)$preview['rows_found']);
    }

    public function testSelectedAccountWorksWhenFileHasNoAccountIdentifier(): void
    {
        $selectedId = $this->createAccount('Selected Wallet', 'TEST-WALLET-001');
        $preview = preview_statement_import($this->db, $selectedId, 'wallet.xlsx', $this->walletWorkbook());

        self::assertSame($selectedId, (int)$preview['account']['id']);
        self::assertSame('', (string)$preview['file_account_number']);
        self::assertSame(1, (int)$preview['rows_found']);
    }

    public function testNormalWorkflowContainsNoAutomaticTargetAccountResolver(): void
    {
        self::assertFalse(function_exists('statement_detect_account_by_number'));
        self::assertSame('53907547034', statement_normalize_account_number('5390 7547034'), 'Exact identifier normalization remains available for counterpart Transfer recognition.');
    }

    public function testImportPersistsEvidenceOnlyUnderSelectedAccount(): void
    {
        $selectedId = $this->createAccount('Selected Account', 'TEST-SELECTED-002');
        $otherId = $this->createAccount('Other Account', 'TEST-FILE-002');
        $result = import_statement_evidence($this->db, $selectedId, 'statement.xlsx', $this->signedWorkbook('TEST-FILE-002'));

        self::assertTrue((bool)$result['ok']);
        self::assertSame($selectedId, (int)$this->db->query('SELECT account_id FROM statement_imports')->fetchColumn());
        self::assertSame($selectedId, (int)$this->db->query('SELECT account_id FROM statement_rows')->fetchColumn());
        self::assertNotSame($otherId, (int)$this->db->query('SELECT account_id FROM statement_rows')->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM envelope_movements')->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn());
    }

    public function testStatementOwnerIdentifierCannotBecomeAnAutomaticTransferCounterpart(): void
    {
        $selectedId = $this->createAccount('Selected Account', 'TEST-SELECTED-003');
        $otherId = $this->createAccount('Other Account', 'TEST-FILE-003');
        import_statement_evidence($this->db, $selectedId, 'statement.xlsx', $this->signedWorkbook('TEST-FILE-003'));
        $rowId = (int)$this->db->query('SELECT id FROM statement_rows')->fetchColumn();

        $result = statement_reconciliation_auto_process_row($this->db, $rowId);

        self::assertSame('exception', $result['outcome']);
        self::assertSame('missing_envelope', $result['code']);
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM account_events WHERE event_type='transfer'")->fetchColumn());
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM account_movements WHERE account_id=' . $otherId)->fetchColumn());
    }

    public function testArchivedAccountIsNotEligibleForNewStatementEvidence(): void
    {
        $accountId = $this->createAccount('Archived Account', 'TEST-ARCHIVED-001');
        set_account_active($this->db, $accountId, false);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Select an active Account.');
        preview_statement_import($this->db, $accountId, 'statement.xlsx', $this->signedWorkbook('TEST-ARCHIVED-001'));
    }

    private function createAccount(string $name, string $number): int
    {
        return save_account_definition($this->db, [
            'name' => $name,
            'account_number' => $number,
            'type' => 'asset',
            'currency' => 'CURRENCY',
        ]);
    }

    private function signedWorkbook(string $fileAccountNumber): string
    {
        return $this->buildWorkbook([
            ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn', 'Hovedkategori', 'Kategori', 'Kommentar'],
            ['46237', 'Synthetic statement row', '-50', '950', '', $fileAccountNumber, 'Synthetic account', '', '', ''],
        ]);
    }

    private function walletWorkbook(): string
    {
        return $this->buildWorkbook([
            ['Type', 'Produkt', 'Startdato', 'Fuldførelsesdato', 'Beskrivelse', 'Beløb', 'Gebyr', 'Valuta', 'State', 'Saldo'],
            ['Payment', 'Current', '46052.5', '46052.5', 'Synthetic wallet row', '-20.50', '0', 'DKK', 'Completed', '700'],
        ]);
    }

    private function buildWorkbook(array $rows): string
    {
        $path = '/tmp/fin-explicit-account-' . bin2hex(random_bytes(6)) . '.zip';
        $this->tempFiles[] = $path;
        $archive = new PharData($path, 0, null, Phar::ZIP);
        $xmlRows = [];
        foreach ($rows as $rowIndex => $row) {
            $cells = [];
            foreach ($row as $columnIndex => $value) {
                $letter = '';
                for ($n = $columnIndex + 1; $n > 0; $n = intdiv($n - 1, 26)) $letter = chr(65 + (($n - 1) % 26)) . $letter;
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
}
