<?php

declare(strict_types=1);

require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/report.php';
require_once __DIR__ . '/recommendation.php';
require_once __DIR__ . '/accounting.php';
require_once __DIR__ . '/statement_import.php';
require_once __DIR__ . '/statement_reconciliation.php';
require_once __DIR__ . '/auth.php';

define('DB_FILE', getenv('FIN_DB_FILE') ?: __DIR__ . '/data/fin.sqlite');
define('DB_DIR', dirname(DB_FILE));

/**
 * The maximum number of Plan rows one `setPlanAmounts` request may carry.
 * The action is meant for one Month's Envelopes, not for bulk imports.
 */
const FIN_PLAN_AMOUNT_MAX_ROWS = 500;

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        run_cli($argv ?? []);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

if (PHP_SAPI !== 'cli') {
$config = app_config();
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
session_name($config['session_name']);
session_set_cookie_params(session_cookie_options($config, $_SERVER));
session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');

// Keep JSON API responses buffered until request shutdown. Runtime fatal errors
// can then replace partial/non-JSON output with one valid JSON error object;
// streamed report downloads remain outside this JSON-only guard.
$GLOBALS['fin_json_response_complete'] = false;
if (($_GET['action'] ?? null) !== 'report') {
    $GLOBALS['fin_json_guard_buffer_level'] = ob_get_level();
    ob_start();
    register_shutdown_function('fin_emit_fatal_json_response');
}

try {
    $db = db();
    migrate($db, $config);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? null;

    if ($method === 'GET') {
        if ($action === 'version') respond(version_info($db));
        if ($action === 'me') {
            respond(array_merge(authentication_bootstrap_status($db, $config), version_info($db)));
        }
        if (!api_token_authorized($config, $method, $action, request_authorization_header())) {
            require_auth($db);
        }
        if ($action === null || $action === 'state') {
            $year = isset($_GET['year']) ? (int)$_GET['year'] : null;
            respond(get_state($db, $year));
        }
        if ($action === 'report') {
            $format = strtolower((string)($_GET['format'] ?? 'pdf'));
            $from = (string)($_GET['from'] ?? '');
            $to = (string)($_GET['to'] ?? '');
            $report = get_report_data($db, $from, $to);
            if ($format === 'pdf') export_report_pdf($db, $report);
            if ($format === 'xlsx') export_report_xlsx($report);
            fail('Unknown report format', 400);
        }
        if ($action === 'history') {
            $from = (string)($_GET['from'] ?? '');
            $to = (string)($_GET['to'] ?? '');
            respond(build_history_response($db, $from, $to));
        }
        if ($action === 'transactions') {
            $from = (string)($_GET['from'] ?? '');
            $to = (string)($_GET['to'] ?? '');
            $envelopeId = null;
            if (array_key_exists('envelope_id', $_GET)) {
                $rawEnvelopeId = trim((string)($_GET['envelope_id'] ?? ''));
                if ($rawEnvelopeId !== '') {
                    $validatedEnvelopeId = filter_var($rawEnvelopeId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($validatedEnvelopeId === false) fail('envelope_id must be a positive integer', 400);
                    $envelopeId = (int)$validatedEnvelopeId;
                }
            }
            try {
                respond(build_transactions_response($db, $from, $to, $envelopeId));
            } catch (InvalidArgumentException $error) {
                fail($error->getMessage(), 400);
            }
        }
        if ($action === 'transfers') {
            $from = (string)($_GET['from'] ?? '');
            $to = (string)($_GET['to'] ?? '');
            try {
                respond(build_transfers_response($db, $from, $to));
            } catch (InvalidArgumentException $error) {
                fail($error->getMessage(), 400);
            }
        }
        if ($action === 'fundingRecommendations') {
            respond(build_funding_recommendations($db, (int)($_GET['year'] ?? 0), (int)($_GET['month'] ?? 0)));
        }
        if ($action === 'accountHistory') {
            respond(get_account_history($db, (int)($_GET['id'] ?? 0)));
        }
        if ($action === 'accountBalances') {
            respond(build_account_balances_response($db));
        }
        if ($action === 'deviceCredentials') {
            respond(['credentials' => public_webauthn_credentials($db)]);
        }
        fail('Unknown GET action', 404);
    }

    if ($method === 'POST') {
        $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($payload)) fail('Invalid JSON payload', 400);
        $action = $payload['action'] ?? $action;

        if ($action === 'masterPinLogin') {
            $pin = is_string($payload['pin'] ?? null) ? $payload['pin'] : '';
            $result = verify_master_pin($config, $pin);
            if (!$result['configured']) fail('Authentication is not configured', 503);
            if (!$result['ok']) fail('Authentication failed', $result['retry_after'] > 0 ? 429 : 401);
            complete_fin_authentication('master-pin');
            respond(array_merge(['ok' => true, 'principal' => ['name' => 'owner'], 'csrf_token' => auth_csrf_token()], version_info($db)));
        }
        if ($action === 'webauthnAssertionOptions') {
            $credentialId = is_string($payload['credential_id'] ?? null) ? $payload['credential_id'] : '';
            $options = webauthn_assertion_options($db, $config, $credentialId);
            respond(['available' => $options !== null, 'options' => $options]);
        }
        if ($action === 'webauthnAssertionVerify') {
            try {
                webauthn_verify_assertion($db, $config, $payload['credential'] ?? []);
            } catch (Throwable) {
                usleep(random_int(30000, 70000));
                fail('Device authentication failed', 401);
            }
            respond(array_merge(['ok' => true, 'principal' => ['name' => 'owner'], 'csrf_token' => auth_csrf_token()], version_info($db)));
        }
        if ($action === 'logout') {
            if (is_fin_authenticated()) {
                try { require_auth_csrf($payload); } catch (Throwable) { fail('Security request could not be verified', 403); }
            }
            destroy_fin_session($config);
            respond(['ok' => true]);
        }

        // The API token authorizes exactly one write action. Every other POST
        // keeps requiring an ordinary authenticated FIN session.
        $tokenAuthorized = api_token_write_authorized($config, $method, $action, request_authorization_header());
        if (!$tokenAuthorized) require_auth($db);
        switch ($action) {
            case 'webauthnRegistrationOptions':
                try {
                    require_auth_csrf($payload);
                    respond(['options' => webauthn_registration_options($db, $config)]);
                } catch (Throwable) {
                    fail('Device enrollment could not be started', 400);
                }
                break;

            case 'webauthnRegistrationVerify':
                try {
                    require_auth_csrf($payload);
                    $id = webauthn_register_credential($db, $config, $payload['credential'] ?? []);
                    respond(['ok' => true, 'id' => $id, 'credentials' => public_webauthn_credentials($db)]);
                } catch (Throwable) {
                    fail('Device enrollment failed', 400);
                }
                break;

            case 'renameWebauthnCredential':
                try {
                    require_auth_csrf($payload);
                    rename_webauthn_credential($db, (int)($payload['id'] ?? 0), (string)($payload['label'] ?? ''));
                    respond(['ok' => true, 'credentials' => public_webauthn_credentials($db)]);
                } catch (Throwable $e) {
                    fail($e instanceof InvalidArgumentException ? $e->getMessage() : 'Device login could not be updated', 400);
                }
                break;

            case 'revokeWebauthnCredential':
                try {
                    require_auth_csrf($payload);
                    revoke_webauthn_credential($db, (int)($payload['id'] ?? 0));
                    respond(['ok' => true, 'credentials' => public_webauthn_credentials($db)]);
                } catch (Throwable) {
                    fail('Device login could not be removed', 400);
                }
                break;

            case 'saveMonth':
                $id = save_month($db, $payload['month'] ?? []);
                respond(['ok' => true, 'id' => $id, 'state' => get_state($db, (int)($payload['year'] ?? 0) ?: null)]);
                break;

            case 'createEntry':
                $id = save_entry($db, $payload['entry'] ?? []);
                respond(['ok' => true, 'id' => $id, 'state' => get_state($db, (int)($payload['year'] ?? 0) ?: null)]);
                break;

            case 'updateEntry':
                $id = (int)($payload['id'] ?? 0);
                if ($id <= 0) fail('Missing entry id', 400);
                save_entry($db, $payload['entry'] ?? [], $id);
                respond(['ok' => true, 'state' => get_state($db, (int)($payload['year'] ?? 0) ?: null)]);
                break;

            case 'deleteEntry':
                $id = (int)($payload['id'] ?? 0);
                if ($id <= 0) fail('Missing entry id', 400);
                delete_allocation_entry($db, $id);
                respond(['ok' => true, 'state' => get_state($db, (int)($payload['year'] ?? 0) ?: null)]);
                break;

            case 'createTransaction':
                try {
                    $id = save_transaction($db, $payload['transaction'] ?? [], null, false, false, !empty($payload['confirm_duplicate']));
                } catch (DuplicateTransactionException $error) {
                    emit_json_response(['ok' => false, 'error' => 'An identical transaction already exists', 'duplicate' => $error->duplicate()], 409);
                }
                respond(['ok' => true, 'id' => $id, 'state' => get_state($db, (int)($payload['year'] ?? 0) ?: null)]);
                break;

            case 'updateTransaction':
                $id = (int)($payload['id'] ?? 0);
                if ($id <= 0) fail('Missing expense id', 400);
                save_transaction($db, $payload['transaction'] ?? [], $id);
                respond(['ok' => true, 'id' => $id, 'state' => get_state($db, (int)($payload['year'] ?? 0) ?: null)]);
                break;

            case 'saveEnvelope':
                $id = save_envelope($db, $payload['envelope'] ?? []);
                respond(['ok' => true, 'id' => $id, 'state' => get_state($db, (int)($payload['year'] ?? 0) ?: null)]);
                break;

            case 'previewStatementImport':
                try {
                    $explicitAccountId = $payload['account_id'] ?? null;
                    $accountId = is_numeric($explicitAccountId) ? (int)$explicitAccountId : null;
                    if ($accountId === null || $accountId <= 0) fail('Select an Account.', 400);
                    statement_account($db, $accountId);
                    [$filename, $contents] = statement_decode_file_payload($payload);
                    $preview = preview_statement_import($db, $accountId, $filename, $contents);
                    unset($preview['_rows']);
                    respond(['ok' => true, 'preview' => $preview]);
                } catch (InvalidArgumentException $error) {
                    fail($error->getMessage(), 400);
                }
                break;

            case 'importStatementEvidence':
                try {
                    $accountId = (int)($payload['account_id'] ?? 0);
                    if ($accountId <= 0) fail('Select an Account.', 400);
                    statement_account($db, $accountId);
                    [$filename, $contents] = statement_decode_file_payload($payload);
                    // UI upload convergence: stage the source file in import/ and let
                    // the shared processor run the existing ingest path, which also moves
                    // the source file to import/imported/ on success.
                    $importDir = statement_drop_dir();
                    $importedDir = statement_imported_dir();
                    $sourcePath = statement_write_to_drop_inbox($importDir, basename($filename), $contents);
                    try {
                        $result = statement_process_one_dropped_file($db, $sourcePath, $importedDir, $accountId);
                    } catch (Throwable $error) {
                        @unlink($sourcePath);
                        throw $error;
                    }
                    if (!isset($result['preview'])) {
                        // Expected file validation failures use FIN's normal
                        // HTTP 400 JSON error contract. A rejected UI upload is
                        // not retained in the operator drop directory.
                        $reason = (string)($result['reason'] ?? 'unknown');
                        if (!empty($result['validation_error'])) {
                            @unlink($sourcePath);
                            fail($reason, 400);
                        }
                        // Other drop-processing outcomes retain the source file
                        // so an operator can inspect or retry it.
                        respond([
                            'ok' => false,
                            'duplicate_file' => !empty($result['duplicate']),
                            'import_id' => $result['import_id'] ?? null,
                            'source_path' => $sourcePath,
                            'outcome' => (string)($result['outcome']),
                            'reason' => $reason,
                            'preview' => ['rows_found' => 0, 'new_rows' => 0, 'known_rows' => 0],
                        ]);
                    }
                    $outcome = (string)$result['outcome'];
                    $previewPayload = $result['preview'];
                    unset($previewPayload['_rows']);
                    respond([
                        'ok' => $outcome === 'IMPORTED' || $outcome === 'ALREADY IMPORTED',
                        'duplicate_file' => $outcome === 'ALREADY IMPORTED',
                        'import_id' => $result['import_id'],
                        'source_path' => $sourcePath,
                        'destination' => $result['destination'],
                        'outcome' => $outcome,
                        'preview' => $previewPayload,
                    ]);
                } catch (InvalidArgumentException $error) {
                    fail($error->getMessage(), 400);
                } catch (RuntimeException $error) {
                    fail($error->getMessage(), 500);
                }
                break;

            case 'loadStatementReconciliation':
                $accountId = (int)($payload['account_id'] ?? 0);
                if ($accountId <= 0) fail('Account is required', 400);
                $account = account_by_id($db, $accountId);
                $reconciliationState = statement_reconciliation_load_for_account($db, $accountId);
                respond([
                    'account' => ['id' => (int)$account['id'], 'name' => (string)$account['name']],
                    'state' => $reconciliationState,
                    'preselections' => statement_reconciliation_history_preselections($db, $accountId, $reconciliationState['unresolved']),
                ]);
                break;

            case 'batchReconcileStatementExpenses':
                $accountId = (int)($payload['account_id'] ?? 0);
                if ($accountId <= 0) fail('Account is required', 400);
                account_by_id($db, $accountId);
                $selections = $payload['selections'] ?? [];
                if (!is_array($selections) || !$selections) fail('At least one manual reconciliation selection is required', 400);
                $results = statement_reconciliation_batch_expenses($db, $accountId, $selections);
                // The browser asynchronously loads the next bounded batch after applying these results.
                respond([
                    'ok' => !array_filter($results, static fn(array $result): bool => $result['status'] === 'error'),
                    'results' => $results,
                ]);
                break;

            case 'removeReconciliationLink':
                $linkId = (int)($payload['link_id'] ?? 0);
                if ($linkId <= 0) fail('Reconciliation link is required', 400);
                $lookup = $db->prepare('SELECT r.account_id FROM statement_reconciliations sr JOIN statement_rows r ON r.id = sr.statement_row_id WHERE sr.id = :id');
                $lookup->execute([':id' => $linkId]);
                $accountId = (int)($lookup->fetchColumn() ?: 0);
                if ($accountId <= 0) fail('Reconciliation link not found', 404);
                $ownsTransaction = !$db->inTransaction();
                if ($ownsTransaction) $db->beginTransaction();
                try {
                    statement_reconciliation_remove_link($db, $linkId);
                    if ($ownsTransaction) $db->commit();
                } catch (Throwable $error) {
                    if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
                    throw $error;
                }
                respond(['ok' => true, 'state' => statement_reconciliation_load_for_account($db, $accountId)]);
                break;

            case 'saveAccount':
                $account = $payload['account'] ?? [];
                $accountId = (int)($account['id'] ?? 0);
                $id = $accountId > 0 ? update_managed_account($db, $accountId, $account) : create_account_with_initial_position($db, $account);
                respond(['ok' => true, 'id' => $id, 'state' => get_state($db, (int)($payload['year'] ?? 0) ?: null)]);
                break;

            case 'createAccountTransfer':
                // Manual Account-to-Account Transfer creation (one canonical operation).
                $sourceAccountId = (int)($payload['source_account_id'] ?? 0);
                $destinationAccountId = (int)($payload['destination_account_id'] ?? 0);
                $amount = (float)($payload['amount'] ?? 0);
                $date = trim((string)($payload['date'] ?? ''));
                $note = trim((string)($payload['note'] ?? ''));
                if ($sourceAccountId <= 0) fail('Source Account is required', 400);
                if ($destinationAccountId <= 0) fail('Destination Account is required', 400);
                if ($sourceAccountId === $destinationAccountId) fail('Destination Account must differ from the source Account', 400);
                if (!is_finite($amount) || $amount <= 0) fail('Amount must be greater than zero', 400);
                if (!valid_date($date)) fail('Date must be YYYY-MM-DD', 400);
                try {
                    $eventId = record_account_transfer($db, $sourceAccountId, $destinationAccountId, $amount, $date, $note);
                } catch (InvalidArgumentException $error) {
                    fail($error->getMessage(), 400);
                }
                respond(['ok' => true, 'account_event_id' => $eventId, 'state' => get_state($db, (int)($payload['year'] ?? 0) ?: null)]);
                break;

            case 'setAccountActive':
                set_account_active($db, (int)($payload['id'] ?? 0), !empty($payload['active']));
                respond(['ok' => true, 'state' => get_state($db, (int)($payload['year'] ?? 0) ?: null)]);
                break;

            case 'saveAccountBalanceObservation':
                $accountId = (int)($payload['id'] ?? 0);
                $account = account_by_id($db, $accountId);
                if ((int)$account['active'] !== 1) fail('Restore the Account before recording its Balance', 400);
                $amount = (float)($payload['balance'] ?? NAN);
                if (!is_finite($amount)) fail('Enter a valid Account Balance', 400);
                try {
                    $observationId = record_account_balance_observation(
                        $db,
                        $accountId,
                        $amount,
                        (string)($payload['effective_date'] ?? ''),
                        (string)($payload['note'] ?? ''),
                        'manual'
                    );
                } catch (InvalidArgumentException $error) {
                    fail($error->getMessage(), 400);
                }
                respond(['ok' => true, 'id' => $observationId, 'state' => get_state($db, (int)($payload['year'] ?? 0) ?: null)]);
                break;

            case 'deleteAccountBalanceObservation':
                $accountId = (int)($payload['account_id'] ?? 0);
                try {
                    delete_account_balance_observation($db, $accountId, (int)($payload['observation_id'] ?? 0));
                } catch (InvalidArgumentException $error) {
                    fail($error->getMessage(), 400);
                }
                respond(['ok' => true, 'state' => get_state($db, (int)($payload['year'] ?? 0) ?: null)]);
                break;

            case 'saveEnvelopeBalanceObservation':
                $envelopeId = (int)($payload['envelope_id'] ?? 0);
                $amount = (float)($payload['balance'] ?? NAN);
                if (!is_finite($amount)) fail('Enter a valid Envelope Balance', 400);
                try {
                    $observationId = record_envelope_balance_observation(
                        $db,
                        $envelopeId,
                        $amount,
                        (string)($payload['effective_date'] ?? ''),
                        (string)($payload['note'] ?? ''),
                        'manual'
                    );
                } catch (InvalidArgumentException $error) {
                    fail($error->getMessage(), 400);
                }
                respond(['ok' => true, 'id' => $observationId, 'state' => get_state($db, (int)($payload['year'] ?? 0) ?: null)]);
                break;

            case 'deleteEnvelopeBalanceObservation':
                try {
                    delete_envelope_balance_observation(
                        $db,
                        (int)($payload['envelope_id'] ?? 0),
                        (int)($payload['observation_id'] ?? 0)
                    );
                } catch (InvalidArgumentException $error) {
                    fail($error->getMessage(), 400);
                }
                respond(['ok' => true, 'state' => get_state($db, (int)($payload['year'] ?? 0) ?: null)]);
                break;

            case 'reconcileEnvelope':
                $reconciliation = (array)($payload['reconciliation'] ?? []);
                $amount = (float)($reconciliation['actual_balance'] ?? NAN);
                if (!is_finite($amount)) fail('Actual balance must be a number', 400);
                try {
                    record_envelope_balance_observation(
                        $db,
                        (int)($reconciliation['envelope_id'] ?? 0),
                        $amount,
                        (string)($reconciliation['movement_date'] ?? ''),
                        (string)($reconciliation['note'] ?? ''),
                        'manual'
                    );
                } catch (InvalidArgumentException $error) {
                    fail($error->getMessage(), 400);
                }
                respond(['ok' => true, 'state' => get_state($db, (int)($payload['year'] ?? 0) ?: null)]);
                break;

            case 'transferEnvelope':
                $id = transfer_envelope_balance($db, $payload['transfer'] ?? []);
                respond(['ok' => true, 'id' => $id, 'state' => get_state($db, (int)($payload['year'] ?? 0) ?: null)]);
                break;

            case 'undoEnvelopeTransfer':
                undo_envelope_transfer($db, (int)($payload['id'] ?? 0));
                respond(['ok' => true, 'state' => get_state($db, (int)($payload['year'] ?? 0) ?: null)]);
                break;

            case 'setAllocationStatus':
                $id = set_allocation_status($db, (int)($payload['month_id'] ?? 0), (int)($payload['envelope_id'] ?? 0), (string)($payload['status'] ?? ''));
                respond(['ok' => true, 'id' => $id, 'state' => get_state($db, (int)($payload['year'] ?? 0) ?: null)]);
                break;

            case 'setPlanAmounts':
                try {
                    respond(set_plan_amounts($db, $payload));
                } catch (PlanAmountException $error) {
                    fail($error->getMessage(), $error->status());
                }
                break;

            case 'setRecommendationExclusion':
                save_recommendation_exclusion(
                    $db,
                    (int)($payload['envelope_id'] ?? 0),
                    (int)($payload['year'] ?? 0),
                    (int)($payload['month'] ?? 0),
                    !empty($payload['excluded']),
                    (string)($payload['reason'] ?? '')
                );
                respond(['ok' => true]);
                break;

            case 'deleteEnvelope':
                $id = (int)($payload['id'] ?? 0);
                if ($id <= 0) fail('Missing envelope id', 400);
                delete_envelope($db, $id);
                respond(['ok' => true, 'state' => get_state($db, (int)($payload['year'] ?? 0) ?: null)]);
                break;

            case 'deleteTransaction':
                $id = (int)($payload['id'] ?? 0);
                if ($id <= 0) fail('Missing expense id', 400);
                delete_transaction($db, $id);
                respond(['ok' => true, 'state' => get_state($db, (int)($payload['year'] ?? 0) ?: null)]);
                break;

            case 'duplicateMonth':
                duplicate_month($db, (int)($payload['from_year'] ?? 0), (int)($payload['from_month'] ?? 0), (int)($payload['to_year'] ?? 0), (int)($payload['to_month'] ?? 0));
                respond(['ok' => true, 'state' => get_state($db, (int)($payload['to_year'] ?? 0) ?: null)]);
                break;

            default:
                fail('Unknown POST action', 404);
        }
    }

    fail('Unsupported method', 405);
} catch (Throwable $e) {
    fail($e->getMessage(), 500);
}
}

function app_version(): string
{
    static $version = null;
    if ($version === null) {
        $versionFile = __DIR__ . '/VERSION';
        $version = is_file($versionFile) ? trim((string)file_get_contents($versionFile)) : 'dev';
    }
    return $version;
}

