<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

/**
 * One real movement, one Transfer.
 *
 * One payment can be reported by two statements: the debit on one Account's statement and
 * the credit on the other Account's statement. Both rows describe one movement, so they
 * must produce one Transfer. These tests pin that rule, its safeguards, and the rule that
 * a statement which cannot show the counterpart Account never gets a guessed destination.
 */
final class StatementTransferTwinTest extends DatabaseTestCase
{
    private string $currency;
    private int $sourceRow = 0;
    private string|false $previousToday = false;
    /** @var array<int,string> */
    private array $accountNumbers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousToday = getenv('FIN_TODAY');
        putenv('FIN_TODAY=2026-11-30');
        $this->currency = (string)currency_context($this->db)['currency'];
    }

    protected function tearDown(): void
    {
        if ($this->previousToday === false) putenv('FIN_TODAY');
        else putenv('FIN_TODAY=' . $this->previousToday);
        parent::tearDown();
    }

    /**
     * The two sides of one movement become ONE Transfer, and both statement rows point
     * at it.
     */
    public function testTheTwoSidesOfOneMovementBecomeOneTransfer(): void
    {
        $this->createMonth(2026, 9);
        $wallet = $this->account('Wallet Account', 'WALLET');
        $card = $this->account('Card Account', 'CARD');

        // The same top-up, seen from both sides.
        $walletRow = $this->statementRow($wallet, '2026-09-04', -512.00, 'Indbetal *0100');
        $cardRow = $this->statementRow($card, '2026-09-04', 512.00, 'MC Wallet**1234*');

        $walletResult = statement_reconciliation_batch_expenses($this->db, $wallet, [
            ['statement_row_id' => $walletRow, 'classification' => 'account:' . $card],
        ]);
        self::assertSame('saved', $walletResult[0]['status']);
        $eventId = (int)$walletResult[0]['account_event_id'];

        $cardResult = statement_reconciliation_batch_expenses($this->db, $card, [
            ['statement_row_id' => $cardRow, 'classification' => 'account:' . $wallet],
        ]);
        self::assertSame('saved', $cardResult[0]['status']);
        self::assertSame($eventId, (int)$cardResult[0]['account_event_id']);

        self::assertSame(1, $this->tableCount('account_events'));
        self::assertSame(2, (int)$this->db->query('SELECT COUNT(*) FROM statement_reconciliations WHERE target_account_event_id=' . $eventId)->fetchColumn());

        $event = $this->db->query("SELECT source_account_id,destination_account_id,amount,event_date FROM account_events WHERE id=$eventId")->fetch();
        self::assertSame($wallet, (int)$event['source_account_id']);
        self::assertSame($card, (int)$event['destination_account_id']);
        self::assertEqualsWithDelta(512.0, (float)$event['amount'], 0.001);

        // The Transfers list reports the one real payment once.
        $transfers = build_transfers_response($this->db, '2026-09', '2026-09')['transfers'];
        $accountTransfers = array_values(array_filter($transfers, static fn(array $row): bool => $row['kind'] === 'account'));
        self::assertCount(1, $accountTransfers);
        self::assertSame('account:' . $eventId, $accountTransfers[0]['payment_id']);
    }

    /**
     * The automatic path links the other side of one movement to the same Transfer.
     */
    public function testTheAutomaticPathLinksTheOtherSideOfOneMovement(): void
    {
        $this->createMonth(2026, 9);
        $wallet = $this->account('Wallet Account', 'WALLET');
        $card = $this->account('Card Account', 'CARD');

        $walletRow = $this->statementRow($wallet, '2026-09-04', -512.00, 'Indbetal *0100');
        $cardRow = $this->statementRow($card, '2026-09-04', 512.00, 'MC Wallet**1234*');

        statement_reconciliation_batch_expenses($this->db, $wallet, [
            ['statement_row_id' => $walletRow, 'classification' => 'account:' . $card],
        ]);

        $result = statement_reconciliation_auto_process_row($this->db, $cardRow);
        self::assertSame('processed', $result['outcome']);
        self::assertSame('link_existing', $result['action']);
        self::assertSame(1, $this->tableCount('account_events'));
        self::assertSame(2, (int)$this->db->query('SELECT COUNT(*) FROM statement_reconciliations WHERE target_account_event_id IS NOT NULL')->fetchColumn());
    }

    /**
     * Two genuine movements of the same amount in the same window stay two Transfers.
     */
    public function testTwoGenuineMovementsOfTheSameAmountStayTwoTransfers(): void
    {
        $this->createMonth(2026, 10);
        $wallet = $this->account('Wallet Account', 'WALLET');
        $card = $this->account('Card Account', 'CARD');

        $firstRow = $this->statementRow($wallet, '2026-10-05', -100.00, 'First movement');
        $secondRow = $this->statementRow($wallet, '2026-10-05', -100.00, 'Second movement');

        statement_reconciliation_batch_expenses($this->db, $wallet, [
            ['statement_row_id' => $firstRow, 'classification' => 'account:' . $card],
        ]);
        statement_reconciliation_batch_expenses($this->db, $wallet, [
            ['statement_row_id' => $secondRow, 'classification' => 'account:' . $card],
        ]);

        self::assertSame(2, $this->tableCount('account_events'));
        self::assertSame(2, (int)$this->db->query('SELECT COUNT(DISTINCT target_account_event_id) FROM statement_reconciliations')->fetchColumn());
    }

    /**
     * When several existing Transfers could be the twin, the row gets its own Transfer.
     * A visible duplicate is preferable to a wrong link.
     */
    public function testAnAmbiguousTwinMatchCreatesANewTransfer(): void
    {
        $this->createMonth(2026, 9);
        $wallet = $this->account('Wallet Account', 'WALLET');
        $card = $this->account('Card Account', 'CARD');

        // Two Transfers of the same amount and date whose card side is already
        // reconciled, so both are still eligible for a wallet-side row.
        $cardRowOne = $this->statementRow($card, '2026-09-04', 512.00, 'MC Wallet**1234*');
        $cardRowTwo = $this->statementRow($card, '2026-09-04', 512.00, 'MC Wallet**1111*');
        statement_reconciliation_batch_expenses($this->db, $card, [
            ['statement_row_id' => $cardRowOne, 'classification' => 'account:' . $wallet],
        ]);
        statement_reconciliation_batch_expenses($this->db, $card, [
            ['statement_row_id' => $cardRowTwo, 'classification' => 'account:' . $wallet],
        ]);
        self::assertSame(2, $this->tableCount('account_events'));

        $walletRow = $this->statementRow($wallet, '2026-09-04', -512.00, 'Indbetal *0100');
        $result = statement_reconciliation_batch_expenses($this->db, $wallet, [
            ['statement_row_id' => $walletRow, 'classification' => 'account:' . $card],
        ]);

        self::assertSame('saved', $result[0]['status']);
        self::assertSame(3, $this->tableCount('account_events'));
    }

    /**
     * A statement that names no counterpart Account gets no Transfer destination from
     * history: the destination is the owner's choice.
     */
    public function testNoTransferDestinationIsPreFilledWhenTheStatementNamesNoCounterpart(): void
    {
        $wallet = $this->account('Wallet Account', 'WALLET');
        $foreign = $this->account('Foreign Account', 'FOREIGN', 'HELPER_CURRENCY');
        $bank = $this->account('Bank Account', 'BANK');

        $firstRow = $this->statementRow($wallet, '2026-09-02', -800.00, 'Udenlandsk overførsel til konto-ejer');
        $secondRow = $this->statementRow($wallet, '2026-10-02', -800.00, 'Udenlandsk overførsel til konto-ejer');

        // The statement carries no counterpart account number at all.
        self::assertSame([], statement_reconciliation_exact_counterpart_accounts($this->db, statement_reconciliation_load_row($this->db, $firstRow)));

        statement_reconciliation_batch_expenses($this->db, $wallet, [
            ['statement_row_id' => $firstRow, 'classification' => 'account:' . $foreign],
        ]);

        $preselections = statement_reconciliation_history_preselections($this->db, $wallet, [
            statement_reconciliation_load_row($this->db, $secondRow),
        ]);
        self::assertSame([], $preselections);

        // The owner chooses the destination; the earlier pairing is not repeated by
        // default.
        $events = $this->db->query("SELECT source_account_id,destination_account_id FROM account_events WHERE event_type='transfer' ORDER BY id")->fetchAll();
        self::assertCount(1, $events);
        self::assertSame($foreign, (int)$events[0]['destination_account_id']);
        self::assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM account_events WHERE destination_account_id=$bank")->fetchColumn());
    }

    /**
     * Where the statement names the counterpart Account, the history pre-selection is
     * unchanged.
     */
    public function testTheTransferDestinationIsPreFilledWhenTheStatementNamesTheCounterpart(): void
    {
        $wallet = $this->account('Wallet Account', 'WALLET');
        $card = $this->account('Card Account', 'CARD');

        $firstRow = $this->statementRow($wallet, '2026-09-02', -800.00, 'Overførsel til modtager', [
            'modtagerkontonummer' => $this->accountNumbers[$card],
        ]);
        $secondRow = $this->statementRow($wallet, '2026-10-02', -800.00, 'Overførsel til modtager', [
            'modtagerkontonummer' => $this->accountNumbers[$card],
        ]);

        statement_reconciliation_batch_expenses($this->db, $wallet, [
            ['statement_row_id' => $firstRow, 'classification' => 'account:' . $card],
        ]);

        $preselections = statement_reconciliation_history_preselections($this->db, $wallet, [
            statement_reconciliation_load_row($this->db, $secondRow),
        ]);
        self::assertCount(1, $preselections);
        self::assertSame('account:' . $card, $preselections[0]['classification']);
    }

    /**
     * The Envelope pre-selection is unchanged: a category is a good guess from earlier
     * history, whether or not the statement names a counterpart Account.
     */
    public function testTheEnvelopePreSelectionIsUnchanged(): void
    {
        $wallet = $this->account('Wallet Account', 'WALLET');
        $envelope = $this->createEnvelope('Envelope A');
        save_transaction($this->db, [
            'envelope_id' => $envelope,
            'payment_account_id' => $wallet,
            'amount' => 10,
            'note' => 'Udenlandsk overførsel til konto-ejer',
            'spent_date' => '2026-09-01',
        ]);

        $row = $this->statementRow($wallet, '2026-10-02', -800.00, 'Udenlandsk overførsel til konto-ejer');
        $preselections = statement_reconciliation_history_preselections($this->db, $wallet, [
            statement_reconciliation_load_row($this->db, $row),
        ]);

        self::assertCount(1, $preselections);
        self::assertSame('envelope:' . $envelope, $preselections[0]['classification']);
    }

    /**
     * A movement dated on the observation day is already inside the observed end-of-day
     * balance and is never added again.
     */
    public function testAMovementOnTheObservationDayIsNotAddedToTheObservedBalance(): void
    {
        $wallet = $this->account('Wallet Account', 'WALLET');
        $envelope = $this->createEnvelope('Envelope A');
        record_account_balance_observation($this->db, $wallet, 100.00, '2026-10-26');

        save_transaction($this->db, [
            'month_id' => null,
            'envelope_id' => $envelope,
            'payment_account_id' => $wallet,
            'amount' => 67.90,
            'spent_date' => '2026-10-26',
            'note' => 'GROCERY SHOP',
        ]);

        self::assertEqualsWithDelta(100.00, (float)account_balance_as_of($this->db, $wallet, '2026-10-26'), 0.001);
        self::assertEqualsWithDelta(100.00, (float)account_balance_as_of($this->db, $wallet, '2026-10-27'), 0.001);
        self::assertEqualsWithDelta(100.00, (float)account_balance_as_of($this->db, $wallet, '2026-11-30'), 0.001);
        $movement = $this->db->query("SELECT amount,movement_date FROM account_movements WHERE account_id=$wallet")->fetch();
        self::assertEqualsWithDelta(-67.90, (float)$movement['amount'], 0.001);
        self::assertSame('2026-10-26', (string)$movement['movement_date']);
    }

    private function account(string $name, string $number, string $currency = 'CURRENCY'): int
    {
        $this->sourceRow++;
        $accountNumber = 'TEST-' . $number . '-' . $this->sourceRow;
        $accountId = save_account_definition($this->db, [
            'name' => $name,
            'account_number' => $accountNumber,
            'currency' => $currency,
        ]);
        $this->accountNumbers[$accountId] = $accountNumber;
        return $accountId;
    }

    private function statementRow(int $accountId, string $date, float $amount, string $description, array $rawFields = []): int
    {
        $this->sourceRow++;
        $checksum = hash('sha256', $accountId . '|' . $this->sourceRow . '|' . $description);
        $stmt = $this->db->prepare('INSERT INTO statement_imports
            (account_id,provider,original_filename,file_checksum,parser_version,row_count,valid_row_count,problem_count)
            VALUES (:account_id,:provider,:filename,:checksum,:parser_version,1,1,0)');
        $stmt->execute([
            ':account_id' => $accountId,
            ':provider' => 'test_provider',
            ':filename' => 'fixture-' . $this->sourceRow . '.xlsx',
            ':checksum' => $checksum,
            ':parser_version' => STATEMENT_PARSER_VERSION,
        ]);
        $importId = (int)$this->db->lastInsertId();
        $insert = $this->db->prepare('INSERT INTO statement_rows
            (import_id,account_id,provider,source_row_number,booking_date,value_date,amount,currency,description_original,description_normalized,row_fingerprint,occurrence_index,parse_problem,raw_fields_json)
            VALUES (:import_id,:account_id,:provider,:source_row,:date,:date,:amount,:currency,:description,:normalized,:fingerprint,0,\'\',:raw_fields)');
        $insert->execute([
            ':import_id' => $importId,
            ':account_id' => $accountId,
            ':provider' => 'test_provider',
            ':source_row' => $this->sourceRow,
            ':date' => $date,
            ':amount' => $amount,
            ':currency' => $this->currency,
            ':description' => $description,
            ':normalized' => statement_normalize_token($description),
            ':fingerprint' => hash('sha256', $checksum . '|row'),
            ':raw_fields' => json_encode($rawFields === [] ? new stdClass() : $rawFields),
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function tableCount(string $table): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }
}
