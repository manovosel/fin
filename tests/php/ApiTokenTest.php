<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseTestCase.php';

final class ApiTokenTest extends DatabaseTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/fin-api-token-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            @rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function testOnlyTheAllowListedReadActionsAcceptTheTokenAndOnlyItsHashIsStored(): void
    {
        $config = $this->config();
        $token = create_api_token($config);
        self::assertNotSame('', $token);

        $file = $config['auth']['api_token_file'];
        $stored = trim((string)file_get_contents($file));
        self::assertSame(hash('sha256', $token), $stored);
        self::assertStringNotContainsString($token, $stored);
        self::assertSame(0600, fileperms($file) & 0777);

        self::assertTrue(api_token_authorized($config, 'GET', 'history', 'Bearer ' . $token));
        self::assertTrue(api_token_authorized($config, 'GET', 'transactions', 'Bearer ' . $token));
        self::assertTrue(api_token_authorized($config, 'GET', 'transfers', 'Bearer ' . $token));
        self::assertTrue(api_token_authorized($config, 'GET', 'fundingRecommendations', 'Bearer ' . $token));
        self::assertTrue(api_token_authorized($config, 'GET', 'accountBalances', 'Bearer ' . $token));
        self::assertFalse(api_token_authorized($config, 'GET', 'state', 'Bearer ' . $token));
        self::assertFalse(api_token_authorized($config, 'GET', 'report', 'Bearer ' . $token));
        self::assertFalse(api_token_authorized($config, 'GET', 'accountHistory', 'Bearer ' . $token));
        self::assertFalse(api_token_authorized($config, 'POST', 'history', 'Bearer ' . $token));
        self::assertFalse(api_token_authorized($config, 'POST', 'transactions', 'Bearer ' . $token));
        self::assertFalse(api_token_authorized($config, 'POST', 'transfers', 'Bearer ' . $token));
        self::assertFalse(api_token_authorized($config, 'POST', 'accountBalances', 'Bearer ' . $token));
        self::assertFalse(api_token_authorized($config, 'GET', 'history', 'Bearer ' . $token . 'x'));
        self::assertFalse(api_token_authorized($config, 'GET', 'history', 'Basic ' . $token));
        self::assertFalse(api_token_authorized($config, 'GET', 'history', null));

        // The token is honoured for exactly one write action and nothing else.
        self::assertTrue(api_token_write_authorized($config, 'POST', 'setPlanAmounts', 'Bearer ' . $token));
        self::assertFalse(api_token_write_authorized($config, 'POST', 'saveMonth', 'Bearer ' . $token));
        self::assertFalse(api_token_write_authorized($config, 'POST', 'createEntry', 'Bearer ' . $token));
        self::assertFalse(api_token_write_authorized($config, 'GET', 'setPlanAmounts', 'Bearer ' . $token));
        self::assertFalse(api_token_write_authorized($config, 'POST', 'setPlanAmounts', 'Bearer ' . $token . 'x'));
        self::assertFalse(api_token_write_authorized($config, 'POST', 'setPlanAmounts', null));

        $replacement = create_api_token($config);
        self::assertNotSame($token, $replacement);
        self::assertFalse(api_token_authorized($config, 'GET', 'history', 'Bearer ' . $token));
        self::assertTrue(api_token_authorized($config, 'GET', 'history', 'Bearer ' . $replacement));
    }

    public function testWorldReadableTokenHashFailsClosed(): void
    {
        $config = $this->config();
        create_api_token($config);
        chmod($config['auth']['api_token_file'], 0644);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API token file permissions must be 0600 or 0640');
        api_token_hash($config);
    }

    public function testTokenReadsTheAllowListedActionsOverHttpAndWritesNothing(): void
    {
        $server = $this->startServer();
        try {
            $token = $this->createToken($server);
            self::assertNotSame('', $token);

            $history = $this->request($server['url'] . '/api.php?action=history&from=2026-07&to=2026-07', null, 'GET', null, $token);
            self::assertSame(200, $history['status'], $history['body']);
            $payload = json_decode($history['body'], true);
            self::assertSame('2026-07', $payload['from']);
            self::assertSame('July 2026', $payload['months'][0]['label']);

            // The token also reads the Months that only exist as actual spending.
            $extended = $this->request($server['url'] . '/api.php?action=history&from=2025-01&to=2025-01', null, 'GET', null, $token);
            self::assertSame(200, $extended['status'], $extended['body']);
            $extendedPayload = json_decode($extended['body'], true);
            self::assertCount(1, $extendedPayload['months']);
            self::assertSame('actual_only', $extendedPayload['months'][0]['coverage']);
            self::assertSame('Envelope A', $extendedPayload['months'][0]['daily']['rows'][0]['envelope']);
            self::assertEqualsWithDelta(250, $extendedPayload['months'][0]['daily']['rows'][0]['actual'], 0.001);

            $recommendations = $this->request($server['url'] . '/api.php?action=fundingRecommendations&year=2026&month=7', null, 'GET', null, $token);
            self::assertSame(200, $recommendations['status'], $recommendations['body']);

            $transactions = $this->request($server['url'] . '/api.php?action=transactions&from=2026-07&to=2026-07', null, 'GET', null, $token);
            self::assertSame(200, $transactions['status'], $transactions['body']);
            $transactionPayload = json_decode($transactions['body'], true);
            self::assertSame('2026-07', $transactionPayload['from']);
            self::assertSame('2026-07', $transactionPayload['to']);
            self::assertCount(1, $transactionPayload['transactions']);
            self::assertSame('July spending', $transactionPayload['transactions'][0]['description']);

            $tooLong = $this->request($server['url'] . '/api.php?action=transactions&from=2026-07&to=2026-10', null, 'GET', null, $token);
            self::assertSame(400, $tooLong['status'], $tooLong['body']);
            self::assertSame(['error' => 'Transaction range cannot exceed 3 months'], json_decode($tooLong['body'], true));

            // The token is ignored everywhere else.
            foreach (['state', 'report&from=2026-07&to=2026-07', 'accountHistory&id=1', 'deviceCredentials'] as $query) {
                $rejected = $this->request($server['url'] . '/api.php?action=' . $query, null, 'GET', null, $token);
                self::assertSame(401, $rejected['status'], $query . ' must not accept the token');
                self::assertSame(['error' => 'Authentication required'], json_decode($rejected['body'], true));
            }

            $write = $this->request($server['url'] . '/api.php', json_encode(['action' => 'saveMonth', 'month' => ['year' => 2026, 'month' => 8, 'exchange_rate' => 7.46]]), 'POST', null, $token);
            self::assertSame(401, $write['status'], $write['body']);
            self::assertSame(['error' => 'Authentication required'], json_decode($write['body'], true));

            $transactionPost = $this->request($server['url'] . '/api.php', json_encode(['action' => 'transactions']), 'POST', null, $token);
            self::assertSame(401, $transactionPost['status'], $transactionPost['body']);
            self::assertSame(['error' => 'Authentication required'], json_decode($transactionPost['body'], true));

            $wrong = $this->request($server['url'] . '/api.php?action=history&from=2026-07&to=2026-07', null, 'GET', null, $token . 'x');
            self::assertSame(401, $wrong['status'], $wrong['body']);
            $missing = $this->request($server['url'] . '/api.php?action=history&from=2026-07&to=2026-07');
            self::assertSame(401, $missing['status'], $missing['body']);

            // The token creates no session.
            $anonymous = $this->request($server['url'] . '/api.php?action=me', null, 'GET', $history['cookie']);
            self::assertSame(200, $anonymous['status'], $anonymous['body']);
            self::assertFalse(json_decode($anonymous['body'], true)['authenticated']);

            // The ordinary session still authenticates every action.
            $login = $this->request($server['url'] . '/api.php?action=masterPinLogin', json_encode(['pin' => '12345678']), 'POST');
            self::assertSame(200, $login['status'], $login['body']);
            $session = $this->request($server['url'] . '/api.php?action=state', null, 'GET', $login['cookie']);
            self::assertSame(200, $session['status'], $session['body']);
            $sessionHistory = $this->request($server['url'] . '/api.php?action=history&from=2026-07&to=2026-07', null, 'GET', $login['cookie']);
            self::assertSame(200, $sessionHistory['status'], $sessionHistory['body']);

            // The rejected write changed nothing.
            $months = $this->request($server['url'] . '/api.php?action=state&year=2026', null, 'GET', $login['cookie']);
            self::assertSame(200, $months['status'], $months['body']);
            self::assertStringNotContainsString('"month":8', $months['body']);

            // A new token revokes the previous one.
            $replacement = $this->createToken($server);
            self::assertNotSame($token, $replacement);
            $revoked = $this->request($server['url'] . '/api.php?action=history&from=2026-07&to=2026-07', null, 'GET', null, $token);
            self::assertSame(401, $revoked['status'], $revoked['body']);
            $accepted = $this->request($server['url'] . '/api.php?action=history&from=2026-07&to=2026-07', null, 'GET', null, $replacement);
            self::assertSame(200, $accepted['status'], $accepted['body']);
        } finally {
            $this->stopServer($server);
        }
    }

    public function testCliWritesOnlyTheHashOfThePrintedToken(): void
    {
        $server = $this->startServer();
        try {
            $token = $this->createToken($server);
            $stored = trim((string)file_get_contents($server['tokenFile']));
            self::assertSame(hash('sha256', $token), $stored);
            self::assertStringNotContainsString($token, $stored);
            self::assertSame(0600, fileperms($server['tokenFile']) & 0777);
            self::assertStringNotContainsString($stored, $token);
        } finally {
            $this->stopServer($server);
        }
    }

    public function testTheTokenAuthorizesOnlyThePlanAmountWrite(): void
    {
        $server = $this->startServer();
        try {
            $token = $this->createToken($server);
            $ids = $this->seedPlanRows($server);

            // The token may use the one write action it is allowed to use.
            $write = $this->request($server['url'] . '/api.php', json_encode([
                'action' => 'setPlanAmounts',
                'year' => 2026,
                'month' => 11,
                'amounts' => [['envelope_id' => $ids['planned'], 'amount' => 4000]],
            ]), 'POST', null, $token);
            self::assertSame(200, $write['status'], $write['body']);
            $payload = json_decode($write['body'], true);
            self::assertTrue($payload['ok']);
            self::assertSame(2026, $payload['year']);
            self::assertSame(11, $payload['month']);
            self::assertCount(1, $payload['rows']);
            self::assertSame($ids['planned'], $payload['rows'][0]['envelope_id']);
            self::assertSame('Plan Envelope', $payload['rows'][0]['envelope']);
            self::assertSame(1000.0, (float)$payload['rows'][0]['previous']);
            self::assertSame(4000.0, (float)$payload['rows'][0]['amount']);
            self::assertSame('planned', $payload['rows'][0]['status']);

            // The write changed the amount and nothing else.
            $month = $this->serverMonthState($server, (int)$ids['month_id']);
            self::assertSame(3, count($month['entries']));
            self::assertSame(4000.0, (float)$month['entries'][0]['amount']);
            self::assertSame('planned', (string)$month['entries'][0]['status']);
            self::assertSame(2000.0, (float)$month['entries'][1]['amount']);
            self::assertSame('paid', (string)$month['entries'][1]['status']);
            self::assertSame(3000.0, (float)$month['entries'][2]['amount']);
            self::assertSame('skipped', (string)$month['entries'][2]['status']);
            self::assertSame(1, (int)$month['movements']);
            self::assertSame(0, (int)$month['transactions']);

            // Every other POST still requires the ordinary session.
            foreach (['saveMonth', 'createEntry', 'updateEntry', 'deleteEntry', 'createTransaction', 'setAllocationStatus'] as $action) {
                $rejected = $this->request($server['url'] . '/api.php', json_encode(['action' => $action]), 'POST', null, $token);
                self::assertSame(401, $rejected['status'], $action . ' must not accept the token');
                self::assertSame(['error' => 'Authentication required'], json_decode($rejected['body'], true));
            }

            // The write action itself is not a GET action.
            $readAttempt = $this->request($server['url'] . '/api.php?action=setPlanAmounts', null, 'GET', null, $token);
            self::assertSame(401, $readAttempt['status'], $readAttempt['body']);

            // The token is still required for the write.
            $anonymous = $this->request($server['url'] . '/api.php', json_encode([
                'action' => 'setPlanAmounts',
                'year' => 2026,
                'month' => 11,
                'amounts' => [['envelope_id' => $ids['planned'], 'amount' => 9999]],
            ]), 'POST');
            self::assertSame(401, $anonymous['status'], $anonymous['body']);
            $wrong = $this->request($server['url'] . '/api.php', json_encode([
                'action' => 'setPlanAmounts',
                'year' => 2026,
                'month' => 11,
                'amounts' => [['envelope_id' => $ids['planned'], 'amount' => 9999]],
            ]), 'POST', null, $token . 'x');
            self::assertSame(401, $wrong['status'], $wrong['body']);
            $unchanged = $this->serverMonthState($server, (int)$ids['month_id']);
            self::assertSame(4000.0, (float)$unchanged['entries'][0]['amount']);

            // The ordinary session still authenticates the write as well.
            $login = $this->request($server['url'] . '/api.php?action=masterPinLogin', json_encode(['pin' => '12345678']), 'POST');
            self::assertSame(200, $login['status'], $login['body']);
            $sessionWrite = $this->request($server['url'] . '/api.php', json_encode([
                'action' => 'setPlanAmounts',
                'year' => 2026,
                'month' => 11,
                'amounts' => [['envelope_id' => $ids['planned'], 'amount' => 4200]],
            ]), 'POST', $login['cookie']);
            self::assertSame(200, $sessionWrite['status'], $sessionWrite['body']);

            // The token still reads exactly the allow-listed GET actions.
            $history = $this->request($server['url'] . '/api.php?action=history&from=2026-07&to=2026-07', null, 'GET', null, $token);
            self::assertSame(200, $history['status'], $history['body']);
            $state = $this->request($server['url'] . '/api.php?action=state', null, 'GET', null, $token);
            self::assertSame(401, $state['status'], $state['body']);

            // The token never authenticates a session.
            $me = $this->request($server['url'] . '/api.php?action=me', null, 'GET', $write['cookie']);
            self::assertSame(200, $me['status'], $me['body']);
            self::assertFalse(json_decode($me['body'], true)['authenticated']);
        } finally {
            $this->stopServer($server);
        }
    }

    public function testTheTokenWriteRejectsAMissingMonthAndNonPlannedRows(): void
    {
        $server = $this->startServer();
        try {
            $token = $this->createToken($server);
            $ids = $this->seedPlanRows($server);

            $missingMonth = $this->request($server['url'] . '/api.php', json_encode([
                'action' => 'setPlanAmounts',
                'year' => 2026,
                'month' => 12,
                'amounts' => [['envelope_id' => $ids['planned'], 'amount' => 4000]],
            ]), 'POST', null, $token);
            self::assertSame(404, $missingMonth['status'], $missingMonth['body']);
            self::assertSame(['error' => 'Month not found'], json_decode($missingMonth['body'], true));

            foreach (['funded' => 'Funded', 'skipped' => 'Skipped'] as $key => $label) {
                $rejected = $this->request($server['url'] . '/api.php', json_encode([
                    'action' => 'setPlanAmounts',
                    'year' => 2026,
                    'month' => 11,
                    'amounts' => [['envelope_id' => $ids[$key], 'amount' => 4000]],
                ]), 'POST', null, $token);
                self::assertSame(409, $rejected['status'], $rejected['body']);
                self::assertStringContainsString($label, json_decode($rejected['body'], true)['error']);
            }

            $month = $this->serverMonthState($server, (int)$ids['month_id']);
            self::assertSame(2000.0, (float)$month['entries'][1]['amount']);
            self::assertSame(3000.0, (float)$month['entries'][2]['amount']);
        } finally {
            $this->stopServer($server);
        }
    }

    public function testTheTokenReadsTheAccountBalancesWithoutChangingAnything(): void
    {
        $server = $this->startServer();
        try {
            $token = $this->createToken($server);
            $ids = $this->seedAccounts($server);

            $before = $this->serverDatabaseDigest($server);

            $balances = $this->request($server['url'] . '/api.php?action=accountBalances', null, 'GET', null, $token);
            self::assertSame(200, $balances['status'], $balances['body']);
            $payload = json_decode($balances['body'], true);
            self::assertSame('2026-08-14', $payload['as_of']);
            self::assertSame('DKK', $payload['currency']);

            $byName = [];
            foreach ($payload['accounts'] as $account) $byName[$account['name']] = $account;

            // An Account with an observation and a later movement.
            self::assertArrayHasKey('Account A', $byName);
            self::assertSame($ids['first'], $byName['Account A']['id']);
            self::assertSame('DKK', $byName['Account A']['currency']);
            self::assertTrue($byName['Account A']['balance_known']);
            self::assertEqualsWithDelta(1500.0, $byName['Account A']['balance'], 0.001);
            self::assertSame('active', $byName['Account A']['status']);
            self::assertSame('2026-08-01', $byName['Account A']['last_observation_date']);

            // An archived Account is reported with its status.
            self::assertArrayHasKey('Account B', $byName);
            self::assertSame($ids['second'], $byName['Account B']['id']);
            self::assertEqualsWithDelta(250.0, $byName['Account B']['balance'], 0.001);
            self::assertSame('archived', $byName['Account B']['status']);
            self::assertSame('2026-08-02', $byName['Account B']['last_observation_date']);

            // An Account without an observation has no balance and no observation date.
            self::assertArrayHasKey('Main', $byName);
            self::assertFalse($byName['Main']['balance_known']);
            self::assertNull($byName['Main']['balance']);
            self::assertNull($byName['Main']['last_observation_date']);

            // No Account number is reported.
            self::assertStringNotContainsString('ACCOUNT-A-1', $balances['body']);
            self::assertStringNotContainsString('ACCOUNT-B-2', $balances['body']);
            self::assertStringNotContainsString('account_number', $balances['body']);

            // The action changed nothing.
            self::assertSame($before, $this->serverDatabaseDigest($server));

            // A POST to the action needs an ordinary session.
            $post = $this->request($server['url'] . '/api.php', json_encode(['action' => 'accountBalances']), 'POST', null, $token);
            self::assertSame(401, $post['status'], $post['body']);
            self::assertSame(['error' => 'Authentication required'], json_decode($post['body'], true));

            // No other POST became available to the token.
            foreach (['saveMonth', 'createEntry', 'createTransaction', 'setAllocationStatus', 'saveAccountBalanceObservation'] as $action) {
                $rejected = $this->request($server['url'] . '/api.php', json_encode(['action' => $action]), 'POST', null, $token);
                self::assertSame(401, $rejected['status'], $action . ' must not accept the token');
                self::assertSame(['error' => 'Authentication required'], json_decode($rejected['body'], true));
            }

            // The other GET actions are unchanged: the session-only Account
            // history still needs a session, the API-token reads still work.
            $accountHistory = $this->request($server['url'] . '/api.php?action=accountHistory&id=1', null, 'GET', null, $token);
            self::assertSame(401, $accountHistory['status'], $accountHistory['body']);
            $history = $this->request($server['url'] . '/api.php?action=history&from=2026-07&to=2026-07', null, 'GET', null, $token);
            self::assertSame(200, $history['status'], $history['body']);
            $state = $this->request($server['url'] . '/api.php?action=state', null, 'GET', null, $token);
            self::assertSame(401, $state['status'], $state['body']);

            // The token creates no session.
            $me = $this->request($server['url'] . '/api.php?action=me', null, 'GET', $balances['cookie']);
            self::assertSame(200, $me['status'], $me['body']);
            self::assertFalse(json_decode($me['body'], true)['authenticated']);

            // The ordinary session reads the same action.
            $login = $this->request($server['url'] . '/api.php?action=masterPinLogin', json_encode(['pin' => '12345678']), 'POST');
            self::assertSame(200, $login['status'], $login['body']);
            $sessionBalances = $this->request($server['url'] . '/api.php?action=accountBalances', null, 'GET', $login['cookie']);
            self::assertSame(200, $sessionBalances['status'], $sessionBalances['body']);
            self::assertSame(
                json_decode($balances['body'], true),
                json_decode($sessionBalances['body'], true),
                'The token and the session must report the same balances'
            );
        } finally {
            $this->stopServer($server);
        }
    }

    public function testTheTokenReadsTheTransfersWithoutChangingAnything(): void
    {
        $server = $this->startServer();
        try {
            $token = $this->createToken($server);
            $this->seedTransfers($server);

            $before = $this->serverDatabaseDigest($server);

            $transfers = $this->request($server['url'] . '/api.php?action=transfers&from=2026-08&to=2026-08', null, 'GET', null, $token);
            self::assertSame(200, $transfers['status'], $transfers['body']);
            $payload = json_decode($transfers['body'], true);
            self::assertSame('2026-08', $payload['from']);
            self::assertSame('2026-08', $payload['to']);
            self::assertCount(2, $payload['transfers']);

            $envelope = null;
            $account = null;
            foreach ($payload['transfers'] as $row) {
                if ($row['kind'] === 'envelope') $envelope = $row;
                if ($row['kind'] === 'account') $account = $row;
            }
            self::assertIsArray($envelope);
            self::assertIsArray($account);
            self::assertSame('Transfer Envelope A', $envelope['from_envelope']);
            self::assertSame('Transfer Envelope B', $envelope['to_envelope']);
            self::assertEqualsWithDelta(100.0, $envelope['amount'], 0.001);
            self::assertSame('2026-08-05', $envelope['date']);
            self::assertFalse($envelope['undone']);
            self::assertSame('Account A', $account['from_account']);
            self::assertSame('Account B', $account['to_account']);
            self::assertEqualsWithDelta(250.0, $account['amount'], 0.001);
            self::assertSame('DKK', $account['currency']);
            self::assertFalse($account['linked_to_statement_row']);
            self::assertNotSame($envelope['payment_id'], $account['payment_id']);

            // No Account number is reported.
            self::assertStringNotContainsString('ACCOUNT-A-1', $transfers['body']);
            self::assertStringNotContainsString('ACCOUNT-B-2', $transfers['body']);

            // The action changed nothing.
            self::assertSame($before, $this->serverDatabaseDigest($server));

            // A POST to the action needs an ordinary session.
            $post = $this->request($server['url'] . '/api.php', json_encode(['action' => 'transfers']), 'POST', null, $token);
            self::assertSame(401, $post['status'], $post['body']);
            self::assertSame(['error' => 'Authentication required'], json_decode($post['body'], true));

            // No other POST became available to the token.
            foreach (['saveMonth', 'createEntry', 'createTransaction', 'setAllocationStatus', 'transferEnvelope', 'undoEnvelopeTransfer'] as $action) {
                $rejected = $this->request($server['url'] . '/api.php', json_encode(['action' => $action]), 'POST', null, $token);
                self::assertSame(401, $rejected['status'], $action . ' must not accept the token');
                self::assertSame(['error' => 'Authentication required'], json_decode($rejected['body'], true));
            }

            // The other GET actions are unchanged.
            $history = $this->request($server['url'] . '/api.php?action=history&from=2026-07&to=2026-07', null, 'GET', null, $token);
            self::assertSame(200, $history['status'], $history['body']);
            $state = $this->request($server['url'] . '/api.php?action=state', null, 'GET', null, $token);
            self::assertSame(401, $state['status'], $state['body']);

            // A range that is too long is rejected.
            $tooLong = $this->request($server['url'] . '/api.php?action=transfers&from=2026-06&to=2026-09', null, 'GET', null, $token);
            self::assertSame(400, $tooLong['status'], $tooLong['body']);
            self::assertSame(['error' => 'Transfer range cannot exceed 3 months'], json_decode($tooLong['body'], true));

            // The token creates no session.
            $me = $this->request($server['url'] . '/api.php?action=me', null, 'GET', $transfers['cookie']);
            self::assertSame(200, $me['status'], $me['body']);
            self::assertFalse(json_decode($me['body'], true)['authenticated']);

            // The ordinary session reads the same action.
            $login = $this->request($server['url'] . '/api.php?action=masterPinLogin', json_encode(['pin' => '12345678']), 'POST');
            self::assertSame(200, $login['status'], $login['body']);
            $sessionTransfers = $this->request($server['url'] . '/api.php?action=transfers&from=2026-08&to=2026-08', null, 'GET', $login['cookie']);
            self::assertSame(200, $sessionTransfers['status'], $sessionTransfers['body']);
            self::assertSame(
                json_decode($transfers['body'], true),
                json_decode($sessionTransfers['body'], true),
                'The token and the session must report the same Transfers'
            );
        } finally {
            $this->stopServer($server);
        }
    }

    private function config(): array
    {
        return [
            'auth' => [
                'master_pin_file' => $this->directory . '/auth/master-pin.hash',
                'throttle_file' => $this->directory . '/state/throttle.json',
                'api_token_file' => $this->directory . '/auth/api-token.hash',
                'file_group' => '',
                'pin_failure_delay_us' => 0,
            ],
        ];
    }

    /**
     * Adds two Accounts to the isolated server database: one active Account with
     * a Balance observation and a later movement, and one archived Account with
     * a Balance observation.
     *
     * @return array{first:int,second:int}
     */
    private function seedAccounts(array $server): array
    {
        $script = <<<'PHP'
$db = db();
migrate($db);
$first = save_account_definition($db, ['name' => 'Account A', 'account_number' => 'ACCOUNT-A-1', 'currency' => 'CURRENCY']);
record_account_balance_observation($db, $first, 1000, '2026-08-01', '', 'manual');
record_account_event($db, 'income', 500, '2026-08-05', null, $first, null, 'Income');
$second = save_account_definition($db, ['name' => 'Account B', 'account_number' => 'ACCOUNT-B-2', 'currency' => 'CURRENCY']);
record_account_balance_observation($db, $second, 250, '2026-08-02', '', 'manual');
set_account_active($db, $second, false);
echo json_encode(['first' => $first, 'second' => $second]);
PHP;
        $file = $server['appRoot'] . '/seed-accounts.php';
        file_put_contents($file, "<?php\nrequire_once __DIR__ . '/api.php';\n" . $script . "\n");
        [$status, $stdout, $stderr] = $this->runCli($server['appRoot'], ['seed-accounts.php'], $server['environment']);
        @unlink($file);
        self::assertSame(0, $status, $stderr);
        $ids = json_decode(trim($stdout), true);
        self::assertIsArray($ids, $stdout);
        return $ids;
    }

    /**
     * Adds one Envelope Transfer and one Account Transfer to the isolated
     * server database.
     */
    private function seedTransfers(array $server): void
    {
        $script = <<<'PHP'
$db = db();
migrate($db);
$month = save_month($db, ['year' => 2026, 'month' => 8, 'exchange_rate' => 7.46, 'salary' => 30000]);
$source = save_account_definition($db, ['name' => 'Account A', 'account_number' => 'ACCOUNT-A-1', 'currency' => 'CURRENCY']);
$destination = save_account_definition($db, ['name' => 'Account B', 'account_number' => 'ACCOUNT-B-2', 'currency' => 'CURRENCY']);
$sourceEnvelope = save_envelope($db, ['name' => 'Transfer Envelope A', 'role' => 'daily', 'currency' => 'CURRENCY', 'default_account_id' => $source]);
$destinationEnvelope = save_envelope($db, ['name' => 'Transfer Envelope B', 'role' => 'daily', 'currency' => 'CURRENCY', 'default_account_id' => $destination]);
record_envelope_balance_observation($db, $sourceEnvelope, 1000, '2026-08-01');
record_envelope_balance_observation($db, $destinationEnvelope, 100, '2026-08-01');
transfer_envelope_balance($db, [
    'source_envelope_id' => $sourceEnvelope,
    'destination_envelope_id' => $destinationEnvelope,
    'amount' => 100,
    'transfer_date' => '2026-08-05',
    'note' => 'Move money',
    'cross_account_confirmed' => true,
]);
record_account_transfer($db, $source, $destination, 250, '2026-08-06', 'Account transfer');
PHP;
        $file = $server['appRoot'] . '/seed-transfers.php';
        file_put_contents($file, "<?php\nrequire_once __DIR__ . '/api.php';\n" . $script . "\n");
        [$status, $stdout, $stderr] = $this->runCli($server['appRoot'], ['seed-transfers.php'], $server['environment']);
        @unlink($file);
        self::assertSame(0, $status, $stderr);
        self::assertStringNotContainsString('"error"', $stdout, $stdout);
    }

    /**
     * A digest of every table of the isolated server database, so a read can be
     * shown to have changed nothing.
     */
    private function serverDatabaseDigest(array $server): string
    {
        $script = <<<'PHP'
$db = db();
$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll();
$digest = [];
foreach ($tables as $table) {
    $rows = $db->query('SELECT * FROM "' . $table['name'] . '"')->fetchAll(PDO::FETCH_ASSOC);
    $digest[(string)$table['name']] = count($rows) . ':' . md5((string)json_encode($rows));
}
echo md5((string)json_encode($digest));
PHP;
        $file = $server['appRoot'] . '/digest.php';
        file_put_contents($file, "<?php\nrequire_once __DIR__ . '/api.php';\n" . $script . "\n");
        [$status, $stdout, $stderr] = $this->runCli($server['appRoot'], ['digest.php'], $server['environment']);
        @unlink($file);
        self::assertSame(0, $status, $stderr);
        return trim($stdout);
    }

    /**
     * Runs the real CLI in the isolated application root and returns the
     * token it printed.
     */
    private function createToken(array $server): string
    {
        [$status, $stdout, $stderr] = $this->runCli($server['appRoot'], ['api.php', 'create-api-token'], $server['environment']);
        self::assertSame(0, $status, $stderr);
        $stored = trim((string)file_get_contents($server['tokenFile']));
        self::assertSame(64, strlen($stored));
        self::assertStringNotContainsString($stored, $stdout);

        $token = '';
        foreach (explode("\n", $stdout) as $line) {
            if (str_starts_with($line, 'API token: ')) $token = trim(substr($line, strlen('API token: ')));
        }
        self::assertNotSame('', $token, $stdout);
        return $token;
    }

    /**
     * Serve the application from an isolated web root with its own SQLite
     * database so the HTTP request is a real request against the real
     * routing, without touching the runtime database.
     *
     * @return array{url:string,process:resource,pipes:array,root:string,appRoot:string,environment:array,tokenFile:string}
     */
    private function startServer(): array
    {
        $base = sys_get_temp_dir() . '/fin-api-token-server-' . bin2hex(random_bytes(6));
        $root = $base . '/app';
        $state = $base . '/state';
        mkdir($root, 0700, true);
        mkdir($state, 0700, true);
        $source = dirname(__DIR__, 2);
        foreach (['VERSION', 'accounting.php', 'api.php', 'auth.php', 'config.php', 'currency.php', 'ecb_rates.php', 'recommendation.php', 'report.php', 'statement_import.php', 'statement_reconciliation.php'] as $file) {
            copy($source . '/' . $file, $root . '/' . $file);
        }
        symlink($source . '/vendor', $root . '/vendor');
        $tokenFile = $state . '/api-token.hash';
        file_put_contents($root . '/config.local.php', "<?php\nreturn [\n    'auth' => [\n        'master_pin_file' => '$state/master-pin.hash',\n        'throttle_file' => '$state/master-pin-throttle.json',\n        'api_token_file' => '$tokenFile',\n        'file_group' => '',\n        'pin_failure_delay_us' => 0,\n    ],\n    'webauthn_rp_id' => 'localhost',\n    'webauthn_origin' => 'http://localhost',\n];\n");

        $database = $root . '/fin.sqlite';
        $environment = array_merge($_ENV, getenv(), ['FIN_DB_FILE' => $database, 'FIN_TODAY' => '2026-08-14', 'FIN_TEST_APP_ROOT' => $root]);
        $this->runCli($root, ['api.php', 'migrate'], $environment);
        $this->runCli($root, ['api.php', 'set-master-pin', '--stdin'], $environment, "12345678\n12345678\n");
        $this->seedServerDatabase($root, $environment);

        $port = $this->freePort();
        $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root];
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
        if (!is_resource($process)) self::fail('Could not start the test web server');
        foreach ($pipes as $pipe) stream_set_blocking($pipe, false);

        $server = ['url' => 'http://127.0.0.1:' . $port, 'process' => $process, 'pipes' => $pipes, 'root' => $base, 'appRoot' => $root, 'environment' => $environment, 'tokenFile' => $tokenFile];
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

    /**
     * Adds one Month with a Planned, a Funded and a Skipped Plan row to the
     * isolated server database and returns their identifiers.
     *
     * @return array{month_id:int,planned:int,funded:int,skipped:int}
     */
    private function seedPlanRows(array $server): array
    {
        $script = <<<'PHP'
$db = db();
migrate($db);
$month = save_month($db, ['year' => 2026, 'month' => 11, 'exchange_rate' => 7.46, 'salary' => 30000]);
$planned = save_envelope($db, ['name' => 'Plan Envelope', 'role' => 'daily', 'currency' => 'CURRENCY']);
$funded = save_envelope($db, ['name' => 'Funded Envelope', 'role' => 'daily', 'currency' => 'CURRENCY']);
$skipped = save_envelope($db, ['name' => 'Skipped Envelope', 'role' => 'daily', 'currency' => 'CURRENCY']);
save_entry($db, ['month_id' => $month, 'envelope_id' => $planned, 'type' => 'expense', 'amount' => 1000, 'status' => 'planned']);
save_entry($db, ['month_id' => $month, 'envelope_id' => $funded, 'type' => 'expense', 'amount' => 2000, 'status' => 'paid']);
save_entry($db, ['month_id' => $month, 'envelope_id' => $skipped, 'type' => 'expense', 'amount' => 3000, 'status' => 'skipped']);
echo json_encode(['month_id' => $month, 'planned' => $planned, 'funded' => $funded, 'skipped' => $skipped]);
PHP;
        $file = $server['appRoot'] . '/seed-plan.php';
        file_put_contents($file, "<?php\nrequire_once __DIR__ . '/api.php';\n" . $script . "\n");
        [$status, $stdout, $stderr] = $this->runCli($server['appRoot'], ['seed-plan.php'], $server['environment']);
        @unlink($file);
        self::assertSame(0, $status, $stderr);
        $ids = json_decode(trim($stdout), true);
        self::assertIsArray($ids, $stdout);
        return $ids;
    }

    /**
     * Reads the Plan rows, movements and transactions of one Month back from
     * the isolated server database.
     *
     * @return array{entries:array<int,array<string,mixed>>,movements:int,transactions:int}
     */
    private function serverMonthState(array $server, int $monthId): array
    {
        $script = <<<'PHP'
$db = db();
$monthId = (int)getenv('FIN_QUERY_MONTH_ID');
echo json_encode([
    'entries' => $db->query('SELECT id, envelope_id, amount, status, is_bs, due_date FROM entries WHERE month_id = ' . $monthId . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    'movements' => (int) $db->query('SELECT COUNT(*) FROM envelope_movements WHERE month_id = ' . $monthId)->fetchColumn(),
    'transactions' => (int) $db->query('SELECT COUNT(*) FROM transactions WHERE month_id = ' . $monthId)->fetchColumn(),
]);
PHP;
        $file = $server['appRoot'] . '/query-month.php';
        file_put_contents($file, "<?php\nrequire_once __DIR__ . '/api.php';\n" . $script . "\n");
        $environment = array_merge($server['environment'], ['FIN_QUERY_MONTH_ID' => (string)$monthId]);
        [$status, $stdout, $stderr] = $this->runCli($server['appRoot'], ['query-month.php'], $environment);
        @unlink($file);
        self::assertSame(0, $status, $stderr);
        $state = json_decode(trim($stdout), true);
        self::assertIsArray($state, $stdout);
        return $state;
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

    /** @return array{int,string,string} */
    private function runCli(string $root, array $arguments, array $environment, ?string $input = null): array
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
        return [$status, (string)$stdout, (string)$stderr];
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
    private function request(string $url, ?string $body = null, string $method = 'GET', ?string $cookie = null, ?string $bearer = null): array
    {
        $headers = [];
        if ($cookie !== null && $cookie !== '') $headers[] = 'Cookie: ' . $cookie;
        if ($bearer !== null && $bearer !== '') $headers[] = 'Authorization: Bearer ' . $bearer;
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
