<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\BuiltIn;

use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Chat\Exporter\ResultSetExporter;

/**
 * export_pdf — generative-UI built-in.
 *
 * Exports the current/last result set as a PDF download. Backed by {@see ResultSetExporter},
 * which degrades gracefully (returns an error AiActionResult telling the user to install
 * barryvdh/laravel-dompdf) when Dompdf is absent.
 *
 * Link grammar:
 *   [Download PDF](action://export_pdf?title=Overdue%20Invoices)
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
        return 'Export the current result set as a PDF file.';
    }

    public function aliases(): array
    {
        return ['export pdf', 'download pdf', 'export to pdf', 'pdf'];
    }

    public function inputSchema(): array
    {
        return [
            'title' => ['type' => 'string', 'default' => 'export', 'label' => 'File title'],
        ];
    }

    public function handle(array $input, ActionContext $context): AiActionResult
    {
        $rows = $context->resultSet;
        if (empty($rows)) {
            return AiActionResult::error('No data available to export to PDF.');
        }

        $title = (string) ($input['title'] ?? 'export');

        return $this->exporter->toPdf($rows, $title);
    }
}
