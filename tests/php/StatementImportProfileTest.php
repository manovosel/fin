<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class StatementImportProfileTest extends DatabaseTestCase
{
    public function testCreateRetrieveListRenameEnableDisableAndDeleteUnusedProfile(): void
    {
        $definition = $this->signedDefinition();
        $id = statement_import_profile_create($this->db, [
            'name' => '  Test Bank A  ',
            'evidence_namespace' => 'profile:test-bank-a',
            'schema_version' => 1,
            'definition' => $definition,
        ]);

        $profile = statement_import_profile_get($this->db, $id);
        self::assertSame('Test Bank A', $profile['name']);
        self::assertTrue($profile['enabled']);
        self::assertSame('profile:test-bank-a', $profile['evidence_namespace']);
        self::assertSame($definition, $profile['definition']);
        self::assertSame(statement_import_profile_signature_hash($definition), $profile['signature_hash']);
        self::assertSame([$id], array_column(statement_import_profile_list($this->db), 'id'));

        $disabled = statement_import_profile_set_enabled($this->db, $id, false);
        self::assertFalse($disabled['enabled']);
        $renamed = statement_import_profile_rename($this->db, $id, 'Test Bank Renamed');
        self::assertSame('Test Bank Renamed', $renamed['name']);
        self::assertSame('profile:test-bank-a', $renamed['evidence_namespace']);
        self::assertSame($profile['signature_hash'], $renamed['signature_hash']);
        self::assertTrue(statement_import_profile_set_enabled($this->db, $id, true)['enabled']);

        statement_import_profile_delete($this->db, $id);
        self::assertSame([], statement_import_profile_list($this->db));
    }

    public function testNameAndNamespaceUniquenessAndNamespaceImmutability(): void
    {
        $id = $this->createProfile('Test Wallet', 'profile:test-wallet', $this->feeDefinition());

        $this->assertInvalid(fn() => statement_import_profile_create($this->db, [
            'name' => ' test wallet ',
            'evidence_namespace' => 'profile:other',
            'schema_version' => 1,
            'definition' => $this->signedDefinition(),
        ]));
        $this->assertInvalid(fn() => statement_import_profile_create($this->db, [
            'name' => 'Other Profile',
            'evidence_namespace' => 'profile:test-wallet',
            'schema_version' => 1,
            'definition' => $this->signedDefinition(),
        ]));
        $this->assertInvalid(fn() => statement_import_profile_update_metadata($this->db, $id, [
            'evidence_namespace' => 'profile:changed',
        ]));

        self::assertSame('profile:test-wallet', statement_import_profile_get($this->db, $id)['evidence_namespace']);
    }

    public function testValidClosedSchemaVariantsAndClassificationMapping(): void
    {
        self::assertSame('signed', statement_import_profile_validate_definition($this->signedDefinition())['parser']['amount']['mode']);
        self::assertSame('signed_less_nonnegative_fee', statement_import_profile_validate_definition($this->feeDefinition())['parser']['amount']['mode']);
        self::assertSame('debit_credit', statement_import_profile_validate_definition($this->debitCreditDefinition())['parser']['amount']['mode']);
        $classification = statement_import_profile_validate_definition($this->classifiedDefinition())['parser']['classification'];
        self::assertSame([
            'source' => 'maincategory',
            'mappings' => [['normalized_equals' => 'income-marker', 'classification' => 'income']],
        ], $classification);
    }

    public function testUnsupportedSchemaVersionsAreRejected(): void
    {
        foreach ([-1, 0, 2] as $version) {
            $this->assertInvalid(fn() => statement_import_profile_validate_definition($this->signedDefinition(), $version));
        }
        $this->assertInvalid(fn() => statement_import_profile_create($this->db, [
            'name' => 'Future Profile',
            'evidence_namespace' => 'profile:future',
            'schema_version' => 2,
            'definition' => $this->signedDefinition(),
        ]));
    }

    public function testClosedSchemaRejectsUnknownKeysBadReferencesAndUnsupportedModes(): void
    {
        $cases = [];

        $value = $this->signedDefinition();
        $value['provider'] = 'not-allowed';
        $cases[] = $value;

        $value = $this->signedDefinition();
        $value['parser']['script'] = 'not-allowed';
        $cases[] = $value;

        $value = $this->signedDefinition();
        $value['parser']['amount']['regex'] = 'not-allowed';
        $cases[] = $value;

        $value = $this->signedDefinition();
        $value['parser']['amount']['action'] = 'not-allowed';
        $cases[] = $value;

        $value = $this->signedDefinition();
        $value['parser']['booking_date']['sources'] = ['missing'];
        $cases[] = $value;

        $value = $this->signedDefinition();
        $value['signature']['header'][1]['column'] = 0;
        $cases[] = $value;

        $value = $this->signedDefinition();
        $value['signature']['header'][1]['key'] = 'transactiondate';
        $cases[] = $value;

        $value = $this->signedDefinition();
        $value['parser']['amount'] = ['mode' => 'unknown', 'column' => 'amount'];
        $cases[] = $value;

        $value = $this->signedDefinition();
        $value['parser']['currency'] = ['mode' => 'constant', 'value' => 'dkk'];
        $cases[] = $value;

        $value = $this->signedDefinition();
        $value['parser']['account_number'] = ['mode' => 'unknown'];
        $cases[] = $value;

        $value = $this->signedDefinition();
        $value['signature']['markers'] = [['column' => 'description', 'operator' => 'regex', 'value' => 'marker']];
        $cases[] = $value;

        foreach ($cases as $index => $definition) {
            $this->assertInvalid(fn() => statement_import_profile_validate_definition($definition), "Invalid closed-schema case $index was accepted");
        }
    }

    public function testClassificationCannotExpressOtherClassificationsOrFinancialActions(): void
    {
        $otherClassification = $this->classifiedDefinition();
        $otherClassification['parser']['classification']['mappings'][0]['classification'] = 'expense';
        $this->assertInvalid(fn() => statement_import_profile_validate_definition($otherClassification));

        foreach (['action', 'post_as', 'envelope', 'account_id', 'expression', 'regex'] as $unsupported) {
            $definition = $this->classifiedDefinition();
            $definition['parser']['classification']['mappings'][0][$unsupported] = 'not-allowed';
            $this->assertInvalid(fn() => statement_import_profile_validate_definition($definition));
        }

        $duplicate = $this->classifiedDefinition();
        $duplicate['parser']['classification']['mappings'][] = ['normalized_equals' => 'income-marker', 'classification' => 'income'];
        $this->assertInvalid(fn() => statement_import_profile_validate_definition($duplicate));
    }

    public function testBaseSignatureHashIsDeterministicAndExcludesMetadataParserAndMarkers(): void
    {
        $base = $this->signedDefinition();
        $canonical = statement_import_profile_canonical_signature($base);
        $hash = statement_import_profile_signature_hash($base);
        self::assertSame($hash, statement_import_profile_signature_hash($base));
        self::assertSame(hash('sha256', $canonical), $hash);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);

        $parserChanged = $base;
        $parserChanged['parser']['currency']['value'] = 'EUR';
        self::assertSame($hash, statement_import_profile_signature_hash($parserChanged));

        $markerChanged = $base;
        $markerChanged['signature']['markers'][] = ['column' => 'description', 'operator' => 'contains', 'value' => 'test marker'];
        self::assertSame($hash, statement_import_profile_signature_hash($markerChanged));

        $firstId = $this->createProfile('Test Bank A', 'profile:test-bank-a', $base);
        $secondId = $this->createProfile('Test Bank B', 'profile:test-bank-b', $base);
        self::assertSame(
            statement_import_profile_get($this->db, $firstId)['signature_hash'],
            statement_import_profile_get($this->db, $secondId)['signature_hash']
        );
    }

    public function testHeaderPositionAndKeyChangeBaseSignatureHash(): void
    {
        $base = $this->signedDefinition();
        $positionChanged = $base;
        foreach ($positionChanged['signature']['header'] as &$header) $header['column']++;
        unset($header);
        self::assertNotSame(statement_import_profile_signature_hash($base), statement_import_profile_signature_hash($positionChanged));

        $keyChanged = $base;
        $keyChanged['signature']['header'][0]['key'] = 'posteddate';
        $keyChanged['parser']['booking_date']['sources'] = ['posteddate'];
        self::assertNotSame(statement_import_profile_signature_hash($base), statement_import_profile_signature_hash($keyChanged));
    }

    public function testMigrationPreservesExistingEvidenceReconciliationAndFinancialData(): void
    {
        $this->returnDatabaseToV217();
        [$importId, $rowId] = $this->seedPreProfileEvidence();

        $oldImportColumns = array_column($this->db->query('PRAGMA table_info(statement_imports)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        $oldRowColumns = array_column($this->db->query('PRAGMA table_info(statement_rows)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        self::assertNotContains('import_profile_id', $oldImportColumns);
        self::assertNotContains('classification', $oldRowColumns);
        $beforeEvidence = $this->snapshotTables(['statement_imports', 'statement_rows', 'statement_reconciliations']);
        $financialTables = [
            'months', 'entries', 'transactions', 'accounts', 'account_events', 'account_movements', 'account_balance_observations',
            'month_funding_context', 'envelopes', 'envelope_movements', 'envelope_transfers',
            'recommendation_exclusions', 'envelope_recommendation_settings',
        ];
        $beforeFinancial = $this->snapshotTables($financialTables);

        migrate($this->db);

        self::assertSame('2.23', database_version($this->db));
        self::assertSame(34, (int)$this->db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
        self::assertSame($beforeFinancial, $this->snapshotTables($financialTables));
        $importColumns = implode(',', array_map(static fn(string $column): string => '"' . str_replace('"', '""', $column) . '"', $oldImportColumns));
        self::assertSame($beforeEvidence['statement_imports'], $this->db->query("SELECT $importColumns FROM statement_imports ORDER BY id")->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($beforeEvidence['statement_reconciliations'], $this->db->query('SELECT * FROM statement_reconciliations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));

        $columns = implode(',', array_map(static fn(string $column): string => '"' . str_replace('"', '""', $column) . '"', $oldRowColumns));
        self::assertSame($beforeEvidence['statement_rows'], $this->db->query("SELECT $columns FROM statement_rows ORDER BY id")->fetchAll(PDO::FETCH_ASSOC));
        $row = $this->db->query("SELECT provider,row_fingerprint,classification FROM statement_rows WHERE id=$rowId")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('legacy:test-profile', $row['provider']);
        self::assertSame(str_repeat('b', 64), $row['row_fingerprint']);
        self::assertNull($row['classification']);
        self::assertSame('legacy:test-profile', $this->db->query("SELECT provider FROM statement_imports WHERE id=$importId")->fetchColumn());
        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM statement_reconciliations')->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='statement_import_profiles'")->fetchColumn());
        self::assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='idx_statement_import_profiles_signature'")->fetchColumn());
        self::assertContains('import_profile_id', array_column($this->db->query('PRAGMA table_info(statement_imports)')->fetchAll(PDO::FETCH_ASSOC), 'name'));
        self::assertNull($this->db->query("SELECT import_profile_id FROM statement_imports WHERE id=$importId")->fetchColumn());

        $after = $this->snapshotTables(array_merge(['statement_imports', 'statement_rows', 'statement_reconciliations', 'statement_import_profiles'], $financialTables));
        migrate($this->db);
        self::assertSame($after, $this->snapshotTables(array_keys($after)));
    }

    public function testClassificationDatabaseConstraintAcceptsOnlyNullOrIncome(): void
    {
        [, $rowId] = $this->seedPreProfileEvidence();
        self::assertNull($this->db->query("SELECT classification FROM statement_rows WHERE id=$rowId")->fetchColumn());
        $this->db->exec("UPDATE statement_rows SET classification='income' WHERE id=$rowId");
        self::assertSame('income', $this->db->query("SELECT classification FROM statement_rows WHERE id=$rowId")->fetchColumn());
        $this->db->exec("UPDATE statement_rows SET classification=NULL WHERE id=$rowId");
        self::assertNull($this->db->query("SELECT classification FROM statement_rows WHERE id=$rowId")->fetchColumn());

        $this->expectException(PDOException::class);
        $this->db->exec("UPDATE statement_rows SET classification='expense' WHERE id=$rowId");
    }

    private function signedDefinition(): array
    {
        return [
            'signature' => [
                'container' => 'ooxml',
                'sheet' => 0,
                'header' => [
                    ['column' => 0, 'key' => 'transactiondate'],
                    ['column' => 1, 'key' => 'description'],
                    ['column' => 2, 'key' => 'amount'],
                ],
                'markers' => [],
            ],
            'parser' => [
                'booking_date' => ['sources' => ['transactiondate']],
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

    private function feeDefinition(): array
    {
        $definition = $this->signedDefinition();
        $definition['signature']['header'][] = ['column' => 3, 'key' => 'fee'];
        $definition['parser']['amount'] = ['mode' => 'signed_less_nonnegative_fee', 'amount_column' => 'amount', 'fee_column' => 'fee'];
        return $definition;
    }

    private function debitCreditDefinition(): array
    {
        $definition = $this->signedDefinition();
        $definition['signature']['header'] = [
            ['column' => 0, 'key' => 'transactiondate'],
            ['column' => 1, 'key' => 'description'],
            ['column' => 2, 'key' => 'debit'],
            ['column' => 3, 'key' => 'credit'],
            ['column' => 4, 'key' => 'currency'],
            ['column' => 5, 'key' => 'payee'],
            ['column' => 6, 'key' => 'payer'],
            ['column' => 7, 'key' => 'direction'],
            ['column' => 8, 'key' => 'status'],
            ['column' => 9, 'key' => 'reference'],
        ];
        $definition['parser']['amount'] = ['mode' => 'debit_credit', 'debit_column' => 'debit', 'credit_column' => 'credit'];
        $definition['parser']['currency'] = ['mode' => 'column', 'column' => 'currency'];
        $definition['parser']['description']['counterparty_by_sign'] = ['negative' => 'payee', 'positive' => 'payer'];
        $definition['parser']['reference'] = 'reference';
        $definition['parser']['row_type'] = ['columns' => ['direction', 'status']];
        $definition['parser']['account_number'] = ['mode' => 'preheader_prefixed_digits', 'prefix' => 'account:', 'minimum_digits' => 6];
        return $definition;
    }

    private function classifiedDefinition(): array
    {
        $definition = $this->signedDefinition();
        $definition['signature']['header'][] = ['column' => 3, 'key' => 'maincategory'];
        $definition['parser']['classification'] = [
            'source' => 'maincategory',
            'mappings' => [['normalized_equals' => 'income-marker', 'classification' => 'income']],
        ];
        return $definition;
    }

    private function createProfile(string $name, string $namespace, array $definition): int
    {
        return statement_import_profile_create($this->db, [
            'name' => $name,
            'evidence_namespace' => $namespace,
            'schema_version' => 1,
            'definition' => $definition,
        ]);
    }

    private function assertInvalid(callable $operation, string $message = 'Invalid Import Profile data was accepted'): void
    {
        try {
            $operation();
            self::fail($message);
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    private function returnDatabaseToV217(): void
    {
        $this->db->exec('PRAGMA foreign_keys = OFF');
        $this->db->exec('ALTER TABLE statement_imports DROP COLUMN import_profile_id');
        $this->db->exec('DROP TABLE statement_import_profiles');
        $this->db->exec('ALTER TABLE statement_rows DROP COLUMN classification');
        $this->db->exec("DELETE FROM schema_migrations WHERE version IN ('2.18','2.19','2.20')");
        $this->db->exec('PRAGMA foreign_keys = ON');
        self::assertSame('2.23', database_version($this->db));
    }

    private function seedPreProfileEvidence(): array
    {
        $accountId = save_account_definition($this->db, [
            'name' => 'Test Bank',
            'account_number' => 'TEST-ACCOUNT-1',
            'currency' => 'CURRENCY',
        ]);
        $monthId = $this->createMonth(2026, 8);
        $envelopeId = $this->createEnvelope('Envelope A');
        $transactionId = save_transaction($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'payment_account_id' => $accountId,
            'amount' => 25,
            'spent_date' => '2026-08-03',
            'note' => 'Existing financial record',
        ]);
        $insertImport = $this->db->prepare("INSERT INTO statement_imports
            (account_id,provider,original_filename,file_checksum,statement_from,statement_to,parser_version,row_count,valid_row_count,problem_count)
            VALUES (:account_id,'legacy:test-profile','statement.xlsx',:checksum,'2026-08-03','2026-08-03','2',1,1,0)");
        $insertImport->execute([':account_id' => $accountId, ':checksum' => str_repeat('a', 64)]);
        $importId = (int)$this->db->lastInsertId();
        $insertRow = $this->db->prepare("INSERT INTO statement_rows
            (import_id,account_id,provider,source_row_number,booking_date,amount,currency,description_original,description_normalized,row_fingerprint)
            VALUES (:import_id,:account_id,'legacy:test-profile',2,'2026-08-03',-25,'DKK','Existing evidence','existing evidence',:fingerprint)");
        $insertRow->execute([':import_id' => $importId, ':account_id' => $accountId, ':fingerprint' => str_repeat('b', 64)]);
        $rowId = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO statement_reconciliations (statement_row_id,transaction_id) VALUES (:row_id,:transaction_id)')
            ->execute([':row_id' => $rowId, ':transaction_id' => $transactionId]);
        return [$importId, $rowId];
    }

    private function snapshotTables(array $tables): array
    {
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = $this->db->query("SELECT * FROM \"$table\" ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
        }
        return $snapshot;
    }
}
