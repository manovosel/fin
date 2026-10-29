<?php

declare(strict_types=1);

/**
 * Build the FIN Planner operator manual PDF.
 *
 * Reads docs/manual/manual.md, converts it to HTML through Python's `markdown`
 * library, wraps the result in a small A4 stylesheet plus a deterministic title
 * page drawn from the canonical CLI, and renders the result through the
 * Composer-managed Dompdf renderer.
 *
 * Usage:
 *     php docs/manual/build_manual.php
 *
 * Output:
 *     docs/manual/fin_manual.pdf
 *
 * Requirements (host-side, none of these are FIN runtime dependencies):
 *     - `php` with the Composer autoloader reachable at `<FIN root>/vendor/autoload.php`.
 *       Install with `composer install` (Dompdf is the only Composer package used here;
 *       it is already a FIN runtime dependency in `composer.json`).
 *     - `python3` on PATH with the `markdown` package installed (used to convert the
 *       Markdown source to HTML). No third-party Python packages are required; the
 *       `markdown` package is a system Python module on Debian/Ubuntu hosts.
 *     - `php api.php` reachable so the title page reports the live FIN/database
 *       version. The script points `FIN_DB_FILE` at a fresh temporary SQLite
 *       database; no real database is touched.
 */

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Missing vendor/autoload.php; run composer install.\n");
    exit(1);
}
require $autoload;

$markdownFile = __DIR__ . '/manual.md';
$outputFile = __DIR__ . '/fin_manual.pdf';
if (!is_file($markdownFile)) {
    fwrite(STDERR, "Missing manual source: $markdownFile\n");
    exit(1);
}
$source = file_get_contents($markdownFile);
if (!is_string($source)) {
    fwrite(STDERR, "Could not read $markdownFile\n");
    exit(1);
}

// Resolve FIN + DB version from api.php so the manual cites the same values
// as `php api.php version`. The command requires a SQLite file so we point it
// at a fresh temp database, run migrate against it, then read `version`.
$tempDir = sys_get_temp_dir();
$tempDb = tempnam($tempDir, 'fin-build-') . '.sqlite';
$oldUmask = umask(077);
$dbHandle = new SQLite3($tempDb);
$dbHandle->close();
umask($oldUmask);
$envPrefix = 'FIN_DB_FILE=' . escapeshellarg($tempDb);
$versionOutput = shell_exec($envPrefix . ' php ' . escapeshellarg($root . '/api.php') . ' migrate 2>&1');
$versionOutput = shell_exec($envPrefix . ' php ' . escapeshellarg($root . '/api.php') . ' version 2>&1');
foreach ([$tempDb, $tempDb . '-wal', $tempDb . '-shm', $tempDb . '-journal'] as $candidate) {
    if (is_file($candidate)) @unlink($candidate);
}
@unlink($tempDb . '-journal');
if (!preg_match('/FIN Planner v([^\s]+) \/ database v([^\s]+)/', (string)$versionOutput, $match)) {
    fwrite(STDERR, "Could not resolve FIN/database versions from api.php.\n");
    exit(1);
}
$appVersion = $match[1];
$databaseVersion = $match[2];
$generatedAt = gmdate('Y-m-d H:i:s') . ' UTC';

$pythonScript = <<<'PY'
import sys, markdown

text = sys.stdin.read()
sys.stdout.write(markdown.markdown(text, extensions=['tables', 'fenced_code']))
PY;
$command = 'python3 -c ' . escapeshellarg($pythonScript);
$descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = proc_open($command, $descriptor, $pipes, $root);
if (!is_resource($process)) {
    fwrite(STDERR, "Could not start python3 markdown converter.\n");
    exit(1);
}
fwrite($pipes[0], $source);
fclose($pipes[0]);
$htmlBody = stream_get_contents($pipes[1]);
fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
$exit = proc_close($process);
if ($exit !== 0) {
    fwrite(STDERR, "Markdown conversion failed: $stderr\n");
    exit(1);
}
if (!is_string($htmlBody) || $htmlBody === '') {
    fwrite(STDERR, "Markdown conversion produced no output: $stderr\n");
    exit(1);
}