function session_cookie_options(array $config, array $server): array
{
    $path = trim((string)($config['session_cookie_path'] ?? '/'));
    if ($path === '' || $path[0] !== '/') $path = '/';
    $forwardedProto = strtolower(trim(explode(',', (string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $secure = (!empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off') || $forwardedProto === 'https';
    return [
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => $path,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function app_config(?string $root = null): array
{
    $root = $root === null ? __DIR__ : rtrim($root, DIRECTORY_SEPARATOR);
    $baseFile = $root . '/config.php';
    if (!is_file($baseFile)) throw new RuntimeException('FIN configuration file is missing');
    $config = require $baseFile;
    if (!is_array($config)) throw new RuntimeException('FIN configuration file must return an array');

    $localFile = $root . '/config.local.php';
    if (is_file($localFile)) {
        $local = require $localFile;
        if (!is_array($local)) throw new RuntimeException('FIN local configuration file must return an array');
        $config = array_replace_recursive($config, $local);
    }
    if (!isset($config['auth']) || !is_array($config['auth'])) {
        throw new RuntimeException('FIN auth configuration is missing');
    }
    validate_currency_config($config);
    return $config;
}

function db(): PDO
{
    if (!is_dir(DB_DIR) && !mkdir(DB_DIR, 0775, true) && !is_dir(DB_DIR)) {
        throw new RuntimeException('Could not create data directory');
    }
    $db = new PDO('sqlite:' . DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    return $db;
}

function migrate(PDO $db, ?array $config = null): void
{
    $config ??= app_config();
    set_currency_config($db, $config);
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $migrations = [
        '1.0' => ['name' => 'FIN Planner v1.0 baseline', 'up' => 'migrate_to_v1'],
        '1.1' => ['name' => 'Add envelope plan currency', 'up' => 'migrate_to_v1_1'],
        '1.2' => ['name' => 'Add envelope visibility modes', 'up' => 'migrate_to_v1_2'],
        '1.3' => ['name' => 'Enforce unique envelope names', 'up' => 'migrate_to_v1_3'],
        '1.4' => ['name' => 'Add monthly account transfer markers', 'up' => 'migrate_to_v1_4'],
        '1.5' => ['name' => 'Fund envelopes through account transfers', 'up' => 'migrate_to_v1_5'],
        '1.6' => ['name' => 'Add BS automatic payments', 'up' => 'migrate_to_v1_6'],
        '1.7' => ['name' => 'Add envelope balance tools and transfers', 'up' => 'migrate_to_v1_7'],
        '1.8' => ['name' => 'Add configurable savings goal progress', 'up' => 'migrate_to_v1_8'],
        '1.9' => ['name' => 'Restore independent account transfer reminders', 'up' => 'migrate_to_v1_9'],
        '2.0' => ['name' => 'Store expenses in DKK and EUR', 'up' => 'migrate_to_v2'],
        '2.1' => ['name' => 'Make ledger movements date-effective', 'up' => 'migrate_to_v2_1'],
        '2.2' => ['name' => 'Add funding recommendation metadata', 'up' => 'migrate_to_v2_2'],
        '2.3' => ['name' => 'Add account ledger foundations', 'up' => 'migrate_to_v2_3'],
        '2.4' => ['name' => 'Add September clean-start onboarding', 'up' => 'migrate_to_v2_4'],
        '2.5' => ['name' => 'Add envelope default payment accounts', 'up' => 'migrate_to_v2_5'],
        '2.6' => ['name' => 'Remove rejected onboarding and transaction scope', 'up' => 'migrate_to_v2_6'],
        '2.7' => ['name' => 'Use single-principal device authentication', 'up' => 'migrate_to_v2_7'],
        '2.8' => ['name' => 'Add inert statement evidence ingestion', 'up' => 'migrate_to_v2_8'],
        '2.9' => ['name' => 'Add ordinary statement reconciliation links', 'up' => 'migrate_to_v2_9'],
        '2.10' => ['name' => 'Make Account number mandatory identity', 'up' => 'migrate_to_v2_10'],
        '2.11' => ['name' => 'Add statement reconciliation links to Account events', 'up' => 'migrate_to_v2_11'],
        '2.12' => ['name' => 'Converge Account-event types on Transfer', 'up' => 'migrate_to_v2_12'],
        '2.13' => ['name' => 'Add statement evidence No FIN action disposition', 'up' => 'migrate_to_v2_13'],
        '2.14' => ['name' => 'Add explicit Refund transaction semantic', 'up' => 'migrate_to_v2_14'],
        '2.15' => ['name' => 'Make Account Balance signed and classification-only', 'up' => 'migrate_to_v2_15'],
        '2.16' => ['name' => 'Use one immutable Currency and remove persisted Helper amounts', 'up' => 'migrate_to_v2_16'],
        '2.17' => ['name' => 'Simplify Accounts and retire legacy Account labels', 'up' => 'migrate_to_v2_17'],
        '2.18' => ['name' => 'Add dormant Import Profile foundation', 'up' => 'migrate_to_v2_18'],
        '2.19' => ['name' => 'Add Import Profile provenance to statement imports', 'up' => 'migrate_to_v2_19'],
        '2.20' => ['name' => 'Use absolute Account Balance observations', 'up' => 'migrate_to_v2_20'],
        '2.21' => ['name' => 'Use absolute Envelope Balance observations', 'up' => 'migrate_to_v2_21'],
        '2.22' => ['name' => 'Remove obsolete Envelope Balance movements', 'up' => 'migrate_to_v2_22'],
        '2.23' => ['name' => 'Add Envelope amount source and month-end behavior', 'up' => 'migrate_to_v2_23'],
    ];
    $databaseWasInitialized = (int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() > 0;
    $appliedAny = false;
    foreach ($migrations as $version => $migration) {
        $check = $db->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $check->execute([':version' => $version]);
        if ($check->fetchColumn()) continue;
        if ($version === '2.7') {
            migrate_to_v2_7($db, $config);
        } elseif ($version === '2.16') {
            migrate_to_v2_16($db, $databaseWasInitialized ? 'DKK' : validate_currency_config($config)['currency']);
        } else {
            $migration['up']($db);
        }
        $record = $db->prepare('INSERT OR IGNORE INTO schema_migrations (version, name) VALUES (:version, :name)');
        $record->execute([':version' => $version, ':name' => $migration['name']]);
        $appliedAny = true;
    }

    // Every supported database reaches the canonical current schema through ordered migrations.
    currency_context($db);
}

function migrate_to_v2_2(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS envelope_recommendation_settings (
        envelope_id INTEGER PRIMARY KEY REFERENCES envelopes(id) ON DELETE CASCADE,
        manual_baseline_dkk REAL DEFAULT NULL CHECK(manual_baseline_dkk IS NULL OR manual_baseline_dkk >= 0),
        manual_baseline_note TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS recommendation_exclusions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        envelope_id INTEGER NOT NULL REFERENCES envelopes(id) ON DELETE CASCADE,
        year INTEGER NOT NULL CHECK(year BETWEEN 1900 AND 2200),
        month INTEGER NOT NULL CHECK(month BETWEEN 1 AND 12),
        reason TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(envelope_id, year, month)
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_recommendation_exclusions_month ON recommendation_exclusions(year, month, envelope_id)');
}

function migrate_to_v2_3(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        type TEXT NOT NULL CHECK(type IN ('asset','liability')),
        currency TEXT NOT NULL DEFAULT 'DKK',
        ledger_start_date TEXT DEFAULT NULL,
        active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_accounts_name_normalized ON accounts(lower(trim(name)))');

    $transactionColumns = array_column($db->query('PRAGMA table_info(transactions)')->fetchAll(), 'name');
    $monthColumn = array_values(array_filter($db->query('PRAGMA table_info(transactions)')->fetchAll(), fn(array $column): bool => $column['name'] === 'month_id'))[0] ?? null;
    $needsTransactionRebuild = !in_array('payment_account_id', $transactionColumns, true)
        || !in_array('posting_scope', $transactionColumns, true)
        || (int)($monthColumn['notnull'] ?? 1) === 1;
    if ($needsTransactionRebuild) {
        $db->beginTransaction();
        try {
            $db->exec("CREATE TABLE transactions_v2_3 (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                month_id INTEGER DEFAULT NULL REFERENCES months(id) ON DELETE SET NULL,
                entry_id INTEGER DEFAULT NULL REFERENCES entries(id) ON DELETE SET NULL,
                envelope_id INTEGER DEFAULT NULL,
                payment_account_id INTEGER DEFAULT NULL REFERENCES accounts(id) ON DELETE SET NULL,
                envelope TEXT NOT NULL DEFAULT '',
                amount_dkk REAL NOT NULL DEFAULT 0,
                amount_eur REAL NOT NULL DEFAULT 0,
                currency_original TEXT NOT NULL DEFAULT 'DKK',
                note TEXT NOT NULL DEFAULT '',
                spent_date TEXT NOT NULL,
                posting_scope TEXT NOT NULL DEFAULT 'posted' CHECK(posting_scope IN ('posted','historical_only')),
                is_automatic INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $db->exec("INSERT INTO transactions_v2_3
                (id,month_id,entry_id,envelope_id,payment_account_id,envelope,amount_dkk,amount_eur,currency_original,note,spent_date,posting_scope,is_automatic,created_at,updated_at)
                SELECT id,month_id,entry_id,envelope_id,NULL,envelope,amount_dkk,amount_eur,currency_original,note,spent_date,'posted',is_automatic,created_at,updated_at
                FROM transactions ORDER BY id");
            $db->exec("CREATE TABLE envelope_movements_v2_3 (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                envelope_id INTEGER NOT NULL REFERENCES envelopes(id) ON DELETE CASCADE,
                month_id INTEGER DEFAULT NULL REFERENCES months(id) ON DELETE SET NULL,
                entry_id INTEGER DEFAULT NULL REFERENCES entries(id) ON DELETE SET NULL,
                transaction_id INTEGER DEFAULT NULL REFERENCES transactions_v2_3(id) ON DELETE CASCADE,
                type TEXT NOT NULL DEFAULT 'adjustment',
                amount_dkk REAL NOT NULL DEFAULT 0,
                note TEXT NOT NULL DEFAULT '',
                movement_date TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                transfer_id INTEGER DEFAULT NULL,
                reversal_of_movement_id INTEGER DEFAULT NULL
            )");
            $db->exec("INSERT INTO envelope_movements_v2_3
                (id,envelope_id,month_id,entry_id,transaction_id,type,amount_dkk,note,movement_date,created_at,updated_at,transfer_id,reversal_of_movement_id)
                SELECT id,envelope_id,month_id,entry_id,transaction_id,type,amount_dkk,note,movement_date,created_at,updated_at,transfer_id,reversal_of_movement_id
                FROM envelope_movements ORDER BY id");
            $db->exec('DROP TABLE envelope_movements');
            $db->exec('DROP TABLE transactions');
            $db->exec('ALTER TABLE transactions_v2_3 RENAME TO transactions');
            $db->exec('ALTER TABLE envelope_movements_v2_3 RENAME TO envelope_movements');
            $db->exec('CREATE INDEX idx_transactions_month_date ON transactions(month_id, spent_date, id)');
            $db->exec('CREATE INDEX idx_transactions_envelope ON transactions(envelope_id)');
            $db->exec('CREATE INDEX idx_transactions_payment_account ON transactions(payment_account_id, spent_date, id)');
            $db->exec('CREATE UNIQUE INDEX idx_transactions_entry_automatic ON transactions(entry_id) WHERE entry_id IS NOT NULL AND is_automatic = 1');
            $db->exec('CREATE INDEX idx_envelope_movements_envelope ON envelope_movements(envelope_id, movement_date, id)');
            $db->exec("CREATE UNIQUE INDEX idx_envelope_movements_entry_funding ON envelope_movements(entry_id) WHERE entry_id IS NOT NULL AND type = 'funding'");
            $db->exec("CREATE UNIQUE INDEX idx_envelope_movements_transaction_expense ON envelope_movements(transaction_id) WHERE transaction_id IS NOT NULL AND type = 'expense'");
            $db->exec('CREATE INDEX idx_envelope_movements_transfer ON envelope_movements(transfer_id)');
            $db->exec('CREATE UNIQUE INDEX idx_envelope_movements_reversal ON envelope_movements(reversal_of_movement_id) WHERE reversal_of_movement_id IS NOT NULL');
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    $db->exec("CREATE TABLE IF NOT EXISTS account_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_type TEXT NOT NULL CHECK(event_type IN ('opening','income','expense','transfer','borrowing','repayment','reconciliation')),
        event_date TEXT NOT NULL,
        amount_dkk REAL NOT NULL CHECK(amount_dkk > 0),
        source_account_id INTEGER DEFAULT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
        destination_account_id INTEGER DEFAULT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
        transaction_id INTEGER DEFAULT NULL UNIQUE REFERENCES transactions(id) ON DELETE CASCADE,
        note TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS account_movements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id INTEGER NOT NULL REFERENCES account_events(id) ON DELETE CASCADE,
        account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
        sequence INTEGER NOT NULL CHECK(sequence > 0),
        amount_dkk REAL NOT NULL CHECK(amount_dkk <> 0),
        movement_date TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(event_id, account_id),
        UNIQUE(event_id, sequence)
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_account_movements_account_date ON account_movements(account_id, movement_date, id)');
    $db->exec("CREATE TABLE IF NOT EXISTS month_funding_context (
        month_id INTEGER PRIMARY KEY REFERENCES months(id) ON DELETE CASCADE,
        opening_available_owned_dkk REAL DEFAULT NULL,
        source TEXT NOT NULL DEFAULT 'unknown' CHECK(source IN ('unknown','user_confirmed','derived')),
        confirmed_at TEXT DEFAULT NULL,
        note TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
}

function migrate_to_v2_4(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS onboarding_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        month_id INTEGER NOT NULL UNIQUE REFERENCES months(id) ON DELETE CASCADE,
        ledger_start_date TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','completed')),
        card_envelope_action TEXT DEFAULT NULL CHECK(card_envelope_action IS NULL OR card_envelope_action IN ('keep','rename')),
        completed_at TEXT DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS onboarding_account_openings (
        session_id INTEGER NOT NULL REFERENCES onboarding_sessions(id) ON DELETE CASCADE,
        account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
        opening_amount_dkk REAL NOT NULL CHECK(opening_amount_dkk >= 0),
        applied_event_id INTEGER DEFAULT NULL UNIQUE REFERENCES account_events(id) ON DELETE SET NULL,
        applied_at TEXT DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(session_id,account_id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS onboarding_envelope_reviews (
        session_id INTEGER NOT NULL REFERENCES onboarding_sessions(id) ON DELETE CASCADE,
        envelope_id INTEGER NOT NULL REFERENCES envelopes(id) ON DELETE CASCADE,
        review_action TEXT NOT NULL CHECK(review_action IN ('keep','reset','custom')),
        target_balance_dkk REAL DEFAULT NULL,
        applied_movement_id INTEGER DEFAULT NULL UNIQUE REFERENCES envelope_movements(id) ON DELETE SET NULL,
        reviewed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        applied_at TEXT DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CHECK((review_action='custom' AND target_balance_dkk IS NOT NULL) OR (review_action<>'custom')),
        PRIMARY KEY(session_id,envelope_id)
    )");
}

function migrate_to_v2_5(PDO $db): void
{
    $columns = array_column($db->query('PRAGMA table_info(envelopes)')->fetchAll(), 'name');
    if (!in_array('default_account_id', $columns, true)) {
        $db->exec('ALTER TABLE envelopes ADD COLUMN default_account_id INTEGER DEFAULT NULL REFERENCES accounts(id) ON DELETE SET NULL');
    }
    $db->exec('CREATE INDEX IF NOT EXISTS idx_envelopes_default_account ON envelopes(default_account_id)');
}

function migrate_to_v2_6(PDO $db): void
{
    $obsoleteTables = ['onboarding_envelope_reviews', 'onboarding_account_openings', 'onboarding_sessions'];
    foreach ($obsoleteTables as $table) {
        $exists = (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=" . $db->quote($table))->fetchColumn() > 0;
        if ($exists && (int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn() !== 0) {
            throw new RuntimeException("Database v2.6 will not discard non-empty $table; review the obsolete data first");
        }
    }

    $transactionColumns = array_column($db->query('PRAGMA table_info(transactions)')->fetchAll(), 'name');
    $entryColumns = array_column($db->query('PRAGMA table_info(entries)')->fetchAll(), 'name');
    $hasPostingScope = in_array('posting_scope', $transactionColumns, true);
    if ($hasPostingScope && (int)$db->query("SELECT COUNT(*) FROM transactions WHERE posting_scope <> 'posted'")->fetchColumn() !== 0) {
        throw new RuntimeException('Database v2.6 cannot remove posting_scope while non-Posted transactions exist; resolve them first');
    }
    $transactionFields = 'id,month_id,entry_id,envelope_id,payment_account_id,envelope,amount_dkk,amount_eur,currency_original,note,spent_date,is_automatic,created_at,updated_at';
    $transactionsBefore = $db->query("SELECT $transactionFields FROM transactions ORDER BY id")->fetchAll();
    $sequenceBefore = $db->query("SELECT seq FROM sqlite_sequence WHERE name='transactions'")->fetchColumn();
    $foreignKeysEnabled = (int)$db->query('PRAGMA foreign_keys')->fetchColumn() === 1;
    if ($db->inTransaction()) throw new RuntimeException('Database v2.6 migration requires no active transaction');
    if ($foreignKeysEnabled) $db->exec('PRAGMA foreign_keys=OFF');

    try {
        $db->beginTransaction();
        if (!in_array('bs_payment_account_id', $entryColumns, true)) {
            $db->exec('ALTER TABLE entries ADD COLUMN bs_payment_account_id INTEGER DEFAULT NULL REFERENCES accounts(id) ON DELETE SET NULL');
        }
        if (!in_array('bs_expense_finalized_at', $entryColumns, true)) {
            $db->exec('ALTER TABLE entries ADD COLUMN bs_expense_finalized_at TEXT DEFAULT NULL');
        }
        $db->exec('CREATE INDEX IF NOT EXISTS idx_entries_bs_payment_account ON entries(bs_payment_account_id)');
        if ($hasPostingScope) {
            $db->exec("CREATE TABLE transactions_v2_6 (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                month_id INTEGER DEFAULT NULL REFERENCES months(id) ON DELETE SET NULL,
                entry_id INTEGER DEFAULT NULL REFERENCES entries(id) ON DELETE SET NULL,
                envelope_id INTEGER DEFAULT NULL,
                payment_account_id INTEGER DEFAULT NULL REFERENCES accounts(id) ON DELETE SET NULL,
                envelope TEXT NOT NULL DEFAULT '',
                amount_dkk REAL NOT NULL DEFAULT 0,
                amount_eur REAL NOT NULL DEFAULT 0,
                currency_original TEXT NOT NULL DEFAULT 'DKK',
                note TEXT NOT NULL DEFAULT '',
                spent_date TEXT NOT NULL,
                is_automatic INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $db->exec("INSERT INTO transactions_v2_6 ($transactionFields)
                SELECT $transactionFields FROM transactions ORDER BY id");
            $db->exec('DROP TABLE transactions');
            $db->exec('ALTER TABLE transactions_v2_6 RENAME TO transactions');
            $db->exec('CREATE INDEX idx_transactions_month_date ON transactions(month_id, spent_date, id)');
            $db->exec('CREATE INDEX idx_transactions_envelope ON transactions(envelope_id)');
            $db->exec('CREATE INDEX idx_transactions_payment_account ON transactions(payment_account_id, spent_date, id)');
            $db->exec('CREATE UNIQUE INDEX idx_transactions_entry_automatic ON transactions(entry_id) WHERE entry_id IS NOT NULL AND is_automatic = 1');
            if ($sequenceBefore !== false) {
                $sequence = $db->prepare("UPDATE sqlite_sequence SET seq=:seq WHERE name='transactions'");
                $sequence->execute([':seq' => (int)$sequenceBefore]);
                if ($sequence->rowCount() === 0) $db->prepare("INSERT INTO sqlite_sequence(name,seq) VALUES ('transactions',:seq)")->execute([':seq' => (int)$sequenceBefore]);
            }
        }
        foreach ($obsoleteTables as $table) $db->exec("DROP TABLE IF EXISTS $table");
        if ($transactionsBefore !== $db->query("SELECT $transactionFields FROM transactions ORDER BY id")->fetchAll()) {
            throw new RuntimeException('Database v2.6 transaction reconstruction verification failed');
        }
        if ($db->query('PRAGMA foreign_key_check')->fetch()) throw new RuntimeException('Database v2.6 foreign-key verification failed');
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    } finally {
        if ($foreignKeysEnabled) $db->exec('PRAGMA foreign_keys=ON');
    }
}

function migrate_to_v2_7(PDO $db, array $config): void
{
    $usersExist = (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn() === 1;
    $userCount = $usersExist ? (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() : 0;
    if ($userCount > 0 && !master_pin_configured($config)) {
        throw new RuntimeException('Configure the server-managed Master PIN before upgrading authentication');
    }

    $db->beginTransaction();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS auth_principal (
            id INTEGER PRIMARY KEY CHECK(id=1),
            user_handle BLOB NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        if ((int)$db->query('SELECT COUNT(*) FROM auth_principal')->fetchColumn() === 0) {
            $principal = $db->prepare('INSERT INTO auth_principal (id,user_handle) VALUES (1,:user_handle)');
            $principal->bindValue(':user_handle', random_bytes(32), PDO::PARAM_LOB);
            $principal->execute();
        }
        $db->exec("CREATE TABLE IF NOT EXISTS webauthn_credentials (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            credential_id BLOB NOT NULL UNIQUE,
            public_key TEXT NOT NULL,
            sign_count INTEGER NOT NULL DEFAULT 0 CHECK(sign_count>=0),
            transports TEXT NOT NULL DEFAULT '[]',
            label TEXT NOT NULL DEFAULT 'This device',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at TEXT DEFAULT NULL,
            revoked_at TEXT DEFAULT NULL
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_webauthn_credentials_active ON webauthn_credentials(revoked_at,created_at,id)');
        if ($usersExist) $db->exec('DROP TABLE users');
        if ($db->query('PRAGMA foreign_key_check')->fetch()) throw new RuntimeException('Database v2.7 foreign-key verification failed');
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function migrate_to_v2_8(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS statement_imports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
        provider TEXT NOT NULL,
        original_filename TEXT NOT NULL,
        file_checksum TEXT NOT NULL,
        imported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        statement_from TEXT DEFAULT NULL,
        statement_to TEXT DEFAULT NULL,
        parser_version TEXT NOT NULL,
        row_count INTEGER NOT NULL CHECK(row_count >= 0),
        valid_row_count INTEGER NOT NULL CHECK(valid_row_count >= 0),
        problem_count INTEGER NOT NULL CHECK(problem_count >= 0),
        UNIQUE(account_id, provider, file_checksum)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS statement_rows (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        import_id INTEGER NOT NULL REFERENCES statement_imports(id) ON DELETE CASCADE,
        account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
        provider TEXT NOT NULL,
        source_row_number INTEGER NOT NULL CHECK(source_row_number > 0),
        booking_date TEXT DEFAULT NULL,
        value_date TEXT DEFAULT NULL,
        amount_original REAL DEFAULT NULL,
        currency_original TEXT NOT NULL DEFAULT '',
        description_original TEXT NOT NULL DEFAULT '',
        description_normalized TEXT NOT NULL DEFAULT '',
        provider_reference TEXT DEFAULT NULL,
        running_balance REAL DEFAULT NULL,
        provider_row_type TEXT NOT NULL DEFAULT '',
        row_fingerprint TEXT NOT NULL,
        occurrence_index INTEGER NOT NULL DEFAULT 0 CHECK(occurrence_index >= 0),
        parse_problem TEXT NOT NULL DEFAULT '',
        raw_fields_json TEXT NOT NULL DEFAULT '{}',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(account_id, provider, row_fingerprint)
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_statement_imports_account_date ON statement_imports(account_id, imported_at, id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_statement_rows_import ON statement_rows(import_id, source_row_number, id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_statement_rows_account_date ON statement_rows(account_id, booking_date, id)');
}

function migrate_to_v2_9(PDO $db): void
{
    // Statement reconciliation is provenance only. The link table is disposable:
    // - statement_rows ON DELETE CASCADE removes its links when evidence is purged;
    // - transactions ON DELETE CASCADE removes its links when the authoritative record is deleted;
    // - no ordinary FIN financial table holds a foreign key into statement_reconciliations.
    $db->exec("CREATE TABLE IF NOT EXISTS statement_reconciliations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        statement_row_id INTEGER NOT NULL UNIQUE REFERENCES statement_rows(id) ON DELETE CASCADE,
        transaction_id INTEGER NOT NULL REFERENCES transactions(id) ON DELETE CASCADE,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_statement_reconciliations_transaction ON statement_reconciliations(transaction_id)');
}

function migrate_to_v2_10(PDO $db): void
{
    // Account number is a canonical, mandatory, user-configured identifier. Current statement
    // import ownership is selected explicitly; Account Name remains presentation only.
    //
    // Migration: add the column with NOT NULL DEFAULT '' so existing rows are safely populated;
    // backfill every existing Account with a unique reserved placeholder 'TEMP-<id>'; enforce
    // uniqueness through a UNIQUE INDEX. Non-empty is enforced at the application layer because
    // SQLite cannot ALTER a column's NOT NULL constraint in place.
    $columns = array_column($db->query('PRAGMA table_info(accounts)')->fetchAll(), 'name');
    if (!in_array('account_number', $columns, true)) {
        $db->exec("ALTER TABLE accounts ADD COLUMN account_number TEXT NOT NULL DEFAULT ''");
    }
    // Backfill: any row with empty account_number becomes TEMP-<id>. Idempotent.
    $db->exec("UPDATE accounts SET account_number = 'TEMP-' || id WHERE TRIM(IFNULL(account_number, '')) = ''");
    // Unique constraint: Account identifiers remain unique and the user-facing value is preserved as-is.
    // Exact counterpart matching normalizes presentation through statement_normalize_account_number().
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_accounts_account_number ON accounts(account_number)');
}

function migrate_to_v2_11(PDO $db): void
{
    // Add a nullable link from statement_reconciliations to account_events so statement evidence
    // can reconcile to an authoritative Account-to-Account Transfer. SQLite cannot relax an
    // existing NOT NULL column with ALTER TABLE, so the table is recreated with the transaction_id
    // column relaxed to nullable. The "exactly one of (transaction_id, target_account_event_id)"
    // invariant is enforced at the application layer by statement_reconciliation_link_insert.
    // Foreign-key check is preserved because both columns still reference their authoritative
    // tables with ON DELETE CASCADE.
    $tableInfo = array_column($db->query('PRAGMA table_info(statement_reconciliations)')->fetchAll(), 'name');
    if (in_array('target_account_event_id', $tableInfo, true)) {
        // Migration already applied; just make sure the supporting index exists.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_statement_reconciliations_account_event ON statement_reconciliations(target_account_event_id)');
        return;
    }

    // Snapshot existing data before recreating.
    $existingRows = $db->query('SELECT id, statement_row_id, transaction_id, created_at FROM statement_reconciliations')->fetchAll(PDO::FETCH_ASSOC);
    $existingIndexes = $db->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='statement_reconciliations' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($existingIndexes as $indexName) {
        $db->exec("DROP INDEX IF EXISTS " . $indexName);
    }
    $db->exec('DROP TABLE statement_reconciliations');
    $db->exec("CREATE TABLE statement_reconciliations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        statement_row_id INTEGER NOT NULL UNIQUE REFERENCES statement_rows(id) ON DELETE CASCADE,
        transaction_id INTEGER DEFAULT NULL REFERENCES transactions(id) ON DELETE CASCADE,
        target_account_event_id INTEGER DEFAULT NULL REFERENCES account_events(id) ON DELETE CASCADE,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $restore = $db->prepare('INSERT INTO statement_reconciliations (id, statement_row_id, transaction_id, target_account_event_id, created_at) VALUES (:id, :rid, :tid, :eid, :created_at)');
    foreach ($existingRows as $row) {
        $restore->execute([
            ':id' => (int)$row['id'],
            ':rid' => (int)$row['statement_row_id'],
            ':tid' => $row['transaction_id'] === null ? null : (int)$row['transaction_id'],
            ':eid' => null,
            ':created_at' => (string)$row['created_at'],
        ]);
    }
    $db->exec('CREATE INDEX IF NOT EXISTS idx_statement_reconciliations_transaction ON statement_reconciliations(transaction_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_statement_reconciliations_account_event ON statement_reconciliations(target_account_event_id)');
}

function migrate_to_v2_12(PDO $db): void
{
    // Converge active FIN on a single Account-to-Account accounting semantic: Transfer.
    // The historical v2.3 schema CHECK on account_events.event_type accepted
    // ('opening','income','expense','transfer','borrowing','repayment','reconciliation').
    // Canonical architecture: ALL ACCOUNTS ARE ACCOUNTS, and there is exactly one
    // Account-to-Account semantic. 'borrowing' and 'repayment' are not separate
    // Account-to-Account accounting semantics and must be retired from active code.
    //
    // Safety: the existing record_account_event writers only ever emit
    // 'opening', 'expense', and 'reconciliation'. The record_account_transfer
    // v1.27 writer emits 'transfer'. Production and test fixtures inspected at
    // v1.28 / DB v2.12 contain zero rows with event_type IN
    // ('borrowing','repayment'). Migration is therefore safe: the schema CHECK
    // is tightened to the new canonical set without any row rewrite.
    //
    // SQLite cannot ALTER an existing CHECK constraint. The table is recreated
    // with the new constraint; existing rows are preserved as-is (zero rows match
    // the obsolete labels so no data is changed). Foreign keys (including the
    // v2.11 statement_reconciliations.target_account_event_id → account_events.id
    // link) are preserved by recreating the table inside a deferred-FK context.
    $createSql = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='account_events'")->fetchColumn();
    $alreadyCanonical = is_string($createSql) && strpos($createSql, "'borrowing'") === false && strpos($createSql, "'repayment'") === false;
    if ($alreadyCanonical) {
        return;
    }

    $rows = $db->query('SELECT * FROM account_events')->fetchAll(PDO::FETCH_ASSOC);
    $db->exec('DROP TABLE account_events');
    $db->exec("CREATE TABLE account_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_type TEXT NOT NULL CHECK(event_type IN ('opening','income','expense','transfer','reconciliation')),
        event_date TEXT NOT NULL,
        amount_dkk REAL NOT NULL CHECK(amount_dkk > 0),
        source_account_id INTEGER DEFAULT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
        destination_account_id INTEGER DEFAULT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
        transaction_id INTEGER DEFAULT NULL UNIQUE REFERENCES transactions(id) ON DELETE CASCADE,
        note TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    if ($rows) {
        $restore = $db->prepare('INSERT INTO account_events (id, event_type, event_date, amount_dkk, source_account_id, destination_account_id, transaction_id, note, created_at, updated_at) VALUES (:id, :event_type, :event_date, :amount_dkk, :source_account_id, :destination_account_id, :transaction_id, :note, :created_at, :updated_at)');
        foreach ($rows as $row) {
            $restore->execute([
                ':id' => (int)$row['id'],
                ':event_type' => (string)$row['event_type'],
                ':event_date' => (string)$row['event_date'],
                ':amount_dkk' => (float)$row['amount_dkk'],
                ':source_account_id' => $row['source_account_id'] === null ? null : (int)$row['source_account_id'],
                ':destination_account_id' => $row['destination_account_id'] === null ? null : (int)$row['destination_account_id'],
                ':transaction_id' => $row['transaction_id'] === null ? null : (int)$row['transaction_id'],
                ':note' => (string)$row['note'],
                ':created_at' => (string)$row['created_at'],
                ':updated_at' => (string)$row['updated_at'],
            ]);
        }
    }
}

function database_version(PDO $db): string
{
    $versions = $db->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    if (!$versions) return '0';
    usort($versions, 'version_compare');
    return (string)end($versions);
}

function version_info(PDO $db): array
{
    return [
        'app_version' => app_version(),
        'database_version' => database_version($db),
    ];
}

function migrate_to_v2_13(PDO $db): void
{
    // No FIN action is a third, financially inert reconciliation disposition. Recreate the
    // disposable link table so SQLite enforces exactly one of Expense, Transfer, or No FIN action.
    $columns = array_column($db->query('PRAGMA table_info(statement_reconciliations)')->fetchAll(), 'name');
    $createSql = (string)$db->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'statement_reconciliations'")->fetchColumn();
    if (in_array('disposition', $columns, true) && str_contains($createSql, "disposition = 'no_fin_action'")) {
        return;
    }

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $hasPartialDispositionNote = in_array('disposition_note', $columns, true);
        $bothTargets = (int)$db->query('SELECT COUNT(*) FROM statement_reconciliations WHERE transaction_id IS NOT NULL AND target_account_event_id IS NOT NULL')->fetchColumn();
        $neitherTarget = (int)$db->query('SELECT COUNT(*) FROM statement_reconciliations WHERE transaction_id IS NULL AND target_account_event_id IS NULL')->fetchColumn();
        if ($bothTargets > 0 || ($neitherTarget > 0 && !$hasPartialDispositionNote)) {
            throw new RuntimeException('Invalid statement reconciliation disposition found during migration');
        }

        $db->exec('DROP TABLE IF EXISTS statement_reconciliations_v2_13');
        $db->exec("CREATE TABLE statement_reconciliations_v2_13 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            statement_row_id INTEGER NOT NULL UNIQUE REFERENCES statement_rows(id) ON DELETE CASCADE,
            transaction_id INTEGER DEFAULT NULL REFERENCES transactions(id) ON DELETE CASCADE,
            target_account_event_id INTEGER DEFAULT NULL REFERENCES account_events(id) ON DELETE CASCADE,
            disposition TEXT DEFAULT NULL CHECK(disposition IS NULL OR disposition = 'no_fin_action'),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CHECK((transaction_id IS NOT NULL) + (target_account_event_id IS NOT NULL) + (disposition IS NOT NULL) = 1)
        )");
        $dispositionSql = $hasPartialDispositionNote
            ? "CASE WHEN transaction_id IS NULL AND target_account_event_id IS NULL THEN 'no_fin_action' ELSE NULL END"
            : 'NULL';
        $db->exec("INSERT INTO statement_reconciliations_v2_13 (id, statement_row_id, transaction_id, target_account_event_id, disposition, created_at)
            SELECT id, statement_row_id, transaction_id, target_account_event_id, $dispositionSql, created_at
            FROM statement_reconciliations");
        $db->exec('DROP TABLE statement_reconciliations');
        $db->exec('ALTER TABLE statement_reconciliations_v2_13 RENAME TO statement_reconciliations');
        $db->exec('CREATE INDEX idx_statement_reconciliations_transaction ON statement_reconciliations(transaction_id)');
        $db->exec('CREATE INDEX idx_statement_reconciliations_account_event ON statement_reconciliations(target_account_event_id)');
        if ($ownsTransaction) $db->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function v2_14_schema_state(PDO $db): array
{
    $transactionInfo = $db->query('PRAGMA table_info(transactions)')->fetchAll();
    $kindColumn = array_values(array_filter($transactionInfo, static fn(array $column): bool => $column['name'] === 'kind'))[0] ?? null;
    $transactionSql = (string)$db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='transactions'")->fetchColumn();
    $eventSql = (string)$db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='account_events'")->fetchColumn();
    $eventForeignKeys = $db->query('PRAGMA foreign_key_list(account_events)')->fetchAll();
    $foreignKey = static function(string $from, string $table, string $onDelete) use ($eventForeignKeys): bool {
        return (bool)array_filter($eventForeignKeys, static fn(array $fk): bool => $fk['from'] === $from && $fk['table'] === $table && strtoupper((string)$fk['on_delete']) === $onDelete);
    };
    $movementIndexSql = (string)$db->query("SELECT sql FROM sqlite_master WHERE type='index' AND name='idx_envelope_movements_transaction'")->fetchColumn();
    $normalizedIndexSql = strtolower(preg_replace('/\s+/', ' ', $movementIndexSql) ?? $movementIndexSql);
    return [
        'has_kind' => $kindColumn !== null,
        'transactions_ready' => $kindColumn !== null
            && strtoupper((string)$kindColumn['type']) === 'TEXT'
            && (int)$kindColumn['notnull'] === 1
            && trim((string)$kindColumn['dflt_value'], "'\"") === 'expense'
            && str_contains(strtolower($transactionSql), "check(kind in ('expense','refund'))"),
        'events_ready' => str_contains(strtolower($eventSql), "event_type in ('opening','income','expense','refund','transfer','reconciliation')")
            && !str_contains(strtolower($eventSql), "'borrowing'")
            && !str_contains(strtolower($eventSql), "'repayment'")
            && $foreignKey('source_account_id', 'accounts', 'RESTRICT')
            && $foreignKey('destination_account_id', 'accounts', 'RESTRICT')
            && $foreignKey('transaction_id', 'transactions', 'CASCADE'),
        'movement_index_ready' => str_contains($normalizedIndexSql, 'transaction_id is not null')
            && str_contains($normalizedIndexSql, "type in ('expense','refund')"),
    ];
}

function assert_v2_14_schema(PDO $db): void
{
    $state = v2_14_schema_state($db);
    if (!$state['transactions_ready'] || !$state['events_ready'] || !$state['movement_index_ready']) {
        throw new RuntimeException('Database v2.14 schema verification failed');
    }
    if ($db->query('PRAGMA foreign_key_check')->fetch()) {
        throw new RuntimeException('Database v2.14 schema has a foreign-key violation');
    }
}

function migrate_to_v2_14(PDO $db): void
{
    // Refund adds one Transaction discriminator and one Account-event type. Existing rows retain
    // Expense/event semantics. The generic movement index enforces one Expense-or-Refund Envelope
    // movement per Transaction.
    $state = v2_14_schema_state($db);
    if ($state['has_kind'] && !$state['transactions_ready']) {
        throw new RuntimeException('Database has an incompatible transactions.kind definition');
    }
    if ($state['transactions_ready'] && $state['events_ready'] && $state['movement_index_ready']) {
        assert_v2_14_schema($db);
        return;
    }
    if ($db->inTransaction()) throw new RuntimeException('Database v2.14 migration requires its own transaction');

    $foreignKeysEnabled = (int)$db->query('PRAGMA foreign_keys')->fetchColumn() === 1;
    $eventSequence = $state['events_ready'] ? null : $db->query("SELECT seq FROM sqlite_sequence WHERE name='account_events'")->fetchColumn();
    if ($foreignKeysEnabled) $db->exec('PRAGMA foreign_keys = OFF');
    $db->beginTransaction();
    try {
        if (!$state['transactions_ready']) {
            $db->exec("ALTER TABLE transactions ADD COLUMN kind TEXT NOT NULL DEFAULT 'expense' CHECK(kind IN ('expense','refund'))");
        }
        if (!$state['events_ready']) {
            $db->exec('DROP TABLE IF EXISTS account_events_v2_14');
            $db->exec("CREATE TABLE account_events_v2_14 (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type TEXT NOT NULL CHECK(event_type IN ('opening','income','expense','refund','transfer','reconciliation')),
                event_date TEXT NOT NULL,
                amount_dkk REAL NOT NULL CHECK(amount_dkk > 0),
                source_account_id INTEGER DEFAULT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
                destination_account_id INTEGER DEFAULT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
                transaction_id INTEGER DEFAULT NULL UNIQUE REFERENCES transactions(id) ON DELETE CASCADE,
                note TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $db->exec('INSERT INTO account_events_v2_14 (id,event_type,event_date,amount_dkk,source_account_id,destination_account_id,transaction_id,note,created_at,updated_at)
                SELECT id,event_type,event_date,amount_dkk,source_account_id,destination_account_id,transaction_id,note,created_at,updated_at FROM account_events ORDER BY id');
            $db->exec('DROP TABLE account_events');
            $db->exec('ALTER TABLE account_events_v2_14 RENAME TO account_events');
            if ($eventSequence !== false && $eventSequence !== null) {
                $sequence = $db->prepare("UPDATE sqlite_sequence SET seq=:seq WHERE name='account_events'");
                $sequence->execute([':seq' => (int)$eventSequence]);
                if ($sequence->rowCount() === 0) {
                    $db->prepare("INSERT INTO sqlite_sequence(name,seq) VALUES ('account_events',:seq)")->execute([':seq' => (int)$eventSequence]);
                }
            }
        }
        $db->exec('DROP INDEX IF EXISTS idx_envelope_movements_transaction_expense');
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_envelope_movements_transaction ON envelope_movements(transaction_id) WHERE transaction_id IS NOT NULL AND type IN ('expense','refund')");
        assert_v2_14_schema($db);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        if ($foreignKeysEnabled) $db->exec('PRAGMA foreign_keys = ON');
        throw $error;
    }
    if ($foreignKeysEnabled) $db->exec('PRAGMA foreign_keys = ON');
    assert_v2_14_schema($db);
}

function v2_15_accounts_schema_ready(PDO $db): bool
{
    $sql = strtolower((string)$db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='accounts'")->fetchColumn());
    return str_contains($sql, "type in ('asset','liability','credit_card')");
}

function assert_v2_15_schema(PDO $db): void
{
    if (!v2_15_accounts_schema_ready($db)) {
        throw new RuntimeException('Database v2.15 Account classification schema verification failed');
    }
    if ($db->query('PRAGMA foreign_key_check')->fetch()) {
        throw new RuntimeException('Database v2.15 schema has a foreign-key violation');
    }
}

function migrate_to_v2_15(PDO $db): void
{
    if (v2_15_accounts_schema_ready($db)) {
        assert_v2_15_schema($db);
        return;
    }
    if ($db->inTransaction()) throw new RuntimeException('Database v2.15 migration requires its own transaction');
    if ((int)$db->query("SELECT COUNT(*) FROM accounts WHERE type NOT IN ('asset','liability')")->fetchColumn() !== 0) {
        throw new RuntimeException('Database contains an unsupported Account classification');
    }

    $fields = 'id,name,type,currency,ledger_start_date,active,created_at,updated_at,account_number';
    $accountsBefore = $db->query("SELECT $fields FROM accounts ORDER BY id")->fetchAll();
    $positionsBefore = account_position_snapshot($db, '9999-12-31', 'amount_dkk');
    $sequenceBefore = $db->query("SELECT seq FROM sqlite_sequence WHERE name='accounts'")->fetchColumn();
    $foreignKeysEnabled = (int)$db->query('PRAGMA foreign_keys')->fetchColumn() === 1;
    if ($foreignKeysEnabled) $db->exec('PRAGMA foreign_keys = OFF');

    $db->beginTransaction();
    try {
        $db->exec('DROP TABLE IF EXISTS accounts_v2_15');
        $db->exec("CREATE TABLE accounts_v2_15 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            type TEXT NOT NULL CHECK(type IN ('asset','liability','credit_card')),
            currency TEXT NOT NULL DEFAULT 'DKK',
            ledger_start_date TEXT DEFAULT NULL,
            active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            account_number TEXT NOT NULL DEFAULT ''
        )");
        $db->exec("INSERT INTO accounts_v2_15 ($fields) SELECT $fields FROM accounts ORDER BY id");
        $db->exec('DROP TABLE accounts');
        $db->exec('ALTER TABLE accounts_v2_15 RENAME TO accounts');
        $db->exec('CREATE UNIQUE INDEX idx_accounts_name_normalized ON accounts(lower(trim(name)))');
        $db->exec('CREATE UNIQUE INDEX idx_accounts_account_number ON accounts(account_number)');

        if ($sequenceBefore !== false && $sequenceBefore !== null) {
            $targetSequence = max((int)$sequenceBefore, (int)$db->query('SELECT COALESCE(MAX(id),0) FROM accounts')->fetchColumn());
            $sequence = $db->prepare("UPDATE sqlite_sequence SET seq=:seq WHERE name='accounts'");
            $sequence->execute([':seq' => $targetSequence]);
            if ($sequence->rowCount() === 0) {
                $db->prepare("INSERT INTO sqlite_sequence(name,seq) VALUES ('accounts',:seq)")->execute([':seq' => $targetSequence]);
            }
        }
        $db->exec("DELETE FROM sqlite_sequence WHERE name='accounts_v2_15'");

        if ($accountsBefore !== $db->query("SELECT $fields FROM accounts ORDER BY id")->fetchAll()) {
            throw new RuntimeException('Database v2.15 Account rows changed during migration');
        }
        if ($positionsBefore !== account_position_snapshot($db, '9999-12-31', 'amount_dkk')) {
            throw new RuntimeException('Database v2.15 Account Balances changed during migration');
        }
        assert_v2_15_schema($db);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        if ($foreignKeysEnabled) $db->exec('PRAGMA foreign_keys = ON');
        throw $error;
    }
    if ($foreignKeysEnabled) $db->exec('PRAGMA foreign_keys = ON');
    assert_v2_15_schema($db);
}

function migrate_to_v2_16(PDO $db, string $initialCurrency): void
{
    $initialCurrency = currency_code($initialCurrency, 'Currency');
    foreach (['accounts', 'envelopes'] as $table) {
        $unexpected = $db->query("SELECT DISTINCT currency FROM $table WHERE currency NOT IN ('DKK','EUR') ORDER BY currency")->fetchAll(PDO::FETCH_COLUMN);
        if ($unexpected) {
            throw new RuntimeException("Database v2.16 cannot map unexpected $table currency preference: " . implode(', ', $unexpected));
        }
    }

    $sequence = [];
    foreach (['months', 'accounts', 'envelopes', 'entries', 'transactions'] as $table) {
        $stmt = $db->prepare("SELECT seq FROM sqlite_sequence WHERE name=:name");
        $stmt->execute([':name' => $table]);
        $value = $stmt->fetchColumn();
        $sequence[$table] = $value === false ? null : (int)$value;
    }
    unset($stmt);
    $foreignKeysEnabled = (int)$db->query('PRAGMA foreign_keys')->fetchColumn() === 1;
    if ($foreignKeysEnabled) $db->exec('PRAGMA foreign_keys = OFF');

    $db->beginTransaction();
    try {
        $db->exec("CREATE TABLE database_settings (
            id INTEGER PRIMARY KEY CHECK(id=1),
            currency TEXT NOT NULL CHECK(length(currency)=3 AND currency=upper(currency))
        )");
        $insertCurrency = $db->prepare('INSERT INTO database_settings(id,currency) VALUES (1,:currency)');
        $insertCurrency->execute([':currency' => $initialCurrency]);

        $db->exec("CREATE TABLE months_v2_16 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            year INTEGER NOT NULL,
            month INTEGER NOT NULL,
            exchange_rate REAL,
            salary REAL NOT NULL DEFAULT 0,
            target_savings_percent REAL NOT NULL DEFAULT 0.11,
            notes TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(year, month)
        )");
        $db->exec("INSERT INTO months_v2_16 (id,year,month,exchange_rate,salary,target_savings_percent,notes,created_at,updated_at)
            SELECT id,year,month,exchange_rate_eur_dkk,salary_dkk,target_savings_percent,notes,created_at,updated_at
            FROM months ORDER BY id");
        $db->exec('DROP TABLE months');
        $db->exec('ALTER TABLE months_v2_16 RENAME TO months');

        $db->exec("CREATE TABLE accounts_v2_16 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            type TEXT NOT NULL CHECK(type IN ('asset','liability','credit_card')),
            currency TEXT NOT NULL DEFAULT 'CURRENCY' CHECK(currency IN ('CURRENCY','HELPER_CURRENCY')),
            ledger_start_date TEXT DEFAULT NULL,
            active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            account_number TEXT NOT NULL DEFAULT ''
        )");
        $db->exec("INSERT INTO accounts_v2_16 (id,name,type,currency,ledger_start_date,active,created_at,updated_at,account_number)
            SELECT id,name,type,CASE currency WHEN 'DKK' THEN 'CURRENCY' ELSE 'HELPER_CURRENCY' END,ledger_start_date,active,created_at,updated_at,account_number
            FROM accounts ORDER BY id");
        $db->exec('DROP TABLE accounts');
        $db->exec('ALTER TABLE accounts_v2_16 RENAME TO accounts');
        $db->exec('CREATE UNIQUE INDEX idx_accounts_name_normalized ON accounts(lower(trim(name)))');
        $db->exec('CREATE UNIQUE INDEX idx_accounts_account_number ON accounts(account_number)');

        $db->exec("CREATE TABLE envelopes_v2_16 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            account TEXT NOT NULL DEFAULT '',
            note TEXT NOT NULL DEFAULT '',
            role TEXT NOT NULL DEFAULT 'daily',
            currency TEXT NOT NULL DEFAULT 'CURRENCY' CHECK(currency IN ('CURRENCY','HELPER_CURRENCY')),
            visibility TEXT NOT NULL DEFAULT 'normal',
            savings_goal REAL NOT NULL DEFAULT 0,
            goal_visibility TEXT NOT NULL DEFAULT 'month',
            hidden INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            default_account_id INTEGER DEFAULT NULL REFERENCES accounts(id) ON DELETE SET NULL
        )");
        $db->exec("INSERT INTO envelopes_v2_16 (id,name,account,note,role,currency,visibility,savings_goal,goal_visibility,hidden,sort_order,created_at,updated_at,default_account_id)
            SELECT id,name,account,note,role,CASE currency WHEN 'DKK' THEN 'CURRENCY' ELSE 'HELPER_CURRENCY' END,visibility,savings_goal_dkk,goal_visibility,hidden,sort_order,created_at,updated_at,default_account_id
            FROM envelopes ORDER BY id");
        $db->exec('DROP TABLE envelopes');
        $db->exec('ALTER TABLE envelopes_v2_16 RENAME TO envelopes');
        $db->exec('CREATE UNIQUE INDEX idx_envelopes_name_normalized ON envelopes(lower(trim(name)))');
        $db->exec('CREATE INDEX idx_envelopes_default_account ON envelopes(default_account_id)');

        $db->exec("CREATE TABLE entries_v2_16 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            month_id INTEGER NOT NULL REFERENCES months(id) ON DELETE CASCADE,
            envelope_id INTEGER DEFAULT NULL,
            type TEXT NOT NULL DEFAULT 'expense',
            name TEXT NOT NULL,
            category TEXT NOT NULL DEFAULT 'Other',
            amount REAL NOT NULL DEFAULT 0,
            account TEXT NOT NULL DEFAULT '',
            note TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'planned',
            due_date TEXT DEFAULT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_recurring INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            is_bs INTEGER NOT NULL DEFAULT 0,
            bs_payment_account_id INTEGER DEFAULT NULL REFERENCES accounts(id) ON DELETE SET NULL,
            bs_expense_finalized_at TEXT DEFAULT NULL
        )");
        $db->exec("INSERT INTO entries_v2_16 (id,month_id,envelope_id,type,name,category,amount,account,note,status,due_date,sort_order,is_recurring,created_at,updated_at,is_bs,bs_payment_account_id,bs_expense_finalized_at)
            SELECT id,month_id,envelope_id,type,name,category,amount_dkk,account,note,status,due_date,sort_order,is_recurring,created_at,updated_at,is_bs,bs_payment_account_id,bs_expense_finalized_at
            FROM entries ORDER BY id");
        $db->exec('DROP TABLE entries');
        $db->exec('ALTER TABLE entries_v2_16 RENAME TO entries');
        $db->exec('CREATE INDEX idx_entries_month_sort ON entries(month_id,sort_order,id)');
        $db->exec('CREATE INDEX idx_entries_envelope ON entries(envelope_id)');
        $db->exec('CREATE INDEX idx_entries_bs_payment_account ON entries(bs_payment_account_id)');

        $db->exec("CREATE TABLE transactions_v2_16 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            month_id INTEGER DEFAULT NULL REFERENCES months(id) ON DELETE SET NULL,
            entry_id INTEGER DEFAULT NULL REFERENCES entries(id) ON DELETE SET NULL,
            envelope_id INTEGER DEFAULT NULL,
            payment_account_id INTEGER DEFAULT NULL REFERENCES accounts(id) ON DELETE SET NULL,
            envelope TEXT NOT NULL DEFAULT '',
            amount REAL NOT NULL DEFAULT 0,
            note TEXT NOT NULL DEFAULT '',
            spent_date TEXT NOT NULL,
            is_automatic INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            kind TEXT NOT NULL DEFAULT 'expense' CHECK(kind IN ('expense','refund'))
        )");
        $db->exec("INSERT INTO transactions_v2_16 (id,month_id,entry_id,envelope_id,payment_account_id,envelope,amount,note,spent_date,is_automatic,created_at,updated_at,kind)
            SELECT id,month_id,entry_id,envelope_id,payment_account_id,envelope,amount_dkk,note,spent_date,is_automatic,created_at,updated_at,kind
            FROM transactions ORDER BY id");
        $db->exec('DROP TABLE transactions');
        $db->exec('ALTER TABLE transactions_v2_16 RENAME TO transactions');
        $db->exec('CREATE INDEX idx_transactions_month_date ON transactions(month_id,spent_date,id)');
        $db->exec('CREATE INDEX idx_transactions_envelope ON transactions(envelope_id)');
        $db->exec('CREATE INDEX idx_transactions_payment_account ON transactions(payment_account_id,spent_date,id)');
        $db->exec("CREATE UNIQUE INDEX idx_transactions_entry_automatic ON transactions(entry_id) WHERE entry_id IS NOT NULL AND is_automatic=1");

        $renames = [
            ['account_events','amount_dkk','amount'],
            ['account_movements','amount_dkk','amount'],
            ['envelope_movements','amount_dkk','amount'],
            ['envelope_transfers','amount_dkk','amount'],
            ['envelope_recommendation_settings','manual_baseline_dkk','manual_baseline'],
            ['month_funding_context','opening_available_owned_dkk','opening_available_owned'],
            ['statement_rows','amount_original','amount'],
            ['statement_rows','currency_original','currency'],
        ];
        foreach ($renames as [$table,$from,$to]) $db->exec("ALTER TABLE $table RENAME COLUMN $from TO $to");

        $deleteSequence = $db->prepare('DELETE FROM sqlite_sequence WHERE name=:name');
        $restoreSequence = $db->prepare('INSERT INTO sqlite_sequence(name,seq) VALUES (:name,:seq)');
        foreach ($sequence as $table => $value) {
            $deleteSequence->execute([':name' => $table]);
            if ($value !== null) {
                $restoreSequence->bindValue(':name', $table, PDO::PARAM_STR);
                $restoreSequence->bindValue(':seq', $value, PDO::PARAM_INT);
                $restoreSequence->execute();
            }
        }
        $db->exec("DELETE FROM sqlite_sequence WHERE name IN ('months_v2_16','accounts_v2_16','envelopes_v2_16','entries_v2_16','transactions_v2_16')");
        $record = $db->prepare("INSERT OR IGNORE INTO schema_migrations(version,name) VALUES ('2.16',:name)");
        $record->execute([':name' => 'Use one immutable Currency and remove persisted Helper amounts']);
        if ($db->query('PRAGMA foreign_key_check')->fetch()) throw new RuntimeException('Database v2.16 foreign-key verification failed');
        if ((string)$db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') throw new RuntimeException('Database v2.16 integrity verification failed');
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        if ($foreignKeysEnabled) $db->exec('PRAGMA foreign_keys = ON');
        throw $error;
    }
    if ($foreignKeysEnabled) $db->exec('PRAGMA foreign_keys = ON');
}

function migrate_to_v2_17(PDO $db): void
{
    $sequence = [];
    foreach (['accounts', 'envelopes', 'entries', 'envelope_transfers'] as $table) {
        $stmt = $db->prepare('SELECT seq FROM sqlite_sequence WHERE name=:name');
        $stmt->execute([':name' => $table]);
        $value = $stmt->fetchColumn();
        $sequence[$table] = $value === false ? null : (int)$value;
    }
    unset($stmt);

    $foreignKeysEnabled = (int)$db->query('PRAGMA foreign_keys')->fetchColumn() === 1;
    if ($foreignKeysEnabled) $db->exec('PRAGMA foreign_keys = OFF');
    $db->beginTransaction();
    try {
        // A legacy Envelope label is mapped only when its normalized value uniquely
        // identifies an existing Account. Existing explicit references always win.
        $db->exec("UPDATE envelopes
            SET default_account_id=(
                SELECT a.id FROM accounts a
                WHERE lower(trim(a.name))=lower(trim(envelopes.account))
                LIMIT 1
            )
            WHERE default_account_id IS NULL AND trim(account)<>''
              AND (SELECT COUNT(*) FROM accounts a WHERE lower(trim(a.name))=lower(trim(envelopes.account)))=1");

        $db->exec("CREATE TABLE accounts_v2_17 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            currency TEXT NOT NULL DEFAULT 'CURRENCY' CHECK(currency IN ('CURRENCY','HELPER_CURRENCY')),
            ledger_start_date TEXT DEFAULT NULL,
            active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            account_number TEXT NOT NULL DEFAULT ''
        )");
        $db->exec('INSERT INTO accounts_v2_17 (id,name,currency,ledger_start_date,active,created_at,updated_at,account_number)
            SELECT id,name,currency,ledger_start_date,active,created_at,updated_at,account_number FROM accounts ORDER BY id');
        $db->exec('DROP TABLE accounts');
        $db->exec('ALTER TABLE accounts_v2_17 RENAME TO accounts');
        $db->exec('CREATE UNIQUE INDEX idx_accounts_name_normalized ON accounts(lower(trim(name)))');
        $db->exec('CREATE UNIQUE INDEX idx_accounts_account_number ON accounts(account_number)');

        $db->exec("CREATE TABLE envelopes_v2_17 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            note TEXT NOT NULL DEFAULT '',
            role TEXT NOT NULL DEFAULT 'daily',
            currency TEXT NOT NULL DEFAULT 'CURRENCY' CHECK(currency IN ('CURRENCY','HELPER_CURRENCY')),
            visibility TEXT NOT NULL DEFAULT 'normal',
            savings_goal REAL NOT NULL DEFAULT 0,
            goal_visibility TEXT NOT NULL DEFAULT 'month',
            hidden INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            default_account_id INTEGER DEFAULT NULL REFERENCES accounts(id) ON DELETE SET NULL
        )");
        $db->exec('INSERT INTO envelopes_v2_17 (id,name,note,role,currency,visibility,savings_goal,goal_visibility,hidden,sort_order,created_at,updated_at,default_account_id)
            SELECT id,name,note,role,currency,visibility,savings_goal,goal_visibility,hidden,sort_order,created_at,updated_at,default_account_id FROM envelopes ORDER BY id');
        $db->exec('DROP TABLE envelopes');
        $db->exec('ALTER TABLE envelopes_v2_17 RENAME TO envelopes');
        $db->exec('CREATE UNIQUE INDEX idx_envelopes_name_normalized ON envelopes(lower(trim(name)))');
        $db->exec('CREATE INDEX idx_envelopes_default_account ON envelopes(default_account_id)');

        $db->exec("CREATE TABLE entries_v2_17 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            month_id INTEGER NOT NULL REFERENCES months(id) ON DELETE CASCADE,
            envelope_id INTEGER DEFAULT NULL,
            type TEXT NOT NULL DEFAULT 'expense',
            name TEXT NOT NULL,
            category TEXT NOT NULL DEFAULT 'Other',
            amount REAL NOT NULL DEFAULT 0,
            note TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'planned',
            due_date TEXT DEFAULT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_recurring INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            is_bs INTEGER NOT NULL DEFAULT 0,
            bs_payment_account_id INTEGER DEFAULT NULL REFERENCES accounts(id) ON DELETE SET NULL,
            bs_expense_finalized_at TEXT DEFAULT NULL
        )");
        $db->exec('INSERT INTO entries_v2_17 (id,month_id,envelope_id,type,name,category,amount,note,status,due_date,sort_order,is_recurring,created_at,updated_at,is_bs,bs_payment_account_id,bs_expense_finalized_at)
            SELECT id,month_id,envelope_id,type,name,category,amount,note,status,due_date,sort_order,is_recurring,created_at,updated_at,is_bs,bs_payment_account_id,bs_expense_finalized_at FROM entries ORDER BY id');
        $db->exec('DROP TABLE entries');
        $db->exec('ALTER TABLE entries_v2_17 RENAME TO entries');
        $db->exec('CREATE INDEX idx_entries_month_sort ON entries(month_id,sort_order,id)');
        $db->exec('CREATE INDEX idx_entries_envelope ON entries(envelope_id)');
        $db->exec('CREATE INDEX idx_entries_bs_payment_account ON entries(bs_payment_account_id)');

        $db->exec("CREATE TABLE envelope_transfers_v2_17 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_envelope_id INTEGER DEFAULT NULL REFERENCES envelopes(id) ON DELETE SET NULL,
            destination_envelope_id INTEGER DEFAULT NULL REFERENCES envelopes(id) ON DELETE SET NULL,
            source_envelope TEXT NOT NULL,
            destination_envelope TEXT NOT NULL,
            source_account_id INTEGER DEFAULT NULL REFERENCES accounts(id) ON DELETE SET NULL,
            destination_account_id INTEGER DEFAULT NULL REFERENCES accounts(id) ON DELETE SET NULL,
            amount REAL NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            transfer_date TEXT NOT NULL,
            reversed_at TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("INSERT INTO envelope_transfers_v2_17
            (id,source_envelope_id,destination_envelope_id,source_envelope,destination_envelope,source_account_id,destination_account_id,amount,note,transfer_date,reversed_at,created_at,updated_at)
            SELECT t.id,t.source_envelope_id,t.destination_envelope_id,t.source_envelope,t.destination_envelope,
                COALESCE((SELECT a.id FROM accounts a WHERE lower(trim(a.name))=lower(trim(t.source_account)) LIMIT 1),
                         (SELECT e.default_account_id FROM envelopes e WHERE e.id=t.source_envelope_id)),
                COALESCE((SELECT a.id FROM accounts a WHERE lower(trim(a.name))=lower(trim(t.destination_account)) LIMIT 1),
                         (SELECT e.default_account_id FROM envelopes e WHERE e.id=t.destination_envelope_id)),
                t.amount,t.note,t.transfer_date,t.reversed_at,t.created_at,t.updated_at
            FROM envelope_transfers t ORDER BY t.id");
        $db->exec('DROP TABLE envelope_transfers');
        $db->exec('ALTER TABLE envelope_transfers_v2_17 RENAME TO envelope_transfers');
        $db->exec('CREATE INDEX idx_envelope_transfers_date ON envelope_transfers(transfer_date,id)');
        $db->exec('CREATE INDEX idx_envelope_transfers_source_account ON envelope_transfers(source_account_id)');
        $db->exec('CREATE INDEX idx_envelope_transfers_destination_account ON envelope_transfers(destination_account_id)');

        $db->exec('DROP TABLE month_account_transfers');

        $deleteSequence = $db->prepare('DELETE FROM sqlite_sequence WHERE name=:name');
        $restoreSequence = $db->prepare('INSERT INTO sqlite_sequence(name,seq) VALUES (:name,:seq)');
        foreach ($sequence as $table => $value) {
            $deleteSequence->execute([':name' => $table]);
            if ($value !== null) {
                $restoreSequence->bindValue(':name', $table, PDO::PARAM_STR);
                $restoreSequence->bindValue(':seq', $value, PDO::PARAM_INT);
                $restoreSequence->execute();
            }
        }
        $db->exec("DELETE FROM sqlite_sequence WHERE name IN ('accounts_v2_17','envelopes_v2_17','entries_v2_17','envelope_transfers_v2_17')");
        if ($db->query('PRAGMA foreign_key_check')->fetch()) throw new RuntimeException('Database v2.17 foreign-key verification failed');
        if ((string)$db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') throw new RuntimeException('Database v2.17 integrity verification failed');
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        if ($foreignKeysEnabled) $db->exec('PRAGMA foreign_keys = ON');
        throw $error;
    }
    if ($foreignKeysEnabled) $db->exec('PRAGMA foreign_keys = ON');
}

function migrate_to_v2_18(PDO $db): void
{
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS statement_import_profiles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1 CHECK(enabled IN (0,1)),
            evidence_namespace TEXT NOT NULL UNIQUE,
            schema_version INTEGER NOT NULL,
            signature_hash TEXT NOT NULL,
            definition_json TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_statement_import_profiles_name ON statement_import_profiles(name COLLATE NOCASE)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_statement_import_profiles_signature ON statement_import_profiles(enabled, signature_hash)');

        $statementRowColumns = array_column($db->query('PRAGMA table_info(statement_rows)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('classification', $statementRowColumns, true)) {
            $db->exec("ALTER TABLE statement_rows ADD COLUMN classification TEXT DEFAULT NULL CHECK(classification IS NULL OR classification = 'income')");
        }

        if ($db->query('PRAGMA foreign_key_check')->fetch()) throw new RuntimeException('Database v2.18 foreign-key verification failed');
        if ((string)$db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') throw new RuntimeException('Database v2.18 integrity verification failed');
        if ($ownsTransaction) $db->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function migrate_to_v2_19(PDO $db): void
{
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $columns = array_column($db->query('PRAGMA table_info(statement_imports)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('import_profile_id', $columns, true)) {
            $db->exec('ALTER TABLE statement_imports ADD COLUMN import_profile_id INTEGER DEFAULT NULL REFERENCES statement_import_profiles(id) ON DELETE RESTRICT');
        }

        if ($db->query('PRAGMA foreign_key_check')->fetch()) throw new RuntimeException('Database v2.19 foreign-key verification failed');
        if ((string)$db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') throw new RuntimeException('Database v2.19 integrity verification failed');
        if ($ownsTransaction) $db->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function migrate_to_v2_20(PDO $db): void
{
    if ($db->inTransaction()) throw new RuntimeException('Database v2.20 migration requires no active transaction');
    $accountColumns = array_column($db->query('PRAGMA table_info(accounts)')->fetchAll(), 'name');
    $observationTableExists = (bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='account_balance_observations'")->fetchColumn();
    $eventSql = strtolower((string)$db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='account_events'")->fetchColumn());
    if ($observationTableExists && !in_array('ledger_start_date', $accountColumns, true)
        && !str_contains($eventSql, "'opening'") && !str_contains($eventSql, "'reconciliation'")) {
        if ($db->query('PRAGMA foreign_key_check')->fetch()) throw new RuntimeException('Database v2.20 existing-schema foreign-key verification failed');
        return;
    }
    if ($observationTableExists || !in_array('ledger_start_date', $accountColumns, true)) {
        throw new RuntimeException('Database v2.20 found a partially migrated Account schema');
    }

    $foreignKeysEnabled = (int)$db->query('PRAGMA foreign_keys')->fetchColumn() === 1;
    $accountSequence = $db->query("SELECT seq FROM sqlite_sequence WHERE name='accounts'")->fetchColumn();
    $eventSequence = $db->query("SELECT seq FROM sqlite_sequence WHERE name='account_events'")->fetchColumn();
    $accountFields = 'id,name,currency,active,created_at,updated_at,account_number';
    $accountsBefore = $db->query("SELECT $accountFields FROM accounts ORDER BY id")->fetchAll();
    $ordinaryEventsBefore = $db->query("SELECT * FROM account_events WHERE event_type IN ('income','expense','refund','transfer') ORDER BY id")->fetchAll();
    $ordinaryMovementsBefore = $db->query("SELECT am.* FROM account_movements am JOIN account_events ae ON ae.id=am.event_id WHERE ae.event_type IN ('income','expense','refund','transfer') ORDER BY am.id")->fetchAll();

    $accountIds = array_fill_keys(array_map('intval', $db->query('SELECT id FROM accounts')->fetchAll(PDO::FETCH_COLUMN)), true);
    $allEvents = $db->query('SELECT * FROM account_events ORDER BY id')->fetchAll();
    $movementStmt = $db->prepare('SELECT * FROM account_movements WHERE event_id=:event_id ORDER BY sequence,id');
    $legacyEventIds = [];
    $observationDates = [];
    $zeroMovementOpeningContributions = [];
    foreach ($allEvents as $event) {
        $eventId = (int)$event['id'];
        $type = (string)$event['event_type'];
        $date = (string)$event['event_date'];
        $amount = (float)$event['amount'];
        if (!valid_date($date) || !is_finite($amount) || $amount <= 0) {
            throw new RuntimeException("Database v2.20 found an invalid Account event $eventId");
        }
        $movementStmt->execute([':event_id' => $eventId]);
        $movements = $movementStmt->fetchAll();
        $source = $event['source_account_id'] === null ? null : (int)$event['source_account_id'];
        $destination = $event['destination_account_id'] === null ? null : (int)$event['destination_account_id'];
        $transaction = $event['transaction_id'] === null ? null : (int)$event['transaction_id'];
        $valid = false;
        if ($type === 'opening' || $type === 'reconciliation') {
            if ($date > effective_today()) throw new RuntimeException("Database v2.20 cannot migrate a future Account Balance fact on $date");
            $hasExactlyOneSide = ($source === null) !== ($destination === null);
            $accountId = $destination ?? $source;
            $validAccount = $hasExactlyOneSide && $accountId !== null && isset($accountIds[$accountId]);
            $movementCount = count($movements);

            if ($type === 'opening' && $movementCount === 0) {
                $valid = $transaction === null && $validAccount;
                if ($valid) {
                    $zeroMovementOpeningContributions[$accountId][] = [
                        'date' => $date,
                        'amount' => $destination !== null ? $amount : -$amount,
                    ];
                }
            } else {
                $valid = $movementCount === 1
                    && $transaction === null
                    && $validAccount
                    && (int)$movements[0]['account_id'] === $accountId
                    && (int)$movements[0]['sequence'] === 1
                    && (string)$movements[0]['movement_date'] === $date
                    && is_finite((float)$movements[0]['amount'])
                    && abs(abs((float)$movements[0]['amount']) - $amount) < 0.00001;
                if ($valid) {
                    $movementAmount = (float)$movements[0]['amount'];
                    $valid = ($destination !== null && $movementAmount > 0)
                        || ($source !== null && $movementAmount < 0);
                }
            }
            if ($valid) {
                $legacyEventIds[] = $eventId;
                $observationDates[$accountId][$date] = true;
            }
        } elseif ($type === 'income') {
            $valid = count($movements) === 1 && $source === null && $destination !== null && $transaction === null
                && (int)$movements[0]['account_id'] === $destination && (int)$movements[0]['sequence'] === 1
                && (string)$movements[0]['movement_date'] === $date
                && abs((float)$movements[0]['amount'] - $amount) < 0.00001;
        } elseif ($type === 'expense') {
            $valid = count($movements) === 1 && $source !== null && $destination === null
                && (int)$movements[0]['account_id'] === $source && (int)$movements[0]['sequence'] === 1
                && (string)$movements[0]['movement_date'] === $date
                && abs((float)$movements[0]['amount'] + $amount) < 0.00001;
        } elseif ($type === 'refund') {
            $valid = count($movements) === 1 && $source === null && $destination !== null
                && (int)$movements[0]['account_id'] === $destination && (int)$movements[0]['sequence'] === 1
                && (string)$movements[0]['movement_date'] === $date
                && abs((float)$movements[0]['amount'] - $amount) < 0.00001;
        } elseif ($type === 'transfer') {
            $valid = count($movements) === 2 && $source !== null && $destination !== null && $source !== $destination && $transaction === null
                && (int)$movements[0]['account_id'] === $source && (int)$movements[0]['sequence'] === 1
                && (int)$movements[1]['account_id'] === $destination && (int)$movements[1]['sequence'] === 2
                && (string)$movements[0]['movement_date'] === $date && (string)$movements[1]['movement_date'] === $date
                && abs((float)$movements[0]['amount'] + $amount) < 0.00001
                && abs((float)$movements[1]['amount'] - $amount) < 0.00001;
        }
        if (!$valid) throw new RuntimeException("Database v2.20 found malformed Account event $eventId");
    }
    $movementStmt->closeCursor();

    if ($legacyEventIds) {
        $linked = $db->query('SELECT COUNT(*) FROM statement_reconciliations WHERE target_account_event_id IN (' . implode(',', $legacyEventIds) . ')')->fetchColumn();
        if ((int)$linked !== 0) throw new RuntimeException('Database v2.20 cannot remove a statement link to a legacy Account Balance event');
    }

    $legacyBalances = [];
    $balanceStmt = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM account_movements WHERE account_id=:account_id AND movement_date<=:effective_date');
    $legacyPosition = static function (int $accountId, string $date) use ($balanceStmt, $zeroMovementOpeningContributions): float {
        $balanceStmt->execute([':account_id' => $accountId, ':effective_date' => $date]);
        $position = (float)$balanceStmt->fetchColumn();
        foreach ($zeroMovementOpeningContributions[$accountId] ?? [] as $opening) {
            if ($opening['date'] <= $date) $position += $opening['amount'];
        }
        return round($position, 2);
    };
    $datesStmt = $db->prepare('SELECT DISTINCT movement_date FROM account_movements WHERE account_id=:account_id AND movement_date>=:first_date ORDER BY movement_date');
    foreach ($observationDates as $accountId => $dates) {
        $anchorDates = array_keys($dates);
        sort($anchorDates, SORT_STRING);
        $datesStmt->execute([':account_id' => $accountId, ':first_date' => $anchorDates[0]]);
        $verifyDates = array_values(array_unique(array_merge($anchorDates, $datesStmt->fetchAll(PDO::FETCH_COLUMN))));
        sort($verifyDates, SORT_STRING);
        foreach ($verifyDates as $date) {
            $legacyBalances[$accountId][$date] = $legacyPosition((int)$accountId, $date);
        }
    }
    $balanceStmt->closeCursor();
    $datesStmt->closeCursor();

    if ($foreignKeysEnabled) $db->exec('PRAGMA foreign_keys = OFF');
    $db->beginTransaction();
    try {
        $db->exec("CREATE TABLE account_balance_observations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
            effective_date TEXT NOT NULL,
            balance REAL NOT NULL,
            source TEXT NOT NULL CHECK(source IN ('manual','account_creation','migration','statement')),
            note TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(account_id,effective_date)
        )");
        $db->exec('CREATE INDEX idx_account_balance_observations_account_date ON account_balance_observations(account_id,effective_date)');
        $insertObservation = $db->prepare("INSERT INTO account_balance_observations (account_id,effective_date,balance,source,note) VALUES (:account_id,:effective_date,:balance,'migration','Migrated Account Balance')");
        foreach ($observationDates as $accountId => $dates) {
            foreach (array_keys($dates) as $date) {
                $insertObservation->execute([
                    ':account_id' => $accountId,
                    ':effective_date' => $date,
                    ':balance' => $legacyPosition((int)$accountId, $date),
                ]);
            }
        }
        $balanceStmt->closeCursor();
        $insertObservation->closeCursor();

        if ($legacyEventIds) {
            $ids = implode(',', $legacyEventIds);
            $db->exec("DELETE FROM account_movements WHERE event_id IN ($ids)");
            $db->exec("DELETE FROM account_events WHERE id IN ($ids)");
        }

        $db->exec("CREATE TABLE account_events_v2_20 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_type TEXT NOT NULL CHECK(event_type IN ('income','expense','refund','transfer')),
            event_date TEXT NOT NULL,
            amount REAL NOT NULL CHECK(amount > 0),
            source_account_id INTEGER DEFAULT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
            destination_account_id INTEGER DEFAULT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
            transaction_id INTEGER DEFAULT NULL UNIQUE REFERENCES transactions(id) ON DELETE CASCADE,
            note TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec('INSERT INTO account_events_v2_20 (id,event_type,event_date,amount,source_account_id,destination_account_id,transaction_id,note,created_at,updated_at)
            SELECT id,event_type,event_date,amount,source_account_id,destination_account_id,transaction_id,note,created_at,updated_at FROM account_events ORDER BY id');
        $db->exec('DROP TABLE account_events');
        $db->exec('ALTER TABLE account_events_v2_20 RENAME TO account_events');

        $db->exec("CREATE TABLE accounts_v2_20 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            currency TEXT NOT NULL DEFAULT 'CURRENCY' CHECK(currency IN ('CURRENCY','HELPER_CURRENCY')),
            active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            account_number TEXT NOT NULL DEFAULT ''
        )");
        $db->exec("INSERT INTO accounts_v2_20 ($accountFields) SELECT $accountFields FROM accounts ORDER BY id");
        $db->exec('DROP TABLE accounts');
        $db->exec('ALTER TABLE accounts_v2_20 RENAME TO accounts');
        $db->exec('CREATE UNIQUE INDEX idx_accounts_name_normalized ON accounts(lower(trim(name)))');
        $db->exec('CREATE UNIQUE INDEX idx_accounts_account_number ON accounts(account_number)');

        foreach ([['accounts', $accountSequence], ['account_events', $eventSequence]] as [$table, $sequenceBefore]) {
            if ($sequenceBefore === false || $sequenceBefore === null) continue;
            $target = max((int)$sequenceBefore, (int)$db->query("SELECT COALESCE(MAX(id),0) FROM $table")->fetchColumn());
            $update = $db->prepare('UPDATE sqlite_sequence SET seq=:seq WHERE name=:name');
            $update->bindValue(':seq', $target, PDO::PARAM_INT);
            $update->bindValue(':name', $table, PDO::PARAM_STR);
            $update->execute();
            if ($update->rowCount() === 0) {
                $insertSequence = $db->prepare('INSERT INTO sqlite_sequence(name,seq) VALUES (:name,:seq)');
                $insertSequence->bindValue(':name', $table, PDO::PARAM_STR);
                $insertSequence->bindValue(':seq', $target, PDO::PARAM_INT);
                $insertSequence->execute();
            }
        }
        $db->exec("DELETE FROM sqlite_sequence WHERE name IN ('accounts_v2_20','account_events_v2_20')");

        if ($accountsBefore !== $db->query("SELECT $accountFields FROM accounts ORDER BY id")->fetchAll()) {
            throw new RuntimeException('Database v2.20 Account metadata changed during migration');
        }
        if ($ordinaryEventsBefore !== $db->query('SELECT * FROM account_events ORDER BY id')->fetchAll()) {
            throw new RuntimeException('Database v2.20 ordinary Account events changed during migration');
        }
        $ordinaryMovementsAfter = $db->query('SELECT am.* FROM account_movements am JOIN account_events ae ON ae.id=am.event_id ORDER BY am.id')->fetchAll();
        if ($ordinaryMovementsBefore !== $ordinaryMovementsAfter) {
            throw new RuntimeException('Database v2.20 ordinary Account movements changed during migration');
        }
        foreach ($legacyBalances as $accountId => $dates) {
            foreach ($dates as $date => $expected) {
                $observation = $db->prepare('SELECT effective_date,balance FROM account_balance_observations WHERE account_id=:account_id AND effective_date<=:as_of ORDER BY effective_date DESC LIMIT 1');
                $observation->execute([':account_id' => $accountId, ':as_of' => $date]);
                $anchor = $observation->fetch();
                if (!$anchor) throw new RuntimeException("Database v2.20 lost Account $accountId Balance knowledge");
                $movementAfter = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM account_movements WHERE account_id=:account_id AND movement_date>:anchor_date AND movement_date<=:as_of');
                $movementAfter->execute([':account_id' => $accountId, ':anchor_date' => $anchor['effective_date'], ':as_of' => $date]);
                $actual = round((float)$anchor['balance'] + (float)$movementAfter->fetchColumn(), 2);
                if (abs($actual - $expected) > 0.00001) throw new RuntimeException("Database v2.20 changed Account $accountId Balance on $date");
            }
        }
        foreach ($db->query("SELECT id FROM account_events WHERE event_type='transfer'")->fetchAll(PDO::FETCH_COLUMN) as $transferId) {
            $sum = (float)$db->query('SELECT COALESCE(SUM(amount),0) FROM account_movements WHERE event_id=' . (int)$transferId)->fetchColumn();
            if (abs($sum) > 0.00001) throw new RuntimeException("Database v2.20 found unbalanced Transfer $transferId");
        }
        if ($db->query('PRAGMA foreign_key_check')->fetch()) throw new RuntimeException('Database v2.20 foreign-key verification failed');
        if ((string)$db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') throw new RuntimeException('Database v2.20 integrity verification failed');
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        if ($foreignKeysEnabled) $db->exec('PRAGMA foreign_keys = ON');
        throw $error;
    }
    if ($foreignKeysEnabled) $db->exec('PRAGMA foreign_keys = ON');
    if ($db->query('PRAGMA foreign_key_check')->fetch()) throw new RuntimeException('Database v2.20 foreign-key verification failed after commit');
}

function migrate_to_v2_21(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS envelope_balance_observations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        envelope_id INTEGER NOT NULL REFERENCES envelopes(id) ON DELETE CASCADE,
        effective_date TEXT NOT NULL,
        balance REAL NOT NULL,
        source TEXT NOT NULL DEFAULT 'manual' CHECK(source IN ('manual')),
        note TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(envelope_id,effective_date)
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_envelope_balance_observations_envelope_date ON envelope_balance_observations(envelope_id,effective_date)');
}

function migrate_to_v2_22(PDO $db): void
{
    if ($db->inTransaction()) throw new RuntimeException('Database v2.22 migration requires no active transaction');

    $legacyTypes = "'opening_balance','adjustment','reversal'";
    $legacyRows = $db->query("SELECT * FROM envelope_movements WHERE type IN ($legacyTypes) ORDER BY id")->fetchAll();
    $legacyIds = array_fill_keys(array_map(static fn(array $row): int => (int)$row['id'], $legacyRows), true);
    $envelopeIds = array_fill_keys(array_map('intval', $db->query('SELECT id FROM envelopes')->fetchAll(PDO::FETCH_COLUMN)), true);

    foreach ($legacyRows as $row) {
        $movementId = (int)$row['id'];
        $envelopeId = (int)$row['envelope_id'];
        $date = (string)$row['movement_date'];
        $amount = (float)$row['amount'];
        if (!isset($envelopeIds[$envelopeId]) || !valid_date($date) || !is_finite($amount)) {
            throw new RuntimeException("Database v2.22 found malformed legacy Envelope movement $movementId");
        }
        if ($row['transaction_id'] !== null || $row['transfer_id'] !== null || $row['entry_id'] !== null) {
            throw new RuntimeException("Database v2.22 found an unsafe reference on legacy Envelope movement $movementId");
        }
        if ($row['reversal_of_movement_id'] !== null && !isset($legacyIds[(int)$row['reversal_of_movement_id']])) {
            throw new RuntimeException("Database v2.22 found legacy Envelope movement $movementId reversing a retained movement");
        }
    }

    if ($legacyIds) {
        $ids = implode(',', array_keys($legacyIds));
        $retainedReference = $db->query("SELECT id FROM envelope_movements WHERE type NOT IN ($legacyTypes) AND reversal_of_movement_id IN ($ids) ORDER BY id LIMIT 1")->fetchColumn();
        if ($retainedReference !== false) {
            throw new RuntimeException('Database v2.22 found retained Envelope movement ' . (int)$retainedReference . ' referencing a legacy movement');
        }
    }

    $ordinaryMovementsBefore = $db->query("SELECT * FROM envelope_movements WHERE type NOT IN ($legacyTypes) ORDER BY id")->fetchAll();
    $observationsBefore = $db->query('SELECT * FROM envelope_balance_observations ORDER BY id')->fetchAll();
    $movementSequenceBefore = $db->query("SELECT seq FROM sqlite_sequence WHERE name='envelope_movements'")->fetchColumn();

    $db->beginTransaction();
    try {
        $db->exec("DELETE FROM envelope_movements WHERE type IN ($legacyTypes)");

        if ($ordinaryMovementsBefore !== $db->query('SELECT * FROM envelope_movements ORDER BY id')->fetchAll()) {
            throw new RuntimeException('Database v2.22 changed retained Envelope movements');
        }
        if ($observationsBefore !== $db->query('SELECT * FROM envelope_balance_observations ORDER BY id')->fetchAll()) {
            throw new RuntimeException('Database v2.22 changed Envelope Balance observations');
        }
        $movementSequenceAfter = $db->query("SELECT seq FROM sqlite_sequence WHERE name='envelope_movements'")->fetchColumn();
        if ($movementSequenceBefore !== $movementSequenceAfter) {
            throw new RuntimeException('Database v2.22 changed the Envelope movement sequence');
        }
        if ($db->query('PRAGMA foreign_key_check')->fetch()) {
            throw new RuntimeException('Database v2.22 foreign-key verification failed');
        }
        if ((string)$db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('Database v2.22 integrity verification failed');
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    if ($db->query('PRAGMA foreign_key_check')->fetch()) {
        throw new RuntimeException('Database v2.22 foreign-key verification failed after commit');
    }
}

function migrate_to_v2_23(PDO $db): void
{
    if ($db->inTransaction()) throw new RuntimeException('Database v2.23 migration requires no active transaction');

    $columns = array_column($db->query('PRAGMA table_info(envelopes)')->fetchAll(), 'name');
    $envelopesBefore = $db->query('SELECT id, name FROM envelopes ORDER BY id')->fetchAll();

    $db->beginTransaction();
    try {
        if (!in_array('amount_source', $columns, true)) {
            $db->exec("ALTER TABLE envelopes ADD COLUMN amount_source TEXT NOT NULL DEFAULT 'history'");
        }
        if (!in_array('month_end_behavior', $columns, true)) {
            $db->exec("ALTER TABLE envelopes ADD COLUMN month_end_behavior TEXT NOT NULL DEFAULT 'accumulate'");
        }
        // Every existing Envelope keeps the behaviour it has today: a
        // history-based monthly amount and an accumulated balance.
        $db->exec("UPDATE envelopes SET amount_source = 'history' WHERE amount_source IS NULL OR amount_source NOT IN ('history','fixed')");
        $db->exec("UPDATE envelopes SET month_end_behavior = 'accumulate' WHERE month_end_behavior IS NULL OR month_end_behavior NOT IN ('accumulate','reset')");

        if ($envelopesBefore !== $db->query('SELECT id, name FROM envelopes ORDER BY id')->fetchAll()) {
            throw new RuntimeException('Database v2.23 changed existing Envelopes');
        }
        if ($db->query('PRAGMA foreign_key_check')->fetch()) {
            throw new RuntimeException('Database v2.23 foreign-key verification failed');
        }
        if ((string)$db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('Database v2.23 integrity verification failed');
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function migrate_to_v1(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS months (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        year INTEGER NOT NULL,
        month INTEGER NOT NULL,
        exchange_rate_eur_dkk REAL NOT NULL DEFAULT 7.46,
        salary_dkk REAL NOT NULL DEFAULT 0,
        target_savings_percent REAL NOT NULL DEFAULT 0.11,
        notes TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(year, month)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS envelopes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        account TEXT NOT NULL DEFAULT '',
        note TEXT NOT NULL DEFAULT '',
        role TEXT NOT NULL DEFAULT 'daily',
        currency TEXT NOT NULL DEFAULT 'DKK',
        visibility TEXT NOT NULL DEFAULT 'normal',
        savings_goal_dkk REAL NOT NULL DEFAULT 0,
        goal_visibility TEXT NOT NULL DEFAULT 'month',
        hidden INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $columns = array_column($db->query('PRAGMA table_info(envelopes)')->fetchAll(), 'name');
    if (!in_array('account', $columns, true)) $db->exec("ALTER TABLE envelopes ADD COLUMN account TEXT NOT NULL DEFAULT ''");
    $addedRoleColumn = false;
    if (!in_array('role', $columns, true)) {
        $db->exec("ALTER TABLE envelopes ADD COLUMN role TEXT NOT NULL DEFAULT 'daily'");
        $addedRoleColumn = true;
    }
    if (!in_array('savings_goal_dkk', $columns, true)) $db->exec('ALTER TABLE envelopes ADD COLUMN savings_goal_dkk REAL NOT NULL DEFAULT 0');
    if (!in_array('hidden', $columns, true)) $db->exec('ALTER TABLE envelopes ADD COLUMN hidden INTEGER NOT NULL DEFAULT 0');
    if (!in_array('visibility', $columns, true)) {
        $db->exec("ALTER TABLE envelopes ADD COLUMN visibility TEXT NOT NULL DEFAULT 'normal'");
        $db->exec("UPDATE envelopes SET visibility = CASE WHEN hidden = 1 THEN 'hidden' ELSE 'normal' END");
    }
    if ($addedRoleColumn) seed_envelope_roles($db);

    $db->exec("CREATE TABLE IF NOT EXISTS entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        month_id INTEGER NOT NULL REFERENCES months(id) ON DELETE CASCADE,
        envelope_id INTEGER DEFAULT NULL,
        type TEXT NOT NULL DEFAULT 'expense',
        name TEXT NOT NULL,
        category TEXT NOT NULL DEFAULT 'Other',
        amount_dkk REAL NOT NULL DEFAULT 0,
        amount_eur REAL NOT NULL DEFAULT 0,
        currency_original TEXT NOT NULL DEFAULT 'DKK',
        account TEXT NOT NULL DEFAULT '',
        note TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'planned',
        due_date TEXT DEFAULT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_recurring INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $columns = array_column($db->query('PRAGMA table_info(entries)')->fetchAll(), 'name');
    if (!in_array('envelope_id', $columns, true)) $db->exec('ALTER TABLE entries ADD COLUMN envelope_id INTEGER DEFAULT NULL');
    $db->exec("UPDATE entries SET status = 'planned' WHERE status = 'handled'");

    $db->exec('CREATE INDEX IF NOT EXISTS idx_entries_month_sort ON entries(month_id, sort_order, id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_entries_envelope ON entries(envelope_id)');

    $db->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        month_id INTEGER NOT NULL REFERENCES months(id) ON DELETE CASCADE,
        entry_id INTEGER DEFAULT NULL REFERENCES entries(id) ON DELETE SET NULL,
        envelope_id INTEGER DEFAULT NULL,
        envelope TEXT NOT NULL DEFAULT '',
        amount_dkk REAL NOT NULL DEFAULT 0,
        amount_eur REAL NOT NULL DEFAULT 0,
        currency_original TEXT NOT NULL DEFAULT 'DKK',
        note TEXT NOT NULL DEFAULT '',
        spent_date TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $columns = array_column($db->query('PRAGMA table_info(transactions)')->fetchAll(), 'name');
    if (!in_array('envelope_id', $columns, true)) $db->exec('ALTER TABLE transactions ADD COLUMN envelope_id INTEGER DEFAULT NULL');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_transactions_month_date ON transactions(month_id, spent_date, id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_transactions_envelope ON transactions(envelope_id)');

    $db->exec("CREATE TABLE IF NOT EXISTS envelope_movements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        envelope_id INTEGER NOT NULL REFERENCES envelopes(id) ON DELETE CASCADE,
        month_id INTEGER DEFAULT NULL REFERENCES months(id) ON DELETE SET NULL,
        entry_id INTEGER DEFAULT NULL REFERENCES entries(id) ON DELETE SET NULL,
        transaction_id INTEGER DEFAULT NULL REFERENCES transactions(id) ON DELETE CASCADE,
        type TEXT NOT NULL DEFAULT 'adjustment',
        amount_dkk REAL NOT NULL DEFAULT 0,
        note TEXT NOT NULL DEFAULT '',
        movement_date TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_envelope_movements_envelope ON envelope_movements(envelope_id, movement_date, id)');
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_envelope_movements_entry_funding ON envelope_movements(entry_id) WHERE entry_id IS NOT NULL AND type = 'funding'");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_envelope_movements_transaction_expense ON envelope_movements(transaction_id) WHERE transaction_id IS NOT NULL AND type = 'expense'");

    maintain_current_data_model($db);
}

function migrate_to_v1_1(PDO $db): void
{
    $columns = array_column($db->query('PRAGMA table_info(envelopes)')->fetchAll(), 'name');
    if (!in_array('currency', $columns, true)) {
        $db->exec("ALTER TABLE envelopes ADD COLUMN currency TEXT NOT NULL DEFAULT 'DKK'");
    }
    $db->exec("UPDATE envelopes SET currency = COALESCE((
        SELECT CASE WHEN upper(e.currency_original) = 'EUR' THEN 'EUR' ELSE 'DKK' END
        FROM entries e
        JOIN months m ON m.id = e.month_id
        WHERE e.envelope_id = envelopes.id AND e.type <> 'income'
        ORDER BY m.year DESC, m.month DESC, e.id DESC
        LIMIT 1
    ), 'DKK')");
    recalculate_allocation_currencies($db);
}

function migrate_to_v1_2(PDO $db): void
{
    $columns = array_column($db->query('PRAGMA table_info(envelopes)')->fetchAll(), 'name');
    if (!in_array('visibility', $columns, true)) {
        $db->exec("ALTER TABLE envelopes ADD COLUMN visibility TEXT NOT NULL DEFAULT 'normal'");
        $db->exec("UPDATE envelopes SET visibility = CASE WHEN hidden = 1 THEN 'hidden' ELSE 'normal' END");
    }
    $db->exec("UPDATE envelopes SET visibility = 'normal', hidden = 0 WHERE role = 'daily'");
}

function migrate_to_v1_3(PDO $db): void
{
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_envelopes_name_normalized ON envelopes(lower(trim(name)))');
}

function migrate_to_v1_4(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS month_account_transfers (
        month_id INTEGER NOT NULL REFERENCES months(id) ON DELETE CASCADE,
        account TEXT NOT NULL COLLATE NOCASE,
        transferred INTEGER NOT NULL DEFAULT 1,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (month_id, account)
    )");
}

function migrate_to_v1_5(PDO $db): void
{
    $tableExists = (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'month_account_transfers'")->fetchColumn() > 0;
    if (!$tableExists) return;

    $db->beginTransaction();
    try {
        $markers = $db->query('SELECT month_id, account FROM month_account_transfers WHERE transferred = 1')->fetchAll();
        $findEntries = $db->prepare("SELECT e.id FROM entries e JOIN envelopes v ON v.id = e.envelope_id
            WHERE e.month_id = :month_id AND e.type <> 'income' AND e.status <> 'skipped' AND e.amount_dkk > 0
              AND lower(trim(v.account)) = lower(trim(:account))");
        $markFunded = $db->prepare("UPDATE entries SET status = 'paid', updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        foreach ($markers as $marker) {
            $findEntries->execute([':month_id' => (int)$marker['month_id'], ':account' => (string)$marker['account']]);
            foreach ($findEntries->fetchAll(PDO::FETCH_COLUMN) as $entryId) {
                $markFunded->execute([':id' => (int)$entryId]);
                sync_entry_funding_movement($db, (int)$entryId);
            }
        }
        $db->exec('DROP TABLE month_account_transfers');
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function migrate_to_v1_6(PDO $db): void
{
    $entryColumns = array_column($db->query('PRAGMA table_info(entries)')->fetchAll(), 'name');
    if (!in_array('is_bs', $entryColumns, true)) {
        $db->exec("ALTER TABLE entries ADD COLUMN is_bs INTEGER NOT NULL DEFAULT 0");
    }
    $transactionColumns = array_column($db->query('PRAGMA table_info(transactions)')->fetchAll(), 'name');
    if (!in_array('is_automatic', $transactionColumns, true)) {
        $db->exec("ALTER TABLE transactions ADD COLUMN is_automatic INTEGER NOT NULL DEFAULT 0");
    }
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_transactions_entry_automatic ON transactions(entry_id) WHERE entry_id IS NOT NULL AND is_automatic = 1");
}

function migrate_to_v1_7(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS envelope_transfers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_envelope_id INTEGER DEFAULT NULL REFERENCES envelopes(id) ON DELETE SET NULL,
        destination_envelope_id INTEGER DEFAULT NULL REFERENCES envelopes(id) ON DELETE SET NULL,
        source_envelope TEXT NOT NULL,
        destination_envelope TEXT NOT NULL,
        source_account TEXT NOT NULL DEFAULT '',
        destination_account TEXT NOT NULL DEFAULT '',
        amount_dkk REAL NOT NULL,
        note TEXT NOT NULL DEFAULT '',
        transfer_date TEXT NOT NULL,
        reversed_at TEXT DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $transferColumns = array_column($db->query('PRAGMA table_info(envelope_transfers)')->fetchAll(), 'name');
    if (!in_array('reversed_at', $transferColumns, true)) {
        $db->exec('ALTER TABLE envelope_transfers ADD COLUMN reversed_at TEXT DEFAULT NULL');
    }
    $movementColumns = array_column($db->query('PRAGMA table_info(envelope_movements)')->fetchAll(), 'name');
    if (!in_array('transfer_id', $movementColumns, true)) {
        $db->exec('ALTER TABLE envelope_movements ADD COLUMN transfer_id INTEGER DEFAULT NULL');
    }
    if (!in_array('reversal_of_movement_id', $movementColumns, true)) {
        $db->exec('ALTER TABLE envelope_movements ADD COLUMN reversal_of_movement_id INTEGER DEFAULT NULL');
    }
    $db->exec('CREATE INDEX IF NOT EXISTS idx_envelope_movements_transfer ON envelope_movements(transfer_id)');
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_envelope_movements_reversal ON envelope_movements(reversal_of_movement_id) WHERE reversal_of_movement_id IS NOT NULL');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_envelope_transfers_date ON envelope_transfers(transfer_date, id)');
}

function migrate_to_v1_8(PDO $db): void
{
    $columns = array_column($db->query('PRAGMA table_info(envelopes)')->fetchAll(), 'name');
    if (!in_array('goal_visibility', $columns, true)) {
        $db->exec("ALTER TABLE envelopes ADD COLUMN goal_visibility TEXT NOT NULL DEFAULT 'month'");
    }
    $db->exec("UPDATE envelopes SET goal_visibility = 'hidden' WHERE role <> 'savings'");
}

function migrate_to_v1_9(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS month_account_transfers (
        month_id INTEGER NOT NULL REFERENCES months(id) ON DELETE CASCADE,
        account TEXT NOT NULL COLLATE NOCASE,
        transferred INTEGER NOT NULL DEFAULT 1,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (month_id, account)
    )");
    $db->exec("INSERT OR IGNORE INTO month_account_transfers (month_id, account, transferred)
        SELECT e.month_id, trim(v.account), 1
        FROM entries e
        JOIN envelopes v ON v.id = e.envelope_id
        WHERE e.type <> 'income' AND e.amount_dkk > 0 AND e.status <> 'skipped' AND trim(v.account) <> ''
        GROUP BY e.month_id, lower(trim(v.account))
        HAVING SUM(CASE WHEN e.status <> 'paid' THEN 1 ELSE 0 END) = 0");
}

function migrate_to_v2(PDO $db): void
{
    $columns = array_column($db->query('PRAGMA table_info(transactions)')->fetchAll(), 'name');
    if (!in_array('amount_eur', $columns, true)) {
        $db->exec('ALTER TABLE transactions ADD COLUMN amount_eur REAL NOT NULL DEFAULT 0');
    }
    if (!in_array('currency_original', $columns, true)) {
        $db->exec("ALTER TABLE transactions ADD COLUMN currency_original TEXT NOT NULL DEFAULT 'DKK'");
    }
    $db->exec("UPDATE transactions
        SET amount_eur = CASE WHEN amount_eur > 0 THEN amount_eur ELSE ROUND(amount_dkk / COALESCE(NULLIF((SELECT exchange_rate_eur_dkk FROM months WHERE months.id = transactions.month_id), 0), 7.46), 2) END,
            currency_original = CASE WHEN upper(currency_original) = 'EUR' THEN 'EUR' ELSE 'DKK' END");
}

function migrate_to_v2_1(PDO $db): void
{
    $db->beginTransaction();
    try {
        $entries = $db->query("SELECT e.id, e.is_bs, e.due_date, e.updated_at,
                (SELECT substr(t.created_at, 1, 10) FROM transactions t WHERE t.entry_id = e.id AND t.is_automatic = 1 LIMIT 1) AS automatic_created_date
            FROM entries e
            WHERE e.type <> 'income'
            ORDER BY e.id")->fetchAll();
        foreach ($entries as $entry) {
            $fundingDate = null;
            if ((int)$entry['is_bs'] === 1) {
                $candidate = (string)($entry['automatic_created_date'] ?: substr((string)$entry['updated_at'], 0, 10));
                if (valid_date($candidate)) $fundingDate = $candidate;
            }
            migrate_v2_1_sync_entry_funding_movement($db, (int)$entry['id'], $fundingDate);
        }
        foreach ($db->query('SELECT id FROM transactions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $transactionId) {
            migrate_v2_1_sync_transaction_envelope_movement($db, (int)$transactionId);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function migrate_v2_1_sync_entry_funding_movement(PDO $db, int $entryId, ?string $fundingDate): void
{
    $stmt = $db->prepare('SELECT * FROM entries WHERE id=:id');
    $stmt->execute([':id' => $entryId]);
    $entry = $stmt->fetch();
    if (!$entry || (int)($entry['envelope_id'] ?? 0) <= 0 || (string)$entry['status'] !== 'paid' || (string)$entry['type'] === 'income' || (float)$entry['amount_dkk'] <= 0) {
        $db->prepare("DELETE FROM envelope_movements WHERE entry_id=:id AND type='funding'")->execute([':id' => $entryId]);
        return;
    }
    $existing = $db->prepare("SELECT id,movement_date FROM envelope_movements WHERE entry_id=:entry_id AND type='funding'");
    $existing->execute([':entry_id' => $entryId]);
    $movement = $existing->fetch() ?: null;
    $movementDate = $fundingDate
        ?? (valid_date((string)($entry['due_date'] ?? '')) ? (string)$entry['due_date'] : ($movement['movement_date'] ?? effective_today()));
    $params = [
        ':envelope_id' => (int)$entry['envelope_id'], ':month_id' => (int)$entry['month_id'], ':entry_id' => $entryId,
        ':amount_dkk' => (float)$entry['amount_dkk'], ':note' => 'Funded from plan row: ' . (string)$entry['name'], ':movement_date' => $movementDate,
    ];
    $sql = $movement
        ? "UPDATE envelope_movements SET envelope_id=:envelope_id,month_id=:month_id,amount_dkk=:amount_dkk,note=:note,movement_date=:movement_date,updated_at=CURRENT_TIMESTAMP WHERE entry_id=:entry_id AND type='funding'"
        : "INSERT INTO envelope_movements(envelope_id,month_id,entry_id,type,amount_dkk,note,movement_date) VALUES(:envelope_id,:month_id,:entry_id,'funding',:amount_dkk,:note,:movement_date)";
    $db->prepare($sql)->execute($params);
}

function migrate_v2_1_sync_transaction_envelope_movement(PDO $db, int $transactionId): void
{
    $columns = array_column($db->query('PRAGMA table_info(transactions)')->fetchAll(), 'name');
    $kind = in_array('kind', $columns, true) ? 'kind' : "'expense' AS kind";
    $stmt = $db->prepare("SELECT id,$kind,month_id,envelope_id,amount_dkk,note,spent_date FROM transactions WHERE id=:id");
    $stmt->execute([':id' => $transactionId]);
    $transaction = $stmt->fetch();
    if (!$transaction || (int)($transaction['envelope_id'] ?? 0) <= 0) {
        $db->prepare("DELETE FROM envelope_movements WHERE transaction_id=:id AND type IN ('expense','refund')")->execute([':id' => $transactionId]);
        return;
    }
    $type = (string)$transaction['kind'] === 'refund' ? 'refund' : 'expense';
    $params = [
        ':envelope_id' => (int)$transaction['envelope_id'], ':month_id' => $transaction['month_id'] === null ? null : (int)$transaction['month_id'],
        ':transaction_id' => $transactionId, ':type' => $type,
        ':amount_dkk' => $type === 'refund' ? abs((float)$transaction['amount_dkk']) : -abs((float)$transaction['amount_dkk']),
        ':note' => (string)$transaction['note'], ':movement_date' => (string)$transaction['spent_date'],
    ];
    $existing = $db->prepare("SELECT id FROM envelope_movements WHERE transaction_id=:transaction_id AND type IN ('expense','refund')");
    $existing->execute([':transaction_id' => $transactionId]);
    $id = $existing->fetchColumn();
    if ($id !== false) {
        $params[':id'] = (int)$id;
        unset($params[':transaction_id']);
        $sql = 'UPDATE envelope_movements SET type=:type,envelope_id=:envelope_id,month_id=:month_id,amount_dkk=:amount_dkk,note=:note,movement_date=:movement_date,updated_at=CURRENT_TIMESTAMP WHERE id=:id';
    } else {
        $sql = 'INSERT INTO envelope_movements(envelope_id,month_id,transaction_id,type,amount_dkk,note,movement_date) VALUES(:envelope_id,:month_id,:transaction_id,:type,:amount_dkk,:note,:movement_date)';
    }
    $db->prepare($sql)->execute($params);
}

function maintain_current_data_model(PDO $db): void
{
    $transactionColumns = array_column($db->query('PRAGMA table_info(transactions)')->fetchAll(), 'name');
    if (!in_array('kind', $transactionColumns, true)) {
        $db->exec("ALTER TABLE transactions ADD COLUMN kind TEXT NOT NULL DEFAULT 'expense' CHECK(kind IN ('expense','refund'))");
    }
    seed_envelopes_from_existing_entries($db);
    seed_all_entries_as_envelopes($db);
    $db->exec("UPDATE envelopes SET role = 'savings' WHERE role <> 'savings' AND (lower(name) LIKE '%saving%' OR lower(name) LIKE '%savings%' OR lower(name) LIKE '%invest%' OR lower(name) LIKE '%home%')");
    seed_envelope_accounts_from_entries($db);
    seed_movements_from_existing_transactions($db);
    normalize_month_allocations($db);
    remove_legacy_plan_helper_notes($db);
}

function remove_legacy_plan_helper_notes(PDO $db): void
{
    $rows = $db->query("SELECT id, note FROM entries WHERE trim(note) <> ''")->fetchAll();
    $update = $db->prepare('UPDATE entries SET note = :note, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    foreach ($rows as $row) {
        $lines = preg_split('/\R/', (string)$row['note']) ?: [];
        $kept = array_values(array_filter($lines, fn($line) => !preg_match('/^(?:-?\d+(?:\.\d+)?\s+)?[dw]$/i', trim((string)$line))));
        $note = trim(implode("\n", $kept));
        if ($note !== trim((string)$row['note'])) {
            $update->execute([':note' => $note, ':id' => (int)$row['id']]);
        }
    }
}

function normalize_month_allocations(PDO $db): void
{
    $needsNormalization = (int)$db->query("SELECT COUNT(*) FROM entries e JOIN envelopes v ON v.id = e.envelope_id WHERE e.type <> 'income' AND (trim(e.name) <> trim(v.name) OR e.type <> 'expense' OR e.category <> 'Envelope')")->fetchColumn() > 0;
    $hasDuplicates = (int)$db->query("SELECT COUNT(*) FROM (SELECT 1 FROM entries WHERE type <> 'income' AND envelope_id IS NOT NULL GROUP BY month_id, envelope_id HAVING COUNT(*) > 1)")->fetchColumn() > 0;
    if (!$needsNormalization && !$hasDuplicates) return;

    $db->beginTransaction();
    try {
        $mixed = $db->query("SELECT e.envelope_id FROM entries e WHERE e.type <> 'income' AND e.envelope_id IS NOT NULL GROUP BY e.envelope_id HAVING COUNT(DISTINCT lower(trim(e.name))) > 1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($mixed as $envelopeIdValue) {
            $envelopeId = (int)$envelopeIdValue;
            $stmt = $db->prepare("SELECT lower(trim(name)) AS name_key, trim(name) AS name, MIN(id) AS first_id, COUNT(*) AS row_count FROM entries WHERE type <> 'income' AND envelope_id = :envelope_id GROUP BY lower(trim(name)) ORDER BY first_id");
            $stmt->execute([':envelope_id' => $envelopeId]);
            $names = $stmt->fetchAll();
            if (count($names) <= 1) continue;
            array_shift($names);

            $sourceStmt = $db->prepare('SELECT * FROM envelopes WHERE id = :id');
            $sourceStmt->execute([':id' => $envelopeId]);
            $source = $sourceStmt->fetch();
            if (!$source) continue;

            foreach ($names as $nameRow) {
                $name = trim((string)$nameRow['name']);
                if ($name === '') continue;
                $find = $db->prepare('SELECT id FROM envelopes WHERE lower(name) = lower(:name) LIMIT 1');
                $find->execute([':name' => $name]);
                $targetId = (int)$find->fetchColumn();
                if ($targetId <= 0) {
                    $insert = $db->prepare('INSERT INTO envelopes (name, account, note, role, visibility, savings_goal_dkk, goal_visibility, hidden, sort_order) VALUES (:name, :account, :note, :role, :visibility, :goal, :goal_visibility, :hidden, :sort_order)');
                    $insert->execute([
                        ':name' => $name,
                        ':account' => (string)$source['account'],
                        ':note' => 'Created from historical plan purpose previously grouped under ' . (string)$source['name'],
                        ':role' => (string)$source['role'],
                        ':visibility' => (string)($source['visibility'] ?? 'normal'),
                        ':goal' => (float)$source['savings_goal_dkk'],
                        ':goal_visibility' => (string)($source['goal_visibility'] ?? 'month'),
                        ':hidden' => (int)$source['hidden'],
                        ':sort_order' => (int)$source['sort_order'] + (int)$nameRow['first_id'],
                    ]);
                    $targetId = (int)$db->lastInsertId();
                }
                if ($targetId === $envelopeId) continue;
                $idsStmt = $db->prepare("SELECT id FROM entries WHERE type <> 'income' AND envelope_id = :envelope_id AND lower(trim(name)) = :name_key");
                $idsStmt->execute([':envelope_id' => $envelopeId, ':name_key' => (string)$nameRow['name_key']]);
                $entryIds = array_map('intval', $idsStmt->fetchAll(PDO::FETCH_COLUMN));
                if (!$entryIds) continue;
                $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
                $db->prepare("UPDATE entries SET envelope_id = ? WHERE id IN ($placeholders)")->execute(array_merge([$targetId], $entryIds));
                $db->prepare("UPDATE envelope_movements SET envelope_id = ? WHERE entry_id IN ($placeholders)")->execute(array_merge([$targetId], $entryIds));
            }
        }

        $duplicateGroups = $db->query("SELECT month_id, envelope_id FROM entries WHERE type <> 'income' AND envelope_id IS NOT NULL GROUP BY month_id, envelope_id HAVING COUNT(*) > 1")->fetchAll();
        foreach ($duplicateGroups as $group) {
            $stmt = $db->prepare("SELECT e.*, v.name AS envelope_name, v.account AS envelope_account FROM entries e JOIN envelopes v ON v.id = e.envelope_id WHERE e.month_id = :month_id AND e.envelope_id = :envelope_id AND e.type <> 'income' ORDER BY e.id");
            $stmt->execute([':month_id' => (int)$group['month_id'], ':envelope_id' => (int)$group['envelope_id']]);
            $rows = $stmt->fetchAll();
            if (count($rows) <= 1) continue;

            $keeper = $rows[0];
            $ids = array_map(fn($row) => (int)$row['id'], $rows);
            $amountDkk = array_sum(array_map(fn($row) => (float)$row['amount_dkk'], $rows));
            $amountEur = array_sum(array_map(fn($row) => (float)$row['amount_eur'], $rows));
            $paidDkk = array_sum(array_map(fn($row) => (string)$row['status'] === 'paid' ? (float)$row['amount_dkk'] : 0.0, $rows));
            $statuses = array_unique(array_map(fn($row) => (string)$row['status'], $rows));
            $status = count($statuses) === 1 ? (string)$statuses[0] : 'planned';
            $dates = array_values(array_filter(array_map(fn($row) => trim((string)($row['due_date'] ?? '')), $rows)));
            sort($dates);
            $details = array_map(function ($row): string {
                $parts = [(string)$row['name'], number_format((float)$row['amount_dkk'], 2, '.', '') . ' DKK'];
                if (!empty($row['due_date'])) $parts[] = 'date ' . (string)$row['due_date'];
                if (trim((string)$row['note']) !== '') $parts[] = trim((string)$row['note']);
                return implode(' · ', $parts);
            }, $rows);
            $existingNotes = array_values(array_unique(array_filter(array_map(fn($row) => trim((string)$row['note']), $rows))));
            $noteParts = $existingNotes;
            $noteParts[] = 'Merged historical allocations: ' . implode('; ', $details);
            $note = implode("\n", array_values(array_unique($noteParts)));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("DELETE FROM envelope_movements WHERE entry_id IN ($placeholders) AND type = 'funding'")->execute($ids);
            $db->prepare("UPDATE transactions SET entry_id = ? WHERE entry_id IN ($placeholders)")->execute(array_merge([(int)$keeper['id']], $ids));
            $removeIds = array_slice($ids, 1);
            if ($removeIds) {
                $removePlaceholders = implode(',', array_fill(0, count($removeIds), '?'));
                $db->prepare("DELETE FROM entries WHERE id IN ($removePlaceholders)")->execute($removeIds);
            }
            $update = $db->prepare("UPDATE entries SET name=:name, category='Envelope', type='expense', amount_dkk=:amount_dkk, amount_eur=:amount_eur, currency_original='DKK', account=:account, note=:note, status=:status, due_date=:due_date, updated_at=CURRENT_TIMESTAMP WHERE id=:id");
            $update->execute([
                ':name' => (string)$keeper['envelope_name'],
                ':amount_dkk' => $amountDkk,
                ':amount_eur' => $amountEur,
                ':account' => (string)$keeper['envelope_account'],
                ':note' => $note,
                ':status' => $status,
                ':due_date' => $dates[0] ?? null,
                ':id' => (int)$keeper['id'],
            ]);
            sync_entry_funding_movement($db, (int)$keeper['id']);
            if ($paidDkk > 0 && $status !== 'paid') {
                $movementDate = $dates[0] ?? date('Y-m-d');
                $partial = $db->prepare("INSERT INTO envelope_movements (envelope_id, month_id, type, amount_dkk, note, movement_date) VALUES (:envelope_id, :month_id, 'funding', :amount_dkk, :note, :movement_date)");
                $partial->execute([
                    ':envelope_id' => (int)$group['envelope_id'],
                    ':month_id' => (int)$group['month_id'],
                    ':amount_dkk' => $paidDkk,
                    ':note' => 'Partial historical funding preserved while allocations were combined',
                    ':movement_date' => $movementDate,
                ]);
            }
        }

        $rows = $db->query("SELECT e.id, e.name, e.note, e.envelope_id, v.name AS envelope_name, v.account AS envelope_account FROM entries e JOIN envelopes v ON v.id = e.envelope_id WHERE e.type <> 'income'")->fetchAll();
        $update = $db->prepare("UPDATE entries SET name=:name, category='Envelope', type='expense', account=:account, note=:note, updated_at=CURRENT_TIMESTAMP WHERE id=:id");
        foreach ($rows as $row) {
            $oldName = trim((string)$row['name']);
            $envelopeName = (string)$row['envelope_name'];
            $note = trim((string)$row['note']);
            if ($oldName !== '' && strcasecmp($oldName, $envelopeName) !== 0 && !str_contains($note, 'Imported name: ' . $oldName)) {
                $note = trim($note . "\nImported name: " . $oldName);
            }
            $update->execute([
                ':name' => $envelopeName,
                ':account' => (string)$row['envelope_account'],
                ':note' => $note,
                ':id' => (int)$row['id'],
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    $currencyMigrationApplied = (int)$db->query("SELECT COUNT(*) FROM schema_migrations WHERE version = '1.1'")->fetchColumn() > 0;
    if ($currencyMigrationApplied) recalculate_allocation_currencies($db);
}

function recalculate_allocation_currencies(PDO $db, ?int $monthId = null, ?int $envelopeId = null): void
{
    $columns = array_column($db->query('PRAGMA table_info(envelopes)')->fetchAll(), 'name');
    if (!in_array('currency', $columns, true)) return;

    $where = ["e.type <> 'income'"];
    $params = [];
    if ($monthId !== null) {
        $where[] = 'e.month_id = :month_id';
        $params[':month_id'] = $monthId;
    }
    if ($envelopeId !== null) {
        $where[] = 'e.envelope_id = :envelope_id';
        $params[':envelope_id'] = $envelopeId;
    }
    $stmt = $db->prepare('SELECT e.id, e.amount_dkk, e.amount_eur, v.currency, m.exchange_rate_eur_dkk
        FROM entries e
        JOIN envelopes v ON v.id = e.envelope_id
        JOIN months m ON m.id = e.month_id
        WHERE ' . implode(' AND ', $where));
    $stmt->execute($params);
    $update = $db->prepare('UPDATE entries SET amount_dkk = :amount_dkk, amount_eur = :amount_eur, currency_original = :currency, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    foreach ($stmt->fetchAll() as $row) {
        $rate = (float)$row['exchange_rate_eur_dkk'] ?: 7.46;
        $currency = strtoupper((string)$row['currency']) === 'EUR' ? 'EUR' : 'DKK';
        if ($currency === 'EUR') {
            $amountEur = (float)$row['amount_eur'];
            if ($amountEur == 0.0 && (float)$row['amount_dkk'] != 0.0) $amountEur = (float)$row['amount_dkk'] / $rate;
            $amountDkk = $amountEur * $rate;
        } else {
            $amountDkk = (float)$row['amount_dkk'];
            $amountEur = $amountDkk / $rate;
        }
        $update->execute([
            ':amount_dkk' => round($amountDkk, 2),
            ':amount_eur' => round($amountEur, 2),
            ':currency' => $currency,
            ':id' => (int)$row['id'],
        ]);
        sync_entry_funding_movement($db, (int)$row['id']);
        sync_bs_automatic_transaction($db, (int)$row['id']);
    }
}

function seed_envelopes_from_existing_entries(PDO $db): void
{
    $rows = $db->query("SELECT id, name, category FROM entries WHERE envelope_id IS NULL AND type = 'expense' ORDER BY sort_order, id")->fetchAll();
    foreach ($rows as $row) {
        $label = envelope_label_from_entry((string)$row['name'], (string)$row['category']);
        if ($label === null) continue;
        $stmt = $db->prepare('INSERT OR IGNORE INTO envelopes (name, sort_order, role) VALUES (:name, :sort_order, :role)');
        $stmt->execute([':name' => $label, ':sort_order' => (int)$row['id'], ':role' => default_envelope_role($label)]);
        $idStmt = $db->prepare('SELECT id FROM envelopes WHERE name = :name');
        $idStmt->execute([':name' => $label]);
        $envelopeId = (int)$idStmt->fetchColumn();
        if ($envelopeId > 0) {
            $update = $db->prepare('UPDATE entries SET envelope_id = :envelope_id, category = :category WHERE id = :id');
            $update->execute([':envelope_id' => $envelopeId, ':category' => 'Envelope', ':id' => (int)$row['id']]);
        }
    }
}

function seed_all_entries_as_envelopes(PDO $db): void
{
    $entryColumns = array_column($db->query('PRAGMA table_info(entries)')->fetchAll(), 'name');
    $envelopeColumns = array_column($db->query('PRAGMA table_info(envelopes)')->fetchAll(), 'name');
    $hasLegacyAccount = in_array('account', $entryColumns, true) && in_array('account', $envelopeColumns, true);
    $accountColumn = $hasLegacyAccount ? 'account' : "'' AS account";
    $rows = $db->query("SELECT id, name, category, $accountColumn, sort_order FROM entries WHERE envelope_id IS NULL AND type <> 'income' ORDER BY sort_order, id")->fetchAll();
    foreach ($rows as $row) {
        $label = envelope_label_from_entry((string)$row['name'], (string)$row['category']) ?? trim((string)$row['name']);
        if ($label === '') continue;
        $role = default_envelope_role_from_entry((string)$row['name'], (string)$row['category']);
        if ($hasLegacyAccount) {
            $stmt = $db->prepare('INSERT OR IGNORE INTO envelopes (name, account, sort_order, role) VALUES (:name, :account, :sort_order, :role)');
            $stmt->execute([':name' => $label, ':account' => trim((string)$row['account']), ':sort_order' => (int)$row['sort_order'], ':role' => $role]);
        } else {
            $stmt = $db->prepare('INSERT OR IGNORE INTO envelopes (name, sort_order, role) VALUES (:name, :sort_order, :role)');
            $stmt->execute([':name' => $label, ':sort_order' => (int)$row['sort_order'], ':role' => $role]);
        }
        $idStmt = $db->prepare('SELECT id FROM envelopes WHERE lower(name) = lower(:name) LIMIT 1');
        $idStmt->execute([':name' => $label]);
        $envelopeId = (int)$idStmt->fetchColumn();
        if ($envelopeId > 0) {
            $db->prepare('UPDATE entries SET envelope_id = :envelope_id WHERE id = :id')->execute([':envelope_id' => $envelopeId, ':id' => (int)$row['id']]);
        }
    }
}

function seed_movements_from_existing_transactions(PDO $db): void
{
    synchronize_all_transaction_envelope_movements($db);
}

function seed_envelope_accounts_from_entries(PDO $db): void
{
    $entryColumns = array_column($db->query('PRAGMA table_info(entries)')->fetchAll(), 'name');
    $envelopeColumns = array_column($db->query('PRAGMA table_info(envelopes)')->fetchAll(), 'name');
    if (!in_array('account', $entryColumns, true) || !in_array('account', $envelopeColumns, true)) return;
    $rows = $db->query("SELECT e.envelope_id, e.account FROM entries e JOIN envelopes v ON v.id = e.envelope_id WHERE e.envelope_id IS NOT NULL AND e.account <> '' AND v.account = '' GROUP BY e.envelope_id")->fetchAll();
    foreach ($rows as $row) {
        $db->prepare("UPDATE envelopes SET account = :account WHERE id = :id AND account = ''")->execute([':account' => (string)$row['account'], ':id' => (int)$row['envelope_id']]);
    }
}

function seed_envelope_roles(PDO $db): void
{
    foreach ($db->query('SELECT id, name FROM envelopes')->fetchAll() as $row) {
        $db->prepare('UPDATE envelopes SET role = :role WHERE id = :id')->execute([
            ':role' => default_envelope_role((string)$row['name']),
            ':id' => (int)$row['id'],
        ]);
    }
}

function default_envelope_role(string $name): string
{
    $s = strtolower($name);
    if (str_contains($s, 'saving') || str_contains($s, 'savings') || str_contains($s, 'invest') || str_contains($s, 'home')) return 'savings';
    if (str_contains($s, 'food') || str_contains($s, 'fun') || str_contains($s, 'travel') || str_contains($s, 'clothing') || str_contains($s, 'barber')) return 'daily';
    return 'reserved';
}

function default_envelope_role_from_entry(string $name, string $category): string
{
    $s = strtolower($name . ' ' . $category);
    if (str_contains($s, 'saving') || str_contains($s, 'savings') || str_contains($s, 'invest') || str_contains($s, 'home')) return 'savings';
    return default_envelope_role($name);
}

function envelope_label_from_entry(string $name, string $category): ?string
{
    $s = strtolower($name . ' ' . $category);
    if (str_contains($s, 'food')) return 'Food';
    if (str_contains($s, 'fuel') || str_contains($s, 'transport')) return 'Fuel';
    if (str_contains($s, 'fun') || str_contains($s, 'travel')) return 'Fun & Travel';
    if (str_contains($s, 'clothing') || str_contains($s, 'barber')) return 'Clothing & Barber';
    if (str_contains($s, 'subscr')) return 'Subscriptions';
    if (str_contains(strtolower($category), 'envelope')) return trim($name) ?: null;
    return null;
}

function is_fin_authenticated(): bool
{
    return ($_SESSION['fin_authenticated'] ?? false) === true;
}

/**
 * The Authorization header of the current request, if the web server exposes
 * one. It is only ever read for the allow-listed read and write actions;
 * everywhere else the header is ignored.
 */
function request_authorization_header(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    if (is_string($header) && trim($header) !== '') return $header;
    foreach (['apache_request_headers', 'getallheaders'] as $reader) {
        if (!function_exists($reader)) continue;
        foreach ((array)$reader() as $name => $value) {
            if (strcasecmp((string)$name, 'Authorization') === 0) return (string)$value;
        }
    }
    return null;
}

function require_auth(PDO $db): void
{
    if (!is_fin_authenticated()) fail('Authentication required', 401);
}

function effective_today(): string
{
    $override = trim((string)(getenv('FIN_TODAY') ?: ''));
    return valid_date($override) ? $override : date('Y-m-d');
}

// Stored exchange_rate of the Month containing effective_today(), or
// null if no Month exists for today. Unlike exchange_rate_for_date this
// does NOT fall back to the ECB cache; it returns exactly the stored
// value, including null, so the Plan summary can decide to render
// "Conversion unavailable" when no Month is configured for today.
// This is the same rate display_balance uses (via amount_from_currency
// → exchange_rate_for_date's primary branch), and it is the rate the
// Plan summary Balance cell converts the Envelope balance sum at
// (FIN iteration 4).
function current_stored_exchange_rate(PDO $db): ?float
{
    $today = effective_today();
    $stmt = $db->prepare("SELECT exchange_rate FROM months WHERE year=CAST(substr(:today,1,4) AS INTEGER) AND month=CAST(substr(:today,6,2) AS INTEGER) LIMIT 1");
    $stmt->execute([':today' => $today]);
    $row = $stmt->fetch();
    if (!$row) return null;
    return $row['exchange_rate'] === null ? null : (float)$row['exchange_rate'];
}

function envelope_balances_as_of(PDO $db, string $asOf): array
{
    if (!valid_date($asOf)) throw new InvalidArgumentException('Balance cutoff must be YYYY-MM-DD');
    $stmt = $db->prepare("SELECT e.id AS envelope_id,o.id AS observation_id,o.effective_date,o.balance AS observed_balance,
            COALESCE(SUM(CASE WHEN o.id IS NULL OR m.movement_date>o.effective_date THEN m.amount ELSE 0 END),0) AS movement_sum
        FROM envelopes e
        LEFT JOIN envelope_balance_observations o ON o.id=(
            SELECT latest.id FROM envelope_balance_observations latest
            WHERE latest.envelope_id=e.id AND latest.effective_date<=:observation_as_of
            ORDER BY latest.effective_date DESC LIMIT 1
        )
        LEFT JOIN envelope_movements m ON m.envelope_id=e.id AND m.movement_date<=:movement_as_of
        GROUP BY e.id,o.id,o.effective_date,o.balance
        ORDER BY e.id");
    $stmt->execute([':observation_as_of' => $asOf, ':movement_as_of' => $asOf]);
    $balances = [];
    foreach ($stmt->fetchAll() as $row) {
        $movementSum = (float)$row['movement_sum'];
        $balances[(int)$row['envelope_id']] = $row['observation_id'] === null
            ? $movementSum
            : round((float)$row['observed_balance'] + $movementSum, 2);
    }
    return $balances;
}

function envelope_balance_as_of(PDO $db, int $envelopeId, string $asOf): float
{
    return (float)(envelope_balances_as_of($db, $asOf)[$envelopeId] ?? 0.0);
}

function record_envelope_balance_observation(PDO $db, int $envelopeId, float $balance, string $date, string $note = '', string $source = 'manual'): int
{
    if ($envelopeId <= 0) throw new InvalidArgumentException('Envelope is required');
    if (!valid_date($date)) throw new InvalidArgumentException('Balance date must be YYYY-MM-DD');
    if ($date > effective_today()) throw new InvalidArgumentException('Balance date cannot be in the future');
    if (!is_finite($balance)) throw new InvalidArgumentException('Envelope Balance must be a number');
    if ($source !== 'manual') throw new InvalidArgumentException('Unknown Envelope Balance source');
    $envelope = $db->prepare('SELECT id FROM envelopes WHERE id=:id');
    $envelope->execute([':id' => $envelopeId]);
    if (!$envelope->fetchColumn()) throw new InvalidArgumentException('Envelope not found');
    $storedBalance = abs($balance) < 0.005 ? 0.0 : round($balance, 2);
    $stmt = $db->prepare("INSERT INTO envelope_balance_observations (envelope_id,effective_date,balance,source,note)
        VALUES (:envelope_id,:effective_date,:balance,:source,:note)
        ON CONFLICT(envelope_id,effective_date) DO UPDATE SET
            balance=excluded.balance,
            source=excluded.source,
            note=excluded.note,
            updated_at=STRFTIME('%Y-%m-%d %H:%M:%f','now')");
    $stmt->execute([
        ':envelope_id' => $envelopeId,
        ':effective_date' => $date,
        ':balance' => $storedBalance,
        ':source' => $source,
        ':note' => trim($note),
    ]);
    $lookup = $db->prepare('SELECT id FROM envelope_balance_observations WHERE envelope_id=:envelope_id AND effective_date=:effective_date');
    $lookup->execute([':envelope_id' => $envelopeId, ':effective_date' => $date]);
    return (int)$lookup->fetchColumn();
}

function delete_envelope_balance_observation(PDO $db, int $envelopeId, int $observationId): void
{
    if ($envelopeId <= 0) throw new InvalidArgumentException('Envelope is required');
    if ($observationId <= 0) throw new InvalidArgumentException('Balance observation is required');
    $stmt = $db->prepare('DELETE FROM envelope_balance_observations WHERE id=:id AND envelope_id=:envelope_id');
    $stmt->execute([':id' => $observationId, ':envelope_id' => $envelopeId]);
    if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('Balance observation not found');
}

function get_state(PDO $db, ?int $year = null): array
{
    if ($year === null || $year <= 0) {
        $year = (int)($db->query('SELECT COALESCE(MIN(year), CAST(strftime("%Y", "now") AS INTEGER)) FROM months')->fetchColumn());
    }
    if ($year === (int)date('Y')) ensure_current_month_exists($db);
    $months = $db->prepare('SELECT * FROM months WHERE year = :year ORDER BY month');
    $months->execute([':year' => $year]);
    $monthRows = $months->fetchAll();
    $ids = array_map(fn($m) => (int)$m['id'], $monthRows);
    $entries = [];
    $transactions = [];
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT * FROM entries WHERE month_id IN ($placeholders) ORDER BY month_id, sort_order, id");
        $stmt->execute($ids);
        $entries = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT t.*, m.year AS source_year, m.month AS source_month FROM transactions t LEFT JOIN months m ON m.id = t.month_id WHERE t.month_id IN ($placeholders) OR substr(t.spent_date, 1, 4) = ? ORDER BY t.spent_date DESC, t.id DESC");
        $stmt->execute(array_merge($ids, [sprintf('%04d', $year)]));
        $transactions = $stmt->fetchAll();

    }
    $movements = $db->query("SELECT m.*, v.name AS envelope_name,
            t.source_envelope_id, t.destination_envelope_id, t.source_envelope, t.destination_envelope,
            t.source_account_id, t.destination_account_id,
            source_account.name AS source_account_name, destination_account.name AS destination_account_name,
            t.reversed_at AS transfer_reversed_at
        FROM envelope_movements m
        JOIN envelopes v ON v.id = m.envelope_id
        LEFT JOIN envelope_transfers t ON t.id = m.transfer_id
        LEFT JOIN accounts source_account ON source_account.id=t.source_account_id
        LEFT JOIN accounts destination_account ON destination_account.id=t.destination_account_id
        ORDER BY m.movement_date DESC, m.id DESC")->fetchAll();
    $envelopeBalanceObservations = $db->query('SELECT * FROM envelope_balance_observations ORDER BY effective_date DESC,id DESC')->fetchAll();
    $years = $db->query('SELECT DISTINCT year FROM months ORDER BY year')->fetchAll(PDO::FETCH_COLUMN);
    $reportMonths = $db->query('SELECT id, year, month FROM months ORDER BY year, month')->fetchAll();
    return [
        'year' => $year,
        'years' => array_map('intval', $years),
        'months' => array_map('normalize_month', $monthRows),
        'entries' => array_map('normalize_entry', $entries),
        'transactions' => array_map('normalize_transaction', $transactions),
        'movements' => array_map('normalize_movement', $movements),
        'envelope_balance_observations' => array_map('normalize_envelope_balance_observation', $envelopeBalanceObservations),
        'envelopes' => get_envelopes($db),
        'accounts' => get_accounts($db),
        'report_months' => array_map(fn($row) => ['id' => (int)$row['id'], 'year' => (int)$row['year'], 'month' => (int)$row['month']], $reportMonths),
        'app_version' => app_version(),
        'database_version' => database_version($db),
        'currency' => currency_context($db)['currency'],
        'helper_currency' => currency_context($db)['helper_currency'],
        'current_exchange_rate' => current_stored_exchange_rate($db),
    ];
}

function ensure_current_month_exists(PDO $db): void
{
    $stmt = $db->prepare('INSERT OR IGNORE INTO months (year, month) VALUES (:year, :month)');
    $stmt->execute([':year' => (int)date('Y'), ':month' => (int)date('n')]);
}

function get_envelopes(PDO $db, ?string $asOf = null): array
{
    $asOf = $asOf ?: effective_today();
    $balances = envelope_balances_as_of($db, $asOf);
    $stmt = $db->prepare("SELECT v.id, v.name, v.default_account_id, default_account.name AS default_account_name,
            v.note, v.role, v.currency, v.visibility, v.savings_goal, v.goal_visibility, v.hidden, v.sort_order,
            v.amount_source, v.month_end_behavior,
            rs.manual_baseline, rs.manual_baseline_note,
            (SELECT COUNT(*) FROM transactions t WHERE t.spent_date <= :as_of AND COALESCE(t.kind, 'expense') = 'expense' AND (t.envelope_id = v.id OR (t.envelope_id IS NULL AND lower(t.envelope) = lower(v.name)))) AS usage_count
        FROM envelopes v
        LEFT JOIN accounts default_account ON default_account.id=v.default_account_id
        LEFT JOIN envelope_recommendation_settings rs ON rs.envelope_id = v.id
        ORDER BY CASE v.visibility WHEN 'hidden' THEN 1 ELSE 0 END, v.sort_order, v.name");
    $stmt->execute([':as_of' => $asOf]);
    return array_map(fn($row) => [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'default_account_id' => $row['default_account_id'] === null ? null : (int)$row['default_account_id'],
        'default_account_name' => (string)($row['default_account_name'] ?? ''),
        'note' => (string)$row['note'],
        'role' => in_array((string)$row['role'], ['daily', 'reserved', 'savings'], true) ? (string)$row['role'] : 'daily',
        'currency' => currency_assert_preference((string)$row['currency']),
        'visibility' => (string)$row['role'] === 'daily' ? 'normal' : (in_array((string)$row['visibility'], ['normal', 'hidden', 'today'], true) ? (string)$row['visibility'] : 'normal'),
        'savings_goal' => (float)$row['savings_goal'],
        'goal_visibility' => (string)$row['role'] === 'savings' && in_array((string)$row['goal_visibility'], ['hidden', 'month', 'today'], true) ? (string)$row['goal_visibility'] : 'hidden',
        'amount_source' => in_array((string)$row['amount_source'], ['history', 'fixed'], true) ? (string)$row['amount_source'] : 'history',
        'month_end_behavior' => in_array((string)$row['month_end_behavior'], ['accumulate', 'reset'], true) ? (string)$row['month_end_behavior'] : 'accumulate',
        'manual_baseline' => $row['manual_baseline'] === null ? null : (float)$row['manual_baseline'],
        'manual_baseline_note' => (string)($row['manual_baseline_note'] ?? ''),
        'balance' => (float)($balances[(int)$row['id']] ?? 0.0),
        'usage_count' => (int)$row['usage_count'],
        'hidden' => (int)$row['hidden'],
        'sort_order' => (int)$row['sort_order'],
    ], $stmt->fetchAll());
}

function normalize_month(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'year' => (int)$row['year'],
        'month' => (int)$row['month'],
        'exchange_rate' => $row['exchange_rate'] === null ? null : (float)$row['exchange_rate'],
        'salary' => (float)$row['salary'],
        'notes' => (string)$row['notes'],
    ];
}

function normalize_entry(array $row): array
{
    $entry = [
        'id' => (int)$row['id'],
        'month_id' => (int)$row['month_id'],
        'envelope_id' => $row['envelope_id'] === null ? null : (int)$row['envelope_id'],
        'type' => (string)$row['type'],
        'name' => (string)$row['name'],
        'category' => (string)$row['category'],
        'amount' => (float)$row['amount'],
        'note' => (string)$row['note'],
        'status' => (string)$row['status'],
        'due_date' => $row['due_date'],
        'sort_order' => (int)$row['sort_order'],
        'is_recurring' => (int)$row['is_recurring'],
        'is_bs' => (int)($row['is_bs'] ?? 0),
        'bs_payment_account_id' => ($row['bs_payment_account_id'] ?? null) === null ? null : (int)$row['bs_payment_account_id'],
        'bs_expense_finalized_at' => $row['bs_expense_finalized_at'] ?? null,
    ];
    if (array_key_exists('account_name', $row)) $entry['account_name'] = (string)($row['account_name'] ?? '');
    return $entry;
}

function normalize_movement(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'envelope_id' => (int)$row['envelope_id'],
        'envelope_name' => (string)$row['envelope_name'],
        'month_id' => $row['month_id'] === null ? null : (int)$row['month_id'],
        'entry_id' => $row['entry_id'] === null ? null : (int)$row['entry_id'],
        'transaction_id' => $row['transaction_id'] === null ? null : (int)$row['transaction_id'],
        'transfer_id' => $row['transfer_id'] === null ? null : (int)$row['transfer_id'],
        'reversal_of_movement_id' => $row['reversal_of_movement_id'] === null ? null : (int)$row['reversal_of_movement_id'],
        'type' => (string)$row['type'],
        'amount' => (float)$row['amount'],
        'note' => (string)$row['note'],
        'movement_date' => (string)$row['movement_date'],
        'source_envelope_id' => $row['source_envelope_id'] === null ? null : (int)$row['source_envelope_id'],
        'destination_envelope_id' => $row['destination_envelope_id'] === null ? null : (int)$row['destination_envelope_id'],
        'source_envelope' => (string)($row['source_envelope'] ?? ''),
        'destination_envelope' => (string)($row['destination_envelope'] ?? ''),
        'source_account_id' => $row['source_account_id'] === null ? null : (int)$row['source_account_id'],
        'destination_account_id' => $row['destination_account_id'] === null ? null : (int)$row['destination_account_id'],
        'source_account_name' => (string)($row['source_account_name'] ?? ''),
        'destination_account_name' => (string)($row['destination_account_name'] ?? ''),
        'transfer_reversed_at' => $row['transfer_reversed_at'] ?? null,
    ];
}

function normalize_envelope_balance_observation(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'envelope_id' => (int)$row['envelope_id'],
        'effective_date' => (string)$row['effective_date'],
        'balance' => (float)$row['balance'],
        'source' => (string)$row['source'],
        'note' => (string)$row['note'],
        'updated_at' => (string)$row['updated_at'],
    ];
}

function save_month(PDO $db, array $month): int
{
    $id = (int)($month['id'] ?? 0);
    $year = (int)($month['year'] ?? 0);
    $m = (int)($month['month'] ?? 0);
    if ($year < 1900 || $year > 2200 || $m < 1 || $m > 12) fail('Invalid year/month', 400);
    $rateInput = $month['exchange_rate'] ?? null;
    $rate = $rateInput === null || trim((string)$rateInput) === '' ? null : (float)$rateInput;
    if ($rate !== null && $rate <= 0) fail('Exchange rate must be greater than zero when provided', 400);

    $params = [
        ':year' => $year,
        ':month' => $m,
        ':rate' => $rate,
        ':salary' => (float)($month['salary'] ?? 0),
        ':notes' => trim((string)($month['notes'] ?? '')),
    ];

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        if ($id > 0) {
            $params[':id'] = $id;
            $stmt = $db->prepare('UPDATE months SET year=:year, month=:month, exchange_rate=:rate, salary=:salary, notes=:notes, updated_at=CURRENT_TIMESTAMP WHERE id=:id');
            $stmt->execute($params);
            $savedId = $id;
        } else {
            $stmt = $db->prepare('INSERT INTO months (year, month, exchange_rate, salary, notes)
                VALUES (:year, :month, :rate, :salary, :notes)
                ON CONFLICT(year, month) DO UPDATE SET exchange_rate=excluded.exchange_rate, salary=excluded.salary, notes=excluded.notes, updated_at=CURRENT_TIMESTAMP');
            $stmt->execute($params);
            $savedId = (int)$db->query('SELECT id FROM months WHERE year = ' . (int)$year . ' AND month = ' . (int)$m)->fetchColumn();
        }
        if ($ownsTransaction) $db->commit();
        return $savedId;
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function transaction_spending_amount(array $transaction, string $amountField = 'amount'): float
{
    $amount = abs((float)($transaction[$amountField] ?? 0));
    return ($transaction['kind'] ?? 'expense') === 'refund' ? -$amount : $amount;
}

function statement_reconciliation_create_expense(PDO $db, int $statementRowId, array $input): int
{
    $row = statement_reconciliation_load_row($db, $statementRowId);
    $accountId = (int)$row['account_id'];
    $evidenceAmount = (float)$row['amount'];
    $evidenceCurrency = strtoupper((string)$row['currency']);
    if ((string)$row['booking_date'] === '' || $row['parse_problem'] !== '' || !statement_reconciliation_supported_currency($db, $evidenceCurrency) || !is_finite($evidenceAmount) || $evidenceAmount >= 0) {
        throw new InvalidArgumentException('Statement row does not represent an outgoing expense');
    }
    $existing = $db->prepare('SELECT 1 FROM statement_reconciliations WHERE statement_row_id = :rid');
    $existing->execute([':rid' => $statementRowId]);
    if ($existing->fetchColumn()) throw new InvalidArgumentException('Statement row already has a reconciliation disposition');
    $envelopeId = (int)($input['envelope_id'] ?? 0);
    if ($envelopeId <= 0) throw new InvalidArgumentException('Envelope is required');
    $envelope = $db->prepare('SELECT name FROM envelopes WHERE id = :id');
    $envelope->execute([':id' => $envelopeId]);
    $envelopeRow = $envelope->fetch();
    if (!$envelopeRow) throw new InvalidArgumentException('Envelope not found');
    $date = trim((string)($input['spent_date'] ?? $input['date'] ?? $row['booking_date']));
    if (!valid_date($date)) throw new InvalidArgumentException('Date must be YYYY-MM-DD');
    $originalAmount = abs($evidenceAmount);
    $currencyAmount = statement_reconciliation_amount_in_currency($db, $originalAmount, $evidenceCurrency, $date);
    if ($currencyAmount === null || $currencyAmount <= 0) throw new InvalidArgumentException('A usable exchange rate is required for this Expense date');
    $transaction = [
        'envelope_id' => $envelopeId,
        'envelope' => (string)$envelopeRow['name'],
        'payment_account_id' => $accountId,
        'amount' => $currencyAmount,
        'note' => trim((string)($input['note'] ?? $row['description_original'] ?? '')),
        'spent_date' => $date,
        'kind' => 'expense',
    ];
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $transactionId = save_transaction($db, $transaction, null, false, true, !empty($input['confirm_duplicate']));
        statement_reconciliation_link_insert($db, $statementRowId, $transactionId, null);
        if ($ownsTransaction) $db->commit();
        return $transactionId;
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function statement_reconciliation_create_refund(PDO $db, int $statementRowId, array $input): int
{
    $row = statement_reconciliation_load_row($db, $statementRowId);
    $accountId = (int)$row['account_id'];
    $evidenceAmount = (float)$row['amount'];
    $evidenceCurrency = strtoupper((string)$row['currency']);
    if ((string)$row['booking_date'] === '' || $row['parse_problem'] !== '' || !statement_reconciliation_supported_currency($db, $evidenceCurrency) || !is_finite($evidenceAmount) || $evidenceAmount <= 0) {
        throw new InvalidArgumentException('Statement row does not represent an incoming Refund');
    }
    $existing = $db->prepare('SELECT 1 FROM statement_reconciliations WHERE statement_row_id = :rid');
    $existing->execute([':rid' => $statementRowId]);
    if ($existing->fetchColumn()) throw new InvalidArgumentException('Statement row already has a reconciliation disposition');
    $envelopeId = (int)($input['envelope_id'] ?? 0);
    if ($envelopeId <= 0) throw new InvalidArgumentException('Envelope is required');
    $envelope = $db->prepare('SELECT name FROM envelopes WHERE id = :id');
    $envelope->execute([':id' => $envelopeId]);
    $envelopeRow = $envelope->fetch();
    if (!$envelopeRow) throw new InvalidArgumentException('Envelope not found');
    $date = trim((string)($input['date'] ?? $row['booking_date']));
    if (!valid_date($date)) throw new InvalidArgumentException('Date must be YYYY-MM-DD');
    $currencyAmount = statement_reconciliation_amount_in_currency($db, $evidenceAmount, $evidenceCurrency, $date);
    if ($currencyAmount === null || $currencyAmount <= 0) throw new InvalidArgumentException('A usable exchange rate is required for this Refund date');
    $transaction = [
        'envelope_id' => $envelopeId,
        'envelope' => (string)$envelopeRow['name'],
        'payment_account_id' => $accountId,
        'amount' => $currencyAmount,
        'note' => trim((string)($input['note'] ?? $row['description_original'] ?? '')),
        'spent_date' => $date,
        'kind' => 'refund',
    ];
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $transactionId = save_transaction($db, $transaction, null, false, true, !empty($input['confirm_duplicate']));
        statement_reconciliation_link_insert($db, $statementRowId, $transactionId, null);
        if ($ownsTransaction) $db->commit();
        return $transactionId;
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function normalize_transaction(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'month_id' => $row['month_id'] === null ? null : (int)$row['month_id'],
        'entry_id' => $row['entry_id'] === null ? null : (int)$row['entry_id'],
        'envelope_id' => $row['envelope_id'] === null ? null : (int)$row['envelope_id'],
        'payment_account_id' => $row['payment_account_id'] === null ? null : (int)$row['payment_account_id'],
        'kind' => (string)($row['kind'] ?? '') === 'refund' ? 'refund' : 'expense',
        'envelope' => (string)$row['envelope'],
        'amount' => (float)$row['amount'],
        'note' => (string)$row['note'],
        'spent_date' => (string)$row['spent_date'],
        'is_automatic' => (int)($row['is_automatic'] ?? 0),
        'source_year' => isset($row['source_year']) ? (int)$row['source_year'] : null,
        'source_month' => isset($row['source_month']) ? (int)$row['source_month'] : null,
    ];
}

function sync_transaction_envelope_movement(PDO $db, int $transactionId): void
{
    $stmt = $db->prepare('SELECT id, kind, month_id, envelope_id, amount, note, spent_date FROM transactions WHERE id = :id');
    $stmt->execute([':id' => $transactionId]);
    $transaction = $stmt->fetch();
    if (!$transaction || (int)($transaction['envelope_id'] ?? 0) <= 0) {
        $db->prepare("DELETE FROM envelope_movements WHERE transaction_id = :id AND type IN ('expense','refund')")->execute([':id' => $transactionId]);
        return;
    }
    $kind = (string)($transaction['kind'] ?? '') === 'refund' ? 'refund' : 'expense';
    $params = [
        ':envelope_id' => (int)$transaction['envelope_id'],
        ':month_id' => $transaction['month_id'] === null ? null : (int)$transaction['month_id'],
        ':transaction_id' => $transactionId,
        ':type' => $kind,
        ':amount' => $kind === 'refund' ? abs((float)$transaction['amount']) : -abs((float)$transaction['amount']),
        ':note' => (string)$transaction['note'],
        ':movement_date' => (string)$transaction['spent_date'],
    ];
    $exists = $db->prepare("SELECT id,type,envelope_id,month_id,amount,note,movement_date FROM envelope_movements WHERE transaction_id = :transaction_id AND type IN ('expense','refund')");
    $exists->execute([':transaction_id' => $transactionId]);
    $current = $exists->fetch() ?: null;
    if ($current) {
        $currentMonthId = $current['month_id'] === null ? null : (int)$current['month_id'];
        $changed = (string)$current['type'] !== $params[':type']
            || (int)$current['envelope_id'] !== $params[':envelope_id']
            || $currentMonthId !== $params[':month_id']
            || abs((float)$current['amount'] - (float)$params[':amount']) > 0.004
            || (string)$current['note'] !== $params[':note']
            || (string)$current['movement_date'] !== $params[':movement_date'];
        if (!$changed) return;
        $movement = $db->prepare("UPDATE envelope_movements SET type=:type, envelope_id=:envelope_id, month_id=:month_id, amount=:amount, note=:note, movement_date=:movement_date, updated_at=CURRENT_TIMESTAMP WHERE id=:id");
        $params[':id'] = (int)$current['id'];
        unset($params[':transaction_id']);
    } else {
        $movement = $db->prepare("INSERT INTO envelope_movements (envelope_id, month_id, transaction_id, type, amount,note,movement_date) VALUES (:envelope_id, :month_id, :transaction_id, :type, :amount, :note, :movement_date)");
    }
    $movement->execute($params);
}

function synchronize_all_transaction_envelope_movements(PDO $db): void
{
    $transactionColumns = array_column($db->query('PRAGMA table_info(transactions)')->fetchAll(), 'name');
    $hasAccountPosting = in_array('payment_account_id', $transactionColumns, true);
    foreach ($db->query('SELECT id FROM transactions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $transactionId) {
        sync_transaction_envelope_movement($db, (int)$transactionId);
        if ($hasAccountPosting) sync_transaction_account_event($db, (int)$transactionId);
    }
}

function save_entry(PDO $db, array $entry, ?int $id = null): int
{
    $previousEntry = null;
    if ($id !== null) {
        $previousStmt = $db->prepare('SELECT status, is_bs, due_date, bs_payment_account_id, bs_expense_finalized_at FROM entries WHERE id = :id');
        $previousStmt->execute([':id' => $id]);
        $previousEntry = $previousStmt->fetch() ?: null;
    }
    $monthId = (int)($entry['month_id'] ?? 0);
    if ($monthId <= 0) fail('Missing month', 400);
    $monthStmt = $db->prepare('SELECT year, month, exchange_rate FROM months WHERE id = :id');
    $monthStmt->execute([':id' => $monthId]);
    $month = $monthStmt->fetch();
    if (!$month) fail('Month not found', 404);
    $name = trim((string)($entry['name'] ?? ''));
    $type = (string)($entry['type'] ?? 'expense');
    if (!in_array($type, ['expense', 'income', 'bill', 'savings'], true)) fail('Invalid type', 400);
    if ($type !== 'income') $type = 'expense';
    if ($type === 'income' && $name === '') fail('Name is required', 400);
    $status = (string)($entry['status'] ?? 'planned');
    if ($status === 'handled') $status = 'planned';
    if (!in_array($status, ['planned', 'paid', 'skipped'], true)) fail('Invalid status', 400);
    $inputPreference = currency_assert_preference((string)($entry['currency'] ?? FIN_CURRENCY_PREFERENCE));
    $isBs = $type !== 'income' && !empty($entry['is_bs']);
    $bsPaymentProvided = array_key_exists('bs_payment_account_id', $entry);
    $bsPaymentAccountId = $bsPaymentProvided
        ? ((int)$entry['bs_payment_account_id'] ?: null)
        : (($previousEntry['bs_payment_account_id'] ?? null) === null ? null : (int)$previousEntry['bs_payment_account_id']);
    if (!$isBs) $bsPaymentAccountId = null;
    if ($bsPaymentAccountId !== null) {
        $paymentAccount = account_by_id($db, $bsPaymentAccountId);
        $previousPaymentAccountId = ($previousEntry['bs_payment_account_id'] ?? null) === null ? null : (int)$previousEntry['bs_payment_account_id'];
        if ((int)$paymentAccount['active'] !== 1 && $bsPaymentAccountId !== $previousPaymentAccountId) fail('Archived accounts cannot be used for new Direct debit payments', 400);
    }
    $due = trim((string)($entry['due_date'] ?? ''));
    if ($due !== '' && !valid_date($due)) fail('Due date must be YYYY-MM-DD', 400);
    if ($isBs && $due === '') $due = first_day_of_month((int)$month['year'], (int)$month['month']);

    $envelopeId = (int)($entry['envelope_id'] ?? 0) ?: null;
    $category = trim((string)($entry['category'] ?? 'Other')) ?: 'Other';
    if ($type !== 'income') {
        if ($envelopeId === null) fail('Envelope is required', 400);
        $envelopeStmt = $db->prepare('SELECT id, name, currency FROM envelopes WHERE id = :id');
        $envelopeStmt->execute([':id' => $envelopeId]);
        $envelope = $envelopeStmt->fetch();
        if (!$envelope) fail('Envelope not found', 404);
        $name = (string)$envelope['name'];
        $category = 'Envelope';
        $inputPreference = currency_assert_preference((string)$envelope['currency']);
        $duplicate = $db->prepare("SELECT id FROM entries WHERE month_id = :month_id AND envelope_id = :envelope_id AND type <> 'income' AND id <> :id LIMIT 1");
        $duplicate->execute([':month_id' => $monthId, ':envelope_id' => $envelopeId, ':id' => $id ?? 0]);
        if ((int)$duplicate->fetchColumn() > 0) fail('This Envelope already has a Plan for this Month', 409);
    }

    $inputAmount = (float)($entry['amount'] ?? 0);
    $amount = amount_to_currency($db, $inputAmount, $inputPreference, sprintf('%04d-%02d-01', (int)$month['year'], (int)$month['month']));

    $params = [
        ':month_id' => $monthId,
        ':envelope_id' => $envelopeId,
        ':type' => $type,
        ':name' => $name,
        ':category' => $category,
        ':amount' => $amount,
        ':note' => trim((string)($entry['note'] ?? '')),
        ':status' => $status,
        ':due_date' => $due === '' ? null : $due,
        ':sort_order' => (int)($entry['sort_order'] ?? 0),
        ':is_recurring' => !empty($entry['is_recurring']) ? 1 : 0,
        ':is_bs' => $isBs ? 1 : 0,
        ':bs_payment_account_id' => $bsPaymentAccountId,
        ':bs_expense_finalized_at' => $previousEntry['bs_expense_finalized_at'] ?? null,
    ];

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        if ($id === null) {
            $stmt = $db->prepare('INSERT INTO entries (month_id, envelope_id, type, name, category, amount, note, status, due_date, sort_order, is_recurring, is_bs, bs_payment_account_id, bs_expense_finalized_at)
                VALUES (:month_id, :envelope_id, :type, :name, :category, :amount, :note, :status, :due_date, :sort_order, :is_recurring, :is_bs, :bs_payment_account_id, :bs_expense_finalized_at)');
            $stmt->execute($params);
            $id = (int)$db->lastInsertId();
        } else {
            $params[':id'] = $id;
            $stmt = $db->prepare('UPDATE entries SET month_id=:month_id, envelope_id=:envelope_id, type=:type, name=:name, category=:category, amount=:amount, note=:note, status=:status, due_date=:due_date, sort_order=:sort_order, is_recurring=:is_recurring, is_bs=:is_bs, bs_payment_account_id=:bs_payment_account_id, bs_expense_finalized_at=:bs_expense_finalized_at, updated_at=CURRENT_TIMESTAMP WHERE id=:id');
            $stmt->execute($params);
        }
        $preserveFundingDate = $isBs || (int)($previousEntry['is_bs'] ?? 0) === 1;
        sync_entry_funding_movement($db, $id, null, $preserveFundingDate);
        sync_bs_automatic_transaction($db, $id);
        if ($ownsTransaction) $db->commit();
        return $id;
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function sync_entry_funding_movement(PDO $db, int $entryId, ?string $newFundingDate = null, bool $preserveExistingDate = false): void
{
    $stmt = $db->prepare('SELECT * FROM entries WHERE id = :id');
    $stmt->execute([':id' => $entryId]);
    $entry = $stmt->fetch();
    if (!$entry || (int)($entry['envelope_id'] ?? 0) <= 0 || (string)$entry['status'] !== 'paid' || (string)$entry['type'] === 'income' || (float)$entry['amount'] <= 0) {
        $db->prepare("DELETE FROM envelope_movements WHERE entry_id = :id AND type = 'funding'")->execute([':id' => $entryId]);
        $db->prepare("DELETE FROM envelope_movements WHERE entry_id = :id AND type = 'month_reset'")->execute([':id' => $entryId]);
        return;
    }
    $existing = $db->prepare("SELECT id, movement_date FROM envelope_movements WHERE entry_id = :entry_id AND type = 'funding'");
    $existing->execute([':entry_id' => $entryId]);
    $existingMovement = $existing->fetch() ?: null;
    $isBs = (int)($entry['is_bs'] ?? 0) === 1;
    if ($newFundingDate !== null && !valid_date($newFundingDate)) throw new InvalidArgumentException('Funding date must be YYYY-MM-DD');
    if ($existingMovement && ($preserveExistingDate || $isBs)) {
        $movementDate = (string)$existingMovement['movement_date'];
    } elseif ($newFundingDate !== null) {
        $movementDate = $newFundingDate;
    } elseif (!$isBs && valid_date((string)($entry['due_date'] ?? ''))) {
        $movementDate = (string)$entry['due_date'];
    } elseif ($existingMovement) {
        $movementDate = (string)$existingMovement['movement_date'];
    } else {
        $movementDate = effective_today();
    }
    $params = [
        ':envelope_id' => (int)$entry['envelope_id'],
        ':month_id' => (int)$entry['month_id'],
        ':entry_id' => $entryId,
        ':amount' => (float)$entry['amount'],
        ':note' => 'Funded from plan row: ' . (string)$entry['name'],
        ':movement_date' => $movementDate,
    ];
    if ($existingMovement) {
        $stmt = $db->prepare("UPDATE envelope_movements SET envelope_id=:envelope_id, month_id=:month_id, amount=:amount, note=:note, movement_date=:movement_date, updated_at=CURRENT_TIMESTAMP WHERE entry_id=:entry_id AND type = 'funding'");
    } else {
        $stmt = $db->prepare("INSERT INTO envelope_movements (envelope_id, month_id, entry_id, type, amount, note, movement_date) VALUES (:envelope_id, :month_id, :entry_id, 'funding', :amount, :note, :movement_date)");
    }
    $stmt->execute($params);
    sync_entry_reset_movement($db, $entryId, $movementDate);
}

/**
 * A Reset envelope starts the month with exactly that month's allocation: the
 * balance it carried into the funding is dropped, so neither a leftover nor a
 * negative is inherited. The reset is a dated ledger event of its own, so the
 * previous month's result stays visible in history while the new balance
 * starts from the allocation.
 *
 * The reset belongs to the Plan row that caused it: it is created, changed and
 * removed together with that row's funding movement. It is written only for a
 * funding inside the allocation's own Month, because that funding is the month
 * rollover. An amount moved forward for a later Month is simply added to the
 * Envelope and leaves its carried-over balance alone.
 */
function sync_entry_reset_movement(PDO $db, int $entryId, string $movementDate): void
{
    $stmt = $db->prepare('SELECT e.envelope_id, e.month_id, e.amount, v.month_end_behavior
        FROM entries e JOIN envelopes v ON v.id = e.envelope_id WHERE e.id = :id');
    $stmt->execute([':id' => $entryId]);
    $entry = $stmt->fetch();
    $existing = $db->prepare("SELECT id FROM envelope_movements WHERE entry_id = :entry_id AND type = 'month_reset'");
    $existing->execute([':entry_id' => $entryId]);
    $existingId = $existing->fetchColumn();
    $removeReset = static function () use ($db, $existingId): void {
        if ($existingId !== false) $db->prepare('DELETE FROM envelope_movements WHERE id = :id')->execute([':id' => (int)$existingId]);
    };
    if (!$entry || (string)$entry['month_end_behavior'] !== 'reset') {
        $removeReset();
        return;
    }
    $monthStmt = $db->prepare('SELECT year, month FROM months WHERE id = :id');
    $monthStmt->execute([':id' => (int)$entry['month_id']]);
    $month = $monthStmt->fetch();
    if (!$month || substr($movementDate, 0, 7) !== sprintf('%04d-%02d', (int)$month['year'], (int)$month['month'])) {
        $removeReset();
        return;
    }
    $envelopeId = (int)$entry['envelope_id'];
    $cutoff = (new DateTimeImmutable($movementDate))->modify('-1 day')->format('Y-m-d');
    $today = effective_today();
    if ($cutoff > $today) $cutoff = $today;
    $amount = round(-envelope_balance_as_of($db, $envelopeId, $cutoff), 2);
    if (abs($amount) < 0.005) {
        $removeReset();
        return;
    }
    $params = [
        ':envelope_id' => $envelopeId,
        ':month_id' => (int)$entry['month_id'],
        ':entry_id' => $entryId,
        ':amount' => $amount,
        ':note' => 'Reset: previous balance not carried over',
        ':movement_date' => $movementDate,
    ];
    if ($existingId !== false) {
        $stmt = $db->prepare("UPDATE envelope_movements SET amount=:amount, note=:note, movement_date=:movement_date, updated_at=CURRENT_TIMESTAMP WHERE id=:id");
        $stmt->execute([
            ':amount' => $params[':amount'],
            ':note' => $params[':note'],
            ':movement_date' => $params[':movement_date'],
            ':id' => (int)$existingId,
        ]);
        return;
    }
    $db->prepare("INSERT INTO envelope_movements (envelope_id, month_id, entry_id, type, amount, note, movement_date)
        VALUES (:envelope_id, :month_id, :entry_id, 'month_reset', :amount, :note, :movement_date)")->execute($params);
}

function release_bs_transaction_from_planning(PDO $db, int $entryId): void
{
    $stmt = $db->prepare('SELECT id,spent_date FROM transactions WHERE entry_id=:entry_id AND is_automatic=1');
    $stmt->execute([':entry_id' => $entryId]);
    $transaction = $stmt->fetch();
    if (!$transaction) return;
    if ((string)$transaction['spent_date'] > effective_today()) {
        $db->prepare('DELETE FROM transactions WHERE id=:id')->execute([':id' => (int)$transaction['id']]);
        return;
    }
    $db->prepare('UPDATE entries SET bs_expense_finalized_at=COALESCE(bs_expense_finalized_at,CURRENT_TIMESTAMP) WHERE id=:id')->execute([':id' => $entryId]);
}

function sync_bs_automatic_transaction(PDO $db, int $entryId): void
{
    $entryColumns = array_column($db->query('PRAGMA table_info(entries)')->fetchAll(), 'name');
    $transactionColumns = array_column($db->query('PRAGMA table_info(transactions)')->fetchAll(), 'name');
    if (!in_array('is_bs', $entryColumns, true) || !in_array('is_automatic', $transactionColumns, true)) return;

    $stmt = $db->prepare("SELECT e.*,m.year,m.month,v.name AS envelope_name
        FROM entries e
        JOIN months m ON m.id=e.month_id
        JOIN envelopes v ON v.id=e.envelope_id
        WHERE e.id=:id");
    $stmt->execute([':id' => $entryId]);
    $entry = $stmt->fetch();
    if (!$entry) return;
    $existing = $db->prepare('SELECT id,spent_date FROM transactions WHERE entry_id=:entry_id AND is_automatic=1');
    $existing->execute([':entry_id' => $entryId]);
    $transaction = $existing->fetch() ?: null;
    if (($entry['bs_expense_finalized_at'] ?? null) !== null) return;
    if ($transaction && (string)$transaction['spent_date'] <= effective_today()) {
        $db->prepare('UPDATE entries SET bs_expense_finalized_at=CURRENT_TIMESTAMP WHERE id=:id')->execute([':id' => $entryId]);
        return;
    }

    $shouldExist = (int)($entry['is_bs'] ?? 0) === 1 && (string)$entry['status'] === 'paid' && (float)$entry['amount'] > 0;
    if (!$shouldExist) {
        if ($transaction) $db->prepare('DELETE FROM transactions WHERE id=:id')->execute([':id' => (int)$transaction['id']]);
        return;
    }

    $dueDate = (string)($entry['due_date'] ?? '');
    $spentDate = valid_date($dueDate) ? $dueDate : first_day_of_month((int)$entry['year'], (int)$entry['month']);
    if (!valid_date($dueDate)) {
        $db->prepare('UPDATE entries SET due_date=:due_date,updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute([':due_date' => $spentDate, ':id' => $entryId]);
    }
    $transactionId = save_transaction($db, [
        'month_id' => (int)$entry['month_id'],
        'entry_id' => $entryId,
        'envelope_id' => (int)$entry['envelope_id'],
        'payment_account_id' => ($entry['bs_payment_account_id'] ?? null) === null ? null : (int)$entry['bs_payment_account_id'],
        'amount' => (float)$entry['amount'],
        'note' => 'Direct debit',
        'spent_date' => $spentDate,
        'is_automatic' => 1,
    ], $transaction ? (int)$transaction['id'] : null, true, true);
    if ($spentDate <= effective_today()) {
        $db->prepare('UPDATE entries SET bs_expense_finalized_at=CURRENT_TIMESTAMP WHERE id=:id')->execute([':id' => $entryId]);
    }
}

function delete_allocation_entry(PDO $db, int $entryId): void
{
    if ($entryId <= 0) throw new InvalidArgumentException('Allocation id is required');
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        release_bs_transaction_from_planning($db, $entryId);
        $db->prepare('DELETE FROM envelope_movements WHERE entry_id=:id')->execute([':id' => $entryId]);
        $db->prepare('DELETE FROM entries WHERE id=:id')->execute([':id' => $entryId]);
        if ($ownsTransaction) $db->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function set_allocation_status(PDO $db, int $monthId, int $envelopeId, string $status): int
{
    if ($monthId <= 0) fail('Month is required', 400);
    if ($envelopeId <= 0) fail('Envelope is required', 400);
    if (!in_array($status, ['planned', 'paid', 'skipped'], true)) fail('Invalid allocation status', 400);

    $stmt = $db->prepare("SELECT id FROM entries WHERE month_id = :month_id AND envelope_id = :envelope_id AND type <> 'income' LIMIT 1");
    $stmt->execute([':month_id' => $monthId, ':envelope_id' => $envelopeId]);
    $id = (int)$stmt->fetchColumn();

    $db->beginTransaction();
    try {
        if ($id > 0) {
            $db->prepare('UPDATE entries SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute([':status' => $status, ':id' => $id]);
            sync_entry_funding_movement($db, $id);
            sync_bs_automatic_transaction($db, $id);
        } else {
            $envelopeStmt = $db->prepare('SELECT name, sort_order FROM envelopes WHERE id = :id');
            $envelopeStmt->execute([':id' => $envelopeId]);
            $envelope = $envelopeStmt->fetch();
            if (!$envelope) fail('Envelope not found', 404);
            $id = save_entry($db, [
                'month_id' => $monthId,
                'envelope_id' => $envelopeId,
                'type' => 'expense',
                'name' => (string)$envelope['name'],
                'category' => 'Envelope',
                'amount' => 0,
                'status' => $status,
                'sort_order' => (int)$envelope['sort_order'],
                'is_recurring' => 1,
            ]);
        }
        $db->commit();
        return $id;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * A rejected `setPlanAmounts` request. The status is the HTTP status the API
 * answers with; the message always names the offending row.
 */
final class PlanAmountException extends RuntimeException
{
    public function __construct(string $message, private int $status)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}

/**
 * Sets the planned amounts of one existing Month's Plan rows.
 *
 * The action is deliberately narrow. It changes nothing but the amount of the
 * listed Plan rows: it never creates a Month, never changes a row's status or
 * any other field, and never creates a Funding movement, a Transaction, or a
 * Balance change. A row that does not exist yet becomes a plain Planned Plan
 * row without Betalingsservice and without a payment date; a Funded or
 * Skipped row is rejected instead of being overwritten.
 *
 * @return array{ok:bool,year:int,month:int,currency:string,rows:array<int,array<string,mixed>>}
 */
function set_plan_amounts(PDO $db, array $payload): array
{
    $year = plan_amount_integer($payload['year'] ?? null);
    $month = plan_amount_integer($payload['month'] ?? null);
    if ($year === null || $year < 1900 || $year > 2200 || $month === null || $month < 1 || $month > 12) {
        throw new PlanAmountException('Invalid year/month', 400);
    }

    $monthStmt = $db->prepare('SELECT id FROM months WHERE year = :year AND month = :month');
    $monthStmt->execute([':year' => $year, ':month' => $month]);
    $monthRow = $monthStmt->fetch();
    if (!$monthRow) throw new PlanAmountException('Month not found', 404);
    $monthId = (int)$monthRow['id'];

    $amounts = $payload['amounts'] ?? null;
    if (!is_array($amounts) || ($amounts !== [] && !array_is_list($amounts))) {
        throw new PlanAmountException('amounts must be a list of Plan rows', 400);
    }
    if (count($amounts) < 1) throw new PlanAmountException('At least one Plan amount is required', 400);
    if (count($amounts) > FIN_PLAN_AMOUNT_MAX_ROWS) {
        throw new PlanAmountException('A request may carry at most ' . FIN_PLAN_AMOUNT_MAX_ROWS . ' Plan amounts', 400);
    }

    // Validate the whole request before the first write, so an invalid row
    // leaves the Month untouched.
    $plans = [];
    $seen = [];
    foreach (array_values($amounts) as $index => $row) {
        $position = $index + 1;
        if (!is_array($row)) throw new PlanAmountException("Row {$position}: each Plan amount must be an object", 400);
        $envelopeId = $row['envelope_id'] ?? null;
        if (!is_int($envelopeId) || $envelopeId <= 0) {
            throw new PlanAmountException("Row {$position}: envelope_id must be a positive integer", 400);
        }
        if (isset($seen[$envelopeId])) {
            throw new PlanAmountException("Row {$position}: envelope_id {$envelopeId} is listed more than once", 400);
        }
        $seen[$envelopeId] = true;

        $rawAmount = $row['amount'] ?? null;
        if (!is_int($rawAmount) && !is_float($rawAmount)) {
            throw new PlanAmountException("Row {$position}: amount must be a number", 400);
        }
        $amount = plan_amount_value($rawAmount);
        if ($amount === null) {
            throw new PlanAmountException("Row {$position}: amount must have at most two decimals", 400);
        }
        if ($amount < 0) throw new PlanAmountException("Row {$position}: amount must be zero or more", 400);

        $envelopeStmt = $db->prepare('SELECT id, name, sort_order FROM envelopes WHERE id = :id');
        $envelopeStmt->execute([':id' => $envelopeId]);
        $envelope = $envelopeStmt->fetch();
        if (!$envelope) throw new PlanAmountException("Row {$position}: Envelope {$envelopeId} does not exist", 404);

        $entryStmt = $db->prepare("SELECT id, amount, status FROM entries WHERE month_id = :month_id AND envelope_id = :envelope_id AND type <> 'income' ORDER BY id LIMIT 1");
        $entryStmt->execute([':month_id' => $monthId, ':envelope_id' => $envelopeId]);
        $entry = $entryStmt->fetch() ?: null;
        if ($entry !== null && (string)$entry['status'] !== 'planned') {
            $status = (string)$entry['status'];
            $label = $status === 'paid' ? 'Funded' : ($status === 'skipped' ? 'Skipped' : $status);
            throw new PlanAmountException("Row {$position}: Envelope {$envelopeId} ({$envelope['name']}) already has a {$label} Plan row for this Month", 409);
        }

        $plans[] = [
            'envelope_id' => $envelopeId,
            'envelope' => (string)$envelope['name'],
            'sort_order' => (int)$envelope['sort_order'],
            'amount' => $amount,
            'entry_id' => $entry === null ? null : (int)$entry['id'],
            'previous' => $entry === null ? null : (float)$entry['amount'],
        ];
    }

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        foreach ($plans as $plan) {
            if ($plan['entry_id'] === null) {
                $stmt = $db->prepare("INSERT INTO entries (month_id, envelope_id, type, name, category, amount, note, status, due_date, sort_order, is_recurring, is_bs, bs_payment_account_id)
                    VALUES (:month_id, :envelope_id, 'expense', :name, 'Envelope', :amount, '', 'planned', NULL, :sort_order, 1, 0, NULL)");
                $stmt->execute([
                    ':month_id' => $monthId,
                    ':envelope_id' => $plan['envelope_id'],
                    ':name' => $plan['envelope'],
                    ':amount' => $plan['amount'],
                    ':sort_order' => $plan['sort_order'],
                ]);
            } else {
                $stmt = $db->prepare('UPDATE entries SET amount = :amount, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $stmt->execute([':amount' => $plan['amount'], ':id' => $plan['entry_id']]);
            }
        }
        if ($ownsTransaction) $db->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $error;
    }

    return [
        'ok' => true,
        'year' => $year,
        'month' => $month,
        'currency' => currency_context($db)['currency'],
        'rows' => array_map(static fn(array $plan): array => [
            'envelope_id' => $plan['envelope_id'],
            'envelope' => $plan['envelope'],
            'previous' => $plan['previous'],
            'amount' => $plan['amount'],
            'status' => 'planned',
        ], $plans),
    ];
}

/**
 * The value of an integer-valued JSON number, or null when the value is not
 * an integer.
 */
function plan_amount_integer(mixed $value): ?int
{
    if (is_int($value)) return $value;
    if (is_float($value) && is_finite($value) && floor($value) === $value) return (int)$value;
    return null;
}

/**
 * The Currency amount of a JSON number with at most two decimals, or null
 * when the value is not a number or carries more than two decimals.
 */
function plan_amount_value(mixed $value): ?float
{
    if (is_int($value)) return (float)$value;
    if (!is_float($value) || !is_finite($value)) return null;
    $rounded = round($value, 2);
    if (abs($rounded - $value) > 1e-9) return null;
    return $rounded;
}

function normalize_envelope_name(string $name): string
{
    $name = trim($name);
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}

function find_envelope_by_name(PDO $db, string $name, int $exceptId = 0): ?array
{
    $normalized = normalize_envelope_name($name);
    $stmt = $db->prepare('SELECT id, name FROM envelopes WHERE id <> :id');
    $stmt->execute([':id' => $exceptId]);
    foreach ($stmt->fetchAll() as $row) {
        if (normalize_envelope_name((string)$row['name']) === $normalized) return $row;
    }
    return null;
}

function get_or_create_envelope(PDO $db, string $name): int
{
    $name = trim($name);
    if ($name === '') fail('Envelope name is required', 400);
    $row = find_envelope_by_name($db, $name);
    if ($row) return (int)$row['id'];
    $stmt = $db->prepare('INSERT INTO envelopes (name, role, sort_order) VALUES (:name, :role, :sort_order)');
    $stmt->execute([':name' => $name, ':role' => default_envelope_role($name), ':sort_order' => (int)$db->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM envelopes')->fetchColumn()]);
    return (int)$db->lastInsertId();
}

function save_envelope(PDO $db, array $envelope): int
{
    $id = (int)($envelope['id'] ?? 0);
    $name = trim((string)($envelope['name'] ?? ''));
    if ($name === '') fail('Envelope name is required', 400);
    if (find_envelope_by_name($db, $name, $id)) fail('An envelope with this name already exists.', 400);
    $role = (string)($envelope['role'] ?? 'daily');
    if (!in_array($role, ['daily', 'reserved', 'savings'], true)) fail('Invalid envelope role', 400);
    $currency = currency_assert_preference((string)($envelope['currency'] ?? FIN_CURRENCY_PREFERENCE));
    $visibility = (string)($envelope['visibility'] ?? (!empty($envelope['hidden']) ? 'hidden' : 'normal'));
    if (!in_array($visibility, ['normal', 'hidden', 'today'], true)) fail('Invalid envelope visibility', 400);
    if ($role === 'daily') $visibility = 'normal';
    $goalVisibility = (string)($envelope['goal_visibility'] ?? 'month');
    if (!in_array($goalVisibility, ['hidden', 'month', 'today'], true)) fail('Invalid goal progress visibility', 400);
    if ($role !== 'savings') $goalVisibility = 'hidden';
    $amountSource = (string)($envelope['amount_source'] ?? 'history');
    if (!in_array($amountSource, ['history', 'fixed'], true)) fail('Invalid envelope amount source', 400);
    $monthEndBehavior = (string)($envelope['month_end_behavior'] ?? 'accumulate');
    if (!in_array($monthEndBehavior, ['accumulate', 'reset'], true)) fail('Invalid envelope month-end behavior', 400);
    $baselineProvided = array_key_exists('manual_baseline', $envelope);
    $manualBaseline = $baselineProvided && $envelope['manual_baseline'] !== null && $envelope['manual_baseline'] !== ''
        ? (float)$envelope['manual_baseline']
        : null;
    $manualBaselineNote = trim((string)($envelope['manual_baseline_note'] ?? ''));
    if (!$baselineProvided && $id > 0) {
        $existingSettings = $db->prepare('SELECT manual_baseline, manual_baseline_note FROM envelope_recommendation_settings WHERE envelope_id=:envelope_id');
        $existingSettings->execute([':envelope_id' => $id]);
        $existingBaseline = $existingSettings->fetch();
        if ($existingBaseline) {
            $manualBaseline = $existingBaseline['manual_baseline'] === null ? null : (float)$existingBaseline['manual_baseline'];
            $manualBaselineNote = (string)$existingBaseline['manual_baseline_note'];
        }
    }
    if ($manualBaseline !== null && (!is_finite($manualBaseline) || $manualBaseline < 0)) fail('Manual baseline must be a non-negative amount', 400);
    if ($manualBaseline !== null && $manualBaselineNote === '') $manualBaselineNote = 'Manual historical review';
    $defaultAccountProvided = array_key_exists('default_account_id', $envelope);
    $defaultAccountId = $defaultAccountProvided ? ((int)$envelope['default_account_id'] ?: null) : null;
    $currentDefaultAccountId = null;
    if ($id > 0) {
        $currentStmt = $db->prepare('SELECT currency,default_account_id,amount_source,month_end_behavior FROM envelopes WHERE id = :id');
        $currentStmt->execute([':id' => $id]);
        $current = $currentStmt->fetch();
        if (!$current) fail('Envelope not found', 404);
        $currentDefaultAccountId = $current['default_account_id'] === null ? null : (int)$current['default_account_id'];
        if (!$defaultAccountProvided) $defaultAccountId = $currentDefaultAccountId;
        if (!array_key_exists('amount_source', $envelope)) $amountSource = (string)$current['amount_source'];
        if (!array_key_exists('month_end_behavior', $envelope)) $monthEndBehavior = (string)$current['month_end_behavior'];
    }
    if ($defaultAccountId !== null) {
        $defaultAccount = account_by_id($db, $defaultAccountId);
        if ((int)$defaultAccount['active'] !== 1 && $defaultAccountId !== $currentDefaultAccountId) fail('Archived accounts cannot be selected as an envelope default', 400);
    }
    $params = [
        ':name' => $name,
        ':default_account_id' => $defaultAccountId,
        ':note' => trim((string)($envelope['note'] ?? '')),
        ':role' => $role,
        ':currency' => $currency,
        ':visibility' => $visibility,
        ':savings_goal' => $role === 'savings' ? max(0.0, (float)($envelope['savings_goal'] ?? 0)) : 0.0,
        ':goal_visibility' => $goalVisibility,
        ':hidden' => $visibility === 'hidden' ? 1 : 0,
        ':sort_order' => (int)($envelope['sort_order'] ?? 0),
        ':amount_source' => $amountSource,
        ':month_end_behavior' => $monthEndBehavior,
    ];
    if ($id > 0) {
        $params[':id'] = $id;
        $stmt = $db->prepare('UPDATE envelopes SET name=:name, default_account_id=:default_account_id, note=:note, role=:role, currency=:currency, visibility=:visibility, savings_goal=:savings_goal, goal_visibility=:goal_visibility, hidden=:hidden, sort_order=:sort_order, amount_source=:amount_source, month_end_behavior=:month_end_behavior, updated_at=CURRENT_TIMESTAMP WHERE id=:id');
        $stmt->execute($params);
    } else {
        $stmt = $db->prepare('INSERT INTO envelopes (name, default_account_id, note, role, currency, visibility, savings_goal, goal_visibility, hidden, sort_order, amount_source, month_end_behavior) VALUES (:name, :default_account_id, :note, :role, :currency, :visibility, :savings_goal, :goal_visibility, :hidden, :sort_order, :amount_source, :month_end_behavior)');
        $stmt->execute($params);
        $id = (int)$db->lastInsertId();
    }
    if ($manualBaseline === null) {
        $db->prepare('DELETE FROM envelope_recommendation_settings WHERE envelope_id=:envelope_id')->execute([':envelope_id' => $id]);
    } else {
        $settings = $db->prepare('INSERT INTO envelope_recommendation_settings (envelope_id, manual_baseline, manual_baseline_note)
            VALUES (:envelope_id, :manual_baseline, :manual_baseline_note)
            ON CONFLICT(envelope_id) DO UPDATE SET manual_baseline=excluded.manual_baseline, manual_baseline_note=excluded.manual_baseline_note, updated_at=CURRENT_TIMESTAMP');
        $settings->execute([':envelope_id' => $id, ':manual_baseline' => round($manualBaseline, 2), ':manual_baseline_note' => $manualBaselineNote]);
    }
    return $id;
}

function movement_month_id(PDO $db, string $date): ?int
{
    $stmt = $db->prepare("SELECT id FROM months WHERE year = CAST(substr(:date, 1, 4) AS INTEGER) AND month = CAST(substr(:date, 6, 2) AS INTEGER)");
    $stmt->execute([':date' => $date]);
    $id = (int)$stmt->fetchColumn();
    return $id > 0 ? $id : null;
}

function transfer_envelope_balance(PDO $db, array $transfer): int
{
    $sourceId = (int)($transfer['source_envelope_id'] ?? 0);
    $destinationId = (int)($transfer['destination_envelope_id'] ?? 0);
    if ($sourceId <= 0 || $destinationId <= 0) fail('Source and destination envelopes are required', 400);
    if ($sourceId === $destinationId) fail('Choose two different envelopes', 400);
    $amount = round((float)($transfer['amount'] ?? 0), 2);
    if (!is_finite($amount) || $amount <= 0) fail('Amount must be greater than zero', 400);
    $date = trim((string)($transfer['transfer_date'] ?? date('Y-m-d')));
    if (!valid_date($date)) fail('Date must be YYYY-MM-DD', 400);
    $ids = [$sourceId, $destinationId];
    $stmt = $db->prepare('SELECT id, name, default_account_id FROM envelopes WHERE id IN (?, ?)');
    $stmt->execute($ids);
    $envelopes = [];
    foreach ($stmt->fetchAll() as $envelope) $envelopes[(int)$envelope['id']] = $envelope;
    if (!isset($envelopes[$sourceId], $envelopes[$destinationId])) fail('Envelope not found', 404);
    $source = $envelopes[$sourceId];
    $destination = $envelopes[$destinationId];
    $sourceBalance = envelope_balance_as_of($db, $sourceId, $date);
    if ($amount > $sourceBalance && empty($transfer['negative_source_confirmed'])) fail('Confirm that this transfer may make the source envelope negative', 400);
    $crossAccount = ($source['default_account_id'] === null ? null : (int)$source['default_account_id'])
        !== ($destination['default_account_id'] === null ? null : (int)$destination['default_account_id']);
    if ($crossAccount && empty($transfer['cross_account_confirmed'])) fail('Confirm that the bank transfer has been completed', 400);
    $note = trim((string)($transfer['note'] ?? ''));
    if ($note === '') $note = 'Envelope transfer';
    $monthId = movement_month_id($db, $date);

    $db->beginTransaction();
    try {
        $insertTransfer = $db->prepare('INSERT INTO envelope_transfers
            (source_envelope_id, destination_envelope_id, source_envelope, destination_envelope, source_account_id, destination_account_id, amount, note, transfer_date)
            VALUES (:source_id, :destination_id, :source_name, :destination_name, :source_account_id, :destination_account_id, :amount, :note, :transfer_date)');
        $insertTransfer->execute([
            ':source_id' => $sourceId,
            ':destination_id' => $destinationId,
            ':source_name' => (string)$source['name'],
            ':destination_name' => (string)$destination['name'],
            ':source_account_id' => $source['default_account_id'] === null ? null : (int)$source['default_account_id'],
            ':destination_account_id' => $destination['default_account_id'] === null ? null : (int)$destination['default_account_id'],
            ':amount' => $amount,
            ':note' => $note,
            ':transfer_date' => $date,
        ]);
        $transferId = (int)$db->lastInsertId();
        $insertMovement = $db->prepare('INSERT INTO envelope_movements
            (envelope_id, month_id, transfer_id, type, amount, note, movement_date)
            VALUES (:envelope_id, :month_id, :transfer_id, :type, :amount, :note, :movement_date)');
        foreach ([[$sourceId, 'transfer_out', -$amount], [$destinationId, 'transfer_in', $amount]] as [$envelopeId, $type, $movementAmount]) {
            $insertMovement->execute([
                ':envelope_id' => $envelopeId,
                ':month_id' => $monthId,
                ':transfer_id' => $transferId,
                ':type' => $type,
                ':amount' => $movementAmount,
                ':note' => $note,
                ':movement_date' => $date,
            ]);
        }
        $db->commit();
        return $transferId;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function undo_envelope_transfer(PDO $db, int $id): void
{
    if ($id <= 0) fail('Transfer is required', 400);
    $stmt = $db->prepare('SELECT * FROM envelope_transfers WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $transfer = $stmt->fetch();
    if (!$transfer) fail('Transfer not found', 404);
    if ($transfer['reversed_at'] !== null) fail('This transfer has already been undone', 400);
    if ($transfer['source_envelope_id'] === null || $transfer['destination_envelope_id'] === null) fail('A transfer involving a deleted envelope cannot be undone', 400);
    $date = effective_today();
    $monthId = movement_month_id($db, $date);
    $db->beginTransaction();
    try {
        $insert = $db->prepare('INSERT INTO envelope_movements
            (envelope_id, month_id, transfer_id, type, amount, note, movement_date)
            VALUES (:envelope_id, :month_id, :transfer_id, :type, :amount, :note, :movement_date)');
        $amount = (float)$transfer['amount'];
        $note = 'Undo: ' . ((string)$transfer['note'] ?: 'Envelope transfer');
        foreach ([[(int)$transfer['source_envelope_id'], 'transfer_reversal_in', $amount], [(int)$transfer['destination_envelope_id'], 'transfer_reversal_out', -$amount]] as [$envelopeId, $type, $movementAmount]) {
            $insert->execute([
                ':envelope_id' => $envelopeId,
                ':month_id' => $monthId,
                ':transfer_id' => $id,
                ':type' => $type,
                ':amount' => $movementAmount,
                ':note' => $note,
                ':movement_date' => $date,
            ]);
        }
        $db->prepare('UPDATE envelope_transfers SET reversed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute([':id' => $id]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function delete_envelope(PDO $db, int $id): void
{
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE transactions SET envelope_id = NULL WHERE envelope_id = :id')->execute([':id' => $id]);
        $db->prepare('DELETE FROM entries WHERE envelope_id = :id')->execute([':id' => $id]);
        $db->prepare('DELETE FROM envelopes WHERE id = :id')->execute([':id' => $id]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function delete_transaction(PDO $db, int $transactionId): void
{
    $stmt = $db->prepare('SELECT entry_id,spent_date,is_automatic FROM transactions WHERE id=:id');
    $stmt->execute([':id' => $transactionId]);
    $transaction = $stmt->fetch();
    if (!$transaction) fail('Transaction not found', 404);
    $planningControlled = false;
    if ((int)$transaction['is_automatic'] === 1 && $transaction['entry_id'] !== null) {
        $finalized = $db->prepare('SELECT bs_expense_finalized_at FROM entries WHERE id=:id');
        $finalized->execute([':id' => (int)$transaction['entry_id']]);
        $planningControlled = $finalized->fetchColumn() === null;
    }
    if ($planningControlled && (string)$transaction['spent_date'] > effective_today()) {
        fail('Change the allocation from Funded to remove this scheduled Direct debit expense', 400);
    }
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        if ($planningControlled && $transaction['entry_id'] !== null) {
            $db->prepare('UPDATE entries SET bs_expense_finalized_at=COALESCE(bs_expense_finalized_at,CURRENT_TIMESTAMP) WHERE id=:id')->execute([':id' => (int)$transaction['entry_id']]);
        }
        $db->prepare('DELETE FROM envelope_movements WHERE transaction_id=:id')->execute([':id' => $transactionId]);
        $db->prepare('DELETE FROM transactions WHERE id=:id')->execute([':id' => $transactionId]);
        if ($ownsTransaction) $db->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

/**
 * A new Transaction would repeat an existing one exactly: the same date,
 * Envelope, amount and description. FIN asks the owner to confirm instead of
 * silently recording the same payment twice. Identical same-day entries stay
 * legitimate, so the duplicate is never blocked.
 */
final class DuplicateTransactionException extends RuntimeException
{
    /** @param array<string,mixed> $duplicate */
    public function __construct(private array $duplicate)
    {
        parent::__construct('An identical transaction already exists');
    }

    /** @return array<string,mixed> */
    public function duplicate(): array
    {
        return $this->duplicate;
    }
}

/**
 * Normalizes a Transaction description for duplicate comparison: trimmed,
 * whitespace-collapsed and case-insensitive.
 */
function transaction_description_key(string $description): string
{
    $collapsed = preg_replace('/\s+/u', ' ', trim($description));
    $collapsed = $collapsed === null ? trim($description) : $collapsed;
    return function_exists('mb_strtolower') ? mb_strtolower($collapsed, 'UTF-8') : strtolower($collapsed);
}

/**
 * Finds an existing Expense/Refund with the same date, Envelope, amount and
 * description. An Expense never matches a Refund, and vice versa.
 */
function find_duplicate_transaction(PDO $db, string $spentDate, int $envelopeId, string $envelope, float $amount, string $note, string $kind): ?array
{
    $descriptionKey = transaction_description_key($note);
    $stmt = $db->prepare("SELECT id, envelope_id, envelope, amount, note, spent_date, kind, is_automatic
        FROM transactions
        WHERE spent_date = :spent_date
          AND kind = :kind
          AND (envelope_id = :envelope_id OR (envelope_id IS NULL AND envelope = :envelope))
        ORDER BY id");
    $stmt->execute([
        ':spent_date' => $spentDate,
        ':kind' => $kind === 'refund' ? 'refund' : 'expense',
        ':envelope_id' => $envelopeId,
        ':envelope' => $envelope,
    ]);
    foreach ($stmt->fetchAll() as $row) {
        if (abs(round((float)$row['amount'], 2) - round($amount, 2)) >= 0.005) continue;
        if (transaction_description_key((string)$row['note']) !== $descriptionKey) continue;
        return [
            'id' => (int)$row['id'],
            'envelope_id' => $row['envelope_id'] === null ? null : (int)$row['envelope_id'],
            'envelope' => (string)$row['envelope'],
            'amount' => (float)$row['amount'],
            'note' => (string)$row['note'],
            'spent_date' => (string)$row['spent_date'],
            'kind' => (string)$row['kind'] === 'refund' ? 'refund' : 'expense',
            'is_automatic' => (int)$row['is_automatic'],
        ];
    }
    return null;
}

function save_transaction(PDO $db, array $transaction, ?int $id = null, bool $allowAutomatic = false, bool $amountIsCurrency = false, bool $allowDuplicate = false): int
{
    $existing = null;
    $finalizeEntryId = null;
    if ($id !== null) {
        $stmt = $db->prepare('SELECT id, month_id, entry_id, payment_account_id, spent_date, is_automatic, kind FROM transactions WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $existing = $stmt->fetch();
        if (!$existing) fail('Transaction not found', 404);
        if ((int)$existing['is_automatic'] === 1 && !$allowAutomatic) {
            $planningControlled = false;
            if ($existing['entry_id'] !== null) {
                $finalized = $db->prepare('SELECT bs_expense_finalized_at FROM entries WHERE id=:id');
                $finalized->execute([':id' => (int)$existing['entry_id']]);
                $planningControlled = $finalized->fetchColumn() === null;
            }
            if ($planningControlled && (string)$existing['spent_date'] > effective_today()) fail('Edit the Direct debit allocation instead', 400);
            if ($planningControlled && $existing['entry_id'] !== null) $finalizeEntryId = (int)$existing['entry_id'];
        }
    }

    $entryId = (int)($transaction['entry_id'] ?? 0);
    $monthId = array_key_exists('month_id', $transaction)
        ? ((int)$transaction['month_id'] ?: null)
        : ($existing && $existing['month_id'] !== null ? (int)$existing['month_id'] : null);
    if ($monthId === null && $entryId > 0) {
        $entryMonth = $db->prepare('SELECT month_id FROM entries WHERE id = :id');
        $entryMonth->execute([':id' => $entryId]);
        $monthId = (int)$entryMonth->fetchColumn() ?: null;
    }
    $month = null;
    if ($monthId !== null) {
        $monthStmt = $db->prepare('SELECT year, month, exchange_rate FROM months WHERE id = :id');
        $monthStmt->execute([':id' => $monthId]);
        $month = $monthStmt->fetch();
        if (!$month) fail('Month not found', 404);
    }

    $envelopeId = (int)($transaction['envelope_id'] ?? 0);
    $inputAmount = (float)($transaction['amount'] ?? 0);
    $inputPreference = FIN_CURRENCY_PREFERENCE;
    $date = trim((string)($transaction['spent_date'] ?? ''));
    if (!valid_date($date)) fail('Date must be YYYY-MM-DD', 400);

    $envelope = trim((string)($transaction['envelope'] ?? ''));
    if ($envelopeId > 0) {
        $stmt = $db->prepare('SELECT name, currency FROM envelopes WHERE id = :id');
        $stmt->execute([':id' => $envelopeId]);
        $envelopeRow = $stmt->fetch();
        $envelope = (string)($envelopeRow['name'] ?? '');
        $inputPreference = currency_assert_preference((string)($envelopeRow['currency'] ?? FIN_CURRENCY_PREFERENCE));
    }
    if ($envelope === '' && $entryId > 0) {
        $stmt = $db->prepare('SELECT name, envelope_id FROM entries WHERE id = :id');
        $stmt->execute([':id' => $entryId]);
        $row = $stmt->fetch();
        $envelope = (string)($row['name'] ?? '');
        if ($envelopeId <= 0) $envelopeId = (int)($row['envelope_id'] ?? 0);
    }
    if ($envelopeId <= 0 || $envelope === '') fail('Envelope is required', 400);

    if (!is_finite($inputAmount) || $inputAmount <= 0) fail('Amount must be greater than zero', 400);
    $amount = $amountIsCurrency
        ? round($inputAmount, 2)
        : amount_to_currency($db, $inputAmount, $inputPreference, $date);

    $paymentAccountId = array_key_exists('payment_account_id', $transaction)
        ? ((int)$transaction['payment_account_id'] ?: null)
        : ($existing && $existing['payment_account_id'] !== null ? (int)$existing['payment_account_id'] : null);
    if ($paymentAccountId !== null) {
        $paymentAccount = account_by_id($db, $paymentAccountId);
        $existingPaymentAccountId = $existing && $existing['payment_account_id'] !== null ? (int)$existing['payment_account_id'] : null;
        if ((int)$paymentAccount['active'] !== 1 && $paymentAccountId !== $existingPaymentAccountId) fail('Archived accounts cannot be used for new payments', 400);
    }
    $isAutomatic = $existing ? (int)$existing['is_automatic'] : ($allowAutomatic && !empty($transaction['is_automatic']) ? 1 : 0);
    if (array_key_exists('kind', $transaction)) {
        $kind = strtolower(trim((string)$transaction['kind']));
        if (!in_array($kind, ['expense', 'refund'], true)) fail('Transaction kind must be Expense or Refund', 400);
    } else {
        $kind = $existing ? ((string)$existing['kind'] === 'refund' ? 'refund' : 'expense') : 'expense';
    }
    $values = [
        ':month_id' => $monthId,
        ':entry_id' => $entryId > 0 ? $entryId : null,
        ':envelope_id' => $envelopeId,
        ':payment_account_id' => $paymentAccountId,
        ':envelope' => $envelope,
        ':amount' => $amount,
        ':note' => trim((string)($transaction['note'] ?? '')),
        ':spent_date' => $date,
        ':is_automatic' => $isAutomatic,
        ':kind' => $kind,
    ];

    // A Plan-derived Betalingsservice payment is not an owner-authored
    // Transaction, so it never carries the duplicate confirmation. Every
    // owner-created or imported Transaction is checked once, before it is
    // written, and only when it would repeat an existing one exactly.
    if ($id === null && !$allowAutomatic && !$allowDuplicate) {
        $duplicate = find_duplicate_transaction($db, $date, $envelopeId, $envelope, $amount, (string)$values[':note'], $kind);
        if ($duplicate !== null) throw new DuplicateTransactionException($duplicate);
    }

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        if ($finalizeEntryId !== null) {
            $db->prepare('UPDATE entries SET bs_expense_finalized_at=COALESCE(bs_expense_finalized_at,CURRENT_TIMESTAMP) WHERE id=:id')->execute([':id' => $finalizeEntryId]);
        }
        if ($id === null) {
            $stmt = $db->prepare('INSERT INTO transactions (month_id, entry_id, envelope_id, payment_account_id, envelope, amount, note, spent_date, is_automatic, kind)
                VALUES (:month_id, :entry_id, :envelope_id, :payment_account_id, :envelope, :amount, :note, :spent_date, :is_automatic, :kind)');
            $stmt->execute($values);
            $id = (int)$db->lastInsertId();
        } else {
            $values[':id'] = $id;
            $stmt = $db->prepare('UPDATE transactions SET month_id=:month_id, entry_id=:entry_id, envelope_id=:envelope_id, payment_account_id=:payment_account_id, envelope=:envelope, amount=:amount, note=:note, spent_date=:spent_date, is_automatic=:is_automatic, kind=:kind, updated_at=CURRENT_TIMESTAMP WHERE id=:id');
            $stmt->execute($values);
        }

        sync_transaction_envelope_movement($db, $id);
        sync_transaction_account_event($db, $id);
        if ($ownsTransaction) $db->commit();
        return $id;
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function duplicate_month(PDO $db, int $fromYear, int $fromMonth, int $toYear, int $toMonth): void
{
    if ($fromYear < 1900 || $toYear < 1900 || $fromMonth < 1 || $fromMonth > 12 || $toMonth < 1 || $toMonth > 12) fail('Invalid source/target month', 400);
    if ($fromYear === $toYear && $fromMonth === $toMonth) fail('Source and target month must be different', 400);
    $src = find_month($db, $fromYear, $fromMonth);
    if (!$src) fail('Source month not found', 404);
    $existingTarget = find_month($db, $toYear, $toMonth);
    $rateValue = $existingTarget ? $existingTarget['exchange_rate'] : $src['exchange_rate'];
    $targetRate = $rateValue === null ? null : (float)$rateValue;
    $targetId = save_month($db, [
        'year' => $toYear,
        'month' => $toMonth,
        'exchange_rate' => $targetRate,
        'salary' => $src['salary'],
        'notes' => '',
    ]);
    $db->beginTransaction();
    try {
        $targetEntries = $db->prepare('SELECT id FROM entries WHERE month_id=:id ORDER BY id');
        $targetEntries->execute([':id' => $targetId]);
        foreach ($targetEntries->fetchAll(PDO::FETCH_COLUMN) as $entryId) release_bs_transaction_from_planning($db, (int)$entryId);
        $db->prepare("DELETE FROM envelope_movements WHERE entry_id IN (SELECT id FROM entries WHERE month_id = :id) AND type = 'funding'")->execute([':id' => $targetId]);
        $db->prepare('DELETE FROM entries WHERE month_id = :id')->execute([':id' => $targetId]);
        $stmt = $db->prepare("INSERT INTO entries (month_id, envelope_id, type, name, category, amount, note, status, due_date, sort_order, is_recurring, is_bs, bs_payment_account_id, bs_expense_finalized_at)
            SELECT :target_id, envelope_id, type, name, category, amount, note,
                   CASE WHEN status = 'skipped' THEN 'skipped' ELSE 'planned' END,
                   NULL, sort_order, is_recurring, is_bs, bs_payment_account_id, NULL
            FROM entries
            WHERE month_id = :source_id
            ORDER BY sort_order, id");
        $stmt->execute([':target_id' => $targetId, ':source_id' => (int)$src['id']]);
        $check = $db->prepare("SELECT
            SUM(CASE WHEN envelope_id IS NOT NULL THEN 1 ELSE 0 END) AS assigned,
            SUM(CASE WHEN type = 'expense' AND status <> 'skipped' AND envelope_id IS NULL THEN 1 ELSE 0 END) AS unassigned
            FROM entries WHERE month_id = :month_id");
        $check->execute([':month_id' => (int)$src['id']]);
        $sourceStats = $check->fetch() ?: ['assigned' => 0, 'unassigned' => 0];
        $check->execute([':month_id' => $targetId]);
        $targetStats = $check->fetch() ?: ['assigned' => 0, 'unassigned' => 0];
        if ((int)$sourceStats['assigned'] !== (int)$targetStats['assigned'] || (int)$sourceStats['unassigned'] !== (int)$targetStats['unassigned']) {
            fail('Copy failed: envelope assignments were not preserved', 500);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function find_month(PDO $db, int $year, int $month): ?array
{
    $stmt = $db->prepare('SELECT * FROM months WHERE year = :year AND month = :month');
    $stmt->execute([':year' => $year, ':month' => $month]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * The transaction rows selected by the report's calendar-month and
 * effective-date rules. The optional Envelope filter changes only which
 * transactions are returned; it does not change their selection semantics.
 */
function report_transaction_rows(PDO $db, string $fromDate, string $toDate, ?int $envelopeId = null): array
{
    $effectiveToDate = min($toDate, effective_today());
    if ($fromDate > $effectiveToDate) return [];

    $sql = 'SELECT t.*, a.name AS account_name
        FROM transactions t
        LEFT JOIN accounts a ON a.id=t.payment_account_id
        WHERE t.spent_date BETWEEN :from_date AND :to_date';
    $params = [':from_date' => $fromDate, ':to_date' => $effectiveToDate];
    if ($envelopeId !== null) {
        $sql .= ' AND t.envelope_id = :envelope_id';
        $params[':envelope_id'] = $envelopeId;
    }
    $sql .= ' ORDER BY t.spent_date, t.id';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * The balance movements selected by the report's calendar-month and
 * effective-date rules: the human-meaningful movement types only. The
 * selection is shared by the monthly report and the Transfers read action so
 * both describe the same movements with the same amounts, dates, and notes.
 */
function report_balance_movement_rows(PDO $db, string $fromDate, string $toDate): array
{
    $stmt = $db->prepare("SELECT m.*, v.name AS envelope_name,
            t.source_envelope_id, t.destination_envelope_id, t.source_envelope, t.destination_envelope,
            t.source_account_id, t.destination_account_id,
            source_account.name AS source_account_name, destination_account.name AS destination_account_name,
            t.reversed_at AS transfer_reversed_at
        FROM envelope_movements m
        JOIN envelopes v ON v.id = m.envelope_id
        LEFT JOIN envelope_transfers t ON t.id = m.transfer_id
        LEFT JOIN accounts source_account ON source_account.id=t.source_account_id
        LEFT JOIN accounts destination_account ON destination_account.id=t.destination_account_id
        WHERE m.type IN ('opening_balance', 'archive_rollup', 'adjustment', 'reversal', 'transfer_in', 'transfer_out', 'transfer_reversal_in', 'transfer_reversal_out')
          AND m.movement_date BETWEEN :from_date AND :to_date
        ORDER BY m.movement_date, m.id");
    $stmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
    return $stmt->fetchAll();
}

/**
 * Individual Expense and Refund Transactions for trusted read-only clients.
 * The selection is the PDF report's selection, and amounts use the PDF's
 * signed actual-spending convention: Expense is positive and Refund negative.
 */
function build_transactions_response(PDO $db, string $from, string $to, ?int $envelopeId = null): array
{
    [$fromYear, $fromMonth] = parse_month_key($from);
    [$toYear, $toMonth] = parse_month_key($to);
    if (($fromYear * 12 + $fromMonth) > ($toYear * 12 + $toMonth)) {
        throw new InvalidArgumentException('From month must be before to month');
    }
    $monthCount = ($toYear * 12 + $toMonth) - ($fromYear * 12 + $fromMonth) + 1;
    if ($monthCount > 3) throw new InvalidArgumentException('Transaction range cannot exceed 3 months');

    $fromDate = sprintf('%04d-%02d-01', $fromYear, $fromMonth);
    $toDate = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $toYear, $toMonth)));
    $transactions = [];
    foreach (report_transaction_rows($db, $fromDate, $toDate, $envelopeId) as $row) {
        $transaction = normalize_transaction($row);
        $transactions[] = [
            'id' => $transaction['id'],
            'date' => $transaction['spent_date'],
            'envelope_id' => $transaction['envelope_id'],
            'envelope' => $transaction['envelope'],
            'description' => $transaction['note'],
            'amount' => transaction_spending_amount($transaction),
            'kind' => $transaction['kind'],
            'account' => $row['account_name'] === null ? null : (string)$row['account_name'],
        ];
    }

    return [
        'from' => sprintf('%04d-%02d', $fromYear, $fromMonth),
        'to' => sprintf('%04d-%02d', $toYear, $toMonth),
        'transactions' => $transactions,
    ];
}

/**
 * The Transfers of a month range, for trusted external clients.
 *
 * FIN has two kinds of Transfer and both are reported here:
 *
 * - `envelope` — money moved from one Envelope to another. The row is the
 *   monthly report's own transfer-out movement, so the date, amount, note,
 *   and Envelope names are exactly the ones the report shows for that move.
 * - `account` — money moved between two Accounts. The amount is the
 *   authoritative Currency amount of the Account event, the same amount the
 *   Accounts screen shows for the transfer.
 *
 * `payment_id` names the one real money movement a row belongs to. When the
 * same real payment is recorded both as an Envelope Transfer and as an
 * Account Transfer, both rows carry the same `payment_id`, so a client counts
 * the payment once instead of adding it up twice.
 */
function build_transfers_response(PDO $db, string $from, string $to): array
{
    [$fromYear, $fromMonth] = parse_month_key($from);
    [$toYear, $toMonth] = parse_month_key($to);
    if (($fromYear * 12 + $fromMonth) > ($toYear * 12 + $toMonth)) {
        throw new InvalidArgumentException('From month must be before to month');
    }
    $monthCount = ($toYear * 12 + $toMonth) - ($fromYear * 12 + $fromMonth) + 1;
    if ($monthCount > 3) throw new InvalidArgumentException('Transfer range cannot exceed 3 months');

    $fromDate = sprintf('%04d-%02d-01', $fromYear, $fromMonth);
    $toDate = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $toYear, $toMonth)));
    $effectiveToDate = min($toDate, effective_today());

    $envelopeTransfers = [];
    $envelopeMatches = [];
    foreach (report_balance_movement_rows($db, $fromDate, $effectiveToDate) as $row) {
        $movement = normalize_movement($row);
        if ($movement['type'] !== 'transfer_out' || $movement['transfer_id'] === null) continue;
        $amount = round(abs((float)$movement['amount']), 2);
        $envelopeTransfers[] = [
            'kind' => 'envelope',
            'id' => (int)$movement['transfer_id'],
            'date' => (string)$movement['movement_date'],
            'from_envelope' => (string)$movement['source_envelope'],
            'to_envelope' => (string)$movement['destination_envelope'],
            'amount' => $amount,
            'note' => (string)$movement['note'],
            'undone' => $movement['transfer_reversed_at'] !== null,
        ];
        $envelopeMatches[] = [
            'date' => (string)$movement['movement_date'],
            'amount' => $amount,
            'source_account_id' => $movement['source_account_id'],
            'destination_account_id' => $movement['destination_account_id'],
        ];
    }

    $currency = database_currency($db);
    $accountTransfers = [];
    $accountMatches = [];
    $stmt = $db->prepare("SELECT ae.id, ae.event_date, ae.amount, ae.note,
            ae.source_account_id, ae.destination_account_id,
            source_account.name AS source_account_name,
            destination_account.name AS destination_account_name,
            EXISTS(SELECT 1 FROM statement_reconciliations sr WHERE sr.target_account_event_id = ae.id) AS statement_linked
        FROM account_events ae
        LEFT JOIN accounts source_account ON source_account.id = ae.source_account_id
        LEFT JOIN accounts destination_account ON destination_account.id = ae.destination_account_id
        WHERE ae.event_type = 'transfer'
          AND ae.event_date BETWEEN :from_date AND :to_date
        ORDER BY ae.event_date, ae.id");
    $stmt->execute([':from_date' => $fromDate, ':to_date' => $effectiveToDate]);
    foreach ($stmt->fetchAll() as $row) {
        $amount = round((float)$row['amount'], 2);
        $accountTransfers[] = [
            'kind' => 'account',
            'id' => (int)$row['id'],
            'date' => (string)$row['event_date'],
            'from_account' => (string)($row['source_account_name'] ?? ''),
            'to_account' => (string)($row['destination_account_name'] ?? ''),
            'amount' => $amount,
            'currency' => $currency,
            'note' => (string)$row['note'],
            'linked_to_statement_row' => (int)$row['statement_linked'] === 1,
        ];
        $accountMatches[] = [
            'date' => (string)$row['event_date'],
            'amount' => $amount,
            'source_account_id' => $row['source_account_id'] === null ? null : (int)$row['source_account_id'],
            'destination_account_id' => $row['destination_account_id'] === null ? null : (int)$row['destination_account_id'],
        ];
    }

    foreach ($envelopeTransfers as $index => $transfer) {
        $envelopeTransfers[$index]['payment_id'] = 'envelope:' . $transfer['id'];
    }
    foreach ($accountTransfers as $index => $transfer) {
        $accountTransfers[$index]['payment_id'] = 'account:' . $transfer['id'];
    }
    foreach (transfer_same_payment_pairs($envelopeMatches, $accountMatches) as $pair) {
        $group = 'account:' . $accountTransfers[$pair['account']]['id'] . '+envelope:' . $envelopeTransfers[$pair['envelope']]['id'];
        $accountTransfers[$pair['account']]['payment_id'] = $group;
        $envelopeTransfers[$pair['envelope']]['payment_id'] = $group;
    }

    $transfers = array_merge($envelopeTransfers, $accountTransfers);
    usort($transfers, static function(array $left, array $right): int {
        $dateCompare = strcmp((string)$left['date'], (string)$right['date']);
        if ($dateCompare !== 0) return $dateCompare;
        $kindCompare = strcmp((string)$left['kind'], (string)$right['kind']);
        if ($kindCompare !== 0) return $kindCompare;
        return (int)$left['id'] <=> (int)$right['id'];
    });

    return [
        'from' => sprintf('%04d-%02d', $fromYear, $fromMonth),
        'to' => sprintf('%04d-%02d', $toYear, $toMonth),
        'transfers' => array_values($transfers),
    ];
}

/**
 * Pairs the Envelope Transfers and the Account Transfers that describe the
 * same real payment, so a client can count that payment once.
 *
 * One real payment can be recorded twice: as an Envelope Transfer between two
 * Envelopes that have different default Accounts, and as an Account Transfer
 * between those two Accounts. FIN stores no explicit link between the two
 * records, so they are recognized the way FIN already recognizes the same
 * payment for statement reconciliation: the same amount within the existing
 * amount tolerance, the same two Accounts, and the same date within the
 * existing date tolerance.
 *
 * Every row belongs to at most one pair, so an ambiguous case never links a
 * row to more than one partner.
 *
 * @return array<int,array{envelope:int,account:int}>
 */
function transfer_same_payment_pairs(array $envelopeMatches, array $accountMatches): array
{
    $pairs = [];
    $taken = [];
    foreach ($envelopeMatches as $envelopeIndex => $envelope) {
        foreach ($accountMatches as $accountIndex => $account) {
            if (isset($taken[$accountIndex])) continue;
            if (!transfers_describe_same_payment($envelope, $account)) continue;
            $pairs[] = ['envelope' => $envelopeIndex, 'account' => $accountIndex];
            $taken[$accountIndex] = true;
            break;
        }
    }
    return $pairs;
}

/**
 * True when one Envelope Transfer and one Account Transfer describe the same
 * real payment.
 *
 * An Envelope Transfer only moves money between Accounts when its two
 * Envelopes have different default Accounts; a move inside one Account is an
 * internal reallocation and never the Account side of a real payment.
 */
function transfers_describe_same_payment(array $envelope, array $account): bool
{
    $envelopeSource = $envelope['source_account_id'] ?? null;
    $envelopeDestination = $envelope['destination_account_id'] ?? null;
    $accountSource = $account['source_account_id'] ?? null;
    $accountDestination = $account['destination_account_id'] ?? null;
    if ($envelopeSource === null || $envelopeDestination === null) return false;
    if ($accountSource === null || $accountDestination === null) return false;
    if ((int)$envelopeSource === (int)$envelopeDestination) return false;
    $samePair = ((int)$envelopeSource === (int)$accountSource && (int)$envelopeDestination === (int)$accountDestination)
        || ((int)$envelopeSource === (int)$accountDestination && (int)$envelopeDestination === (int)$accountSource);
    if (!$samePair) return false;
    if (abs((float)$envelope['amount'] - (float)$account['amount']) > STATEMENT_RECONCILIATION_AMOUNT_TOLERANCE) return false;
    $days = (int)round(abs((strtotime((string)$envelope['date']) - strtotime((string)$account['date']))) / 86400);
    return $days <= STATEMENT_RECONCILIATION_DATE_TOLERANCE_DAYS;
}

/**
 * The current balance of every Account, for external clients.
 *
 * The balance is not derived again here: `get_accounts()` is the exact
 * source of the Accounts screen's account rows, so the reported balance is
 * the balance that screen shows for the same Account, including its
 * treatment of an unknown balance and of an unavailable conversion.
 *
 * Archived Accounts are part of the answer and are never filtered out; they
 * carry their status. Account numbers are deliberately not reported.
 */
function build_account_balances_response(PDO $db): array
{
    $asOf = effective_today();
    $currency = currency_context($db)['currency'];
    $observationDates = [];
    $observation = $db->prepare('SELECT account_id,MAX(effective_date) AS last_observation_date
        FROM account_balance_observations WHERE effective_date<=:as_of GROUP BY account_id');
    $observation->execute([':as_of' => $asOf]);
    foreach ($observation->fetchAll() as $row) {
        $observationDates[(int)$row['account_id']] = (string)$row['last_observation_date'];
    }

    $accounts = [];
    foreach (get_accounts($db, $asOf) as $account) {
        $known = (bool)$account['balance_known'];
        $converted = $known && $account['display_balance'] !== null;
        if (!$known) {
            $balance = null;
            $balanceCurrency = (string)$account['display_currency'];
        } elseif ($converted) {
            $balance = (float)$account['display_balance'];
            $balanceCurrency = (string)$account['display_currency'];
        } else {
            // The Accounts screen falls back to the Currency amount when the
            // conversion into the Account's own currency is not available.
            $balance = (float)$account['balance'];
            $balanceCurrency = $currency;
        }
        $accounts[] = [
            'id' => (int)$account['id'],
            'name' => (string)$account['name'],
            'currency' => $balanceCurrency,
            'balance' => $balance,
            'balance_known' => $known,
            'status' => (int)$account['active'] === 1 ? 'active' : 'archived',
            'last_observation_date' => $observationDates[(int)$account['id']] ?? null,
        ];
    }

    return [
        'as_of' => $asOf,
        'currency' => $currency,
        'accounts' => $accounts,
    ];
}

function get_report_data(PDO $db, string $from, string $to): array
{
    [$fromYear, $fromMonth] = parse_month_key($from);
    [$toYear, $toMonth] = parse_month_key($to);
    $fromKey = ($fromYear * 100) + $fromMonth;
    $toKey = ($toYear * 100) + $toMonth;
    if ($fromKey > $toKey) fail('From month must be before to month', 400);

    $stmt = $db->prepare('SELECT * FROM months WHERE ((year > ?) OR (year = ? AND month >= ?)) AND ((year < ?) OR (year = ? AND month <= ?)) ORDER BY year, month');
    $stmt->execute([$fromYear, $fromYear, $fromMonth, $toYear, $toYear, $toMonth]);
    $monthRows = $stmt->fetchAll();
    if (!$monthRows) fail('No months found in selected range', 404);

    $ids = array_map(fn($m) => (int)$m['id'], $monthRows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $db->prepare("SELECT e.*,a.name AS account_name
        FROM entries e
        LEFT JOIN envelopes v ON v.id=e.envelope_id
        LEFT JOIN accounts a ON a.id=COALESCE(e.bs_payment_account_id,v.default_account_id)
        WHERE e.month_id IN ($placeholders)
        ORDER BY e.month_id,e.sort_order,e.id");
    $stmt->execute($ids);
    $entries = [];
    foreach ($stmt->fetchAll() as $entry) {
        $entries[(int)$entry['month_id']][] = normalize_entry($entry);
    }

    $fromDate = sprintf('%04d-%02d-01', $fromYear, $fromMonth);
    $toDate = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $toYear, $toMonth)));
    $effectiveToDate = min($toDate, effective_today());
    $transactions = [];
    foreach (report_transaction_rows($db, $fromDate, $toDate) as $tx) {
        $transactions[substr((string)$tx['spent_date'], 0, 7)][] = normalize_transaction($tx);
    }
    $balanceMovements = [];
    foreach (report_balance_movement_rows($db, $fromDate, $effectiveToDate) as $movement) {
        $balanceMovements[substr((string)$movement['movement_date'], 0, 7)][] = normalize_movement($movement);
    }

    // The recorded Income of every month in the range. The same query and the
    // same rule the read-only history API uses; a month without an Income
    // event has no recorded income figure at all.
    $incomeEventsByMonth = report_income_events_by_month($db, sprintf('%04d-%02d', $fromYear, $fromMonth), sprintf('%04d-%02d', $toYear, $toMonth));

    $months = [];
    foreach ($monthRows as $row) {
        $month = normalize_month($row);
        $monthKey = sprintf('%04d-%02d', $month['year'], $month['month']);
        $month['entries'] = $entries[$month['id']] ?? [];
        $month['transactions'] = $transactions[$monthKey] ?? [];
        $month['balance_movements'] = $balanceMovements[$monthKey] ?? [];
        $month['summary'] = report_month_summary($month);
        $incomeEvents = $incomeEventsByMonth[$monthKey] ?? [];
        $month['recorded_income'] = $incomeEvents === [] ? null : (float)array_sum(array_column($incomeEvents, 'amount'));
        $months[] = $month;
    }

    return [
        'from' => sprintf('%04d-%02d', $fromYear, $fromMonth),
        'to' => sprintf('%04d-%02d', $toYear, $toMonth),
        'months' => $months,
        'currency' => currency_context($db)['currency'],
        'helper_currency' => currency_context($db)['helper_currency'],
        'created_at' => date('Y-m-d H:i'),
    ];
}

/**
 * Per-month history for external clients.
 *
 * Reuses the report model unchanged, so Plan, Funded, Spent, and Balance are
 * exactly what the PDF report computes, and adds actual Income events.
 * Planned income, exchange rates, the transaction list, transfers/adjustments,
 * and every note that is not an Income note are deliberately left out.
 *
 * A Month outside the retained running data that the funding-recommendation
 * engine already knows is reported as an actual-only Month: it carries the
 * per-envelope actual spending and nothing else, because Plan, Funded and
 * Balance do not exist for it.
 *
 * The current Month additionally carries `safe_to_spend`: the figure the Today
 * screen shows, together with every input and intermediate result behind it.
 */
function build_history_response(PDO $db, string $from, string $to): array
{
    [$fromYear, $fromMonth] = parse_month_key($from);
    [$toYear, $toMonth] = parse_month_key($to);
    if ((($fromYear * 100) + $fromMonth) > (($toYear * 100) + $toMonth)) fail('From month must be before to month', 400);

    $retainedMonths = history_retained_months($db, $fromYear, $fromMonth, $toYear, $toMonth);
    $actualOnlyMonths = history_actual_only_months($db, $from, $to, $retainedMonths);
    if (!$retainedMonths && !$actualOnlyMonths) fail('No months found in selected range', 404);

    $incomeByMonth = report_income_events_by_month($db, $from, $to);
    $today = effective_today();
    $currentMonthKey = substr($today, 0, 7);
    $months = [];

    if ($retainedMonths) {
        $report = get_report_data($db, $retainedMonths[0], $retainedMonths[count($retainedMonths) - 1]);
        $model = build_pdf_report_model($db, $report);
        foreach ($model['months'] as $month) {
            $key = sprintf('%04d-%02d', (int)$month['year'], (int)$month['month']);
            $income = $incomeByMonth[$key] ?? [];
            $entry = [
                'year' => (int)$month['year'],
                'month' => (int)$month['month'],
                'label' => (string)$month['label'],
                'period' => (string)$month['period'],
                'cutoff' => (string)$month['cutoff'],
                'coverage' => 'full',
                'overview' => [
                    'planned_allocations' => (float)$month['overview']['planned_allocations'],
                    'actual_expenses' => (float)$month['overview']['actual_expenses'],
                ],
                'daily' => $month['daily'],
                'reserved' => $month['reserved'],
                'savings' => $month['savings'],
                'income' => [
                    'total' => (float)array_sum(array_column($income, 'amount')),
                    'events' => $income,
                ],
            ];
            if ($key === $currentMonthKey) {
                $entry['safe_to_spend'] = history_safe_to_spend($db, (int)$month['year'], (int)$month['month'], $today);
            }
            $months[] = $entry;
        }
    }

    if ($actualOnlyMonths) {
        $actuals = funding_actual_spending_by_month($db, $from, $to);
        $envelopes = history_envelope_roles($db);
        foreach ($actualOnlyMonths as $monthKey) {
            $month = history_actual_only_month($envelopes, $actuals, $monthKey, $incomeByMonth[$monthKey] ?? []);
            if ($monthKey === $currentMonthKey) {
                $month['safe_to_spend'] = history_safe_to_spend($db, (int)$month['year'], (int)$month['month'], $today);
            }
            $months[] = $month;
        }
    }

    usort($months, static fn(array $a, array $b): int => [$a['year'], $a['month']] <=> [$b['year'], $b['month']]);

    return [
        'from' => sprintf('%04d-%02d', $fromYear, $fromMonth),
        'to' => sprintf('%04d-%02d', $toYear, $toMonth),
        'currency' => currency_context($db)['currency'],
        'months' => $months,
        'savings_goals' => history_savings_goals($db),
    ];
}

/**
 * The current Month's safe-to-spend figure with every input and intermediate
 * result, so an external client can reproduce the number by hand from the
 * response alone.
 *
 * Only Daily spend envelopes contribute: their persistent balances as of today
 * are summed, spread over the days that remain in the Month (today included),
 * and rounded down to a whole 10 — the same rule the Today screen and the PDF
 * report use. A Reserved or Savings balance never contributes, no matter how
 * it is presented on Today.
 *
 * `daily_envelopes` lists the contributing envelopes in the order their
 * balances were summed, so `daily_total` is the sum of exactly those values.
 * `zero_reason` names the condition that produced a 0.
 */
function history_safe_to_spend(PDO $db, int $year, int $month, string $today): array
{
    $balances = envelope_balances_as_of($db, $today);
    $rows = $db->query('SELECT id, name, role FROM envelopes ORDER BY sort_order, name')->fetchAll();
    $envelopes = [];
    $dailyTotal = 0.0;
    foreach ($rows as $row) {
        $role = in_array((string)$row['role'], ['daily', 'reserved', 'savings'], true) ? (string)$row['role'] : 'daily';
        if ($role !== 'daily') continue;
        $balance = (float)($balances[(int)$row['id']] ?? 0.0);
        $dailyTotal += $balance;
        $envelopes[] = ['envelope_id' => (int)$row['id'], 'envelope' => (string)$row['name'], 'balance' => $balance];
    }

    $safe = report_safe_to_spend($dailyTotal, $year, $month, $today);
    return [
        'amount' => $safe['amount'],
        'label' => $safe['label'],
        'as_of' => $today,
        'daily_total' => $dailyTotal,
        'daily_envelopes' => $envelopes,
        'days_in_month' => $safe['total_days'],
        'remaining_days' => $safe['remaining_days'],
        'raw_per_day' => $safe['raw_per_day'],
        'rounded_amount' => $safe['rounded_amount'],
        'rounding' => 'floor(raw_per_day / 10) * 10, then max(0, rounded_amount)',
        'zero_reason' => $safe['zero_reason'],
    ];
}

/**
 * The retained Month rows inside the requested range, as YYYY-MM keys.
 */
function history_retained_months(PDO $db, int $fromYear, int $fromMonth, int $toYear, int $toMonth): array
{
    $stmt = $db->prepare('SELECT printf("%04d-%02d", year, month) AS month_key FROM months
        WHERE ((year > ?) OR (year = ? AND month >= ?)) AND ((year < ?) OR (year = ? AND month <= ?))
        ORDER BY year, month');
    $stmt->execute([$fromYear, $fromYear, $fromMonth, $toYear, $toYear, $toMonth]);
    return array_map(static fn(array $row): string => (string)$row['month_key'], $stmt->fetchAll());
}

/**
 * The Months inside the requested range that exist only as actual spending:
 * the Months the funding-recommendation engine already knows because a
 * Transaction was recorded in them. A retained Month is left out, because the
 * report model is authoritative for it.
 */
function history_actual_only_months(PDO $db, string $from, string $to, array $retainedMonths): array
{
    $stmt = $db->prepare("SELECT DISTINCT substr(spent_date,1,7) AS month_key FROM transactions
        WHERE spent_date BETWEEN :from_date AND :to_date AND spent_date <= :today ORDER BY month_key");
    $stmt->execute([
        ':from_date' => $from . '-01',
        ':to_date' => date('Y-m-t', strtotime($to . '-01')),
        ':today' => effective_today(),
    ]);
    $retained = array_flip($retainedMonths);
    $months = [];
    foreach ($stmt->fetchAll() as $row) {
        $key = (string)$row['month_key'];
        if (isset($retained[$key])) continue;
        $months[] = $key;
    }
    return $months;
}

/**
 * Envelope id, name and role, in the report model's order.
 */
function history_envelope_roles(PDO $db): array
{
    $rows = $db->query('SELECT id, name, role FROM envelopes ORDER BY sort_order, name')->fetchAll();
    return array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'role' => in_array((string)$row['role'], ['daily', 'reserved', 'savings'], true) ? (string)$row['role'] : 'daily',
    ], $rows);
}

/**
 * One Month of actual spending only.
 *
 * A Month outside the retained running data has no Plan, no Funding and no
 * Balance, so only the per-envelope actual spending and the Month's actual
 * Income are reported.
 */
function history_actual_only_month(array $envelopes, array $actuals, string $monthKey, array $incomeEvents): array
{
    [$year, $month] = array_map('intval', explode('-', $monthKey));
    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd = date('Y-m-t', strtotime($monthStart));
    $today = effective_today();
    $cutoff = min($monthEnd, $today);
    $period = $monthStart > $today ? 'future' : ($monthEnd < $today ? 'past' : 'current');

    $rowsByRole = ['daily' => [], 'reserved' => [], 'savings' => []];
    foreach ($envelopes as $envelope) {
        $actual = funding_actual_for_envelope($actuals, (int)$envelope['id'], (string)$envelope['name'], $monthKey);
        if (abs($actual) < 0.005) continue;
        $rowsByRole[$envelope['role']][] = [
            'envelope_id' => (int)$envelope['id'],
            'envelope' => (string)$envelope['name'],
            'actual' => $actual,
        ];
    }

    $tables = [];
    $actualExpenses = 0.0;
    foreach (['daily', 'reserved', 'savings'] as $role) {
        $total = (float)array_sum(array_column($rowsByRole[$role], 'actual'));
        $actualExpenses += $total;
        $tables[$role] = ['rows' => $rowsByRole[$role], 'totals' => ['actual' => $total]];
    }

    return [
        'year' => $year,
        'month' => $month,
        'label' => month_label(['year' => $year, 'month' => $month]),
        'period' => $period,
        'cutoff' => $cutoff,
        'coverage' => 'actual_only',
        'overview' => ['actual_expenses' => $actualExpenses],
        'daily' => $tables['daily'],
        'reserved' => $tables['reserved'],
        'savings' => $tables['savings'],
        'income' => [
            'total' => (float)array_sum(array_column($incomeEvents, 'amount')),
            'events' => $incomeEvents,
        ],
    ];
}

/**
 * Savings goals: the target amount and the progress derived from the
 * Envelope's persistent Balance. FIN stores no goal deadline, so none is
 * reported.
 */
function history_savings_goals(PDO $db): array
{
    $balances = envelope_balances_as_of($db, effective_today());
    $stmt = $db->prepare("SELECT id, name, savings_goal FROM envelopes WHERE role='savings' AND savings_goal > 0 ORDER BY sort_order, name");
    $stmt->execute();
    $goals = [];
    foreach ($stmt->fetchAll() as $row) {
        $goal = (float)$row['savings_goal'];
        $balance = (float)($balances[(int)$row['id']] ?? 0.0);
        $goals[] = [
            'envelope_id' => (int)$row['id'],
            'envelope' => (string)$row['name'],
            'goal' => $goal,
            'balance' => $balance,
            'progress_percent' => round(max(0.0, ($balance / $goal) * 100), 2),
        ];
    }
    return $goals;
}

function parse_month_key(string $value): array
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $value, $m)) fail('Month must be YYYY-MM', 400);
    $year = (int)$m[1];
    $month = (int)$m[2];
    if ($year < 1900 || $year > 2200 || $month < 1 || $month > 12) fail('Invalid month range', 400);
    return [$year, $month];
}

function report_month_summary(array $month): array
{
    $income = (float)$month['salary'];
    $expenses = 0.0;
    $handled = 0.0;
    foreach ($month['entries'] as $entry) {
        if (($entry['status'] ?? '') === 'skipped') continue;
        if (($entry['type'] ?? '') === 'income') $income += (float)$entry['amount'];
        else $expenses += (float)$entry['amount'];
        if (($entry['status'] ?? '') === 'paid') $handled += max(0.0, (float)$entry['amount']);
    }
    $actual = array_sum(array_map(static fn(array $tx): float => transaction_spending_amount($tx), $month['transactions']));
    return [
        'income' => $income,
        'expenses' => $expenses,
        'expected_leftover' => $income - $expenses,
        'handled' => $handled,
        'actual_spent' => $actual,
    ];
}

function export_report_xlsx(array $report): never
{
    $filename = 'fin-report-' . $report['from'] . '-to-' . $report['to'] . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo build_report_xlsx($report);
    exit;
}

function build_report_xlsx(array $report): string
{
    $rows = [];
    foreach ($report['months'] as $month) {
        $s = $month['summary'];
        $rate = (float)($month['exchange_rate'] ?? 0);
        $rateCell = $rate > 0 ? ['v' => $rate, 's' => 2] : '';
        $rows[] = [['v' => month_label($month), 's' => 1], '', '', 1, '=', $rateCell];
        $rows[] = ['Income', '', ['v' => (float)$s['income'], 's' => 2], '', '', ''];
        // The recorded Income of the month. It exists only for a month with at
        // least one Income event, so a month without one prints no income
        // figure and no income difference at all.
        $recordedIncome = isset($month['recorded_income']) ? (float)$month['recorded_income'] : null;
        if ($recordedIncome !== null) {
            $incomeDifference = report_income_difference($recordedIncome, (float)$s['income']);
            $rows[] = ['Recorded income', '', ['v' => $recordedIncome, 's' => 2], 'Income difference', '', $incomeDifference === null ? '' : ['v' => $incomeDifference, 's' => 2]];
        }
        $rows[] = ['Plan', '', ['v' => (float)$s['expenses'], 's' => 2], 'Plan difference', '', ['v' => (float)$s['expected_leftover'], 's' => 2]];
        $rows[] = ['Actual spending', '', ['v' => (float)$s['actual_spent'], 's' => 2], '', '', ''];
        $rows[] = ['Plan vs Actual difference', '', ['v' => (float)$s['expenses'] - (float)$s['actual_spent'], 's' => 2], '', '', ''];
        $rows[] = [];
        $rows[] = [['v' => 'Name', 's' => 3], ['v' => (string)$report['helper_currency'], 's' => 3], ['v' => (string)$report['currency'], 's' => 3], ['v' => 'x', 's' => 3], ['v' => 'Account / note', 's' => 3], ['v' => 'Section', 's' => 3]];
        foreach ($month['entries'] as $entry) {
            $rows[] = [
                (string)$entry['name'],
                $rate > 0 ? ['v' => (float)$entry['amount'] / $rate, 's' => 2] : '',
                ['v' => (float)$entry['amount'], 's' => 2],
                (string)$entry['status'] === 'paid' ? 'x' : '',
                trim((string)($entry['account_name'] ?? '') . ' ' . (string)$entry['note']),
                (string)$entry['category'],
            ];
        }
        $rows[] = [];
        $rows[] = [['v' => 'Actual transactions', 's' => 3], '', '', '', '', ''];
        foreach ($month['transactions'] as $tx) {
            $exception = ($tx['kind'] ?? 'expense') === 'refund' ? 'Refund' : '';
            $helperAmount = $rate > 0 ? ['v' => transaction_spending_amount($tx) / $rate, 's' => 2] : '';
            $rows[] = [report_date_text((string)$tx['spent_date']) . ' · ' . (string)$tx['envelope'], $helperAmount, ['v' => transaction_spending_amount($tx), 's' => 2], '', (string)$tx['note'], $exception];
        }
        if (!empty($month['balance_movements'])) {
            $rows[] = [];
            $rows[] = [['v' => 'Balance movements', 's' => 3], '', '', '', '', ''];
            foreach ($month['balance_movements'] as $movement) {
                $rows[] = [report_date_text((string)$movement['movement_date']), (string)$movement['envelope_name'], ['v' => (float)$movement['amount'], 's' => 2], (string)$movement['type'], (string)$movement['note'], ''];
            }
        }
        $rows[] = [];
        $rows[] = [];
    }

    $sheetXml = worksheet_xml($rows);
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => xlsx_styles_xml(),
        'xl/worksheets/sheet1.xml' => $sheetXml,
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>FIN report</dc:title></cp:coreProperties>',
        'docProps/app.xml' => '<?xml version="1.0" encoding="UTF-8"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>FIN</Application></Properties>',
    ];
    return zip_files($files);
}

function worksheet_xml(array $rows): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="1" width="24" customWidth="1"/><col min="2" max="3" width="13" customWidth="1"/><col min="4" max="4" width="6" customWidth="1"/><col min="5" max="6" width="22" customWidth="1"/></cols><sheetData>';
    foreach ($rows as $r => $row) {
        $rowNum = $r + 1;
        $xml .= '<row r="' . $rowNum . '">';
        foreach ($row as $c => $cell) {
            if ($cell === '' || $cell === null) continue;
            $value = is_array($cell) ? ($cell['v'] ?? '') : $cell;
            $style = is_array($cell) ? (int)($cell['s'] ?? 0) : 0;
            $ref = xlsx_col($c + 1) . $rowNum;
            if (is_int($value) || is_float($value)) $xml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $value . '</v></c>';
            else $xml .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t>' . xml_escape((string)$value) . '</t></is></c>';
        }
        $xml .= '</row>';
    }
    return $xml . '</sheetData></worksheet>';
}

function xlsx_styles_xml(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="12"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFD9EAD3"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/></border></borders><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" applyFont="1" applyFill="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" applyFont="1" applyFill="1"/></cellXfs></styleSheet>';
}

function zip_files(array $files): string
{
    $data = '';
    $central = '';
    $offset = 0;
    foreach ($files as $name => $body) {
        $crc = (int)sprintf('%u', crc32($body));
        $size = strlen($body);
        $nameLen = strlen($name);
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLen, 0) . $name . $body;
        $data .= $local;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLen, 0, 0, 0, 0, 0, $offset) . $name;
        $offset += strlen($local);
    }
    return $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($data), 0);
}

function xlsx_col(int $n): string
{
    $s = '';
    while ($n > 0) {
        $n--;
        $s = chr(65 + ($n % 26)) . $s;
        $n = intdiv($n, 26);
    }
    return $s;
}

function month_label(array $month): string
{
    $names = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    return $names[(int)$month['month']] . ' ' . $month['year'];
}

function xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function valid_date(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function first_day_of_month(int $year, int $month): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-n-j', sprintf('%d-%d-1', $year, $month));
    if (!$date) throw new InvalidArgumentException('Invalid month');
    return $date->format('Y-m-d');
}

function retention_plan(PDO $db, int $keepMonths = 12): array
{
    if ($keepMonths < 1 || $keepMonths > 120) throw new InvalidArgumentException('Keep months must be between 1 and 120');
    $months = $db->query('SELECT id, year, month FROM months ORDER BY year DESC, month DESC')->fetchAll();
    $retained = array_slice($months, 0, $keepMonths);
    $old = array_slice($months, $keepMonths);
    $empty = [
        'keep_months' => $keepMonths,
        'total_months' => count($months),
        'retained_months' => count($retained),
        'protected_future_months' => 0,
        'protected_month_labels' => [],
        'cutoff' => null,
        'archive_through' => null,
        'months' => 0,
        'entries' => 0,
        'transactions' => 0,
        'movements' => 0,
        'account_transfers' => 0,
        'transfers' => 0,
        'month_labels' => [],
        '_old_month_ids' => [],
        '_movement_ids' => [],
        '_transfer_ids' => [],
        '_oldest_retained_month_id' => null,
    ];
    if (!$old || !$retained) return $empty;

    $oldestRetained = end($retained);
    $cutoff = sprintf('%04d-%02d-01', (int)$oldestRetained['year'], (int)$oldestRetained['month']);
    $archiveThrough = (new DateTimeImmutable($cutoff))->modify('-1 day')->format('Y-m-d');
    $candidateOldIds = array_map(fn(array $month): int => (int)$month['id'], $old);
    $candidateList = implode(',', $candidateOldIds);
    $quotedCutoff = $db->quote($cutoff);
    $protectedIds = array_map('intval', $db->query("SELECT DISTINCT source_month_id FROM (
            SELECT em.month_id AS source_month_id FROM envelope_movements em WHERE em.month_id IN ($candidateList) AND em.movement_date > $quotedCutoff
            UNION
            SELECT e.month_id AS source_month_id FROM envelope_movements em JOIN entries e ON e.id = em.entry_id WHERE e.month_id IN ($candidateList) AND em.movement_date > $quotedCutoff
            UNION
            SELECT t.month_id AS source_month_id FROM envelope_movements em JOIN transactions t ON t.id = em.transaction_id WHERE t.month_id IN ($candidateList) AND em.movement_date > $quotedCutoff
        ) WHERE source_month_id IS NOT NULL ORDER BY source_month_id")->fetchAll(PDO::FETCH_COLUMN));
    $protectedSet = array_fill_keys($protectedIds, true);
    $protected = array_values(array_filter($old, fn(array $month): bool => isset($protectedSet[(int)$month['id']])));
    $old = array_values(array_filter($old, fn(array $month): bool => !isset($protectedSet[(int)$month['id']])));
    $oldMonthIds = array_map(fn(array $month): int => (int)$month['id'], $old);
    if (!$oldMonthIds) {
        return array_merge($empty, [
            'retained_months' => count($retained) + count($protected),
            'protected_future_months' => count($protected),
            'protected_month_labels' => array_map(fn(array $month): string => sprintf('%04d-%02d', (int)$month['year'], (int)$month['month']), array_reverse($protected)),
            'cutoff' => $cutoff,
            'archive_through' => $archiveThrough,
        ]);
    }
    $monthList = implode(',', $oldMonthIds);
    $movementCondition = "(em.movement_date < $quotedCutoff
        OR (em.month_id IS NOT NULL AND em.month_id IN ($monthList))
        OR (em.entry_id IS NOT NULL AND em.entry_id IN (SELECT id FROM entries WHERE month_id IN ($monthList)))
        OR (em.transaction_id IS NOT NULL AND em.transaction_id IN (SELECT id FROM transactions WHERE month_id IN ($monthList))))";
    $movementIds = array_map('intval', $db->query("SELECT em.id FROM envelope_movements em WHERE $movementCondition ORDER BY em.id")->fetchAll(PDO::FETCH_COLUMN));
    $transferIds = array_map('intval', $db->query("SELECT t.id FROM envelope_transfers t
        WHERE t.transfer_date < $quotedCutoff
          AND NOT EXISTS (SELECT 1 FROM envelope_movements em WHERE em.transfer_id = t.id AND NOT $movementCondition)
        ORDER BY t.id")->fetchAll(PDO::FETCH_COLUMN));

    return [
        'keep_months' => $keepMonths,
        'total_months' => count($months),
        'retained_months' => count($retained) + count($protected),
        'protected_future_months' => count($protected),
        'protected_month_labels' => array_map(fn(array $month): string => sprintf('%04d-%02d', (int)$month['year'], (int)$month['month']), array_reverse($protected)),
        'cutoff' => $cutoff,
        'archive_through' => $archiveThrough,
        'months' => count($oldMonthIds),
        'entries' => (int)$db->query("SELECT COUNT(*) FROM entries WHERE month_id IN ($monthList)")->fetchColumn(),
        'transactions' => (int)$db->query("SELECT COUNT(*) FROM transactions WHERE spent_date < $quotedCutoff")->fetchColumn(),
        'movements' => count($movementIds),
        'transfers' => count($transferIds),
        'month_labels' => array_map(fn(array $month): string => sprintf('%04d-%02d', (int)$month['year'], (int)$month['month']), array_reverse($old)),
        '_old_month_ids' => $oldMonthIds,
        '_movement_ids' => $movementIds,
        '_transfer_ids' => $transferIds,
        '_oldest_retained_month_id' => (int)$oldestRetained['id'],
    ];
}

function retention_preview(PDO $db, int $keepMonths = 12): array
{
    $preview = retention_plan($db, $keepMonths);
    foreach (array_keys($preview) as $key) {
        if (str_starts_with($key, '_')) unset($preview[$key]);
    }
    $preview['cleanup_required'] = $preview['months'] > 0;
    return $preview;
}

function envelope_balance_snapshot(PDO $db, ?string $asOf = null): array
{
    $balances = envelope_balances_as_of($db, $asOf ?: effective_today());
    ksort($balances);
    return $balances;
}

function account_position_snapshot(PDO $db, ?string $asOf = null, string $amountColumn = 'amount'): array
{
    $asOf = $asOf ?: effective_today();
    if (!in_array($amountColumn, ['amount', 'amount_dkk'], true)) throw new InvalidArgumentException('Invalid Account amount column');
    $positions = [];
    $hasObservations = (bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='account_balance_observations'")->fetchColumn();
    if ($hasObservations && $amountColumn === 'amount') {
        foreach ($db->query('SELECT id FROM accounts ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $positions[(int)$id] = account_balance_as_of($db, (int)$id, $asOf);
        }
        return $positions;
    }
    $stmt = $db->prepare("SELECT a.id,COALESCE(SUM(CASE WHEN am.movement_date<=:as_of THEN am.$amountColumn ELSE 0 END),0) AS position FROM accounts a LEFT JOIN account_movements am ON am.account_id=a.id GROUP BY a.id ORDER BY a.id");
    $stmt->execute([':as_of' => $asOf]);
    foreach ($stmt->fetchAll() as $row) $positions[(int)$row['id']] = (float)$row['position'];
    return $positions;
}

function delete_ids(PDO $db, string $table, array $ids): void
{
    if (!$ids) return;
    foreach (array_chunk(array_map('intval', $ids), 500) as $chunk) {
        $db->exec("DELETE FROM $table WHERE id IN (" . implode(',', $chunk) . ')');
    }
}

function apply_retention_plan(PDO $db, array $plan): array
{
    if (empty($plan['_old_month_ids'])) return retention_preview($db, (int)$plan['keep_months']);
    $before = envelope_balance_snapshot($db);
    $accountPositionsBefore = account_position_snapshot($db);
    $movementIds = $plan['_movement_ids'];
    $rollups = [];
    if ($movementIds) {
        $movementList = implode(',', array_map('intval', $movementIds));
        $archiveThrough = $db->quote((string)$plan['archive_through']);
        $rollups = $db->query("SELECT em.envelope_id, COALESCE(SUM(em.amount), 0) AS amount
            FROM envelope_movements em
            LEFT JOIN envelope_balance_observations o ON o.id=(
                SELECT latest.id FROM envelope_balance_observations latest
                WHERE latest.envelope_id=em.envelope_id AND latest.effective_date<=$archiveThrough
                ORDER BY latest.effective_date DESC LIMIT 1
            )
            WHERE em.id IN ($movementList)
              AND (o.id IS NULL OR em.movement_date>o.effective_date)
            GROUP BY em.envelope_id ORDER BY em.envelope_id")->fetchAll();
        $db->exec("UPDATE envelope_movements SET reversal_of_movement_id = NULL
            WHERE reversal_of_movement_id IN ($movementList) AND id NOT IN ($movementList)");
    }

    $insert = $db->prepare("INSERT INTO envelope_movements
        (envelope_id, month_id, type, amount, note, movement_date)
        VALUES (:envelope_id, :month_id, 'archive_rollup', :amount, :note, :movement_date)");
    foreach ($rollups as $rollup) {
        $amount = (float)$rollup['amount'];
        if (abs($amount) < 0.0000001) continue;
        $insert->execute([
            ':envelope_id' => (int)$rollup['envelope_id'],
            ':month_id' => (int)$plan['_oldest_retained_month_id'],
            ':amount' => $amount,
            ':note' => 'Archived balance brought forward through ' . $plan['archive_through'],
            ':movement_date' => $plan['cutoff'],
        ]);
    }

    delete_ids($db, 'envelope_movements', $movementIds);
    $transactionIds = array_map('intval', $db->query('SELECT id FROM transactions WHERE spent_date < ' . $db->quote($plan['cutoff']) . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    if ($transactionIds) {
        foreach (array_chunk($transactionIds, 500) as $chunk) {
            $db->exec('UPDATE account_events SET transaction_id=NULL,updated_at=CURRENT_TIMESTAMP WHERE transaction_id IN (' . implode(',', $chunk) . ')');
        }
    }
    delete_ids($db, 'transactions', $transactionIds);
    delete_ids($db, 'entries', array_map('intval', $db->query('SELECT id FROM entries WHERE month_id IN (' . implode(',', $plan['_old_month_ids']) . ')')->fetchAll(PDO::FETCH_COLUMN)));
    delete_ids($db, 'envelope_transfers', $plan['_transfer_ids']);
    delete_ids($db, 'months', $plan['_old_month_ids']);

    $after = envelope_balance_snapshot($db);
    if (array_keys($before) !== array_keys($after)) throw new RuntimeException('Envelope set changed during cleanup');
    foreach ($before as $envelopeId => $balance) {
        if (abs($balance - $after[$envelopeId]) > 0.00001) {
            throw new RuntimeException("Envelope $envelopeId balance changed during cleanup");
        }
    }
    $accountPositionsAfter = account_position_snapshot($db);
    if (array_keys($accountPositionsBefore) !== array_keys($accountPositionsAfter)) throw new RuntimeException('Account set changed during cleanup');
    foreach ($accountPositionsBefore as $accountId => $position) {
        $afterPosition = $accountPositionsAfter[$accountId];
        if (($position === null) !== ($afterPosition === null)
            || ($position !== null && abs($position - $afterPosition) > 0.00001)) {
            throw new RuntimeException("Account $accountId position changed during cleanup");
        }
    }
    if ($db->query('PRAGMA foreign_key_check')->fetch()) throw new RuntimeException('Foreign-key verification failed after cleanup');

    $result = retention_preview($db, (int)$plan['keep_months']);
    $result['deleted'] = [
        'months' => $plan['months'],
        'entries' => $plan['entries'],
        'transactions' => $plan['transactions'],
        'movements' => $plan['movements'],
        'transfers' => $plan['transfers'],
    ];
    $result['cutoff'] = $plan['cutoff'];
    $result['archive_through'] = $plan['archive_through'];
    $result['balances_verified'] = true;
    $result['account_positions_verified'] = true;
    return $result;
}

function cleanup_database_history(PDO $db, int $keepMonths = 12): array
{
    $db->exec('BEGIN IMMEDIATE');
    try {
        $plan = retention_plan($db, $keepMonths);
        if (!$plan['_old_month_ids']) {
            $db->exec('ROLLBACK');
            return retention_preview($db, $keepMonths);
        }
        $result = apply_retention_plan($db, $plan);
        $db->exec('COMMIT');
        return $result;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->exec('ROLLBACK');
        throw $e;
    }
}

function sqlite_integrity(PDO $db): void
{
    $result = (string)$db->query('PRAGMA integrity_check')->fetchColumn();
    if (strtolower($result) !== 'ok') throw new RuntimeException('SQLite integrity check failed: ' . $result);
}

function create_sqlite_backup(string $sourcePath, string $targetPath): void
{
    if (!class_exists('SQLite3') || !method_exists('SQLite3', 'backup')) throw new RuntimeException('PHP SQLite3 backup support is required');
    if (!is_file($sourcePath)) throw new RuntimeException('Source database not found: ' . $sourcePath);
    if (file_exists($targetPath)) throw new RuntimeException('Backup already exists: ' . $targetPath);
    $source = null;
    $target = null;
    try {
        $source = new SQLite3($sourcePath, SQLITE3_OPEN_READONLY);
        $source->busyTimeout(10000);
        $target = new SQLite3($targetPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $target->busyTimeout(10000);
        if (!$source->backup($target)) throw new RuntimeException('SQLite backup failed');
        $target->close();
        $target = null;
        $source->close();
        $source = null;
        chmod($targetPath, 0600);
        $check = new PDO('sqlite:' . $targetPath);
        $check->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        sqlite_integrity($check);
    } catch (Throwable $e) {
        if ($target instanceof SQLite3) $target->close();
        if ($source instanceof SQLite3) $source->close();
        @unlink($targetPath);
        throw $e;
    }
}

function default_backup_dir(): string
{
    $configured = trim((string)getenv('FIN_BACKUP_DIR'));
    if ($configured !== '') return $configured;
    $home = trim((string)getenv('HOME'));
    if ($home === '') throw new RuntimeException('Set HOME or FIN_BACKUP_DIR for backup storage');
    return rtrim($home, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'fin-backups';
}

function secure_backup_directory(?string $backupDir = null): string
{
    $backupDir = rtrim($backupDir ?: default_backup_dir(), DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR;
    if (is_link($backupDir)) throw new RuntimeException('Backup directory cannot be a symbolic link: ' . $backupDir);
    if (file_exists($backupDir) && !is_dir($backupDir)) throw new RuntimeException('Backup path is not a directory: ' . $backupDir);
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        throw new RuntimeException('Could not create backup directory: ' . $backupDir);
    }
    if (!chmod($backupDir, 0700) && ((fileperms($backupDir) ?: 0) & 0777) !== 0700) {
        throw new RuntimeException('Could not secure backup directory permissions: ' . $backupDir);
    }
    return $backupDir;
}

function create_refresh_snapshot(string $sourcePath, ?string $backupDir = null): array
{
    $backupDir = secure_backup_directory($backupDir);
    $resolvedBackupDir = realpath($backupDir);
    $appRoot = realpath(__DIR__);
    if ($resolvedBackupDir === false || $appRoot === false) throw new RuntimeException('Could not resolve refresh snapshot paths');
    if ($resolvedBackupDir === $appRoot || str_starts_with($resolvedBackupDir, $appRoot . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Refresh snapshots must be stored outside the FIN web root');
    }
    $stamp = gmdate('Ymd-His');
    $backupPath = $backupDir . DIRECTORY_SEPARATOR . 'fin-prod-dev-refresh-' . $stamp . '-' . bin2hex(random_bytes(6)) . '.sqlite';
    try {
        create_sqlite_backup($sourcePath, $backupPath);
        $checksum = hash_file('sha256', $backupPath);
        if (!is_string($checksum) || !preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            throw new RuntimeException('Could not checksum refresh snapshot');
        }
        $snapshot = new PDO('sqlite:' . $backupPath);
        $snapshot->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $snapshot->exec('PRAGMA query_only = ON');
        return [
            'snapshot_file' => $backupPath,
            'sha256' => $checksum,
            'app_version' => app_version(),
            'database_version' => database_version($snapshot),
        ];
    } catch (Throwable $error) {
        @unlink($backupPath);
        throw $error;
    }
}

function archive_and_cleanup_database(PDO $db, int $keepMonths = 12, ?string $backupDir = null): array
{
    $backupDir = secure_backup_directory($backupDir);

    $db->exec('BEGIN IMMEDIATE');
    $backupPath = null;
    $manifestPath = null;
    $backupVerified = false;
    try {
        $plan = retention_plan($db, $keepMonths);
        if (!$plan['_old_month_ids']) {
            $db->exec('ROLLBACK');
            return ['ok' => true, 'message' => 'No cleanup required', 'preview' => retention_preview($db, $keepMonths)];
        }
        sqlite_integrity($db);
        $stamp = gmdate('Ymd-His');
        $base = 'fin-archive-before-' . $plan['cutoff'] . '-' . $stamp;
        $backupPath = $backupDir . DIRECTORY_SEPARATOR . $base . '.sqlite';
        if (file_exists($backupPath)) $backupPath = $backupDir . DIRECTORY_SEPARATOR . $base . '-' . getmypid() . '.sqlite';
        create_sqlite_backup(DB_FILE, $backupPath);
        $archiveDb = new PDO('sqlite:' . $backupPath);
        $archiveDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ((int)$archiveDb->query('SELECT COUNT(*) FROM months')->fetchColumn() !== (int)$plan['total_months']) {
            throw new RuntimeException('Archive verification found an unexpected month count');
        }
        $sourceBalances = envelope_balance_snapshot($db);
        $archiveBalances = envelope_balance_snapshot($archiveDb);
        if (array_keys($sourceBalances) !== array_keys($archiveBalances)) throw new RuntimeException('Archive verification found an unexpected envelope set');
        foreach ($sourceBalances as $envelopeId => $balance) {
            if (abs($balance - $archiveBalances[$envelopeId]) > 0.00001) throw new RuntimeException("Archive balance verification failed for envelope $envelopeId");
        }
        $checksum = hash_file('sha256', $backupPath);
        if ($checksum === false) throw new RuntimeException('Could not checksum archive backup');
        $manifestPath = $backupPath . '.manifest.json';
        $manifest = [
            'created_at' => gmdate(DATE_ATOM),
            'backup_file' => basename($backupPath),
            'sha256' => $checksum,
            'app_version' => app_version(),
            'database_version' => database_version($db),
            'preview' => retention_preview($db, $keepMonths),
        ];
        if (file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Could not write archive manifest');
        }
        chmod($manifestPath, 0600);
        $backupVerified = true;
        $result = apply_retention_plan($db, $plan);
        $db->exec('COMMIT');
        sqlite_integrity($db);
        $vacuumWarning = null;
        try {
            $db->exec('VACUUM');
        } catch (Throwable $e) {
            $vacuumWarning = $e->getMessage();
        }
        return [
            'ok' => true,
            'backup_file' => $backupPath,
            'manifest_file' => $manifestPath,
            'sha256' => $checksum,
            'cleanup' => $result,
            'vacuum_warning' => $vacuumWarning,
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->exec('ROLLBACK');
        if (!$backupVerified) {
            if ($manifestPath !== null) @unlink($manifestPath);
            if ($backupPath !== null) @unlink($backupPath);
        }
        throw $e;
    }
}

function cli_retention_options(array $argv): array
{
    $keepMonths = 12;
    $backupDir = null;
    for ($i = 2; $i < count($argv); $i++) {
        $argument = (string)$argv[$i];
        if (preg_match('/^--keep-months=(\d+)$/', $argument, $match)) {
            $keepMonths = (int)$match[1];
        } elseif (str_starts_with($argument, '--backup-dir=')) {
            $backupDir = substr($argument, strlen('--backup-dir='));
        } else {
            throw new InvalidArgumentException('Unknown option: ' . $argument);
        }
    }
    if ($keepMonths < 1 || $keepMonths > 120) throw new InvalidArgumentException('Keep months must be between 1 and 120');
    if ($backupDir !== null && trim($backupDir) === '') throw new InvalidArgumentException('Backup directory cannot be empty');
    return [$keepMonths, $backupDir];
}

function cli_refresh_snapshot_options(array $argv): ?string
{
    $backupDir = null;
    for ($i = 2; $i < count($argv); $i++) {
        $argument = (string)$argv[$i];
        if (str_starts_with($argument, '--backup-dir=')) {
            $backupDir = substr($argument, strlen('--backup-dir='));
        } else {
            throw new InvalidArgumentException('Unknown option: ' . $argument);
        }
    }
    if ($backupDir !== null && trim($backupDir) === '') throw new InvalidArgumentException('Backup directory cannot be empty');
    return $backupDir;
}

function cli_read_master_pin(bool $fromStdin): array
{
    if ($fromStdin) {
        $first = fgets(STDIN);
        $second = fgets(STDIN);
        if ($first === false || $second === false) throw new RuntimeException('Provide the PIN and confirmation as two input lines');
        return [rtrim($first, "\r\n"), rtrim($second, "\r\n")];
    }
    if (function_exists('posix_isatty') && !posix_isatty(STDIN)) {
        throw new RuntimeException('An interactive terminal is required; use --stdin only for a protected input stream');
    }
    fwrite(STDERR, 'New Master PIN: ');
    exec('stty -echo 2>/dev/null', $ignored, $sttyStatus);
    if ($sttyStatus !== 0) throw new RuntimeException('Could not disable terminal echo');
    try { $first = fgets(STDIN); } finally { exec('stty echo 2>/dev/null'); fwrite(STDERR, PHP_EOL); }
    fwrite(STDERR, 'Confirm Master PIN: ');
    exec('stty -echo 2>/dev/null', $ignored, $sttyStatus);
    if ($sttyStatus !== 0) throw new RuntimeException('Could not disable terminal echo');
    try { $second = fgets(STDIN); } finally { exec('stty echo 2>/dev/null'); fwrite(STDERR, PHP_EOL); }
    if ($first === false || $second === false) throw new RuntimeException('Could not read Master PIN');
    return [rtrim($first, "\r\n"), rtrim($second, "\r\n")];
}

function cli_require_argument_count(array $argv, int $expected, string $usage): void
{
    if (count($argv) !== $expected) throw new InvalidArgumentException('Usage: php api.php ' . $usage);
}

function cli_read_local_file(string $path, string $label, int $maximumBytes): string
{
    if ($path === '' || !is_file($path) || !is_readable($path)) throw new InvalidArgumentException("$label file is not readable: $path");
    $size = filesize($path);
    if ($size === false || $size <= 0 || $size > $maximumBytes) throw new InvalidArgumentException("$label file must be non-empty and no larger than $maximumBytes bytes");
    $contents = file_get_contents($path);
    if (!is_string($contents) || $contents === '') throw new InvalidArgumentException("$label file could not be read");
    return $contents;
}

function cli_load_import_profile_file(string $path): array
{
    $contents = cli_read_local_file($path, 'Import Profile JSON', STATEMENT_IMPORT_PROFILE_JSON_MAX_BYTES);
    if (preg_match('//u', $contents) !== 1) throw new InvalidArgumentException('Import Profile JSON must be valid UTF-8');
    try {
        $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new InvalidArgumentException('Import Profile JSON is invalid: ' . $error->getMessage(), 0, $error);
    }
    if (!is_array($decoded) || array_is_list($decoded)) throw new InvalidArgumentException('Import Profile JSON must be an object');
    return statement_import_profile_validate_transport($decoded);
}

function cli_import_profile_id(string $value): int
{
    if (!ctype_digit($value) || (int)$value <= 0) throw new InvalidArgumentException('Import Profile ID must be a positive integer');
    return (int)$value;
}

function cli_print_json(array $value): void
{
    echo json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
}

function cli_import_profile_summary(array $profile, ?bool $referenced = null): array
{
    $summary = [
        'id' => (int)$profile['id'],
        'name' => (string)$profile['name'],
        'state' => $profile['enabled'] ? 'Enabled' : 'Disabled',
        'evidence_namespace' => (string)$profile['evidence_namespace'],
        'schema_version' => (int)$profile['schema_version'],
        'signature' => substr((string)$profile['signature_hash'], 0, 12),
    ];
    if ($referenced !== null) $summary['referenced'] = $referenced;
    return $summary;
}

function run_cli(array $argv): never
{
    $command = $argv[1] ?? '';
    $config = app_config();
    if ($command === 'set-master-pin') {
        $unknown = array_values(array_filter(array_slice($argv, 2), static fn(string $argument): bool => $argument !== '--stdin'));
        if ($unknown !== []) throw new InvalidArgumentException('Unknown option: ' . $unknown[0]);
        [$pin, $confirmation] = cli_read_master_pin(in_array('--stdin', $argv, true));
        if (!hash_equals($pin, $confirmation)) throw new RuntimeException('Master PIN confirmation does not match');
        set_master_pin($config, $pin);
        echo 'Master PIN file: ' . master_pin_file($config) . PHP_EOL;
        echo 'Master PIN configured: yes' . PHP_EOL;
        exit(0);
    }
    if ($command === 'master-pin-status') {
        $unknown = array_values(array_filter(array_slice($argv, 2), static fn(string $argument): bool => $argument !== '--require'));
        if ($unknown !== []) throw new InvalidArgumentException('Unknown option: ' . $unknown[0]);
        echo 'Master PIN file: ' . master_pin_file($config) . PHP_EOL;
        $configured = master_pin_configured($config);
        echo 'Master PIN configured: ' . ($configured ? 'yes' : 'no') . PHP_EOL;
        if (in_array('--require', $argv, true) && !$configured) exit(1);
        exit(0);
    }
    if ($command === 'create-api-token') {
        $unknown = array_values(array_slice($argv, 2));
        if ($unknown !== []) throw new InvalidArgumentException('Unknown option: ' . $unknown[0]);
        $token = create_api_token($config);
        echo 'API token file: ' . auth_api_token_file($config) . PHP_EOL;
        echo 'API token: ' . $token . PHP_EOL;
        exit(0);
    }
    if ($command === 'refresh-snapshot') {
        echo json_encode(create_refresh_snapshot(DB_FILE, cli_refresh_snapshot_options($argv)), JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }
    if ($command === 'inspect-statement') {
        cli_require_argument_count($argv, 3, 'inspect-statement FILE');
        $contents = cli_read_local_file((string)$argv[2], 'Statement', STATEMENT_MAX_FILE_BYTES);
        cli_print_json(statement_import_profile_inspect_rows(statement_xlsx_rows($contents)));
        exit(0);
    }
    if ($command === 'validate-import-profile') {
        cli_require_argument_count($argv, 3, 'validate-import-profile PROFILE.json');
        $transport = cli_load_import_profile_file((string)$argv[2]);
        $definition = $transport['definition'];
        cli_print_json([
            'schema_version' => $transport['schema_version'],
            'container' => $definition['signature']['container'],
            'sheet' => $definition['signature']['sheet'],
            'header_fields' => count($definition['signature']['header']),
            'markers' => count($definition['signature']['markers']),
            'amount_mode' => $definition['parser']['amount']['mode'],
            'account_number_mode' => $definition['parser']['account_number']['mode'],
            'classification_mapping' => $definition['parser']['classification'] !== null,
            'signature_hash' => statement_import_profile_signature_hash($definition, $transport['schema_version']),
        ]);
        exit(0);
    }
    if ($command === 'preview-import-profile') {
        cli_require_argument_count($argv, 4, 'preview-import-profile PROFILE.json FILE');
        $transport = cli_load_import_profile_file((string)$argv[2]);
        $contents = cli_read_local_file((string)$argv[3], 'Statement', STATEMENT_MAX_FILE_BYTES);
        cli_print_json(statement_import_profile_preview(statement_xlsx_rows($contents), $transport['definition']));
        exit(0);
    }

    $profileToSave = null;
    if ($command === 'save-import-profile') {
        cli_require_argument_count($argv, 3, 'save-import-profile PROFILE.json');
        $profileToSave = cli_load_import_profile_file((string)$argv[2]);
    }

    $db = db();
    migrate($db, $config);
    if ($command === 'migrate') {
        echo 'Database migrated to v' . database_version($db) . ': ' . DB_FILE . PHP_EOL;
        exit(0);
    }
    if ($command === 'version') {
        echo 'FIN Planner v' . app_version() . ' / database v' . database_version($db) . PHP_EOL;
        exit(0);
    }
    if ($command === 'archive-preview') {
        [$keepMonths] = cli_retention_options($argv);
        echo json_encode(retention_preview($db, $keepMonths), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }
    if ($command === 'archive') {
        [$keepMonths, $backupDir] = cli_retention_options($argv);
        echo json_encode(archive_and_cleanup_database($db, $keepMonths, $backupDir), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }
    if ($command === 'save-import-profile') {
        $id = statement_import_profile_create($db, $profileToSave);
        $profile = statement_import_profile_get($db, $id);
        cli_print_json([
            'saved_profile_id' => $id,
            'name' => $profile['name'],
            'state' => $profile['enabled'] ? 'Enabled' : 'Disabled',
            'evidence_namespace' => $profile['evidence_namespace'],
            'signature_hash' => $profile['signature_hash'],
        ]);
        exit(0);
    }
    if ($command === 'list-import-profiles') {
        cli_require_argument_count($argv, 2, 'list-import-profiles');
        $profiles = [];
        foreach (statement_import_profile_list($db) as $profile) {
            $profiles[] = cli_import_profile_summary($profile, statement_import_profile_is_referenced($db, $profile['id']));
        }
        cli_print_json($profiles);
        exit(0);
    }
    if ($command === 'rename-import-profile') {
        cli_require_argument_count($argv, 4, 'rename-import-profile ID NAME');
        $profile = statement_import_profile_rename($db, cli_import_profile_id((string)$argv[2]), (string)$argv[3]);
        cli_print_json(cli_import_profile_summary($profile));
        exit(0);
    }
    if ($command === 'enable-import-profile' || $command === 'disable-import-profile') {
        cli_require_argument_count($argv, 3, $command . ' ID');
        $profile = statement_import_profile_set_enabled(
            $db,
            cli_import_profile_id((string)$argv[2]),
            $command === 'enable-import-profile'
        );
        cli_print_json(cli_import_profile_summary($profile));
        exit(0);
    }
    if ($command === 'delete-import-profile') {
        cli_require_argument_count($argv, 3, 'delete-import-profile ID');
        $id = cli_import_profile_id((string)$argv[2]);
        statement_import_profile_delete($db, $id);
        cli_print_json(['deleted_profile_id' => $id]);
        exit(0);
    }
    if ($command === 'import-statements') {
        cli_require_argument_count($argv, 3, 'import-statements ACCOUNT_ID');
        $accountId = (int)$argv[2];
        statement_account($db, $accountId);
        $importDir = statement_drop_dir();
        $report = statement_process_dropped_files($db, $importDir, $accountId);
        foreach ($report as $row) {
            $line = str_pad((string)($row['outcome'] ?? ''), 18) . ' ' . (string)($row['filename'] ?? '');
            if (!empty($row['reason'])) $line .= ' — ' . (string)$row['reason'];
            if (!empty($row['destination'])) $line .= ' -> imported/' . (string)$row['destination'];
            echo $line . PHP_EOL;
        }
        if ($report === []) {
            echo 'No files in ' . rtrim($importDir, '/\\') . PHP_EOL;
            exit(0);
        }
        $failures = 0;
        foreach ($report as $row) if (($row['outcome'] ?? '') === 'FAILED') $failures++;
        exit($failures > 0 ? 1 : 0);
    }
    fwrite(STDERR, "Usage: php api.php set-master-pin [--stdin] | master-pin-status [--require] | create-api-token | refresh-snapshot [--backup-dir=PATH] | migrate | version | archive-preview [--keep-months=12] | archive [--keep-months=12] [--backup-dir=PATH] | import-statements ACCOUNT_ID | inspect-statement FILE | validate-import-profile PROFILE.json | preview-import-profile PROFILE.json FILE | save-import-profile PROFILE.json | list-import-profiles | rename-import-profile ID NAME | enable-import-profile ID | disable-import-profile ID | delete-import-profile ID\n");
    exit(1);
}

function encode_json_response(array $data): string
{
    return json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
}

function emit_json_response(array $data, int $status): never
{
    try {
        $body = encode_json_response($data);
    } catch (Throwable $error) {
        error_log('FIN JSON response serialization failed: ' . $error->getMessage());
        $status = 500;
        $body = '{"error":"Response could not be encoded as JSON."}';
    }
    http_response_code($status);
    $GLOBALS['fin_json_response_complete'] = true;
    echo $body;
    exit;
}

function fin_emit_fatal_json_response(): void
{
    if (($GLOBALS['fin_json_response_complete'] ?? false) === true) return;
    $error = error_get_last();
    if (!is_array($error) || !in_array((int)($error['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;

    error_log('FIN fatal API error: ' . (string)($error['message'] ?? 'Unknown fatal error'));
    $bufferLevel = (int)($GLOBALS['fin_json_guard_buffer_level'] ?? 0);
    while (ob_get_level() > $bufferLevel) ob_end_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
    }
    http_response_code(500);
    $GLOBALS['fin_json_response_complete'] = true;
    echo '{"error":"A server error prevented the response from completing."}';
}

function respond(array $data): never
{
    emit_json_response($data, 200);
}

function fail(string $message, int $status): never
{
    emit_json_response(['error' => $message], $status);
}
