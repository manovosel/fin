<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';
require_once __DIR__ . '/Fixtures/SyntheticStatementGenerator.php';

use Tests\Php\Fixtures\SyntheticStatementGenerator;

final class StatementImportProfileProvenanceTest extends DatabaseTestCase
{
    public function testHistoricalContextResolutionAndReferencedProfileLifecycle(): void
    {
        $accountId = $this->account('Statement Account');
        $fallbackId = $this->profile('Fallback Profile', 'legacy:fallback');
        $directId = $this->profile('Direct Profile', 'profile:direct');
        $otherFallbackId = $this->profile('Other Fallback', 'legacy:other');
        $unusedId = $this->profile('Unused Profile', 'profile:unused');

        $oldImportId = $this->insertImport($accountId, 'legacy:fallback');
        $unmatchedImportId = $this->insertImport($accountId, 'legacy:missing');
        $directImportId = $this->insertImport($accountId, 'legacy:other', $directId);
        statement_import_profile_set_enabled($this->db, $fallbackId, false);

        self::assertNull($this->db->query("SELECT import_profile_id FROM statement_imports WHERE id=$oldImportId")->fetchColumn());
        self::assertSame('legacy:fallback', $this->db->query("SELECT provider FROM statement_imports WHERE id=$oldImportId")->fetchColumn());
        $fallbackContext = statement_import_profile_context($this->db, $oldImportId);
        self::assertSame($fallbackId, $fallbackContext['id']);
        self::assertFalse($fallbackContext['enabled']);
        self::assertNull(statement_import_profile_context($this->db, $unmatchedImportId));
        self::assertSame($directId, statement_import_profile_context($this->db, $directImportId)['id'], 'FK context must win over namespace fallback');
        self::assertNotSame($otherFallbackId, statement_import_profile_context($this->db, $directImportId)['id']);

        statement_import_profile_set_enabled($this->db, $directId, false);
        statement_import_profile_rename($this->db, $directId, 'Renamed Direct Profile');
        $resolved = statement_import_profile_context($this->db, $directImportId);
        self::assertSame($directId, $resolved['id']);
        self::assertSame('Renamed Direct Profile', $resolved['name']);
        self::assertFalse($resolved['enabled']);
        self::assertSame('profile:direct', $resolved['evidence_namespace']);
        self::assertSame($directId, (int)$this->db->query("SELECT import_profile_id FROM statement_imports WHERE id=$directImportId")->fetchColumn());

        try {
            statement_import_profile_delete($this->db, $directId);
            self::fail('A referenced Import Profile was deleted');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('referenced', $error->getMessage());
        }
        self::assertSame($directId, statement_import_profile_context($this->db, $directImportId)['id']);

        statement_import_profile_delete($this->db, $unusedId);
        self::assertNotContains($unusedId, array_column(statement_import_profile_list($this->db), 'id'));
    }

