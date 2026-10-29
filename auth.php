<?php

declare(strict_types=1);

use lbuchs\WebAuthn\WebAuthn;

const FIN_MASTER_PIN_MIN_LENGTH = 8;
const FIN_MASTER_PIN_MAX_LENGTH = 32;
const FIN_AUTH_CHALLENGE_TTL = 120;

/**
 * The only read actions an external client may call with the API token
 * instead of an ordinary FIN session. Every other action keeps requiring
 * the session.
 */
const FIN_API_TOKEN_ACTIONS = ['history', 'transactions', 'transfers', 'fundingRecommendations', 'accountBalances'];

/**
 * The only write action an external client may call with the API token
 * instead of an ordinary FIN session. It is deliberately a single, narrow
 * Plan write; every other POST keeps requiring the session.
 */
const FIN_API_TOKEN_WRITE_ACTIONS = ['setPlanAmounts'];

function auth_normalize_absolute_path(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '' || $path[0] !== '/') throw new RuntimeException('Authentication path must be absolute');
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }
    return '/' . implode('/', $parts);
}

function auth_path_is_inside_app(string $path): bool
{
    $app = rtrim((string)realpath(__DIR__), DIRECTORY_SEPARATOR);
    $normalized = auth_normalize_absolute_path($path);
    if ($app !== '' && ($normalized === $app || str_starts_with($normalized, $app . DIRECTORY_SEPARATOR))) return true;
    $directory = realpath(dirname($normalized));
    return $directory !== false && ($directory === $app || str_starts_with($directory . DIRECTORY_SEPARATOR, $app . DIRECTORY_SEPARATOR));
}

function auth_validate_server_path(string $path, string $label): string
{
    try {
        $path = auth_normalize_absolute_path($path);
    } catch (RuntimeException) {
        throw new RuntimeException("$label must be an absolute server path");
    }
    if (auth_path_is_inside_app($path)) throw new RuntimeException("$label must be outside the FIN web root");
    return $path;
}

function fin_auth_config(array $config): array
{
    $auth = $config['auth'] ?? null;
    if (!is_array($auth)) throw new RuntimeException('FIN auth configuration is missing');
    foreach (['master_pin_file', 'throttle_file'] as $key) {
        if (!isset($auth[$key]) || !is_string($auth[$key]) || trim($auth[$key]) === '') {
            throw new RuntimeException("FIN auth.$key is not configured");
        }
    }
    return $auth;
}

function master_pin_file(array $config): string
{
    return auth_validate_server_path(fin_auth_config($config)['master_pin_file'], 'Master PIN file');
}

function auth_throttle_file(array $config): string
{
    return auth_validate_server_path(fin_auth_config($config)['throttle_file'], 'Authentication throttle file');
}

function master_pin_hash(array $config): ?string
{
    $path = master_pin_file($config);
    if (is_link($path)) throw new RuntimeException('Master PIN file cannot be a symbolic link');
    if (!file_exists($path)) return null;
    if (!is_file($path)) throw new RuntimeException('Master PIN file is not a regular file');
    if (!is_readable($path)) throw new RuntimeException('Master PIN file is not readable by the FIN runtime');
    $mode = fileperms($path);
    if ($mode === false || !in_array($mode & 0777, [0600, 0640], true)) {
        throw new RuntimeException('Master PIN file permissions must be 0600 or 0640');
    }
    $size = filesize($path);
    if ($size === false || $size < 20 || $size > 1024) throw new RuntimeException('Master PIN file does not contain a valid password hash');
    $contents = file_get_contents($path);
    if ($contents === false) throw new RuntimeException('Master PIN file could not be read');
    $hash = trim($contents);
    $info = password_get_info($hash);
    if (($info['algo'] ?? null) === null || ($info['algoName'] ?? 'unknown') === 'unknown') {
        throw new RuntimeException('Master PIN file does not contain a valid password hash');
    }
    return $hash;
}

function master_pin_configured(array $config): bool
{
    return master_pin_hash($config) !== null;
}

