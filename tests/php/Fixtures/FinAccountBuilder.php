<?php

declare(strict_types=1);

namespace Tests\Php\Fixtures;

use PDO;

/**
 * Self-owned builder for FIN test prerequisites: Accounts, Envelopes,
 * Months. Tests create everything they need and never reach into the
 * user's data.
 *
 * Every fixture value is invented at the call site. The fixture layer
 * itself does NOT hardcode Account numbers, names, currencies, or
 * exchange rates beyond the deterministic defaults the test author
 * explicitly requested.
 */
final class FinAccountBuilder
{
    private PDO $db;
    private int $nonce = 0;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public static function for(PDO $db): self
    {
        return new self($db);
    }

    private function uniqueToken(string $prefix): string
    {
        $this->nonce++;
        return $prefix . '-' . bin2hex(random_bytes(4)) . '-' . $this->nonce;
    }

    /**
     * Create an isolated Month with a known exchange rate. Returns month id.
     */
    public function month(int $year, int $monthNumber, float $exchangeRate = 1.0, float $salary = 0.0): int
    {
        return save_month($this->db, [
            'year' => $year,
            'month' => $monthNumber,
            'exchange_rate' => $exchangeRate,
            'salary' => $salary,
        ]);
    }

    /** Create an Account with a synthetic absolute Balance observation. */
    public function account(string $name, string $accountNumber, string $preference = 'CURRENCY', float $openingBalance = 0.0, ?string $observationDate = null): int
    {
        $date = $observationDate ?? '2026-01-01';
        $accountId = create_account_with_initial_position($this->db, [
            'name' => $name,
            'account_number' => $accountNumber,
            'currency' => $preference,
        ]);
        record_account_balance_observation($this->db, $accountId, $openingBalance, $date, 'Synthetic fixture Balance');
        return $accountId;
    }

    /**
     * Create an Envelope. The default Account is not set; tests that need
     * it must call `setEnvelopeDefaultAccount()` explicitly.
     */
    public function envelope(string $name, string $role = 'daily', ?string $preference = null): int
    {
        return save_envelope($this->db, [
            'name' => $name,
            'role' => $role,
            'currency' => $preference,
        ]);
    }

    /**
     * Build a synthetic-statement row payload. Tests pass only invented
     * values. The synthetic-statement generator will format them
     * appropriately for the chosen statement format.
     *
     * @return array{booking_date:string,amount:float,currency?:string,description:string,running_balance?:float|null,fee?:float|null,direction?:string|null,counterparty?:string|null}
     */
    public function row(string $bookingDate, float $amount, string $description, ?float $runningBalance = null, ?float $fee = null, ?string $currency = null, ?string $direction = null, ?string $counterparty = null): array
    {
        $row = [
            'booking_date' => $bookingDate,
            'amount' => $amount,
            'description' => $description,
        ];
        if ($runningBalance !== null) $row['running_balance'] = $runningBalance;
        if ($fee !== null) $row['fee'] = $fee;
        if ($currency !== null) $row['currency'] = $currency;
        if ($direction !== null) $row['direction'] = $direction;
        if ($counterparty !== null) $row['counterparty'] = $counterparty;
        return $row;
    }

    /**
     * Convenience wrapper that builds one synthetic statement file from
     * self-owned rows and returns its absolute path.
     */
    public function writeStatement(string $format, string $directory, string $basename, array $rows, array $metadata = []): string
    {
        return SyntheticStatementGenerator::build($format, $directory, $basename, $rows, $metadata);
    }
}
