<?php

declare(strict_types=1);

const STATEMENT_PARSER_VERSION = '2';
const STATEMENT_MAX_FILE_BYTES = 5_000_000;
const STATEMENT_MAX_ROWS = 10_000;
const STATEMENT_IMPORT_MAX_ROWS = 1_000;

function statement_text_is_valid_utf8(string $value): bool
{
    return preg_match('//u', $value) === 1;
}

function statement_repair_invalid_utf8_bytes(string $value): string
{
    if (statement_text_is_valid_utf8($value)) return $value;

    $result = '';
    $length = strlen($value);
    for ($offset = 0; $offset < $length;) {
        $lead = ord($value[$offset]);
        $sequenceLength = match (true) {
            $lead <= 0x7F => 1,
            $lead >= 0xC2 && $lead <= 0xDF => 2,
            $lead >= 0xE0 && $lead <= 0xEF => 3,
            $lead >= 0xF0 && $lead <= 0xF4 => 4,
            default => 0,
        };
        $validSequence = $sequenceLength > 0 && $offset + $sequenceLength <= $length;
        for ($index = 1; $validSequence && $index < $sequenceLength; $index++) {
            $continuation = ord($value[$offset + $index]);
            if ($continuation < 0x80 || $continuation > 0xBF) $validSequence = false;
        }
        if ($validSequence && $sequenceLength >= 3) {
            $second = ord($value[$offset + 1]);
            if (($lead === 0xE0 && $second < 0xA0)
                || ($lead === 0xED && $second > 0x9F)
                || ($lead === 0xF0 && $second < 0x90)
                || ($lead === 0xF4 && $second > 0x8F)) $validSequence = false;
        }
        if ($validSequence) {
            $result .= substr($value, $offset, $sequenceLength);
            $offset += $sequenceLength;
            continue;
        }

        $repaired = function_exists('iconv') ? @iconv('Windows-1252', 'UTF-8//IGNORE', $value[$offset]) : false;
        $result .= is_string($repaired) && $repaired !== '' && statement_text_is_valid_utf8($repaired)
            ? $repaired
            : "\xEF\xBF\xBD";
        $offset++;
    }
    return $result;
}

