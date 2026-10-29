<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class MigrationTest extends DatabaseTestCase
{
    public function testFreshDatabaseMigratesToCurrentCanonicalSchema(): void
    {
        self::assertSame('2.23', database_version($this->db));
        self::assertSame(34, (int)$this->db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
        self::assertSame('DKK', database_currency($this->db));

        $expected = [
            'months' => ['exchange_rate', 'salary'],
            'entries' => ['amount'],
            'transactions' => ['amount'],
            'account_events' => ['amount'],
            'account_movements' => ['amount'],
            'account_balance_observations' => ['account_id', 'effective_date', 'balance', 'source', 'note', 'updated_at'],
            'envelope_movements' => ['amount'],
            'envelope_transfers' => ['amount'],
            'statement_imports' => ['import_profile_id'],
            'statement_rows' => ['amount', 'currency', 'classification'],
        ];
        foreach ($expected as $table => $required) {
            $columns = array_column($this->db->query("PRAGMA table_info($table)")->fetchAll(), 'name');
            foreach ($required as $column) self::assertContains($column, $columns, "$table.$column must exist");
        }
        foreach (['entries', 'transactions'] as $table) {
            $columns = array_column($this->db->query("PRAGMA table_info($table)")->fetchAll(), 'name');
            self::assertNotContains('amount_eur', $columns);
            self::assertNotContains('currency_original', $columns);
        }
        $accountColumns = array_column($this->db->query('PRAGMA table_info(accounts)')->fetchAll(), 'name');
        self::assertNotContains('type', $accountColumns);
        self::assertNotContains('ledger_start_date', $accountColumns);
        self::assertNotContains('account', array_column($this->db->query('PRAGMA table_info(envelopes)')->fetchAll(), 'name'));
        self::assertNotContains('account', array_column($this->db->query('PRAGMA table_info(entries)')->fetchAll(), 'name'));
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='month_account_transfers'")->fetchColumn());
        $monthColumns = array_column($this->db->query('PRAGMA table_info(months)')->fetchAll(), null, 'name');
        self::assertSame(0, (int)$monthColumns['exchange_rate']['notnull']);
        self::assertNull($monthColumns['exchange_rate']['dflt_value']);

        $expectedIndexes = [
            'accounts' => ['idx_accounts_name_normalized', 'idx_accounts_account_number'],
            'envelopes' => ['idx_envelopes_name_normalized', 'idx_envelopes_default_account'],
            'entries' => ['idx_entries_month_sort', 'idx_entries_envelope', 'idx_entries_bs_payment_account'],
            'transactions' => ['idx_transactions_month_date', 'idx_transactions_envelope', 'idx_transactions_payment_account', 'idx_transactions_entry_automatic'],
            'envelope_transfers' => ['idx_envelope_transfers_date', 'idx_envelope_transfers_source_account', 'idx_envelope_transfers_destination_account'],
            'statement_import_profiles' => ['idx_statement_import_profiles_name', 'idx_statement_import_profiles_signature'],
            'account_balance_observations' => ['idx_account_balance_observations_account_date'],
        ];
        foreach ($expectedIndexes as $table => $indexes) {
            $actual = $this->db->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name=" . $this->db->quote($table))->fetchAll(PDO::FETCH_COLUMN);
            foreach ($indexes as $index) self::assertContains($index, $actual, "$table.$index must exist");
        }
        $accountsSql = (string)$this->db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='accounts'")->fetchColumn();
        $envelopesSql = (string)$this->db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='envelopes'")->fetchColumn();
        $transactionsSql = (string)$this->db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='transactions'")->fetchColumn();
        self::assertStringContainsString("CHECK(currency IN ('CURRENCY','HELPER_CURRENCY'))", $accountsSql);
        self::assertStringContainsString("CHECK(currency IN ('CURRENCY','HELPER_CURRENCY'))", $envelopesSql);
        self::assertStringContainsString("CHECK(kind IN ('expense','refund'))", $transactionsSql);
        $this->assertForeignKey('envelopes', 'default_account_id', 'accounts', 'SET NULL');
        $this->assertForeignKey('entries', 'month_id', 'months', 'CASCADE');
        $this->assertForeignKey('entries', 'bs_payment_account_id', 'accounts', 'SET NULL');
        $this->assertForeignKey('transactions', 'month_id', 'months', 'SET NULL');
        $this->assertForeignKey('transactions', 'entry_id', 'entries', 'SET NULL');
        $this->assertForeignKey('transactions', 'payment_account_id', 'accounts', 'SET NULL');
        $this->assertForeignKey('envelope_transfers', 'source_account_id', 'accounts', 'SET NULL');
        $this->assertForeignKey('envelope_transfers', 'destination_account_id', 'accounts', 'SET NULL');
        $this->assertForeignKey('statement_imports', 'import_profile_id', 'statement_import_profiles', 'RESTRICT');
        self::assertFalse($this->db->query('PRAGMA foreign_key_check')->fetch());
    }

    public function testMigrationIsIdempotent(): void
    {
        $monthId = $this->createMonth();
        $envelopeId = $this->createEnvelope();
        $this->saveAllocation($monthId, $envelopeId, 1000, 'paid');
        $before = $this->snapshot($this->db);

        migrate($this->db);

        self::assertSame($before, $this->snapshot($this->db));
        self::assertSame(34, (int)$this->db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
    }

    public function testLegacyAllocationsStillNormalizeThroughTheCurrentMigrationChain(): void
    {
        $db = self::newDatabase();
        $this->createLegacySchema($db);
        $db->exec("INSERT INTO months (id,year,month,exchange_rate_eur_dkk) VALUES (1,2026,1,7.5)");
        $db->exec("INSERT INTO entries (month_id,type,name,category,amount_dkk,amount_eur,currency_original,account,note,status,due_date,sort_order) VALUES
            (1,'bill','Envelope A','Living',100,13.33,'DKK','Test account','first row','paid','2026-01-05',10),
            (1,'expense','Envelope A','Living',200,26.67,'DKK','Test account','second row','planned','2026-01-10',20),
            (1,'expense','Fuel','Living',300,40,'EUR','Test account','7 d\nKeep this note','planned',NULL,30)");
        $totalBefore = (float)$db->query('SELECT SUM(amount_dkk) FROM entries')->fetchColumn();

        migrate($db);

        self::assertSame('2.23', database_version($db));
        self::assertEqualsWithDelta($totalBefore, (float)$db->query('SELECT SUM(amount) FROM entries')->fetchColumn(), 0.001);
        self::assertSame(2, (int)$db->query('SELECT COUNT(*) FROM entries')->fetchColumn());
        self::assertSame(0, (int)$db->query("SELECT COUNT(*) FROM entries WHERE type<>'income' AND (envelope_id IS NULL OR category<>'Envelope')")->fetchColumn());
        self::assertSame(0, (int)$db->query("SELECT COUNT(*) FROM (SELECT 1 FROM entries GROUP BY month_id,envelope_id HAVING COUNT(*)>1)")->fetchColumn());
        self::assertEqualsWithDelta(100, (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM envelope_movements WHERE type='funding'")->fetchColumn(), 0.001);
        self::assertSame('Keep this note', $db->query("SELECT note FROM entries WHERE name='Fuel'")->fetchColumn());
        self::assertSame('HELPER_CURRENCY', $db->query("SELECT currency FROM envelopes WHERE name='Fuel'")->fetchColumn());
    }

    public function testV20BackfillsLegacyExpenseHelperRepresentationFromItsMonthRate(): void
    {
        $db = self::newDatabase();
        $this->migrateThroughVersion($db, '1.9');
        $db->exec("INSERT INTO months (id,year,month,exchange_rate_eur_dkk) VALUES (1,2026,8,7.5)");
        $db->exec("INSERT INTO transactions (id,month_id,envelope,amount_dkk,note,spent_date) VALUES (2,1,'Envelope A',150,'Legacy expense','2026-08-05')");

        migrate_to_v2($db);

        $transaction = $db->query('SELECT id,amount_dkk,amount_eur,currency_original,note FROM transactions WHERE id=2')->fetch();
        self::assertSame(['id' => 2, 'amount_dkk' => 150.0, 'amount_eur' => 20.0, 'currency_original' => 'DKK', 'note' => 'Legacy expense'], $transaction);
    }

    public function testV21RepairsPaidBsFundingAndSynchronizesExpenseMovement(): void
    {
        $db = self::newDatabase();
        $this->migrateThroughVersion($db, '2.0');
        $db->exec("INSERT INTO months (id,year,month,exchange_rate_eur_dkk) VALUES (1,2026,8,7.5)");
        $db->exec("INSERT INTO envelopes (id,name,account,role,currency) VALUES (2,'Envelope E','Test account','reserved','DKK')");
        $db->exec("INSERT INTO entries (id,month_id,envelope_id,type,name,category,amount_dkk,amount_eur,currency_original,account,status,due_date,is_bs,updated_at) VALUES (3,1,2,'expense','Envelope E','Envelope',3973,529.73,'DKK','Test account','paid','2026-09-01',1,'2026-08-11 10:05:41')");
        $db->exec("INSERT INTO transactions (id,month_id,entry_id,envelope_id,envelope,amount_dkk,amount_eur,currency_original,note,spent_date,is_automatic,created_at) VALUES (4,1,3,2,'Envelope E',3973,529.73,'DKK','Betalingsservice','2026-09-01',1,'2026-08-11 10:05:41')");
        $db->exec("INSERT INTO envelope_movements (envelope_id,month_id,transaction_id,type,amount_dkk,note,movement_date) VALUES (2,1,4,'expense',-3000,'Stale','2026-08-01')");

        migrate_to_v2_1($db);

        $funding = $db->query("SELECT amount_dkk,movement_date FROM envelope_movements WHERE entry_id=3 AND type='funding'")->fetch();
        $expense = $db->query("SELECT amount_dkk,movement_date,note FROM envelope_movements WHERE transaction_id=4 AND type='expense'")->fetch();
        self::assertEqualsWithDelta(3973, (float)$funding['amount_dkk'], 0.001);
        self::assertSame('2026-08-11', $funding['movement_date']);
        self::assertEqualsWithDelta(-3973, (float)$expense['amount_dkk'], 0.001);
        self::assertSame('2026-09-01', $expense['movement_date']);
        self::assertSame('Betalingsservice', $expense['note']);
    }

    public function testV23PreservesExistingFinancialRowsWhileAddingAccountFoundations(): void
    {
        $db = self::newDatabase();
        $this->migrateThroughVersion($db, '2.2');
        $db->exec("INSERT INTO months (id,year,month,exchange_rate_eur_dkk) VALUES (1,2026,6,7.46)");
        $db->exec("INSERT INTO envelopes (id,name,account,role,currency) VALUES (2,'Envelope A','Test Wallet','daily','DKK')");
        $db->exec("INSERT INTO transactions (id,month_id,envelope_id,envelope,amount_dkk,amount_eur,currency_original,note,spent_date) VALUES (3,1,2,'Envelope A',800,107.24,'DKK','Preserve','2026-06-12')");
        $db->exec("INSERT INTO envelope_movements (id,envelope_id,month_id,transaction_id,type,amount_dkk,note,movement_date) VALUES (4,2,1,3,'expense',-800,'Preserve','2026-06-12')");
        $sharedTransactionFields = 'id,month_id,entry_id,envelope_id,envelope,amount_dkk,amount_eur,currency_original,note,spent_date,is_automatic,created_at,updated_at';
        $beforeTransaction = $db->query("SELECT $sharedTransactionFields FROM transactions WHERE id=3")->fetch();
        $beforeMovement = $db->query('SELECT * FROM envelope_movements WHERE id=4')->fetch();

        migrate_to_v2_3($db);

        self::assertSame($beforeTransaction, $db->query("SELECT $sharedTransactionFields FROM transactions WHERE id=3")->fetch());
        self::assertSame($beforeMovement, $db->query('SELECT * FROM envelope_movements WHERE id=4')->fetch());
        self::assertSame(0, (int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn());
        self::assertSame(0, (int)$db->query('SELECT COUNT(*) FROM account_events')->fetchColumn());
        self::assertSame(0, (int)$db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn());
        self::assertSame(0, (int)$db->query('SELECT COUNT(*) FROM month_funding_context')->fetchColumn());
        self::assertFalse($db->query('PRAGMA foreign_key_check')->fetch());
    }

    /**
     * A fresh install runs the whole ordered migration chain and ends on the
     * canonical schema. The rejected onboarding tables are created by the v2.4
     * baseline migration and dropped again by v2.6, so no onboarding column
     * survives into the resulting schema under any name.
     */
    public function testFreshInstallChainLeavesNoRetiredOnboardingColumnInTheSchema(): void
    {
        $db = self::newDatabase();
        migrate($db);

        self::assertSame('2.23', database_version($db));
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['onboarding_sessions', 'onboarding_account_openings', 'onboarding_envelope_reviews'] as $table) {
            self::assertNotContains($table, $tables, "$table must not survive the migration chain");
        }

        $schema = implode("\n", $db->query("SELECT sql FROM sqlite_master WHERE sql IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN));
        self::assertStringNotContainsString('envelope_action', $schema, 'No retired onboarding action column may survive in the schema');
        self::assertStringNotContainsString('onboarding', $schema, 'No rejected onboarding table may survive in the schema');
    }

    public function testV26DropsOnlyVerifiedEmptyOnboardingTablesAndPreservesFinancialRows(): void
    {
        $db = self::newDatabase();
        $this->migrateThroughVersion($db, '2.5');
        $db->exec("INSERT INTO months (id,year,month,exchange_rate_eur_dkk) VALUES (1,2026,9,7.46)");
        $db->exec("INSERT INTO accounts (id,name,type,currency,ledger_start_date) VALUES (2,'Test Wallet','asset','DKK','2026-01-01')");
        $db->exec("INSERT INTO envelopes (id,name,account,role,currency,default_account_id) VALUES (3,'Envelope A','Legacy','daily','DKK',2)");
        $db->exec("INSERT INTO transactions (id,month_id,envelope_id,payment_account_id,posting_scope,envelope,amount_dkk,amount_eur,currency_original,note,spent_date) VALUES (4,1,3,2,'posted','Envelope A',250,33.51,'DKK','Preserve','2026-09-03')");
        $before = [
            'months' => $db->query('SELECT * FROM months')->fetchAll(),
            'envelopes' => $db->query('SELECT * FROM envelopes')->fetchAll(),
            'accounts' => $db->query('SELECT * FROM accounts')->fetchAll(),
        ];

        migrate_to_v2_6($db);

        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['onboarding_sessions','onboarding_account_openings','onboarding_envelope_reviews'] as $table) self::assertNotContains($table, $tables);
        foreach ($before as $table => $rows) self::assertSame($rows, $db->query("SELECT * FROM $table")->fetchAll(), $table);
        self::assertNotContains('posting_scope', array_column($db->query('PRAGMA table_info(transactions)')->fetchAll(), 'name'));
        self::assertSame(4, (int)$db->query('SELECT id FROM transactions')->fetchColumn());
        self::assertSame(2, (int)$db->query('SELECT payment_account_id FROM transactions')->fetchColumn());
        self::assertFalse($db->query('PRAGMA foreign_key_check')->fetch());
    }

    public function testV27RequiresMasterPinBeforeRemovingLegacyUsersAndPreservesFinancialData(): void
    {
        $db = self::newDatabase();
        $this->migrateThroughVersion($db, '2.6');
        $db->exec("INSERT INTO users (username,password_hash) VALUES ('legacy','legacy-hash')");
        $db->exec("INSERT INTO months (id,year,month,exchange_rate_eur_dkk,salary_dkk) VALUES (1,2026,8,7.46,30000)");
        $financialBefore = $db->query('SELECT * FROM months')->fetchAll();
        $directory = sys_get_temp_dir() . '/fin-v27-' . bin2hex(random_bytes(5));
        mkdir($directory, 0700, true);
        $base = app_config();
        $config = [...$base, 'auth' => [...$base['auth'],
            'master_pin_file' => $directory . '/master-pin.hash',
            'throttle_file' => $directory . '/throttle.json',
            'file_group' => '',
            'pin_failure_delay_us' => 0,
        ]];
        try {
            try {
                migrate_to_v2_7($db, $config);
                self::fail('DB v2.7 must not remove populated legacy authentication without a Master PIN.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('Configure the server-managed Master PIN', $error->getMessage());
            }
            self::assertSame(1, (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn());
            set_master_pin($config, '12345678');
            migrate_to_v2_7($db, $config);
            $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            self::assertNotContains('users', $tables);
            self::assertContains('auth_principal', $tables);
            self::assertContains('webauthn_credentials', $tables);
            self::assertSame($financialBefore, $db->query('SELECT * FROM months')->fetchAll());
            self::assertFalse($db->query('PRAGMA foreign_key_check')->fetch());
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) @unlink($file);
            @rmdir($directory);
        }
    }

    public function testV28AddsOnlyDisposableStatementEvidenceTables(): void
    {
        $db = self::newDatabase();
        $this->migrateThroughVersion($db, '2.7');
        $db->exec("INSERT INTO months (id,year,month,exchange_rate_eur_dkk) VALUES (1,2026,8,7.46)");
        $db->exec("INSERT INTO envelopes (id,name,account,role,currency) VALUES (2,'Envelope A','Bank','daily','DKK')");
        $financialBefore = [
            'months' => $db->query('SELECT * FROM months')->fetchAll(),
            'envelopes' => $db->query('SELECT * FROM envelopes')->fetchAll(),
            'transactions' => $db->query('SELECT * FROM transactions')->fetchAll(),
            'movements' => $db->query('SELECT * FROM envelope_movements')->fetchAll(),
        ];

        migrate_to_v2_8($db);

        foreach ($financialBefore as $table => $rows) {
            $actualTable = $table === 'movements' ? 'envelope_movements' : $table;
            self::assertSame($rows, $db->query("SELECT * FROM $actualTable")->fetchAll(), $actualTable);
        }
        self::assertSame(0, (int)$db->query('SELECT COUNT(*) FROM statement_imports')->fetchColumn());
        self::assertSame(0, (int)$db->query('SELECT COUNT(*) FROM statement_rows')->fetchColumn());
        self::assertFalse($db->query('PRAGMA foreign_key_check')->fetch());
    }

    public function testV213PreservesExistingReconciliationLinksAndAddsCheckedDisposition(): void
    {
        $db = self::newDatabase();
        $this->migrateThroughVersion($db, '2.12');
        $db->exec("INSERT INTO accounts (id,name,type,currency,ledger_start_date,account_number) VALUES (1,'Statement account','asset','DKK','2026-01-01','STATEMENT-1')");
        $db->exec("INSERT INTO envelopes (id,name,account,role,currency) VALUES (2,'General','Bank','daily','DKK')");
        $db->exec("INSERT INTO transactions (id,envelope_id,payment_account_id,envelope,amount_dkk,amount_eur,currency_original,note,spent_date) VALUES (3,2,1,'General',25,3.35,'DKK','Expense','2026-08-05')");
        $db->exec("INSERT INTO statement_imports (id,account_id,provider,original_filename,file_checksum,parser_version,row_count,valid_row_count,problem_count) VALUES (4,1,'test','test.xlsx','v213',1,1,1,0)");
        $db->exec("INSERT INTO statement_rows (id,import_id,account_id,provider,source_row_number,booking_date,amount_original,currency_original,row_fingerprint) VALUES (5,4,1,'test',1,'2026-08-05',-25,'DKK','v213-row')");
        $db->exec("INSERT INTO statement_reconciliations (id,statement_row_id,transaction_id) VALUES (6,5,3)");
        $before = $db->query('SELECT * FROM statement_reconciliations WHERE id=6')->fetch();

        migrate_to_v2_13($db);

        $after = $db->query('SELECT * FROM statement_reconciliations WHERE id=6')->fetch();
        self::assertSame($before, array_intersect_key($after, $before));
        self::assertNull($after['disposition']);
        $sql = (string)$db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='statement_reconciliations'")->fetchColumn();
        self::assertStringContainsString("disposition = 'no_fin_action'", $sql);
        self::assertFalse($db->query('PRAGMA foreign_key_check')->fetch());
    }

    public function testV214PreservesPopulatedRowsLinksForeignKeysAndEventSequence(): void
    {
        $db = self::newDatabase();
        $this->migrateThroughVersion($db, '2.13');
        $db->exec("INSERT INTO accounts (id,name,type,currency,ledger_start_date,account_number) VALUES (1,'Account','asset','DKK','2026-01-01','ACCOUNT-1')");
        $db->exec("INSERT INTO envelopes (id,name,account,role,currency) VALUES (2,'Envelope','Bank','daily','DKK')");
        $db->exec("INSERT INTO transactions (id,envelope_id,payment_account_id,envelope,amount_dkk,amount_eur,currency_original,note,spent_date) VALUES (7,2,1,'Envelope',100,13.4,'DKK','Expense','2026-08-02')");
        $db->exec("INSERT INTO account_events (id,event_type,event_date,amount_dkk,source_account_id,transaction_id,note) VALUES (11,'expense','2026-08-02',100,1,7,'Expense')");
        $db->exec("INSERT INTO account_movements (id,event_id,account_id,sequence,amount_dkk,movement_date) VALUES (12,11,1,1,-100,'2026-08-02')");
        $db->exec("INSERT INTO statement_imports (id,account_id,provider,original_filename,file_checksum,parser_version,row_count,valid_row_count,problem_count) VALUES (13,1,'test','v214.xlsx','v214',1,2,2,0)");
        $db->exec("INSERT INTO statement_rows (id,import_id,account_id,provider,source_row_number,booking_date,amount_original,currency_original,row_fingerprint) VALUES
            (14,13,1,'test',1,'2026-08-02',-100,'DKK','expense-row'),
            (15,13,1,'test',2,'2026-08-03',5,'DKK','no-action-row')");
        $db->exec("INSERT INTO statement_reconciliations (id,statement_row_id,transaction_id) VALUES (16,14,7)");
        $db->exec("INSERT INTO statement_reconciliations (id,statement_row_id,disposition) VALUES (17,15,'no_fin_action')");
        $db->exec("INSERT INTO account_events (event_type,event_date,amount_dkk,destination_account_id,note) VALUES ('opening','2026-08-04',1,1,'Deleted')");
        $deleted = (int)$db->lastInsertId();
        $db->exec("DELETE FROM account_events WHERE id=$deleted");
        $sequenceBefore = (int)$db->query("SELECT seq FROM sqlite_sequence WHERE name='account_events'")->fetchColumn();
        $tables = ['transactions','accounts','account_events','account_movements','statement_imports','statement_rows','statement_reconciliations'];
        $before = [];
        foreach ($tables as $table) $before[$table] = $db->query("SELECT * FROM $table ORDER BY id")->fetchAll();

        migrate_to_v2_14($db);

        self::assertSame('expense', $db->query('SELECT kind FROM transactions WHERE id=7')->fetchColumn());
        foreach ($tables as $table) {
            $after = $db->query("SELECT * FROM $table ORDER BY id")->fetchAll();
            if ($table === 'transactions') foreach ($after as &$row) unset($row['kind']);
            unset($row);
            self::assertSame($before[$table], $after, $table);
        }
        self::assertSame($sequenceBefore, (int)$db->query("SELECT seq FROM sqlite_sequence WHERE name='account_events'")->fetchColumn());
        self::assertFalse($db->query('PRAGMA foreign_key_check')->fetch());
    }

    public function testV215WidensAccountClassificationWithoutChangingReferencesOrBalances(): void
    {
        $db = self::newDatabase();
        $this->migrateThroughVersion($db, '2.14');
        $db->exec("INSERT INTO accounts (id,name,type,currency,ledger_start_date,account_number) VALUES
            (1,'Asset','asset','DKK','2026-01-01','ASSET-1'),
            (2,'Liability','liability','DKK','2026-01-01','LIABILITY-2')");
        $db->exec("INSERT INTO account_events (id,event_type,event_date,amount_dkk,destination_account_id,note) VALUES (3,'opening','2026-01-01',1000,1,'Opening')");
        $db->exec("INSERT INTO account_movements (id,event_id,account_id,sequence,amount_dkk,movement_date) VALUES (4,3,1,1,1000,'2026-01-01')");
        $db->exec("INSERT INTO envelopes (id,name,account,role,currency,default_account_id) VALUES (5,'Envelope','Bank','daily','DKK',1)");
        $db->exec("INSERT INTO entries (id,month_id,envelope_id,type,name,category,amount_dkk,amount_eur,currency_original,account,status,bs_payment_account_id) SELECT 6,NULL,5,'expense','Envelope','Envelope',100,13.4,'DKK','Bank','planned',2 WHERE 0");
        $db->exec("INSERT INTO accounts (name,type,currency,ledger_start_date,account_number) VALUES ('Deleted','asset','DKK','2026-01-01','DELETED')");
        $deleted = (int)$db->lastInsertId();
        $db->exec("DELETE FROM accounts WHERE id=$deleted");
        $sequenceBefore = (int)$db->query("SELECT seq FROM sqlite_sequence WHERE name='accounts'")->fetchColumn();
        $before = [
            'accounts' => $db->query('SELECT * FROM accounts ORDER BY id')->fetchAll(),
            'events' => $db->query('SELECT * FROM account_events ORDER BY id')->fetchAll(),
            'movements' => $db->query('SELECT * FROM account_movements ORDER BY id')->fetchAll(),
            'envelopes' => $db->query('SELECT * FROM envelopes ORDER BY id')->fetchAll(),
        ];
        $balanceBefore = $db->query('SELECT SUM(amount_dkk) FROM account_movements WHERE account_id=1')->fetchColumn();

        migrate_to_v2_15($db);

        self::assertSame($before['accounts'], $db->query('SELECT * FROM accounts ORDER BY id')->fetchAll());
        self::assertSame($before['events'], $db->query('SELECT * FROM account_events ORDER BY id')->fetchAll());
        self::assertSame($before['movements'], $db->query('SELECT * FROM account_movements ORDER BY id')->fetchAll());
        self::assertSame($before['envelopes'], $db->query('SELECT * FROM envelopes ORDER BY id')->fetchAll());
        self::assertSame($balanceBefore, $db->query('SELECT SUM(amount_dkk) FROM account_movements WHERE account_id=1')->fetchColumn());
        self::assertSame($sequenceBefore, (int)$db->query("SELECT seq FROM sqlite_sequence WHERE name='accounts'")->fetchColumn());
        $db->exec("INSERT INTO accounts (name,type,currency,ledger_start_date,account_number) VALUES ('Card','credit_card','DKK','2026-01-01','CARD')");
        self::assertGreaterThan($deleted, (int)$db->lastInsertId());
        self::assertFalse($db->query('PRAGMA foreign_key_check')->fetch());
    }

    public function testV216PreservesIdsSequencesReferencesAndAuthoritativeValues(): void
    {
        $db = self::newDatabase();
        $this->migrateThroughV215($db);
        $db->exec("INSERT INTO months (id,year,month,exchange_rate_eur_dkk,salary_dkk) VALUES (10,2026,8,7.5,30000)");
        $db->exec("INSERT INTO accounts (id,name,account_number,type,currency,ledger_start_date) VALUES
            (20,'Currency account','A20','asset','DKK','2026-01-01'),
            (21,'Helper account','A21','credit_card','EUR','2026-01-01')");
        $db->exec("INSERT INTO envelopes (id,name,account,role,currency,savings_goal_dkk) VALUES
            (30,'Travel','Currency account','reserved','EUR',5000.125),
            (31,'Buffer','Currency account','reserved','DKK',0)");
        $db->exec("INSERT INTO entries (id,month_id,envelope_id,type,name,category,amount_dkk,amount_eur,currency_original,account,status,note) VALUES
            (40,10,30,'expense','Travel','Envelope',750.125,100,'EUR','Currency account','paid','preserve entry'),
            (41,10,NULL,'income','Zero','Income',0,0,'DKK','Income','planned','preserve zero')");
        $db->exec("INSERT INTO transactions (id,month_id,entry_id,envelope_id,payment_account_id,envelope,amount_dkk,amount_eur,currency_original,spent_date,kind,note) VALUES (50,10,40,30,20,'Travel',75.375,10,'EUR','2026-08-05','expense','preserve transaction')");
        $db->exec("INSERT INTO envelope_transfers (id,source_envelope_id,destination_envelope_id,source_envelope,destination_envelope,amount_dkk,transfer_date,note) VALUES (55,30,31,'Travel','Buffer',12.625,'2026-08-04','preserve transfer')");
        $db->exec("INSERT INTO envelope_movements (id,envelope_id,month_id,entry_id,type,amount_dkk,movement_date,note) VALUES (60,30,10,40,'funding',750.125,'2026-08-01','preserve movement')");
        $db->exec("INSERT INTO account_events (id,event_type,event_date,amount_dkk,source_account_id,transaction_id,note) VALUES (70,'expense','2026-08-05',75.375,20,50,'preserve event')");
        $db->exec("INSERT INTO account_movements (id,event_id,account_id,sequence,amount_dkk,movement_date) VALUES (80,70,20,1,-75.375,'2026-08-05')");
        $db->exec("INSERT INTO envelope_recommendation_settings (envelope_id,manual_baseline_dkk,manual_baseline_note) VALUES (30,900.875,'preserve baseline')");
        $db->exec("INSERT INTO month_funding_context (month_id,opening_available_owned_dkk,source,note) VALUES (10,1234.625,'user_confirmed','preserve context')");
        $db->exec("INSERT INTO statement_imports (id,account_id,provider,original_filename,file_checksum,parser_version,row_count,valid_row_count,problem_count) VALUES (90,20,'test','test.xlsx','checksum',1,1,1,0)");
        $db->exec("INSERT INTO statement_rows (id,import_id,account_id,provider,source_row_number,booking_date,amount_original,currency_original,row_fingerprint,occurrence_index,raw_fields_json) VALUES
            (91,90,20,'test',1,'2026-08-05',-75.375,'JPY','fingerprint',2,'{\"raw\":true}'),
            (93,90,20,'test',2,'2026-08-06',-12.5,'DKK','transfer-fingerprint',0,'{}'),
            (94,90,20,'test',3,'2026-08-07',5,'CHF','no-action-fingerprint',0,'{}')");
        $db->exec("INSERT INTO statement_reconciliations (id,statement_row_id,transaction_id) VALUES (92,91,50)");
        $db->exec("INSERT INTO account_events (id,event_type,event_date,amount_dkk,source_account_id,destination_account_id,note) VALUES (71,'transfer','2026-08-06',12.5,20,21,'preserve linked transfer')");
        $db->exec("INSERT INTO account_movements (id,event_id,account_id,sequence,amount_dkk,movement_date) VALUES
            (81,71,20,1,-12.5,'2026-08-06'),
            (82,71,21,2,12.5,'2026-08-06')");
        $db->exec("INSERT INTO statement_reconciliations (statement_row_id,target_account_event_id) VALUES (93,71)");
        $db->exec("INSERT INTO statement_reconciliations (statement_row_id,disposition) VALUES (94,'no_fin_action')");

        $beforeValues = [
            'month' => $db->query('SELECT exchange_rate_eur_dkk,salary_dkk,notes FROM months WHERE id=10')->fetch(),
            'envelopes' => $db->query('SELECT id,savings_goal_dkk,note FROM envelopes ORDER BY id')->fetchAll(),
            'entries' => $db->query('SELECT id,amount_dkk,note,created_at,updated_at FROM entries ORDER BY id')->fetchAll(),
            'transactions' => $db->query('SELECT id,amount_dkk,entry_id,envelope_id,payment_account_id,note,created_at,updated_at FROM transactions ORDER BY id')->fetchAll(),
            'event' => $db->query('SELECT id,amount_dkk,source_account_id,transaction_id,note FROM account_events WHERE id=70')->fetch(),
            'account_movement' => $db->query('SELECT id,event_id,account_id,amount_dkk,movement_date FROM account_movements WHERE id=80')->fetch(),
            'envelope_movement' => $db->query('SELECT id,envelope_id,entry_id,amount_dkk,note FROM envelope_movements WHERE id=60')->fetch(),
            'transfer' => $db->query('SELECT id,source_envelope_id,destination_envelope_id,amount_dkk,note FROM envelope_transfers WHERE id=55')->fetch(),
            'baseline' => $db->query('SELECT envelope_id,manual_baseline_dkk,manual_baseline_note FROM envelope_recommendation_settings WHERE envelope_id=30')->fetch(),
            'context' => $db->query('SELECT month_id,opening_available_owned_dkk,source,note FROM month_funding_context WHERE month_id=10')->fetch(),
            'evidence' => $db->query('SELECT id,amount_original,currency_original,row_fingerprint,occurrence_index,raw_fields_json FROM statement_rows ORDER BY id')->fetchAll(),
            'links' => $db->query('SELECT id,statement_row_id,transaction_id,target_account_event_id,disposition,created_at FROM statement_reconciliations ORDER BY id')->fetchAll(),
            'account_balance' => $db->query('SELECT SUM(amount_dkk) FROM account_movements WHERE account_id=20')->fetchColumn(),
            'envelope_balance' => $db->query('SELECT SUM(amount_dkk) FROM envelope_movements WHERE envelope_id=30')->fetchColumn(),
            'report_total' => $db->query('SELECT SUM(amount_dkk) FROM transactions')->fetchColumn(),
            'sequences' => $db->query('SELECT name,seq FROM sqlite_sequence ORDER BY name')->fetchAll(),
        ];

        migrate($db);

        self::assertSame('2.23', database_version($db));
        self::assertSame('DKK', database_currency($db));
        self::assertSame('CURRENCY', $db->query('SELECT currency FROM accounts WHERE id=20')->fetchColumn());
        self::assertSame('HELPER_CURRENCY', $db->query('SELECT currency FROM accounts WHERE id=21')->fetchColumn());
        self::assertSame('HELPER_CURRENCY', $db->query('SELECT currency FROM envelopes WHERE id=30')->fetchColumn());
        self::assertSame(20, (int)$db->query('SELECT default_account_id FROM envelopes WHERE id=30')->fetchColumn());
        self::assertNotContains('type', array_column($db->query('PRAGMA table_info(accounts)')->fetchAll(), 'name'));
        self::assertNotContains('account', array_column($db->query('PRAGMA table_info(envelopes)')->fetchAll(), 'name'));
        self::assertNotContains('account', array_column($db->query('PRAGMA table_info(entries)')->fetchAll(), 'name'));
        self::assertSame($beforeValues['month'], $db->query('SELECT exchange_rate AS exchange_rate_eur_dkk,salary AS salary_dkk,notes FROM months WHERE id=10')->fetch());
        self::assertSame($beforeValues['envelopes'], $db->query('SELECT id,savings_goal AS savings_goal_dkk,note FROM envelopes ORDER BY id')->fetchAll());
        self::assertSame($beforeValues['entries'], $db->query('SELECT id,amount AS amount_dkk,note,created_at,updated_at FROM entries ORDER BY id')->fetchAll());
        self::assertSame($beforeValues['transactions'], $db->query('SELECT id,amount AS amount_dkk,entry_id,envelope_id,payment_account_id,note,created_at,updated_at FROM transactions ORDER BY id')->fetchAll());
        self::assertSame($beforeValues['event'], $db->query('SELECT id,amount AS amount_dkk,source_account_id,transaction_id,note FROM account_events WHERE id=70')->fetch());
        self::assertSame($beforeValues['account_movement'], $db->query('SELECT id,event_id,account_id,amount AS amount_dkk,movement_date FROM account_movements WHERE id=80')->fetch());
        self::assertSame($beforeValues['envelope_movement'], $db->query('SELECT id,envelope_id,entry_id,amount AS amount_dkk,note FROM envelope_movements WHERE id=60')->fetch());
        self::assertSame($beforeValues['transfer'], $db->query('SELECT id,source_envelope_id,destination_envelope_id,amount AS amount_dkk,note FROM envelope_transfers WHERE id=55')->fetch());
        self::assertSame($beforeValues['baseline'], $db->query('SELECT envelope_id,manual_baseline AS manual_baseline_dkk,manual_baseline_note FROM envelope_recommendation_settings WHERE envelope_id=30')->fetch());
        self::assertSame($beforeValues['context'], $db->query('SELECT month_id,opening_available_owned AS opening_available_owned_dkk,source,note FROM month_funding_context WHERE month_id=10')->fetch());
        self::assertSame($beforeValues['evidence'], $db->query('SELECT id,amount AS amount_original,currency AS currency_original,row_fingerprint,occurrence_index,raw_fields_json FROM statement_rows ORDER BY id')->fetchAll());
        self::assertSame($beforeValues['links'], $db->query('SELECT id,statement_row_id,transaction_id,target_account_event_id,disposition,created_at FROM statement_reconciliations ORDER BY id')->fetchAll());
        self::assertSame($beforeValues['account_balance'], $db->query('SELECT SUM(amount) FROM account_movements WHERE account_id=20')->fetchColumn());
        self::assertSame($beforeValues['envelope_balance'], $db->query('SELECT SUM(amount) FROM envelope_movements WHERE envelope_id=30')->fetchColumn());
        self::assertSame($beforeValues['report_total'], $db->query('SELECT SUM(amount) FROM transactions')->fetchColumn());
        self::assertSame($beforeValues['sequences'], $db->query("SELECT name,seq FROM sqlite_sequence WHERE name NOT IN ('accounts_v2_16','envelopes_v2_16','entries_v2_16','transactions_v2_16') ORDER BY name")->fetchAll());
        self::assertSame(0, (int)$db->query('PRAGMA foreign_key_check')->fetchColumn());

        $next = $db->query("INSERT INTO transactions (month_id,envelope_id,envelope,amount,spent_date) VALUES (10,30,'Travel',1,'2026-08-06') RETURNING id")->fetchColumn();
        self::assertGreaterThan(50, (int)$next);
    }

    public function testV216AbortsWithoutPartialChangesForUnsupportedPreferenceData(): void
    {
        $db = self::newDatabase();
        $this->migrateThroughV215($db);
        $db->exec("INSERT INTO accounts (id,name,account_number,type,currency,ledger_start_date) VALUES (1,'Bad','BAD','asset','USD','2026-01-01')");
        $before = $this->snapshot($db);

        try {
            migrate($db);
            self::fail('Unsupported legacy preference must abort migration.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('unexpected accounts currency preference', $error->getMessage());
        }

        self::assertSame($before, $this->snapshot($db));
        self::assertSame(0, (int)$db->query("SELECT COUNT(*) FROM schema_migrations WHERE version='2.16'")->fetchColumn());
        self::assertContains('amount_dkk', array_column($db->query('PRAGMA table_info(account_events)')->fetchAll(), 'name'));
    }

    public function testV216VerificationFailureRollsBackSchemaDataVersionAndSettings(): void
    {
        $db = self::newDatabase();
        $this->migrateThroughV215($db);
        $db->exec("INSERT INTO months (id,year,month,exchange_rate_eur_dkk,salary_dkk,notes) VALUES (7,2026,8,7.5,1234,'Preserve month')");
        $db->exec("INSERT INTO accounts (id,name,account_number,type,currency,ledger_start_date) VALUES (8,'Valid account','ROLLBACK-8','asset','DKK','2026-01-01')");
        $db->exec('PRAGMA foreign_keys=OFF');
        $db->exec("INSERT INTO statement_imports (id,account_id,provider,original_filename,file_checksum,parser_version,row_count,valid_row_count,problem_count) VALUES (9,999,'test','invalid.xlsx','invalid-fk',2,0,0,0)");
        $db->exec('PRAGMA foreign_keys=ON');
        self::assertNotFalse($db->query('PRAGMA foreign_key_check')->fetch());
        $before = $this->completeSnapshot($db);

        try {
            migrate($db);
            self::fail('A foreign-key verification failure must abort DB v2.16.');
        } catch (RuntimeException $error) {
            self::assertSame('Database v2.16 foreign-key verification failed', $error->getMessage());
        }

        self::assertSame('2.15', database_version($db));
        self::assertSame($before, $this->completeSnapshot($db));
        self::assertSame(0, (int)$db->query("SELECT COUNT(*) FROM schema_migrations WHERE version='2.16'")->fetchColumn());
        self::assertSame(0, (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='database_settings'")->fetchColumn());
        self::assertContains('exchange_rate_eur_dkk', array_column($db->query('PRAGMA table_info(months)')->fetchAll(), 'name'));
        self::assertContains('amount_dkk', array_column($db->query('PRAGMA table_info(entries)')->fetchAll(), 'name'));
    }

    public function testV217MapsOnlyDeterministicAccountReferencesAndDropsObsoleteState(): void
    {
        $db = self::newDatabase();
        $this->migrateThroughV215($db);
        $db->exec("INSERT INTO months (id,year,month,exchange_rate_eur_dkk) VALUES (1,2026,8,7.46)");
        $db->exec("INSERT INTO accounts (id,name,account_number,type,currency,ledger_start_date) VALUES
            (10,'Test Bank','A10','asset','DKK','2026-01-01'),
            (11,'Test Wallet','A11','asset','DKK','2026-01-01')");
        $db->exec("INSERT INTO envelopes (id,name,account,role,default_account_id) VALUES
            (20,'Mapped',' Test Bank ','daily',NULL),
            (21,'Unmapped','Old bank','reserved',NULL),
            (22,'Explicit wins','Test Bank','reserved',11)");
        $db->exec("INSERT INTO entries (id,month_id,envelope_id,type,name,amount_dkk,amount_eur,currency_original,account) VALUES
            (30,1,20,'expense','Mapped',100,0,'DKK','Test Bank')");
        $db->exec("INSERT INTO envelope_transfers (id,source_envelope_id,destination_envelope_id,source_envelope,destination_envelope,source_account,destination_account,amount_dkk,transfer_date)
            VALUES (40,20,21,'Mapped','Unmapped','Test Bank','Unknown',25,'2026-08-01')");
        $db->exec("INSERT INTO month_account_transfers (month_id,account,transferred) VALUES (1,'Test Bank',1)");

        migrate_to_v2_16($db, 'DKK');
        migrate_to_v2_17($db);

        self::assertSame(10, (int)$db->query('SELECT default_account_id FROM envelopes WHERE id=20')->fetchColumn());
        self::assertNull($db->query('SELECT default_account_id FROM envelopes WHERE id=21')->fetchColumn());
        self::assertSame(11, (int)$db->query('SELECT default_account_id FROM envelopes WHERE id=22')->fetchColumn());
        self::assertSame(10, (int)$db->query('SELECT source_account_id FROM envelope_transfers WHERE id=40')->fetchColumn());
        self::assertNull($db->query('SELECT destination_account_id FROM envelope_transfers WHERE id=40')->fetchColumn());
        self::assertNotContains('type', array_column($db->query('PRAGMA table_info(accounts)')->fetchAll(), 'name'));
        self::assertNotContains('account', array_column($db->query('PRAGMA table_info(envelopes)')->fetchAll(), 'name'));
        self::assertNotContains('account', array_column($db->query('PRAGMA table_info(entries)')->fetchAll(), 'name'));
        self::assertSame(0, (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE name='month_account_transfers'")->fetchColumn());
        self::assertFalse($db->query('PRAGMA foreign_key_check')->fetch());
    }

    public function testV220ConvertsLegacyBalanceEventsAndPreservesOrdinaryRecords(): void
    {
        $db = self::newDatabase();
        $this->migrateThroughVersion($db, '2.19');
        $db->exec("INSERT INTO accounts (id,name,account_number,currency,ledger_start_date) VALUES
            (1,'Observed','MIGRATION-1','CURRENCY','2025-01-01'),
            (2,'Movement only','MIGRATION-2','CURRENCY','2025-01-01'),
            (3,'Ledger only','MIGRATION-3','CURRENCY','2025-01-01')");
        $db->exec("INSERT INTO account_events (id,event_type,event_date,amount,source_account_id,destination_account_id,note) VALUES
            (10,'opening','2025-01-01',1000,NULL,1,'Opening'),
            (20,'expense','2025-01-01',50,1,NULL,'Ordinary expense'),
            (30,'reconciliation','2025-01-05',100,NULL,1,'Correction'),
            (40,'transfer','2025-01-06',25,1,2,'Ordinary transfer')");
        $db->exec("INSERT INTO account_movements (id,event_id,account_id,sequence,amount,movement_date) VALUES
            (10,10,1,1,1000,'2025-01-01'),
            (20,20,1,1,-50,'2025-01-01'),
            (30,30,1,1,100,'2025-01-05'),
            (40,40,1,1,-25,'2025-01-06'),
            (41,40,2,2,25,'2025-01-06')");
        $import = $db->query("INSERT INTO statement_imports (account_id,provider,original_filename,file_checksum,parser_version,row_count,valid_row_count,problem_count) VALUES (1,'test','legacy.xlsx','migration-link',2,1,1,0) RETURNING id")->fetchColumn();
        $db->exec("INSERT INTO statement_rows (import_id,account_id,provider,source_row_number,booking_date,amount,currency,row_fingerprint) VALUES ($import,1,'test',1,'2025-01-06',-25,'DKK','migration-link-row')");
        $row = (int)$db->lastInsertId();
        $db->exec("INSERT INTO statement_reconciliations (statement_row_id,target_account_event_id) VALUES ($row,40)");
        $ordinaryEvents = $db->query("SELECT * FROM account_events WHERE id IN (20,40) ORDER BY id")->fetchAll();
        $ordinaryMovements = $db->query("SELECT * FROM account_movements WHERE event_id IN (20,40) ORDER BY id")->fetchAll();
        $eventSequence = (int)$db->query("SELECT seq FROM sqlite_sequence WHERE name='account_events'")->fetchColumn();

        migrate($db);

        self::assertSame('2.23', database_version($db));
        self::assertSame($ordinaryEvents, $db->query('SELECT * FROM account_events ORDER BY id')->fetchAll());
        self::assertSame($ordinaryMovements, $db->query('SELECT * FROM account_movements ORDER BY id')->fetchAll());
        self::assertSame($eventSequence, (int)$db->query("SELECT seq FROM sqlite_sequence WHERE name='account_events'")->fetchColumn());
        self::assertSame(950.0, account_balance_as_of($db, 1, '2025-01-01'));
        self::assertSame(1050.0, account_balance_as_of($db, 1, '2025-01-05'));
        self::assertSame(1025.0, account_balance_as_of($db, 1, '2025-01-06'));
        self::assertNull(account_balance_as_of($db, 2, '2025-01-06'));
        self::assertNull(account_balance_as_of($db, 3, '2025-01-06'));
        self::assertSame([
            ['effective_date' => '2025-01-01', 'balance' => 950.0, 'source' => 'migration'],
            ['effective_date' => '2025-01-05', 'balance' => 1050.0, 'source' => 'migration'],
        ], array_map(static fn(array $item): array => [...$item, 'balance' => (float)$item['balance']], $db->query('SELECT effective_date,balance,source FROM account_balance_observations ORDER BY effective_date')->fetchAll()));
        self::assertSame(40, (int)$db->query('SELECT target_account_event_id FROM statement_reconciliations')->fetchColumn());
        self::assertNotContains('ledger_start_date', array_column($db->query('PRAGMA table_info(accounts)')->fetchAll(), 'name'));
        self::assertFalse($db->query('PRAGMA foreign_key_check')->fetch());
    }

    public function testV220MigratesDestinationSideZeroMovementOpening(): void
    {
        $db = $this->newV220LegacyAccountDatabase();
        $db->exec("INSERT INTO account_events (id,event_type,event_date,amount,destination_account_id) VALUES (1,'opening','2025-01-01',100,1)");

        migrate($db);

        self::assertSame(100.0, account_balance_as_of($db, 1, '2025-01-01'));
        self::assertSame([
            'account_id' => 1,
            'effective_date' => '2025-01-01',
            'balance' => 100.0,
            'source' => 'migration',
        ], $this->singleMigrationObservation($db));
        self::assertSame(0, (int)$db->query("SELECT COUNT(*) FROM account_events WHERE event_type='opening'")->fetchColumn());
        self::assertSame(0, (int)$db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn());
    }

    public function testV220MigratesSourceSideZeroMovementOpening(): void
    {
        $db = $this->newV220LegacyAccountDatabase();
        $db->exec("INSERT INTO account_events (id,event_type,event_date,amount,source_account_id) VALUES (1,'opening','2025-01-01',100,1)");

        migrate($db);

        self::assertSame(-100.0, account_balance_as_of($db, 1, '2025-01-01'));
        self::assertSame(-100.0, $this->singleMigrationObservation($db)['balance']);
    }

    public function testV220IncludesZeroMovementOpeningInLaterOrdinaryBalance(): void
    {
        $db = $this->newV220LegacyAccountDatabase();
        $db->exec("INSERT INTO account_events (id,event_type,event_date,amount,source_account_id,destination_account_id,note) VALUES
            (1,'opening','2025-01-01',100,NULL,1,'Legacy opening'),
            (2,'expense','2025-01-02',25,1,NULL,'Later expense')");
        $db->exec("INSERT INTO account_movements (id,event_id,account_id,sequence,amount,movement_date) VALUES (2,2,1,1,-25,'2025-01-02')");

        migrate($db);

        self::assertSame(100.0, $this->singleMigrationObservation($db)['balance']);
        self::assertSame(75.0, account_balance_as_of($db, 1, '2025-01-02'));
        self::assertSame(1, (int)$db->query("SELECT COUNT(*) FROM account_events WHERE event_type='expense'")->fetchColumn());
        self::assertSame(-25.0, (float)$db->query('SELECT amount FROM account_movements')->fetchColumn());
    }

    public function testV220IncludesZeroMovementOpeningInLaterReconciliationObservation(): void
    {
        $db = $this->newV220LegacyAccountDatabase();
        $db->exec("INSERT INTO account_events (id,event_type,event_date,amount,source_account_id,destination_account_id) VALUES
            (1,'opening','2025-01-01',100,NULL,1),
            (2,'reconciliation','2025-01-03',20,NULL,1)");
        $db->exec("INSERT INTO account_movements (id,event_id,account_id,sequence,amount,movement_date) VALUES (2,2,1,1,20,'2025-01-03')");

        migrate($db);

        self::assertSame([
            ['effective_date' => '2025-01-01', 'balance' => 100.0],
            ['effective_date' => '2025-01-03', 'balance' => 120.0],
        ], array_map(static fn(array $row): array => [...$row, 'balance' => (float)$row['balance']], $db->query('SELECT effective_date,balance FROM account_balance_observations ORDER BY effective_date')->fetchAll()));
        self::assertSame(0, (int)$db->query('SELECT COUNT(*) FROM account_events')->fetchColumn());
        self::assertSame(0, (int)$db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn());
    }

    public function testV220IncludesNegativeZeroMovementOpeningInLaterBalance(): void
    {
        $db = $this->newV220LegacyAccountDatabase();
        $db->exec("INSERT INTO account_events (id,event_type,event_date,amount,source_account_id,destination_account_id) VALUES
            (1,'opening','2025-01-01',100,1,NULL),
            (2,'income','2025-01-02',25,NULL,1)");
        $db->exec("INSERT INTO account_movements (id,event_id,account_id,sequence,amount,movement_date) VALUES (2,2,1,1,25,'2025-01-02')");

        migrate($db);

        self::assertSame(-100.0, $this->singleMigrationObservation($db)['balance']);
        self::assertSame(-75.0, account_balance_as_of($db, 1, '2025-01-02'));
    }

    public function testV220MigratesOneMovementOpeningWithoutDoubleCounting(): void
    {
        $db = $this->newV220LegacyAccountDatabase();
        $db->exec("INSERT INTO account_events (id,event_type,event_date,amount,destination_account_id) VALUES (1,'opening','2025-01-01',100,1)");
        $db->exec("INSERT INTO account_movements (id,event_id,account_id,sequence,amount,movement_date) VALUES (1,1,1,1,100,'2025-01-01')");

        migrate($db);

        self::assertSame(100.0, $this->singleMigrationObservation($db)['balance']);
        self::assertSame(0, (int)$db->query('SELECT COUNT(*) FROM account_movements')->fetchColumn());
    }

    public function testV220RejectsOpeningWithMultipleMovements(): void
    {
        $db = $this->newV220LegacyAccountDatabase();
        $db->exec("INSERT INTO accounts (id,name,account_number,currency,ledger_start_date) VALUES (2,'Other','MIGRATION-2','CURRENCY','2025-01-01')");
        $db->exec("INSERT INTO account_events (id,event_type,event_date,amount,destination_account_id) VALUES (1,'opening','2025-01-01',100,1)");
        $db->exec("INSERT INTO account_movements (id,event_id,account_id,sequence,amount,movement_date) VALUES
            (1,1,1,1,100,'2025-01-01'),
            (2,1,2,2,100,'2025-01-01')");

        $this->assertV220MigrationRejectsEvent($db, 1);
    }

    public function testV220RejectsOpeningWithContradictorySideAndMovement(): void
    {
        $db = $this->newV220LegacyAccountDatabase();
        $db->exec("INSERT INTO account_events (id,event_type,event_date,amount,destination_account_id) VALUES (1,'opening','2025-01-01',100,1)");
        $db->exec("INSERT INTO account_movements (id,event_id,account_id,sequence,amount,movement_date) VALUES (1,1,1,1,-100,'2025-01-01')");

        $this->assertV220MigrationRejectsEvent($db, 1);
    }

    public function testV220RejectsZeroMovementReconciliation(): void
    {
        $db = $this->newV220LegacyAccountDatabase();
        $db->exec("INSERT INTO account_events (id,event_type,event_date,amount,destination_account_id) VALUES (1,'reconciliation','2025-01-01',100,1)");

        $this->assertV220MigrationRejectsEvent($db, 1);
    }

    public function testV220StillRejectsMalformedReconciliation(): void
    {
        $db = $this->newV220LegacyAccountDatabase();
        $db->exec("INSERT INTO accounts (id,name,account_number,currency,ledger_start_date) VALUES (2,'Other','MIGRATION-2','CURRENCY','2025-01-01')");
        $db->exec("INSERT INTO account_events (id,event_type,event_date,amount,destination_account_id) VALUES (1,'reconciliation','2025-01-01',100,1)");
        $db->exec("INSERT INTO account_movements (id,event_id,account_id,sequence,amount,movement_date) VALUES (1,1,2,1,100,'2025-01-01')");

        $this->assertV220MigrationRejectsEvent($db, 1);
    }

    public function testV220DoesNotCreateObservationFromLedgerStartDateAlone(): void
    {
        $db = $this->newV220LegacyAccountDatabase();

        migrate($db);

        self::assertSame(0, (int)$db->query('SELECT COUNT(*) FROM account_balance_observations')->fetchColumn());
        self::assertNull(account_balance_as_of($db, 1, '2025-01-01'));
    }

    public function testV220IncludesSameDayOrdinaryMovementsInMigratedObservation(): void
    {
        $db = $this->newV220LegacyAccountDatabase();
        $db->exec("INSERT INTO account_events (id,event_type,event_date,amount,source_account_id,destination_account_id) VALUES
            (1,'opening','2025-01-01',100,NULL,1),
            (2,'expense','2025-01-01',25,1,NULL)");
        $db->exec("INSERT INTO account_movements (id,event_id,account_id,sequence,amount,movement_date) VALUES (2,2,1,1,-25,'2025-01-01')");

        migrate($db);

        self::assertSame(75.0, $this->singleMigrationObservation($db)['balance']);
        self::assertSame(75.0, account_balance_as_of($db, 1, '2025-01-01'));
    }

    public function testV220AbortsMalformedLegacyBalanceEventWithoutPartialChanges(): void
    {
        $db = self::newDatabase();
        $this->migrateThroughVersion($db, '2.19');
        $db->exec("INSERT INTO accounts (id,name,account_number,currency,ledger_start_date) VALUES (1,'Malformed','MALFORMED','CURRENCY','2025-01-01')");
        $db->exec("INSERT INTO account_events (id,event_type,event_date,amount,destination_account_id) VALUES (1,'opening','2025-01-01',100,1)");
        $db->exec("INSERT INTO account_movements (event_id,account_id,sequence,amount,movement_date) VALUES (1,1,1,99,'2025-01-01')");
        $before = $this->snapshot($db);
        try {
            migrate($db);
            self::fail('Malformed legacy Account data must abort DB v2.20');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('malformed Account event 1', $error->getMessage());
        }
        self::assertSame($before, $this->snapshot($db));
        self::assertSame('2.19', database_version($db));
        self::assertSame(0, (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='account_balance_observations'")->fetchColumn());
    }

    public function testV222DeletesOnlyLegacyEnvelopeBalanceMovementsAndKeepsObservations(): void
    {
        $db = $this->newV222LegacyEnvelopeDatabase();
        $db->exec("INSERT INTO transactions (id,envelope_id,envelope,amount,note,spent_date,kind) VALUES
            (10,1,'Affected',100,'Ordinary expense','2026-09-04','expense'),
            (11,1,'Affected',25,'Ordinary refund','2026-09-06','refund')");
        $db->exec("INSERT INTO envelope_transfers (id,source_envelope_id,destination_envelope_id,source_envelope,destination_envelope,amount,note,transfer_date) VALUES
            (20,1,2,'Affected','Unaffected',50,'Ordinary transfer','2026-09-07')");
        $db->exec("INSERT INTO envelope_balance_observations (id,envelope_id,effective_date,balance,source,note,updated_at) VALUES
            (30,1,'2026-09-01',0,'manual','Start fresh','2026-09-01 12:00:00'),
            (31,2,'2026-09-01',50,'manual','Known balance','2026-09-01 12:00:00')");
        $db->exec("INSERT INTO envelope_movements (id,envelope_id,month_id,transaction_id,transfer_id,reversal_of_movement_id,type,amount,note,movement_date,created_at,updated_at) VALUES
            (10,1,1,NULL,NULL,NULL,'opening_balance',500,'Legacy opening','2026-08-01','2026-08-01 10:00:00','2026-08-01 10:00:00'),
            (20,1,1,NULL,NULL,NULL,'funding',1000,'Ordinary funding','2026-08-15','2026-08-15 10:00:00','2026-08-15 10:00:00'),
            (30,1,1,NULL,NULL,NULL,'adjustment',1700,'Broken later correction','2026-09-03','2026-09-03 10:00:00','2026-09-03 10:00:00'),
            (40,1,1,10,NULL,NULL,'expense',-100,'Ordinary expense','2026-09-04','2026-09-04 10:00:00','2026-09-04 10:00:00'),
            (50,1,1,NULL,NULL,NULL,'adjustment',-200,'Legacy correction','2026-09-05','2026-09-05 10:00:00','2026-09-05 10:00:00'),
            (51,1,1,NULL,NULL,50,'reversal',200,'Legacy undo','2026-09-05','2026-09-05 11:00:00','2026-09-05 11:00:00'),
            (60,1,1,11,NULL,NULL,'refund',25,'Ordinary refund','2026-09-06','2026-09-06 10:00:00','2026-09-06 10:00:00'),
            (70,1,1,NULL,20,NULL,'transfer_out',-50,'Ordinary transfer','2026-09-07','2026-09-07 10:00:00','2026-09-07 10:00:00'),
            (71,2,1,NULL,20,NULL,'transfer_in',50,'Ordinary transfer','2026-09-07','2026-09-07 10:00:00','2026-09-07 10:00:00'),
            (80,2,1,NULL,NULL,NULL,'funding',200,'Unaffected funding','2026-09-02','2026-09-02 10:00:00','2026-09-02 10:00:00')");
        $ordinaryBefore = $db->query("SELECT * FROM envelope_movements WHERE type NOT IN ('opening_balance','adjustment','reversal') ORDER BY id")->fetchAll();
        $observationsBefore = $db->query('SELECT * FROM envelope_balance_observations ORDER BY id')->fetchAll();
        $movementSequenceBefore = $db->query("SELECT seq FROM sqlite_sequence WHERE name='envelope_movements'")->fetchColumn();
        $affectedBefore = [
            '2026-08-01' => envelope_balance_as_of($db, 1, '2026-08-01'),
            '2026-09-01' => envelope_balance_as_of($db, 1, '2026-09-01'),
            '2026-09-03' => envelope_balance_as_of($db, 1, '2026-09-03'),
            '2026-09-04' => envelope_balance_as_of($db, 1, '2026-09-04'),
            '2026-09-07' => envelope_balance_as_of($db, 1, '2026-09-07'),
        ];
        $unaffectedBefore = envelope_balance_as_of($db, 2, '2026-09-07');

        migrate($db);

        self::assertSame('2.23', database_version($db));
        self::assertSame(0, (int)$db->query("SELECT COUNT(*) FROM envelope_movements WHERE type IN ('opening_balance','adjustment','reversal')")->fetchColumn());
        self::assertSame($ordinaryBefore, $db->query('SELECT * FROM envelope_movements ORDER BY id')->fetchAll());
        self::assertSame($observationsBefore, $db->query('SELECT * FROM envelope_balance_observations ORDER BY id')->fetchAll());
        self::assertSame($movementSequenceBefore, $db->query("SELECT seq FROM sqlite_sequence WHERE name='envelope_movements'")->fetchColumn());
        self::assertSame([
            '2026-08-01' => 0.0,
            '2026-09-01' => 0.0,
            '2026-09-03' => 0.0,
            '2026-09-04' => -100.0,
            '2026-09-07' => -125.0,
        ], [
            '2026-08-01' => envelope_balance_as_of($db, 1, '2026-08-01'),
            '2026-09-01' => envelope_balance_as_of($db, 1, '2026-09-01'),
            '2026-09-03' => envelope_balance_as_of($db, 1, '2026-09-03'),
            '2026-09-04' => envelope_balance_as_of($db, 1, '2026-09-04'),
            '2026-09-07' => envelope_balance_as_of($db, 1, '2026-09-07'),
        ]);
        self::assertSame([
            '2026-08-01' => 500.0,
            '2026-09-01' => 0.0,
            '2026-09-03' => 1700.0,
            '2026-09-04' => 1600.0,
            '2026-09-07' => 1575.0,
        ], $affectedBefore);
        self::assertSame($unaffectedBefore, envelope_balance_as_of($db, 2, '2026-09-07'));
        self::assertSame(300.0, $unaffectedBefore);
        self::assertFalse($db->query('PRAGMA foreign_key_check')->fetch());
    }

    public function testV222RejectsLegacyEnvelopeMovementsOwnedByFinancialRecords(): void
    {
        foreach (['entry_id', 'transaction_id', 'transfer_id'] as $ownerColumn) {
            $db = $this->newV222LegacyEnvelopeDatabase();
            if ($ownerColumn === 'entry_id') {
                $db->exec("INSERT INTO entries (id,month_id,envelope_id,type,name,category,amount,status) VALUES (10,1,1,'expense','Affected','Envelope',100,'planned')");
            } elseif ($ownerColumn === 'transaction_id') {
                $db->exec("INSERT INTO transactions (id,envelope_id,envelope,amount,note,spent_date) VALUES (10,1,'Affected',100,'Owned','2026-09-03')");
            } else {
                $db->exec("INSERT INTO envelope_transfers (id,source_envelope_id,destination_envelope_id,source_envelope,destination_envelope,amount,transfer_date) VALUES (10,1,2,'Affected','Unaffected',100,'2026-09-03')");
            }
            $db->exec("INSERT INTO envelope_movements (id,envelope_id,month_id,$ownerColumn,type,amount,note,movement_date) VALUES (100,1,1,10,'adjustment',100,'Unsafe','2026-09-03')");
            $before = $this->snapshot($db);

            try {
                migrate($db);
                self::fail("DB v2.22 must reject a legacy movement with $ownerColumn");
            } catch (RuntimeException $error) {
                self::assertStringContainsString('unsafe reference on legacy Envelope movement 100', $error->getMessage());
            }

            self::assertSame($before, $this->snapshot($db));
            self::assertSame('2.21', database_version($db));
        }
    }

    public function testV222RejectsLegacyReversalPointingToRetainedMovement(): void
    {
        $db = $this->newV222LegacyEnvelopeDatabase();
        $db->exec("INSERT INTO envelope_movements (id,envelope_id,month_id,type,amount,note,movement_date) VALUES
            (90,1,1,'funding',100,'Retained','2026-09-02'),
            (100,1,1,'reversal',-100,'Unsafe reversal','2026-09-03')");
        $db->exec('UPDATE envelope_movements SET reversal_of_movement_id=90 WHERE id=100');
        $before = $this->snapshot($db);

        try {
            migrate($db);
            self::fail('DB v2.22 must reject a legacy reversal pointing to a retained movement');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('legacy Envelope movement 100 reversing a retained movement', $error->getMessage());
        }

        self::assertSame($before, $this->snapshot($db));
        self::assertSame('2.21', database_version($db));
    }

    public function testV222RejectsRetainedMovementReferencingLegacyMovement(): void
    {
        $db = $this->newV222LegacyEnvelopeDatabase();
        $db->exec("INSERT INTO envelope_movements (id,envelope_id,month_id,type,amount,note,movement_date) VALUES
            (90,1,1,'adjustment',100,'Legacy','2026-09-02'),
            (100,1,1,'funding',100,'Unsafe retained reference','2026-09-03')");
        $db->exec('UPDATE envelope_movements SET reversal_of_movement_id=90 WHERE id=100');
        $before = $this->snapshot($db);

        try {
            migrate($db);
            self::fail('DB v2.22 must reject a retained movement referencing a legacy movement');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('retained Envelope movement 100 referencing a legacy movement', $error->getMessage());
        }

        self::assertSame($before, $this->snapshot($db));
        self::assertSame('2.21', database_version($db));
    }

    public function testV223AddsEnvelopeAmountSourceAndMonthEndBehaviorWithoutChangingExistingEnvelopes(): void
    {
        $db = self::newDatabase();
        $this->migrateThroughVersion($db, '2.22');
        $db->exec("INSERT INTO envelopes (id,name,role,currency) VALUES (1,'Envelope A','daily','CURRENCY')");
        $before = $db->query('SELECT id, name, role, currency, visibility, savings_goal FROM envelopes ORDER BY id')->fetchAll();

        migrate($db);

        self::assertSame('2.23', database_version($db));
        $columns = array_column($db->query('PRAGMA table_info(envelopes)')->fetchAll(), 'name');
        self::assertContains('amount_source', $columns);
        self::assertContains('month_end_behavior', $columns);
        self::assertSame($before, $db->query('SELECT id, name, role, currency, visibility, savings_goal FROM envelopes ORDER BY id')->fetchAll());
        $stored = $db->query('SELECT amount_source, month_end_behavior FROM envelopes WHERE id = 1')->fetch();
        self::assertSame('history', (string)$stored['amount_source'], 'Every existing Envelope keeps its history-based amount');
        self::assertSame('accumulate', (string)$stored['month_end_behavior'], 'Every existing Envelope keeps its accumulated balance');
        self::assertFalse($db->query('PRAGMA foreign_key_check')->fetch());
    }

    public function testPreferenceCheckConstraintsRejectLiteralCodes(): void
    {
        $this->expectException(PDOException::class);
        $this->db->exec("INSERT INTO accounts (name,account_number,currency) VALUES ('Bad','BAD-CODE','DKK')");
    }

    public function testEnvelopeNamesRemainUniqueIgnoringCaseAndWhitespace(): void
    {
        $this->createEnvelope('Ångström');
        self::assertNotNull(find_envelope_by_name($this->db, ' åNGSTRÖM '));
        $this->createEnvelope('Envelope A');

        $this->expectException(PDOException::class);
        $this->db->exec("INSERT INTO envelopes (name) VALUES (' envelope a ')");
    }

    private function newV220LegacyAccountDatabase(): PDO
    {
        $db = self::newDatabase();
        $this->migrateThroughVersion($db, '2.19');
        $db->exec("INSERT INTO accounts (id,name,account_number,currency,ledger_start_date) VALUES (1,'Legacy','MIGRATION-1','CURRENCY','2025-01-01')");
        return $db;
    }

    private function newV222LegacyEnvelopeDatabase(): PDO
    {
        $db = self::newDatabase();
        $this->migrateThroughVersion($db, '2.21');
        $db->exec("INSERT INTO months (id,year,month,exchange_rate,salary) VALUES (1,2026,9,7.46,30000)");
        $db->exec("INSERT INTO envelopes (id,name,role,currency) VALUES
            (1,'Affected','daily','CURRENCY'),
            (2,'Unaffected','reserved','CURRENCY')");
        return $db;
    }

    private function singleMigrationObservation(PDO $db): array
    {
        $rows = $db->query("SELECT account_id,effective_date,balance,source FROM account_balance_observations WHERE source='migration' ORDER BY account_id,effective_date")->fetchAll();
        self::assertCount(1, $rows);
        $rows[0]['account_id'] = (int)$rows[0]['account_id'];
        $rows[0]['balance'] = (float)$rows[0]['balance'];
        return $rows[0];
    }

    private function assertV220MigrationRejectsEvent(PDO $db, int $eventId): void
    {
        $before = $this->snapshot($db);
        try {
            migrate($db);
            self::fail("Malformed legacy Account event $eventId must abort DB v2.20");
        } catch (RuntimeException $error) {
            self::assertStringContainsString("malformed Account event $eventId", $error->getMessage());
        }
        self::assertSame($before, $this->snapshot($db));
        self::assertSame('2.19', database_version($db));
        self::assertSame(0, (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='account_balance_observations'")->fetchColumn());
    }

    private function migrateThroughV215(PDO $db): void
    {
        $this->migrateThroughVersion($db, '2.15');
    }

    private function migrateThroughVersion(PDO $db, string $target, ?array $config = null): void
    {
        $config ??= app_config();
        set_currency_config($db, $config);
        $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY,name TEXT NOT NULL,applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $migrations = [
            '1.0' => 'migrate_to_v1', '1.1' => 'migrate_to_v1_1', '1.2' => 'migrate_to_v1_2',
            '1.3' => 'migrate_to_v1_3', '1.4' => 'migrate_to_v1_4', '1.5' => 'migrate_to_v1_5',
            '1.6' => 'migrate_to_v1_6', '1.7' => 'migrate_to_v1_7', '1.8' => 'migrate_to_v1_8',
            '1.9' => 'migrate_to_v1_9', '2.0' => 'migrate_to_v2', '2.1' => 'migrate_to_v2_1',
            '2.2' => 'migrate_to_v2_2', '2.3' => 'migrate_to_v2_3', '2.4' => 'migrate_to_v2_4',
            '2.5' => 'migrate_to_v2_5', '2.6' => 'migrate_to_v2_6', '2.7' => 'migrate_to_v2_7',
            '2.8' => 'migrate_to_v2_8', '2.9' => 'migrate_to_v2_9', '2.10' => 'migrate_to_v2_10',
            '2.11' => 'migrate_to_v2_11', '2.12' => 'migrate_to_v2_12', '2.13' => 'migrate_to_v2_13',
            '2.14' => 'migrate_to_v2_14', '2.15' => 'migrate_to_v2_15', '2.16' => 'migrate_to_v2_16',
            '2.17' => 'migrate_to_v2_17', '2.18' => 'migrate_to_v2_18', '2.19' => 'migrate_to_v2_19',
            '2.20' => 'migrate_to_v2_20', '2.21' => 'migrate_to_v2_21', '2.22' => 'migrate_to_v2_22', '2.23' => 'migrate_to_v2_23',
        ];
        $record = $db->prepare('INSERT INTO schema_migrations(version,name) VALUES (?,?)');
        foreach ($migrations as $version => $function) {
            if ($version === '2.7') migrate_to_v2_7($db, $config);
            elseif ($version === '2.16') migrate_to_v2_16($db, 'DKK');
            else $function($db);
            if ($version !== '2.16') $record->execute([$version, "Test migration $version"]);
            if ($version === $target) return;
        }
        throw new InvalidArgumentException("Unknown migration target $target");
    }

    private function createLegacySchema(PDO $db): void
    {
        $db->exec("CREATE TABLE months (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            year INTEGER NOT NULL,
            month INTEGER NOT NULL,
            exchange_rate_eur_dkk REAL NOT NULL DEFAULT 7.46,
            salary_dkk REAL NOT NULL DEFAULT 0,
            target_savings_percent REAL NOT NULL DEFAULT 0.11,
            notes TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(year,month)
        )");
        $db->exec("CREATE TABLE entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            month_id INTEGER NOT NULL,
            type TEXT NOT NULL DEFAULT 'expense',
            name TEXT NOT NULL,
            category TEXT NOT NULL DEFAULT 'Other',
            amount_dkk REAL NOT NULL DEFAULT 0,
            amount_eur REAL NOT NULL DEFAULT 0,
            currency_original TEXT NOT NULL DEFAULT 'DKK',
            account TEXT NOT NULL DEFAULT '',
            note TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'planned',
            due_date TEXT DEFAULT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_recurring INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
    }

    private function assertForeignKey(string $table, string $column, string $target, string $onDelete): void
    {
        $foreignKeys = $this->db->query("PRAGMA foreign_key_list($table)")->fetchAll();
        self::assertTrue((bool)array_filter($foreignKeys, static fn(array $foreignKey): bool =>
            $foreignKey['from'] === $column && $foreignKey['table'] === $target && $foreignKey['on_delete'] === $onDelete
        ), "$table.$column must reference $target with ON DELETE $onDelete");
    }

    private function completeSnapshot(PDO $db): array
    {
        $schema = $db->query("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name")->fetchAll();
        return ['schema' => $schema, 'tables' => $this->snapshot($db), 'sequences' => $db->query('SELECT name,seq FROM sqlite_sequence ORDER BY name')->fetchAll()];
    }

    private function snapshot(PDO $db): array
    {
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = [
                'sql' => $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name=" . $db->quote($table))->fetchColumn(),
                'rows' => $db->query("SELECT * FROM \"$table\" ORDER BY 1")->fetchAll(),
            ];
        }
        return $snapshot;
    }
}
