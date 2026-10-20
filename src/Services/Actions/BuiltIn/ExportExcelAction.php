<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\BuiltIn;

use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Chat\Exporter\ResultSetExporter;

/**
 * export_excel - generative-UI built-in.
 *
 * mode=table exports the current/last result set as an .xlsx workbook.
 * mode=document renders markdown tables from a clean document payload in
 * ActionContext (legacy fallback: visible answer markdown).
 * mode=rerun re-runs the query recorded with the message, as the acting user, and
 * exports every row ({@see RecordedResultExport}) — the chip of a capped list. Code
 * attaches it; it is not advertised to the model.
 */
class ExportExcelAction extends AbstractBuiltInAction
{
    public function __construct(
        private ?ResultSetExporter $exporter = null,
        private ?RecordedResultExport $recorded = null,
    ) {
        $this->exporter = $exporter ?? new ResultSetExporter();
    }

    public function key(): string
    {
        return 'export_excel';
    }

    public function label(): string
    {
        return 'Download Excel';
    }

    public function description(): string
    {
        return 'Export as an Excel (.xlsx) file. mode=table (default) exports the current result set; '
            . 'mode=document renders markdown tables from a clean document payload. '
            . 'Keep the visible chat answer concise and provide the download action.';
    }

    public function aliases(): array
    {
        return ['export excel', 'download excel', 'export to excel', 'xlsx', 'spreadsheet'];
    }

    public function inputSchema(): array
    {
        return [
            'title' => ['type' => 'string', 'default' => 'export', 'label' => 'File title'],
            'mode' => ['type' => 'string', 'default' => 'table', 'label' => "Export source: 'table' (result set) or 'document' (clean markdown document tables)"],
            // Carried by the code-attached re-run chip only (the answer's language, for the
            // file's labels and headers) — see declaredParams().
            'language' => ['type' => 'string', 'label' => 'Language of the file (ISO 639-1)'],
        ];
    }

    /**
     * The params the model is told about: the re-run chip is attached by code, so its
     * `language` carrier is not advertised.
     *
     * @return array<string, mixed>
     */
    public function declaredParams(): array
    {
        return array_diff_key($this->inputSchema(), ['language' => true]);
    }

    public function handle(array $input, ActionContext $context): AiActionResult
    {
        $title = (string) ($input['title'] ?? 'export');

        // The capped list's chip: every row, re-fetched now as the acting user — the
        // message's stored rows are never used on this path.
        if (RecordedResultExport::requested($input)) {
            return ($this->recorded ??= new RecordedResultExport(null, $this->exporter))->handle('excel', $input, $context);
        }

        if (strtolower((string) ($input['mode'] ?? 'table')) === 'document') {
            // The composed document's ROWS when persisted (Phase 8 batch A): written
            // straight to typed cells — no rows → markdown → re-parsed cells round
            // trip. The markdown parse stays for legacy messages / LLM-composed tables.
            if ($context->documentRows !== []) {
                // …under the document's own column headers (a column is headed by what
                // it holds, not by its row key — eval p17-B cold-roster-excel).
                return $this->exporter->documentRowsToExcel($context->documentRows, $title, $context->documentHeaders);
            }

            return $this->exporter->documentToExcel((string) ($context->documentMarkdown ?? $context->answer ?? ''), $title);
        }

        $rows = $context->resultSet;
        if (empty($rows)) {
            return AiActionResult::error('No data available to export to Excel.');
        }

        // The columns the screen showed: a split name is one column (redacted first, so a
        // name part that is withheld is never joined back in).
        return $this->exporter->toExcel(
            RecordedResultExport::withNamesMerged($this->exporter->redact($rows), $context->resultCypher),
            $title
        );
    }
}
