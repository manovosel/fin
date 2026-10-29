<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AuthChallengeTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testChallengeIsRandomShortLivedContextBoundAndSingleUse(): void
    {
        $first = random_bytes(32);
        $second = random_bytes(32);
        self::assertNotSame($first, $second);
        auth_issue_challenge('assertion', $first, ['allowed' => ['credential']], 100);
        auth_issue_challenge('registration', $second, [], 100);

        $record = auth_consume_challenge('assertion', 101);
        self::assertSame($first, $record['challenge']);
        self::assertSame(['credential'], $record['context']['allowed']);

        $this->expectException(RuntimeException::class);
        auth_consume_challenge('assertion', 101);
    }

    public function testExpiredChallengeIsRejectedAndConsumed(): void
    {
        auth_issue_challenge('assertion', random_bytes(32), [], 100);
        try {
            auth_consume_challenge('assertion', 221);
            self::fail('Expired challenge was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('Authentication verification failed', $error->getMessage());
        }
        $this->expectException(RuntimeException::class);
        auth_consume_challenge('assertion', 101);
    }

    public function testExactOriginTypeAndCrossOriginAreRequired(): void
    {
        $config = ['webauthn_origin' => 'https://example.com'];
        validate_webauthn_client_origin(json_encode([
            'type' => 'webauthn.get',
            'origin' => 'https://example.com',
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR), $config, 'webauthn.get');
        self::addToAssertionCount(1);

        foreach ([
            ['type' => 'webauthn.create', 'origin' => 'https://example.com'],
            ['type' => 'webauthn.get', 'origin' => 'https://evil.example.com'],
            ['type' => 'webauthn.get', 'origin' => 'http://example.com'],
            ['type' => 'webauthn.get', 'origin' => 'https://example.com', 'crossOrigin' => true],
        ] as $invalid) {
            try {
                validate_webauthn_client_origin(json_encode($invalid, JSON_THROW_ON_ERROR), $config, 'webauthn.get');
                self::fail('Invalid client origin data was accepted');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testProductionRequiresHttpsButLocalhostMayUseHttp(): void
    {
        self::assertSame('http://localhost:8092', expected_webauthn_origin(['webauthn_origin' => 'http://localhost:8092']));
        $this->expectException(RuntimeException::class);
        expected_webauthn_origin(['webauthn_origin' => 'http://example.com']);
    }
}
