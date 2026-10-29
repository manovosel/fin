<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class HistoryApiTest extends DatabaseTestCase
{
    private string|false $previousToday;

    protected function setUp(): void
    {
        $this->previousToday = getenv('FIN_TODAY');
        putenv('FIN_TODAY=2026-08-14');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->previousToday === false) putenv('FIN_TODAY');
        else putenv('FIN_TODAY=' . $this->previousToday);
        parent::tearDown();
    }

    public function testPastMonthReturnsTheReportModelPlanFundingSpendingAndBalance(): void
    {
        $monthId = $this->createMonth(2026, 7);
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $reserved = $this->createEnvelope('Envelope B', 'reserved');
        $savings = $this->createEnvelope('Envelope C', 'savings');

        $this->fundedAllocation($monthId, $daily, 1000, '2026-07-01');
        $this->plannedAllocation($monthId, $reserved, 500);
        $this->fundedAllocation($monthId, $savings, 2000, '2026-07-03');
        $this->transaction($monthId, $daily, 'Envelope A', 300, '2026-07-05', 'July spending');
        $this->transaction($monthId, $reserved, 'Envelope B', 500, '2026-07-06', 'July rent');

        $history = build_history_response($this->db, '2026-07', '2026-07');
        $month = $history['months'][0];

        self::assertSame('2026-07', $history['from']);
        self::assertSame('2026-07', $history['to']);
        self::assertSame(2026, $month['year']);
        self::assertSame(7, $month['month']);
        self::assertSame('July 2026', $month['label']);
        self::assertSame('past', $month['period']);
        self::assertSame('2026-07-31', $month['cutoff']);

        self::assertEqualsWithDelta(3500, $month['overview']['planned_allocations'], 0.001);
        self::assertEqualsWithDelta(800, $month['overview']['actual_expenses'], 0.001);
        self::assertIsFloat($month['overview']['actual_expenses']);

        self::assertSame('Envelope A', $month['daily']['rows'][0]['envelope']);
        self::assertEqualsWithDelta(1000, $month['daily']['rows'][0]['planned'], 0.001);
        self::assertEqualsWithDelta(1000, $month['daily']['rows'][0]['funded'], 0.001);
        self::assertEqualsWithDelta(300, $month['daily']['rows'][0]['actual'], 0.001);
        self::assertEqualsWithDelta(700, $month['daily']['rows'][0]['difference'], 0.001);
        self::assertEqualsWithDelta(700, $month['daily']['totals']['balance'], 0.001);
        self::assertEqualsWithDelta(500, $month['reserved']['totals']['planned'], 0.001);
        self::assertEqualsWithDelta(0, $month['reserved']['totals']['funded'], 0.001);
        self::assertEqualsWithDelta(500, $month['reserved']['totals']['actual'], 0.001);
        self::assertEqualsWithDelta(2000, $month['savings']['totals']['planned'], 0.001);
        self::assertEqualsWithDelta(2000, $month['savings']['totals']['funded'], 0.001);
        self::assertEqualsWithDelta(0, $month['savings']['totals']['actual'], 0.001);

        // The history response is the report model, not a second calculation.
        $model = build_pdf_report_model($this->db, get_report_data($this->db, '2026-07', '2026-07'));
        self::assertSame($model['months'][0]['daily'], $month['daily']);
        self::assertSame($model['months'][0]['reserved'], $month['reserved']);
        self::assertSame($model['months'][0]['savings'], $month['savings']);
        self::assertEqualsWithDelta($model['months'][0]['overview']['planned_allocations'], $month['overview']['planned_allocations'], 0.001);
        self::assertEqualsWithDelta($model['months'][0]['overview']['actual_expenses'], $month['overview']['actual_expenses'], 0.001);
    }

    public function testIncomeCountsOnlyIncomeEventsAndHidesEverythingElse(): void
    {
        $monthId = $this->createMonth(2026, 7);
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $this->fundedAllocation($monthId, $daily, 1000, '2026-07-01');
        $this->transaction($monthId, $daily, 'Envelope A', 300, '2026-07-05', 'July spending');

        $account = save_account_definition($this->db, ['name' => 'Main', 'account_number' => 'MAIN-1', 'currency' => 'CURRENCY']);
        $other = save_account_definition($this->db, ['name' => 'Reserve', 'account_number' => 'RES-1', 'currency' => 'CURRENCY']);
        record_account_event($this->db, 'income', 25000, '2026-07-05', null, $account, null, 'July salary');
        record_account_event($this->db, 'income', 750, '2026-07-20', null, $account, null, 'Freelance invoice');
        record_account_event($this->db, 'transfer', 1000, '2026-07-10', $account, $other, null, 'Transfer to reserve');
        record_account_event($this->db, 'refund', 250, '2026-07-12', null, $account, null, 'Refund of purchase');
        record_account_event($this->db, 'expense', 100, '2026-07-13', $account, null, null, 'Expense event note');

        $history = build_history_response($this->db, '2026-07', '2026-07');
        $month = $history['months'][0];

        self::assertEqualsWithDelta(25750, $month['income']['total'], 0.001);
        self::assertCount(2, $month['income']['events']);
        self::assertSame(['date' => '2026-07-05', 'amount' => 25000.0, 'note' => 'July salary'], $month['income']['events'][0]);
        self::assertSame(['date' => '2026-07-20', 'amount' => 750.0, 'note' => 'Freelance invoice'], $month['income']['events'][1]);

        $json = json_encode($history, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('Transfer to reserve', $json);
        self::assertStringNotContainsString('Refund of purchase', $json);
        self::assertStringNotContainsString('Expense event note', $json);
        self::assertStringNotContainsString('July spending', $json);
        self::assertStringNotContainsString('planned_income', $json);
        self::assertStringNotContainsString('unallocated_plan_balance', $json);
        self::assertStringNotContainsString('exchange_rate', $json);
        self::assertStringNotContainsString('transfers_adjustments', $json);
        // The Month salary / planned income is not part of the response.
        self::assertArrayNotHasKey('salary', $month);
        self::assertArrayNotHasKey('planned_income', $month['overview']);
        self::assertArrayNotHasKey('exchange_rate', $month);
        // The transaction list is not part of the response; only the totals are.
        self::assertArrayNotHasKey('actual_expenses', $month);
        self::assertSame(['planned_allocations', 'actual_expenses'], array_keys($month['overview']));
    }

    public function testFutureMonthIsReportedWithoutIncome(): void
    {
        $monthId = $this->createMonth(2026, 9);
        $daily = $this->createEnvelope('Envelope A', 'daily');
        $this->plannedAllocation($monthId, $daily, 800);

        $account = save_account_definition($this->db, ['name' => 'Main', 'account_number' => 'MAIN-1', 'currency' => 'CURRENCY']);
        record_account_event($this->db, 'income', 25000, '2026-09-01', null, $account, null, 'September salary');

        $history = build_history_response($this->db, '2026-09', '2026-09');
        $month = $history['months'][0];

        self::assertSame('future', $month['period']);
        self::assertSame('2026-08-14', $month['cutoff']);
        self::assertEqualsWithDelta(800, $month['overview']['planned_allocations'], 0.001);
        self::assertEqualsWithDelta(0, $month['overview']['actual_expenses'], 0.001);
        self::assertEqualsWithDelta(0, $month['income']['total'], 0.001);
        self::assertSame([], $month['income']['events']);
    }

    public function testHistoryRequestRequiresAnAuthenticatedSession(): void
    {
        $server = $this->startServer();
        try {
            $anonymous = $this->request($server['url'] . '/api.php?action=history&from=2026-07&to=2026-07');
            self::assertSame(401, $anonymous['status']);
            self::assertSame(['error' => 'Authentication required'], json_decode($anonymous['body'], true));

            $login = $this->request($server['url'] . '/api.php?action=masterPinLogin', json_encode(['pin' => '12345678']), 'POST');
            self::assertSame(200, $login['status'], $login['body']);
            $cookie = $login['cookie'];
            self::assertNotSame('', $cookie);

            $authenticated = $this->request($server['url'] . '/api.php?action=history&from=2026-07&to=2026-07', null, 'GET', $cookie);
            self::assertSame(200, $authenticated['status'], $authenticated['body']);
            $payload = json_decode($authenticated['body'], true);
            self::assertSame('2026-07', $payload['from']);
            self::assertCount(1, $payload['months']);
            self::assertSame('July 2026', $payload['months'][0]['label']);
            self::assertEqualsWithDelta(25000, $payload['months'][0]['income']['total'], 0.001);
        } finally {
            $this->stopServer($server);
        }
    }

    public function testTheExtendedRangeAndAMonthOutsideTheKnownRangeAreServedOverHttp(): void
    {
        $server = $this->startServer();
        try {
            $login = $this->request($server['url'] . '/api.php?action=masterPinLogin', json_encode(['pin' => '12345678']), 'POST');
            self::assertSame(200, $login['status'], $login['body']);
            $cookie = $login['cookie'];

            $extended = $this->request($server['url'] . '/api.php?action=history&from=2025-01&to=2026-07', null, 'GET', $cookie);
            self::assertSame(200, $extended['status'], $extended['body']);
            $payload = json_decode($extended['body'], true);
            self::assertSame('2025-01', $payload['from']);
            self::assertSame('2026-07', $payload['to']);
            self::assertCount(2, $payload['months']);
            self::assertSame('January 2025', $payload['months'][0]['label']);
            self::assertSame('actual_only', $payload['months'][0]['coverage']);
            self::assertSame('Envelope A', $payload['months'][0]['daily']['rows'][0]['envelope']);
            self::assertEqualsWithDelta(250, $payload['months'][0]['daily']['rows'][0]['actual'], 0.001);
            self::assertArrayNotHasKey('planned', $payload['months'][0]['daily']['rows'][0]);
            self::assertSame('full', $payload['months'][1]['coverage']);

            $unknown = $this->request($server['url'] . '/api.php?action=history&from=2024-01&to=2024-12', null, 'GET', $cookie);
            self::assertSame(404, $unknown['status'], $unknown['body']);
            self::assertSame(['error' => 'No months found in selected range'], json_decode($unknown['body'], true));
        } finally {
            $this->stopServer($server);
        }
    }

    private function fundedAllocation(int $monthId, int $envelopeId, float $amount, string $date): int
    {
        return save_entry($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'type' => 'expense',
            'amount' => $amount,
            'status' => 'paid',
            'due_date' => $date,
        ]);
    }

    private function plannedAllocation(int $monthId, int $envelopeId, float $amount): int
    {
        return save_entry($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'type' => 'expense',
            'amount' => $amount,
            'status' => 'planned',
            'due_date' => '2026-07-02',
        ]);
    }

    private function transaction(int $monthId, int $envelopeId, string $envelope, float $amount, string $date, string $note): int
    {
        return save_transaction($this->db, [
            'month_id' => $monthId,
            'envelope_id' => $envelopeId,
            'envelope' => $envelope,
            'amount' => $amount,
            'spent_date' => $date,
            'note' => $note,
        ]);
    }

    /**
     * Serve the application from an isolated web root with its own SQLite
     * database so the HTTP request is a real request against the real
     * routing, without touching the runtime database.
     *
     * @return array{url:string,process:resource,pipes:array,root:string}
     */
    private function startServer(): array
    {
        $base = sys_get_temp_dir() . '/fin-history-api-' . bin2hex(random_bytes(6));
        $root = $base . '/app';
        $state = $base . '/state';
        mkdir($root, 0700, true);
        mkdir($state, 0700, true);
        $source = dirname(__DIR__, 2);
        foreach (['VERSION', 'accounting.php', 'api.php', 'auth.php', 'config.php', 'currency.php', 'ecb_rates.php', 'recommendation.php', 'report.php', 'statement_import.php', 'statement_reconciliation.php'] as $file) {
            copy($source . '/' . $file, $root . '/' . $file);
        }
        symlink($source . '/vendor', $root . '/vendor');
        file_put_contents($root . '/config.local.php', "<?php\nreturn [\n    'auth' => [\n        'master_pin_file' => '$state/master-pin.hash',\n        'throttle_file' => '$state/master-pin-throttle.json',\n        'file_group' => '',\n        'pin_failure_delay_us' => 0,\n    ],\n    'webauthn_rp_id' => 'localhost',\n    'webauthn_origin' => 'http://localhost',\n];\n");

        $database = $root . '/fin.sqlite';
        $environment = array_merge($_ENV, getenv(), ['FIN_DB_FILE' => $database, 'FIN_TODAY' => '2026-08-14', 'FIN_TEST_APP_ROOT' => $root]);
        $this->runCli($root, ['api.php', 'migrate'], $environment);
        $pin = "12345678\n12345678\n";
        $this->runCli($root, ['api.php', 'set-master-pin', '--stdin'], $environment, $pin);
        $this->seedServerDatabase($root, $environment);

        $port = $this->freePort();
        $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root];
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
        if (!is_resource($process)) self::fail('Could not start the test web server');
        foreach ($pipes as $pipe) stream_set_blocking($pipe, false);

        $server = ['url' => 'http://127.0.0.1:' . $port, 'process' => $process, 'pipes' => $pipes, 'root' => $base];
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket) {
                fclose($socket);
                return $server;
            }
            usleep(100000);
        }
        $this->stopServer($server);
        self::fail('The test web server did not start');
    }

    private function stopServer(array $server): void
    {
        if (isset($server['process']) && is_resource($server['process'])) {
            proc_terminate($server['process']);
            proc_close($server['process']);
        }
        if (isset($server['root']) && is_dir($server['root'])) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($server['root'], FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $path) {
                if ($path->isDir() && !$path->isLink()) @rmdir($path->getPathname());
                else @unlink($path->getPathname());
            }
            @rmdir($server['root']);
        }
    }

    private function seedServerDatabase(string $root, array $environment): void
    {
        $script = <<<'PHP'
$db = db();
migrate($db);
$month = save_month($db, ['year' => 2026, 'month' => 7, 'exchange_rate' => 7.46, 'salary' => 30000]);
$envelope = save_envelope($db, ['name' => 'Envelope A', 'role' => 'daily', 'currency' => 'CURRENCY']);
save_entry($db, ['month_id' => $month, 'envelope_id' => $envelope, 'type' => 'expense', 'amount' => 1000, 'status' => 'paid', 'due_date' => '2026-07-01']);
save_transaction($db, ['month_id' => $month, 'envelope_id' => $envelope, 'envelope' => 'Envelope A', 'amount' => 300, 'spent_date' => '2026-07-05', 'note' => 'July spending']);
save_transaction($db, ['month_id' => null, 'envelope_id' => $envelope, 'envelope' => 'Envelope A', 'amount' => 250, 'spent_date' => '2025-01-10', 'note' => 'January spending']);
$account = save_account_definition($db, ['name' => 'Main', 'account_number' => 'MAIN-1', 'currency' => 'CURRENCY']);
record_account_event($db, 'income', 25000, '2026-07-05', null, $account, null, 'July salary');
PHP;
        $file = $root . '/seed.php';
        file_put_contents($file, "<?php\nrequire_once __DIR__ . '/api.php';\n" . $script . "\n");
        $this->runCli($root, ['seed.php'], $environment);
        @unlink($file);
    }

    private function runCli(string $root, array $arguments, array $environment, ?string $input = null): void
    {
        $command = array_merge([PHP_BINARY], $arguments);
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
        if (!is_resource($process)) self::fail('Could not run ' . implode(' ', $arguments));
        if ($input !== null) fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) self::fail(implode(' ', $arguments) . ' failed: ' . $stdout . $stderr);
    }

    private function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!is_resource($server)) self::fail('Could not allocate a free port');
        $name = stream_socket_get_name($server, false);
        fclose($server);
        return (int)substr((string)$name, strrpos((string)$name, ':') + 1);
    }

    /** @return array{status:int,body:string,cookie:string} */
    private function request(string $url, ?string $body = null, string $method = 'GET', ?string $cookie = null): array
    {
        $headers = [];
        if ($cookie !== null && $cookie !== '') $headers[] = 'Cookie: ' . $cookie;
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        $options = ['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 30]];
        if ($headers) $options['http']['header'] = implode("\r\n", $headers);
        if ($body !== null) $options['http']['content'] = $body;
        $response = @file_get_contents($url, false, stream_context_create($options));
        $status = 0;
        $sessionCookie = '';
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches)) $status = (int)$matches[1];
            if (preg_match('#^Set-Cookie:\s*FINSESSID=([^;]+)#i', $line, $matches)) $sessionCookie = 'FINSESSID=' . $matches[1];
        }
        return ['status' => $status, 'body' => (string)$response, 'cookie' => $sessionCookie];
    }
}
