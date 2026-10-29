<?php

declare(strict_types=1);

namespace Tests\Php\Fixtures;

/**
 * Self-owned synthetic XLSX/OOXML generator for every supported statement
 * structure. Reproduces the structural characteristics a saved Import Profile
 * needs, without copying or sanitizing any real/private statement file.
 *
 * Supported structures:
 *   - format:signed        (Danish header keys, Kontonummer, Saldo)
 *   - format:signed-card   (same shape; Kontonavn row contains "card")
 *   - format:fee           (Type, Produkt, Startdato, Beskrivelse, Beløb, Gebyr, Valuta, Saldo)
 *   - format:debit-credit  (OOXML .xls: pre-header "Račun: <digits>" + localized column keys)
 *
 * Generated files are written to a caller-supplied temporary directory
 * and registered for cleanup. No real customer data is used.
 */
final class SyntheticStatementGenerator
{
    public const FORMAT_SIGNED = 'format:signed';
    public const FORMAT_SIGNED_CARD = 'format:signed-card';
    public const FORMAT_FEE = 'format:fee';
    public const FORMAT_DEBIT_CREDIT = 'format:debit-credit';

    /**
     * Build a single synthetic XLSX/OOXML file and write it to
     * $outputDirectory. Returns the absolute path to the generated file.
     *
     * @param array<int,array{
     *     booking_date:string,           // YYYY-MM-DD
     *     amount:float,                  // signed, currency-native
     *     currency?:string,              // 3-letter code; defaults to the format default
     *     description:string,
     *     running_balance?:float|null,   // null = omit column or leave blank
     *     fee?:float|null,               // fee format only
     *     direction?:string|null,        // debit/credit format only: 'Uplata' or 'Isplata'
     *     counterparty?:string|null,     // debit/credit format only
     * } $rows
     * @param array{
     *     account_number?:string|null,
     *     account_name?:string|null,
     *     sheet_currency?:string|null,
     * } $metadata
     */
    public static function build(string $format, string $outputDirectory, string $basename, array $rows, array $metadata = []): string
    {
        if (!is_dir($outputDirectory)) {
            if (!@mkdir($outputDirectory, 0700, true) && !is_dir($outputDirectory)) {
                throw new \RuntimeException("Could not create output directory: $outputDirectory");
            }
        }
        $contents = self::render($format, $rows, $metadata);
        $ext = $format === self::FORMAT_DEBIT_CREDIT ? 'xls' : 'xlsx';
        $path = rtrim($outputDirectory, '/\\') . DIRECTORY_SEPARATOR . "$basename.$ext";
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new \RuntimeException("Could not write synthetic statement to: $path");
        }
        @chmod($path, 0600);
        return $path;
    }

    private static function render(string $format, array $rows, array $metadata): string
    {
        return match ($format) {
            self::FORMAT_SIGNED,
            self::FORMAT_SIGNED_CARD => self::renderSigned($format, $rows, $metadata),
            self::FORMAT_FEE => self::renderFee($rows, $metadata),
            self::FORMAT_DEBIT_CREDIT => self::renderDebitCredit($rows, $metadata),
            default => throw new \InvalidArgumentException("Unknown statement format: $format"),
        };
    }

    // ------------------------------------------------------------------
    // Signed statement (ordinary + card variant)
    // ------------------------------------------------------------------
    //
    // Structural requirements (Import Profile):
    //   - First row header with these keys after normalization:
    //     dato, tekst, beløb, saldo, kontonavn
    //   - Plus optional: kontonummer, kategori, afstemt, hovedkategori, kommentar
    //   - The card variant is distinguished by a `kontonavn` cell containing "card"
    //   - Amount column uses Danish comma-decimal
    //   - Informational Account identifier extracted from `kontonummer` column

    private static function renderSigned(string $format, array $rows, array $metadata): string
    {
        $accountNumber = (string)($metadata['account_number'] ?? 'TEST-SYNTHETIC-ACCOUNT');
        $accountName = (string)($metadata['account_name'] ?? ($format === self::FORMAT_SIGNED_CARD ? 'Synthetic Card Account' : 'Everyday Account'));
        $currency = strtoupper((string)($metadata['sheet_currency'] ?? 'DKK'));

        $headers = ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn', 'Hovedkategori', 'Kategori', 'Kommentar'];
        $rowXml = '';
        $rowNumber = 1;
        $rowXml .= self::rowXml($rowNumber++, array_map(fn(string $h): string => self::inlineStr($h), $headers));
        foreach ($rows as $row) {
            $dateRaw = (string)$row['booking_date'];
            $amount = (float)$row['amount'];
            $runningBalance = $row['running_balance'] ?? null;
            $description = (string)$row['description'];
            $cells = [
                self::inlineStr(self::excelSerialDate($dateRaw)),
                self::inlineStr($description),
                self::inlineStr(self::danishDecimal($amount)),
                self::inlineStr($runningBalance === null ? '' : self::danishDecimal((float)$runningBalance)),
                self::inlineStr(''),
                self::inlineStr($accountNumber),
                self::inlineStr($accountName),
                self::inlineStr(''),
                self::inlineStr(''),
                self::inlineStr(''),
            ];
            $rowXml .= self::rowXml($rowNumber++, $cells);
        }
        return self::packageXlsx(
            self::sheetXml($rowXml),
            sharedStrings: self::emptySharedStrings()
        );
    }

    // ------------------------------------------------------------------
    // Fee format
    // ------------------------------------------------------------------
    //
    // Structural requirements (Import Profile):
    //   - Header keys after normalization: type, produkt, startdato, beskrivelse,
    //     beløb, gebyr, valuta, saldo
    //   - Effective amount = Beløb − Gebyr (so Gebyr is a non-negative charge)
    //   - Beløb may be negative or positive, currency is 3 letters, Saldo is signed
    //   - Date format is `d.m.Y H:i:s` or `d.m.Y` (the parser accepts `d.m.Y` numeric)
    //   - This format has NO stable per-row account number; the profile skips it

    private static function renderFee(array $rows, array $metadata): string
    {
        // Include all 10 expected fee-format columns so the structural
        // matcher recognises synthetic fee files.
        $headers = ['Type', 'Produkt', 'Startdato', 'Fuldførelsesdato', 'Beskrivelse', 'Beløb', 'Gebyr', 'Valuta', 'State', 'Saldo'];
        $rowXml = '';
        $rowNumber = 1;
        $rowXml .= self::rowXml($rowNumber++, array_map(fn(string $h): string => self::inlineStr($h), $headers));
        foreach ($rows as $row) {
            $dateRaw = (string)$row['booking_date'];
            $amount = (float)$row['amount'];
            $fee = (float)($row['fee'] ?? 0.0);
            $currency = strtoupper((string)($row['currency'] ?? $metadata['sheet_currency'] ?? 'DKK'));
            $description = (string)$row['description'];
            $runningBalance = $row['running_balance'] ?? null;
            // The fee format uses d.m.Y H:i:s date format for both start and completion dates.
            $dateCell = self::displayDate($dateRaw) . ' 12:00:00';
            $cells = [
                self::inlineStr('TRANSFER'),
                self::inlineStr('Current'),
                self::inlineStr($dateCell),
                self::inlineStr($dateCell),
                self::inlineStr($description),
                self::inlineStr(self::danishDecimal($amount)),
                self::inlineStr(self::danishDecimal($fee)),
                self::inlineStr($currency),
                self::inlineStr('GENNEMFØRT'),
                self::inlineStr($runningBalance === null ? '' : self::danishDecimal((float)$runningBalance)),
            ];
            $rowXml .= self::rowXml($rowNumber++, $cells);
        }
        return self::packageXlsx(
            self::sheetXml($rowXml),
            sharedStrings: self::emptySharedStrings()
        );
    }

    // ------------------------------------------------------------------
    // Debit/credit format (OOXML .xls)
    // ------------------------------------------------------------------
    //
    // Structural requirements (Import Profile):
    //   - Pre-header (rows 0-7) contains "Račun: <digits>" for informational identifier extraction
    //   - Header row (rows 0-9) contains all 7 keys: datumknjienja, datumvalute,
    //     uplataisplata, iznosisplate, iznosuplate, valuta, brojtransakcije
    //   - Header keys after normalization contain diacritics-stripped forms
    //     (e.g. "Datum knjiženja" → "datumknjienja")
    //   - Date format `d.m.Y` (parser tries that)
    //   - Amount uses iznosisplate (outflow) or iznosuplate (inflow), not both
    //   - Description combines opisplaanja and counterparty (nazivprimatelja /
    //     nazivplatitelja depending on direction)
    //   - File extension is .xls (this export uses OOXML inside .xls)
    //   - Running balance is NOT a column (the profile forces null)

    private static function renderDebitCredit(array $rows, array $metadata): string
    {
        $accountNumber = (string)($metadata['account_number'] ?? 'TEST-DEBIT-CREDIT-SYNTHETIC');
        $currency = strtoupper((string)($metadata['sheet_currency'] ?? 'EUR'));

        // Pre-header row with account-number, used by the Import Profile
        // `preheader_prefixed_digits` Account-number mode.
        $preHeaderCells = [
            self::inlineStr('Transakcije'),
            self::inlineStr("Račun: $accountNumber"),
            self::inlineStr('Vremensko razdoblje: synthetic range'),
        ];
        // Pre-header uses columns A, B, C — the profile scans for "Račun:" text anywhere.
        $rowXml = self::rowXml(1, $preHeaderCells);

        // Header row at row 2 with the debit/credit column keys. The parser's
        // `statement_header_key` strips non-alphanumeric and lowercases, so
        // diacritics in the source text are matched against the normalized key set.
        // Render all 26 debit/credit columns matching the accepted profile
        // schema so the structural matcher recognises synthetic files.
        $rbaHeaders = [
            'Datum knjiženja',
            'Datum valute',
            'Uplata/Isplata',
            'Iznos isplate',
            'Iznos uplate',
            'Valuta',
            'Broj transakcije',
            'Status',
            'IBAN platitelja',
            'Naziv platitelja',
            'Adresa platitelja',
            'Sjedište platitelja',
            'Poziv na broj platitelja',
            'Stvarni dužnik',
            'IBAN primatelja',
            'Naziv primatelja',
            'Adresa primatelja',
            'Sjedište primatelja',
            'Poziv na broj primatelja',
            'Opis plaćanja',
            'Krajnji primatelj',
            'Šifra namjene',
            'Originalni iznos isplate',
            'Originalni iznos uplate',
            'Originalna valuta',
            'Tečaj',
        ];
        $rowXml .= self::rowXml(2, array_map(fn(string $h): string => self::inlineStr($h), $rbaHeaders));

        // Data rows.
        $rowNumber = 3;
        $txCounter = 100000;
        foreach ($rows as $row) {
            $dateRaw = (string)$row['booking_date'];
            $amount = (float)$row['amount'];
            $direction = (string)($row['direction'] ?? ($amount >= 0 ? 'Uplata' : 'Isplata'));
            $description = (string)$row['description'];
            $counterparty = (string)($row['counterparty'] ?? '');
            $outflow = $amount < 0 ? self::danishDecimal(-$amount) : '';
            $inflow = $amount >= 0 ? self::danishDecimal($amount) : '';
            $counterpartyCol = $amount < 0 ? $counterparty : '';
            $payerCol = $amount < 0 ? '' : $counterparty;
            // Extend each row to the full 26-column debit/credit layout so
            // the structural matcher recognises synthetic files.
            $baseCells = [
                self::inlineStr(self::displayDate($dateRaw)),
                self::inlineStr(self::displayDate($dateRaw)),
                self::inlineStr($direction),
                self::inlineStr($outflow),
                self::inlineStr($inflow),
                self::inlineStr($currency),
                self::inlineStr((string)$txCounter),
                self::inlineStr('Provedeno'),
                self::inlineStr(''),
                self::inlineStr(''),
                self::inlineStr(''),
                self::inlineStr(''),
                self::inlineStr(''),
                self::inlineStr(''),
                self::inlineStr(''),
                self::inlineStr($counterpartyCol),
                self::inlineStr(''),
                self::inlineStr(''),
                self::inlineStr(''),
                self::inlineStr($description),
                self::inlineStr(''),
                self::inlineStr(''),
                self::inlineStr(''),
                self::inlineStr(''),
                self::inlineStr(''),
                self::inlineStr(''),
            ];
            $rowXml .= self::rowXml($rowNumber++, $baseCells);
            $txCounter++;
            $txCounter++;
        }

        return self::packageXlsx(
            self::sheetXml($rowXml),
            sharedStrings: self::emptySharedStrings(),
            asXlsExt: true
        );
    }

    // ------------------------------------------------------------------
    // XLSX packaging (zip via PharData)
    // ------------------------------------------------------------------

    private static function packageXlsx(string $sheetXml, string $sharedStrings, bool $asXlsExt = false): string
    {
        $tmpBase = tempnam(sys_get_temp_dir(), 'fin-synthetic-xlsx-');
        if ($tmpBase === false) {
            throw new \RuntimeException('Could not allocate a temp file for XLSX packaging');
        }
        @unlink($tmpBase);
        $tarPath = $tmpBase . '.tar.gz';
        $zipPath = $tmpBase . '.zip';
        try {
            $phar = new \PharData($tarPath);
            $phar->addFromString('[Content_Types].xml', self::contentTypesXml());
            $phar->addFromString('_rels/.rels', self::rootRelsXml());
            $phar->addFromString('xl/workbook.xml', self::workbookXml());
            $phar->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelsXml());
            $phar->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
            $phar->addFromString('xl/sharedStrings.xml', $sharedStrings);
            // Convert to a separate ZIP file so the resulting bytes are a real
            // zip-formatted archive (PK\x03\x04 magic). PharData replaces the
            // extension, so the zip file lives at `<base>.zip`, not `.tar.gz.zip`.
            $phar->convertToData(\Phar::ZIP, \Phar::NONE, '.zip');
            if (!is_file($zipPath)) {
                throw new \RuntimeException("Converted zip not found at: $zipPath");
            }
            $contents = (string)file_get_contents($zipPath);
            if ($contents === '' || strlen($contents) < 100) {
                throw new \RuntimeException('Synthetic XLSX packaging produced empty output');
            }
            return $contents;
        } finally {
            @unlink($tarPath);
            @unlink($zipPath);
        }
    }

    private static function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>';
    }

    private static function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';
    }

    private static function sheetXml(string $rowsXml): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $rowsXml . '</sheetData>'
            . '</worksheet>';
    }

    private static function emptySharedStrings(): string
    {
        // Inline strings do not require sharedStrings entries; we keep an empty
        // document so the relationship graph is consistent.
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="0" uniqueCount="0"></sst>';
    }

    private static function rowXml(int $rowNumber, array $cells): string
    {
        // The parser reads `cell['r']` to determine column index; each cell must
        // carry its spreadsheet reference (A1, B1, …).
        $rendered = [];
        foreach ($cells as $columnIndex => $cellXml) {
            $ref = self::columnLetter($columnIndex) . $rowNumber;
            $rendered[] = preg_replace('/^<c\b/', '<c r="' . $ref . '"', $cellXml, 1);
        }
        return '<row r="' . $rowNumber . '">' . implode('', $rendered) . '</row>';
    }

    private static function columnLetter(int $zeroBasedIndex): string
    {
        // Convert 0-based column index to spreadsheet letter (0=A, 25=Z, 26=AA).
        $letter = '';
        $index = $zeroBasedIndex;
        do {
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);
        return $letter;
    }

    private static function inlineStr(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return '<c t="inlineStr"><is><t>' . $escaped . '</t></is></c>';
    }

    // ------------------------------------------------------------------
    // Format helpers
    // ------------------------------------------------------------------

    private static function danishDecimal(float $value): string
    {
        // Danish style uses comma for decimal separator and a leading minus
        // for negative values (e.g. -42,50). The parser strips spaces, then
        // if comma is present, replaces '.' with '' and ',' with '.'.
        return number_format($value, 2, ',', '');
    }

    private static function excelSerialDate(string $yyyyMmDd): string
    {
        // Convert YYYY-MM-DD to Excel serial date (days since 1899-12-30).
        // The parser does round(serial*86400) and adds the result back to
        // 1899-12-30 00:00:00 UTC, so we must use midnight to avoid a
        // rounding-up drift into the next calendar day.
        // Malformed inputs are passed through as the raw cell content so the
        // parser can mark them as a problem row rather than failing the
        // entire fixture.
        $epoch = strtotime($yyyyMmDd . ' 00:00:00 UTC');
        if ($epoch === false) {
            return $yyyyMmDd;
        }
        $baseEpoch = strtotime('1899-12-30 00:00:00 UTC');
        return (string)round(($epoch - $baseEpoch) / 86400);
    }

    private static function displayDate(string $yyyyMmDd): string
    {
        // The debit/credit format expects d.m.Y; malformed inputs are passed through so
        // the parser can mark them as a problem row.
        $parts = explode('-', $yyyyMmDd);
        if (count($parts) !== 3) {
            return $yyyyMmDd;
        }
        return sprintf('%02d.%02d.%04d', (int)$parts[2], (int)$parts[1], (int)$parts[0]);
    }
}
