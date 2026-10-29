<?php

declare(strict_types=1);

require_once __DIR__ . '/Fixtures/IsolatedApiServer.php';

use PHPUnit\Framework\TestCase;
use Tests\Php\Fixtures\IsolatedApiServer;

/**
 * The API-token read of the History action must not change anything.
 *
 * The test takes the same full-database before/after digest that the
 * `accountBalances` and `transfers` token reads use, around a token
 * authorized GET `history` over real HTTP, and proves the digest is
 * identical.
 *
 * Every value is invented here. The test never touches the installation
 * database, the development database or production data.
 */
final class ApiTokenHistoryReadTest extends TestCase
{
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

    public function testTheTokenReadsHistoryWithoutChangingAnything(): void
    {
        $token = $this->server->createApiToken();
        self::assertNotSame('', $token);

        $before = $this->server->databaseDigest();

        $history = $this->server->get('/api.php?action=history&from=2026-07&to=2026-07', null, $token);
        self::assertSame(200, $history['status'], $history['body']);
        $payload = json_decode($history['body'], true);
        self::assertIsArray($payload, $history['body']);
        self::assertSame('2026-07', $payload['from']);
        self::assertSame('2026-07', $payload['to']);
        self::assertCount(1, $payload['months']);
        self::assertSame('July 2026', $payload['months'][0]['label']);

        // The read changed nothing at all.
        self::assertSame($before, $this->server->databaseDigest(), 'The token History read changed the database');

        // The read is the ordinary one: an ordinary session reports the same
        // History for the same Month.
        $cookie = $this->server->login();
        $sessionHistory = $this->server->get('/api.php?action=history&from=2026-07&to=2026-07', $cookie);
        self::assertSame(200, $sessionHistory['status'], $sessionHistory['body']);
        self::assertSame($payload, json_decode($sessionHistory['body'], true), 'The token and the session report the same History');

        // The session read changed nothing either.
        self::assertSame($before, $this->server->databaseDigest(), 'The session History read changed the database');
    }
}
