<?php

namespace App\Domain\Reconciliation\Services;

use App\Domain\Reconciliation\Exceptions\CsvParseException;

class CsvParserService
{
    /**
     * Parse a CSV file into headers and associative row arrays.
     *
     * @return array{headers: list<string>, rows: list<array<string, string>>, delimiter: string, total_rows: int}
     *
     * @throws CsvParseException
     */
    public function parse(string $absolutePath): array
    {
        $maxBytes = (int) config('reconciliation.max_csv_size_bytes');
        $maxRows = (int) config('reconciliation.max_data_rows');
        $maxColumns = (int) config('reconciliation.max_columns');
        $maxFieldLength = (int) config('reconciliation.max_field_length');

        if (! is_readable($absolutePath)) {
            throw new CsvParseException('FILE_UNREADABLE', 'The uploaded file could not be read.');
        }

        $size = filesize($absolutePath);

        if ($size === false) {
            throw new CsvParseException('FILE_UNREADABLE', 'The uploaded file could not be read.');
        }

        if ($size === 0) {
            throw new CsvParseException('EMPTY_FILE', 'The CSV file is empty.');
        }

        if ($size > $maxBytes) {
            throw new CsvParseException('FILE_TOO_LARGE', 'The CSV exceeds the maximum allowed size.');
        }

        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw new CsvParseException('FILE_UNREADABLE', 'The uploaded file could not be read.');
        }

        try {
            $probe = fread($handle, min(8192, $size));

            if ($probe === false || $probe === '') {
                throw new CsvParseException('EMPTY_FILE', 'The CSV file is empty.');
            }

            if (str_contains($probe, "\0")) {
                throw new CsvParseException('BINARY_FILE', 'Binary files are not supported. Upload a UTF-8 CSV.');
            }

            if (! mb_check_encoding($probe, 'UTF-8')) {
                throw new CsvParseException('UNSUPPORTED_ENCODING', 'Only UTF-8 CSV files are supported.');
            }

            rewind($handle);

            $bom = fread($handle, 3);

            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            $delimiter = $this->detectDelimiter($handle);
            rewind($handle);

            if ($bom === "\xEF\xBB\xBF") {
                fread($handle, 3);
            }

            $this->assertQuotesAreBalanced($handle);
            rewind($handle);

            if ($bom === "\xEF\xBB\xBF") {
                fread($handle, 3);
            }

            $headerLine = fgetcsv($handle, 0, $delimiter, '"', '\\');

            if ($headerLine === false || $headerLine === [null] || $headerLine === []) {
                throw new CsvParseException('MISSING_HEADER', 'The CSV must include a header row.');
            }

            $headers = array_map(fn ($value) => $this->normalizeField((string) $value, $maxFieldLength), $headerLine);

            if (count($headers) > $maxColumns) {
                throw new CsvParseException('TOO_MANY_COLUMNS', "CSVs may include at most {$maxColumns} columns.");
            }

            if (count(array_filter($headers, fn ($h) => $h !== '')) === 0) {
                throw new CsvParseException('MISSING_HEADER', 'The CSV must include a header row.');
            }

            if (count($headers) !== count(array_unique($headers))) {
                throw new CsvParseException('DUPLICATE_HEADERS', 'Column headers must be unique.');
            }

            $rows = [];
            $rowNumber = 0;

            while (($cells = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                if ($this->isEmptyRow($cells)) {
                    continue;
                }

                $rowNumber++;

                if ($rowNumber > $maxRows) {
                    throw new CsvParseException('TOO_MANY_ROWS', "CSVs may include at most {$maxRows} data rows.");
                }

                if (count($cells) > $maxColumns) {
                    throw new CsvParseException('TOO_MANY_COLUMNS', "CSVs may include at most {$maxColumns} columns.");
                }

                if (count($cells) !== count($headers)) {
                    throw new CsvParseException(
                        'INCONSISTENT_COLUMN_COUNT',
                        "Source row {$rowNumber} has ".count($cells).' columns; the header defines '.count($headers).'.'
                    );
                }

                if ($this->hasMalformedQuoting($cells)) {
                    throw new CsvParseException('MALFORMED_CSV', 'The CSV contains malformed quoting.');
                }

                $normalized = [];

                foreach ($headers as $index => $header) {
                    $value = array_key_exists($index, $cells) ? (string) $cells[$index] : '';
                    $normalized[$header] = $this->normalizeField($value, $maxFieldLength);
                }

                $rows[] = $normalized;
            }

            if (! feof($handle)) {
                throw new CsvParseException('MALFORMED_CSV', 'The CSV could not be fully parsed.');
            }

            if ($rowNumber === 0) {
                throw new CsvParseException('NO_DATA_ROWS', 'The CSV has a header but no data rows.');
            }

            return [
                'headers' => $headers,
                'rows' => $rows,
                'delimiter' => $delimiter,
                'total_rows' => $rowNumber,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function detectDelimiter($handle): string
    {
        $sample = '';
        $lines = 0;

        while ($lines < 5 && ($line = fgets($handle)) !== false) {
            $sample .= $line;
            $lines++;
        }

        $candidates = [
            ',' => substr_count($sample, ','),
            ';' => substr_count($sample, ';'),
            "\t" => substr_count($sample, "\t"),
        ];

        arsort($candidates);
        $delimiter = array_key_first($candidates);

        if ($candidates[$delimiter] === 0) {
            return ',';
        }

        return $delimiter;
    }

    /**
     * @throws CsvParseException
     */
    private function normalizeField(string $value, int $maxFieldLength): string
    {
        if (str_contains($value, "\0")) {
            throw new CsvParseException('BINARY_FILE', 'Binary files are not supported. Upload a UTF-8 CSV.');
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new CsvParseException('UNSUPPORTED_ENCODING', 'Only UTF-8 CSV files are supported.');
        }

        if (mb_strlen($value) > $maxFieldLength) {
            throw new CsvParseException(
                'FIELD_TOO_LONG',
                "Each field may contain at most {$maxFieldLength} characters."
            );
        }

        return $value;
    }

    /**
     * @param  list<string|null>  $cells
     */
    private function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * fgetcsv returns a single null cell when quoting is broken.
     *
     * @param  list<string|null>  $cells
     */
    private function hasMalformedQuoting(array $cells): bool
    {
        return count($cells) === 1 && $cells[0] === null;
    }

    /**
     * Reject files that end inside an open quoted field.
     *
     * PHP's fgetcsv is permissive with unclosed quotes, so this check is required
     * for a deterministic MALFORMED_CSV failure. Files are already size-capped.
     *
     * @param  resource  $handle
     *
     * @throws CsvParseException
     */
    private function assertQuotesAreBalanced($handle): void
    {
        $contents = stream_get_contents($handle);

        if ($contents === false) {
            throw new CsvParseException('FILE_UNREADABLE', 'The uploaded file could not be read.');
        }

        $inQuotes = false;
        $length = strlen($contents);

        for ($i = 0; $i < $length; $i++) {
            if ($contents[$i] !== '"') {
                continue;
            }

            if ($inQuotes) {
                if ($i + 1 < $length && $contents[$i + 1] === '"') {
                    $i++;

                    continue;
                }

                $inQuotes = false;

                continue;
            }

            $inQuotes = true;
        }

        if ($inQuotes) {
            throw new CsvParseException('MALFORMED_CSV', 'The CSV contains malformed quoting.');
        }
    }
}