function validate_master_pin(string $pin): void
{
    $length = strlen($pin);
    if ($length < FIN_MASTER_PIN_MIN_LENGTH || $length > FIN_MASTER_PIN_MAX_LENGTH || !ctype_digit($pin)) {
        throw new InvalidArgumentException('Master PIN must contain 8 to 32 digits');
    }
}

function master_pin_password_hash(string $pin): string
{
    validate_master_pin($pin);
    $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $options = getenv('FIN_TEST_APP_ROOT') === false ? [] : ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1];
    $hash = $options === [] ? password_hash($pin, $algorithm) : password_hash($pin, $algorithm, $options);
    if (!is_string($hash) || $hash === '') throw new RuntimeException('Could not hash Master PIN');
    return $hash;
}

function auth_file_group(array $config): string
{
    return trim((string)(fin_auth_config($config)['file_group'] ?? ''));
}

function auth_apply_group(string $path, array $config): void
{
    $group = auth_file_group($config);
    if ($group === '') return;
    if (function_exists('posix_getgrnam')) {
        $groupInfo = posix_getgrnam($group);
        $currentGroup = filegroup($path);
        if (is_array($groupInfo) && $currentGroup !== false && (int)$groupInfo['gid'] === $currentGroup) return;
    }
    if (!@chgrp($path, $group)) throw new RuntimeException('Could not apply the configured authentication file group');
}

function auth_file_mode(array $config, int $ownerOnly, int $shared): int
{
    return auth_file_group($config) === '' ? $ownerOnly : $shared;
}

function auth_ensure_directory(string $directory, int $mode, array $config): void
{
    if (is_link($directory)) throw new RuntimeException('Authentication directory cannot be a symbolic link');
    if (file_exists($directory) && !is_dir($directory)) throw new RuntimeException('Authentication directory is not a directory');
    if (!is_dir($directory) && !mkdir($directory, $mode, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create authentication directory');
    }
    if (!@chmod($directory, $mode) && ((fileperms($directory) ?: 0) & 0777) !== $mode) {
        throw new RuntimeException('Could not secure authentication directory permissions');
    }
    auth_apply_group($directory, $config);
}

function auth_atomic_write(string $path, string $contents, int $fileMode, int $directoryMode, array $config): void
{
    $directory = dirname($path);
    auth_ensure_directory($directory, $directoryMode, $config);
    if (is_link($path)) throw new RuntimeException('Authentication file cannot be a symbolic link');
    if (file_exists($path) && !is_file($path)) throw new RuntimeException('Authentication file is not a regular file');
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
    $handle = @fopen($temporary, 'x+b');
    if ($handle === false) throw new RuntimeException('Could not create authentication file');
    try {
        if (!chmod($temporary, $fileMode)) throw new RuntimeException('Could not secure authentication file permissions');
        auth_apply_group($temporary, $config);
        if (fwrite($handle, $contents) !== strlen($contents)) throw new RuntimeException('Could not write authentication file');
        fflush($handle);
        if (function_exists('fsync')) fsync($handle);
        fclose($handle);
        $handle = null;
        if (!rename($temporary, $path)) throw new RuntimeException('Could not install authentication file');
        if (!chmod($path, $fileMode)) throw new RuntimeException('Could not secure authentication file permissions');
    } finally {
        if (is_resource($handle)) fclose($handle);
        if (is_file($temporary)) @unlink($temporary);
    }
}

function set_master_pin(array $config, string $pin): void
{
    $hash = master_pin_password_hash($pin);
    $fileMode = auth_file_mode($config, 0600, 0640);
    $directoryMode = auth_file_mode($config, 0700, 0750);
    auth_atomic_write(master_pin_file($config), $hash . PHP_EOL, $fileMode, $directoryMode, $config);
    auth_ensure_directory(dirname(auth_throttle_file($config)), auth_file_mode($config, 0700, 0770), $config);
    reset_master_pin_throttle($config);
}

function auth_throttle_delay(int $failures): int
{
    if ($failures < 5) return 0;
    return min(900, 30 * (2 ** min(5, $failures - 5)));
}

function auth_read_throttle_handle($handle): array
{
    rewind($handle);
    $raw = stream_get_contents($handle);
    $state = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
    if (!is_array($state)) $state = [];
    return [
        'failures' => max(0, (int)($state['failures'] ?? 0)),
        'blocked_until' => max(0, (int)($state['blocked_until'] ?? 0)),
        'last_failed_at' => max(0, (int)($state['last_failed_at'] ?? 0)),
    ];
}

function auth_write_throttle_handle($handle, array $state): void
{
    $encoded = json_encode($state, JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) throw new RuntimeException('Could not encode authentication throttle state');
    rewind($handle);
    if (!ftruncate($handle, 0) || fwrite($handle, $encoded . PHP_EOL) === false) {
        throw new RuntimeException('Could not persist authentication throttle state');
    }
    fflush($handle);
    if (function_exists('fsync')) fsync($handle);
}

function with_auth_throttle(array $config, callable $callback): mixed
{
    $path = auth_throttle_file($config);
    $fileMode = auth_file_mode($config, 0600, 0660);
    auth_ensure_directory(dirname($path), auth_file_mode($config, 0700, 0770), $config);
    if (is_link($path)) throw new RuntimeException('Authentication throttle file cannot be a symbolic link');
    if (file_exists($path)) {
        if (!is_file($path)) throw new RuntimeException('Authentication throttle file is not a regular file');
        $mode = fileperms($path);
        if ($mode === false || ($mode & 0777) !== $fileMode) throw new RuntimeException('Authentication throttle file has unsafe permissions');
        if (!is_readable($path) || !is_writable($path)) throw new RuntimeException('Authentication throttle file is not readable and writable by the FIN runtime');
    }
    $handle = @fopen($path, 'c+b');
    if ($handle === false) throw new RuntimeException('Could not open authentication throttle state');
    if (!chmod($path, $fileMode)) {
        fclose($handle);
        throw new RuntimeException('Could not secure authentication throttle permissions');
    }
    auth_apply_group($path, $config);
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('Could not lock authentication throttle state');
        $state = auth_read_throttle_handle($handle);
        $result = $callback($state);
        auth_write_throttle_handle($handle, $state);
        flock($handle, LOCK_UN);
        return $result;
    } finally {
        fclose($handle);
    }
}

