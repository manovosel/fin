<?php

declare(strict_types=1);

/**
 * ECB historical reference rates, resolved per FIN week.
 *
 * Purpose:
 *   - resolve the EUR-anchored ECB reference rate for a target Currency on a given
 *     observed date, by FIN week (ISO Monday-anchored). All days inside one ISO
 *     week share a single cached ECB rate, so transaction processing never needs
 *     a per-day lookup;
 *   - never interpolate or invent a rate;
 *   - use the most recent preceding ECB publication for weekends / TARGET2 closing
 *     / ECB holidays;
 *   - once a (target_currency, week_key) is cached, normal transaction processing
 *     must not depend on ECB being reachable;
 *   - one HTTP fetch per (target_currency, missing-window) populates the full
 *     historical series in the cache; same-Currency lookups thereafter hit the
 *     cache without HTTP;
 *   - never silently use another FX provider.
 *
 * Cache schema (DB v2.17, no migration required):
 *   exchange_rate_cache(
 *     target_currency TEXT NOT NULL,    -- e.g. 'DKK'
 *     week_key        TEXT NOT NULL,    -- ISO YYYY-Www, Monday-anchored
 *     published_date  TEXT NOT NULL,    -- ECB publication date actually used
 *     rate            REAL NOT NULL,    -- EUR->target as published by ECB
 *     source          TEXT NOT NULL,
 *     fetched_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY (target_currency, week_key)
 *   )
 *
 * Historical weekly rates are immutable: a row whose `(target_currency, week_key)`
 * is already cached is never overwritten by automatic lookup. The
 * `ecb_reference_rate_refresh_window` helper exists as an explicit operator action
 * for picking up late corrections or extending the window; it is not invoked
 * automatically.
 */

const ECB_HISTORICAL_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist.xml';
const ECB_REFERENCE_SOURCE = 'ecb.europa.eu';
const ECB_HTTP_TIMEOUT_SECONDS = 8;

/**
 * Test hook: inject an HTTP fetcher. The closure must accept a URL and return the
 * raw response body as a string, or throw on failure.
 */
function ecb_reference_rate_set_fetcher(?Closure $fetcher): void
{
    ecb_reference_rate_test_fetcher()['fetcher'] = $fetcher;
}

function ecb_reference_rate_reset_test_fetcher(): void
{
    ecb_reference_rate_test_fetcher()['fetcher'] = null;
}

function &ecb_reference_rate_test_fetcher(): array
{
    static $holder = ['fetcher' => null];
    return $holder;
}

/**
 * ISO Monday-anchored week key for a given observed date (e.g. 2025-04-18 ->
 * 2025-W16). Returns null when the input is not a valid YYYY-MM-DD date.
 */
function ecb_reference_rate_week_key(string $observedDate): ?string
{
    if (!function_exists('valid_date') || !valid_date($observedDate)) return null;
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $observedDate);
    if (!$dt) return null;
    $iso = (int)$dt->format('W');
    $year = (int)$dt->format('o');
    return sprintf('%04d-W%02d', $year, $iso);
}

/**
 * Monday date for a given ISO YYYY-Www week key. Returns null on malformed input.
 */
function ecb_reference_rate_week_monday(string $weekKey): ?string
{
    if (!preg_match('/^(\d{4})-W(\d{2})$/', $weekKey, $m)) return null;
    $year = (int)$m[1];
    $week = (int)$m[2];
    $monday = (new DateTimeImmutable())->setISODate($year, $week, 1);
    return $monday->format('Y-m-d');
}

function ecb_reference_rate_next_week_key(string $weekKey): ?string
{
    if (!preg_match('/^(\d{4})-W(\d{2})$/', $weekKey, $m)) return null;
    $year = (int)$m[1];
    $week = (int)$m[2];
    $monday = (new DateTimeImmutable())->setISODate($year, $week, 1);
    $next = $monday->modify('+7 days');
    return sprintf('%04d-W%02d', (int)$next->format('o'), (int)$next->format('W'));
}

/**
 * EUR is the ECB anchor. Database Currency = EUR is the same-currency case and
 * must not require any ECB round-trip.
 */
function ecb_reference_rate_for_eur(): array
{
    return ['published_date' => '1970-01-01', 'rate' => 1.0, 'week_key' => '0000-W00'];
}

/**
 * Resolve the EUR-anchored ECB reference rate for the requested Currency for the
 * ISO week containing the observed date.
 */
