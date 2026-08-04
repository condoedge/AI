<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat\Exporter;

use Condoedge\Ai\Services\Actions\Results\AiActionResult;

/**
 * ResultSetExporter
 *
 * Exports an arbitrary tabular result set (array of associative rows) to Excel (.xlsx) or
 * PDF, returning the raw bytes wrapped in an {@see AiActionResult} so the action layer can
 * turn it into a download.
 *
 * GUARDED DEGRADATION (the package must NOT hard-require the host's export libraries):
 *   - Excel uses PhpOffice\PhpSpreadsheet IF class_exists, else returns an isError result
 *     with a clear "install maatwebsite/excel (phpoffice/phpspreadsheet)" summary.
 *   - PDF uses Dompdf\Dompdf IF class_exists, else returns an isError result with a clear
 *     "install barryvdh/laravel-dompdf (dompdf/dompdf)" summary.
 *
 * Nothing here imports those classes at the top of the file — every reference is a string
 * passed to class_exists()/new, so the package autoloads cleanly without them installed.
 *
 * EXPORT PRIVACY (the file must not leak schema internals):
 *   Both toExcel()/toPdf() project rows through {@see redact()} before serializing, so the
 *   generated file always drops internal id-ish columns (neo4j_id, *_id, leading-underscore
 *   keys). Beyond that, the export carries the columns the user is already authorized to see;
 *   a host that wants specific columns stripped from files lists them in
 *   config('ai.export.redact_columns') (empty by default).
 */
class ResultSetExporter
{
    private const SPREADSHEET_CLASS = '\\PhpOffice\\PhpSpreadsheet\\Spreadsheet';
    private const XLSX_WRITER_CLASS = '\\PhpOffice\\PhpSpreadsheet\\Writer\\Xlsx';
    private const DOMPDF_CLASS = '\\Dompdf\\Dompdf';
    private const MARKDOWN_CONVERTER_CLASS = '\\League\\CommonMark\\GithubFlavoredMarkdownConverter';

    /**
     * Print stylesheet for documentToPdf() (override via config('ai.export.pdf_document_styles')).
     * DejaVu Sans is dompdf's bundled unicode font — required for accented (FR) text.
     */
    private const DEFAULT_DOCUMENT_STYLES =
        'body{font-family:"DejaVu Sans",sans-serif;font-size:12px;color:#1a202c;margin:28px}'
        . 'h1{font-size:20px;border-bottom:2px solid #2d3748;padding-bottom:6px;margin-bottom:12px}'
        . 'h2{font-size:16px;margin-top:18px}h3{font-size:13px;margin-top:14px}'
        . 'table{width:100%;border-collapse:collapse;margin:10px 0}'
        . 'th,td{border:1px solid #cbd5e0;padding:6px 8px;text-align:left;font-size:11px}'
        . 'th{background:#edf2f7}'
        . 'blockquote{border-left:3px solid #cbd5e0;margin:8px 0;padding:4px 12px;color:#4a5568}'
        . 'hr{border:0;border-top:1px solid #cbd5e0;margin:14px 0}';

    /**
     * Internal id-ish column names always dropped from an export (case-insensitive). These are
     * graph/store internals that carry no user-facing meaning and leak the schema.
     */
    private const DEFAULT_INTERNAL_COLUMNS = ['neo4j_id', 'element_id', 'elementid', '_id'];

    /**
     * Column-name patterns (PCRE, anchored, case-insensitive) treated as internal id-ish keys:
     * anything ending in `_id`, and any leading-underscore meta key (e.g. `_owner_id`).
     */
    private const DEFAULT_INTERNAL_PATTERNS = ['/_id$/i', '/^_/'];

    /**
     * Whether Excel export is possible in this runtime.
     */
    public function canExportExcel(): bool
    {
        return class_exists(self::SPREADSHEET_CLASS) && class_exists(self::XLSX_WRITER_CLASS);
    }

    /**
     * Whether PDF export is possible in this runtime.
     */
    public function canExportPdf(): bool
    {
        return class_exists(self::DOMPDF_CLASS);
    }