function reset_master_pin_throttle(array $config): void
{
    $path = auth_throttle_file($config);
    if (!file_exists($path)) return;
    with_auth_throttle($config, static function (array &$state): void {
        $state = ['failures' => 0, 'blocked_until' => 0, 'last_failed_at' => 0];
    });
}

function verify_master_pin(array $config, string $pin, ?int $now = null): array
{
    $now ??= time();
    $hash = master_pin_hash($config);
    if ($hash === null) return ['ok' => false, 'retry_after' => 0, 'configured' => false];

    $result = with_auth_throttle($config, static function (array &$state) use ($hash, $pin, $now): array {
        if ($state['blocked_until'] > $now) {
            return ['ok' => false, 'retry_after' => $state['blocked_until'] - $now, 'configured' => true];
        }
        if (password_verify($pin, $hash)) {
            $state = ['failures' => 0, 'blocked_until' => 0, 'last_failed_at' => 0];
            return ['ok' => true, 'retry_after' => 0, 'configured' => true];
        }
        $state['failures']++;
        $state['last_failed_at'] = $now;
        $delay = auth_throttle_delay($state['failures']);
        $state['blocked_until'] = $delay > 0 ? $now + $delay : 0;
        return ['ok' => false, 'retry_after' => $delay, 'configured' => true];
    });

    if (!$result['ok']) {
        $microseconds = max(0, (int)(fin_auth_config($config)['pin_failure_delay_us'] ?? 250000));
        if ($microseconds > 0) usleep($microseconds);
    }
    return $result;
}

/**
 * Absolute path of the API token hash file, or null when the
 * installation does not configure one. Like the Master PIN file it lives
 * outside the FIN web root and outside SQLite.
 */
function auth_api_token_file(array $config): ?string
{
    $path = trim((string)(fin_auth_config($config)['api_token_file'] ?? ''));
    if ($path === '') return null;
    return auth_validate_server_path($path, 'API token file');
}

