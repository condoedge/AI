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
 */
class ResultSetExporter
{
    private const SPREADSHEET_CLASS = '\\PhpOffice\\PhpSpreadsheet\\Spreadsheet';
    private const XLSX_WRITER_CLASS = '\\PhpOffice\\PhpSpreadsheet\\Writer\\Xlsx';
    private const DOMPDF_CLASS = '\\Dompdf\\Dompdf';

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
     * @return AiActionResult  ok → renderable is ['content'=>bytes,'mime'=>..,'filename'=>..]; error → isError set.
     */
    public function toExcel(array $rows, string $title = 'export'): AiActionResult
    {
        if (!$this->canExportExcel()) {
            return AiActionResult::error(
                'Excel export is unavailable: install the "maatwebsite/excel" (phpoffice/phpspreadsheet) package to enable it.'
            );
        }

        [$headers, $matrix] = $this->toMatrix($rows);
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

        return AiActionResult::ok([
            'content' => $bytes,
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'filename' => $this->safeFilename($title, 'xlsx'),
        ], 'Generated an Excel export with ' . count($matrix) . ' row(s).');
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

        [$headers, $matrix] = $this->toMatrix($rows);
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

        return AiActionResult::ok([
            'content' => $bytes,
            'mime' => 'application/pdf',
            'filename' => $this->safeFilename($title, 'pdf'),
        ], 'Generated a PDF export with ' . count($matrix) . ' row(s).');
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
                $cell,
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
        $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

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
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
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
        $base = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $title) ?: 'export';
        $base = trim($base, '_') ?: 'export';
        return $base . '.' . $ext;
    }
}