function ecb_reference_rate_for_date(PDO $db, string $targetCurrency, string $eventDate): ?array
{
    $targetCurrency = strtoupper(trim($targetCurrency));
    if (!preg_match('/^[A-Z]{3}$/', $targetCurrency)) return null;
    $weekKey = ecb_reference_rate_week_key($eventDate);
    if ($weekKey === null) return null;
    ecb_reference_rate_ensure_cache_table($db);
    $cached = ecb_reference_rate_cache_lookup($db, $targetCurrency, $weekKey);
    if ($cached !== null) return $cached;
    if (!ecb_reference_rate_populate_from_source($db, $targetCurrency)) return null;
    return ecb_reference_rate_cache_lookup($db, $targetCurrency, $weekKey);
}

function ecb_reference_rate_ensure_cache_table(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS exchange_rate_cache (
        target_currency TEXT NOT NULL,
        week_key TEXT NOT NULL,
        published_date TEXT NOT NULL,
        rate REAL NOT NULL,
        source TEXT NOT NULL,
        fetched_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (target_currency, week_key)
    )");
}

function ecb_reference_rate_cache_lookup(PDO $db, string $targetCurrency, string $weekKey): ?array
{
    $stmt = $db->prepare('SELECT published_date, rate, week_key FROM exchange_rate_cache WHERE target_currency = :tc AND week_key = :wk LIMIT 1');
    $stmt->execute([':tc' => $targetCurrency, ':wk' => $weekKey]);
    $row = $stmt->fetch();
    if (!$row) return null;
    return [
        'published_date' => (string)$row['published_date'],
        'rate' => (float)$row['rate'],
        'week_key' => (string)$row['week_key'],
    ];
}

/**
 * Fetch the full ECB historical series once and reduce it to one row per ISO week
 * for the requested Currency. INSERT OR IGNORE leaves already-cached weeks alone.
 */
function ecb_reference_rate_populate_from_source(PDO $db, string $targetCurrency): bool
{
    $body = ecb_reference_rate_http_get(ECB_HISTORICAL_URL);
    if ($body === null) return false;
    $series = ecb_reference_rate_parse_series($body);
    if ($series === null || !isset($series[$targetCurrency]) || $series[$targetCurrency] === []) return false;
    $weekly = ecb_reference_rate_reduce_to_weekly($series[$targetCurrency]);
    if ($weekly === []) return false;
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $insert = $db->prepare('INSERT OR IGNORE INTO exchange_rate_cache (target_currency, week_key, published_date, rate, source) VALUES (:tc, :wk, :pd, :r, :s)');
        foreach ($weekly as $weekKey => $row) {
            $insert->execute([':tc' => $targetCurrency, ':wk' => $weekKey, ':pd' => $row['published_date'], ':r' => $row['rate'], ':s' => ECB_REFERENCE_SOURCE]);
        }
        if ($ownsTransaction) $db->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $error;
    }
    return true;
}

/**
 * Parse an ECB reference-rate XML document into a per-currency series of
 * `published_date => rate`.
 */
function ecb_reference_rate_parse_series(string $body): ?array
{
    $xml = @simplexml_load_string($body);
    if (!$xml) return null;
    $byCurrency = [];
    foreach ($xml->Cube->Cube ?? [] as $day) {
        $time = (string)$day['time'];
        if ($time === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $time)) continue;
        foreach ($day->Cube ?? [] as $rate) {
            $currency = strtoupper((string)$rate['currency']);
            $value = (float)(string)$rate['rate'];
            if ($currency === '' || $value <= 0) continue;
            $byCurrency[$currency][$time] = $value;
        }
    }
    return $byCurrency === [] ? null : $byCurrency;
}

/**
 * Reduce a per-day series (`YYYY-MM-DD => rate`, ECB publication dates only) to a
 * per-ISO-week series. For each ISO week with any publication, the weekly entry
 * is the latest publication date inside the week. ISO weeks with no publication at
 * all are bridged from the most recent preceding published week.
 */
function ecb_reference_rate_reduce_to_weekly(array $daily): array
{
    if ($daily === []) return [];
    $byWeek = [];
    foreach ($daily as $publishedDate => $rate) {
        $weekKey = ecb_reference_rate_week_key($publishedDate);
        if ($weekKey === null) continue;
        $byWeek[$weekKey][$publishedDate] = (float)$rate;
    }
    ksort($byWeek);
    $weekly = [];
    $previousWeekKey = null;
    $previousPublished = null;
    $previousRate = null;
    foreach ($byWeek as $weekKey => $dayRates) {
        ksort($dayRates, SORT_STRING);
        $latestDate = array_key_last($dayRates);
        if ($latestDate === null) continue;
        if ($previousWeekKey !== null) {
            $expected = ecb_reference_rate_next_week_key($previousWeekKey);
            $walkKey = $expected;
            while ($walkKey !== null && $walkKey !== $weekKey) {
                if (!isset($weekly[$walkKey]) && !isset($byWeek[$walkKey])) {
                    $weekly[$walkKey] = ['published_date' => $previousPublished, 'rate' => $previousRate];
                }
                $walkKey = ecb_reference_rate_next_week_key($walkKey);
            }
        }
        $weekly[$weekKey] = ['published_date' => $latestDate, 'rate' => (float)$dayRates[$latestDate]];
        $previousWeekKey = $weekKey;
        $previousPublished = $latestDate;
        $previousRate = (float)$dayRates[$latestDate];
    }
    ksort($weekly);
    return $weekly;
}

