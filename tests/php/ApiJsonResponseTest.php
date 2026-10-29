<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ApiJsonResponseTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) @unlink($file);
        parent::tearDown();
    }

    public function testMalformedExceptionsPayloadReturnsJsonErrorInsteadOfEmptyBody(): void
    {
        [$status, $stdout, $stderr] = $this->runPhp(<<<'PHP'
respond([
    'account' => ['id' => 1, 'name' => 'Account A'],
    'state' => ['unresolved' => [[
        'id' => 15,
        'description_original' => "\xCEle-de-France Mobilit\xC3\xA9s",
    ]]],
    'preselections' => [],
]);
PHP);

        self::assertSame(0, $status, $stderr);
        self::assertNotSame('', $stdout);
        self::assertSame(
            ['error' => 'Response could not be encoded as JSON.'],
            json_decode($stdout, true, 32, JSON_THROW_ON_ERROR)
        );
        self::assertStringContainsString('JSON response serialization failed', $stderr);
    }

    public function testRuntimeFatalReplacesPartialOutputWithJsonError(): void
    {
        [$status, $stdout] = $this->runPhp(<<<'PHP'
$GLOBALS['fin_json_response_complete'] = false;
$GLOBALS['fin_json_guard_buffer_level'] = ob_get_level();
ob_start();
register_shutdown_function('fin_emit_fatal_json_response');
echo 'partial non-JSON output';
trigger_error('Synthetic exceptions-loading fatal', E_USER_ERROR);
PHP);

        self::assertNotSame(0, $status);
        self::assertSame(
            ['error' => 'A server error prevented the response from completing.'],
            json_decode($stdout, true, 32, JSON_THROW_ON_ERROR)
        );
        self::assertStringNotContainsString('partial', $stdout);
    }

    /** @return array{int,string,string} */
    private function runPhp(string $body): array
    {
        $file = tempnam(sys_get_temp_dir(), 'fin-json-response-');
        if ($file === false) self::fail('Could not create temporary PHP script');
        $this->temporaryFiles[] = $file;
        $api = var_export(dirname(__DIR__, 2) . '/api.php', true);
        file_put_contents($file, "<?php\ndeclare(strict_types=1);\nrequire_once $api;\n" . $body);

        $process = proc_open(
            [PHP_BINARY, $file],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) self::fail('Could not start PHP subprocess');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        return [$status, (string)$stdout, (string)$stderr];
    }
}
