<?php

declare(strict_types=1);

require_once __DIR__ . '/Fixtures/IsolatedApiServer.php';

use PHPUnit\Framework\TestCase;
use Tests\Php\Fixtures\IsolatedApiServer;

/**
 * The API router must reject a request that carries no API token and a
 * request that carries an invalid API token, for every action the router
 * accepts.
 *
 * The only exceptions are the pre-login actions, which are meant to be
 * reachable before a session exists. They are listed explicitly below
 * together with the line of the router that reaches them before the
 * authentication gate.
 *
 * The action list is derived from the served router and compared with an
 * explicit list, so a newly added action fails this test until it is covered.
 *
 * Every value is invented here. The test never touches the installation
 * database, the development database or production data.
 */
final class ApiTokenRejectionMatrixTest extends TestCase
{
    /**
     * Every GET action the router accepts.
     */
    private const GET_ACTIONS = [
        'accountBalances',
        'accountHistory',
        'deviceCredentials',
        'fundingRecommendations',
        'history',
        'me',
        'report',
        'state',
        'transactions',
        'transfers',
        'version',
    ];

    /**
     * Every POST action the router accepts.
     */
    private const POST_ACTIONS = [
        'batchReconcileStatementExpenses',
        'createAccountTransfer',
        'createEntry',
        'createTransaction',
        'deleteAccountBalanceObservation',
        'deleteEntry',
        'deleteEnvelope',
        'deleteEnvelopeBalanceObservation',
        'deleteTransaction',
        'duplicateMonth',
        'importStatementEvidence',
        'loadStatementReconciliation',
        'logout',
        'masterPinLogin',
        'previewStatementImport',
        'reconcileEnvelope',
        'removeReconciliationLink',
        'renameWebauthnCredential',
        'revokeWebauthnCredential',
        'saveAccount',
        'saveAccountBalanceObservation',
        'saveEnvelope',
        'saveEnvelopeBalanceObservation',
        'saveMonth',
        'setAccountActive',
        'setAllocationStatus',
        'setPlanAmounts',
        'setRecommendationExclusion',
        'transferEnvelope',
        'undoEnvelopeTransfer',
        'updateEntry',
        'updateTransaction',
        'webauthnAssertionOptions',
        'webauthnAssertionVerify',
        'webauthnRegistrationOptions',
        'webauthnRegistrationVerify',
    ];

    /**
     * The actions the router answers before the authentication gate, so they
     * are reachable without a session. They are the only exceptions.
     *
     * GET `version` and `me` are answered at api.php:63 and api.php:64, the
     * POST pre-login actions at api.php:135, api.php:143, api.php:148 and
     * api.php:157. The authentication gate itself is api.php:66 (GET) and
     * api.php:167 (POST).
     */
    private const PRE_LOGIN_ACTIONS = [
        'version' => 'GET',
        'me' => 'GET',
        'masterPinLogin' => 'POST',
        'webauthnAssertionOptions' => 'POST',
        'webauthnAssertionVerify' => 'POST',
        'logout' => 'POST',
    ];

    private IsolatedApiServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = IsolatedApiServer::start();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    public function testEveryRouterActionRejectsAMissingOrAnInvalidToken(): void
    {
        $router = $this->routerActions();
        self::assertSame(self::GET_ACTIONS, $router['get'], 'The router accepts a GET action this test does not cover');
        self::assertSame(self::POST_ACTIONS, $router['post'], 'The router accepts a POST action this test does not cover');

        // A new action in any other shape still breaks this test: the router
        // labels its actions only in these two ways, so their number is fixed.
        self::assertSame(15, $router['action_label_count'], 'The router gained a new $action comparison');
        self::assertSame(32, $router['case_label_count'], 'The router gained a new switch case');

        $invalidToken = $this->server->createApiToken() . 'x';

        foreach (self::GET_ACTIONS as $action) {
            if (isset(self::PRE_LOGIN_ACTIONS[$action])) {
                $this->assertReachableWithoutSession($action);
                continue;
            }
            $missing = $this->server->get('/api.php?action=' . $action);
            $this->assertRejected($missing, 'GET ' . $action . ' without a token');
            $invalid = $this->server->get('/api.php?action=' . $action, null, $invalidToken);
            $this->assertRejected($invalid, 'GET ' . $action . ' with an invalid token');
        }

        foreach (self::POST_ACTIONS as $action) {
            if (isset(self::PRE_LOGIN_ACTIONS[$action])) {
                $this->assertReachableWithoutSession($action);
                continue;
            }
            $missing = $this->server->post(['action' => $action]);
            $this->assertRejected($missing, 'POST ' . $action . ' without a token');
            $invalid = $this->server->post(['action' => $action], null, $invalidToken);
            $this->assertRejected($invalid, 'POST ' . $action . ' with an invalid token');
        }
    }

    /**
     * The pre-login actions are reachable without a session, so they are the
     * documented exceptions of the matrix.
     */
    private function assertReachableWithoutSession(string $action): void
    {
        if (self::PRE_LOGIN_ACTIONS[$action] === 'GET') {
            $response = $this->server->get('/api.php?action=' . $action);
        } else {
            $payload = ['action' => $action];
            if ($action === 'masterPinLogin') $payload['pin'] = '12345678';
            $response = $this->server->post($payload);
        }
        $decoded = json_decode($response['body'], true);
        self::assertNotSame(
            ['error' => 'Authentication required'],
            $decoded,
            $action . ' is excluded from the matrix, so it must not answer with the authentication gate'
        );
    }

    /** @param array{status:int,body:string,cookie:string} $response */
    private function assertRejected(array $response, string $context): void
    {
        self::assertSame(401, $response['status'], $context . ' must be rejected: ' . $response['body']);
        self::assertSame(['error' => 'Authentication required'], json_decode($response['body'], true), $context);
    }

    /**
     * The action list of the served router.
     *
     * The router labels its actions in exactly two ways: `if ($action ===
     * 'name')` before the POST branch, and `case 'name':` in the POST
     * switch. The counts of both labels are asserted as well, so an action
     * added in a third shape still breaks this test.
     *
     * @return array{get:array<int,string>,post:array<int,string>,action_label_count:int,case_label_count:int}
     */
    private function routerActions(): array
    {
        $source = (string)file_get_contents($this->server->appRoot() . '/api.php');
        $postAt = strpos($source, "if (\$method === 'POST')");
        self::assertNotFalse($postAt, 'The router POST branch was not found');

        $getSource = substr($source, 0, $postAt);
        $postSource = substr($source, $postAt);

        preg_match_all('/\$action === \'([A-Za-z][A-Za-z0-9]*)\'/', $getSource, $getMatches);
        preg_match_all('/\$action === \'([A-Za-z][A-Za-z0-9]*)\'/', $postSource, $postIfMatches);
        preg_match_all('/case \'([A-Za-z][A-Za-z0-9]*)\':/', $postSource, $postCaseMatches);

        $get = array_values(array_unique($getMatches[1]));
        $post = array_values(array_unique(array_merge($postIfMatches[1], $postCaseMatches[1])));
        sort($get);
        sort($post);

        return [
            'get' => $get,
            'post' => $post,
            'action_label_count' => substr_count($source, "\$action === '"),
            'case_label_count' => substr_count($source, "case '"),
        ];
    }
}
