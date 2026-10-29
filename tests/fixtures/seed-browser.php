<?php

declare(strict_types=1);

/**
 * Per-Playwright-run browser test seed (self-owned).
 *
 * This seed establishes the comprehensive synthetic baseline the existing
 * critical-flows specs require:
 *
 *   Accounts:    Test Wallet, Test Bank, Test Card, Transfer Account
 *   Envelopes:   Envelope A (daily), Reserved test envelope (reserved),
 *                Emergency fund (savings, 10000 DKK goal),
 *                Skipped plan (reserved), Vehicle test envelope (reserved),
 *                Test Card (reserved, opening balance 1200),
 *                Savings Balance (reserved)
 *   Months:      current year+month with salary 30000, exchange_rate 7.46;
 *                previous year+month with salary 25000, exchange_rate 7.46003;
 *                fixed reference month September 2026 with salary 28000.
 *   Profiles:    Test Bank (signed Account statement) and Test Foreign Bank
 *                (debit/credit statement with an Account pre-header).
 *
 * Self-owned-data properties:
 *   - all Accounts use TEST-prefixed account numbers that include a
 *     per-run random suffix (TEST-ACCOUNT-XXXXXXXX, TEST-CARD-XXXXXXXX,
 *     TEST-BANK-XXXXXXXX, TEST-TRANSFER-XXXXXXXX); never any
 *     production-looking default.
 *   - the current Month uses Today as the invariant (Year+Month of Today);
 *     this is required because the critical-flows tests under "authenticated
 *     critical flows" deliberately exercise Today. The seed does NOT
 *     pin Today to a fixed date because Today's Month is the invariant.
 *   - the fixed reference month September 2026 is used for the
 *     "current Plan month" baseline the September-clean-start tests
 *     require; it is NOT a current-date dependency because the value
 *     is a fixed synthetic reference, not Today.
 *   - opening balances, planned amounts, paid amounts, and notes are
 *     synthetic; they never reference real transactions or real accounts.
 *   - this seed runs once per Playwright invocation against a fresh
 *     isolated SQLite; no DB state is shared between runs.
 *   - the previous Month (when $month > 1) and the September 2026
 *     reference Month together guarantee that the
 *     `null and cross-source expenses edit by spent date ...` test
 *     (tests/browser/critical-flows.spec.js) can always find a
 *     non-current reference Month. Removing either the previous-Month
 *     or September 2026 lines from this seed will break that test; the
 *     test now throws a named diagnostic if no non-current Month is
 *     found in state.months.
 */

$appRoot = getenv('FIN_TEST_APP_ROOT') ?: dirname(__DIR__, 2);
require $appRoot . '/api.php';

