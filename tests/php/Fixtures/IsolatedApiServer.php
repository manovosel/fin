<?php

declare(strict_types=1);

namespace Tests\Php\Fixtures;

/**
 * A private FIN web root with its own SQLite database, its own Master PIN and
 * its own API-token file, served by the PHP built-in server.
 *
 * The fixture exists so an invariant test can speak to the real HTTP router
 * (real routing, real authentication gate, real JSON responses) without
 * touching the installation database, the development database or production
 * data. Every identifier it creates is invented here; nothing is read from a
 * personal data set.
 *
 * The fixture is self-contained on purpose: it owns the whole lifecycle
 * (copy the code, migrate, set the Master PIN, seed, serve, stop, clean up)
 * and it does not depend on any other test helper. A protected harness can
 * therefore copy this one file next to a test file and use it as it is.
 */
final class IsolatedApiServer
{
    /** The application files the served web root needs. */
    private const APPLICATION_FILES = [
        'VERSION',
        'accounting.php',
        'api.php',
        'auth.php',
        'config.php',
        'currency.php',
        'ecb_rates.php',
        'recommendation.php',
        'report.php',
        'statement_import.php',
        'statement_reconciliation.php',
    ];

    private string $baseDirectory = '';
    private string $appRoot = '';
    private string $databaseFile = '';
    private string $tokenFile = '';
    private string $url = '';
    /** @var resource|null */
    private $process = null;
    /** @var array<int, resource> */
    private array $pipes = [];
    /** @var array<string, string> */
    private array $environment = [];

    private function __construct()
    {
    }

    /**
     * Starts an isolated FIN server.
     *
     * @param string $today The date the server treats as today (YYYY-MM-DD).
     */
    public static function start(string $today = '2026-08-14'): self
    {
        $server = new self();
        $server->boot($today);
        return $server;
    }

    /** The base URL of the running server, without a trailing slash. */
    public function url(): string
    {
        return $this->url;
    }

    /** The private web root that is being served. */
    public function appRoot(): string
    {
        return $this->appRoot;
    }

    /** The private SQLite database of the running server. */
    public function databaseFile(): string
    {
        return $this->databaseFile;
    }

