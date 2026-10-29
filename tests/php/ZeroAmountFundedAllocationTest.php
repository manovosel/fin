<?php

declare(strict_types=1);

require_once __DIR__ . '/Fixtures/IsolatedApiServer.php';

use PHPUnit\Framework\TestCase;
use Tests\Php\Fixtures\IsolatedApiServer;

/**
 * The Funding invariant "only a positive Funded allocation creates a Funding
 * movement".
 *
 * The test drives the real HTTP router with the very same action the Month
 * screen's Plan/Funded/Skipped control uses (`setAllocationStatus`), so the
 * behaviour is the one the owner actually sees and not a shortcut through an
 * internal helper.
 *
 * Every value is invented here. The test never touches the installation
 * database, the development database or production data.
 */
final class ZeroAmountFundedAllocationTest extends TestCase
{
    private IsolatedApiServer $server;
    private string $cookie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = IsolatedApiServer::start();
        // The Month screen is an authenticated screen, so the test acts as the
        // owner does: it logs in with the Master PIN first.
        $this->cookie = $this->server->login();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    public function testAZeroAmountFundedAllocationCreatesNoFundingMovementAndChangesNoBalance(): void
    {
        $ids = $this->seedMonthAndEnvelopes();

        $zeroBefore = $this->envelopeState($ids['zero']);
        $positiveBefore = $this->envelopeState($ids['positive']);
        self::assertEqualsWithDelta(250.0, $zeroBefore['balance'], 0.001, 'The zero Envelope starts with a known balance');
        self::assertEqualsWithDelta(0.0, $positiveBefore['balance'], 0.001, 'The positive Envelope starts without a balance');

        // The Plan row of each Envelope is created the way the allocation
        // dialog creates it: a Planned row with an amount.
        $zeroRow = $this->createPlanRow($ids['month'], $ids['zero'], 0);
        self::assertSame(200, $zeroRow['status'], $zeroRow['body']);

        $positiveRow = $this->createPlanRow($ids['month'], $ids['positive'], 1500);
        self::assertSame(200, $positiveRow['status'], $positiveRow['body']);

        // The Plan/Funded/Skipped control funds both rows.
        $zeroFunded = $this->fund($ids['month'], $ids['zero']);
        self::assertSame(200, $zeroFunded['status'], $zeroFunded['body']);

        $positiveFunded = $this->fund($ids['month'], $ids['positive']);
        self::assertSame(200, $positiveFunded['status'], $positiveFunded['body']);

        $zeroAfter = $this->envelopeState($ids['zero']);
        $positiveAfter = $this->envelopeState($ids['positive']);

        // The zero row really is Funded, it is only its amount that is zero.
        self::assertSame('paid', $zeroAfter['status'], 'The zero row is Funded');
        self::assertEqualsWithDelta(0.0, $zeroAfter['amount'], 0.001, 'The zero row keeps its zero amount');

        // A Funded allocation of zero creates no Funding movement.
        self::assertSame(0, $zeroAfter['movements'], 'A Funded allocation of 0 creates no Funding movement');
        self::assertSame([], $zeroAfter['movement_rows'], 'A Funded allocation of 0 creates no movement at all');

        // ... and it changes no balance.
        self::assertEqualsWithDelta(250.0, $zeroAfter['balance'], 0.001, 'A Funded allocation of 0 changes no balance');

        // A positive Funded allocation does create the Funding movement and
        // does change the balance.
        self::assertSame('paid', $positiveAfter['status'], 'The positive row is Funded');
        self::assertEqualsWithDelta(1500.0, $positiveAfter['amount'], 0.001, 'The positive row keeps its amount');
        self::assertSame(1, $positiveAfter['movements'], 'A positive Funded allocation creates one Funding movement');
        self::assertSame('funding', $positiveAfter['movement_rows'][0]['type']);
        self::assertEqualsWithDelta(1500.0, (float)$positiveAfter['movement_rows'][0]['amount'], 0.001);
        self::assertEqualsWithDelta(1500.0, $positiveAfter['balance'], 0.001, 'A positive Funded allocation adds to the balance');

        // The two Envelopes are independent: the zero one changed nothing.
        self::assertEqualsWithDelta($zeroBefore['balance'], $zeroAfter['balance'], 0.001);
        self::assertSame(0, $zeroBefore['movements']);
        self::assertSame(0, $positiveBefore['movements']);
    }

    /**
     * Creates the Month and the two Envelopes of the test.
     *
     * @return array{month:int,zero:int,positive:int}
     */
    private function seedMonthAndEnvelopes(): array
    {
        $script = <<<'PHP'
$db = db();
migrate($db);
$month = save_month($db, ['year' => 2026, 'month' => 7, 'exchange_rate' => 7.46, 'salary' => 30000]);
$zero = save_envelope($db, ['name' => 'Envelope Zero', 'role' => 'daily', 'currency' => 'CURRENCY']);
$positive = save_envelope($db, ['name' => 'Envelope Positive', 'role' => 'daily', 'currency' => 'CURRENCY']);
record_envelope_balance_observation($db, $zero, 250, '2026-07-01');
echo json_encode(['month' => $month, 'zero' => $zero, 'positive' => $positive]);
PHP;
        $ids = json_decode(trim($this->server->runPhp($script)), true);
        self::assertIsArray($ids);
        return $ids;
    }

    /** @return array{status:int,body:string,cookie:string} */
    private function createPlanRow(int $monthId, int $envelopeId, float $amount): array
    {
        return $this->server->post([
            'action' => 'createEntry',
            'entry' => [
                'month_id' => $monthId,
                'envelope_id' => $envelopeId,
                'type' => 'expense',
                'amount' => $amount,
                'status' => 'planned',
                'due_date' => '2026-07-01',
            ],
        ], $this->cookie);
    }

    /**
     * The Plan/Funded/Skipped control of the Month screen.
     *
     * @return array{status:int,body:string,cookie:string}
     */
    private function fund(int $monthId, int $envelopeId): array
    {
        return $this->server->post([
            'action' => 'setAllocationStatus',
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'status' => 'paid',
            'year' => 2026,
        ], $this->cookie);
    }

    /**
     * The Plan row, the movements and the balance of one Envelope, read back
     * from the server database.
     *
     * @return array{status:string,amount:float,movements:int,movement_rows:array<int,array<string,mixed>>,balance:float}
     */
    private function envelopeState(int $envelopeId): array
    {
        $script = sprintf(<<<'PHP'
$db = db();
$envelopeId = %d;
$row = $db->prepare("SELECT status, amount FROM entries WHERE envelope_id = :id AND type <> 'income' ORDER BY id LIMIT 1");
$row->execute([':id' => $envelopeId]);
$entry = $row->fetch() ?: ['status' => '', 'amount' => 0];
$movements = $db->prepare('SELECT id, type, amount, movement_date FROM envelope_movements WHERE envelope_id = :id ORDER BY id');
$movements->execute([':id' => $envelopeId]);
$rows = $movements->fetchAll(PDO::FETCH_ASSOC);
$balances = envelope_balances_as_of($db, effective_today());
echo json_encode([
    'status' => (string)$entry['status'],
    'amount' => (float)$entry['amount'],
    'movements' => count($rows),
    'movement_rows' => $rows,
    'balance' => (float)($balances[$envelopeId] ?? 0.0),
]);
PHP, $envelopeId);
        $state = json_decode(trim($this->server->runPhp($script)), true);
        self::assertIsArray($state);
        return $state;
    }
}
