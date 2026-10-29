<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected PDO $db;

    protected function setUp(): void
    {
        $this->db = self::newDatabase();
        migrate($this->db);
    }

    protected static function newDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA foreign_keys = ON');
        return $db;
    }

    protected function createMonth(int $year = 2026, int $month = 1): int
    {
        return save_month($this->db, [
            'year' => $year,
            'month' => $month,
            'exchange_rate' => 7.46,
            'salary' => 30000,
        ]);
    }

    protected function createEnvelope(string $name = 'Envelope A', string $role = 'daily', string $currency = 'CURRENCY'): int
    {
        return save_envelope($this->db, [
            'name' => $name,
            'role' => $role,
            'currency' => $currency,
        ]);
    }

    protected function saveAllocation(int $monthId, int $envelopeId, float $amount, string $status = 'planned', ?int $id = null): int
    {
        return save_entry($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'type' => 'expense',
            'amount' => $amount,
            'status' => $status,
            'due_date' => '2026-01-05',
        ], $id);
    }

    protected function envelopeBalance(int $envelopeId): float
    {
        foreach (get_envelopes($this->db) as $envelope) {
            if ($envelope['id'] === $envelopeId) return (float)$envelope['balance'];
        }
        self::fail('Envelope was not found');
    }

    /**
     * Static variant of `provisionNeutralImportProfiles()` for tests that use a
     * custom DB handle (e.g., `IsolatedFinDatabase::db()`) instead of `$this->db`.
     * Returns the saved profile IDs by role.
     */
    public static function seedNeutralImportProfiles(PDO $db): array
    {
        $signedHeader = [
            ['column' => 0, 'key' => 'dato'],
            ['column' => 1, 'key' => 'tekst'],
            ['column' => 2, 'key' => 'beløb'],
            ['column' => 3, 'key' => 'saldo'],
            ['column' => 4, 'key' => 'afstemt'],
            ['column' => 5, 'key' => 'kontonummer'],
            ['column' => 6, 'key' => 'kontonavn'],
            ['column' => 7, 'key' => 'hovedkategori'],
            ['column' => 8, 'key' => 'kategori'],
            ['column' => 9, 'key' => 'kommentar'],
        ];
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
            'classification' => ['source' => 'hovedkategori', 'mappings' => [['normalized_equals' => 'indtægter', 'classification' => 'income']]],
        ];
        $ordinaryId = statement_import_profile_create($db, [
            'name' => 'Test Bank',
            'evidence_namespace' => 'profile:test-bank',
            'schema_version' => 1,
            'definition' => [
                'signature' => ['container' => 'ooxml', 'sheet' => 0, 'header' => $signedHeader, 'markers' => [['column' => 'kontonavn', 'operator' => 'not_contains', 'value' => 'card']]],
                'parser' => $signedParser,
            ],
        ]);
        $cardId = statement_import_profile_create($db, [
            'name' => 'Test Card',
            'evidence_namespace' => 'profile:test-card',
            'schema_version' => 1,
            'definition' => [
                'signature' => ['container' => 'ooxml', 'sheet' => 0, 'header' => $signedHeader, 'markers' => [['column' => 'kontonavn', 'operator' => 'contains', 'value' => 'card']]],
                'parser' => $signedParser,
            ],
        ]);
        $feeId = statement_import_profile_create($db, [
            'name' => 'Test Wallet',
            'evidence_namespace' => 'profile:test-wallet',
            'schema_version' => 1,
            'definition' => [
                'signature' => ['container' => 'ooxml', 'sheet' => 0, 'header' => [
                    ['column' => 0, 'key' => 'type'],
                    ['column' => 1, 'key' => 'produkt'],
                    ['column' => 2, 'key' => 'startdato'],
                    ['column' => 3, 'key' => 'fuldførelsesdato'],
                    ['column' => 4, 'key' => 'beskrivelse'],
                    ['column' => 5, 'key' => 'beløb'],
                    ['column' => 6, 'key' => 'gebyr'],
                    ['column' => 7, 'key' => 'valuta'],
                    ['column' => 8, 'key' => 'state'],
                    ['column' => 9, 'key' => 'saldo'],
                ], 'markers' => []],
                'parser' => [
                    'booking_date' => ['sources' => ['fuldførelsesdato', 'startdato']],
                    'value_date' => ['source' => 'startdato', 'omit_if_same_as_booking' => true],
                    'amount' => ['mode' => 'signed_less_nonnegative_fee', 'amount_column' => 'beløb', 'fee_column' => 'gebyr'],
                    'currency' => ['mode' => 'column', 'column' => 'valuta'],
                    'description' => ['column' => 'beskrivelse', 'counterparty_by_sign' => null],
                    'running_balance' => 'saldo',
                    'reference' => null,
                    'row_type' => ['columns' => ['type']],
                    'account_number' => ['mode' => 'none'],
                    'classification' => null,
                ],
            ],
        ]);
        $debitCreditId = statement_import_profile_create($db, [
            'name' => 'Test Foreign Bank',
            'evidence_namespace' => 'profile:test-foreign-bank',
            'schema_version' => 1,
            'definition' => [
                'signature' => ['container' => 'ooxml', 'sheet' => 0, 'header' => [
                    ['column' => 0, 'key' => 'datumknjienja'],
                    ['column' => 1, 'key' => 'datumvalute'],
                    ['column' => 2, 'key' => 'uplataisplata'],
                    ['column' => 3, 'key' => 'iznosisplate'],
                    ['column' => 4, 'key' => 'iznosuplate'],
                    ['column' => 5, 'key' => 'valuta'],
                    ['column' => 6, 'key' => 'brojtransakcije'],
                    ['column' => 7, 'key' => 'status'],
                    ['column' => 8, 'key' => 'ibanplatitelja'],
                    ['column' => 9, 'key' => 'nazivplatitelja'],
                    ['column' => 10, 'key' => 'adresaplatitelja'],
                    ['column' => 11, 'key' => 'sjediteplatitelja'],
                    ['column' => 12, 'key' => 'pozivnabrojplatitelja'],
                    ['column' => 13, 'key' => 'stvarnidunik'],
                    ['column' => 14, 'key' => 'ibanprimatelja'],
                    ['column' => 15, 'key' => 'nazivprimatelja'],
                    ['column' => 16, 'key' => 'adresaprimatelja'],
                    ['column' => 17, 'key' => 'sjediteprimatelja'],
                    ['column' => 18, 'key' => 'pozivnabrojprimatelja'],
                    ['column' => 19, 'key' => 'opisplaanja'],
                    ['column' => 20, 'key' => 'krajnjiprimatelj'],
                    ['column' => 21, 'key' => 'ifranamjene'],
                    ['column' => 22, 'key' => 'originalniiznosisplate'],
                    ['column' => 23, 'key' => 'originalniiznosuplate'],
                    ['column' => 24, 'key' => 'originalnavaluta'],
                    ['column' => 25, 'key' => 'teaj'],
                ], 'markers' => []],
                'parser' => [
                    'booking_date' => ['sources' => ['datumknjienja']],
                    'value_date' => ['source' => 'datumvalute', 'omit_if_same_as_booking' => false],
                    'amount' => ['mode' => 'debit_credit', 'debit_column' => 'iznosisplate', 'credit_column' => 'iznosuplate'],
                    'currency' => ['mode' => 'column', 'column' => 'valuta'],
                    'description' => ['column' => 'opisplaanja', 'counterparty_by_sign' => ['negative' => 'nazivprimatelja', 'positive' => 'nazivplatitelja']],
                    'running_balance' => null,
                    'reference' => 'brojtransakcije',
                    'row_type' => ['columns' => ['uplataisplata', 'status']],
                    'account_number' => ['mode' => 'preheader_prefixed_digits', 'prefix' => 'račun:', 'minimum_digits' => 4],
                    'classification' => null,
                ],
            ],
        ]);
        return ['ordinary' => $ordinaryId, 'card' => $cardId, 'fee' => $feeId, 'debit_credit' => $debitCreditId];
    }

    /**
     * Provision a complete set of NEUTRAL saved Import Profiles (ordinary signed,
     * card variant, signed amount + fee, debit/credit) inside the isolated test DB.
     * Returns the saved profile IDs by role. Tests exercising NORMAL preview/import
     * SHOULD call this in setUp so the cutover path always has a matching profile.
     */
    protected function provisionNeutralImportProfiles(): array
    {
        $signedHeader = [
            ['column' => 0, 'key' => 'dato'],
            ['column' => 1, 'key' => 'tekst'],
            ['column' => 2, 'key' => 'beløb'],
            ['column' => 3, 'key' => 'saldo'],
            ['column' => 4, 'key' => 'afstemt'],
            ['column' => 5, 'key' => 'kontonummer'],
            ['column' => 6, 'key' => 'kontonavn'],
            ['column' => 7, 'key' => 'hovedkategori'],
            ['column' => 8, 'key' => 'kategori'],
            ['column' => 9, 'key' => 'kommentar'],
        ];
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
            'classification' => ['source' => 'hovedkategori', 'mappings' => [['normalized_equals' => 'indtægter', 'classification' => 'income']]],
        ];
        $ordinaryId = statement_import_profile_create($this->db, [
            'name' => 'Test Bank',
            'evidence_namespace' => 'profile:test-bank',
            'schema_version' => 1,
            'definition' => [
                'signature' => [
                    'container' => 'ooxml', 'sheet' => 0,
                    'header' => $signedHeader,
                    'markers' => [['column' => 'kontonavn', 'operator' => 'not_contains', 'value' => 'card']],
                ],
                'parser' => $signedParser,
            ],
        ]);
        $cardId = statement_import_profile_create($this->db, [
            'name' => 'Test Card',
            'evidence_namespace' => 'profile:test-card',
            'schema_version' => 1,
            'definition' => [
                'signature' => [
                    'container' => 'ooxml', 'sheet' => 0,
                    'header' => $signedHeader,
                    'markers' => [['column' => 'kontonavn', 'operator' => 'contains', 'value' => 'card']],
                ],
                'parser' => $signedParser,
            ],
        ]);
        $feeId = statement_import_profile_create($this->db, [
            'name' => 'Test Wallet',
            'evidence_namespace' => 'profile:test-wallet',
            'schema_version' => 1,
            'definition' => [
                'signature' => [
                    'container' => 'ooxml', 'sheet' => 0,
                    'header' => [
                        ['column' => 0, 'key' => 'type'],
                        ['column' => 1, 'key' => 'produkt'],
                        ['column' => 2, 'key' => 'startdato'],
                        ['column' => 3, 'key' => 'fuldførelsesdato'],
                        ['column' => 4, 'key' => 'beskrivelse'],
                        ['column' => 5, 'key' => 'beløb'],
                        ['column' => 6, 'key' => 'gebyr'],
                        ['column' => 7, 'key' => 'valuta'],
                        ['column' => 8, 'key' => 'state'],
                        ['column' => 9, 'key' => 'saldo'],
                    ],
                    'markers' => [],
                ],
                'parser' => [
                    'booking_date' => ['sources' => ['fuldførelsesdato', 'startdato']],
                    'value_date' => ['source' => 'startdato', 'omit_if_same_as_booking' => true],
                    'amount' => ['mode' => 'signed_less_nonnegative_fee', 'amount_column' => 'beløb', 'fee_column' => 'gebyr'],
                    'currency' => ['mode' => 'column', 'column' => 'valuta'],
                    'description' => ['column' => 'beskrivelse', 'counterparty_by_sign' => null],
                    'running_balance' => 'saldo',
                    'reference' => null,
                    'row_type' => ['columns' => ['type']],
                    'account_number' => ['mode' => 'none'],
                    'classification' => null,
                ],
            ],
        ]);
        $debitCreditId = statement_import_profile_create($this->db, [
            'name' => 'Test Foreign Bank',
            'evidence_namespace' => 'profile:test-foreign-bank',
            'schema_version' => 1,
            'definition' => [
                'signature' => [
                    'container' => 'ooxml', 'sheet' => 0,
                    'header' => [
                        ['column' => 0, 'key' => 'datumknjienja'],
                        ['column' => 1, 'key' => 'datumvalute'],
                        ['column' => 2, 'key' => 'uplataisplata'],
                        ['column' => 3, 'key' => 'iznosisplate'],
                        ['column' => 4, 'key' => 'iznosuplate'],
                        ['column' => 5, 'key' => 'valuta'],
                        ['column' => 6, 'key' => 'brojtransakcije'],
                        ['column' => 7, 'key' => 'status'],
                        ['column' => 8, 'key' => 'ibanplatitelja'],
                        ['column' => 9, 'key' => 'nazivplatitelja'],
                        ['column' => 10, 'key' => 'adresaplatitelja'],
                        ['column' => 11, 'key' => 'sjediteplatitelja'],
                        ['column' => 12, 'key' => 'pozivnabrojplatitelja'],
                        ['column' => 13, 'key' => 'stvarnidunik'],
                        ['column' => 14, 'key' => 'ibanprimatelja'],
                        ['column' => 15, 'key' => 'nazivprimatelja'],
                        ['column' => 16, 'key' => 'adresaprimatelja'],
                        ['column' => 17, 'key' => 'sjediteprimatelja'],
                        ['column' => 18, 'key' => 'pozivnabrojprimatelja'],
                        ['column' => 19, 'key' => 'opisplaanja'],
                        ['column' => 20, 'key' => 'krajnjiprimatelj'],
                        ['column' => 21, 'key' => 'ifranamjene'],
                        ['column' => 22, 'key' => 'originalniiznosisplate'],
                        ['column' => 23, 'key' => 'originalniiznosuplate'],
                        ['column' => 24, 'key' => 'originalnavaluta'],
                        ['column' => 25, 'key' => 'teaj'],
                    ],
                    'markers' => [],
                ],
                'parser' => [
                    'booking_date' => ['sources' => ['datumknjienja']],
                    'value_date' => ['source' => 'datumvalute', 'omit_if_same_as_booking' => false],
                    'amount' => ['mode' => 'debit_credit', 'debit_column' => 'iznosisplate', 'credit_column' => 'iznosuplate'],
                    'currency' => ['mode' => 'column', 'column' => 'valuta'],
                    'description' => ['column' => 'opisplaanja', 'counterparty_by_sign' => ['negative' => 'nazivprimatelja', 'positive' => 'nazivplatitelja']],
                    'running_balance' => null,
                    'reference' => 'brojtransakcije',
                    'row_type' => ['columns' => ['uplataisplata', 'status']],
                    'account_number' => ['mode' => 'preheader_prefixed_digits', 'prefix' => 'račun:', 'minimum_digits' => 4],
                    'classification' => null,
                ],
            ],
        ]);
        return ['ordinary' => $ordinaryId, 'card' => $cardId, 'fee' => $feeId, 'debit_credit' => $debitCreditId];
    }
}
