<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';
require_once __DIR__ . '/Fixtures/SyntheticStatementGenerator.php';

use Tests\Php\Fixtures\SyntheticStatementGenerator;

/**
 * Frozen historical baseline for the generic Import Profile path.
 *
 * Every literal in this file was captured by running the CURRENT LEGACY
 * statement parser over the same synthetic workbooks and copying its output
 * verbatim. The generic path then reproduced every captured value and every
 * captured row fingerprint byte for byte under the same evidence namespace.
 *
 * This test asserts the generic path against those literals only. It never
 * calls the legacy detector or the legacy parsers, so it keeps proving the
 * historical equivalence after the legacy machinery is removed.
 *
 * Vocabulary is neutral: structural format names, opaque evidence namespaces
 * and synthetic Account/description values. The foreign-language header
 * strings are kept verbatim because they describe the file format, not an
 * institution.
 */
final class StatementImportProfileBaselineTest extends DatabaseTestCase
{
    /** Deterministic Account id so the frozen row fingerprints apply verbatim. */
    private const ACCOUNT_ID = 42;

    private const SIGNED_BASELINE = [
        'namespace' => 'profile:test-bank',
        'file_account_number' => 'TEST-SIGNED-0001',
        'rows' => [
            [
                'source_row_number' => 2,
                'booking_date' => '2026-01-10',
                'value_date' => null,
                'amount' => -100.0,
                'currency' => 'DKK',
                'description_original' => 'Synthetic everyday expense',
                'description_normalized' => 'synthetic everyday expense',
                'provider_reference' => null,
                'running_balance' => 900.0,
                'provider_row_type' => '',
                'parse_problem' => '',
                'identity_detail' => '46032',
                'identity_amount' => -100.0,
                'occurrence_index' => 0,
                'row_fingerprint' => '1667fe92646a05777e2ecb8ad0d5cab86fe4b36510828075a90a2a817f7b4d7b',
            ],
            [
                'source_row_number' => 3,
                'booking_date' => '2026-01-11',
                'value_date' => null,
                'amount' => 250.0,
                'currency' => 'DKK',
                'description_original' => 'Synthetic everyday credit',
                'description_normalized' => 'synthetic everyday credit',
                'provider_reference' => null,
                'running_balance' => 1150.0,
                'provider_row_type' => '',
                'parse_problem' => '',
                'identity_detail' => '46033',
                'identity_amount' => 250.0,
                'occurrence_index' => 0,
                'row_fingerprint' => 'acab5ffed2a78125036a868b5b65666046986828b86c8b4014392e6f85adad29',
            ],
        ],
    ];

    private const CARD_VARIANT_BASELINE = [
        'namespace' => 'profile:test-card',
        'file_account_number' => 'TEST-CARD-0001',
        'rows' => [
            [
                'source_row_number' => 2,
                'booking_date' => '2026-02-15',
                'value_date' => null,
                'amount' => -150.5,
                'currency' => 'DKK',
                'description_original' => 'Synthetic card expense',
                'description_normalized' => 'synthetic card expense',
                'provider_reference' => null,
                'running_balance' => 849.5,
                'provider_row_type' => '',
                'parse_problem' => '',
                'identity_detail' => '46068',
                'identity_amount' => -150.5,
                'occurrence_index' => 0,
                'row_fingerprint' => '03c2d693125cc1bd3e0cfc5cb204b9a32730edb415480f637f0c46f9b6800b7c',
            ],
        ],
    ];