function ecb_reference_rate_http_get(string $url): ?string
{
    $fetcher = ecb_reference_rate_test_fetcher()['fetcher'] ?? null;
    if ($fetcher instanceof Closure) {
        try { return (string)$fetcher($url); } catch (Throwable) { return null; }
    }
    if (!function_exists('curl_init')) return ecb_reference_rate_http_get_fopen($url);
    $handle = curl_init($url);
    if ($handle === false) return null;
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => ECB_HTTP_TIMEOUT_SECONDS,
        CURLOPT_TIMEOUT => ECB_HTTP_TIMEOUT_SECONDS,
        CURLOPT_USERAGENT => 'FIN Planner (currency rate lookup)',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_NOSIGNAL => 1,
    ]);
    $body = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    if ($body === false || $status < 200 || $status >= 300) return null;
    return (string)$body;
}

function ecb_reference_rate_http_get_fopen(string $url): ?string
{
    $previousSocketTimeout = ini_get('default_socket_timeout');
    ini_set('default_socket_timeout', (string)ECB_HTTP_TIMEOUT_SECONDS);
    try {
        $context = stream_context_create(['http' => ['timeout' => ECB_HTTP_TIMEOUT_SECONDS, 'user_agent' => 'FIN Planner (currency rate lookup)']]);
        $body = @file_get_contents($url, false, $context);
        return $body === false ? null : $body;
    } finally {
        ini_set('default_socket_timeout', (string)$previousSocketTimeout);
    }
}

/**
 * Operator-driven refresh of recent weeks. Inserts new weekly rows only when no
 * cached entry exists for the `(target_currency, week_key)` pair.
 */
function ecb_reference_rate_refresh_window(PDO $db, string $targetCurrency, string $endDate, int $windowWeeks = 12): int
{
    $targetCurrency = strtoupper(trim($targetCurrency));
    if (!preg_match('/^[A-Z]{3}$/', $targetCurrency)) return 0;
    $endWeekKey = ecb_reference_rate_week_key($endDate);
    if ($endWeekKey === null) return 0;
    ecb_reference_rate_ensure_cache_table($db);
    $body = ecb_reference_rate_http_get(ECB_HISTORICAL_URL);
    if ($body === null) return 0;
    $series = ecb_reference_rate_parse_series($body);
    if (!isset($series[$targetCurrency])) return 0;
    $weekly = ecb_reference_rate_reduce_to_weekly($series[$targetCurrency]);
    if ($weekly === []) return 0;
    $existing = $db->prepare('SELECT week_key FROM exchange_rate_cache WHERE target_currency = :tc');
    $existing->execute([':tc' => $targetCurrency]);
    $have = [];
    foreach ($existing->fetchAll() as $row) $have[(string)$row['week_key']] = true;
    $insert = $db->prepare('INSERT OR IGNORE INTO exchange_rate_cache (target_currency, week_key, published_date, rate, source) VALUES (:tc, :wk, :pd, :r, :s)');
    $inserted = 0;
    $sortedKeys = array_keys($weekly);
    $endIndex = array_search($endWeekKey, $sortedKeys, true);
    if ($endIndex === false) return 0;
    $startIndex = max(0, $endIndex - $windowWeeks + 1);
    for ($i = $startIndex; $i <= $endIndex; $i++) {
        $wk = $sortedKeys[$i];
        if (isset($have[$wk])) continue;
        $insert->execute([':tc' => $targetCurrency, ':wk' => $wk, ':pd' => $weekly[$wk]['published_date'], ':r' => $weekly[$wk]['rate'], ':s' => ECB_REFERENCE_SOURCE]);
        $inserted++;
    }
    return $inserted;
}

/**
 * Public helper used by the currency module to obtain a historical EUR-anchored
 * weekly rate for the Currency/Helper pair.
 */
function historical_reference_rate_for_currency(PDO $db, string $targetCurrency, string $eventDate): ?array
{
    $targetCurrency = strtoupper(trim($targetCurrency));
    if ($targetCurrency === 'EUR') return ecb_reference_rate_for_eur();
    if (!preg_match('/^[A-Z]{3}$/', $targetCurrency)) return null;
    $weekKey = ecb_reference_rate_week_key($eventDate);
    if ($weekKey === null) return null;
    return ecb_reference_rate_for_date($db, $targetCurrency, $eventDate);
}