<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MasterPinTest extends TestCase
{
    private string $directory;
    private array $config;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/fin-pin-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->config = [
            'auth' => [
                'master_pin_file' => $this->directory . '/config/master-pin.hash',
                'throttle_file' => $this->directory . '/state/throttle.json',
                'file_group' => '',
                'pin_failure_delay_us' => 0,
            ],
        ];
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) return;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($this->directory);
    }

    public function testPinIsArgonHashedOutsideSqliteAndVerifies(): void
    {
        self::assertFalse(master_pin_configured($this->config));
        set_master_pin($this->config, '01234567');

        $stored = trim((string)file_get_contents($this->config['auth']['master_pin_file']));
        self::assertNotSame('01234567', $stored);
        self::assertStringNotContainsString('01234567', $stored);
        self::assertSame('argon2id', password_get_info($stored)['algoName']);
        self::assertSame(0600, fileperms($this->config['auth']['master_pin_file']) & 0777);
        self::assertTrue(master_pin_configured($this->config));
        self::assertTrue(verify_master_pin($this->config, '01234567', 100)['ok']);
        self::assertFalse(verify_master_pin($this->config, '87654321', 101)['ok']);

        $dbFile = $this->directory . '/financial.sqlite';
        $db = new PDO('sqlite:' . $dbFile);
        $db->exec('CREATE TABLE financial_data (value TEXT)');
        self::assertStringNotContainsString($stored, (string)file_get_contents($dbFile));
    }

    public function testPinPolicyIsNumericAndAtLeastEightDigits(): void
    {
        foreach (['1234567', '1234567x', '1234-5678', str_repeat('1', 33)] as $invalid) {
            try {
                validate_master_pin($invalid);
                self::fail('Invalid PIN was accepted');
            } catch (InvalidArgumentException $error) {
                self::assertSame('Master PIN must contain 8 to 32 digits', $error->getMessage());
            }
        }
        validate_master_pin('00000000');
        self::addToAssertionCount(1);
    }

    public function testProgressiveServerThrottlePersistsAndSuccessResetsIt(): void
    {
        set_master_pin($this->config, '12345678');
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $result = verify_master_pin($this->config, '00000000', 100);
            self::assertFalse($result['ok']);
            self::assertSame(0, $result['retry_after']);
        }
        $locked = verify_master_pin($this->config, '00000000', 100);
        self::assertSame(30, $locked['retry_after']);
        self::assertSame(30, verify_master_pin($this->config, '12345678', 100)['retry_after']);

        $success = verify_master_pin($this->config, '12345678', 131);
        self::assertTrue($success['ok']);
        $state = json_decode((string)file_get_contents($this->config['auth']['throttle_file']), true);
        self::assertSame(0, $state['failures']);
        self::assertSame(0, $state['blocked_until']);
    }

    public function testServerSidePinChangeInvalidatesOldPin(): void
    {
        set_master_pin($this->config, '12345678');
        self::assertTrue(verify_master_pin($this->config, '12345678', 100)['ok']);
        set_master_pin($this->config, '87654321');
        self::assertFalse(verify_master_pin($this->config, '12345678', 101)['ok']);
        self::assertTrue(verify_master_pin($this->config, '87654321', 102)['ok']);
    }

    public function testWorldReadablePinHashFailsClosed(): void
    {
        set_master_pin($this->config, '12345678');
        chmod($this->config['auth']['master_pin_file'], 0644);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Master PIN file permissions must be 0600 or 0640');
        master_pin_configured($this->config);
    }

    public function testUnsafeThrottleFileFailsWithClearDiagnostic(): void
    {
        set_master_pin($this->config, '12345678');
        self::assertTrue(verify_master_pin($this->config, '12345678', 100)['ok']);
        chmod($this->config['auth']['throttle_file'], 0666);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Authentication throttle file has unsafe permissions');
        verify_master_pin($this->config, '12345678', 101);
    }

    public function testAuthenticationFilesAreRejectedInsideWebRoot(): void
    {
        $config = $this->config;
        $root = dirname(__DIR__, 2);
        $config['auth']['master_pin_file'] = dirname($root) . '/fin/../fin/tests/tmp/unsafe-pin.hash';
        $this->expectException(RuntimeException::class);
        master_pin_file($config);
    }
}
