<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StatementImportProfileCliTest extends TestCase
{
    private string $directory;
    private string $appDirectory;
    private string $api;
    private string $database;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/fin-profile-cli-' . bin2hex(random_bytes(6));
        $this->appDirectory = $this->directory . '/app';
        if (!mkdir($this->appDirectory, 0700, true) && !is_dir($this->appDirectory)) {
            throw new RuntimeException('Could not create CLI test directory');
        }
        $root = dirname(__DIR__, 2);
        foreach ([
            'api.php', 'auth.php', 'accounting.php', 'currency.php', 'ecb_rates.php',
            'statement_import.php', 'statement_reconciliation.php', 'recommendation.php',
            'report.php', 'config.php', 'VERSION',
        ] as $file) {
            copy($root . '/' . $file, $this->appDirectory . '/' . $file);
        }
        $this->api = $this->appDirectory . '/api.php';
        $this->database = $this->directory . '/cli.sqlite';
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) return;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($this->directory);
    }

    public function testInspectStatementIsBoundedReadOnlyAndRejectsNonOoxml(): void
    {
        $statement = $this->writeWorkbook('inspect.xlsx', [
            ['Booked Date', 'Description', 'Amount', 'Balance', 'Account Number', 'Category'],
            ['2026-01-01', str_repeat('Long synthetic description ', 8), '-10.00', '990.00', 'TEST-ACCOUNT-001', 'Card'],
            ['2026-01-02', 'Second synthetic row', '20.00', '1010.00', '', 'Transfer'],
            ['2026-01-03', 'Third synthetic row', '-5.00', '1005.00', '', 'Card'],
            ['2026-01-04', 'Fourth synthetic row', '-6.00', '999.00', '', 'Card'],
        ]);

        [$code, $output] = $this->runCli('inspect-statement', $statement);
        self::assertSame(0, $code, $output);
        $result = $this->decodeJson($output);
        self::assertSame('ooxml', $result['container']);
        self::assertSame(0, $result['sheet']);
        self::assertLessThanOrEqual(20, $result['rows_scanned']);
        $header = $this->candidateByRow($result['candidate_rows'], 1);
        self::assertSame([0, 1, 2, 3, 4, 5], $header['occupied_columns']);
        self::assertSame(
            ['bookeddate', 'description', 'amount', 'balance', 'accountnumber', 'category'],
            array_column($header['normalized_header_keys'], 'key')
        );
        self::assertCount(3, $header['sample_rows']);
        foreach ($header['sample_rows'] as $sample) {
            self::assertLessThanOrEqual(16, count($sample['values']));
            foreach ($sample['values'] as $value) self::assertLessThanOrEqual(83, strlen($value));
        }
        self::assertFileDoesNotExist($this->database);

        $invalid = $this->directory . '/not-ooxml.txt';
        file_put_contents($invalid, 'not an OOXML workbook');
        [$invalidCode, $invalidOutput] = $this->runCli('inspect-statement', $invalid);
        self::assertSame(1, $invalidCode);
        self::assertStringContainsString('not a supported XLSX workbook', $invalidOutput);
        self::assertStringNotContainsString('Stack trace', $invalidOutput);
        self::assertFileDoesNotExist($this->database);
    }

    public function testValidateProfileIsClosedReadOnlyAndPrintsSignatureSummary(): void
    {
        $profile = $this->writeProfile('valid-profile.json', $this->signedTransport());

        [$code, $output] = $this->runCli('validate-import-profile', $profile);
        self::assertSame(0, $code, $output);
        $summary = $this->decodeJson($output);
        self::assertSame(1, $summary['schema_version']);
        self::assertSame('ooxml', $summary['container']);
        self::assertSame(0, $summary['sheet']);
        self::assertSame(7, $summary['header_fields']);
        self::assertSame(0, $summary['markers']);
        self::assertSame('signed', $summary['amount_mode']);
        self::assertSame('column_first_nonempty', $summary['account_number_mode']);
        self::assertTrue($summary['classification_mapping']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $summary['signature_hash']);
        self::assertArrayNotHasKey('definition', $summary);
        self::assertFileDoesNotExist($this->database);

        $invalidJson = $this->directory . '/invalid.json';
        file_put_contents($invalidJson, '{invalid');
        [$invalidCode, $invalidOutput] = $this->runCli('validate-import-profile', $invalidJson);
        self::assertSame(1, $invalidCode);
        self::assertStringContainsString('JSON is invalid', $invalidOutput);
        self::assertStringNotContainsString('Stack trace', $invalidOutput);

        $unknown = $this->signedTransport();
        $unknown['provider'] = 'not-allowed';
        $unknownPath = $this->writeProfile('unknown-key.json', $unknown);
        [$unknownCode, $unknownOutput] = $this->runCli('validate-import-profile', $unknownPath);
        self::assertSame(1, $unknownCode);
        self::assertStringContainsString('unknown key provider', $unknownOutput);
        self::assertFileDoesNotExist($this->database);
    }

    public function testPreviewSupportsThreeStructuresWithoutSavingOrPersistingEvidence(): void
    {
        $signedProfile = $this->writeProfile('signed.json', $this->signedTransport());
        $signedStatement = $this->writeWorkbook('signed.xlsx', [
            ['Booked Date', 'Description', 'Amount', 'Balance', 'Account Number', 'Category', 'Main Category'],
            ['2026-02-01', 'Synthetic income', '100.00', '1100.00', 'TEST-ACCOUNT-002', 'Credit', 'Income Marker'],
            ['2026-02-02', 'Malformed synthetic row', 'not-a-number', '1100.00', '', 'Card', 'Other'],
        ]);
        [$signedCode, $signedOutput] = $this->runCli('preview-import-profile', $signedProfile, $signedStatement);
        self::assertSame(0, $signedCode, $signedOutput);
        $signed = $this->decodeJson($signedOutput);
        self::assertSame(1, $signed['header_row']);
        self::assertSame('TEST-ACCOUNT-002', $signed['file_account_number']);
        self::assertSame(2, $signed['rows']);
        self::assertSame(1, $signed['valid_rows']);
        self::assertSame(1, $signed['problem_rows']);
        self::assertSame('2026-02-01', $signed['statement_from']);
        self::assertSame('income', $signed['sample_rows'][0]['classification']);
        self::assertStringContainsString('Invalid amount', $signed['sample_rows'][1]['parse_problem']);

        $feeProfile = $this->writeProfile('fee.json', $this->feeTransport());
        $feeStatement = $this->writeWorkbook('fee.xlsx', [
            ['Type', 'Start Date', 'Completed Date', 'Description', 'Amount', 'Fee', 'Currency', 'Balance'],
            ['Card', '2026-03-01', '2026-03-02', 'Synthetic fee row', '-20.00', '1.50', 'EUR', '978.50'],
        ]);
        [$feeCode, $feeOutput] = $this->runCli('preview-import-profile', $feeProfile, $feeStatement);
        self::assertSame(0, $feeCode, $feeOutput);
        $fee = $this->decodeJson($feeOutput);
        self::assertSame(-21.5, $fee['sample_rows'][0]['amount']);
        self::assertSame('EUR', $fee['sample_rows'][0]['currency']);
        self::assertSame('—', $fee['file_account_number']);

        $debitProfile = $this->writeProfile('debit.json', $this->debitCreditTransport());
        $debitStatement = $this->writeWorkbook('debit.xlsx', [
            ['Synthetic statement'],
            ['Account: 0000123456'],
            ['Booked Date', 'Value Date', 'Direction', 'Debit', 'Credit', 'Currency', 'Reference', 'Status', 'Description', 'Payee', 'Payer'],
            ['2026-04-01', '2026-04-02', 'Out', '25.00', '', 'EUR', 'TEST-REF-1', 'Booked', 'Synthetic payment', 'Test Recipient', ''],
            ['2026-04-03', '2026-04-03', 'Out', '10.00', '20.00', 'EUR', 'TEST-BAD-1', 'Rejected', 'Malformed sides', 'Test Recipient', 'Test Sender'],
        ]);
        [$debitCode, $debitOutput] = $this->runCli('preview-import-profile', $debitProfile, $debitStatement);
        self::assertSame(0, $debitCode, $debitOutput);
        $debit = $this->decodeJson($debitOutput);
        self::assertSame(3, $debit['header_row']);
        self::assertSame('0000123456', $debit['file_account_number']);
        self::assertSame(-25, $debit['sample_rows'][0]['amount']);
        self::assertSame('Out · Booked', $debit['sample_rows'][0]['row_type']);
        self::assertSame('TEST-REF-1', $debit['sample_rows'][0]['reference']);
        self::assertSame(1, $debit['problem_rows']);
        self::assertStringContainsString('Invalid signed amount', $debit['sample_rows'][1]['parse_problem']);

        self::assertFileDoesNotExist($this->database, 'Read-only previews must not initialize or write SQLite');
    }

    public function testSaveAllowsSameBaseSignatureWithDifferentMarkers(): void
    {
        $bank = $this->signedTransport();
        $bank['name'] = 'Test Bank A';
        $bank['evidence_namespace'] = 'profile:test-cli-bank-a';
        $bank['definition']['signature']['markers'] = [
            ['column' => 'description', 'operator' => 'not_contains', 'value' => 'test card marker'],
        ];
        $card = $bank;
        $card['name'] = 'Test Card';
        $card['evidence_namespace'] = 'profile:test-cli-card';
        $card['definition']['signature']['markers'] = [
            ['column' => 'description', 'operator' => 'contains', 'value' => 'test card marker'],
        ];

        self::assertSame($bank['definition']['signature']['container'], $card['definition']['signature']['container']);
        self::assertSame($bank['definition']['signature']['sheet'], $card['definition']['signature']['sheet']);
        self::assertSame($bank['definition']['signature']['header'], $card['definition']['signature']['header']);
        self::assertSame($bank['definition']['parser'], $card['definition']['parser']);
        self::assertNotSame($bank['definition']['signature']['markers'], $card['definition']['signature']['markers']);
        self::assertSame(
            statement_import_profile_signature_hash($bank['definition']),
            statement_import_profile_signature_hash($card['definition'])
        );

        $bankPath = $this->writeProfile('bank-a.json', $bank);
        $cardPath = $this->writeProfile('card.json', $card);
        [$bankCode, $bankOutput] = $this->runCli('save-import-profile', $bankPath);
        [$cardCode, $cardOutput] = $this->runCli('save-import-profile', $cardPath);
        self::assertSame(0, $bankCode, $bankOutput);
        self::assertSame(0, $cardCode, $cardOutput);
        $savedBank = $this->decodeJson($bankOutput);
        $savedCard = $this->decodeJson($cardOutput);
        self::assertSame($savedBank['signature_hash'], $savedCard['signature_hash']);

        $db = new PDO('sqlite:' . $this->database);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $storedBank = statement_import_profile_get($db, (int)$savedBank['saved_profile_id']);
        $storedCard = statement_import_profile_get($db, (int)$savedCard['saved_profile_id']);
        self::assertSame('Test Bank A', $storedBank['name']);
        self::assertSame('Test Card', $storedCard['name']);
        self::assertSame('profile:test-cli-bank-a', $storedBank['evidence_namespace']);
        self::assertSame('profile:test-cli-card', $storedCard['evidence_namespace']);
        self::assertSame($storedBank['signature_hash'], $storedCard['signature_hash']);
        self::assertNotSame($storedBank['definition']['signature']['markers'], $storedCard['definition']['signature']['markers']);

        [$listCode, $listOutput] = $this->runCli('list-import-profiles');
        self::assertSame(0, $listCode, $listOutput);
        $profiles = $this->decodeJson($listOutput);
        self::assertSame(['Test Bank A', 'Test Card'], array_column($profiles, 'name'));
        self::assertSame(
            [(int)$savedBank['saved_profile_id'], (int)$savedCard['saved_profile_id']],
            array_column($profiles, 'id')
        );
        self::assertSame($profiles[0]['signature'], $profiles[1]['signature']);
    }

    public function testSaveListRenameEnableDisableAndDeleteUseExistingLifecycle(): void
    {
        $profilePath = $this->writeProfile('saved.json', $this->signedTransport());
        [$saveCode, $saveOutput] = $this->runCli('save-import-profile', $profilePath);
        self::assertSame(0, $saveCode, $saveOutput);
        $saved = $this->decodeJson($saveOutput);
        $profileId = (int)$saved['saved_profile_id'];
        self::assertGreaterThan(0, $profileId);
        self::assertSame('Test Bank', $saved['name']);
        self::assertSame('Enabled', $saved['state']);
        self::assertSame('profile:test-cli-signed', $saved['evidence_namespace']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $saved['signature_hash']);

        [$duplicateCode, $duplicateOutput] = $this->runCli('save-import-profile', $profilePath);
        self::assertSame(1, $duplicateCode);
        self::assertStringContainsString('already exists', $duplicateOutput);

        [$listCode, $listOutput] = $this->runCli('list-import-profiles');
        self::assertSame(0, $listCode, $listOutput);
        $profiles = $this->decodeJson($listOutput);
        self::assertCount(1, $profiles);
        self::assertSame($profileId, $profiles[0]['id']);
        self::assertFalse($profiles[0]['referenced']);
        self::assertSame(12, strlen($profiles[0]['signature']));
        self::assertArrayNotHasKey('definition', $profiles[0]);

        [$renameCode, $renameOutput] = $this->runCli('rename-import-profile', (string)$profileId, 'Renamed Test Bank');
        self::assertSame(0, $renameCode, $renameOutput);
        self::assertSame('Renamed Test Bank', $this->decodeJson($renameOutput)['name']);
        [$disableCode, $disableOutput] = $this->runCli('disable-import-profile', (string)$profileId);
        self::assertSame(0, $disableCode, $disableOutput);
        self::assertSame('Disabled', $this->decodeJson($disableOutput)['state']);
        [$enableCode, $enableOutput] = $this->runCli('enable-import-profile', (string)$profileId);
        self::assertSame(0, $enableCode, $enableOutput);
        self::assertSame('Enabled', $this->decodeJson($enableOutput)['state']);

        $unusedTransport = $this->signedTransport();
        $unusedTransport['name'] = 'Unused Profile';
        $unusedTransport['evidence_namespace'] = 'profile:test-cli-unused';
        $unusedPath = $this->writeProfile('unused.json', $unusedTransport);
        [, $unusedSaveOutput] = $this->runCli('save-import-profile', $unusedPath);
        $unusedId = (int)$this->decodeJson($unusedSaveOutput)['saved_profile_id'];
        [$deleteUnusedCode, $deleteUnusedOutput] = $this->runCli('delete-import-profile', (string)$unusedId);
        self::assertSame(0, $deleteUnusedCode, $deleteUnusedOutput);
        self::assertSame($unusedId, $this->decodeJson($deleteUnusedOutput)['deleted_profile_id']);

        $db = new PDO('sqlite:' . $this->database);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec("INSERT INTO accounts (name,account_number,currency) VALUES ('CLI Statement Account','TEST-CLI-ACCOUNT','CURRENCY')");
        $accountId = (int)$db->lastInsertId();
        $stmt = $db->prepare("INSERT INTO statement_imports
            (account_id,provider,original_filename,file_checksum,parser_version,row_count,valid_row_count,problem_count,import_profile_id)
            VALUES (:account_id,'profile:test-cli-signed','synthetic.xlsx',:checksum,2,0,0,0,:profile_id)");
        $stmt->execute([':account_id' => $accountId, ':checksum' => str_repeat('a', 64), ':profile_id' => $profileId]);

        [, $referencedListOutput] = $this->runCli('list-import-profiles');
        $referencedProfiles = $this->decodeJson($referencedListOutput);
        self::assertTrue($referencedProfiles[0]['referenced']);
        self::assertSame('profile:test-cli-signed', $referencedProfiles[0]['evidence_namespace']);

        [$deleteCode, $deleteOutput] = $this->runCli('delete-import-profile', (string)$profileId);
        self::assertSame(1, $deleteCode);
        self::assertStringContainsString('disable', strtolower($deleteOutput));
        self::assertSame(1, (int)$db->query("SELECT COUNT(*) FROM schema_migrations WHERE version='2.19'")->fetchColumn());
        self::assertSame(1, (int)$db->query("SELECT COUNT(*) FROM statement_import_profiles WHERE id=$profileId")->fetchColumn());
        self::assertSame($profileId, (int)$db->query('SELECT import_profile_id FROM statement_imports')->fetchColumn());
    }

    private function runCli(string ...$arguments): array
    {
        $command = 'FIN_DB_FILE=' . escapeshellarg($this->database)
            . ' ' . escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($this->api);
        foreach ($arguments as $argument) $command .= ' ' . escapeshellarg($argument);
        exec($command . ' 2>&1', $lines, $code);
        return [$code, implode("\n", $lines)];
    }

    private function decodeJson(string $output): array
    {
        $decoded = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    private function candidateByRow(array $candidates, int $row): array
    {
        foreach ($candidates as $candidate) if ((int)$candidate['row'] === $row) return $candidate;
        self::fail("Candidate row $row was not found");
    }

    private function writeProfile(string $filename, array $transport): string
    {
        $path = $this->directory . '/' . $filename;
        file_put_contents($path, json_encode($transport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $path;
    }

    private function writeWorkbook(string $filename, array $rows): string
    {
        $path = $this->directory . '/' . $filename;
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
        $archive->addFromString(
            'xl/worksheets/sheet1.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
                . implode('', $xmlRows)
                . '</sheetData></worksheet>'
        );
        unset($archive);
        return $path;
    }

    private function columnName(int $index): string
    {
        $name = '';
        for ($number = $index + 1; $number > 0; $number = intdiv($number - 1, 26)) {
            $name = chr(65 + (($number - 1) % 26)) . $name;
        }
        return $name;
    }

    private function signedTransport(): array
    {
        return [
            'name' => 'Test Bank',
            'evidence_namespace' => 'profile:test-cli-signed',
            'schema_version' => 1,
            'definition' => [
                'signature' => [
                    'container' => 'ooxml',
                    'sheet' => 0,
                    'header' => [
                        ['column' => 0, 'key' => 'bookeddate'],
                        ['column' => 1, 'key' => 'description'],
                        ['column' => 2, 'key' => 'amount'],
                        ['column' => 3, 'key' => 'balance'],
                        ['column' => 4, 'key' => 'accountnumber'],
                        ['column' => 5, 'key' => 'category'],
                        ['column' => 6, 'key' => 'maincategory'],
                    ],
                    'markers' => [],
                ],
                'parser' => [
                    'booking_date' => ['sources' => ['bookeddate']],
                    'value_date' => null,
                    'amount' => ['mode' => 'signed', 'column' => 'amount'],
                    'currency' => ['mode' => 'constant', 'value' => 'DKK'],
                    'description' => ['column' => 'description', 'counterparty_by_sign' => null],
                    'running_balance' => 'balance',
                    'reference' => null,
                    'row_type' => ['columns' => ['category']],
                    'account_number' => ['mode' => 'column_first_nonempty', 'column' => 'accountnumber'],
                    'classification' => [
                        'source' => 'maincategory',
                        'mappings' => [['normalized_equals' => 'income marker', 'classification' => 'income']],
                    ],
                ],
            ],
        ];
    }

    private function feeTransport(): array
    {
        return [
            'name' => 'Test Wallet',
            'evidence_namespace' => 'profile:test-cli-fee',
            'schema_version' => 1,
            'definition' => [
                'signature' => [
                    'container' => 'ooxml',
                    'sheet' => 0,
                    'header' => [
                        ['column' => 0, 'key' => 'type'],
                        ['column' => 1, 'key' => 'startdate'],
                        ['column' => 2, 'key' => 'completeddate'],
                        ['column' => 3, 'key' => 'description'],
                        ['column' => 4, 'key' => 'amount'],
                        ['column' => 5, 'key' => 'fee'],
                        ['column' => 6, 'key' => 'currency'],
                        ['column' => 7, 'key' => 'balance'],
                    ],
                    'markers' => [],
                ],
                'parser' => [
                    'booking_date' => ['sources' => ['completeddate', 'startdate']],
                    'value_date' => ['source' => 'startdate', 'omit_if_same_as_booking' => true],
                    'amount' => ['mode' => 'signed_less_nonnegative_fee', 'amount_column' => 'amount', 'fee_column' => 'fee'],
                    'currency' => ['mode' => 'column', 'column' => 'currency'],
                    'description' => ['column' => 'description', 'counterparty_by_sign' => null],
                    'running_balance' => 'balance',
                    'reference' => null,
                    'row_type' => ['columns' => ['type']],
                    'account_number' => ['mode' => 'none'],
                    'classification' => null,
                ],
            ],
        ];
    }

    private function debitCreditTransport(): array
    {
        return [
            'name' => 'Test Bank B',
            'evidence_namespace' => 'profile:test-cli-debit',
            'schema_version' => 1,
            'definition' => [
                'signature' => [
                    'container' => 'ooxml',
                    'sheet' => 0,
                    'header' => [
                        ['column' => 0, 'key' => 'bookeddate'],
                        ['column' => 1, 'key' => 'valuedate'],
                        ['column' => 2, 'key' => 'direction'],
                        ['column' => 3, 'key' => 'debit'],
                        ['column' => 4, 'key' => 'credit'],
                        ['column' => 5, 'key' => 'currency'],
                        ['column' => 6, 'key' => 'reference'],
                        ['column' => 7, 'key' => 'status'],
                        ['column' => 8, 'key' => 'description'],
                        ['column' => 9, 'key' => 'payee'],
                        ['column' => 10, 'key' => 'payer'],
                    ],
                    'markers' => [],
                ],
                'parser' => [
                    'booking_date' => ['sources' => ['bookeddate']],
                    'value_date' => ['source' => 'valuedate', 'omit_if_same_as_booking' => false],
                    'amount' => ['mode' => 'debit_credit', 'debit_column' => 'debit', 'credit_column' => 'credit'],
                    'currency' => ['mode' => 'column', 'column' => 'currency'],
                    'description' => [
                        'column' => 'description',
                        'counterparty_by_sign' => ['negative' => 'payee', 'positive' => 'payer'],
                    ],
                    'running_balance' => null,
                    'reference' => 'reference',
                    'row_type' => ['columns' => ['direction', 'status']],
                    'account_number' => ['mode' => 'preheader_prefixed_digits', 'prefix' => 'account:', 'minimum_digits' => 6],
                    'classification' => null,
                ],
            ],
        ];
    }
}
