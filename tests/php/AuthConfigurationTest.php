<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AuthConfigurationTest extends TestCase
{
    private string $directory;
    private string|false $originalHome;

    protected function setUp(): void
    {
        $this->originalHome = getenv('HOME');
        $this->directory = sys_get_temp_dir() . '/fin-auth-config-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        copy(dirname(__DIR__, 2) . '/config.php', $this->directory . '/config.php');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) @unlink($file);
        @rmdir($this->directory);
        putenv('FIN_MASTER_PIN_FILE');
        putenv('FIN_AUTH_THROTTLE_FILE');
        $this->originalHome === false ? putenv('HOME') : putenv('HOME=' . $this->originalHome);
    }

    private function writeLocalConfig(array $config): void
    {
        file_put_contents($this->directory . '/config.local.php', '<?php return ' . var_export($config, true) . ';');
    }

    public function testProductionDefaultsAreExplicitAndIndependentOfUnixUser(): void
    {
        putenv('HOME=/tmp/a-different-user');
        $config = app_config($this->directory);
        self::assertSame('/etc/fin/master-pin.hash', master_pin_file($config));
        self::assertSame('/var/lib/fin/master-pin-throttle.json', auth_throttle_file($config));
        self::assertSame('www-data', auth_file_group($config));
    }

    public function testConfigLocalOverridesBothDevelopmentPathsForCliAndWebResolver(): void
    {
        $pinFile = $this->directory . '/outside/master-pin.hash';
        $throttleFile = $this->directory . '/outside/master-pin-throttle.json';
        $this->writeLocalConfig([
            'auth' => [
                'master_pin_file' => $pinFile,
                'throttle_file' => $throttleFile,
                'file_group' => '',
            ],
            'webauthn_rp_id' => 'localhost',
            'webauthn_origin' => 'http://localhost:8080',
        ]);

        $cliConfig = app_config($this->directory);
        $webConfig = app_config($this->directory);
        self::assertSame($pinFile, master_pin_file($cliConfig));
        self::assertSame(master_pin_file($cliConfig), master_pin_file($webConfig));
        self::assertSame($throttleFile, auth_throttle_file($cliConfig));
        self::assertSame(auth_throttle_file($cliConfig), auth_throttle_file($webConfig));
        self::assertSame('', auth_file_group($cliConfig));
    }

    public function testLegacyPathEnvironmentVariablesCannotSplitExplicitConfiguration(): void
    {
        $pinFile = $this->directory . '/configured/master-pin.hash';
        $throttleFile = $this->directory . '/configured/throttle.json';
        $this->writeLocalConfig(['auth' => [
            'master_pin_file' => $pinFile,
            'throttle_file' => $throttleFile,
            'file_group' => '',
        ]]);
        putenv('FIN_MASTER_PIN_FILE=' . $this->directory . '/wrong/pin.hash');
        putenv('FIN_AUTH_THROTTLE_FILE=' . $this->directory . '/wrong/throttle.json');

        $config = app_config($this->directory);
        self::assertSame($pinFile, master_pin_file($config));
        self::assertSame($throttleFile, auth_throttle_file($config));
    }
}