    public function testMigrationFromV218PreservesEvidenceReconciliationAndFinancialData(): void
    {
        $db = self::newDatabase();
        migrate($db);
        $db->exec('PRAGMA foreign_keys = OFF');
        $db->exec('ALTER TABLE statement_imports DROP COLUMN import_profile_id');
        $db->exec("DELETE FROM schema_migrations WHERE version IN ('2.19','2.20')");
        $db->exec('PRAGMA foreign_keys = ON');
        self::assertSame('2.23', database_version($db));

        $profileId = statement_import_profile_create($db, [
            'name' => 'Historical Profile',
            'evidence_namespace' => 'legacy:preserved',
            'schema_version' => 1,
            'definition' => $this->minimalDefinition(),
        ]);
        $accountId = save_account_definition($db, [
            'name' => 'Historical Account',
            'account_number' => 'TEST-HISTORICAL-ACCOUNT',
            'currency' => 'CURRENCY',
        ]);
        $monthId = save_month($db, ['year' => 2026, 'month' => 8, 'exchange_rate' => 7.46, 'salary' => 30000]);
        $envelopeId = save_envelope($db, ['name' => 'Historical Envelope', 'role' => 'daily', 'currency' => 'CURRENCY']);
        $transactionId = save_transaction($db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'payment_account_id' => $accountId,
            'amount' => 25,
            'spent_date' => '2026-08-03',
            'note' => 'Preserved financial record',
        ]);
        $checksum = str_repeat('a', 64);
        $fingerprint = str_repeat('b', 64);
        $db->prepare("INSERT INTO statement_imports
            (account_id,provider,original_filename,file_checksum,statement_from,statement_to,parser_version,row_count,valid_row_count,problem_count)
            VALUES (:account_id,'legacy:preserved','historical.xlsx',:checksum,'2026-08-03','2026-08-03','2',1,1,0)")
            ->execute([':account_id' => $accountId, ':checksum' => $checksum]);
        $importId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO statement_rows
            (import_id,account_id,provider,source_row_number,booking_date,amount,currency,description_original,description_normalized,row_fingerprint,classification)
            VALUES (:import_id,:account_id,'legacy:preserved',2,'2026-08-03',-25,'DKK','Preserved evidence','preserved evidence',:fingerprint,'income')")
            ->execute([':import_id' => $importId, ':account_id' => $accountId, ':fingerprint' => $fingerprint]);
        $rowId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO statement_reconciliations (statement_row_id,transaction_id) VALUES (:row_id,:transaction_id)')
            ->execute([':row_id' => $rowId, ':transaction_id' => $transactionId]);

        $oldImportColumns = array_column($db->query('PRAGMA table_info(statement_imports)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        $oldRowColumns = array_column($db->query('PRAGMA table_info(statement_rows)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        self::assertNotContains('import_profile_id', $oldImportColumns);
        $beforeImports = $this->selectColumns($db, 'statement_imports', $oldImportColumns);
        $beforeRows = $this->selectColumns($db, 'statement_rows', $oldRowColumns);
        $beforeLinks = $db->query('SELECT * FROM statement_reconciliations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $financialTables = [
            'months', 'entries', 'transactions', 'accounts', 'account_events', 'account_movements', 'account_balance_observations',
            'month_funding_context', 'envelopes', 'envelope_movements', 'envelope_transfers',
            'recommendation_exclusions', 'envelope_recommendation_settings',
        ];
        $beforeFinancial = $this->snapshotTables($db, $financialTables);

        migrate($db);

        self::assertSame('2.23', database_version($db));
        self::assertSame(34, (int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
        self::assertSame($beforeImports, $this->selectColumns($db, 'statement_imports', $oldImportColumns));
        self::assertSame($beforeRows, $this->selectColumns($db, 'statement_rows', $oldRowColumns));
        self::assertSame($beforeLinks, $db->query('SELECT * FROM statement_reconciliations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($beforeFinancial, $this->snapshotTables($db, $financialTables));
        self::assertNull($db->query("SELECT import_profile_id FROM statement_imports WHERE id=$importId")->fetchColumn());
        self::assertSame('legacy:preserved', $db->query("SELECT provider FROM statement_imports WHERE id=$importId")->fetchColumn());
        self::assertSame('legacy:preserved', $db->query("SELECT provider FROM statement_rows WHERE id=$rowId")->fetchColumn());
        self::assertSame($fingerprint, $db->query("SELECT row_fingerprint FROM statement_rows WHERE id=$rowId")->fetchColumn());
        self::assertSame('income', $db->query("SELECT classification FROM statement_rows WHERE id=$rowId")->fetchColumn());
        self::assertSame($profileId, statement_import_profile_context($db, $importId)['id']);
        $this->assertProfileForeignKey($db);

        $after = $this->snapshotTables($db, array_merge(
            ['schema_migrations', 'statement_import_profiles', 'statement_imports', 'statement_rows', 'statement_reconciliations'],
            $financialTables
        ));
        migrate($db);
        self::assertSame($after, $this->snapshotTables($db, array_keys($after)));
    }



    public function testExactFileDuplicateIdentityRemainsAccountNamespaceAndChecksum(): void
    {
        $indexes = $this->db->query("PRAGMA index_list('statement_imports')")->fetchAll(PDO::FETCH_ASSOC);
        $uniqueColumnSets = [];
        foreach ($indexes as $index) {
            if ((int)$index['unique'] !== 1) continue;
            $name = str_replace("'", "''", (string)$index['name']);
            $uniqueColumnSets[] = array_column($this->db->query("PRAGMA index_info('$name')")->fetchAll(PDO::FETCH_ASSOC), 'name');
        }
        self::assertContains(['account_id', 'provider', 'file_checksum'], $uniqueColumnSets);

        $accountId = 73;
        $currentNamespace = SyntheticStatementGenerator::FORMAT_SIGNED;
        $profile = ['evidence_namespace' => $currentNamespace];
        $contents = 'self-owned synthetic statement bytes';
        $checksum = hash('sha256', $contents);
        $currentTuple = [$accountId, $currentNamespace, $checksum];
        $profileTuple = [$accountId, $profile['evidence_namespace'], $checksum];
        self::assertSame($currentTuple, $profileTuple);
        self::assertSame(
            statement_file_identity($accountId, $currentNamespace, $contents),
            statement_file_identity($accountId, $profile['evidence_namespace'], $contents)
        );
    }

    private function account(string $name): int
    {
        return save_account_definition($this->db, [
            'name' => $name,
            'account_number' => 'TEST-' . strtoupper(str_replace(' ', '-', $name)),
            'currency' => 'CURRENCY',
        ]);
    }

    private function profile(string $name, string $namespace): int
    {
        return statement_import_profile_create($this->db, [
            'name' => $name,
            'evidence_namespace' => $namespace,
            'schema_version' => 1,
            'definition' => $this->minimalDefinition(),
        ]);
    }

    private function insertImport(int $accountId, string $namespace, ?int $profileId = null): int
    {
        $stmt = $this->db->prepare('INSERT INTO statement_imports
            (account_id,provider,original_filename,file_checksum,parser_version,row_count,valid_row_count,problem_count,import_profile_id)
            VALUES (:account_id,:provider,:filename,:checksum,2,0,0,0,:profile_id)');
        $token = bin2hex(random_bytes(8));
        $stmt->execute([
            ':account_id' => $accountId,
            ':provider' => $namespace,
            ':filename' => "synthetic-$token.xlsx",
            ':checksum' => hash('sha256', $token),
            ':profile_id' => $profileId,
        ]);
        return (int)$this->db->lastInsertId();
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






    private function selectColumns(PDO $db, string $table, array $columns): array
    {
        $quoted = implode(',', array_map(static fn(string $column): string => '"' . str_replace('"', '""', $column) . '"', $columns));
        return $db->query("SELECT $quoted FROM \"$table\" ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }

    private function snapshotTables(PDO $db, array $tables): array
    {
        $snapshot = [];
        foreach ($tables as $table) $snapshot[$table] = $db->query("SELECT * FROM \"$table\" ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
        return $snapshot;
    }

    private function assertProfileForeignKey(PDO $db): void
    {
        $foreignKeys = $db->query("PRAGMA foreign_key_list('statement_imports')")->fetchAll(PDO::FETCH_ASSOC);
        $matches = array_values(array_filter($foreignKeys, static fn(array $fk): bool => $fk['from'] === 'import_profile_id'));
        self::assertCount(1, $matches);
        self::assertSame('statement_import_profiles', $matches[0]['table']);
        self::assertSame('id', $matches[0]['to']);
        self::assertSame('RESTRICT', strtoupper((string)$matches[0]['on_delete']));
    }
}