/**
 * The stored SHA-256 hash of the current API token, or null when no
 * token has been created. An unsafe existing file fails with a
 * configuration diagnostic rather than being treated as absent.
 */
function api_token_hash(array $config): ?string
{
    $path = auth_api_token_file($config);
    if ($path === null) return null;
    if (is_link($path)) throw new RuntimeException('API token file cannot be a symbolic link');
    if (!file_exists($path)) return null;
    if (!is_file($path)) throw new RuntimeException('API token file is not a regular file');
    if (!is_readable($path)) throw new RuntimeException('API token file is not readable by the FIN runtime');
    $mode = fileperms($path);
    if ($mode === false || !in_array($mode & 0777, [0600, 0640], true)) {
        throw new RuntimeException('API token file permissions must be 0600 or 0640');
    }
    $size = filesize($path);
    if ($size === false || $size < 64 || $size > 128) throw new RuntimeException('API token file does not contain a valid token hash');
    $contents = file_get_contents($path);
    if ($contents === false) throw new RuntimeException('API token file could not be read');
    $hash = strtolower(trim($contents));
    if (!preg_match('/^[0-9a-f]{64}$/', $hash)) throw new RuntimeException('API token file does not contain a valid token hash');
    return $hash;
}

/**
 * The Bearer token of the current request, or null when the request carries
 * no usable Authorization header.
 */
function api_token_bearer(?string $authorizationHeader): ?string
{
    if ($authorizationHeader === null) return null;
    if (!preg_match('/^Bearer[ ]+(\S+)$/i', trim($authorizationHeader), $matches)) return null;
    return $matches[1];
}

/**
 * True when the request carries the current API token. The token is compared
 * by SHA-256 with `hash_equals` against the stored hash.
 */
function api_token_matches(array $config, ?string $authorizationHeader): bool
{
    $token = api_token_bearer($authorizationHeader);
    if ($token === null) return false;
    $hash = api_token_hash($config);
    if ($hash === null) return false;
    return hash_equals($hash, hash('sha256', $token));
}

/**
 * True only for a GET request for one of the allow-listed read actions
 * that carries the current API token. It never creates a session, never
 * needs a CSRF token, and is not part of the Master PIN throttle.
 */
function api_token_authorized(array $config, string $method, ?string $action, ?string $authorizationHeader): bool
{
    if (strtoupper($method) !== 'GET') return false;
    if ($action === null || !in_array($action, FIN_API_TOKEN_ACTIONS, true)) return false;
    return api_token_matches($config, $authorizationHeader);
}

/**
 * True only for a POST request for one of the allow-listed write actions
 * that carries the current API token. Like the read gate it never creates a
 * session, never needs a CSRF token, and is not part of the Master PIN
 * throttle.
 */
function api_token_write_authorized(array $config, string $method, ?string $action, ?string $authorizationHeader): bool
{
    if (strtoupper($method) !== 'POST') return false;
    if ($action === null || !in_array($action, FIN_API_TOKEN_WRITE_ACTIONS, true)) return false;
    return api_token_matches($config, $authorizationHeader);
}

/**
 * Creates a new API token, stores only its SHA-256 hash, and returns
 * the token itself exactly once. A new token replaces the stored hash and
 * therefore revokes the previous token.
 */
function create_api_token(array $config): string
{
    $path = auth_api_token_file($config);
    if ($path === null) throw new RuntimeException('FIN auth.api_token_file is not configured');
    $token = fin_base64url_encode(random_bytes(32));
    $fileMode = auth_file_mode($config, 0600, 0640);
    $directoryMode = auth_file_mode($config, 0700, 0750);
    auth_atomic_write($path, hash('sha256', $token) . PHP_EOL, $fileMode, $directoryMode, $config);
    return $token;
}

function fin_base64url_encode(string $binary): string
{
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

function fin_base64url_decode(string $encoded): string
{
    if ($encoded === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $encoded)) {
        throw new InvalidArgumentException('Malformed authentication response');
    }
    $padding = (4 - strlen($encoded) % 4) % 4;
    $decoded = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', $padding), true);
    if ($decoded === false) throw new InvalidArgumentException('Malformed authentication response');
    return $decoded;
}

