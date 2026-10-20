<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\ActionKind;
use Condoedge\Ai\Services\Actions\BuiltIn\ExportExcelAction;
use Condoedge\Ai\Services\Actions\BuiltIn\OpenModalAction;
use Condoedge\Ai\Services\Actions\BuiltIn\RenderTableAction;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use PHPUnit\Framework\TestCase;

/**
 * Built-in generative-UI actions: typed-result contracts + offline (no-Kompo) behavior.
 * These run under pure PHPUnit so the _Link/_Button helpers are absent — actions return typed
 * DATA + INTENT (an ActionKind + payload), which is the contract we assert here. The renderable
 * projection (the back-compat view of payload) is exercised via $result->renderable.
 */
class BuiltInActionsTest extends TestCase
{
    /** @test */
    public function open_modal_produces_an_element_result_and_requires_a_target(): void
    {
        $action = new OpenModalAction();

        $this->assertSame('open_modal', $action->key());

        // No modal target → graceful error.
        $err = $action->handle([], new ActionContext());
        $this->assertInstanceOf(AiActionResult::class, $err);
        $this->assertTrue($err->isError);
    }

    /** @test */
    public function open_modal_carries_prefilled_defaults_as_intent_offline(): void
    {
        $action = new OpenModalAction();

        $result = $action->handle(
            ['modal' => 'editUnit', 'amount' => 450],
            ActionContext::fromArray(['text' => 'Open'])
        );

        $this->assertFalse($result->isError);
        $this->assertSame(ActionKind::Element, $result->kind);
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
            ['id' => 7, 'name' => 'Alice', 'amount' => 100, 'action_links' => ['[View](entity://P/7/view)']],
            ['id' => 8, 'name' => 'Bob', 'amount' => 250],
        ];

        $result = $action->handle(['limit' => 10], ActionContext::fromArray(['result_set' => $rows]));

        $this->assertFalse($result->isError);
        $this->assertSame(ActionKind::Table, $result->kind);
        $this->assertSame('table', $result->renderable['type']);
        // Phase 8 batch A: THE TableRenderer — humanized headers, internal ids and
        // UI decorations redacted like every other table/export.
        $this->assertSame(['Name', 'Amount'], $result->renderable['headers']);
        $this->assertCount(2, $result->renderable['rows']);
        $this->assertSame(['Alice', '100'], $result->renderable['rows'][0]);
    }

    /** @test */
    public function render_table_degrades_when_no_data(): void
    {
        $action = new RenderTableAction();
        $result = $action->handle([], ActionContext::fromArray(['result_set' => []]));

        $this->assertTrue($result->isError);
    }

    /** @test */
    public function export_excel_produces_an_export_result_and_no_data_degrades(): void
    {
        $action = new ExportExcelAction();

        $this->assertContains('export excel', $action->aliases());

        $result = $action->handle(['title' => 'x'], ActionContext::fromArray(['result_set' => []]));
        $this->assertTrue($result->isError);
    }
}
