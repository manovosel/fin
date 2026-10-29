<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SessionCookieTest extends TestCase
{
    public function testSessionCookieUsesConfiguredPublicRootBehindSubpathMapping(): void
    {
        $options = session_cookie_options(
            ['session_cookie_path' => '/'],
            ['SCRIPT_NAME' => '/fin/api.php', 'HTTP_X_FORWARDED_PROTO' => 'https']
        );

        self::assertSame('/', $options['path']);
        self::assertTrue($options['secure']);
        self::assertTrue($options['httponly']);
        self::assertSame('Lax', $options['samesite']);
    }

    public function testSessionCookieSupportsLocalHttpDevelopment(): void
    {
        $options = session_cookie_options(
            ['session_cookie_path' => '/'],
            ['SCRIPT_NAME' => '/api.php', 'HTTPS' => 'off']
        );

        self::assertSame('/', $options['path']);
        self::assertFalse($options['secure']);
    }
}