function seedNeutralImportProfiles(PDO $db): array
{
    $positionedHeader = static function (array $keys): array {
        return array_map(
            static fn(string $key, int $column): array => ['column' => $column, 'key' => $key],
            $keys,
            array_keys($keys)
        );
    };

    $signedHeader = $positionedHeader([
        'dato', 'tekst', 'beløb', 'saldo', 'afstemt', 'kontonummer',
        'kontonavn', 'hovedkategori', 'kategori', 'kommentar',
    ]);
    $signedParser = [
        'booking_date' => ['sources' => ['dato']],
        'value_date' => null,
        'amount' => ['mode' => 'signed', 'column' => 'beløb'],
        'currency' => ['mode' => 'constant', 'value' => 'DKK'],
        'description' => ['column' => 'tekst', 'counterparty_by_sign' => null],
        'running_balance' => 'saldo',
        'reference' => null,
        'row_type' => ['columns' => ['kategori']],
        'account_number' => ['mode' => 'column_first_nonempty', 'column' => 'kontonummer'],
        'classification' => [
            'source' => 'hovedkategori',
            'mappings' => [['normalized_equals' => 'indtægter', 'classification' => 'income']],
        ],
    ];
    $ordinaryId = statement_import_profile_create($db, [
        'name' => 'Test Bank',
        'evidence_namespace' => 'profile:test-bank',
        'schema_version' => 1,
        'definition' => [
            'signature' => [
                'container' => 'ooxml',
                'sheet' => 0,
                'header' => $signedHeader,
                'markers' => [['column' => 'kontonavn', 'operator' => 'not_contains', 'value' => 'card']],
            ],
            'parser' => $signedParser,
        ],
    ]);

    $debitCreditHeader = $positionedHeader([
        'datumknjienja', 'datumvalute', 'uplataisplata', 'iznosisplate',
        'iznosuplate', 'valuta', 'brojtransakcije', 'status',
        'ibanplatitelja', 'nazivplatitelja', 'adresaplatitelja',
        'sjediteplatitelja', 'pozivnabrojplatitelja', 'stvarnidunik',
        'ibanprimatelja', 'nazivprimatelja', 'adresaprimatelja',
        'sjediteprimatelja', 'pozivnabrojprimatelja', 'opisplaanja',
        'krajnjiprimatelj', 'ifranamjene', 'originalniiznosisplate',
        'originalniiznosuplate', 'originalnavaluta', 'teaj',
    ]);
    $debitCreditId = statement_import_profile_create($db, [
        'name' => 'Test Foreign Bank',
        'evidence_namespace' => 'profile:test-foreign-bank',
        'schema_version' => 1,
        'definition' => [
            'signature' => [
                'container' => 'ooxml',
                'sheet' => 0,
                'header' => $debitCreditHeader,
                'markers' => [],
            ],
            'parser' => [
                'booking_date' => ['sources' => ['datumknjienja']],
                'value_date' => ['source' => 'datumvalute', 'omit_if_same_as_booking' => false],
                'amount' => [
                    'mode' => 'debit_credit',
                    'debit_column' => 'iznosisplate',
                    'credit_column' => 'iznosuplate',
                ],
                'currency' => ['mode' => 'column', 'column' => 'valuta'],
                'description' => [
                    'column' => 'opisplaanja',
                    'counterparty_by_sign' => [
                        'negative' => 'nazivprimatelja',
                        'positive' => 'nazivplatitelja',
                    ],
                ],
                'running_balance' => null,
                'reference' => 'brojtransakcije',
                'row_type' => ['columns' => ['uplataisplata', 'status']],
                'account_number' => [
                    'mode' => 'preheader_prefixed_digits',
                    'prefix' => 'račun:',
                    'minimum_digits' => 4,
                ],
                'classification' => null,
            ],
        ],
    ]);

    return ['ordinary' => $ordinaryId, 'debit_credit' => $debitCreditId];
}

$db = db();
migrate($db);
seedNeutralImportProfiles($db);

$today = effective_today();
$todayParts = explode('-', $today);
$year = (int)$todayParts[0];
$month = (int)$todayParts[1];
$runId = bin2hex(random_bytes(3));

$monthId = save_month($db, [
    'year' => $year,
    'month' => $month,
    'salary' => 30000,
    'exchange_rate' => 7.46,
]);
if ($month > 1) {
    save_month($db, [
        'year' => $year,
        'month' => $month - 1,
        'salary' => 25000,
        'exchange_rate' => 7.46003,
    ]);
}

$testWalletAccountId = save_account_definition($db, [
    'name' => 'Test Wallet',
    'account_number' => 'TEST-WALLET-' . $runId,
    'currency' => 'CURRENCY',
]);
$testBankAccountId = save_account_definition($db, [
    'name' => 'Test Bank',
    'account_number' => 'TEST-BANK-' . $runId,
    'currency' => 'CURRENCY',
]);
$testCardAccountId = save_account_definition($db, [
    'name' => 'Test Card',
    'account_number' => 'TEST-CARD-' . $runId,
    'currency' => 'CURRENCY',
]);
save_account_definition($db, [
    'name' => 'Transfer Account',
    'account_number' => 'TEST-TRANSFER-' . $runId,
    'currency' => 'CURRENCY',
]);

