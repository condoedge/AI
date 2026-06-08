<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\Services\Actions\BuiltIn\ExportExcelAction;
use Condoedge\Ai\Services\Actions\BuiltIn\OpenModalAction;
use Condoedge\Ai\Services\Actions\BuiltIn\RenderTableAction;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use PHPUnit\Framework\TestCase;

/**
 * Built-in generative-UI actions: presentation contracts + offline (no-Kompo) behavior.
 * These run under pure PHPUnit so the _Link/_Button helpers are absent — actions fall back to
 * structured intents, which is the contract we assert here.
 */
class BuiltInActionsTest extends TestCase
{
    /** @test */
    public function open_modal_declares_modal_presentation_and_requires_a_target(): void
    {
        $action = new OpenModalAction();

        $this->assertSame('open_modal', $action->key());
        $this->assertSame('modal', $action->presentation());

        // No modal target → graceful error.
        $err = $action->handle([], []);
        $this->assertInstanceOf(AiActionResult::class, $err);
        $this->assertTrue($err->isError);
    }

    /** @test */
    public function open_modal_carries_prefilled_defaults_as_intent_offline(): void
    {
        $action = new OpenModalAction();

        $result = $action->handle(['modal' => 'editUnit', 'amount' => 450], ['text' => 'Open']);

        $this->assertFalse($result->isError);
        // Offline → structured intent (no Kompo helpers available in pure phpunit).
        $this->assertIsArray($result->renderable);
        $this->assertSame('editUnit', $result->renderable['modal']);
        $this->assertSame(450, $result->renderable['prefill']['amount']);
    }

    /** @test */
    public function render_table_normalizes_a_result_set_into_headers_and_rows(): void
    {
        $action = new RenderTableAction();

        $rows = [
            ['name' => 'Alice', 'amount' => 100],
            ['name' => 'Bob', 'amount' => 250],
        ];

        $result = $action->handle(['limit' => 10], ['result_set' => $rows]);

        $this->assertFalse($result->isError);
        $this->assertSame('table', $result->renderable['type']);
        $this->assertSame(['name', 'amount'], $result->renderable['headers']);
        $this->assertCount(2, $result->renderable['rows']);
        $this->assertSame(['Alice', '100'], $result->renderable['rows'][0]);
    }

    /** @test */
    public function render_table_degrades_when_no_data(): void
    {
        $action = new RenderTableAction();
        $result = $action->handle([], ['result_set' => []]);

        $this->assertTrue($result->isError);
    }

    /** @test */
    public function export_excel_presentation_and_no_data_degrade(): void
    {
        $action = new ExportExcelAction();

        $this->assertSame('export', $action->presentation());
        $this->assertContains('export excel', $action->aliases());

        $result = $action->handle(['title' => 'x'], ['result_set' => []]);
        $this->assertTrue($result->isError);
    }
}
