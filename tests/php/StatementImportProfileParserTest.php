<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class StatementImportProfileParserTest extends DatabaseTestCase
{
    public function testSignedStructureAndSecondLocalProfileShareOneGenericParser(): void
    {
        $rows = $this->worksheetRows([
            ['Booked Date', 'Description', 'Amount', 'Balance', 'Account Number', 'Account Label', 'Category', 'Main Category'],
            ['46237', 'Synthetic salary', '1000.00', '1500.00', 'TEST-ACCOUNT-001', 'Everyday account', 'Credit', 'Income Marker'],
            ['46238', 'Synthetic shopping', '-50.00', '1450.00', '', 'Everyday account', 'Card', 'Other'],
        ]);
        $definition = $this->signedDefinition();
        $testBankA = [
            'name' => 'Test Bank A',
            'definition' => statement_import_profile_validate_definition($definition),
        ];
        $cardDefinition = $definition;
        $cardDefinition['signature']['markers'] = [[
            'column' => 'accountlabel',
            'operator' => 'contains',
            'value' => 'test card marker',
        ]];
        $testCard = [
            'name' => 'Test Card',
            'definition' => statement_import_profile_validate_definition($cardDefinition),
        ];
        $before = $this->databaseSnapshot();

        $bankResult = statement_parse_rows_with_profile($rows, $testBankA['definition']);
        $cardResult = statement_parse_rows_with_profile($rows, $testCard['definition']);

        self::assertSame('Test Bank A', $testBankA['name']);
        self::assertSame('Test Card', $testCard['name']);
        self::assertSame($bankResult, $cardResult, 'Marker/display differences must not create separate parsing behavior');
        self::assertSame(0, $bankResult['header_row_index']);
        self::assertSame('TEST-ACCOUNT-001', $bankResult['file_account_number']);
        self::assertCount(2, $bankResult['rows']);

        $income = $bankResult['rows'][0];
        self::assertSame(2, $income['source_row_number']);
        self::assertSame('2026-08-03', $income['booking_date']);
        self::assertNull($income['value_date']);
        self::assertSame(1000.0, $income['amount']);
        self::assertSame(1000.0, $income['identity_amount']);
        self::assertSame('DKK', $income['currency']);
        self::assertSame('Synthetic salary', $income['description_original']);
        self::assertSame('synthetic salary', $income['description_normalized']);
        self::assertNull($income['provider_reference']);
        self::assertSame(1500.0, $income['running_balance']);
        self::assertSame('Credit', $income['provider_row_type']);
        self::assertSame('', $income['parse_problem']);
        self::assertSame('income', $income['classification']);
        self::assertSame('1000.00', $income['raw_fields']['amount']);
        self::assertSame('Income Marker', $income['raw_fields']['maincategory']);
        self::assertSame('46237', $income['identity_detail']);

        $expense = $bankResult['rows'][1];
        self::assertSame(-50.0, $expense['amount']);
        self::assertSame(1450.0, $expense['running_balance']);
        self::assertSame('Card', $expense['provider_row_type']);
        self::assertNull($expense['classification']);
        self::assertSame($before, $this->databaseSnapshot(), 'Generic parsing must not persist evidence or financial data');
    }

    public function testFeeStructureSupportsDateFallbackValueDateAndFeeRules(): void
    {
        $rows = $this->worksheetRows([
            ['Type', 'Start Date', 'Completed Date', 'Description', 'Amount', 'Fee', 'Currency', 'Balance'],
            ['Transfer', '2026-06-01', 'not-a-date', 'Fallback booking', '100.00', '2.50', 'eur', '997.50'],
            ['Card', '2026-06-02', '2026-06-03', 'Different value date', '-20.00', '', 'EUR', '977.50'],
            ['Transfer', '2026-06-04', '2026-06-04', 'Zero fee', '5.00', '0', 'EUR', '982.50'],
            ['Card', '2026-06-05', '2026-06-05', 'Negative fee', '-5.00', '-0.50', 'EUR', '977.50'],
        ]);
        $testWallet = [
            'name' => 'Test Wallet',
            'definition' => statement_import_profile_validate_definition($this->feeDefinition()),
        ];

        $result = statement_parse_rows_with_profile($rows, $testWallet['definition']);

        self::assertSame('Test Wallet', $testWallet['name']);
        self::assertNull($result['file_account_number']);
        self::assertCount(4, $result['rows']);

        $fallback = $result['rows'][0];
        self::assertSame('2026-06-01', $fallback['booking_date']);
        self::assertNull($fallback['value_date']);
        self::assertSame(97.5, $fallback['amount']);
        self::assertSame(100.0, $fallback['identity_amount']);
        self::assertSame('EUR', $fallback['currency']);
        self::assertSame(997.5, $fallback['running_balance']);
        self::assertSame('Transfer', $fallback['provider_row_type']);
        self::assertSame('2026-06-01|not-a-date', $fallback['identity_detail']);
        self::assertNull($fallback['classification']);
        self::assertSame('', $fallback['parse_problem']);

        $blankFee = $result['rows'][1];
        self::assertSame('2026-06-03', $blankFee['booking_date']);
        self::assertSame('2026-06-02', $blankFee['value_date']);
        self::assertSame(-20.0, $blankFee['amount']);
        self::assertSame('', $blankFee['raw_fields']['fee']);

        $zeroFee = $result['rows'][2];
        self::assertSame(5.0, $zeroFee['amount']);
        self::assertNull($zeroFee['value_date']);

        $negativeFee = $result['rows'][3];
        self::assertNull($negativeFee['amount']);
        self::assertSame(-5.0, $negativeFee['identity_amount']);
        self::assertSame('Invalid fee', $negativeFee['parse_problem']);
    }

    public function testDebitCreditStructureSupportsPreheaderAndOptionalEvidence(): void
    {
        $rows = $this->worksheetRows([
            ['Synthetic statement'],
            ['Account: 0000123456'],
            ['Booked Date', 'Value Date', 'Direction', 'Debit', 'Credit', 'Currency', 'Reference', 'Status', 'Description', 'Payee', 'Payer'],
            ['2026-07-01', '2026-07-02', 'Out', '23.00', '', 'EUR', 'TEST-OUT-1', 'Booked', 'Synthetic purchase', 'Test Recipient', ''],
            ['2026-07-03', '2026-07-04', 'In', '', '50.00', 'EUR', 'TEST-IN-1', 'Booked', 'Synthetic refund', '', 'Test Sender'],
            ['2026-07-05', '2026-07-05', 'Out', '10.00', '', 'EUR', 'TEST-OUT-2', 'Booked', 'Payment to Test Recipient', 'Test Recipient', ''],
            ['2026-07-06', '2026-07-06', 'Out', '10.00', '20.00', 'EUR', 'TEST-BAD-1', 'Rejected', 'Malformed sides', 'Test Recipient', 'Test Sender'],
        ]);
        $testBankB = [
            'name' => 'Test Bank B',
            'definition' => statement_import_profile_validate_definition($this->debitCreditDefinition()),
        ];

        $result = statement_parse_rows_with_profile($rows, $testBankB['definition']);

        self::assertSame('Test Bank B', $testBankB['name']);
        self::assertSame(2, $result['header_row_index']);
        self::assertSame('0000123456', $result['file_account_number']);
        self::assertCount(4, $result['rows']);

        $debit = $result['rows'][0];
        self::assertSame(4, $debit['source_row_number']);
        self::assertSame('2026-07-01', $debit['booking_date']);
        self::assertSame('2026-07-02', $debit['value_date']);
        self::assertSame(-23.0, $debit['amount']);
        self::assertSame('Synthetic purchase · Test Recipient', $debit['description_original']);
        self::assertSame('TEST-OUT-1', $debit['provider_reference']);
        self::assertSame('Out · Booked', $debit['provider_row_type']);
        self::assertNull($debit['running_balance']);
        self::assertNull($debit['classification']);
        self::assertSame('', $debit['parse_problem']);

        $credit = $result['rows'][1];
        self::assertSame(50.0, $credit['amount']);
        self::assertSame('Synthetic refund · Test Sender', $credit['description_original']);
        self::assertSame('TEST-IN-1', $credit['raw_fields']['reference']);

        $suppressedDuplicate = $result['rows'][2];
        self::assertSame('Payment to Test Recipient', $suppressedDuplicate['description_original']);
        self::assertSame(1, substr_count($suppressedDuplicate['description_normalized'], 'test recipient'));

        $malformed = $result['rows'][3];
        self::assertNull($malformed['amount']);
        self::assertSame('Invalid signed amount', $malformed['parse_problem']);
        self::assertSame('Malformed sides', $malformed['description_original']);
    }

    public function testHeaderResolutionRejectsMissingAndDuplicateExactHeaders(): void
    {
        $definition = statement_import_profile_validate_definition($this->minimalSignedDefinition());
        $missing = $this->worksheetRows([
            ['Booked Date', 'Description', 'Other Amount'],
            ['2026-01-01', 'Synthetic row', '1.00'],
        ]);
        $duplicate = $this->worksheetRows([
            ['Booked Date', 'Description', 'Amount'],
            ['Booked Date', 'Description', 'Amount'],
            ['2026-01-01', 'Synthetic row', '1.00'],
        ]);

        $this->assertInvalidParse(fn() => statement_parse_rows_with_profile($missing, $definition), 'does not match');
        $this->assertInvalidParse(fn() => statement_parse_rows_with_profile($duplicate, $definition), 'more than one');
    }

    private function signedDefinition(): array
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
                    ['column' => 5, 'key' => 'accountlabel'],
                    ['column' => 6, 'key' => 'category'],
                    ['column' => 7, 'key' => 'maincategory'],
                ],
                'markers' => [[
                    'column' => 'accountlabel',
                    'operator' => 'not_contains',
                    'value' => 'test card marker',
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
                'account_number' => ['mode' => 'column_first_nonempty', 'column' => 'accountnumber'],
                'classification' => [
                    'source' => 'maincategory',
                    'mappings' => [['normalized_equals' => 'income marker', 'classification' => 'income']],
                ],
            ],
        ];
    }

    private function feeDefinition(): array
    {
        return [
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
        ];
    }

    private function debitCreditDefinition(): array
    {
        return [
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
        ];
    }

    private function minimalSignedDefinition(): array
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

    private function worksheetRows(array $rows): array
    {
        $path = sys_get_temp_dir() . '/fin-profile-parser-' . bin2hex(random_bytes(6)) . '.zip';
        try {
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
            return statement_xlsx_rows((string)file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    private function columnName(int $index): string
    {
        $name = '';
        for ($number = $index + 1; $number > 0; $number = intdiv($number - 1, 26)) {
            $name = chr(65 + (($number - 1) % 26)) . $name;
        }
        return $name;
    }

    private function assertInvalidParse(callable $parse, string $messagePart): void
    {
        try {
            $parse();
            self::fail('Invalid structural header was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString($messagePart, $error->getMessage());
        }
    }

    private function databaseSnapshot(): array
    {
        $snapshot = [];
        foreach ([
            'months', 'entries', 'transactions', 'accounts', 'account_events', 'account_movements',
            'month_funding_context', 'envelopes', 'envelope_movements', 'envelope_transfers',
            'recommendation_exclusions', 'envelope_recommendation_settings',
            'statement_imports', 'statement_rows', 'statement_reconciliations', 'statement_import_profiles',
        ] as $table) {
            $snapshot[$table] = $this->db->query("SELECT * FROM $table ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
        }
        return $snapshot;
    }
}
