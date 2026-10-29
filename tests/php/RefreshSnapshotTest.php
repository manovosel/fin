<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RefreshSnapshotTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/fin-refresh-snapshot-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) return;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testRefreshSnapshotUsesReadOnlySourceBackupWithoutChangingSource(): void
    {
        $source = $this->root . '/source.sqlite';
        $backupDir = $this->root . '/backups';
        $db = new PDO('sqlite:' . $source);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA foreign_keys = ON');
        migrate($db);
        save_month($db, ['year' => 2026, 'month' => 8, 'notes' => 'Synthetic source']);
        $db = null;

        $sourceHash = hash_file('sha256', $source);
        self::assertIsString($sourceHash);

        $result = create_refresh_snapshot($source, $backupDir);

        self::assertSame($sourceHash, hash_file('sha256', $source));
        self::assertSame('1.37.1', $result['app_version']);
        self::assertSame('2.23', $result['database_version']);
        self::assertMatchesRegularExpression('/^fin-prod-dev-refresh-\d{8}-\d{6}-[a-f0-9]{12}\.sqlite$/', basename($result['snapshot_file']));
        self::assertSame($result['sha256'], hash_file('sha256', $result['snapshot_file']));
        self::assertSame('700', substr(sprintf('%o', fileperms($backupDir)), -3));
        self::assertSame('600', substr(sprintf('%o', fileperms($result['snapshot_file'])), -3));

        $snapshot = new PDO('sqlite:' . $result['snapshot_file']);
        self::assertSame('ok', strtolower((string)$snapshot->query('PRAGMA integrity_check')->fetchColumn()));
        self::assertFalse($snapshot->query('PRAGMA foreign_key_check')->fetch());
        self::assertSame(1, (int)$snapshot->query("SELECT COUNT(*) FROM months WHERE notes='Synthetic source'")->fetchColumn());
    }
}