$css = <<<'CSS'
@page { margin: 24mm 18mm 24mm 18mm; }
body { font-family: 'DejaVu Sans', sans-serif; color: #1f2937; line-height: 1.4; font-size: 10.5pt; }
h1 { font-size: 22pt; margin: 0 0 14pt 0; color: #111827; page-break-after: avoid; }
h2 { font-size: 14pt; margin: 18pt 0 6pt 0; color: #111827; padding-bottom: 3pt; border-bottom: 1px solid #d1d5db; page-break-after: avoid; }
h3 { font-size: 12pt; margin: 12pt 0 4pt 0; color: #111827; page-break-after: avoid; }
p { margin: 0 0 8pt 0; }
ul, ol { margin: 0 0 8pt 18pt; }
li { margin: 0 0 3pt 0; }
code { font-family: 'DejaVu Sans Mono', monospace; font-size: 9pt; background: #f3f4f6; padding: 0 3pt; border-radius: 2pt; }
pre { font-family: 'DejaVu Sans Mono', monospace; font-size: 9pt; background: #f9fafb; border: 1px solid #e5e7eb; padding: 6pt 8pt; border-radius: 3pt; white-space: pre-wrap; word-wrap: break-word; overflow-wrap: break-word; }
table { border-collapse: collapse; margin: 0 0 10pt 0; width: 100%; table-layout: fixed; }
th, td { border: 1px solid #d1d5db; padding: 4pt 6pt; text-align: left; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; }
th { background: #f3f4f6; font-weight: bold; }
hr { border: 0; border-top: 1px solid #d1d5db; margin: 14pt 0; }
.title-page { text-align: center; padding: 40pt 20pt 20pt 20pt; page-break-after: always; }
.title-page h1 { font-size: 28pt; margin-bottom: 16pt; border: 0; }
.title-page .subtitle { font-size: 13pt; color: #374151; margin-bottom: 24pt; }
.title-page .metadata { font-size: 11pt; color: #4b5563; border-top: 1px solid #d1d5db; border-bottom: 1px solid #d1d5db; padding: 12pt 24pt; display: inline-block; min-width: 60%; }
.title-page .footer-mark { font-size: 10pt; color: #6b7280; margin-top: 60pt; }
table th:first-child, table td:first-child { width: 22%; }
.term-name { font-weight: bold; }
CSS;

$safeBody = $htmlBody;

$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>FIN Planner Operator Manual</title>
<style>$css</style>
</head>
<body>

<div class="title-page">
  <h1>FIN Planner</h1>
  <p class="subtitle">Operator Manual</p>
  <p class="metadata">
    <strong>Application version:</strong> $appVersion<br>
    <strong>Database version:</strong> $databaseVersion<br>
    <strong>Generated:</strong> $generatedAt
  </p>
  <p class="footer-mark">FIN Planner — single-owner, self-hosted personal finance.</p>
</div>

<hr>

<h1>About this manual</h1>

$safeBody

</body>
</html>
HTML;

$options = new Dompdf\Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', false);
$options->set('isPhpEnabled', false);
$options->set('isFontSubsettingEnabled', true);
$dompdf = new Dompdf\Dompdf($options);
$dompdf->setPaper('A4', 'portrait');
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->render();
$font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
$pageText = sprintf('FIN Planner · Operator Manual · v%s · DB v%s · generated %s   ·   Page {PAGE_NUM} of {PAGE_COUNT}', $appVersion, $databaseVersion, $generatedAt);
$dompdf->getCanvas()->page_text(36, 815, $pageText, $font, 7.5, [0.40, 0.45, 0.50]);
$bytes = file_put_contents($outputFile, $dompdf->output());
if ($bytes === false) {
    fwrite(STDERR, "Could not write PDF to $outputFile\n");
    exit(1);
}
echo "Wrote $outputFile ($bytes bytes).\n";
echo "FIN Planner v$appVersion / database v$databaseVersion\n";
echo "Generated $generatedAt\n";
