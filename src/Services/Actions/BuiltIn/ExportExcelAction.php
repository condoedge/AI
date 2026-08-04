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
        ];
    }

    public function handle(array $input, ActionContext $context): AiActionResult
    {
        $title = (string) ($input['title'] ?? 'export');

        if (strtolower((string) ($input['mode'] ?? 'table')) === 'document') {
            return $this->exporter->documentToExcel((string) ($context->documentMarkdown ?? $context->answer ?? ''), $title);
        }

        $rows = $context->resultSet;
        if (empty($rows)) {
            return AiActionResult::error('No data available to export to Excel.');
        }

        return $this->exporter->toExcel($rows, $title);
    }
}
