<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\BuiltIn;

use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Chat\Exporter\ResultSetExporter;

/**
 * export_pdf - generative-UI built-in.
 *
 * mode=table exports the current/last result set as a tabular PDF.
 * mode=document renders a clean markdown document payload from ActionContext
 * (legacy fallback: visible answer markdown) to PDF.
 * mode=rerun re-runs the query recorded with the message, as the acting user, and
 * exports every row a PDF may hold ({@see RecordedResultExport}). Code attaches it; it
 * is not advertised to the model.
 */
class ExportPdfAction extends AbstractBuiltInAction
{
    public function __construct(
        private ?ResultSetExporter $exporter = null,
        private ?RecordedResultExport $recorded = null,
    ) {
        $this->exporter = $exporter ?? new ResultSetExporter();
    }

    public function key(): string
    {
        return 'export_pdf';
    }

    public function label(): string
    {
        return 'Download PDF';
    }

    public function description(): string
    {
        return 'Export as a PDF. mode=table (default) exports the current result set as a data table; '
            . 'mode=document renders a clean markdown document payload as a formatted document. '
            . 'Keep the visible chat answer concise and provide the download action.';
    }

    public function aliases(): array
    {
        return ['export pdf', 'download pdf', 'export to pdf', 'pdf', 'pdf document', 'contact sheet', 'fiche'];
    }

    public function inputSchema(): array
    {
        return [
            'title' => ['type' => 'string', 'default' => 'export', 'label' => 'File title'],
            'mode' => ['type' => 'string', 'default' => 'table', 'label' => "Export source: 'table' (result set) or 'document' (clean markdown document payload)"],
            // Carried by the code-attached re-run chip only — see declaredParams().
            'language' => ['type' => 'string', 'label' => 'Language of the file (ISO 639-1)'],
        ];
    }

    /**
     * The params the model is told about (the re-run chip's `language` carrier is not).
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

        // Every row, re-fetched now as the acting user — never the message's stored rows.
        if (RecordedResultExport::requested($input)) {
            return ($this->recorded ??= new RecordedResultExport(null, $this->exporter))->handle('pdf', $input, $context);
        }

        if (strtolower((string) ($input['mode'] ?? 'table')) === 'document') {
            return $this->exporter->documentToPdf((string) ($context->documentMarkdown ?? $context->answer ?? ''), $title);
        }

        $rows = $context->resultSet;
        if (empty($rows)) {
            return AiActionResult::error('No data available to export to PDF.');
        }

        // The columns the screen showed: a split name is one column (redacted first).
        return $this->exporter->toPdf(
            RecordedResultExport::withNamesMerged($this->exporter->redact($rows), $context->resultCypher),
            $title
        );
    }
}
