<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Services\Response\ActionLinkHandler;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\CapturingAction;
use Orchestra\Testbench\TestCase;
use Mockery;

/**
 * HONEST FAILURE for exports.
 *
 * When an export action returns an error AiActionResult (e.g. the host has not installed
 * phpspreadsheet / dompdf), the export link must NOT silently vanish. The export-result branch
 * of ActionLinkHandler surfaces the error's user-facing summary as a visible element instead of
 * dropping it to null — so the user learns the export requires a package, rather than clicking an
 * inert link.
 *
 * A *generic* (non-export) action that errors keeps the old behavior (dropped silently), so this
 * change is scoped to the export branch only.
 */
class ActionLinkHandlerExportTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function handler(AiActionRegistry $registry): ActionLinkHandler
    {
        $discovery = Mockery::mock(EntityAutoDiscovery::class);
        return new ActionLinkHandler($discovery, new ActionDispatcher($registry));
    }

    /** @test */
    public function export_excel_error_surfaces_a_visible_message_not_a_silent_drop(): void
    {
        $registry = new AiActionRegistry();
        // An export action whose key starts with export_ returning an isError result models the
        // missing-library degradation path from ResultSetExporter.
        $registry->registerGeneric(new CapturingAction(
            'export_excel',
            fn() => AiActionResult::error('Excel export requires the maatwebsite/excel package.'),
        ));

        $elements = $this->handler($registry)
            ->createElements('[Download Excel](action://export_excel?title=Report)');

        // Exactly one element produced — the honest failure message, not an empty array.
        $this->assertCount(1, $elements);
        $this->assertStringContainsString('maatwebsite/excel', $this->renderToString($elements[0]));
    }

    /** @test */
    public function export_pdf_error_surfaces_a_visible_message(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new CapturingAction(
            'export_pdf',
            fn() => AiActionResult::error('PDF export requires the dompdf package.'),
        ));

        $elements = $this->handler($registry)
            ->createElements('[Download PDF](action://export_pdf)');

        $this->assertCount(1, $elements);
        $this->assertStringContainsString('dompdf', $this->renderToString($elements[0]));
    }

    /** @test */
    public function non_export_action_error_is_still_dropped_silently(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new CapturingAction(
            'charge',
            fn() => AiActionResult::error('charge failed for some internal reason'),
        ));

        $elements = $this->handler($registry)->createElements('[Charge](action://charge)');

        // Non-export failures keep failing closed (no internal error leaked into the bubble).
        $this->assertSame([], $elements);
    }

    /**
     * Render a Kompo element down to a string we can assert on (the element exposes its rendered
     * markup via render() in the booted Testbench app).
     */
    private function renderToString(mixed $element): string
    {
        if (is_string($element)) {
            return $element;
        }

        if (is_object($element) && method_exists($element, 'render')) {
            return (string) json_encode($element->render());
        }

        return (string) json_encode($element);
    }
}