    /**
     * Export rows to an .xlsx document.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param string $title
     * @return AiActionResult  Export kind → payload ['content'=>bytes,'mime'=>..,'filename'=>..]; or Error kind.
     */
    public function toExcel(array $rows, string $title = 'export'): AiActionResult
    {
        if (!$this->canExportExcel()) {
            return AiActionResult::error(
                'Excel export is unavailable: install the "maatwebsite/excel" (phpoffice/phpspreadsheet) package to enable it.'
            );
        }

        [$headers, $matrix] = $this->toMatrix($this->redact($rows));
        if (empty($matrix)) {
            return AiActionResult::error('Nothing to export: the result set is empty.');
        }

        $spreadsheetClass = self::SPREADSHEET_CLASS;
        $writerClass = self::XLSX_WRITER_CLASS;

        /** @var object $spreadsheet */
        $spreadsheet = new $spreadsheetClass();
        $sheet = $spreadsheet->getActiveSheet();

        $rowIndex = 1;
        if (!empty($headers)) {
            $this->writeRow($sheet, $rowIndex++, $headers);
        }
        foreach ($matrix as $dataRow) {
            $this->writeRow($sheet, $rowIndex++, $dataRow);
        }

        $writer = new $writerClass($spreadsheet);

        $bytes = $this->captureWriterOutput($writer);

        return AiActionResult::export(
            $bytes,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $this->safeFilename($title, 'xlsx'),
            'Generated an Excel export with ' . count($matrix) . ' row(s).'
        );
    }

    /**
     * Export rows to a PDF document.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param string $title
     * @return AiActionResult
     */
    public function toPdf(array $rows, string $title = 'export'): AiActionResult
    {
        if (!$this->canExportPdf()) {
            return AiActionResult::error(
                'PDF export is unavailable: install the "barryvdh/laravel-dompdf" (dompdf/dompdf) package to enable it.'
            );
        }

        [$headers, $matrix] = $this->toMatrix($this->redact($rows));
        if (empty($matrix)) {
            return AiActionResult::error('Nothing to export: the result set is empty.');
        }

        $html = $this->toHtmlTable($title, $headers, $matrix);

        $dompdfClass = self::DOMPDF_CLASS;
        /** @var object $dompdf */
        $dompdf = new $dompdfClass();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $bytes = (string) $dompdf->output();

        return AiActionResult::export(
            $bytes,
            'application/pdf',
            $this->safeFilename($title, 'pdf'),
            'Generated a PDF export with ' . count($matrix) . ' row(s).'
        );
    }

    /**
     * Render an LLM-COMPOSED markdown document (the chat answer) to a PDF.
     *
     * Unlike toPdf() (fixed tabular dump of the result set), this renders whatever document
     * the assistant wrote — contact sheets, summaries, formatted reports — so the layout
     * varies with the ask. The markdown is the already-privacy-governed chat answer;
     * action://-style links are stripped to their text so the file carries no internal URIs.
     */
    public function documentToPdf(string $markdown, string $title = 'document'): AiActionResult
    {
        if (trim($markdown) === '') {
            return AiActionResult::error('Nothing to export: there is no composed answer to render.');
        }

        if (!$this->canExportPdf()) {
            return AiActionResult::error(
                'PDF export is unavailable: install the "barryvdh/laravel-dompdf" (dompdf/dompdf) package to enable it.'
            );
        }

        $html = $this->documentHtml($markdown, $title);

        $dompdfClass = self::DOMPDF_CLASS;
        /** @var object $dompdf */
        $dompdf = new $dompdfClass();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $bytes = (string) $dompdf->output();

        return AiActionResult::export(
            $bytes,
            'application/pdf',
            $this->safeFilename($title, 'pdf'),
            'Generated a PDF document from the composed answer.'
        );
    }

