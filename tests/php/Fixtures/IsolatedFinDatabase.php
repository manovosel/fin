<?php

declare(strict_types=1);

namespace Tests\Php\Fixtures;

use PDO;

/**
 * Self-owned isolated SQLite database factory used by every test that needs
 * FIN state. NEVER uses `data/fin.sqlite`, the DEV database, the production
 * database, or any shared mutable state.
 */
final class IsolatedFinDatabase
{
    private string $path;
    private ?PDO $db;

    private function __construct(string $path, PDO $db)
    {
        $this->path = $path;
        $this->db = $db;
    }

    public static function create(): self
    {
        $path = tempnam(sys_get_temp_dir(), 'fin-test-db-');
        if ($path === false) {
            throw new \RuntimeException('Could not allocate a temp file for the isolated test database');
        }
        // tempnam returns the path; the file exists. Drop it so SQLite creates
        // a fresh empty file rather than attaching to a zero-byte artifact.
        @unlink($path);
        $path .= '.sqlite';
        $db = new PDO('sqlite:' . $path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA foreign_keys = ON');
        migrate($db);
        return new self($path, $db);
    }

    public function db(): PDO
    {
        if ($this->db === null) {
            throw new \RuntimeException('IsolatedFinDatabase has already been destroyed');
        }
        return $this->db;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function destroy(): void
    {
        if ($this->db !== null) {
            try {
                $this->db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            } catch (\Throwable) {
                // best effort
            }
            $this->db = null;
        }
        if (is_file($this->path)) {
            @unlink($this->path);
        }
        $walPath = $this->path . '-wal';
        $shmPath = $this->path . '-shm';
        if (is_file($walPath)) {
            @unlink($walPath);
        }
        if (is_file($shmPath)) {
            @unlink($shmPath);
        }
    }

    public function __destruct()
    {
        $this->destroy();
    }
}
