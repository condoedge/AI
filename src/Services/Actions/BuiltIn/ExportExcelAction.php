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
        return 'Export as an Excel (.xlsx) file. mode=table (default) exports the current result set; '
            . 'mode=document renders the markdown TABLES of YOUR composed answer — your header row becomes '
            . 'the column headers (rename/reorder/derive freely), each table becomes a worksheet named after '
            . 'its preceding heading, and cells like =SUM(C2:C10) become live Excel formulas for totals.';
    }

    public function aliases(): array
    {
        return ['export excel', 'download excel', 'export to excel', 'xlsx', 'spreadsheet'];
    }

    public function inputSchema(): array
    {
        return [
            'title' => ['type' => 'string', 'default' => 'export', 'label' => 'File title'],
            'mode' => ['type' => 'string', 'default' => 'table', 'label' => "Export source: 'table' (result set) or 'document' (the composed answer's markdown tables)"],
        ];
    }

    public function handle(array $input, ActionContext $context): AiActionResult
    {
        $title = (string) ($input['title'] ?? 'export');

        if (strtolower((string) ($input['mode'] ?? 'table')) === 'document') {
            return $this->exporter->documentToExcel((string) ($context->answer ?? ''), $title);
        }

        $rows = $context->resultSet;
        if (empty($rows)) {
            return AiActionResult::error('No data available to export to Excel.');
        }

        return $this->exporter->toExcel($rows, $title);
    }
}