    /**
     * The full HTML document documentToPdf() renders — public so hosts and tests can
     * inspect the markup without dompdf installed.
     */
    public function documentHtml(string $markdown, string $title): string
    {
        $body = $this->markdownToHtml($this->stripAppLinks($markdown));

        $styles = (string) ($this->safeConfig('ai.export.pdf_document_styles') ?? self::DEFAULT_DOCUMENT_STYLES);

        $safeTitle = htmlspecialchars($this->validUtf8($title), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $safeTitle . '</title>'
            . '<style>' . $styles . '</style></head><body>' . $body . '</body></html>';
    }

    /**
     * [text](action://…) / [text](entity://…) → text — app-internal URIs never reach a file.
     */
    private function stripAppLinks(string $markdown): string
    {
        return (string) preg_replace('/\[([^\]]*)\]\((?:action|entity):\/\/[^)]*\)/', '$1', $markdown);
    }

    /**
     * Markdown → HTML via league/commonmark when available (ships with laravel/framework),
     * with raw HTML escaped and unsafe links disallowed; plain escaped-text fallback otherwise.
     */
    private function markdownToHtml(string $markdown): string
    {
        $markdown = $this->validUtf8($markdown);

        $converterClass = self::MARKDOWN_CONVERTER_CLASS;
        if (class_exists($converterClass)) {
            try {
                /** @var object $converter */
                $converter = new $converterClass([
                    'html_input' => 'escape',
                    'allow_unsafe_links' => false,
                ]);

                return (string) $converter->convert($markdown);
            } catch (\Throwable $e) {
                // fall through to the escaped-text fallback
            }
        }

        return nl2br(htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    /**
     * Render the markdown TABLES of an LLM-composed answer to an .xlsx workbook.
     *
     * The harness contract (vs toExcel()'s raw result-set dump):
     *   - the LLM designs the spreadsheet: each markdown table becomes one worksheet, named
     *     after the nearest preceding heading; the table's header row IS the column headers
     *     (so the model freely renames/reorders/derives columns);
     *   - "extra calculations" are REAL Excel formulas, not LLM arithmetic: a cell like
     *     `=SUM(C2:C10)` (allowlisted aggregate functions over a cell range) is written as a
     *     live formula. Any other leading `=`/`+`/`-`/`@` cell is neutralized to literal text
     *     (formula-injection guard) — a spreadsheet must never gain an exfiltration vector
     *     from model output;
     *   - numeric-looking cells become real numbers so the formulas can compute over them.
     */
    public function documentToExcel(string $markdown, string $title = 'document'): AiActionResult
    {
        if (trim($markdown) === '') {
            return AiActionResult::error('Nothing to export: there is no composed answer to render.');
        }

        // Parse BEFORE the library check: "compose a markdown table first" is actionable
        // guidance for the LLM regardless of which export libraries the host installed.
        $tables = $this->extractMarkdownTables($this->stripAppLinks($markdown));
        if (empty($tables)) {
            return AiActionResult::error(
                'Nothing to export: the composed answer contains no markdown table. Compose the data as a markdown table first.'
            );
        }

        if (!$this->canExportExcel()) {
            return AiActionResult::error(
                'Excel export is unavailable: install the "maatwebsite/excel" (phpoffice/phpspreadsheet) package to enable it.'
            );
        }

        $spreadsheetClass = self::SPREADSHEET_CLASS;
        $writerClass = self::XLSX_WRITER_CLASS;

        /** @var object $spreadsheet */
        $spreadsheet = new $spreadsheetClass();

        foreach ($tables as $i => $table) {
            $sheet = $i === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $sheet->setTitle($this->safeSheetTitle($table['name'] ?? null, $i));

            $rowIndex = 1;
            $this->writeRow($sheet, $rowIndex++, $table['headers']);
            foreach ($table['rows'] as $dataRow) {
                $col = 1;
                foreach ($dataRow as $cell) {
                    $this->writeTypedCell($sheet, $this->columnLetter($col++) . $rowIndex, (string) $cell);
                }
                $rowIndex++;
            }
        }

        $writer = new $writerClass($spreadsheet);
        $bytes = $this->captureWriterOutput($writer);

        return AiActionResult::export(
            $bytes,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $this->safeFilename($title, 'xlsx'),
            'Generated an Excel workbook with ' . count($tables) . ' sheet(s) from the composed answer.'
        );
    }

    /**
     * Extract GFM tables from markdown: a header `| … |` line, a `|---|…` separator line,
     * then data lines. The nearest preceding `#` heading names the table (worksheet).
     *
     * Public so the parsing contract is testable without PhpSpreadsheet installed.
     *
     * @return array<int, array{name: ?string, headers: string[], rows: array<int, string[]>}>
     */
    public function extractMarkdownTables(string $markdown): array
    {
        $markdown = $this->validUtf8($markdown);
        $lines = preg_split('/\R/', $markdown) ?: [];
        $tables = [];
        $heading = null;
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = trim($lines[$i]);

            if (preg_match('/^#{1,6}\s+(.+)$/', $line, $m)) {
                $heading = trim($m[1]);
                $i++;
                continue;
            }

            $isHeaderRow = str_contains($line, '|')
                && isset($lines[$i + 1])
                && preg_match('/^\|?[\s:|-]+\|?$/', trim($lines[$i + 1]))
                && str_contains($lines[$i + 1], '-');

            if (!$isHeaderRow) {
                $i++;
                continue;
            }

            $headers = $this->splitTableRow($line);
            $rows = [];
            $i += 2; // past header + separator

            while ($i < $count && str_contains(trim($lines[$i]), '|') && trim($lines[$i]) !== '') {
                $cells = $this->splitTableRow(trim($lines[$i]));
                // Normalize row width to the header width.
                $rows[] = array_pad(array_slice($cells, 0, count($headers)), count($headers), '');
                $i++;
            }

            if (!empty($headers) && !empty($rows)) {
                $tables[] = ['name' => $heading, 'headers' => $headers, 'rows' => $rows];
            }
            $heading = null;
        }

        return $tables;
    }

    /**
     * @return string[]
     */
    private function splitTableRow(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\||\|$/', '', $line) ?? $line;

        return array_map(fn ($cell) => $this->validUtf8(trim($cell)), explode('|', $line));
    }

    /**
     * Write one cell with type detection: numerics become numbers (so formulas can compute
     * over them), allowlisted formulas stay live, anything else formula-bearing is
     * neutralized to a literal string.
     */
    private function writeTypedCell(object $sheet, string $coordinate, string $value): void
    {
        $value = $this->validUtf8($value);

        if (is_numeric(str_replace([',', ' '], ['', ''], $value)) && $value !== '') {
            $numeric = (float) str_replace([',', ' '], ['', ''], $value);
            $sheet->setCellValueExplicit($coordinate, $numeric, 'n'); // DataType::TYPE_NUMERIC
            return;
        }

        if ($this->isSafeFormula($value)) {
            $sheet->setCellValue($coordinate, $value); // interpreted as a formula
            return;
        }

        // Formula-injection guard: leading = + - @ would be interpreted by spreadsheet apps.
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) && !is_numeric($value)) {
            $value = "'" . $value;
        }

        $sheet->setCellValueExplicit($coordinate, $value, 's'); // DataType::TYPE_STRING
    }

