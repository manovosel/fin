<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

/**
 * Focused structural-matcher tests for the saved Import Profile matcher.
 *
 * All test profiles use neutral invented labels and synthetic OOXML bytes; no
 * real evidence and no commercial display names enter Git through this test.
 */
final class StatementImportProfileMatcherTest extends DatabaseTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/fin-matcher-' . bin2hex(random_bytes(6));
        if (!mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            self::fail('Could not create matcher test directory');
        }
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) return;
        foreach (glob($this->directory . '/*') ?: [] as $file) @unlink($file);
        @rmdir($this->directory);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Synthetic OOXML builders
    // ------------------------------------------------------------------

    /**
     * Build a minimal inline-string OOXML workbook from a 2-D cell array.
     * First row is treated as the header; remaining rows are data.
     */
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

    /**
     * Neutral ordinary-shape signed profile with one bounded marker.
     */
    private function ordinaryProfileDefinition(string $markerColumn, string $markerValue, string $markerOperator): array
    {
        return [
            'signature' => [
                'container' => 'ooxml',
                'sheet' => 0,
                'header' => [
                    ['column' => 0, 'key' => 'bookeddate'],
                    ['column' => 1, 'key' => 'description'],
                    ['column' => 2, 'key' => 'amount'],
                    ['column' => 3, 'key' => 'balance'],
                    ['column' => 4, 'key' => 'accountnumber'],
                    ['column' => 5, 'key' => 'accountname'],
                    ['column' => 6, 'key' => 'category'],
                ],
                'markers' => [[
                    'column' => $markerColumn,
                    'operator' => $markerOperator,
                    'value' => $markerValue,
                ]],
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
                'account_number' => ['mode' => 'none'],
                'classification' => null,
            ],
        ];
    }

    /**
     * Neutral signed profile with zero markers.
     */
    private function noMarkerProfileDefinition(): array
    {
        return [
            'signature' => [
                'container' => 'ooxml',
                'sheet' => 0,
                'header' => [
                    ['column' => 0, 'key' => 'bookeddate'],
                    ['column' => 1, 'key' => 'description'],
                    ['column' => 2, 'key' => 'amount'],
                ],
                'markers' => [],
            ],
            'parser' => [
                'booking_date' => ['sources' => ['bookeddate']],
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
     * Build the worksheet rows for an ordinary-shaped workbook with a
     * configurable `accountname` cell on every data row.
     */
    private function ordinaryRows(string $accountName, int $rowCount = 5): array
    {
        $rows = [
            ['Booked Date', 'Description', 'Amount', 'Balance', 'Account Number', 'Account Name', 'Category'],
        ];
        for ($i = 0; $i < $rowCount; $i++) {
            $rows[] = [
                '2026-01-' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT),
                'Synthetic ordinary row ' . ($i + 1),
                '-10.00',
                '990.00',
                'TEST-NEUTRAL-001',
                $accountName,
                'Envelope A',
            ];
        }
        return $rows;
    }

    private function saveProfile(string $name, string $namespace, array $definition, bool $enabled = true): int
    {
        $this->db->prepare('INSERT INTO statement_import_profiles
            (name,enabled,evidence_namespace,schema_version,signature_hash,definition_json)
            VALUES (:name,:enabled,:namespace,:schema_version,:signature_hash,:definition_json)')
            ->execute([
                ':name' => $name,
                ':enabled' => $enabled ? 1 : 0,
                ':namespace' => $namespace,
                ':schema_version' => 1,
                ':signature_hash' => statement_import_profile_signature_hash($definition),
                ':definition_json' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
        return (int)$this->db->lastInsertId();
    }

    // ------------------------------------------------------------------
    // Matcher-focused tests
    // ------------------------------------------------------------------

    public function testUniqueSignatureYieldsExactlyOneMatch(): void
    {
        $definition = $this->noMarkerProfileDefinition();
        $this->saveProfile('Test Bank A', 'profile:test-bank-a', $definition);

        $workbook = $this->writeWorkbook('match.xlsx', [
            ['Booked Date', 'Description', 'Amount'],
            ['2026-01-01', 'Synthetic row 1', '-100.00'],
            ['2026-01-02', 'Synthetic row 2', '50.00'],
        ]);

        $result = statement_import_profile_match_file($this->db, $workbook);
        self::assertSame('matched', $result['status']);
        self::assertSame('Test Bank A', $result['profile']['name']);
        self::assertSame(0, $result['matched_header_row']);
        self::assertSame(statement_import_profile_signature_hash($definition), $result['signature_hash']);
    }

    public function testZeroMatchForUnknownHeader(): void
    {
        $this->saveProfile('Test Bank A', 'profile:test-bank-a', $this->noMarkerProfileDefinition());

        $workbook = $this->writeWorkbook('mismatch.xlsx', [
            ['Date', 'Memo', 'Credit', 'Debit'],
            ['2026-01-01', 'Unknown format', '100.00', ''],
        ]);

        $result = statement_import_profile_match_file($this->db, $workbook);
        self::assertSame('unknown', $result['status']);
        self::assertSame('no_matching_profile', $result['reason']);
    }

    public function testZeroMatchWhenWorkbookHasNoHeaderCandidate(): void
    {
        $this->saveProfile('Test Bank A', 'profile:test-bank-a', $this->noMarkerProfileDefinition());

        $workbook = $this->writeWorkbook('empty.xlsx', [
            ['', '', ''],
            ['', '', ''],
        ]);

        $result = statement_import_profile_match_file($this->db, $workbook);
        self::assertSame('unknown', $result['status']);
        self::assertSame('no_header_candidate', $result['reason']);
    }

    public function testDisabledProfileIsNeverSelected(): void
    {
        $definition = $this->noMarkerProfileDefinition();
        $this->saveProfile('Test Bank A', 'profile:test-bank-a', $definition, false);

        $workbook = $this->writeWorkbook('workbook.xlsx', [
            ['Booked Date', 'Description', 'Amount'],
            ['2026-01-01', 'Synthetic row 1', '-100.00'],
        ]);

        $result = statement_import_profile_match_file($this->db, $workbook);
        self::assertSame('unknown', $result['status']);
        self::assertSame('no_matching_profile', $result['reason']);

        // Re-enable the profile; matcher should now select it.
        statement_import_profile_set_enabled($this->db, 1, true);
        $result = statement_import_profile_match_file($this->db, $workbook);
        self::assertSame('matched', $result['status']);
        self::assertSame(1, $result['profile']['id']);
    }

    public function testComplementaryMarkersSelectOnlyCorrectVariant(): void
    {
        $ordinaryDefinition = $this->ordinaryProfileDefinition('accountname', 'card', 'not_contains');
        $cardDefinition = $this->ordinaryProfileDefinition('accountname', 'card', 'contains');
        self::assertSame(
            statement_import_profile_signature_hash($ordinaryDefinition),
            statement_import_profile_signature_hash($cardDefinition),
            'Two profiles with identical base signature must share the same hash'
        );

        $this->saveProfile('Test Bank', 'profile:test-bank', $ordinaryDefinition);
        $this->saveProfile('Test Card', 'profile:test-card', $cardDefinition);

        $ordinaryWorkbook = $this->writeWorkbook('ordinary.xlsx', $this->ordinaryRows('Privatkonto'));
        $cardWorkbook = $this->writeWorkbook('card.xlsx', $this->ordinaryRows('Synthetic Card Account'));

        $ordinaryMatch = statement_import_profile_match_file($this->db, $ordinaryWorkbook);
        self::assertSame('matched', $ordinaryMatch['status']);
        self::assertSame('Test Bank', $ordinaryMatch['profile']['name']);

        $cardMatch = statement_import_profile_match_file($this->db, $cardWorkbook);
        self::assertSame('matched', $cardMatch['status']);
        self::assertSame('Test Card', $cardMatch['profile']['name']);
    }

    public function testNoMarkersOnSameBaseSignatureIsAmbiguous(): void
    {
        $base = $this->noMarkerProfileDefinition();
        $clone = $base;
        $this->saveProfile('Profile Alpha', 'profile:test-alpha', $base);
        $this->saveProfile('Profile Beta', 'profile:test-beta', $clone);

        $workbook = $this->writeWorkbook('workbook.xlsx', [
            ['Booked Date', 'Description', 'Amount'],
            ['2026-01-01', 'Synthetic row 1', '-100.00'],
            ['2026-01-02', 'Synthetic row 2', '50.00'],
        ]);

        $result = statement_import_profile_match_file($this->db, $workbook);
        self::assertSame('ambiguous', $result['status']);
        self::assertCount(2, $result['candidates']);
        $ids = array_map(static fn($c) => (int)$c['id'], $result['candidates']);
        sort($ids);
        self::assertSame([1, 2], $ids);
    }

    public function testAbsentPositiveMarkerYieldsZeroMatch(): void
    {
        $this->saveProfile(
            'Test Bank',
            'profile:test-bank-marker-mismatch',
            $this->ordinaryProfileDefinition('accountname', 'card-token', 'contains')
        );

        $workbook = $this->writeWorkbook('ordinary.xlsx', $this->ordinaryRows('Privatkonto'));
        $result = statement_import_profile_match_file($this->db, $workbook);
        self::assertSame('unknown', $result['status']);
        self::assertSame('no_matching_profile', $result['reason']);
    }

    public function testAllOfMultipleMarkersMustMatch(): void
    {
        $definition = [
            'signature' => [
                'container' => 'ooxml',
                'sheet' => 0,
                'header' => [
                    ['column' => 0, 'key' => 'bookeddate'],
                    ['column' => 1, 'key' => 'description'],
                    ['column' => 2, 'key' => 'amount'],
                    ['column' => 3, 'key' => 'balance'],
                    ['column' => 4, 'key' => 'accountnumber'],
                    ['column' => 5, 'key' => 'accountname'],
                ],
                'markers' => [
                    ['column' => 'accountname', 'operator' => 'contains', 'value' => 'alpha'],
                    ['column' => 'description', 'operator' => 'not_contains', 'value' => 'forbidden'],
                ],
            ],
            'parser' => [
                'booking_date' => ['sources' => ['bookeddate']],
                'value_date' => null,
                'amount' => ['mode' => 'signed', 'column' => 'amount'],
                'currency' => ['mode' => 'constant', 'value' => 'DKK'],
                'description' => ['column' => 'description', 'counterparty_by_sign' => null],
                'running_balance' => 'balance',
                'reference' => null,
                'row_type' => null,
                'account_number' => ['mode' => 'none'],
                'classification' => null,
            ],
        ];
        $this->saveProfile('Test Multi Marker', 'profile:test-multi-marker', $definition);

        // Both markers satisfied: contains alpha, description does not contain forbidden.
        $pass = $this->writeWorkbook('pass.xlsx', [
            ['Booked Date', 'Description', 'Amount', 'Balance', 'Account Number', 'Account Name'],
            ['2026-01-01', 'Synthetic row alpha pass', '-10.00', '990.00', 'TEST-001', 'Alpha Account'],
            ['2026-01-02', 'Synthetic row alpha pass 2', '5.00', '995.00', '', 'Alpha Account'],
        ]);

        $fail = $this->writeWorkbook('fail.xlsx', [
            ['Booked Date', 'Description', 'Amount', 'Balance', 'Account Number', 'Account Name'],
            ['2026-01-01', 'Synthetic row forbidden', '-10.00', '990.00', 'TEST-001', 'Alpha Account'],
        ]);

        $matched = statement_import_profile_match_file($this->db, $pass);
        self::assertSame('matched', $matched['status']);
        self::assertSame('Test Multi Marker', $matched['profile']['name']);

        $rejected = statement_import_profile_match_file($this->db, $fail);
        self::assertSame('unknown', $rejected['status']);
        self::assertSame('no_matching_profile', $rejected['reason']);
    }

    public function testHeaderDriftYieldsZeroMatch(): void
    {
        $this->saveProfile('Test Bank', 'profile:test-bank-drift', $this->noMarkerProfileDefinition());

        // Renamed column.
        $renamed = $this->writeWorkbook('renamed.xlsx', [
            ['Transaction Date', 'Description', 'Amount'],
            ['2026-01-01', 'Synthetic row 1', '-10.00'],
        ]);
        self::assertSame('unknown', statement_import_profile_match_file($this->db, $renamed)['status']);

        // Shifted column (header column 0 instead of 1).
        $shifted = $this->writeWorkbook('shifted.xlsx', [
            ['Amount', 'Booked Date', 'Description'],
            ['-10.00', '2026-01-01', 'Synthetic row 1'],
        ]);
        self::assertSame('unknown', statement_import_profile_match_file($this->db, $shifted)['status']);
    }

    public function testStoredHashMismatchIsRejectedEvenWhenRawVectorMatches(): void
    {
        $definition = $this->noMarkerProfileDefinition();
        $this->saveProfile('Test Bank', 'profile:test-bank-corruption', $definition);

        // Directly corrupt the stored signature_hash so the saved-profile
        // decode layer refuses it; matcher must fail-safe and skip it.
        $this->db->exec("UPDATE statement_import_profiles SET signature_hash='0000000000000000000000000000000000000000000000000000000000000000' WHERE id=1");

        $workbook = $this->writeWorkbook('workbook.xlsx', [
            ['Booked Date', 'Description', 'Amount'],
            ['2026-01-01', 'Synthetic row 1', '-10.00'],
        ]);

        $result = statement_import_profile_match_file($this->db, $workbook);
        self::assertSame('unknown', $result['status']);
        self::assertSame('no_matching_profile', $result['reason']);

        // Confirm the saved profile remains on disk; corruption must not delete.
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM statement_import_profiles')->fetchColumn());
    }

    public function testInvalidStoredProfileReturnsErrorAndDoesNotSilentlySelectValidAlternative(): void
    {
        // Two enabled profiles sharing one candidate base signature. They have
        // identical header / marker sets so their stored signature hashes are
        // equal; only their namespaces, names, and IDs differ.
        $definition = $this->noMarkerProfileDefinition();
        $validId = $this->saveProfile('Test Bank Valid', 'profile:test-bank-valid', $definition);
        $corruptId = $this->saveProfile('Test Bank Corrupt', 'profile:test-bank-corrupt', $definition);
        self::assertSame(2, (int)$this->db->query('SELECT COUNT(*) FROM statement_import_profiles')->fetchColumn());
        self::assertSame(
            (string)$this->db->query('SELECT signature_hash FROM statement_import_profiles WHERE id=' . $validId)->fetchColumn(),
            (string)$this->db->query('SELECT signature_hash FROM statement_import_profiles WHERE id=' . $corruptId)->fetchColumn(),
            'Both profiles must share the same base signature hash for this test'
        );

        // Deliberately corrupt the second profile's stored definition JSON in a
        // way the normal model validation pipeline would prohibit. The stored
        // signature_hash column is left intact so the SQL hash lookup still
        // returns the row; the corruption only surfaces inside the decode step.
        $this->db->exec("UPDATE statement_import_profiles SET definition_json='not-valid-json' WHERE id=$corruptId");

        $workbook = $this->writeWorkbook('workbook.xlsx', [
            ['Booked Date', 'Description', 'Amount'],
            ['2026-01-01', 'Synthetic row 1', '-10.00'],
            ['2026-01-02', 'Synthetic row 2', '5.00'],
            ['2026-01-03', 'Synthetic row 3', '-7.50'],
        ]);

        $result = statement_import_profile_match_file($this->db, $workbook);

        // The matcher must surface an administrative error, NOT silently fall
        // through to the valid alternative profile.
        self::assertSame('error', $result['status']);
        self::assertSame('invalid_import_profile', $result['reason']);
        self::assertSame($corruptId, (int)$result['profile_id']);
        self::assertSame('Test Bank Corrupt', $result['profile_name']);
        self::assertArrayNotHasKey('profile', $result);
        self::assertArrayNotHasKey('candidates', $result);
        self::assertArrayNotHasKey('signature_hash', $result);

        // Corruption must not delete or alter the saved profile rows.
        self::assertSame(2, (int)$this->db->query('SELECT COUNT(*) FROM statement_import_profiles')->fetchColumn());

        // Restore the corrupted profile so it can be cleanly disabled, then
        // verify the remaining valid profile is selected.
        $this->db->exec('UPDATE statement_import_profiles SET definition_json=' . $this->db->quote(json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) . " WHERE id=$corruptId");
        statement_import_profile_set_enabled($this->db, $corruptId, false);
        $repaired = statement_import_profile_match_file($this->db, $workbook);
        self::assertSame('matched', $repaired['status']);
        self::assertSame($validId, (int)$repaired['profile']['id']);
        self::assertSame('Test Bank Valid', $repaired['profile']['name']);
    }

    public function testInvalidStoredSchemaVersionFailureAlsoReturnsErrorNotSilentSelection(): void
    {
        // Two enabled profiles sharing one candidate base signature.
        $definition = $this->noMarkerProfileDefinition();
        $this->saveProfile('Test Bank Valid Two', 'profile:test-bank-valid-two', $definition);
        $corruptId = $this->saveProfile('Test Bank Corrupt Two', 'profile:test-bank-corrupt-two', $definition);

        // Corrupt the schema_version to a value the validator rejects. The
        // stored hash stays equal to the candidate hash so the SQL lookup
        // returns the row; the corruption surfaces during decode.
        $this->db->exec("UPDATE statement_import_profiles SET schema_version=999 WHERE id=$corruptId");

        $workbook = $this->writeWorkbook('workbook.xlsx', [
            ['Booked Date', 'Description', 'Amount'],
            ['2026-01-01', 'Synthetic row 1', '-10.00'],
        ]);

        $result = statement_import_profile_match_file($this->db, $workbook);
        self::assertSame('error', $result['status']);
        self::assertSame('invalid_import_profile', $result['reason']);
        self::assertSame($corruptId, (int)$result['profile_id']);
        self::assertArrayNotHasKey('profile', $result);
    }

    public function testDisabledInvalidProfileIsIgnoredNotSelectedNotReported(): void
    {
        // A disabled profile is excluded from candidate lookup entirely; an
        // invalid stored definition on a disabled profile must not surface as
        // an administrative error.
        $definition = $this->noMarkerProfileDefinition();
        $validId = $this->saveProfile('Test Bank Valid Four', 'profile:test-bank-valid-four', $definition);
        $disabledId = $this->saveProfile('Test Bank Corrupt Four', 'profile:test-bank-corrupt-four', $definition, false);
        $this->db->exec("UPDATE statement_import_profiles SET definition_json='not-valid-json' WHERE id=$disabledId");

        $workbook = $this->writeWorkbook('workbook.xlsx', [
            ['Booked Date', 'Description', 'Amount'],
            ['2026-01-01', 'Synthetic row 1', '-10.00'],
        ]);

        $result = statement_import_profile_match_file($this->db, $workbook);
        self::assertSame('matched', $result['status']);
        self::assertSame($validId, (int)$result['profile']['id']);
        self::assertSame('Test Bank Valid Four', $result['profile']['name']);
    }

    public function testMatchingIsAccountIndependent(): void
    {
        $definition = $this->noMarkerProfileDefinition();
        $this->saveProfile('Test Bank', 'profile:test-bank-account', $definition);

        $a = $this->writeWorkbook('a.xlsx', [
            ['Booked Date', 'Description', 'Amount'],
            ['2026-01-01', 'Synthetic row 1', '-10.00'],
            ['2026-01-02', 'Synthetic row 2', '50.00'],
            ['2026-01-03', 'Synthetic row 3', '-5.00'],
        ]);
        $b = $this->writeWorkbook('b.xlsx', [
            ['Booked Date', 'Description', 'Amount'],
            ['2026-02-01', 'Different account-number value', '-100.00'],
            ['2026-02-02', 'No account-number at all', '25.00'],
        ]);

        $matchA = statement_import_profile_match_file($this->db, $a);
        $matchB = statement_import_profile_match_file($this->db, $b);
        self::assertSame('matched', $matchA['status']);
        self::assertSame('matched', $matchB['status']);
        self::assertSame($matchA['profile']['id'], $matchB['profile']['id']);
    }

    public function testMatcherOutputDrivesGenericParserInteroperability(): void
    {
        $definition = $this->ordinaryProfileDefinition('accountname', 'card-token', 'not_contains');
        $this->saveProfile('Test Bank', 'profile:test-bank-interop', $definition);

        $workbook = $this->writeWorkbook('interop.xlsx', [
            ['Booked Date', 'Description', 'Amount', 'Balance', 'Account Number', 'Account Name', 'Category'],
            ['2026-01-01', 'Synthetic interoperability row 1', '-10.00', '990.00', 'TEST-ACCOUNT-NEUTRAL', 'Privatkonto', 'Envelope A'],
            ['2026-01-02', 'Synthetic interoperability row 2', '20.00', '1010.00', '', 'Privatkonto', 'Income'],
        ]);

        $match = statement_import_profile_match_file($this->db, $workbook);
        self::assertSame('matched', $match['status']);
        $profileDefinition = $match['profile']['definition'];
        $matchedHeaderRow = $match['matched_header_row'];

        // Hand the profile definition to the existing generic parser.
        $rows = statement_xlsx_rows((string)file_get_contents($workbook));
        $parsed = statement_parse_rows_with_profile($rows, $profileDefinition);
        self::assertSame($matchedHeaderRow, $parsed['header_row_index']);
        self::assertGreaterThan(0, count($parsed['rows']));
        $firstRow = $parsed['rows'][0];
        self::assertSame(2, (int)$firstRow['source_row_number']);
        self::assertSame('2026-01-01', $firstRow['booking_date']);
        self::assertSame(-10.0, (float)$firstRow['amount']);
        self::assertSame('DKK', $firstRow['currency']);
        self::assertSame('', $firstRow['parse_problem']);
    }

    public function testMatcherResultCarriesAllInformationStage5BNeeds(): void
    {
        $definition = $this->ordinaryProfileDefinition('accountname', 'card-token', 'not_contains');
        $savedId = $this->saveProfile('Test Bank', 'profile:test-bank-bridge', $definition);

        $workbook = $this->writeWorkbook('bridge.xlsx', $this->ordinaryRows('Privatkonto'));
        $result = statement_import_profile_match_file($this->db, $workbook);

        self::assertSame('matched', $result['status']);
        self::assertSame($savedId, $result['profile']['id']);
        self::assertArrayHasKey('definition', $result['profile']);
        self::assertArrayHasKey('evidence_namespace', $result['profile']);
        self::assertArrayHasKey('matched_header_row', $result);
        self::assertArrayHasKey('signature_hash', $result);
        self::assertSame(statement_import_profile_signature_hash($definition), $result['signature_hash']);
    }
}