function auth_principal(PDO $db): array
{
    $principal = $db->query('SELECT user_handle FROM auth_principal WHERE id=1')->fetch();
    if (!$principal || !is_string($principal['user_handle'])) throw new RuntimeException('Installation security principal is unavailable');
    return $principal;
}

function active_webauthn_credentials(PDO $db): array
{
    return $db->query("SELECT id,credential_id,public_key,sign_count,transports,label,created_at,last_used_at FROM webauthn_credentials WHERE revoked_at IS NULL ORDER BY created_at,id")->fetchAll();
}

function authentication_bootstrap_status(PDO $db, array $config): array
{
    $authenticated = function_exists('is_fin_authenticated') && is_fin_authenticated();
    $deviceLoginAvailable = count(active_webauthn_credentials($db)) > 0;
    $pinConfigured = master_pin_configured($config);
    return [
        'principal' => $authenticated ? ['name' => 'owner'] : null,
        'authenticated' => $authenticated,
        'master_pin_configured' => $pinConfigured,
        'device_login_available' => $deviceLoginAvailable,
        'configuration_required' => !$pinConfigured && !$deviceLoginAvailable,
        'csrf_token' => $authenticated ? auth_csrf_token() : null,
    ];
}

function public_webauthn_credentials(PDO $db): array
{
    $rows = $db->query("SELECT id,label,created_at,last_used_at FROM webauthn_credentials WHERE revoked_at IS NULL ORDER BY created_at,id")->fetchAll();
    return array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'label' => (string)$row['label'],
        'created_at' => (string)$row['created_at'],
        'last_used_at' => $row['last_used_at'] === null ? null : (string)$row['last_used_at'],
        'status' => 'enabled',
    ], $rows);
}

function webauthn_library(): void
{
    if (class_exists(WebAuthn::class)) return;
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) throw new RuntimeException('WebAuthn runtime dependency is unavailable');
    require_once $autoload;
}

function webauthn_server(array $config): WebAuthn
{
    webauthn_library();
    $rpId = trim((string)($config['webauthn_rp_id'] ?? 'example.com'));
    if ($rpId === '') throw new RuntimeException('WebAuthn RP ID is not configured');
    return new WebAuthn((string)($config['name'] ?? 'FIN Planner'), $rpId, ['none'], true);
}

function expected_webauthn_origin(array $config): string
{
    $origin = rtrim(trim((string)($config['webauthn_origin'] ?? 'https://example.com')), '/');
    $parts = parse_url($origin);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('WebAuthn origin is not configured correctly');
    }
    $host = strtolower((string)$parts['host']);
    $scheme = strtolower((string)$parts['scheme']);
    $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    if ($scheme !== 'https' && !($scheme === 'http' && $local)) {
        throw new RuntimeException('WebAuthn requires HTTPS outside localhost development');
    }
    return $origin;
}

function validate_webauthn_client_origin(string $clientDataJson, array $config, string $expectedType): void
{
    $data = json_decode($clientDataJson, true);
    if (!is_array($data) || ($data['type'] ?? null) !== $expectedType) throw new RuntimeException('Authentication verification failed');
    if (!isset($data['origin']) || !is_string($data['origin']) || !hash_equals(expected_webauthn_origin($config), rtrim($data['origin'], '/'))) {
        throw new RuntimeException('Authentication verification failed');
    }
    if (($data['crossOrigin'] ?? false) !== false) throw new RuntimeException('Authentication verification failed');
}

function auth_issue_challenge(string $purpose, string $challenge, array $context = [], ?int $now = null): void
{
    $now ??= time();
    $_SESSION['fin_auth_challenges'][$purpose] = [
        'challenge' => fin_base64url_encode($challenge),
        'expires_at' => $now + FIN_AUTH_CHALLENGE_TTL,
        'context' => $context,
    ];
}