    /**
     * Only simple aggregate formulas over a single cell range are allowed live — everything
     * else (incl. anything with strings, semicolons, or external references) stays text.
     */
    private function isSafeFormula(string $value): bool
    {
        return (bool) preg_match(
            '/^=(?:SUM|AVERAGE|COUNT|COUNTA|MIN|MAX)\(\s*[A-Z]{1,3}[0-9]{1,7}\s*:\s*[A-Z]{1,3}[0-9]{1,7}\s*\)$/i',
            trim($value)
        );
    }

    /**
     * Excel sheet titles: max 31 chars, no []*?:\/ — fall back to a numbered sheet name.
     */
    private function safeSheetTitle(?string $name, int $index): string
    {
        $clean = trim((string) preg_replace('/[\[\]\*\?:\\\\\/]+/', ' ', $this->validUtf8((string) $name)));

        return $clean !== '' ? mb_substr($clean, 0, 31) : 'Sheet ' . ($index + 1);
    }

    /**
     * Project rows so an export never leaks what the chat prose would have withheld.
     *
     * Drops, per column NAME (case-insensitive):
     *   - configured internal id-ish columns + the default deny-list (neo4j_id, *_id, _meta);
     *   - the export deny-list, passed in by the caller or read from
     *     config('ai.export.redact_columns') (empty by default — see configuredPiiColumns()).
     *
     * The whole step is bypassable with config('ai.export.redact_rows') = false, so a host that
     * exports through an already-redacted result set is not double-charged.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string>|null $piiColumns  Explicit PII column names; null => read config.
     * @return array<int, array<string, mixed>>
     */
    public function redact(array $rows, ?array $piiColumns = null): array
    {
        if (!$this->redactionEnabled()) {
            return $rows;
        }

        $internalColumns = $this->lowerSet($this->configList('ai.export.internal_columns', self::DEFAULT_INTERNAL_COLUMNS));
        $internalPatterns = $this->configList('ai.export.internal_patterns', self::DEFAULT_INTERNAL_PATTERNS);
        $piiColumns = $this->lowerSet($piiColumns ?? $this->configuredPiiColumns());

        $redacted = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $kept = [];
            foreach ($row as $key => $value) {
                if ($this->isRedactedColumn((string) $key, $internalColumns, $internalPatterns, $piiColumns)) {
                    continue;
                }
                $kept[$key] = $value;
            }
            $redacted[] = $kept;
        }

