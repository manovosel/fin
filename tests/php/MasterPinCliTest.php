<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MasterPinCliTest extends TestCase
{
    public function testCliUsesLocalConfigAndNeverPrintsSecrets(): void
    {
        $directory = sys_get_temp_dir() . '/fin-pin-cli-' . bin2hex(random_bytes(6));
        $app = $directory . '/app';
        mkdir($app, 0700, true);
        $root = dirname(__DIR__, 2);
        foreach (['api.php', 'auth.php', 'accounting.php', 'currency.php', 'ecb_rates.php', 'statement_import.php', 'statement_reconciliation.php', 'recommendation.php', 'report.php', 'config.php', 'VERSION'] as $file) {
            copy($root . '/' . $file, $app . '/' . $file);
        }
        $pinFile = $directory . '/auth/master-pin.hash';
        $stateFile = $directory . '/auth/master-pin-throttle.json';
        file_put_contents($app . '/config.local.php', '<?php return ' . var_export([
            'auth' => [
                'master_pin_file' => $pinFile,
                'throttle_file' => $stateFile,
                'file_group' => '',
                'pin_failure_delay_us' => 0,
            ],
        ], true) . ';');
        $api = $app . '/api.php';

        exec('php ' . escapeshellarg($api) . ' master-pin-status 2>&1', $statusOutput, $statusCode);
        self::assertSame(0, $statusCode);
        self::assertSame([
            'Master PIN file: ' . $pinFile,
            'Master PIN configured: no',
        ], $statusOutput);

        $command = "printf '12345678\\n12345678\\n' | php " . escapeshellarg($api) . ' set-master-pin --stdin 2>&1';
        exec($command, $setOutput, $setCode);
        self::assertSame(0, $setCode);
        self::assertSame([
            'Master PIN file: ' . $pinFile,
            'Master PIN configured: yes',
        ], $setOutput);
        self::assertStringNotContainsString('12345678', implode("\n", $setOutput));

        $command = "printf '87654321\\n87654321\\n' | php " . escapeshellarg($api) . ' set-master-pin --stdin 2>&1';
        exec($command, $changeOutput, $changeCode);
        self::assertSame(0, $changeCode);
        self::assertTrue(password_verify('87654321', trim((string)file_get_contents($pinFile))));
        self::assertFalse(password_verify('12345678', trim((string)file_get_contents($pinFile))));

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($directory);
    }
}