function auth_consume_challenge(string $purpose, ?int $now = null): array
{
    $now ??= time();
    $record = $_SESSION['fin_auth_challenges'][$purpose] ?? null;
    unset($_SESSION['fin_auth_challenges'][$purpose]);
    if (!is_array($record) || !isset($record['challenge'], $record['expires_at']) || (int)$record['expires_at'] < $now) {
        throw new RuntimeException('Authentication verification failed');
    }
    return [
        'challenge' => fin_base64url_decode((string)$record['challenge']),
        'context' => is_array($record['context'] ?? null) ? $record['context'] : [],
    ];
}

function auth_csrf_token(): string
{
    if (empty($_SESSION['fin_csrf_token'])) $_SESSION['fin_csrf_token'] = fin_base64url_encode(random_bytes(32));
    return (string)$_SESSION['fin_csrf_token'];
}

function require_auth_csrf(array $payload): void
{
    $expected = (string)($_SESSION['fin_csrf_token'] ?? '');
    $provided = (string)($payload['_csrf'] ?? '');
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        throw new RuntimeException('Security request could not be verified');
    }
}

function complete_fin_authentication(string $method): void
{
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    $_SESSION = [
        'fin_authenticated' => true,
        'fin_csrf_token' => fin_base64url_encode(random_bytes(32)),
    ];
}

function destroy_fin_session(array $config): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $options = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $options['path'] ?: '/',
            'domain' => $options['domain'] ?: '',
            'secure' => (bool)$options['secure'],
            'httponly' => (bool)$options['httponly'],
            'samesite' => $options['samesite'] ?: 'Lax',
        ]);
    }
    session_destroy();
}