        return $redacted;
    }

    /**
     * @param array<string, true> $internalColumns  lower-cased name => true
     * @param array<int, string> $internalPatterns  PCRE patterns
     * @param array<string, true> $piiColumns        lower-cased name => true
     */
    private function isRedactedColumn(string $key, array $internalColumns, array $internalPatterns, array $piiColumns): bool
    {
        $lower = strtolower($key);

        if (isset($internalColumns[$lower]) || isset($piiColumns[$lower])) {
            return true;
        }

        foreach ($internalPatterns as $pattern) {
            if (@preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }

    private function redactionEnabled(): bool
    {
        return (bool) ($this->safeConfig('ai.export.redact_rows') ?? true);
    }

    /**
     * Column names to strip from generated files, read from config('ai.export.redact_columns').
     *
     * This is the export-specific deny-list, deliberately separate from the LLM
     * PII-minimization list (ai.access_control.identifying_fields): an export serializes a
     * result set the user is already authorized to see and asked for, so it must not inherit
     * the list whose only job is to keep fields out of the third-party LLM prompt. Empty by
     * default — internal id-ish columns are stripped independently by the patterns above.
     *
     * @return array<int, string>
     */
    private function configuredPiiColumns(): array
    {
        $columns = $this->safeConfig('ai.export.redact_columns');

        return is_array($columns) ? array_values(array_map('strval', $columns)) : [];
    }

    /**
     * Read a config list with a default, tolerating a missing container (unit context).
     *
     * @param array<int, string> $default
     * @return array<int, string>
     */
    private function configList(string $key, array $default): array
    {
        $value = $this->safeConfig($key);

        return is_array($value) ? array_values(array_map('strval', $value)) : $default;
    }

    /**
     * Read config tolerating an unbooted container (the exporter is unit-tested without a full
     * app boot). Any resolution failure yields null so callers fall back to safe defaults.
     */
    private function safeConfig(string $key): mixed
    {
        if (!function_exists('config')) {
            return null;
        }

        try {
            return config($key);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, string> $names
     * @return array<string, true>  lower-cased name => true
     */
    private function lowerSet(array $names): array
    {
        $set = [];
        foreach ($names as $name) {
            $set[strtolower((string) $name)] = true;
        }

        return $set;
    }

    /**
     * Normalize rows into [headers, matrix-of-string-rows].
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{0: string[], 1: array<int, array<int, string>>}
     */
    private function toMatrix(array $rows): array
    {
        $rows = array_values(array_filter($rows, 'is_array'));

        $headers = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                if (!in_array($key, $headers, true)) {
                    $headers[] = (string) $key;
                }
            }
        }

        $matrix = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($headers as $h) {
                $cells[] = $this->stringify($row[$h] ?? '');
            }
            $matrix[] = $cells;
        }

        return [$headers, $matrix];
    }

    /**
     * @param array<int, string> $cells
     */
    private function writeRow(object $sheet, int $rowIndex, array $cells): void
    {
        $col = 1;
        foreach ($cells as $cell) {
            $sheet->setCellValueExplicit(
                $this->columnLetter($col) . $rowIndex,
                $this->validUtf8($cell),
                // DataType::TYPE_STRING — passed as literal to avoid importing the class.
                's'
            );
            $col++;
        }
    }

    /**
     * Capture an xlsx writer's output as a string without touching the filesystem.
     */
    private function captureWriterOutput(object $writer): string
    {
        ob_start();
        $writer->save('php://output');
        return (string) ob_get_clean();
    }

    /**
     * @param string[] $headers
     * @param array<int, array<int, string>> $matrix
     */
    private function toHtmlTable(string $title, array $headers, array $matrix): string
    {
        $esc = fn($v) => htmlspecialchars($this->validUtf8((string) $v), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = '<html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans, sans-serif;font-size:10px;}'
            . 'table{width:100%;border-collapse:collapse;}'
            . 'th,td{border:1px solid #ccc;padding:4px;text-align:left;}'
            . 'th{background:#f0f0f0;}'
            . '</style></head><body>';
        $html .= '<h3>' . $esc($title) . '</h3><table>';

        if (!empty($headers)) {
            $html .= '<tr>';
            foreach ($headers as $h) {
                $html .= '<th>' . $esc($h) . '</th>';
            }
            $html .= '</tr>';
        }

        foreach ($matrix as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . $esc($cell) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</table></body></html>';

        return $html;
    }

    private function stringify(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return $this->validUtf8((string) $value);
        }

        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return is_string($encoded) ? $this->validUtf8($encoded) : '';
    }

    /**
     * Excel column index (1-based) → letter (A, B, …, AA).
     */
    private function columnLetter(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = (int) (($index - $mod) / 26);
        }
        return $letter ?: 'A';
    }

    private function safeFilename(string $title, string $ext): string
    {
        $base = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $this->validUtf8($title)) ?: 'export';
        $base = trim($base, '_') ?: 'export';
        return $base . '.' . $ext;
    }

    private function validUtf8(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_check_encoding') && @mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if (is_string($converted)) {
                return $converted;
            }
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            if (is_string($converted)) {
                return $converted;
            }
        }

        return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $value) ?? '';
    }
}