$envelopeAId = save_envelope($db, [
    'name' => 'Envelope A',
    'default_account_id' => $testWalletAccountId,
    'role' => 'daily',
]);
$croatianDuesId = save_envelope($db, [
    'name' => 'Reserved test envelope',
    'default_account_id' => $testBankAccountId,
    'role' => 'reserved',
    'currency' => 'CURRENCY',
    'visibility' => 'today',
]);
$emergencyFundId = save_envelope($db, [
    'name' => 'Emergency fund',
    'default_account_id' => $testBankAccountId,
    'role' => 'savings',
    'visibility' => 'hidden',
    'savings_goal' => 10000,
    'goal_visibility' => 'today',
]);
$skippedPlanId = save_envelope($db, [
    'name' => 'Skipped plan',
    'default_account_id' => $testWalletAccountId,
    'role' => 'reserved',
]);
$db->exec("INSERT INTO envelopes (name, role, visibility, sort_order) VALUES ('Vehicle test envelope', 'reserved', 'normal', 40)");
$testCardEnvelopeId = save_envelope($db, [
    'name' => 'Test Card',
    'default_account_id' => $testBankAccountId,
    'role' => 'reserved',
    'visibility' => 'normal',
]);
save_envelope($db, [
    'name' => 'Savings Balance',
    'role' => 'reserved',
    'currency' => 'CURRENCY',
]);

$septemberId = (int)$db->query("SELECT id FROM months WHERE year=2026 AND month=9")->fetchColumn();
if ($septemberId <= 0) {
    $septemberId = save_month($db, [
        'year' => 2026,
        'month' => 9,
        'salary' => 28000,
        'exchange_rate' => 7.46,
    ]);
}
$db->prepare("INSERT INTO envelope_movements (envelope_id,month_id,type,amount,note,movement_date) VALUES (:envelope_id,:month_id,'opening_balance',1200,'Artificial browser fixture','2026-08-31')")->execute([':envelope_id' => $testCardEnvelopeId, ':month_id' => $septemberId]);

save_entry($db, ['month_id' => $septemberId, 'envelope_id' => $envelopeAId, 'type' => 'expense', 'amount' => 3000, 'status' => 'planned']);
save_entry($db, ['month_id' => $septemberId, 'envelope_id' => $emergencyFundId, 'type' => 'expense', 'amount' => 2000, 'status' => 'planned']);
save_entry($db, ['month_id' => $septemberId, 'envelope_id' => $testCardEnvelopeId, 'type' => 'expense', 'amount' => 1000, 'status' => 'planned']);

// The critical-flows tests assume Envelope A has a non-zero "paid" amount in
// the current Month. The same Envelope cannot carry two non-income Plan rows
// for the same Month (application invariant), so we write an opening_balance
// movement directly instead of going through save_entry.
$db->prepare("INSERT INTO envelope_movements (envelope_id,month_id,type,amount,note,movement_date) VALUES (:envelope_id,:month_id,'opening_balance',3100,'Synthetic paid amount for browser tests',:movement_date)")
    ->execute([
        ':envelope_id' => $envelopeAId,
        ':month_id' => $monthId,
        ':movement_date' => date('Y-m-d'),
    ]);

// Synthetic Emergency fund balance = 5000 (50% of 10000 goal). Written as an
// opening_balance movement so the Today view renders the goal progress bar.
$db->prepare("INSERT INTO envelope_movements (envelope_id,month_id,type,amount,note,movement_date) VALUES (:envelope_id,:month_id,'opening_balance',5000,'Synthetic Emergency fund balance for browser tests',:movement_date)")
    ->execute([
        ':envelope_id' => $emergencyFundId,
        ':month_id' => $monthId,
        ':movement_date' => date('Y-m-d'),
    ]);
save_entry($db, [
    'month_id' => $monthId,
    'envelope_id' => $croatianDuesId,
    'type' => 'expense',
    'amount' => 100,
    'status' => 'planned',
]);
save_entry($db, [
    'month_id' => $monthId,
    'envelope_id' => $skippedPlanId,
    'type' => 'expense',
    'amount' => 250,
    'status' => 'skipped',
]);

echo "OK: run=" . $runId . " year=" . $year . " month=" . $month . "\n";
echo "  accounts=Test Wallet,Test Bank,Test Card,Transfer Account\n";
echo "  envelopes=Envelope A,Reserved test envelope,Emergency fund,Skipped plan,Vehicle test envelope,Test Card,Savings Balance\n";
