<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Chat\Exporter;

use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\BuiltIn\ExportExcelAction;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Chat\Exporter\ResultSetExporter;
use PHPUnit\Framework\TestCase;

/**
 * export_excel?mode=document — the LLM-designed spreadsheet harness.
 *
 * Contract pinned here:
 *  - markdown tables in the composed answer drive the workbook: the table's header row IS
 *    the column headers (custom names), the nearest preceding heading names the worksheet;
 *  - allowlisted aggregate formulas (=SUM(C2:C10)…) survive as live formulas — calculations
 *    are Excel's, not the LLM's;
 *  - any other formula-leading cell is neutralized (injection guard);
 *  - parser is asserted without PhpSpreadsheet; real bytes only when it is installed.
 */
class DocumentExcelExportTest extends TestCase
{
    private function exporter(): ResultSetExporter
    {
        return new ResultSetExporter();
    }

    /** @test */
    public function it_extracts_tables_with_custom_headers_and_heading_names(): void
    {
        $markdown = "## Team Members\n\n"
            . "| Full name | Email address | Hours |\n"
            . "|---|---|---|\n"
            . "| Alice | alice@example.com | 12 |\n"
            . "| Bob | bob@example.com | 30 |\n"
            . "| Total | | =SUM(C2:C3) |\n\n"
            . "Some prose in between.\n\n"
            . "### Budget\n"
            . "| Line | Amount |\n"
            . "| --- | ---: |\n"
            . "| Cleaning | 1,200.50 |\n";

        $tables = $this->exporter()->extractMarkdownTables($markdown);

        $this->assertCount(2, $tables);

        $this->assertSame('Team Members', $tables[0]['name']);
        $this->assertSame(['Full name', 'Email address', 'Hours'], $tables[0]['headers']);
        $this->assertCount(3, $tables[0]['rows']);
        $this->assertSame('=SUM(C2:C3)', $tables[0]['rows'][2][2]);

        $this->assertSame('Budget', $tables[1]['name']);
        $this->assertSame(['Line', 'Amount'], $tables[1]['headers']);
        $this->assertSame('1,200.50', $tables[1]['rows'][0][1]);
    }

    /** @test */
    public function ragged_rows_are_normalized_to_the_header_width(): void
    {
        $markdown = "| A | B | C |\n|---|---|---|\n| 1 | 2 |\n| 1 | 2 | 3 | 4 |\n";

        $tables = $this->exporter()->extractMarkdownTables($markdown);

        $this->assertCount(1, $tables);
        $this->assertSame(['1', '2', ''], $tables[0]['rows'][0]);
        $this->assertSame(['1', '2', '3'], $tables[0]['rows'][1]);
    }

    /** @test */
    public function an_answer_without_tables_errors_with_guidance(): void
    {
        $result = $this->exporter()->documentToExcel("Just prose, no table.", 'Sheet');

        $this->assertTrue($result->isError);
        $this->assertStringContainsStringIgnoringCase('markdown table', $result->summaryForLlm);
    }

    /** @test */
    public function an_empty_answer_errors_before_anything_else(): void
    {
        $result = $this->exporter()->documentToExcel('  ', 'Sheet');

        $this->assertTrue($result->isError);
        $this->assertStringContainsStringIgnoringCase('no composed answer', $result->summaryForLlm);
    }

    /** @test */
    public function document_mode_produces_xlsx_bytes_with_live_safe_formulas_when_phpspreadsheet_is_present(): void
    {
        $exporter = $this->exporter();

        if (!$exporter->canExportExcel()) {
            $this->markTestSkipped('PhpSpreadsheet not installed (expected in the package vendor).');
        }

        $markdown = "## Costs\n"
            . "| Item | Amount |\n|---|---|\n"
            . "| A | 10 |\n| B | 32.5 |\n"
            . "| Total | =SUM(B2:B3) |\n"
            . "| Sneaky | =HYPERLINK(\"http://evil\") |\n";

        $result = $exporter->documentToExcel($markdown, 'Costs');

        $this->assertFalse($result->isError);

        // Round-trip through PhpSpreadsheet to assert the harness contract on real cells.
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $result->renderable['content'] ?? $result->payload['content'] ?? '');
        $readerClass = '\\PhpOffice\\PhpSpreadsheet\\IOFactory';
        $workbook = $readerClass::load($tmp);
        $sheet = $workbook->getActiveSheet();

        $this->assertSame('Costs', $sheet->getTitle());
        $this->assertSame('Item', $sheet->getCell('A1')->getValue());
        $this->assertSame(42.5, $sheet->getCell('B4')->getCalculatedValue()); // live =SUM
        $this->assertStringContainsString('HYPERLINK', (string) $sheet->getCell('B5')->getValue());
        $this->assertStringStartsNotWith('=', (string) $sheet->getCell('B5')->getValue()); // neutralized