function webauthn_registration_options(PDO $db, array $config): array
{
    $server = webauthn_server($config);
    $principal = auth_principal($db);
    $options = $server->getCreateArgs(
        (string)$principal['user_handle'],
        'FIN owner',
        'FIN owner',
        FIN_AUTH_CHALLENGE_TTL,
        'discouraged',
        'required',
        false
    );
    auth_issue_challenge('registration', $server->getChallenge()->getBinaryString());
    return json_decode(json_encode($options, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
}

function webauthn_assertion_options(PDO $db, array $config, string $credentialId): ?array
{
    try {
        $rawId = fin_base64url_decode($credentialId);
    } catch (Throwable) {
        return null;
    }
    $stmt = $db->prepare('SELECT credential_id FROM webauthn_credentials WHERE credential_id=:credential_id AND revoked_at IS NULL');
    $stmt->bindValue(':credential_id', $rawId, PDO::PARAM_LOB);
    $stmt->execute();
    if (!$stmt->fetch()) return null;

    $server = webauthn_server($config);
    $options = $server->getGetArgs([$rawId], FIN_AUTH_CHALLENGE_TTL, false, false, false, false, true, 'required');
    auth_issue_challenge('assertion', $server->getChallenge()->getBinaryString(), ['credential_id' => $credentialId]);
    return json_decode(json_encode($options, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
}

function normalize_device_label(string $label): string
{
    $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
    if ($label === '') return 'This device';
    if (mb_strlen($label) > 60) throw new InvalidArgumentException('Device name must be 60 characters or fewer');
    return $label;
}

function webauthn_register_credential(PDO $db, array $config, array $payload): int
{
    $challenge = auth_consume_challenge('registration')['challenge'];
    $clientData = fin_base64url_decode((string)($payload['clientDataJSON'] ?? ''));
    $attestation = fin_base64url_decode((string)($payload['attestationObject'] ?? ''));
    $rawId = fin_base64url_decode((string)($payload['rawId'] ?? ''));
    validate_webauthn_client_origin($clientData, $config, 'webauthn.create');

    $server = webauthn_server($config);
    $created = $server->processCreate($clientData, $attestation, $challenge, true, true, false, false);
    $credentialId = is_object($created->credentialId) && method_exists($created->credentialId, 'getBinaryString')
        ? $created->credentialId->getBinaryString()
        : (string)$created->credentialId;
    if (!hash_equals($credentialId, $rawId)) throw new RuntimeException('Authentication verification failed');

    $transports = array_values(array_intersect(['internal'], array_map('strval', is_array($payload['transports'] ?? null) ? $payload['transports'] : [])));
    if (($payload['transports'] ?? []) !== [] && $transports === []) throw new RuntimeException('Authentication verification failed');
    $stmt = $db->prepare('INSERT INTO webauthn_credentials (credential_id,public_key,sign_count,transports,label) VALUES (:credential_id,:public_key,:sign_count,:transports,:label)');
    $stmt->bindValue(':credential_id', $credentialId, PDO::PARAM_LOB);
    $stmt->bindValue(':public_key', (string)$created->credentialPublicKey, PDO::PARAM_STR);
    $stmt->bindValue(':sign_count', (int)($created->signatureCounter ?? 0), PDO::PARAM_INT);
    $stmt->bindValue(':transports', json_encode($transports, JSON_THROW_ON_ERROR), PDO::PARAM_STR);
    $stmt->bindValue(':label', normalize_device_label((string)($payload['label'] ?? '')), PDO::PARAM_STR);
    $stmt->execute();
    return (int)$db->lastInsertId();
}

function webauthn_verify_assertion(PDO $db, array $config, array $payload): void
{
    $record = auth_consume_challenge('assertion');
    $rawId = fin_base64url_decode((string)($payload['rawId'] ?? ''));
    $expectedId = (string)($record['context']['credential_id'] ?? '');
    if ($expectedId === '' || !hash_equals($expectedId, fin_base64url_encode($rawId))) {
        throw new RuntimeException('Authentication verification failed');
    }
    $stmt = $db->prepare('SELECT * FROM webauthn_credentials WHERE credential_id=:credential_id AND revoked_at IS NULL');
    $stmt->bindValue(':credential_id', $rawId, PDO::PARAM_LOB);
    $stmt->execute();
    $credential = $stmt->fetch();
    if (!$credential) throw new RuntimeException('Authentication verification failed');

    $clientData = fin_base64url_decode((string)($payload['clientDataJSON'] ?? ''));
    $authenticatorData = fin_base64url_decode((string)($payload['authenticatorData'] ?? ''));
    $signature = fin_base64url_decode((string)($payload['signature'] ?? ''));
    validate_webauthn_client_origin($clientData, $config, 'webauthn.get');

    $userHandleEncoded = $payload['userHandle'] ?? null;
    if (is_string($userHandleEncoded) && $userHandleEncoded !== '') {
        $principal = auth_principal($db);
        if (!hash_equals((string)$principal['user_handle'], fin_base64url_decode($userHandleEncoded))) {
            throw new RuntimeException('Authentication verification failed');
        }
    }

    $server = webauthn_server($config);
    $server->processGet(
        $clientData,
        $authenticatorData,
        $signature,
        (string)$credential['public_key'],
        $record['challenge'],
        (int)$credential['sign_count'],
        true,
        true
    );
    $newCounter = $server->getSignatureCounter();
    $update = $db->prepare('UPDATE webauthn_credentials SET sign_count=:sign_count,last_used_at=CURRENT_TIMESTAMP WHERE id=:id AND revoked_at IS NULL');
    $update->execute([':sign_count' => $newCounter ?? (int)$credential['sign_count'], ':id' => (int)$credential['id']]);
    reset_master_pin_throttle($config);
    complete_fin_authentication('device');
}

function revoke_webauthn_credential(PDO $db, int $id): void
{
    $stmt = $db->prepare('UPDATE webauthn_credentials SET revoked_at=CURRENT_TIMESTAMP WHERE id=:id AND revoked_at IS NULL');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() !== 1) throw new RuntimeException('Device login was not found');
}

function rename_webauthn_credential(PDO $db, int $id, string $label): void
{
    $stmt = $db->prepare('UPDATE webauthn_credentials SET label=:label WHERE id=:id AND revoked_at IS NULL');
    $stmt->execute([':id' => $id, ':label' => normalize_device_label($label)]);
    if ($stmt->rowCount() !== 1) throw new RuntimeException('Device login was not found');
}