    private const FEE_BASELINE = [
        'namespace' => 'profile:test-wallet',
        'file_account_number' => '',
        'rows' => [
            [
                'source_row_number' => 2,
                'booking_date' => '2026-03-05',
                'value_date' => null,
                'amount' => -102.5,
                'currency' => 'DKK',
                'description_original' => 'Synthetic fee expense',
                'description_normalized' => 'synthetic fee expense',
                'provider_reference' => null,
                'running_balance' => 897.5,
                'provider_row_type' => 'TRANSFER',
                'parse_problem' => '',
                'identity_detail' => '05.03.2026 12:00:00|05.03.2026 12:00:00',
                'identity_amount' => -100.0,
                'occurrence_index' => 0,
                'row_fingerprint' => '99af85562816d7d354418664a512646e86d75dc38ff7bb771072f4a6d4051c2a',
            ],
            [
                'source_row_number' => 3,
                'booking_date' => '2026-03-06',
                'value_date' => null,
                'amount' => -9.99,
                'currency' => 'DKK',
                'description_original' => 'Synthetic fee only charge',
                'description_normalized' => 'synthetic fee only charge',
                'provider_reference' => null,
                'running_balance' => 887.51,
                'provider_row_type' => 'TRANSFER',
                'parse_problem' => '',
                'identity_detail' => '06.03.2026 12:00:00|06.03.2026 12:00:00',
                'identity_amount' => 0.0,
                'occurrence_index' => 0,
                'row_fingerprint' => '4c5d53e8610e51d6692d3de4fbb8aa1c7a8036bcaf55428a7d49353565c17389',
            ],
        ],
    ];

    private const DEBIT_CREDIT_BASELINE = [
        'namespace' => 'profile:test-foreign-bank',
        'file_account_number' => '0000123456',
        'rows' => [
            [
                'source_row_number' => 3,
                'booking_date' => '2026-04-10',
                'value_date' => '2026-04-10',
                'amount' => -23.0,
                'currency' => 'EUR',
                'description_original' => 'Synthetic cross-border payment · Synthetic Recipient',
                'description_normalized' => 'synthetic cross-border payment · synthetic recipient',
                'provider_reference' => '100000',
                'running_balance' => null,
                'provider_row_type' => 'Isplata · Provedeno',
                'parse_problem' => '',
                'identity_detail' => '10.04.2026|10.04.2026',
                'identity_amount' => -23.0,
                'occurrence_index' => 0,
                'row_fingerprint' => '13d0bb9322bf8cba841af596adab885bf0932294e21077b474dd9264e4323a8f',
            ],
        ],
    ];

    private const TRAILING_BLANK_HEADER_BASELINE = [
        'namespace' => 'profile:test-foreign-bank',
        'file_account_number' => '0000123456',
        'rows' => [
            [
                'source_row_number' => 3,
                'booking_date' => '2026-04-01',
                'value_date' => '2026-04-01',
                'amount' => -10.0,
                'currency' => 'EUR',
                'description_original' => 'Synthetic valid row · Synthetic Recipient',
                'description_normalized' => 'synthetic valid row · synthetic recipient',
                'provider_reference' => 'TEST-VALID',
                'running_balance' => null,
                'provider_row_type' => 'Isplata · Provedeno',
                'parse_problem' => '',
                'identity_detail' => '01.04.2026|01.04.2026',
                'identity_amount' => -10.0,
                'occurrence_index' => 0,
                'row_fingerprint' => '8546b9385b94c9d8c46aee7e0702b4d7029c4dbf2df04107058226db23867a39',
            ],
            [
                'source_row_number' => 4,
                'booking_date' => null,
                'value_date' => '2026-04-02',
                'amount' => -20.0,
                'currency' => 'EUR',
                'description_original' => 'Synthetic malformed row · Synthetic Recipient',
                'description_normalized' => 'synthetic malformed row · synthetic recipient',
                'provider_reference' => 'TEST-MALFORMED',
                'running_balance' => null,
                'provider_row_type' => 'Isplata · Provedeno',
                'parse_problem' => 'Invalid booking date',
                'identity_detail' => 'not-a-date|02.04.2026',
                'identity_amount' => -20.0,
                'occurrence_index' => 0,
                'row_fingerprint' => '632209fa49a75e46d865e5aebcbde0a807f791e27bf0bb2f760911ebc31f2668',
            ],
        ],
    ];