function statement_repair_text(string $value): string
{
    // Existing evidence can contain isolated Windows-1252 bytes written by the
    // former whole-string mojibake conversion. Preserve valid UTF-8 sequences
    // and repair only malformed bytes before applying Unicode expressions.
    $value = statement_repair_invalid_utf8_bytes($value);
    $value = preg_replace_callback('/Ã_x00([89ABab][0-9A-Fa-f])_/', static fn(array $match): string => "\xC3" . chr(hexdec($match[1])), $value) ?? $value;
    $value = preg_replace_callback('/_x([0-9A-Fa-f]{4})_/', static function (array $match): string {
        $code = hexdec($match[1]);
        if ($code < 0x80) return chr($code);
        if ($code < 0x800) return chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
        return chr(0xE0 | ($code >> 12)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
    }, $value) ?? $value;
    if (str_contains($value, 'Ã') && function_exists('iconv')) {
        $value = preg_replace_callback('/Ã./us', static function (array $match): string {
            $repaired = @iconv('UTF-8', 'Windows-1252', $match[0]);
            return is_string($repaired) && statement_text_is_valid_utf8($repaired) ? $repaired : $match[0];
        }, $value) ?? $value;
    }
    $value = statement_repair_invalid_utf8_bytes($value);
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function statement_column_index(string $reference): int
{
    if (!preg_match('/^([A-Z]+)/i', $reference, $match)) return -1;
    $index = 0;
    foreach (str_split(strtoupper($match[1])) as $letter) $index = ($index * 26) + ord($letter) - 64;
    return $index - 1;
}

function statement_xlsx_xml(PharData $archive, string $path): SimpleXMLElement
{
    if (!isset($archive[$path])) throw new InvalidArgumentException('Statement workbook is missing ' . $path);
    $contents = $archive[$path]->getContent();
    if (strlen($contents) > 10_000_000) throw new InvalidArgumentException('Statement workbook is too large');
    $xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    if (!$xml) throw new InvalidArgumentException('Statement workbook contains invalid XML');
    return $xml;
}

function statement_xlsx_rows(string $contents): array
{
    if ($contents === '' || strlen($contents) > STATEMENT_MAX_FILE_BYTES) throw new InvalidArgumentException('Statement file must be a non-empty XLSX file smaller than 5 MB');
    $temporaryBase = tempnam(sys_get_temp_dir(), 'fin-statement-');
    if ($temporaryBase === false) throw new RuntimeException('Could not inspect statement file');
    $temporary = $temporaryBase . '.xlsx';
    @unlink($temporaryBase);
    try {
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) throw new RuntimeException('Could not inspect statement file');
        chmod($temporary, 0600);
        try {
            $archive = new PharData($temporary);
        } catch (Throwable) {
            throw new InvalidArgumentException('Statement file is not a supported XLSX workbook');
        }
        $shared = [];
        if (isset($archive['xl/sharedStrings.xml'])) {
            $strings = statement_xlsx_xml($archive, 'xl/sharedStrings.xml');
            $strings->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($strings->xpath('//s:si') ?: [] as $item) {
                $item->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $shared[] = statement_repair_text(implode('', array_map(static fn(SimpleXMLElement $text): string => (string)$text, $item->xpath('.//s:t') ?: [])));
            }
        }
        $sheet = statement_xlsx_xml($archive, 'xl/worksheets/sheet1.xml');
        $sheet->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = [];
        foreach ($sheet->xpath('//s:sheetData/s:row') ?: [] as $row) {
            if (count($rows) >= STATEMENT_MAX_ROWS + 1) throw new InvalidArgumentException('Statement contains more than 10000 rows');
            $values = [];
            $row->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($row->xpath('./s:c') ?: [] as $cell) {
                $index = statement_column_index((string)$cell['r']);
                if ($index < 0) continue;
                $cell->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $type = (string)$cell['t'];
                $raw = (string)(($cell->xpath('./s:v') ?: [null])[0] ?? '');
                if ($type === 's' && ctype_digit($raw)) {
                    $value = $shared[(int)$raw] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = implode('', array_map(static fn(SimpleXMLElement $text): string => (string)$text, $cell->xpath('.//s:t') ?: []));
                } else {
                    $value = $raw;
                }
                $values[$index] = statement_repair_text($value);
            }
            if ($values !== []) {
                ksort($values);
                // Keep worksheet rows sparse. Some bank exports include an empty
                // styled cell at a far-right Excel column; filling every missing
                // column turned each such row into a 16,384-element PHP array.
                $rows[] = $values;
            }
        }
        if (count($rows) < 2) throw new InvalidArgumentException('Statement workbook has no data rows');
        return $rows;
    } finally {
        @unlink($temporary);
    }
}

function statement_header_key(string $value): string
{
    $value = statement_repair_text($value);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    return preg_replace('/[^a-z0-9æøå]+/u', '', $value) ?? $value;
}

function statement_excel_datetime(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') return null;
    if (is_numeric($value)) {
        $serial = (float)$value;
        if ($serial < 1 || $serial > 200000) return null;
        $seconds = (int)round($serial * 86400);
        return (new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone('UTC')))->modify('+' . $seconds . ' seconds');
    }
    foreach (['!Y-m-d H:i:s', '!Y-m-d', '!d.m.Y H:i:s', '!d.m.Y'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
        if ($date instanceof DateTimeImmutable) return $date;
    }
    return null;
}

function statement_decimal(string $value): ?float
{
    $value = trim(str_replace(["\u{00A0}", ' '], '', $value));
    if ($value === '') return null;
    if (str_contains($value, ',')) $value = str_replace(['.', ','], ['', '.'], $value);
    if (!is_numeric($value)) return null;
    $amount = (float)$value;
    return is_finite($amount) ? $amount : null;
}

function statement_row_value(array $row, array $headers, string $key): string
{
    return isset($headers[$key]) ? statement_repair_text((string)($row[$headers[$key]] ?? '')) : '';
}

function statement_evidence_identity_base(int $accountId, string $evidenceNamespace, array $row, ?float $identityAmount, string $identityDetail): string
{
    $problemRawFields = null;
    if ($row['parse_problem'] !== '') {
        $problemRawFields = $row['raw_fields'];
        unset($problemRawFields['']);
    }
    return hash('sha256', json_encode([
        $accountId,
        $evidenceNamespace,
        $row['booking_date'],
        $row['value_date'],
        $identityAmount === null ? null : number_format($identityAmount, 8, '.', ''),
        $row['currency'],
        $row['description_normalized'],
        $row['provider_reference'],
        $identityDetail,
        $problemRawFields,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function statement_evidence_fingerprint(string $identityBase, int $occurrenceIndex): string
{
    if ($occurrenceIndex < 0) throw new InvalidArgumentException('Statement evidence occurrence index must not be negative');
    return hash('sha256', $identityBase . "\n" . $occurrenceIndex);
}

function statement_account(PDO $db, int $accountId): array
{
    if ($accountId <= 0) throw new InvalidArgumentException('Select an Account.');
    $stmt = $db->prepare('SELECT id,name,account_number,currency,active FROM accounts WHERE id=:id');
    $stmt->execute([':id' => $accountId]);
    $account = $stmt->fetch();
    if (!$account || !(bool)$account['active']) throw new InvalidArgumentException('Select an active Account.');
    return $account;
}

function statement_decode_file_payload(array $payload): array
{
    $filename = trim((string)($payload['filename'] ?? ''));
    $encoded = (string)($payload['file_base64'] ?? '');
    if ($filename === '' || $encoded === '') throw new InvalidArgumentException('Select a statement file');
    $contents = base64_decode($encoded, true);
    if (!is_string($contents)) throw new InvalidArgumentException('Statement file could not be read');
    if (strlen($contents) > STATEMENT_MAX_FILE_BYTES) throw new InvalidArgumentException('Statement file must be smaller than 5 MB');
    return [$filename, $contents];
}

/**
 * Root of the statement-import area. Resolved relative to the FIN root so it does not
 * depend on a specific deployment path. Tests may override this via a test fixture.
 */
function statement_drop_dir(?string $override = null): string
{
    if ($override !== null && $override !== '') {
        $trimmed = rtrim($override, '/\\');
        return $trimmed === '' ? '.' : $trimmed;
    }
    return __DIR__ . DIRECTORY_SEPARATOR . 'import';
}

function statement_imported_dir(?string $override = null): string
{
    return statement_drop_dir($override) . DIRECTORY_SEPARATOR . 'imported';
}

/**
 * Ensure the two import directories exist. Safe to call repeatedly.
 */
function statement_ensure_drop_dirs(string $importDir): array
{
    $importDir = rtrim($importDir, '/\\');
    if ($importDir === '') $importDir = '.';
    $importedDir = $importDir . DIRECTORY_SEPARATOR . 'imported';
    if (!is_dir($importDir) && !@mkdir($importDir, 0700, true) && !is_dir($importDir)) {
        throw new RuntimeException('Could not create statement import directory');
    }
    if (!is_dir($importedDir) && !@mkdir($importedDir, 0700, true) && !is_dir($importedDir)) {
        throw new RuntimeException('Could not create statement imported directory');
    }
    return [$importDir, $importedDir];
}

/**
 * Resolve the final destination for a source file in <importedDir>. Collision-safe,
 * byte-identity aware, no versioning subsystem:
 *   - No collision: use the original filename.
 *   - Same content already imported: caller unlinks the redundant source; we keep destination.
 *   - Different content: minimal deterministic suffix based on sha256 + timestamp.
 */
function statement_destination_for_imported(string $importedDir, string $filename, string $contents): array
{
    $destination = $importedDir . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($destination)) return ['path' => $destination, 'redundant' => false];
    $sourceChecksum = hash('sha256', $contents);
    if (is_file($destination) && hash_file('sha256', $destination) === $sourceChecksum) {
        return ['path' => $destination, 'redundant' => true];
    }
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $sep = $ext !== '' ? '.' : '';
    $unique = $base . '-' . date('Ymd-His') . '-' . substr($sourceChecksum, 0, 6) . $sep . $ext;
    return ['path' => $importedDir . DIRECTORY_SEPARATOR . $unique, 'redundant' => false];
}

/**
 * Process one already-on-disk statement file via the inert evidence path.
 * The operator-supplied Account is mandatory and authoritative. Any Account
 * identifier parsed from the file remains informational evidence only.
 * Only on a successful import is the source moved to import/imported/.
 */
function statement_process_one_dropped_file(PDO $db, string $sourcePath, string $importedDir, ?int $explicitAccountId = null): array
{
    $filename = basename($sourcePath);
    if ($explicitAccountId === null || $explicitAccountId <= 0) {
        return ['filename' => $filename, 'outcome' => 'NEEDS ACCOUNT', 'reason' => 'Select an Account.'];
    }
    $contents = (string)@file_get_contents($sourcePath);
    if ($contents === '') return ['filename' => $filename, 'outcome' => 'FAILED', 'reason' => 'empty file'];
    try {
        $preview = preview_statement_import($db, $explicitAccountId, $filename, $contents);
    } catch (Throwable $error) {
        return [
            'filename' => $filename,
            'outcome' => 'FAILED',
            'reason' => $error->getMessage(),
            'validation_error' => $error instanceof InvalidArgumentException,
        ];
    }
    $accountId = (int)($preview['account']['id'] ?? 0);
    if ($accountId <= 0 || $preview['rows_found'] === 0) {
        return ['filename' => $filename, 'outcome' => 'UNSUPPORTED', 'reason' => 'no parsable evidence'];
    }
    try {
        // Reuse the server-produced preview from this request. Re-parsing the
        // complete workbook immediately before persistence adds no protection.
        $result = import_statement_evidence($db, $accountId, $filename, $contents, $preview);
    } catch (Throwable $error) {
        return ['filename' => $filename, 'outcome' => 'FAILED', 'reason' => $error->getMessage()];
    }
    if (empty($result['ok'])) {
        return ['filename' => $filename, 'outcome' => 'FAILED', 'reason' => (string)($result['error'] ?? 'ingest refused')];
    }
    $destinationInfo = statement_destination_for_imported($importedDir, $filename, $contents);
    if ($destinationInfo['redundant']) {
        @unlink($sourcePath);
    } else {
        if (!@rename($sourcePath, $destinationInfo['path'])) {
            return ['filename' => $filename, 'outcome' => 'FAILED', 'reason' => 'move to imported/ failed'];
        }
    }
    $importId = (int)($result['import_id']);
    $already = !empty($result['duplicate_file']);
    return [
        'filename' => $filename,
        'destination' => basename($destinationInfo['path']),
        'outcome' => $already ? 'ALREADY IMPORTED' : 'IMPORTED',
        'account' => (string)($preview['account']['name'] ?? ''),
        'import_id' => $importId,
        'duplicate' => $already,
        'rows' => (int)($preview['rows_found'] ?? 0),
        'preview' => $preview,
    ];
}

/**
 * Scan import/ for supported source files and process each independently under
 * the explicitly supplied Account. One file's failure never blocks others.
 */
function statement_process_dropped_files(PDO $db, string $importDir, ?int $explicitAccountId = null): array
{
    [$importDir, $importedDir] = statement_ensure_drop_dirs($importDir);
    if (!is_dir($importDir)) return [];
    $entries = @scandir($importDir);
    if (!is_array($entries)) return [];
    sort($entries, SORT_STRING);
    $report = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === 'imported') continue;
        $source = $importDir . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($source)) continue;
        $report[] = statement_process_one_dropped_file($db, $source, $importedDir, $explicitAccountId);
    }
    return $report;
}

/**
 * UI import convergence: write the uploaded content into import/ first and then
 * let the shared processor move it to import/imported/ on success. Returned source
 * path is what the caller can pass to the processor when $contents were just received.
 */
function statement_write_to_drop_inbox(string $importDir, string $filename, string $contents): string
{
    [$importDir, ] = statement_ensure_drop_dirs($importDir);
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $sep = $ext !== '' ? '.' : '';
    $candidate = $importDir . DIRECTORY_SEPARATOR . $filename;
    if (file_exists($candidate)) {
        $candidate = $importDir . DIRECTORY_SEPARATOR . $base . '-upload-' . date('Ymd-His') . $sep . $ext;
    }
    if (file_put_contents($candidate, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Could not write imported source file');
    }
    @chmod($candidate, 0600);
    return $candidate;
}

function statement_account_summary(array $account): array
{
    return [
        'id' => (int)$account['id'],
        'name' => (string)$account['name'],
        'account_number' => (string)($account['account_number'] ?? ''),
        'currency' => (string)$account['currency'],
    ];
}

function preview_statement_import(PDO $db, ?int $accountId, string $filename, string $contents): array
{
    if (!($db instanceof PDO)) throw new InvalidArgumentException('Import Profile database handle is required');
    if ($accountId === null || $accountId <= 0) throw new InvalidArgumentException('Select an Account.');
    $account = statement_account($db, $accountId);
    if (!is_string($filename) || $filename === '') throw new InvalidArgumentException('Statement filename is required');
    if (!is_string($contents)) throw new InvalidArgumentException('Statement file bytes are required');

    // Normal preview is profile-only. The explicit active Account is
    // authoritative; file Account identifiers are retained only for display.
    $parsed = statement_parse_file_with_profile($db, $filename, $contents);
    $namespace = (string)$parsed['evidence_namespace'];
    $profileId = (int)$parsed['import_profile_id'];
    $rows = statement_assign_profile_identities($accountId, $namespace, $parsed['rows']);
    $known = $db->prepare('SELECT 1 FROM statement_rows WHERE account_id=:account_id AND provider=:provider AND row_fingerprint=:fingerprint');
    $knownRows = 0;
    $problems = [];
    foreach ($rows as $row) {
        $known->execute([':account_id' => $accountId, ':provider' => $namespace, ':fingerprint' => $row['row_fingerprint']]);
        if ($known->fetchColumn()) $knownRows++;
        if (($row['parse_problem'] ?? '') !== '') $problems[] = ['row' => $row['source_row_number'], 'problem' => $row['parse_problem']];
    }
    $fileChecksum = hash('sha256', $contents);
    $exact = $db->prepare('SELECT id FROM statement_imports WHERE account_id=:account_id AND provider=:provider AND file_checksum=:checksum');
    $exact->execute([':account_id' => $accountId, ':provider' => $namespace, ':checksum' => $fileChecksum]);
    $existingImportId = $exact->fetchColumn();
    if ($existingImportId !== false) $knownRows = count($rows);
    return [
        'provider' => $namespace,
        'provider_label' => (string)$parsed['provider_label'],
        'account' => statement_account_summary($account),
        'file_account_number' => (string)($parsed['file_account_number'] ?? ''),
        'statement_from' => $parsed['statement_from'],
        'statement_to' => $parsed['statement_to'],
        'rows_found' => count($rows),
        'new_rows' => count($rows) - $knownRows,
        'known_rows' => $knownRows,
        'parse_problem_count' => count($problems),
        'parse_problems' => array_slice($problems, 0, 20),
        'file_checksum' => $fileChecksum,
        'existing_import_id' => $existingImportId === false ? null : (int)$existingImportId,
        'import_profile_id' => $profileId,
        'import_profile_name' => (string)$parsed['import_profile_name'],
        'evidence_namespace' => $namespace,
        '_rows' => $rows,
    ];
}

function import_statement_evidence(PDO $db, int $accountId, string $filename, string $contents, ?array $preparedPreview = null): array
{
    if (!($db instanceof PDO)) throw new InvalidArgumentException('Import Profile database handle is required');
    if ($accountId <= 0) throw new InvalidArgumentException('Select an Account.');
    $preview = $preparedPreview ?? preview_statement_import($db, $accountId, $filename, $contents);
    if (!isset($preview['_rows']) || (int)($preview['account']['id'] ?? 0) !== $accountId) {
        throw new LogicException('Prepared statement preview does not match the selected Account');
    }
    if ($preview['existing_import_id'] !== null) {
        unset($preview['_rows']);
        $autoProcessing = function_exists('statement_reconciliation_auto_process_import')
            ? statement_reconciliation_auto_process_import($db, (int)$preview['existing_import_id'])
            : [];
        return ['ok' => true, 'duplicate_file' => true, 'import_id' => $preview['existing_import_id'], 'preview' => $preview, 'auto_processing' => $autoProcessing];
    }
    $rows = $preview['_rows'];
    $db->beginTransaction();
    try {
        $importProfileId = (int)($preview['import_profile_id'] ?? 0);
        if ($importProfileId <= 0) throw new RuntimeException('Profile-backed statement import requires a matched Import Profile');
        $namespace = (string)($preview['evidence_namespace'] ?? $preview['provider'] ?? '');
        if ($namespace === '') throw new RuntimeException('Profile-backed statement import requires an evidence namespace');
        $insertImport = $db->prepare('INSERT INTO statement_imports
            (account_id,provider,original_filename,file_checksum,statement_from,statement_to,parser_version,row_count,valid_row_count,problem_count,import_profile_id)
            VALUES (:account_id,:provider,:filename,:checksum,:statement_from,:statement_to,:parser_version,:row_count,:valid_row_count,:problem_count,:import_profile_id)');
        $validRows = count(array_filter($rows, static fn(array $row): bool => ($row['parse_problem'] ?? '') === ''));
        $insertImport->execute([
            ':account_id' => $accountId,
            ':provider' => $namespace,
            ':filename' => basename($filename),
            ':checksum' => $preview['file_checksum'],
            ':statement_from' => $preview['statement_from'],
            ':statement_to' => $preview['statement_to'],
            ':parser_version' => STATEMENT_PARSER_VERSION,
            ':row_count' => $preview['rows_found'],
            ':valid_row_count' => $validRows,
            ':problem_count' => $preview['parse_problem_count'],
            ':import_profile_id' => $importProfileId,
        ]);
        $importId = (int)$db->lastInsertId();
        $insertRow = $db->prepare('INSERT OR IGNORE INTO statement_rows
            (import_id,account_id,provider,source_row_number,booking_date,value_date,amount,currency,description_original,description_normalized,provider_reference,running_balance,provider_row_type,row_fingerprint,occurrence_index,parse_problem,raw_fields_json,classification)
            VALUES (:import_id,:account_id,:provider,:source_row_number,:booking_date,:value_date,:amount,:currency,:description_original,:description_normalized,:provider_reference,:running_balance,:provider_row_type,:row_fingerprint,:occurrence_index,:parse_problem,:raw_fields_json,:classification)');
        $inserted = 0;
        foreach ($rows as $row) {
            $classification = ($row['classification'] ?? null) === 'income' ? 'income' : null;
            $insertRow->execute([
                ':import_id' => $importId,
                ':account_id' => $accountId,
                ':provider' => $namespace,
                ':source_row_number' => $row['source_row_number'],
                ':booking_date' => $row['booking_date'],
                ':value_date' => $row['value_date'],
                ':amount' => $row['amount'],
                ':currency' => $row['currency'],
                ':description_original' => $row['description_original'],
                ':description_normalized' => $row['description_normalized'],
                ':provider_reference' => $row['provider_reference'],
                ':running_balance' => $row['running_balance'],
                ':provider_row_type' => $row['provider_row_type'],
                ':row_fingerprint' => $row['row_fingerprint'],
                ':occurrence_index' => $row['occurrence_index'],
                ':parse_problem' => $row['parse_problem'],
                ':raw_fields_json' => json_encode($row['raw_fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':classification' => $classification,
            ]);
            $inserted += $insertRow->rowCount();
        }
        $db->commit();
        unset($preview['_rows']);
        $preview['new_rows'] = $inserted;
        $preview['known_rows'] = $preview['rows_found'] - $inserted;
        $autoProcessing = function_exists('statement_reconciliation_auto_process_import')
            ? statement_reconciliation_auto_process_import($db, $importId)
            : [];
        return ['ok' => true, 'duplicate_file' => false, 'import_id' => $importId, 'preview' => $preview, 'auto_processing' => $autoProcessing];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

const STATEMENT_IMPORT_PROFILE_SCHEMA_VERSION = 1;
const STATEMENT_IMPORT_PROFILE_NAME_MAX_BYTES = 120;
const STATEMENT_IMPORT_PROFILE_NAMESPACE_MAX_BYTES = 160;
const STATEMENT_IMPORT_PROFILE_TOKEN_MAX_BYTES = 120;
const STATEMENT_IMPORT_PROFILE_HEADER_MAX_FIELDS = 64;
const STATEMENT_IMPORT_PROFILE_MARKER_MAX_COUNT = 16;
const STATEMENT_IMPORT_PROFILE_CLASSIFICATION_MAX_MAPPINGS = 16;
const STATEMENT_IMPORT_PROFILE_JSON_MAX_BYTES = 262_144;

function statement_import_profile_assert_object(array $value, array $allowed, array $required, string $path): void
{
    if (array_is_list($value)) throw new InvalidArgumentException("$path must be an object");
    $unknown = array_diff(array_keys($value), $allowed);
    if ($unknown !== []) throw new InvalidArgumentException($path . ' contains unknown key ' . (string)reset($unknown));
    foreach ($required as $key) {
        if (!array_key_exists($key, $value)) throw new InvalidArgumentException("$path.$key is required");
    }
}

function statement_import_profile_assert_list(mixed $value, string $path, int $minimum, int $maximum): array
{
    if (!is_array($value) || !array_is_list($value)) throw new InvalidArgumentException("$path must be an array");
    if (count($value) < $minimum || count($value) > $maximum) {
        throw new InvalidArgumentException("$path must contain between $minimum and $maximum items");
    }
    return $value;
}

function statement_import_profile_assert_text(mixed $value, string $path, int $maximumBytes): string
{
    if (!is_string($value)) throw new InvalidArgumentException("$path must be a string");
    $value = trim($value);
    if ($value === '' || strlen($value) > $maximumBytes || preg_match('//u', $value) !== 1) {
        throw new InvalidArgumentException("$path must be non-empty valid UTF-8 within $maximumBytes bytes");
    }
    return $value;
}

function statement_import_profile_assert_header_key(mixed $value, string $path): string
{
    $key = statement_import_profile_assert_text($value, $path, STATEMENT_IMPORT_PROFILE_TOKEN_MAX_BYTES);
    if (statement_header_key($key) !== $key) throw new InvalidArgumentException("$path must be a normalized header key");
    return $key;
}

function statement_import_profile_assert_normalized_value(mixed $value, string $path): string
{
    $normalized = statement_import_profile_assert_text($value, $path, STATEMENT_IMPORT_PROFILE_TOKEN_MAX_BYTES);
    if (statement_normalize_token($normalized) !== $normalized) throw new InvalidArgumentException("$path must be normalized");
    return $normalized;
}

function statement_import_profile_assert_header_reference(mixed $value, array $headerKeys, string $path): string
{
    $key = statement_import_profile_assert_header_key($value, $path);
    if (!isset($headerKeys[$key])) throw new InvalidArgumentException("$path must reference a signature header key");
    return $key;
}

function statement_import_profile_validate_definition(array $definition, int $schemaVersion = STATEMENT_IMPORT_PROFILE_SCHEMA_VERSION): array
{
    if ($schemaVersion !== STATEMENT_IMPORT_PROFILE_SCHEMA_VERSION) {
        throw new InvalidArgumentException('Unsupported Import Profile schema version');
    }
    statement_import_profile_assert_object($definition, ['signature', 'parser'], ['signature', 'parser'], 'definition');

    $signature = $definition['signature'];
    if (!is_array($signature)) throw new InvalidArgumentException('definition.signature must be an object');
    statement_import_profile_assert_object($signature, ['container', 'sheet', 'header', 'markers'], ['container', 'sheet', 'header', 'markers'], 'definition.signature');
    if ($signature['container'] !== 'ooxml') throw new InvalidArgumentException('definition.signature.container must be ooxml');
    if (!is_int($signature['sheet']) || $signature['sheet'] !== 0) throw new InvalidArgumentException('definition.signature.sheet must be 0');

    $headers = statement_import_profile_assert_list($signature['header'], 'definition.signature.header', 1, STATEMENT_IMPORT_PROFILE_HEADER_MAX_FIELDS);
    $validatedHeaders = [];
    $headerColumns = [];
    $headerKeys = [];
    $previousColumn = -1;
    foreach ($headers as $index => $header) {
        $path = "definition.signature.header.$index";
        if (!is_array($header)) throw new InvalidArgumentException("$path must be an object");
        statement_import_profile_assert_object($header, ['column', 'key'], ['column', 'key'], $path);
        if (!is_int($header['column']) || $header['column'] < 0 || $header['column'] > 1023) {
            throw new InvalidArgumentException("$path.column must be an integer between 0 and 1023");
        }
        $key = statement_import_profile_assert_header_key($header['key'], "$path.key");
        if ($header['column'] <= $previousColumn) throw new InvalidArgumentException('Import Profile headers must be ordered by column');
        if (isset($headerColumns[$header['column']])) throw new InvalidArgumentException('Import Profile header columns must be unique');
        if (isset($headerKeys[$key])) throw new InvalidArgumentException('Import Profile header keys must be unique');
        $previousColumn = $header['column'];
        $headerColumns[$header['column']] = true;
        $headerKeys[$key] = true;
        $validatedHeaders[] = ['column' => $header['column'], 'key' => $key];
    }

    $markers = statement_import_profile_assert_list($signature['markers'], 'definition.signature.markers', 0, STATEMENT_IMPORT_PROFILE_MARKER_MAX_COUNT);
    $validatedMarkers = [];
    foreach ($markers as $index => $marker) {
        $path = "definition.signature.markers.$index";
        if (!is_array($marker)) throw new InvalidArgumentException("$path must be an object");
        statement_import_profile_assert_object($marker, ['column', 'operator', 'value'], ['column', 'operator', 'value'], $path);
        $column = statement_import_profile_assert_header_reference($marker['column'], $headerKeys, "$path.column");
        if (!is_string($marker['operator']) || !in_array($marker['operator'], ['contains', 'not_contains'], true)) {
            throw new InvalidArgumentException("$path.operator is invalid");
        }
        $validatedMarkers[] = [
            'column' => $column,
            'operator' => $marker['operator'],
            'value' => statement_import_profile_assert_normalized_value($marker['value'], "$path.value"),
        ];
    }

    $parser = $definition['parser'];
    if (!is_array($parser)) throw new InvalidArgumentException('definition.parser must be an object');
    $parserKeys = ['booking_date', 'value_date', 'amount', 'currency', 'description', 'running_balance', 'reference', 'row_type', 'account_number', 'classification'];
    statement_import_profile_assert_object($parser, $parserKeys, $parserKeys, 'definition.parser');

    $bookingDate = $parser['booking_date'];
    if (!is_array($bookingDate)) throw new InvalidArgumentException('definition.parser.booking_date must be an object');
    statement_import_profile_assert_object($bookingDate, ['sources'], ['sources'], 'definition.parser.booking_date');
    $bookingSources = statement_import_profile_assert_list($bookingDate['sources'], 'definition.parser.booking_date.sources', 1, 2);
    $validatedBookingSources = [];
    foreach ($bookingSources as $index => $source) {
        $validatedBookingSources[] = statement_import_profile_assert_header_reference($source, $headerKeys, "definition.parser.booking_date.sources.$index");
    }
    if (count(array_unique($validatedBookingSources)) !== count($validatedBookingSources)) {
        throw new InvalidArgumentException('definition.parser.booking_date.sources must be unique');
    }

    $validatedValueDate = null;
    if ($parser['value_date'] !== null) {
        if (!is_array($parser['value_date'])) throw new InvalidArgumentException('definition.parser.value_date must be null or an object');
        statement_import_profile_assert_object($parser['value_date'], ['source', 'omit_if_same_as_booking'], ['source', 'omit_if_same_as_booking'], 'definition.parser.value_date');
        if (!is_bool($parser['value_date']['omit_if_same_as_booking'])) throw new InvalidArgumentException('definition.parser.value_date.omit_if_same_as_booking must be boolean');
        $validatedValueDate = [
            'source' => statement_import_profile_assert_header_reference($parser['value_date']['source'], $headerKeys, 'definition.parser.value_date.source'),
            'omit_if_same_as_booking' => $parser['value_date']['omit_if_same_as_booking'],
        ];
    }

    $amount = $parser['amount'];
    if (!is_array($amount)) throw new InvalidArgumentException('definition.parser.amount must be an object');
    $mode = $amount['mode'] ?? null;
    if ($mode === 'signed') {
        statement_import_profile_assert_object($amount, ['mode', 'column'], ['mode', 'column'], 'definition.parser.amount');
        $validatedAmount = ['mode' => 'signed', 'column' => statement_import_profile_assert_header_reference($amount['column'], $headerKeys, 'definition.parser.amount.column')];
    } elseif ($mode === 'signed_less_nonnegative_fee') {
        statement_import_profile_assert_object($amount, ['mode', 'amount_column', 'fee_column'], ['mode', 'amount_column', 'fee_column'], 'definition.parser.amount');
        $validatedAmount = [
            'mode' => $mode,
            'amount_column' => statement_import_profile_assert_header_reference($amount['amount_column'], $headerKeys, 'definition.parser.amount.amount_column'),
            'fee_column' => statement_import_profile_assert_header_reference($amount['fee_column'], $headerKeys, 'definition.parser.amount.fee_column'),
        ];
    } elseif ($mode === 'debit_credit') {
        statement_import_profile_assert_object($amount, ['mode', 'debit_column', 'credit_column'], ['mode', 'debit_column', 'credit_column'], 'definition.parser.amount');
        $validatedAmount = [
            'mode' => $mode,
            'debit_column' => statement_import_profile_assert_header_reference($amount['debit_column'], $headerKeys, 'definition.parser.amount.debit_column'),
            'credit_column' => statement_import_profile_assert_header_reference($amount['credit_column'], $headerKeys, 'definition.parser.amount.credit_column'),
        ];
    } else {
        throw new InvalidArgumentException('definition.parser.amount.mode is invalid');
    }

    $currency = $parser['currency'];
    if (!is_array($currency)) throw new InvalidArgumentException('definition.parser.currency must be an object');
    $currencyMode = $currency['mode'] ?? null;
    if ($currencyMode === 'constant') {
        statement_import_profile_assert_object($currency, ['mode', 'value'], ['mode', 'value'], 'definition.parser.currency');
        if (!is_string($currency['value']) || preg_match('/^[A-Z]{3}$/D', $currency['value']) !== 1) {
            throw new InvalidArgumentException('definition.parser.currency.value must be three uppercase ASCII letters');
        }
        $validatedCurrency = ['mode' => 'constant', 'value' => $currency['value']];
    } elseif ($currencyMode === 'column') {
        statement_import_profile_assert_object($currency, ['mode', 'column'], ['mode', 'column'], 'definition.parser.currency');
        $validatedCurrency = ['mode' => 'column', 'column' => statement_import_profile_assert_header_reference($currency['column'], $headerKeys, 'definition.parser.currency.column')];
    } else {
        throw new InvalidArgumentException('definition.parser.currency.mode is invalid');
    }

    $description = $parser['description'];
    if (!is_array($description)) throw new InvalidArgumentException('definition.parser.description must be an object');
    statement_import_profile_assert_object($description, ['column', 'counterparty_by_sign'], ['column', 'counterparty_by_sign'], 'definition.parser.description');
    $validatedCounterparties = null;
    if ($description['counterparty_by_sign'] !== null) {
        if (!is_array($description['counterparty_by_sign'])) throw new InvalidArgumentException('definition.parser.description.counterparty_by_sign must be null or an object');
        statement_import_profile_assert_object($description['counterparty_by_sign'], ['negative', 'positive'], ['negative', 'positive'], 'definition.parser.description.counterparty_by_sign');
        $validatedCounterparties = [
            'negative' => statement_import_profile_assert_header_reference($description['counterparty_by_sign']['negative'], $headerKeys, 'definition.parser.description.counterparty_by_sign.negative'),
            'positive' => statement_import_profile_assert_header_reference($description['counterparty_by_sign']['positive'], $headerKeys, 'definition.parser.description.counterparty_by_sign.positive'),
        ];
    }
    $validatedDescription = [
        'column' => statement_import_profile_assert_header_reference($description['column'], $headerKeys, 'definition.parser.description.column'),
        'counterparty_by_sign' => $validatedCounterparties,
    ];

    $validatedRunningBalance = $parser['running_balance'] === null
        ? null
        : statement_import_profile_assert_header_reference($parser['running_balance'], $headerKeys, 'definition.parser.running_balance');
    $validatedReference = $parser['reference'] === null
        ? null
        : statement_import_profile_assert_header_reference($parser['reference'], $headerKeys, 'definition.parser.reference');

    $validatedRowType = null;
    if ($parser['row_type'] !== null) {
        if (!is_array($parser['row_type'])) throw new InvalidArgumentException('definition.parser.row_type must be null or an object');
        statement_import_profile_assert_object($parser['row_type'], ['columns'], ['columns'], 'definition.parser.row_type');
        $columns = statement_import_profile_assert_list($parser['row_type']['columns'], 'definition.parser.row_type.columns', 1, 2);
        $validatedColumns = [];
        foreach ($columns as $index => $column) {
            $validatedColumns[] = statement_import_profile_assert_header_reference($column, $headerKeys, "definition.parser.row_type.columns.$index");
        }
        if (count(array_unique($validatedColumns)) !== count($validatedColumns)) throw new InvalidArgumentException('definition.parser.row_type.columns must be unique');
        $validatedRowType = ['columns' => $validatedColumns];
    }

    $accountNumber = $parser['account_number'];
    if (!is_array($accountNumber)) throw new InvalidArgumentException('definition.parser.account_number must be an object');
    $accountMode = $accountNumber['mode'] ?? null;
    if ($accountMode === 'none') {
        statement_import_profile_assert_object($accountNumber, ['mode'], ['mode'], 'definition.parser.account_number');
        $validatedAccountNumber = ['mode' => 'none'];
    } elseif ($accountMode === 'column_first_nonempty') {
        statement_import_profile_assert_object($accountNumber, ['mode', 'column'], ['mode', 'column'], 'definition.parser.account_number');
        $validatedAccountNumber = [
            'mode' => $accountMode,
            'column' => statement_import_profile_assert_header_reference($accountNumber['column'], $headerKeys, 'definition.parser.account_number.column'),
        ];
    } elseif ($accountMode === 'preheader_prefixed_digits') {
        statement_import_profile_assert_object($accountNumber, ['mode', 'prefix', 'minimum_digits'], ['mode', 'prefix', 'minimum_digits'], 'definition.parser.account_number');
        $prefix = statement_import_profile_assert_normalized_value($accountNumber['prefix'], 'definition.parser.account_number.prefix');
        if (!is_int($accountNumber['minimum_digits']) || $accountNumber['minimum_digits'] < 4 || $accountNumber['minimum_digits'] > 32) {
            throw new InvalidArgumentException('definition.parser.account_number.minimum_digits must be between 4 and 32');
        }
        $validatedAccountNumber = ['mode' => $accountMode, 'prefix' => $prefix, 'minimum_digits' => $accountNumber['minimum_digits']];
    } else {
        throw new InvalidArgumentException('definition.parser.account_number.mode is invalid');
    }

    $validatedClassification = null;
    if ($parser['classification'] !== null) {
        if (!is_array($parser['classification'])) throw new InvalidArgumentException('definition.parser.classification must be null or an object');
        statement_import_profile_assert_object($parser['classification'], ['source', 'mappings'], ['source', 'mappings'], 'definition.parser.classification');
        $source = statement_import_profile_assert_header_reference($parser['classification']['source'], $headerKeys, 'definition.parser.classification.source');
        $mappings = statement_import_profile_assert_list($parser['classification']['mappings'], 'definition.parser.classification.mappings', 1, STATEMENT_IMPORT_PROFILE_CLASSIFICATION_MAX_MAPPINGS);
        $validatedMappings = [];
        $mappingValues = [];
        foreach ($mappings as $index => $mapping) {
            $path = "definition.parser.classification.mappings.$index";
            if (!is_array($mapping)) throw new InvalidArgumentException("$path must be an object");
            statement_import_profile_assert_object($mapping, ['normalized_equals', 'classification'], ['normalized_equals', 'classification'], $path);
            $value = statement_import_profile_assert_normalized_value($mapping['normalized_equals'], "$path.normalized_equals");
            if (isset($mappingValues[$value])) throw new InvalidArgumentException('Import Profile classification mapping values must be unique');
            if ($mapping['classification'] !== 'income') throw new InvalidArgumentException('Only canonical income evidence classification is supported');
            $mappingValues[$value] = true;
            $validatedMappings[] = ['normalized_equals' => $value, 'classification' => 'income'];
        }
        $validatedClassification = ['source' => $source, 'mappings' => $validatedMappings];
    }

    return [
        'signature' => [
            'container' => 'ooxml',
            'sheet' => 0,
            'header' => $validatedHeaders,
            'markers' => $validatedMarkers,
        ],
        'parser' => [
            'booking_date' => ['sources' => $validatedBookingSources],
            'value_date' => $validatedValueDate,
            'amount' => $validatedAmount,
            'currency' => $validatedCurrency,
            'description' => $validatedDescription,
            'running_balance' => $validatedRunningBalance,
            'reference' => $validatedReference,
            'row_type' => $validatedRowType,
            'account_number' => $validatedAccountNumber,
            'classification' => $validatedClassification,
        ],
    ];
}

function statement_import_profile_validate_transport(array $transport): array
{
    statement_import_profile_assert_object(
        $transport,
        ['name', 'evidence_namespace', 'schema_version', 'definition'],
        ['name', 'evidence_namespace', 'schema_version', 'definition'],
        'Import Profile file'
    );
    if (!is_int($transport['schema_version']) || $transport['schema_version'] !== STATEMENT_IMPORT_PROFILE_SCHEMA_VERSION) {
        throw new InvalidArgumentException('Unsupported Import Profile schema version');
    }
    if (!is_array($transport['definition'])) throw new InvalidArgumentException('Import Profile file.definition must be an object');
    return [
        'name' => statement_import_profile_validate_name($transport['name']),
        'evidence_namespace' => statement_import_profile_validate_namespace($transport['evidence_namespace']),
        'schema_version' => $transport['schema_version'],
        'definition' => statement_import_profile_validate_definition($transport['definition'], $transport['schema_version']),
    ];
}

function statement_import_profile_canonical_signature(array $definition, int $schemaVersion = STATEMENT_IMPORT_PROFILE_SCHEMA_VERSION): string
{
    $validated = statement_import_profile_validate_definition($definition, $schemaVersion);
    return json_encode([
        'container' => $validated['signature']['container'],
        'sheet' => $validated['signature']['sheet'],
        'header' => $validated['signature']['header'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function statement_import_profile_signature_hash(array $definition, int $schemaVersion = STATEMENT_IMPORT_PROFILE_SCHEMA_VERSION): string
{
    return hash('sha256', statement_import_profile_canonical_signature($definition, $schemaVersion));
}

function statement_import_profile_header_resolution(array $worksheetRows, array $validatedDefinition): array
{
    $expected = $validatedDefinition['signature']['header'];
    $matches = [];
    foreach (array_slice($worksheetRows, 0, 20, true) as $rowIndex => $candidateRow) {
        if (!is_array($candidateRow)) continue;
        $actual = [];
        $seenKeys = [];
        $valid = true;
        foreach ($candidateRow as $column => $value) {
            $key = statement_header_key((string)$value);
            if ($key === '') continue;
            if (isset($seenKeys[$key])) {
                $valid = false;
                break;
            }
            $seenKeys[$key] = true;
            $actual[] = ['column' => (int)$column, 'key' => $key];
        }
        if ($valid && $actual === $expected) $matches[] = (int)$rowIndex;
    }
    if ($matches === []) throw new InvalidArgumentException('Statement header does not match the selected structure');
    if (count($matches) !== 1) throw new InvalidArgumentException('Statement contains more than one matching header row');

    $columns = [];
    foreach ($expected as $header) $columns[$header['key']] = $header['column'];
    return ['row_index' => $matches[0], 'columns' => $columns];
}

function statement_import_profile_amount(array $row, array $headers, array $config): array
{
    $mode = $config['mode'];
    if ($mode === 'signed') {
        $amount = statement_decimal(statement_row_value($row, $headers, $config['column']));
        return [
            'amount' => $amount,
            'identity_amount' => $amount,
            'problems' => $amount === null ? ['Invalid amount'] : [],
        ];
    }

    if ($mode === 'signed_less_nonnegative_fee') {
        $primary = statement_decimal(statement_row_value($row, $headers, $config['amount_column']));
        $feeRaw = statement_row_value($row, $headers, $config['fee_column']);
        $fee = trim($feeRaw) === '' ? 0.0 : statement_decimal($feeRaw);
        $problems = [];
        if ($primary === null) $problems[] = 'Invalid amount';
        if ($fee === null || $fee < 0) $problems[] = 'Invalid fee';
        return [
            'amount' => $problems === [] ? round($primary - $fee, 8) : null,
            'identity_amount' => $primary,
            'problems' => $problems,
        ];
    }

    $debitRaw = statement_row_value($row, $headers, $config['debit_column']);
    $creditRaw = statement_row_value($row, $headers, $config['credit_column']);
    $debitPresent = trim($debitRaw) !== '';
    $creditPresent = trim($creditRaw) !== '';
    $amount = null;
    if ($debitPresent xor $creditPresent) {
        $magnitude = statement_decimal($debitPresent ? $debitRaw : $creditRaw);
        if ($magnitude !== null && abs($magnitude) > 0.0) $amount = $debitPresent ? -abs($magnitude) : abs($magnitude);
    }
    return [
        'amount' => $amount,
        'identity_amount' => $amount,
        'problems' => $amount === null ? ['Invalid signed amount'] : [],
    ];
}

function statement_import_profile_account_number(array $worksheetRows, array $validatedDefinition, array $header): ?string
{
    $config = $validatedDefinition['parser']['account_number'];
    if ($config['mode'] === 'none') return null;
    if ($config['mode'] === 'column_first_nonempty') {
        foreach (array_slice($worksheetRows, $header['row_index'] + 1) as $row) {
            if (!is_array($row)) continue;
            $value = statement_row_value($row, $header['columns'], $config['column']);
            if ($value !== '') return $value;
        }
        return null;
    }

    $prefix = $config['prefix'];
    foreach (array_slice($worksheetRows, 0, $header['row_index']) as $row) {
        if (!is_array($row)) continue;
        foreach ($row as $cell) {
            $normalized = statement_normalize_token(statement_repair_text((string)$cell));
            if (!str_starts_with($normalized, $prefix)) continue;
            $candidate = trim(substr($normalized, strlen($prefix)));
            if (strlen($candidate) >= $config['minimum_digits'] && ctype_digit($candidate)) return $candidate;
        }
    }
    return null;
}

function statement_import_profile_row_has_data(array $row): bool
{
    foreach ($row as $value) {
        if (trim((string)$value) !== '') return true;
    }
    return false;
}

function statement_import_profile_data_row_count(array $worksheetRows, int $headerRowIndex): int
{
    $rowCount = 0;
    for ($rowIndex = $headerRowIndex + 1, $count = count($worksheetRows); $rowIndex < $count; $rowIndex++) {
        $row = is_array($worksheetRows[$rowIndex]) ? $worksheetRows[$rowIndex] : [];
        if (statement_import_profile_row_has_data($row)) $rowCount++;
    }
    return $rowCount;
}

function statement_parse_rows_with_profile(array $worksheetRows, array $validatedProfileDefinition): array
{
    if (count($worksheetRows) > STATEMENT_MAX_ROWS + 1) throw new InvalidArgumentException('Statement contains more than 10000 rows');
    $worksheetRows = array_values($worksheetRows);
    $definition = statement_import_profile_validate_definition($validatedProfileDefinition);
    $header = statement_import_profile_header_resolution($worksheetRows, $definition);
    $parser = $definition['parser'];

    $identityDateKeys = $parser['booking_date']['sources'];
    if ($parser['value_date'] !== null) $identityDateKeys[] = $parser['value_date']['source'];
    $identityDateKeys = array_values(array_unique($identityDateKeys));
    usort($identityDateKeys, static fn(string $left, string $right): int => $header['columns'][$left] <=> $header['columns'][$right]);

    $parsed = [];
    for ($rowIndex = $header['row_index'] + 1, $count = count($worksheetRows); $rowIndex < $count; $rowIndex++) {
        $row = is_array($worksheetRows[$rowIndex]) ? $worksheetRows[$rowIndex] : [];
        if (!statement_import_profile_row_has_data($row)) continue;

        $raw = [];
        foreach ($header['columns'] as $key => $column) $raw[$key] = statement_repair_text((string)($row[$column] ?? ''));

        $booking = null;
        foreach ($parser['booking_date']['sources'] as $source) {
            $candidate = statement_excel_datetime(statement_row_value($row, $header['columns'], $source));
            if ($candidate instanceof DateTimeImmutable) {
                $booking = $candidate;
                break;
            }
        }
        $valueDate = null;
        if ($parser['value_date'] !== null) {
            $candidate = statement_excel_datetime(statement_row_value($row, $header['columns'], $parser['value_date']['source']));
            if ($candidate instanceof DateTimeImmutable) {
                $formatted = $candidate->format('Y-m-d');
                if (!$parser['value_date']['omit_if_same_as_booking'] || !$booking || $formatted !== $booking->format('Y-m-d')) {
                    $valueDate = $formatted;
                }
            }
        }

        $amountResult = statement_import_profile_amount($row, $header['columns'], $parser['amount']);
        $amount = $amountResult['amount'];
        $currency = $parser['currency']['mode'] === 'constant'
            ? $parser['currency']['value']
            : strtoupper(statement_row_value($row, $header['columns'], $parser['currency']['column']));
        $description = statement_row_value($row, $header['columns'], $parser['description']['column']);
        $counterparties = $parser['description']['counterparty_by_sign'];
        if ($counterparties !== null && $amount !== null && $amount != 0.0) {
            $counterpartyKey = $amount < 0 ? $counterparties['negative'] : $counterparties['positive'];
            $counterparty = statement_row_value($row, $header['columns'], $counterpartyKey);
            if ($counterparty !== '' && !str_contains(statement_normalize_token($description), statement_normalize_token($counterparty))) {
                $description = trim($description . ' · ' . $counterparty, ' ·');
            }
        }

        $rowType = null;
        if ($parser['row_type'] !== null) {
            $parts = [];
            foreach ($parser['row_type']['columns'] as $column) {
                $part = statement_row_value($row, $header['columns'], $column);
                if ($part !== '') $parts[] = $part;
            }
            $rowType = implode(' · ', $parts);
        }

        $classification = null;
        if ($parser['classification'] !== null) {
            $source = statement_normalize_token(statement_row_value($row, $header['columns'], $parser['classification']['source']));
            foreach ($parser['classification']['mappings'] as $mapping) {
                if ($source === $mapping['normalized_equals']) {
                    $classification = 'income';
                    break;
                }
            }
        }

        $problems = [];
        if (!$booking) $problems[] = 'Invalid booking date';
        array_push($problems, ...$amountResult['problems']);
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) $problems[] = 'Invalid currency';
        if ($description === '') $problems[] = 'Missing description';
        $identityDetail = implode('|', array_map(static fn(string $key): string => $raw[$key], $identityDateKeys));
        if ($parser['amount']['mode'] === 'signed_less_nonnegative_fee' && count($identityDateKeys) === 1) $identityDetail .= '|';

        $parsed[] = [
            'source_row_number' => $rowIndex + 1,
            'booking_date' => $booking?->format('Y-m-d'),
            'value_date' => $valueDate,
            'amount' => $amount,
            'currency' => $currency,
            'description_original' => $description,
            'description_normalized' => statement_normalize_token($description),
            'provider_reference' => $parser['reference'] === null ? null : (statement_row_value($row, $header['columns'], $parser['reference']) ?: null),
            'running_balance' => $parser['running_balance'] === null ? null : statement_decimal(statement_row_value($row, $header['columns'], $parser['running_balance'])),
            'provider_row_type' => $rowType,
            'parse_problem' => implode('; ', $problems),
            'raw_fields' => $raw,
            'identity_detail' => $identityDetail,
            'identity_amount' => $amountResult['identity_amount'],
            'classification' => $classification,
        ];
    }

    return [
        'header_row_index' => $header['row_index'],
        'file_account_number' => statement_import_profile_account_number($worksheetRows, $definition, $header),
        'rows' => $parsed,
    ];
}

function statement_import_profile_truncate_text(string $value, int $maximumBytes = 80): string
{
    $value = statement_repair_text($value);
    if (strlen($value) <= $maximumBytes) return $value;
    if (function_exists('mb_strcut')) return mb_strcut($value, 0, $maximumBytes, 'UTF-8') . '…';
    $truncated = substr($value, 0, $maximumBytes);
    while ($truncated !== '' && preg_match('//u', $truncated) !== 1) $truncated = substr($truncated, 0, -1);
    return $truncated . '…';
}

function statement_import_profile_inspect_rows(array $worksheetRows): array
{
    $worksheetRows = array_values($worksheetRows);
    $candidates = [];
    foreach (array_slice($worksheetRows, 0, 20, true) as $rowIndex => $row) {
        if (!is_array($row)) continue;
        $occupied = [];
        $headers = [];
        $seen = [];
        $textLabels = 0;
        $valid = true;
        foreach ($row as $column => $rawValue) {
            $value = statement_repair_text((string)$rawValue);
            if ($value === '') continue;
            $key = statement_header_key($value);
            if ($key === '' || isset($seen[$key])) {
                $valid = false;
                break;
            }
            $seen[$key] = true;
            $occupied[] = (int)$column;
            $headers[] = ['column' => (int)$column, 'key' => statement_import_profile_truncate_text($key, STATEMENT_IMPORT_PROFILE_TOKEN_MAX_BYTES)];
            if (preg_match('/\p{L}/u', $value) === 1) $textLabels++;
        }
        if (!$valid || count($headers) < 2 || count($headers) > STATEMENT_IMPORT_PROFILE_HEADER_MAX_FIELDS || $textLabels < 2) continue;

        $samples = [];
        for ($sampleIndex = $rowIndex + 1, $count = count($worksheetRows); $sampleIndex < $count && count($samples) < 3; $sampleIndex++) {
            $sampleRow = is_array($worksheetRows[$sampleIndex]) ? $worksheetRows[$sampleIndex] : [];
            if (array_filter($sampleRow, static fn(mixed $cell): bool => trim((string)$cell) !== '') === []) continue;
            $values = [];
            foreach (array_slice($occupied, 0, 16) as $column) {
                $values[(string)$column] = statement_import_profile_truncate_text((string)($sampleRow[$column] ?? ''));
            }
            $samples[] = ['row' => $sampleIndex + 1, 'values' => $values];
        }
        $candidates[] = [
            'row' => $rowIndex + 1,
            'occupied_columns' => $occupied,
            'normalized_header_keys' => $headers,
            'sample_rows' => $samples,
        ];
    }
    return [
        'container' => 'ooxml',
        'sheet' => 0,
        'rows_scanned' => min(20, count($worksheetRows)),
        'candidate_rows' => $candidates,
    ];
}

function statement_import_profile_preview(array $worksheetRows, array $validatedDefinition): array
{
    $parsed = statement_parse_rows_with_profile($worksheetRows, $validatedDefinition);
    $valid = array_values(array_filter($parsed['rows'], static fn(array $row): bool => $row['parse_problem'] === ''));
    $dates = array_values(array_filter(array_column($valid, 'booking_date'), static fn(mixed $date): bool => is_string($date) && $date !== ''));
    sort($dates, SORT_STRING);
    $sampleRows = [];
    foreach (array_slice($parsed['rows'], 0, 5) as $row) {
        $sampleRows[] = [
            'date' => $row['booking_date'],
            'amount' => $row['amount'],
            'currency' => statement_import_profile_truncate_text((string)$row['currency'], 16),
            'description' => statement_import_profile_truncate_text($row['description_original'], 120),
            'running_balance' => $row['running_balance'],
            'row_type' => $row['provider_row_type'] === null ? null : statement_import_profile_truncate_text($row['provider_row_type'], 120),
            'reference' => $row['provider_reference'] === null ? null : statement_import_profile_truncate_text($row['provider_reference'], 120),
            'classification' => $row['classification'],
            'parse_problem' => $row['parse_problem'],
        ];
    }
    return [
        'signature_hash' => statement_import_profile_signature_hash($validatedDefinition),
        'header_row' => $parsed['header_row_index'] + 1,
        'file_account_number' => $parsed['file_account_number'] ? statement_import_profile_truncate_text($parsed['file_account_number'], 120) : '—',
        'rows' => count($parsed['rows']),
        'valid_rows' => count($valid),
        'problem_rows' => count($parsed['rows']) - count($valid),
        'statement_from' => $dates === [] ? null : $dates[0],
        'statement_to' => $dates === [] ? null : $dates[count($dates) - 1],
        'sample_rows' => $sampleRows,
    ];
}

function statement_import_profile_row_fingerprint(int $accountId, string $evidenceNamespace, array $row, int $occurrenceIndex = 0): string
{
    if ($accountId <= 0) throw new InvalidArgumentException('Statement evidence Account is required');
    $namespace = statement_import_profile_validate_namespace($evidenceNamespace);
    if (!array_key_exists('identity_amount', $row) || !array_key_exists('identity_detail', $row)) {
        throw new InvalidArgumentException('Generic statement row identity sources are required');
    }
    $identityAmount = $row['identity_amount'];
    if ($identityAmount !== null && !is_numeric($identityAmount)) throw new InvalidArgumentException('Generic statement row identity amount is invalid');
    $base = statement_evidence_identity_base(
        $accountId,
        $namespace,
        $row,
        $identityAmount === null ? null : (float)$identityAmount,
        (string)$row['identity_detail']
    );
    return statement_evidence_fingerprint($base, $occurrenceIndex);
}

function statement_import_profile_validate_name(mixed $name): string
{
    return statement_import_profile_assert_text($name, 'Import Profile name', STATEMENT_IMPORT_PROFILE_NAME_MAX_BYTES);
}

function statement_import_profile_validate_namespace(mixed $namespace): string
{
    return statement_import_profile_assert_text($namespace, 'Import Profile evidence namespace', STATEMENT_IMPORT_PROFILE_NAMESPACE_MAX_BYTES);
}

/**
 * Build the canonical positioned candidate header vector for one worksheet row.
 * Returns null when the row is not a usable header candidate: fewer than two
 * non-empty normalized cells, or any duplicate normalized header key.
 *
 * The result is the ordered (column, key) vector that participates in the
 * closed canonical signature. Cell normalization uses the same deterministic
 * `statement_header_key()` rule as accepted profile design.
 */
function statement_import_profile_candidate_header(array $row): ?array
{
    if (!is_array($row)) return null;
    $actual = [];
    $seenKeys = [];
    foreach ($row as $column => $value) {
        $key = statement_header_key((string)$value);
        if ($key === '') continue;
        if (isset($seenKeys[$key])) return null;
        $seenKeys[$key] = true;
        $actual[] = ['column' => (int)$column, 'key' => $key];
    }
    if (count($actual) < 2) return null;
    return $actual;
}

/**
 * Evaluate the closed bounded marker predicate set of a validated profile
 * against the actual worksheet rows below the matched header.
 *
 * Scan window: the first 20 NON-BLANK data rows after the header row, with
 * blank rows skipped. Each marker column is normalized through the same
 * deterministic `statement_normalize_token()` rule used by accepted profile
 * design. ALL configured markers must match; there is no OR / scoring / regex.
 *
 * Marker operators:
 *   - `contains`:     MATCH when at least one inspected marker-column value
 *                      contains the configured normalized marker value.
 *   - `not_contains`: MATCH when none of the inspected marker-column values
 *                      contains the configured normalized marker value.
 *
 * Blank marker-column cells simply do not contain the marker.
 * `not_contains` does not require non-blank values.
 */
function statement_import_profile_marker_matches(array $validatedDefinition, array $worksheetRows, int $headerRowIndex, array $headerColumns): bool
{
    if (!is_array($validatedDefinition)) throw new InvalidArgumentException('Validated Import Profile definition is required');
    $markers = $validatedDefinition['signature']['markers'] ?? [];
    if (!is_array($markers) || $markers === []) return true;
    if (!is_array($worksheetRows)) throw new InvalidArgumentException('Statement worksheet rows are required');
    if (!is_array($headerColumns)) throw new InvalidArgumentException('Statement header column map is required');

    $samplesByColumn = [];
    foreach ($markers as $marker) {
        if (!isset($headerColumns[$marker['column']])) {
            return false;
        }
        $samplesByColumn[$marker['column']] = [];
    }

    $inspected = 0;
    foreach (array_slice($worksheetRows, $headerRowIndex + 1) as $row) {
        if (!is_array($row)) continue;
        $nonBlank = array_filter($row, static fn(mixed $value): bool => trim((string)$value) !== '');
        if ($nonBlank === []) continue;
        foreach ($markers as $marker) {
            $column = $marker['column'];
            $columnIndex = $headerColumns[$column];
            $raw = (string)($row[$columnIndex] ?? '');
            $samplesByColumn[$column][] = statement_normalize_token(statement_repair_text($raw));
        }
        $inspected++;
        if ($inspected >= 20) break;
    }

    foreach ($markers as $marker) {
        $values = $samplesByColumn[$marker['column']];
        $containsAny = false;
        foreach ($values as $value) {
            if ($value !== '' && str_contains($value, $marker['value'])) {
                $containsAny = true;
                break;
            }
        }
        if ($marker['operator'] === 'contains' && !$containsAny) return false;
        if ($marker['operator'] === 'not_contains' && $containsAny) return false;
    }
    return true;
}

/**
 * Return every distinct (row_index, signature_hash, header_vector) candidate
 * header derived from the first 20 bounded worksheet rows. The returned
 * signature hash is the same SHA-256 form used by stored profiles.
 */
function statement_import_profile_match_candidate_headers(array $worksheetRows): array
{
    $candidates = [];
    foreach (array_slice($worksheetRows, 0, 20, true) as $rowIndex => $row) {
        $headerVector = statement_import_profile_candidate_header($row);
        if ($headerVector === null) continue;
        $canonical = json_encode([
            'container' => 'ooxml',
            'sheet' => 0,
            'header' => $headerVector,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $candidates[] = [
            'row_index' => (int)$rowIndex,
            'signature_hash' => hash('sha256', $canonical),
            'header_vector' => $headerVector,
        ];
    }
    return $candidates;
}

/**
 * Find the structural match for an in-memory OOXML worksheet row set against
 * ENABLED saved Import Profiles. Returns a structured result:
 *
 *   - `status = matched`:   exactly one surviving profile, with its validated
 *                            definition, the matched header row index, and
 *                            the signature hash. Nothing is persisted; no
 *                            Account is resolved; no parsing is performed.
 *   - `status = unknown`:   no valid profile structurally matched, with a
 *                            closed reason.
 *   - `status = ambiguous`: more than one valid profile survived. Returns
 *                            only safe administrative diagnostics (id + name).
 *   - `status = error`:     an ENABLED saved profile was returned by
 *                            candidate hash lookup but failed stored-side
 *                            integrity (decode, schema, hash consistency,
 *                            container / sheet / header-vector drift). The
 *                            matcher stops immediately and does not silently
 *                            fall through to any other surviving profile.
 *                            The result carries safe administrative
 *                            diagnostics (profile_id, profile_name).
 *
 * Disabled profiles are excluded from the lookup. Stored definitions are
 * re-decoded and re-validated, and full container / sheet / header vector
 * equality is confirmed against the actual candidate before marker
 * evaluation. Any stored-side integrity failure stops the match with the
 * explicit administrative error contract above.
 *
 * The matcher is structural only: it does not consult provider names,
 * Account numbers, filenames, geographic markers, or the historical
 * hardcoded detector.
 */
function statement_import_profile_match_rows(PDO $db, array $worksheetRows): array
{
    if (!($db instanceof PDO)) throw new InvalidArgumentException('Import Profile database handle is required');
    if (!is_array($worksheetRows)) throw new InvalidArgumentException('Statement worksheet rows are required');

    $candidates = statement_import_profile_match_candidate_headers($worksheetRows);
    if ($candidates === []) {
        return ['status' => 'unknown', 'reason' => 'no_header_candidate'];
    }

    $hashLookup = [];
    foreach ($candidates as $candidate) {
        $hashLookup[$candidate['signature_hash']] = true;
    }
    $placeholders = [];
    $params = [];
    $i = 0;
    foreach (array_keys($hashLookup) as $hash) {
        $key = ":h$i";
        $placeholders[] = $key;
        $params[$key] = $hash;
        $i++;
    }
    $sql = 'SELECT * FROM statement_import_profiles WHERE enabled=1 AND signature_hash IN (' . implode(',', $placeholders) . ') ORDER BY id';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $surviving = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        try {
            $profile = statement_import_profile_decode_row($row);
        } catch (Throwable) {
            // Fail-closed: any ENABLED saved profile returned by candidate
            // hash lookup that cannot be decoded (JSON parse failure, schema
            // validation failure, stored/computed signature inconsistency) is
            // an administrative matching error. Do not silently drop the
            // candidate; do not fall through to any other surviving profile.
            return [
                'status' => 'error',
                'reason' => 'invalid_import_profile',
                'profile_id' => (int)$row['id'],
                'profile_name' => (string)($row['name'] ?? ''),
                'diagnostic' => 'stored_profile_decode_failed',
            ];
        }
        $validated = $profile['definition'];
        $storedHeader = $validated['signature']['header'];
        $storedContainer = $validated['signature']['container'] ?? null;
        $storedSheet = $validated['signature']['sheet'] ?? null;
        if ($storedContainer !== 'ooxml' || $storedSheet !== 0) {
            // Stored container / sheet drifted from the accepted schema even
            // though the schema validator passed. Treat as administrative
            // error rather than silently skipping the profile.
            return [
                'status' => 'error',
                'reason' => 'invalid_import_profile',
                'profile_id' => (int)$row['id'],
                'profile_name' => (string)($row['name'] ?? ''),
                'diagnostic' => 'stored_profile_container_or_sheet_invalid',
            ];
        }
        $storedHash = (string)$profile['signature_hash'];
        $matchedCandidate = null;
        foreach ($candidates as $candidate) {
            if ($candidate['signature_hash'] !== $storedHash) continue;
            if ($storedHeader !== $candidate['header_vector']) {
                // The stored hash matched a candidate hash (we queried by it)
                // but the stored header vector does not equal that candidate's
                // header vector. This is a stored-side integrity drift that
                // cannot occur when decode_row succeeded; treat as
                // administrative error.
                return [
                    'status' => 'error',
                    'reason' => 'invalid_import_profile',
                    'profile_id' => (int)$row['id'],
                    'profile_name' => (string)($row['name'] ?? ''),
                    'diagnostic' => 'stored_profile_header_vector_drift',
                ];
            }
            $matchedCandidate = $candidate;
            break;
        }
        if ($matchedCandidate === null) continue;
        $columns = [];
        foreach ($storedHeader as $h) $columns[$h['key']] = $h['column'];
        // Marker evaluation is a normal matching outcome, NOT a stored-side
        // integrity check: a `false` result just means this profile does not
        // structurally match this file.
        if (!statement_import_profile_marker_matches($validated, $worksheetRows, $matchedCandidate['row_index'], $columns)) continue;
        $surviving[$profile['id']] = [
            'profile' => $profile,
            'matched_header_row' => $matchedCandidate['row_index'],
            'signature_hash' => $matchedCandidate['signature_hash'],
        ];
    }

    if ($surviving === []) {
        return ['status' => 'unknown', 'reason' => 'no_matching_profile'];
    }
    if (count($surviving) > 1) {
        return [
            'status' => 'ambiguous',
            'reason' => 'ambiguous_match',
            'candidates' => array_map(static fn(array $s): array => [
                'id' => $s['profile']['id'],
                'name' => $s['profile']['name'],
                'signature_hash' => $s['signature_hash'],
            ], array_values($surviving)),
        ];
    }
    $only = array_values($surviving)[0];
    return [
        'status' => 'matched',
        'profile' => $only['profile'],
        'matched_header_row' => $only['matched_header_row'],
        'signature_hash' => $only['signature_hash'],
    ];
}

/**
 * File-path convenience wrapper around `statement_import_profile_match_rows()`.
 * Reads the OOXML file bytes via the closed shared reader, then delegates to
 * the in-memory matcher. Returns the same structured result.
 */
function statement_import_profile_match_file(PDO $db, string $path): array
{
    if (!is_string($path) || $path === '') throw new InvalidArgumentException('Statement file path is required');
    $contents = (string)file_get_contents($path);
    return statement_import_profile_match_rows($db, statement_xlsx_rows($contents));
}

/**
 * Bridge from the saved-profile structural matcher to the generic profile
 * parser. Returns the parsed-statement shape consumed by downstream
 * preview/import, plus:
 *
 *   - `import_profile_id`
 *   - `import_profile_name`
 *   - `evidence_namespace`
 *
 * The rows are produced by the generic parser; each row carries the inert
 * `classification` (canonical `income` or NULL) determined at parse time.
 * The bridge itself does NOT resolve an Account and does NOT persist anything.
 *
 * Failure modes (all raised as `RuntimeException`):
 *   - No matching Import Profile       → "No matching Import Profile"
 *   - Ambiguous Import Profile match    → "Ambiguous Import Profile match"
 *   - Invalid Import Profile configuration → "<diagnostic> (profile <id>: <name>)"
 *
 * The bridge never falls back to a hardcoded format detector or parser.
 */
function statement_parse_file_with_profile(PDO $db, string $filename, string $contents): array
{
    if (!($db instanceof PDO)) throw new InvalidArgumentException('Import Profile database handle is required');
    if (!is_string($filename) || $filename === '') throw new InvalidArgumentException('Statement filename is required');
    if (!is_string($contents)) throw new InvalidArgumentException('Statement file bytes are required');
    $rows = statement_xlsx_rows($contents);
    $match = statement_import_profile_match_rows($db, $rows);

    if (($match['status'] ?? null) === 'error') {
        throw new RuntimeException(sprintf(
            'Invalid Import Profile configuration (profile %d: %s)',
            (int)($match['profile_id'] ?? 0),
            (string)($match['profile_name'] ?? '')
        ));
    }
    if (($match['status'] ?? null) === 'unknown') {
        throw new RuntimeException('No matching Import Profile');
    }
    if (($match['status'] ?? null) === 'ambiguous') {
        throw new RuntimeException('Ambiguous Import Profile match');
    }
    if (($match['status'] ?? null) !== 'matched') {
        throw new RuntimeException('Import Profile matcher returned an unexpected status');
    }
    $profile = $match['profile'];
    $definition = $profile['definition'];
    $rowCount = statement_import_profile_data_row_count($rows, (int)$match['matched_header_row']);
    if ($rowCount > STATEMENT_IMPORT_MAX_ROWS) {
        throw new InvalidArgumentException(sprintf(
            'Statement contains %d rows. Maximum per import is %d. Split the file and import it in smaller parts.',
            $rowCount,
            STATEMENT_IMPORT_MAX_ROWS
        ));
    }
    // Only accepted-size statements enter full field/date/amount parsing.
    $parsed = statement_parse_rows_with_profile($rows, $definition);

    $from = null;
    $to = null;
    foreach ($parsed['rows'] as $row) {
        $date = (string)($row['booking_date'] ?? '');
        if ($date === '') continue;
        if ($from === null || $date < $from) $from = $date;
        if ($to === null || $date > $to) $to = $date;
    }

    return [
        'provider' => (string)$profile['evidence_namespace'],
        'provider_label' => (string)$profile['name'],
        'rows' => $parsed['rows'],
        'statement_from' => $from,
        'statement_to' => $to,
        'file_account_number' => $parsed['file_account_number'],
        'import_profile_id' => (int)$profile['id'],
        'import_profile_name' => (string)$profile['name'],
        'evidence_namespace' => (string)$profile['evidence_namespace'],
        'matched_header_row' => (int)$match['matched_header_row'],
        'signature_hash' => (string)$profile['signature_hash'],
    ];
}

/**
 * Compute fingerprints and occurrence indexes for a set of generic parser rows
 * using the Stage-3 provider-neutral fingerprint bridge keyed by the profile
 * evidence namespace. Mutates and returns the rows.
 *
 * Fee-format profiles use the PRE-FEE primary `identity_amount` already
 * exposed by the generic parser. Malformed-row empty-key normalization is the
 * Stage-4B1 behavior already applied inside the shared identity core.
 */
function statement_assign_profile_identities(int $accountId, string $evidenceNamespace, array $rows): array
{
    if ($accountId <= 0) throw new InvalidArgumentException('Statement evidence Account is required');
    $namespace = statement_import_profile_validate_namespace($evidenceNamespace);
    $occurrences = [];
    foreach ($rows as &$row) {
        $identityAmount = $row['identity_amount'] ?? null;
        if ($identityAmount !== null && !is_numeric($identityAmount)) {
            throw new InvalidArgumentException('Generic statement row identity amount is invalid');
        }
        $identityDetail = (string)($row['identity_detail'] ?? '');
        $base = statement_evidence_identity_base(
            $accountId,
            $namespace,
            $row,
            $identityAmount === null ? null : (float)$identityAmount,
            $identityDetail
        );
        $occurrence = $occurrences[$base] ?? 0;
        $occurrences[$base] = $occurrence + 1;
        $row['occurrence_index'] = $occurrence;
        $row['row_fingerprint'] = statement_import_profile_row_fingerprint($accountId, $namespace, $row, $occurrence);
        unset($row['identity_detail']);
    }
    unset($row);
    return $rows;
}

function statement_import_profile_decode_row(array $row): array
{
    $definition = json_decode((string)$row['definition_json'], true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($definition)) throw new RuntimeException('Stored Import Profile definition is invalid');
    $definition = statement_import_profile_validate_definition($definition, (int)$row['schema_version']);
    $expectedHash = statement_import_profile_signature_hash($definition, (int)$row['schema_version']);
    if (!hash_equals((string)$row['signature_hash'], $expectedHash)) throw new RuntimeException('Stored Import Profile signature hash is invalid');
    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'enabled' => (bool)$row['enabled'],
        'evidence_namespace' => (string)$row['evidence_namespace'],
        'schema_version' => (int)$row['schema_version'],
        'signature_hash' => (string)$row['signature_hash'],
        'definition' => $definition,
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ];
}

function statement_import_profile_get(PDO $db, int $id): array
{
    if ($id <= 0) throw new InvalidArgumentException('Import Profile id is required');
    $stmt = $db->prepare('SELECT * FROM statement_import_profiles WHERE id=:id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new InvalidArgumentException('Import Profile not found');
    return statement_import_profile_decode_row($row);
}

function statement_import_profile_list(PDO $db): array
{
    return array_map('statement_import_profile_decode_row', $db->query('SELECT * FROM statement_import_profiles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
}

function statement_import_profile_is_referenced(PDO $db, int $profileId): bool
{
    if ($profileId <= 0) throw new InvalidArgumentException('Import Profile id is required');
    $stmt = $db->prepare('SELECT 1 FROM statement_imports WHERE import_profile_id=:id LIMIT 1');
    $stmt->execute([':id' => $profileId]);
    return (bool)$stmt->fetchColumn();
}

function statement_import_profile_context(PDO $db, int $importId): ?array
{
    if ($importId <= 0) throw new InvalidArgumentException('Statement import id is required');
    $stmt = $db->prepare('SELECT import_profile_id,provider FROM statement_imports WHERE id=:id');
    $stmt->execute([':id' => $importId]);
    $import = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$import) throw new InvalidArgumentException('Statement import not found');
    if ($import['import_profile_id'] !== null) return statement_import_profile_get($db, (int)$import['import_profile_id']);

    $fallback = $db->prepare('SELECT * FROM statement_import_profiles WHERE evidence_namespace=:namespace');
    $fallback->execute([':namespace' => (string)$import['provider']]);
    $row = $fallback->fetch(PDO::FETCH_ASSOC);
    return $row ? statement_import_profile_decode_row($row) : null;
}

function statement_import_profile_create(PDO $db, array $input): int
{
    statement_import_profile_assert_object($input, ['name', 'enabled', 'evidence_namespace', 'schema_version', 'definition'], ['name', 'evidence_namespace', 'schema_version', 'definition'], 'Import Profile');
    $name = statement_import_profile_validate_name($input['name']);
    $namespace = statement_import_profile_validate_namespace($input['evidence_namespace']);
    if (!is_int($input['schema_version']) || $input['schema_version'] !== STATEMENT_IMPORT_PROFILE_SCHEMA_VERSION) {
        throw new InvalidArgumentException('Unsupported Import Profile schema version');
    }
    $enabled = $input['enabled'] ?? true;
    if (!is_bool($enabled)) throw new InvalidArgumentException('Import Profile enabled must be boolean');
    if (!is_array($input['definition'])) throw new InvalidArgumentException('Import Profile definition must be an object');
    $definition = statement_import_profile_validate_definition($input['definition'], $input['schema_version']);

    $duplicateName = $db->prepare('SELECT 1 FROM statement_import_profiles WHERE lower(trim(name))=lower(:name) LIMIT 1');
    $duplicateName->execute([':name' => $name]);
    if ($duplicateName->fetchColumn()) throw new InvalidArgumentException('An Import Profile with this name already exists');
    $duplicateNamespace = $db->prepare('SELECT 1 FROM statement_import_profiles WHERE evidence_namespace=:namespace LIMIT 1');
    $duplicateNamespace->execute([':namespace' => $namespace]);
    if ($duplicateNamespace->fetchColumn()) throw new InvalidArgumentException('An Import Profile with this evidence namespace already exists');

    $stmt = $db->prepare('INSERT INTO statement_import_profiles
        (name,enabled,evidence_namespace,schema_version,signature_hash,definition_json)
        VALUES (:name,:enabled,:namespace,:schema_version,:signature_hash,:definition_json)');
    try {
        $stmt->execute([
            ':name' => $name,
            ':enabled' => $enabled ? 1 : 0,
            ':namespace' => $namespace,
            ':schema_version' => $input['schema_version'],
            ':signature_hash' => statement_import_profile_signature_hash($definition, $input['schema_version']),
            ':definition_json' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    } catch (PDOException $error) {
        if ((string)$error->getCode() === '23000') throw new InvalidArgumentException('Import Profile name and evidence namespace must be unique', 0, $error);
        throw $error;
    }
    return (int)$db->lastInsertId();
}

function statement_import_profile_update_metadata(PDO $db, int $id, array $changes): array
{
    statement_import_profile_assert_object($changes, ['name', 'enabled', 'evidence_namespace'], [], 'Import Profile update');
    if ($changes === []) throw new InvalidArgumentException('Import Profile update is empty');
    $existing = statement_import_profile_get($db, $id);
    if (array_key_exists('evidence_namespace', $changes)) {
        $namespace = statement_import_profile_validate_namespace($changes['evidence_namespace']);
        if (!hash_equals($existing['evidence_namespace'], $namespace)) throw new InvalidArgumentException('Import Profile evidence namespace is immutable');
    }
    $name = array_key_exists('name', $changes) ? statement_import_profile_validate_name($changes['name']) : $existing['name'];
    $enabled = $changes['enabled'] ?? $existing['enabled'];
    if (!is_bool($enabled)) throw new InvalidArgumentException('Import Profile enabled must be boolean');

    $duplicate = $db->prepare('SELECT 1 FROM statement_import_profiles WHERE id<>:id AND lower(trim(name))=lower(:name) LIMIT 1');
    $duplicate->execute([':id' => $id, ':name' => $name]);
    if ($duplicate->fetchColumn()) throw new InvalidArgumentException('An Import Profile with this name already exists');
    $stmt = $db->prepare('UPDATE statement_import_profiles SET name=:name,enabled=:enabled,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
    try {
        $stmt->execute([':name' => $name, ':enabled' => $enabled ? 1 : 0, ':id' => $id]);
    } catch (PDOException $error) {
        if ((string)$error->getCode() === '23000') throw new InvalidArgumentException('An Import Profile with this name already exists', 0, $error);
        throw $error;
    }
    return statement_import_profile_get($db, $id);
}

function statement_import_profile_rename(PDO $db, int $id, string $name): array
{
    return statement_import_profile_update_metadata($db, $id, ['name' => $name]);
}

function statement_import_profile_set_enabled(PDO $db, int $id, bool $enabled): array
{
    return statement_import_profile_update_metadata($db, $id, ['enabled' => $enabled]);
}

function statement_import_profile_delete(PDO $db, int $id): void
{
    statement_import_profile_get($db, $id);
    $stmt = $db->prepare('DELETE FROM statement_import_profiles WHERE id=:id');
    try {
        $stmt->execute([':id' => $id]);
    } catch (PDOException $error) {
        if ((string)$error->getCode() === '23000') {
            throw new InvalidArgumentException('A referenced Import Profile must be disabled rather than deleted', 0, $error);
        }
        throw $error;
    }
}