        unlink($tmp);
    }

    /** @test */
    public function the_action_routes_document_mode_to_the_composed_answer(): void
    {
        $exporter = new class extends ResultSetExporter {
            public ?string $documentMarkdown = null;
            public ?array $tableRows = null;

            public function documentToExcel(string $markdown, string $title = 'document'): AiActionResult
            {
                $this->documentMarkdown = $markdown;
                return AiActionResult::error('captured');
            }

            public function toExcel(array $rows, string $title = 'export'): AiActionResult
            {
                $this->tableRows = $rows;
                return AiActionResult::error('captured');
            }
        };

        $action = new ExportExcelAction($exporter);
        $context = new ActionContext(
            resultSet: [['name' => 'Alice']],
            answer: "| H |\n|---|\n| v |",
        );

        $action->handle(['mode' => 'document'], $context);
        $this->assertSame("| H |\n|---|\n| v |", $exporter->documentMarkdown);
        $this->assertNull($exporter->tableRows);

        $action->handle([], $context);
        $this->assertSame([['name' => 'Alice']], $exporter->tableRows);
    }

    /** @test */
    public function document_mode_prefers_clean_document_markdown_over_visible_answer(): void
    {
        $exporter = new class extends ResultSetExporter {
            public ?string $documentMarkdown = null;

            public function documentToExcel(string $markdown, string $title = 'document'): AiActionResult
            {
                $this->documentMarkdown = $markdown;
                return AiActionResult::error('captured');
            }
        };

        $context = new ActionContext(
            answer: 'Visible chat answer with [Download Excel](action://export_excel?mode=document)',
            documentMarkdown: "| H |\n|---|\n| clean |",
        );

        (new ExportExcelAction($exporter))->handle(['mode' => 'document'], $context);

        $this->assertSame("| H |\n|---|\n| clean |", $exporter->documentMarkdown);
    }

    /** @test Phase 8 batch A: no rows → markdown → re-parsed cells round trip. */
    public function document_mode_writes_the_persisted_document_rows_directly(): void
    {
        $exporter = new class extends ResultSetExporter {
            public ?array $documentRows = null;
            public ?array $documentHeaders = null;
            public ?string $documentMarkdown = null;

            public function documentRowsToExcel(array $rows, string $title = 'document', array $headers = []): AiActionResult
            {
                $this->documentRows = $rows;
                $this->documentHeaders = $headers;
                return AiActionResult::error('captured');
            }

            public function documentToExcel(string $markdown, string $title = 'document'): AiActionResult
            {
                $this->documentMarkdown = $markdown;
                return AiActionResult::error('captured');
            }
        };

        $context = ActionContext::fromArray([
            'document_markdown' => "| Name |\n|---|\n| Karen |",
            'document_rows' => [['name' => 'Karen', 'due_amount' => 11493.17]],
        ]);

        (new ExportExcelAction($exporter))->handle(['mode' => 'document'], $context);

        $this->assertSame([['name' => 'Karen', 'due_amount' => 11493.17]], $exporter->documentRows);
        $this->assertSame([], $exporter->documentHeaders, 'a message persisted before the headers existed has none');
        $this->assertNull($exporter->documentMarkdown, 'the markdown is not re-parsed when rows exist');
    }

    /**
     * @test Eval p17-B cold-roster-excel: the composed document's own column headers (column
     * key => header, persisted beside the rows) reach the .xlsx writer.
     */
    public function document_mode_hands_the_persisted_headers_to_the_writer(): void
    {
        $exporter = new class extends ResultSetExporter {
            public ?array $documentHeaders = null;

            public function documentRowsToExcel(array $rows, string $title = 'document', array $headers = []): AiActionResult
            {
                $this->documentHeaders = $headers;
                return AiActionResult::error('captured');
            }
        };

        $context = ActionContext::fromArray([
            'document_rows' => [['name' => 'Julie', 'contact_info' => 'j@x.test']],
            'document_headers' => ['name' => 'Name', 'contact_info' => 'Email', 7 => 'ignored', 'blank' => ' '],
        ]);

        (new ExportExcelAction($exporter))->handle(['mode' => 'document'], $context);

        $this->assertSame(['name' => 'Name', 'contact_info' => 'Email'], $exporter->documentHeaders);
    }

    /** @test The header row of the workbook is the document's headers; keys without one keep their humanized key. */
    public function the_workbook_header_row_uses_the_document_headers(): void
    {
        $exporter = $this->exporter();

        if (!$exporter->canExportExcel()) {
            $this->markTestSkipped('PhpSpreadsheet not installed (expected in the package vendor).');
        }

        $result = $exporter->documentRowsToExcel(
            [['name' => 'Julie Rousseau', 'contact_info' => 'j.rousseau@novogestion.ca', 'last_login' => '2026-10-01']],
            'Members',
            ['name' => 'Name', 'contact_info' => 'Email']
        );

        $this->assertFalse($result->isError);

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $result->renderable['content'] ?? $result->payload['content'] ?? '');
        $readerClass = '\\PhpOffice\\PhpSpreadsheet\\IOFactory';
        $sheet = $readerClass::load($tmp)->getActiveSheet();
        unlink($tmp);

        $this->assertSame('Name', $sheet->getCell('A1')->getValue());
        $this->assertSame('Email', $sheet->getCell('B1')->getValue());
        $this->assertSame('Last Login', $sheet->getCell('C1')->getValue());
        $this->assertSame('j.rousseau@novogestion.ca', $sheet->getCell('B2')->getValue());
    }
}