    private string $temporaryDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionNeutralImportProfiles();
        $this->temporaryDirectory = sys_get_temp_dir() . '/fin-baseline-' . bin2hex(random_bytes(6));
        if (!mkdir($this->temporaryDirectory, 0700, true) && !is_dir($this->temporaryDirectory)) {
            throw new RuntimeException('Could not create the synthetic statement directory');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporaryDirectory . '/*') ?: [] as $path) @unlink($path);
        @rmdir($this->temporaryDirectory);
        parent::tearDown();
    }

    public function testSignedFormatBaselineMatchesFrozenExpectations(): void
    {
        $parsed = $this->parseSynthetic(SyntheticStatementGenerator::FORMAT_SIGNED, 'signed-source', [
            ['booking_date' => '2026-01-10', 'amount' => -100.0, 'description' => 'Synthetic everyday expense', 'running_balance' => 900.0],
            ['booking_date' => '2026-01-11', 'amount' => 250.0, 'description' => 'Synthetic everyday credit', 'running_balance' => 1150.0],
        ], ['account_number' => 'TEST-SIGNED-0001', 'account_name' => 'Everyday Account']);

        self::assertSame('2026-01-10', $parsed['statement_from']);
        self::assertSame('2026-01-11', $parsed['statement_to']);
        $this->assertBaseline(self::SIGNED_BASELINE, $parsed);
    }

    public function testCardVariantFormatBaselineMatchesFrozenExpectations(): void
    {
        $parsed = $this->parseSynthetic(SyntheticStatementGenerator::FORMAT_SIGNED_CARD, 'card-source', [
            ['booking_date' => '2026-02-15', 'amount' => -150.5, 'description' => 'Synthetic card expense', 'running_balance' => 849.5],
        ], ['account_number' => 'TEST-CARD-0001', 'account_name' => 'Synthetic Card Account']);

        $this->assertBaseline(self::CARD_VARIANT_BASELINE, $parsed);
    }

    public function testFeeFormatBaselineMatchesFrozenExpectations(): void
    {
        $parsed = $this->parseSynthetic(SyntheticStatementGenerator::FORMAT_FEE, 'fee-source', [
            ['booking_date' => '2026-03-05', 'amount' => -100.0, 'description' => 'Synthetic fee expense', 'running_balance' => 897.5, 'fee' => 2.5, 'currency' => 'DKK'],
            ['booking_date' => '2026-03-06', 'amount' => 0.0, 'description' => 'Synthetic fee only charge', 'running_balance' => 887.51, 'fee' => 9.99, 'currency' => 'DKK'],
        ], ['sheet_currency' => 'DKK']);

        $this->assertBaseline(self::FEE_BASELINE, $parsed);
    }

    public function testDebitCreditFormatBaselineMatchesFrozenExpectations(): void
    {
        $parsed = $this->parseSynthetic(SyntheticStatementGenerator::FORMAT_DEBIT_CREDIT, 'debit-credit-source', [
            ['booking_date' => '2026-04-10', 'amount' => -23.0, 'description' => 'Synthetic cross-border payment', 'currency' => 'EUR', 'direction' => 'Isplata', 'counterparty' => 'Synthetic Recipient'],
        ], ['account_number' => '0000123456', 'sheet_currency' => 'EUR']);

        $this->assertBaseline(self::DEBIT_CREDIT_BASELINE, $parsed);
    }

    public function testTrailingBlankHeaderBaselineMatchesFrozenExpectations(): void
    {
        $parsed = $this->parseWorkbook('synthetic-debit-credit.xls', $this->trailingBlankHeaderWorkbook());

        // The unaddressable empty trailing header cell must not reach the row
        // identity: the generic row carries no empty raw-field key at all.
        foreach ($parsed['rows'] as $index => $row) {
            self::assertArrayNotHasKey('', $row['raw_fields'], "row $index must not carry an empty raw-field key");
            self::assertSame(self::TRAILING_BLANK_HEADER_BASELINE['rows'][$index]['parse_problem'], $row['parse_problem']);
        }

        $this->assertBaseline(self::TRAILING_BLANK_HEADER_BASELINE, $parsed);
    }

    public function testFeeFormatIdentityKeepsParserV1FingerprintStability(): void
    {
        $parsed = $this->parseSynthetic(SyntheticStatementGenerator::FORMAT_FEE, 'fee-source', [
            ['booking_date' => '2026-03-05', 'amount' => -100.0, 'description' => 'Synthetic fee expense', 'running_balance' => 897.5, 'fee' => 2.5, 'currency' => 'DKK'],
            ['booking_date' => '2026-03-06', 'amount' => 0.0, 'description' => 'Synthetic fee only charge', 'running_balance' => 887.51, 'fee' => 9.99, 'currency' => 'DKK'],
        ], ['sheet_currency' => 'DKK']);

        // The fee-only row keeps the parser-v1 identity amount (the raw primary
        // amount, 0.00) instead of the newly derived effective amount (-9.99),
        // so an overlapping export cannot duplicate existing evidence.
        $feeOnlyRow = $parsed['rows'][1];
        self::assertSame(-9.99, (float)$feeOnlyRow['amount']);
        self::assertSame(0.0, (float)$feeOnlyRow['identity_amount']);

        // The v1 identity base, rebuilt by hand from the frozen row fields.
        $v1Base = hash('sha256', json_encode([
            self::ACCOUNT_ID,
            self::FEE_BASELINE['namespace'],
            $feeOnlyRow['booking_date'],
            $feeOnlyRow['value_date'],
            number_format(0.0, 8, '.', ''),
            $feeOnlyRow['currency'],
            $feeOnlyRow['description_normalized'],
            $feeOnlyRow['provider_reference'],
            $feeOnlyRow['identity_detail'],
            null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        self::assertSame(
            hash('sha256', $v1Base . "\n0"),
            self::FEE_BASELINE['rows'][1]['row_fingerprint'],
            'The fee-only row must keep the parser-v1 fingerprint identity'
        );
    }

    public function testHistoricalNamespaceFallbackResolvesRetainedProfileForPreProfileImport(): void
    {
        $profileId = statement_import_profile_create($this->db, [
            'name' => 'Historical Evidence Profile',
            'evidence_namespace' => 'evidence:legacy-alpha',
            'schema_version' => 1,
            'definition' => $this->minimalDefinition(),
        ]);
        $directId = statement_import_profile_create($this->db, [
            'name' => 'Direct Evidence Profile',
            'evidence_namespace' => 'evidence:legacy-beta',
            'schema_version' => 1,
            'definition' => $this->minimalDefinition(),
        ]);

        $historicalAccountId = $this->insertAccount(101, 'Account A', 'TEST-HISTORICAL-A');
        $unmatchedAccountId = $this->insertAccount(102, 'Account B', 'TEST-HISTORICAL-B');
        $directAccountId = $this->insertAccount(103, 'Account C', 'TEST-HISTORICAL-C');

        $historicalImportId = $this->insertHistoricalImport('evidence:legacy-alpha', null, $historicalAccountId);
        $unmatchedImportId = $this->insertHistoricalImport('evidence:unmatched', null, $unmatchedAccountId);
        $directImportId = $this->insertHistoricalImport('evidence:legacy-beta', $directId, $directAccountId);

        $context = statement_import_profile_context($this->db, $historicalImportId);
        self::assertNotNull($context, 'A pre-profile import must resolve its profile through the namespace fallback');
        self::assertSame($profileId, (int)$context['id']);
        self::assertSame('evidence:legacy-alpha', (string)$context['evidence_namespace']);

        self::assertNull(
            statement_import_profile_context($this->db, $unmatchedImportId),
            'An unknown namespace must leave the evidence without profile context'
        );

        self::assertSame(
            $directId,
            (int)statement_import_profile_context($this->db, $directImportId)['id'],
            'The import provenance must win over the namespace fallback'
        );
    }

    public function testHistoricalNamespaceRecognisesExistingFileAndRows(): void
    {
        $accountId = $this->insertAccount(self::ACCOUNT_ID, 'Account A', 'TEST-SIGNED-0001');

        $rows = [
            ['booking_date' => '2026-01-10', 'amount' => -100.0, 'description' => 'Synthetic everyday expense', 'running_balance' => 900.0],
            ['booking_date' => '2026-01-11', 'amount' => 250.0, 'description' => 'Synthetic everyday credit', 'running_balance' => 1150.0],
        ];
        $metadata = ['account_number' => 'TEST-SIGNED-0001', 'account_name' => 'Everyday Account'];
        $path = SyntheticStatementGenerator::build(
            SyntheticStatementGenerator::FORMAT_SIGNED,
            $this->temporaryDirectory,
            'historical-source',
            $rows,
            $metadata
        );
        $filename = basename($path);
        $contents = (string)file_get_contents($path);

        $parsed = $this->parseWorkbook($filename, $contents);
        $identified = statement_assign_profile_identities($accountId, (string)$parsed['evidence_namespace'], $parsed['rows']);

        // The historical evidence rows are exactly the frozen baseline rows.
        foreach (self::SIGNED_BASELINE['rows'] as $index => $expected) {
            self::assertSame($expected['row_fingerprint'], (string)$identified[$index]['row_fingerprint']);
        }

        // Store the same evidence as a PRE-PROFILE historical import that used
        // the opaque namespace as its provider value.
        $namespace = (string)$parsed['evidence_namespace'];
        $historicalImportId = $this->insertHistoricalImport($namespace, null, $accountId, hash('sha256', $contents));
        foreach ($parsed['rows'] as $index => $row) {
            $this->insertHistoricalRow($historicalImportId, $accountId, $namespace, $row, (string)$identified[$index]['row_fingerprint']);
        }

        // The exact same file is recognised as the historical import.
        $preview = preview_statement_import($this->db, $accountId, $filename, $contents);
        self::assertSame($historicalImportId, (int)$preview['existing_import_id']);
        self::assertSame(2, (int)$preview['known_rows']);

        // An overlapping export with a different checksum still recognises the
        // historical row by its evidence namespace and row fingerprint.
        $overlapPath = SyntheticStatementGenerator::build(
            SyntheticStatementGenerator::FORMAT_SIGNED,
            $this->temporaryDirectory,
            'overlap-source',
            [
                $rows[0],
                ['booking_date' => '2026-01-12', 'amount' => -12.0, 'description' => 'Synthetic everyday overlap row', 'running_balance' => 1138.0],
            ],
            $metadata
        );
        $overlapContents = (string)file_get_contents($overlapPath);
        $overlapPreview = preview_statement_import($this->db, $accountId, basename($overlapPath), $overlapContents);
        self::assertNull($overlapPreview['existing_import_id']);
        self::assertSame(2, (int)$overlapPreview['rows_found']);
        self::assertSame(1, (int)$overlapPreview['known_rows']);
        self::assertSame(1, (int)$overlapPreview['new_rows']);

        $result = import_statement_evidence($this->db, $accountId, basename($overlapPath), $overlapContents);
        self::assertTrue((bool)$result['ok']);
        self::assertSame(3, (int)$this->db->query('SELECT COUNT(*) FROM statement_rows')->fetchColumn());
        $historicalRowCount = $this->db->prepare('SELECT COUNT(*) FROM statement_rows WHERE import_id=:id');
        $historicalRowCount->execute([':id' => $historicalImportId]);
        self::assertSame(2, (int)$historicalRowCount->fetchColumn());
    }

    /**
     * Parse a synthetic workbook through the generic Import Profile path and
     * compute its row identities under the matched profile's namespace.
     */
    private function parseSynthetic(string $provider, string $basename, array $rows, array $metadata): array
    {
        $path = SyntheticStatementGenerator::build($provider, $this->temporaryDirectory, $basename, $rows, $metadata);
        return $this->parseWorkbook(basename($path), (string)file_get_contents($path));
    }

    private function parseWorkbook(string $filename, string $contents): array
    {
        $parsed = statement_parse_file_with_profile($this->db, $filename, $contents);
        $parsed['identified_rows'] = statement_assign_profile_identities(
            self::ACCOUNT_ID,
            (string)$parsed['evidence_namespace'],
            $parsed['rows']
        );
        return $parsed;
    }

    private function assertBaseline(array $baseline, array $parsed): void
    {
        self::assertSame($baseline['namespace'], (string)$parsed['evidence_namespace']);
        self::assertSame($baseline['file_account_number'], (string)($parsed['file_account_number'] ?? ''));
        self::assertCount(count($baseline['rows']), $parsed['rows']);
        foreach ($baseline['rows'] as $index => $expected) {
            $row = $parsed['rows'][$index];
            foreach ([
                'source_row_number', 'booking_date', 'value_date', 'amount', 'currency',
                'description_original', 'description_normalized', 'provider_reference',
                'running_balance', 'provider_row_type', 'parse_problem', 'identity_detail',
            ] as $field) {
                self::assertSame($expected[$field], $row[$field] ?? null, "row $index field $field");
            }
            self::assertSame($expected['identity_amount'], $row['identity_amount'] ?? null, "row $index identity_amount");
            self::assertSame($expected['occurrence_index'], (int)($parsed['identified_rows'][$index]['occurrence_index'] ?? -1), "row $index occurrence_index");
            self::assertSame($expected['row_fingerprint'], (string)($parsed['identified_rows'][$index]['row_fingerprint'] ?? ''), "row $index row_fingerprint");
        }
    }

    private function insertAccount(int $id, string $name, string $accountNumber): int
    {
        $this->db->prepare('INSERT INTO accounts (id,name,account_number,currency,active) VALUES (:id,:name,:number,:currency,1)')
            ->execute([
                ':id' => $id,
                ':name' => $name,
                ':number' => $accountNumber,
                ':currency' => 'CURRENCY',
            ]);
        return $id;
    }

    private function insertHistoricalImport(string $namespace, ?int $profileId, int $accountId, ?string $checksum = null): int
    {
        $checksum ??= hash('sha256', 'historical-' . bin2hex(random_bytes(8)));
        $stmt = $this->db->prepare('INSERT INTO statement_imports
            (account_id,provider,original_filename,file_checksum,parser_version,row_count,valid_row_count,problem_count,import_profile_id)
            VALUES (:account_id,:provider,:filename,:checksum,:parser_version,1,1,0,:profile_id)');
        $stmt->execute([
            ':account_id' => $accountId,
            ':provider' => $namespace,
            ':filename' => 'historical-' . bin2hex(random_bytes(6)) . '.xlsx',
            ':checksum' => $checksum,
            ':parser_version' => STATEMENT_PARSER_VERSION,
            ':profile_id' => $profileId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function insertHistoricalRow(int $importId, int $accountId, string $namespace, array $row, string $fingerprint): void
    {
        $stmt = $this->db->prepare('INSERT INTO statement_rows
            (import_id,account_id,provider,source_row_number,booking_date,value_date,amount,currency,description_original,description_normalized,provider_reference,running_balance,provider_row_type,row_fingerprint,occurrence_index,parse_problem,raw_fields_json)
            VALUES (:import_id,:account_id,:provider,:source_row,:booking_date,:value_date,:amount,:currency,:description_original,:description_normalized,:provider_reference,:running_balance,:provider_row_type,:fingerprint,0,:parse_problem,:raw)');
        $stmt->execute([
            ':import_id' => $importId,
            ':account_id' => $accountId,
            ':provider' => $namespace,
            ':source_row' => (int)$row['source_row_number'],
            ':booking_date' => $row['booking_date'],
            ':value_date' => $row['value_date'],
            ':amount' => $row['amount'],
            ':currency' => $row['currency'],
            ':description_original' => $row['description_original'],
            ':description_normalized' => $row['description_normalized'],
            ':provider_reference' => $row['provider_reference'],
            ':running_balance' => $row['running_balance'],
            ':provider_row_type' => $row['provider_row_type'],
            ':fingerprint' => $fingerprint,
            ':parse_problem' => $row['parse_problem'],
            ':raw' => json_encode($row['raw_fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function minimalDefinition(): array
    {
        return [
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
        ];
    }

    /**
     * Debit/credit shaped workbook whose header row carries one extra, empty
     * trailing column: the unaddressable artefact a real export can contain.
     */
    private function trailingBlankHeaderWorkbook(): string
    {
        $headerRow = [
            'Datum knjiženja', 'Datum valute', 'Uplata/Isplata', 'Iznos isplate',
            'Iznos uplate', 'Valuta', 'Broj transakcije', 'Status', 'IBAN platitelja',
            'Naziv platitelja', 'Adresa platitelja', 'Sjedište platitelja',
            'Poziv na broj platitelja', 'Stvarni dužnik', 'IBAN primatelja',
            'Naziv primatelja', 'Adresa primatelja', 'Sjedište primatelja',
            'Poziv na broj primatelja', 'Opis plaćanja', 'Krajnji primatelj',
            'Šifra namjene', 'Originalni iznos isplate', 'Originalni iznos uplate',
            'Originalna valuta', 'Tečaj', '',
        ];
        $validDataRow = [
            '01.04.2026', '01.04.2026', 'Isplata', '10,00', '', 'EUR',
            'TEST-VALID', 'Provedeno', '', '', '', '', '', '', '', 'Synthetic Recipient',
            '', '', '', 'Synthetic valid row', '', '', '', '', '', '', '',
        ];
        $malformedDataRow = [
            'not-a-date', '02.04.2026', 'Isplata', '20,00', '', 'EUR',
            'TEST-MALFORMED', 'Provedeno', '', '', '', '', '', '', '', 'Synthetic Recipient',
            '', '', '', 'Synthetic malformed row', '', '', '', '', '', '', '',
        ];
        $rows = [
            ['Synthetic transactions', 'Račun: 0000123456'],
            $headerRow,
            $validDataRow,
            $malformedDataRow,
        ];
        $xmlRows = [];
        foreach ($rows as $rowIndex => $row) {
            $cells = [];
            foreach ($row as $columnIndex => $value) {
                $reference = $this->spreadsheetColumnName($columnIndex) . ($rowIndex + 1);
                $escaped = htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $cells[] = '<c r="' . $reference . '" t="inlineStr"><is><t>' . $escaped . '</t></is></c>';
            }
            $xmlRows[] = '<row r="' . ($rowIndex + 1) . '">' . implode('', $cells) . '</row>';
        }
        $path = $this->temporaryDirectory . '/trailing-blank-header.zip';
        $archive = new PharData($path, 0, null, Phar::ZIP);
        $archive->addFromString(
            'xl/worksheets/sheet1.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
                . implode('', $xmlRows)
                . '</sheetData></worksheet>'
        );
        unset($archive);
        $contents = (string)file_get_contents($path);
        @unlink($path);
        return $contents;
    }

    private function spreadsheetColumnName(int $index): string
    {
        $name = '';
        for ($number = $index + 1; $number > 0; $number = intdiv($number - 1, 26)) {
            $name = chr(65 + (($number - 1) % 26)) . $name;
        }
        return $name;
    }
}
