<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\BuiltIn;

use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Chat\Exporter\ResultSetExporter;

/**
 * export_excel — generative-UI built-in.
 *
 * Exports the current/last result set as an .xlsx download. Backed by
 * {@see ResultSetExporter}, which degrades gracefully (returns an error AiActionResult
 * telling the user to install maatwebsite/excel) when PhpSpreadsheet is absent.
 *
 * Link grammar:
 *   [Download Excel](action://export_excel?title=Overdue%20Invoices)
 *
 * The result set comes from the context; the action never carries the data through the link.
 * handle() returns an Export-kind result ['content','mime','filename'] — the presenter turns
 * that into a download link.
 */
class ExportExcelAction extends AbstractBuiltInAction
{
    public function __construct(
        private ?ResultSetExporter $exporter = null,
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
        return 'Export the current result set as an Excel (.xlsx) file.';
    }

    public function aliases(): array
    {
        return ['export excel', 'download excel', 'export to excel', 'xlsx', 'spreadsheet'];
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
            return AiActionResult::error('No data available to export to Excel.');
        }

        $title = (string) ($input['title'] ?? 'export');

        return $this->exporter->toExcel($rows, $title);
    }
}
