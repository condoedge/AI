<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\BuiltIn;

use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Chat\Exporter\ResultSetExporter;

/**
 * export_pdf — generative-UI built-in.
 *
 * Two modes, backed by {@see ResultSetExporter} (degrades gracefully — returns an error
 * AiActionResult telling the user to install barryvdh/laravel-dompdf — when Dompdf is absent):
 *
 *   mode=table (default)  the current/last result set as a tabular PDF dump.
 *   mode=document         the COMPOSED ANSWER the link is embedded in, rendered
 *                         markdown→HTML→PDF — for contact sheets, summaries and other
 *                         formatted documents whose layout the LLM designs per ask.
 *
 * Link grammar:
 *   [Download PDF](action://export_pdf?title=Overdue%20Invoices)
 *   [Download contact sheet (PDF)](action://export_pdf?mode=document&title=Contact%20Sheet)
 */
class ExportPdfAction extends AbstractBuiltInAction
{
    public function __construct(
        private ?ResultSetExporter $exporter = null,
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
            . 'mode=document renders YOUR composed answer as a formatted document — write the document '
            . '(headings, sections, tables) in the answer itself, then add this link.';
    }

    public function aliases(): array
    {
        return ['export pdf', 'download pdf', 'export to pdf', 'pdf', 'pdf document', 'contact sheet', 'fiche'];
    }

    public function inputSchema(): array
    {
        return [
            'title' => ['type' => 'string', 'default' => 'export', 'label' => 'File title'],
            'mode' => ['type' => 'string', 'default' => 'table', 'label' => "Export source: 'table' (result set) or 'document' (the composed answer)"],
        ];
    }

    public function handle(array $input, ActionContext $context): AiActionResult
    {
        $title = (string) ($input['title'] ?? 'export');

        if (strtolower((string) ($input['mode'] ?? 'table')) === 'document') {
            return $this->exporter->documentToPdf((string) ($context->answer ?? ''), $title);
        }

        $rows = $context->resultSet;
        if (empty($rows)) {
            return AiActionResult::error('No data available to export to PDF.');
        }

        return $this->exporter->toPdf($rows, $title);
    }
}