    /** Stops the server and removes every file it created. */
    public function stop(): void
    {
        if (is_resource($this->process)) {
            @proc_terminate($this->process);
            @proc_close($this->process);
        }
        $this->process = null;
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) @fclose($pipe);
        }
        $this->pipes = [];
        if ($this->baseDirectory !== '' && is_dir($this->baseDirectory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->baseDirectory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname());
                else @unlink($item->getPathname());
            }
            @rmdir($this->baseDirectory);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Sends a GET request to the server.
     *
     * @return array{status:int,body:string,cookie:string}
     */
    public function get(string $query, ?string $cookie = null, ?string $bearer = null): array
    {
        return $this->send('GET', $this->url . '/api.php' . $query, null, $cookie, $bearer);
    }

    /**
     * Sends a POST request with a JSON payload to the server.
     *
     * @return array{status:int,body:string,cookie:string}
     */
    public function post(array $payload, ?string $cookie = null, ?string $bearer = null): array
    {
        return $this->send('POST', $this->url . '/api.php', json_encode($payload), $cookie, $bearer);
    }

    /**
     * Creates a new API token through the canonical CLI and returns the token
     * itself. The server keeps only its SHA-256 hash.
     */
    public function createApiToken(): string
    {
        [$status, $stdout, $stderr] = $this->runCli(['api.php', 'create-api-token']);
        if ($status !== 0) throw new \RuntimeException('Could not create an API token: ' . $stderr);
        $token = '';
        foreach (explode("\n", $stdout) as $line) {
            if (str_starts_with($line, 'API token: ')) $token = trim(substr($line, strlen('API token: ')));
        }
        if ($token === '') throw new \RuntimeException('The CLI did not print an API token: ' . $stdout);
        return $token;
    }

    /**
     * Logs in with the Master PIN and returns the session cookie.
     */
    public function login(string $pin = '12345678'): string
    {
        $response = $this->post(['action' => 'masterPinLogin', 'pin' => $pin]);
        if ($response['status'] !== 200) throw new \RuntimeException('The Master PIN login failed: ' . $response['body']);
        if ($response['cookie'] === '') throw new \RuntimeException('The Master PIN login returned no session cookie');
        return $response['cookie'];
    }

    /**
     * A digest of every table of the server database, so a read can be shown
     * to have changed nothing.
     *
     * The digest covers every user table and is built from the full row
     * content, so any inserted, changed or deleted row changes it.
     */
    public function databaseDigest(): string
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
        return trim($this->runPhp($script));
    }

    /**
     * Runs PHP code inside the served application root and returns its output.
     *
     * The code can use every FIN function because `api.php` is loaded first.
     */
    public function runPhp(string $code): string
    {
        $file = $this->appRoot . '/fin-isolated-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($file, "<?php\nrequire_once __DIR__ . '/api.php';\n" . $code . "\n");
        [$status, $stdout, $stderr] = $this->runCli([basename($file)]);
        @unlink($file);
        if ($status !== 0) throw new \RuntimeException('The isolated script failed: ' . $stderr . $stdout);
        return $stdout;
    }

    /** @return array{status:int,body:string,cookie:string} */
    private function send(string $method, string $url, ?string $body, ?string $cookie, ?string $bearer): array
    {
        $headers = [];
        if ($cookie !== null && $cookie !== '') $headers[] = 'Cookie: ' . $cookie;
        if ($bearer !== null && $bearer !== '') $headers[] = 'Authorization: Bearer ' . $bearer;
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        $options = ['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 60]];
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

    private function boot(string $today): void
    {
        // The application root is the FIN source tree. A harness that keeps
        // the fixture outside the source tree can point FIN_SOURCE_ROOT at it.
        $source = getenv('FIN_SOURCE_ROOT');
        if (!is_string($source) || $source === '') $source = dirname(__DIR__, 3);
        $source = rtrim($source, DIRECTORY_SEPARATOR);
        $this->baseDirectory = sys_get_temp_dir() . '/fin-isolated-api-' . bin2hex(random_bytes(6));
        $this->appRoot = $this->baseDirectory . '/app';
        mkdir($this->appRoot, 0700, true);
        foreach (self::APPLICATION_FILES as $file) {
            copy($source . '/' . $file, $this->appRoot . '/' . $file);
        }
        symlink($source . '/vendor', $this->appRoot . '/vendor');

        $this->databaseFile = $this->appRoot . '/fin.sqlite';
        $this->tokenFile = $this->baseDirectory . '/api-token.hash';
        file_put_contents($this->appRoot . '/config.local.php', "<?php\nreturn [\n    'auth' => [\n        'master_pin_file' => '{$this->baseDirectory}/master-pin.hash',\n        'throttle_file' => '{$this->baseDirectory}/master-pin-throttle.json',\n        'api_token_file' => '{$this->tokenFile}',\n        'file_group' => '',\n        'pin_failure_delay_us' => 0,\n    ],\n    'webauthn_rp_id' => 'localhost',\n    'webauthn_origin' => 'http://localhost',\n];\n");

        $this->environment = array_merge($_ENV, getenv(), [
            'FIN_DB_FILE' => $this->databaseFile,
            'FIN_TODAY' => $today,
            'FIN_TEST_APP_ROOT' => $this->appRoot,
        ]);
        $this->runCli(['api.php', 'migrate']);
        $this->runCli(['api.php', 'set-master-pin', '--stdin'], "12345678\n12345678\n");
        $this->seed();

        $port = $this->freePort();
        $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $this->appRoot];
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->appRoot, $this->environment);
        if (!is_resource($process)) throw new \RuntimeException('Could not start the isolated FIN server');
        foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
        $this->process = $process;
        $this->pipes = $pipes;
        $this->url = 'http://127.0.0.1:' . $port;

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket) {
                fclose($socket);
                return;
            }
            usleep(100000);
        }
        $this->stop();
        throw new \RuntimeException('The isolated FIN server did not start');
    }

    /**
     * The neutral baseline every test starts from: one Month with one Daily
     * Envelope, one Funded Plan row, spending in that Month and in an earlier
     * Month, and one Account with an income event.
     */
    private function seed(): void
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
        $this->runPhp($script);
    }

    /** @return array{int,string,string} */
    private function runCli(array $arguments, ?string $input = null): array
    {
        $command = array_merge([PHP_BINARY], $arguments);
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->appRoot, $this->environment);
        if (!is_resource($process)) throw new \RuntimeException('Could not run ' . implode(' ', $arguments));
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
        if (!is_resource($server)) throw new \RuntimeException('Could not allocate a free port');
        $name = stream_socket_get_name($server, false);
        fclose($server);
        return (int)substr((string)$name, strrpos((string)$name, ':') + 1);
    }
}
