<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Chat\Exporter;

use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\BuiltIn\ExportPdfAction;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Chat\Exporter\ResultSetExporter;
use PHPUnit\Framework\TestCase;

/**
 * export_pdf?mode=document — LLM-composed documents (contact sheets, summaries) rendered
 * markdown→HTML→PDF, vs the default mode=table result-set dump.
 *
 * Follows ResultSetExporterTest's environment-tolerant pattern: HTML building and routing
 * are asserted unconditionally (no dompdf needed); actual PDF bytes only when Dompdf is
 * installed.
 */
class DocumentPdfExportTest extends TestCase
{
    /** @test */
    public function document_mode_with_an_empty_answer_errors_before_anything_else(): void
    {
        $result = (new ResultSetExporter())->documentToPdf('   ', 'Contact Sheet');

        $this->assertInstanceOf(AiActionResult::class, $result);
        $this->assertTrue($result->isError);
        $this->assertStringContainsStringIgnoringCase('no composed answer', $result->summaryForLlm);
    }

    /** @test */
    public function document_html_converts_markdown_and_strips_app_links(): void
    {
        $markdown = "# Contact Sheet\n\n"
            . "| Name | Email |\n|---|---|\n| Alice | alice@example.com |\n\n"
            . "[Download PDF](action://export_pdf?mode=document) "
            . "[View Alice](entity://Contact/42/view) "
            . "[External docs](https://example.com/help)";

        $html = (new ResultSetExporter())->documentHtml($markdown, 'Contact Sheet');

        // Markdown structure converted (heading + GFM table).
        $this->assertStringContainsString('<h1>Contact Sheet</h1>', $html);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('alice@example.com', $html);

        // App-internal link targets never reach the file; their text survives.
        $this->assertStringNotContainsString('action://', $html);
        $this->assertStringNotContainsString('entity://', $html);
        $this->assertStringContainsString('Download PDF', $html);
        $this->assertStringContainsString('View Alice', $html);

        // Normal external links are untouched by the stripper.
        $this->assertStringContainsString('https://example.com/help', $html);

        // Styled, standalone document.
        $this->assertStringContainsString('<style>', $html);
        $this->assertStringContainsString('DejaVu Sans', $html);
    }

    /** @test */
    public function document_mode_produces_pdf_bytes_when_dompdf_is_present(): void
    {
        $exporter = new ResultSetExporter();

        if (!$exporter->canExportPdf()) {
            $this->markTestSkipped('Dompdf not installed (expected in the package vendor).');
        }

        $result = $exporter->documentToPdf("# Sheet\n\nHello **world**", 'Sheet');

        $this->assertFalse($result->isError);
    }

    /** @test */
    public function the_action_routes_document_mode_to_the_composed_answer(): void
    {
        $exporter = new class extends ResultSetExporter {
            public ?string $documentMarkdown = null;
            public ?array $tableRows = null;

            public function documentToPdf(string $markdown, string $title = 'document'): AiActionResult
            {
                $this->documentMarkdown = $markdown;
                return AiActionResult::error('captured');
            }

            public function toPdf(array $rows, string $title = 'export'): AiActionResult
            {
                $this->tableRows = $rows;
                return AiActionResult::error('captured');
            }
        };

        $action = new ExportPdfAction($exporter);
        $context = new ActionContext(
            resultSet: [['name' => 'Alice']],
            answer: "# Contact Sheet\n\nAlice — alice@example.com",
        );

        $action->handle(['mode' => 'document', 'title' => 'Sheet'], $context);
        $this->assertSame("# Contact Sheet\n\nAlice — alice@example.com", $exporter->documentMarkdown);
        $this->assertNull($exporter->tableRows);

        // Default mode still exports the result set.
        $action->handle(['title' => 'Sheet'], $context);
        $this->assertSame([['name' => 'Alice']], $exporter->tableRows);
    }

    /** @test */
    public function action_context_carries_the_answer_from_the_dispatch_context(): void
    {
        $context = ActionContext::fromArray([
            'answer' => 'Full composed answer',
            'text' => 'Download PDF',
        ]);

        $this->assertSame('Full composed answer', $context->answer);
        $this->assertSame('Download PDF', $context->text);
    }
}
