<?php

declare(strict_types=1);

if (!function_exists('historical_reference_rate_for_currency')) {
    require_once __DIR__ . '/ecb_rates.php';
}

const FIN_CURRENCY_PREFERENCE = 'CURRENCY';
const FIN_HELPER_CURRENCY_PREFERENCE = 'HELPER_CURRENCY';

function currency_code(string $value, string $label): string
{
    $value = strtoupper(trim($value));
    if (!preg_match('/^[A-Z]{3}$/', $value)) {
        throw new RuntimeException("$label must be a three-letter uppercase code");
    }
    return $value;
}

function validate_currency_config(array $config): array
{
    $currency = currency_code((string)($config['currency'] ?? ''), 'Currency');
    $helper = currency_code((string)($config['helper_currency'] ?? ''), 'Helper currency');
    if ($currency === $helper) throw new RuntimeException('Currency and Helper currency must differ');
    return ['currency' => $currency, 'helper_currency' => $helper];
}

function currency_config_registry(PDO $db, ?array $set = null): array
{
    static $configs;
    $configs ??= new WeakMap();
    if ($set !== null) $configs[$db] = validate_currency_config($set);
    if (isset($configs[$db])) return $configs[$db];
    $config = function_exists('app_config') ? app_config() : ['currency' => 'DKK', 'helper_currency' => 'EUR'];
    $configs[$db] = validate_currency_config($config);
    return $configs[$db];
}

function set_currency_config(PDO $db, array $config): void
{
    currency_config_registry($db, $config);
}

function database_currency(PDO $db): string
{
    $exists = (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='database_settings'")->fetchColumn() > 0;
    if (!$exists) throw new RuntimeException('Database Currency is not initialized');
    $currency = strtoupper((string)$db->query('SELECT currency FROM database_settings WHERE id=1')->fetchColumn());
    return currency_code($currency, 'Database Currency');
}

function currency_context(PDO $db): array
{
    $config = currency_config_registry($db);
    $currency = database_currency($db);
    if ($currency !== $config['currency']) {
        throw new RuntimeException("Configured Currency {$config['currency']} does not match database Currency $currency");
    }
    return ['currency' => $currency, 'helper_currency' => $config['helper_currency']];
}

function currency_assert_preference(string $preference): string
{
    $preference = strtoupper(trim($preference));
    if (!in_array($preference, [FIN_CURRENCY_PREFERENCE, FIN_HELPER_CURRENCY_PREFERENCE], true)) {
        throw new InvalidArgumentException('Currency preference must be Currency or Helper currency');
    }
    return $preference;
}

function currency_code_for_preference(PDO $db, string $preference): string
{
    $context = currency_context($db);
    return currency_assert_preference($preference) === FIN_HELPER_CURRENCY_PREFERENCE
        ? $context['helper_currency']
        : $context['currency'];
}

function exchange_rate_for_date(PDO $db, string $date): ?float
{
    if (!function_exists('valid_date') || !valid_date($date)) throw new InvalidArgumentException('Date must be YYYY-MM-DD');
    $actual = $db->prepare("SELECT exchange_rate FROM months WHERE year=CAST(substr(:date,1,4) AS INTEGER) AND month=CAST(substr(:date,6,2) AS INTEGER)");
    $actual->execute([':date' => $date]);
    $rate = (float)($actual->fetchColumn() ?: 0);
    if ($rate > 0) return $rate;
    return historical_exchange_rate_fallback($db, $date);
}

/**
 * Fallback to ECB when no Month-configured rate exists for the given event date.
 * Generalised cross-rate model: with Helper=H and FIN Currency=C, the required
 * `Helper * rate = Currency` rate is derived from the two EUR-anchored ECB rates
 * (rate = eur_to_c / eur_to_h). Same-currency and either-side-equal-EUR cases
 * short-circuit without a network call. Returns null when either side cannot be
 * sourced from the local ECB cache.
 */
function historical_exchange_rate_fallback(PDO $db, string $date): ?float
{
    if (!function_exists('valid_date') || !valid_date($date)) return null;
    $context = currency_context($db);
    $currency = strtoupper($context['currency']);
    $helper = strtoupper($context['helper_currency']);
    if ($currency === $helper) return 1.0;
    if (!function_exists('historical_reference_rate_for_currency')) return null;
    $helperToEur = (float)(historical_reference_rate_for_currency($db, $helper, $date)['rate'] ?? 0.0);
    $currencyToEur = (float)(historical_reference_rate_for_currency($db, $currency, $date)['rate'] ?? 0.0);
    if ($helperToEur <= 0 || $currencyToEur <= 0) return null;
    // ECB rate semantics: rate = units of <currency> per 1 EUR.
    // So: 1 EUR = eur_to_c currency = eur_to_h helper.
    // Helper -> Currency = eur_to_c / eur_to_h.
    return $currencyToEur / $helperToEur;
}

function amount_to_currency(PDO $db, float $amount, string $preference, string $date): float
{
    if (!is_finite($amount)) throw new InvalidArgumentException('Amount must be a number');
    if (currency_assert_preference($preference) === FIN_CURRENCY_PREFERENCE) return round($amount, 2);
    $rate = exchange_rate_for_date($db, $date);
    if ($rate === null) throw new InvalidArgumentException('A usable exchange rate is required for this date');
    return round($amount * $rate, 2);
}

function amount_from_currency(PDO $db, float $amount, string $preference, string $date): ?float
{
    if (!is_finite($amount)) throw new InvalidArgumentException('Amount must be a number');
    if (currency_assert_preference($preference) === FIN_CURRENCY_PREFERENCE) return round($amount, 2);
    $rate = exchange_rate_for_date($db, $date);
    return $rate === null ? null : round($amount / $rate, 2);
}

function evidence_amount_to_currency(PDO $db, float $amount, string $evidenceCurrency, string $eventDate): ?float
{
    if (!is_finite($amount)) return null;
    $context = currency_context($db);
    $evidenceCurrency = strtoupper(trim($evidenceCurrency));
    if ($evidenceCurrency === $context['currency']) return round($amount, 2);
    if ($evidenceCurrency !== $context['helper_currency']) return null;
    $rate = exchange_rate_for_date($db, $eventDate);
    return $rate === null ? null : round($amount * $rate, 2);
}
