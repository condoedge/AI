<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\ActionKind;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use PHPUnit\Framework\TestCase;

/**
 * The typed action result: handle() returns data + intent (an ActionKind + payload), never a
 * Kompo element. Consumers switch on the kind enum, not on the array shape. This replaces the
 * old renderable:mixed boundary that forced every consumer to re-sniff is_array(...)&&isset(...).
 */
class ActionResultTest extends TestCase
{
    /** @test */
    public function element_factory_carries_element_kind_and_payload(): void
    {
        $result = AiActionResult::element(['route' => 'events.show', 'id' => 7], 'Created event.');

        $this->assertSame(ActionKind::Element, $result->kind);
        $this->assertSame(['route' => 'events.show', 'id' => 7], $result->payload);
        $this->assertSame('Created event.', $result->summaryForLlm);
        $this->assertFalse($result->isError);
    }

    /** @test */
    public function table_factory_carries_table_kind_and_normalized_payload(): void
    {
        $result = AiActionResult::table(
            ['headers' => ['name'], 'rows' => [['Alice']]],
            'Rendered 1 row.'
        );

        $this->assertSame(ActionKind::Table, $result->kind);
        $this->assertSame(['name'], $result->payload['headers']);
        $this->assertSame([['Alice']], $result->payload['rows']);
        $this->assertFalse($result->isError);
    }

    /** @test */
    public function export_factory_carries_export_kind_and_download_payload(): void
    {
        $result = AiActionResult::export('BYTES', 'application/pdf', 'report.pdf', 'Generated PDF.');

        $this->assertSame(ActionKind::Export, $result->kind);
        $this->assertSame('BYTES', $result->payload['content']);
        $this->assertSame('application/pdf', $result->payload['mime']);
        $this->assertSame('report.pdf', $result->payload['filename']);
        $this->assertFalse($result->isError);
    }

    /** @test */
    public function pending_factory_carries_pending_kind_and_token_payload(): void
    {
        $result = AiActionResult::pending('TOKEN', 'This will delete. Confirm?', 'Proposed: Delete', [
            'action_key' => 'delete',
            'label' => 'Delete',
        ]);

        $this->assertSame(ActionKind::Pending, $result->kind);
        $this->assertSame('TOKEN', $result->payload['token']);
        $this->assertSame('This will delete. Confirm?', $result->payload['confirm_message']);
        $this->assertSame('delete', $result->payload['action_key']);
        $this->assertSame('Delete', $result->payload['label']);
        // A pending result is NOT an error.
        $this->assertFalse($result->isError);
    }

    /** @test */
    public function error_factory_carries_error_kind_and_is_flagged(): void
    {
        $result = AiActionResult::error('No data available to export.');

        $this->assertSame(ActionKind::Error, $result->kind);
        $this->assertTrue($result->isError);
        $this->assertSame('No data available to export.', $result->summaryForLlm);
    }

    /** @test */
    public function action_context_is_a_readonly_identity_carrying_dto(): void
    {
        $ctx = new ActionContext(
            user: 'the-user',
            team: 'the-team',
            entityType: 'Unit',
            entityId: '402',
            text: 'Open',
            input: ['amount' => 450],
            resultSet: [['name' => 'Alice']],
        );

        $this->assertSame('the-user', $ctx->user);
        $this->assertSame('the-team', $ctx->team);
        $this->assertSame('Unit', $ctx->entityType);
        $this->assertSame('402', $ctx->entityId);
        $this->assertSame('Open', $ctx->text);
        $this->assertSame(['amount' => 450], $ctx->input);
        $this->assertSame([['name' => 'Alice']], $ctx->resultSet);
    }

    /** @test */
    public function action_context_builds_from_a_loose_context_array(): void
    {
        $ctx = ActionContext::fromArray([
            'user' => 'u',
            'entity_type' => 'Person',
            'entity_id' => '152463',
            'text' => 'John',
            'result_set' => [['x' => 1]],
        ], ['amount' => 450]);

        $this->assertSame('u', $ctx->user);
        $this->assertSame('Person', $ctx->entityType);
        $this->assertSame('152463', $ctx->entityId);
        $this->assertSame('John', $ctx->text);
        $this->assertSame(['amount' => 450], $ctx->input);
        $this->assertSame([['x' => 1]], $ctx->resultSet);
    }
}
