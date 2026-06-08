<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\Services\Actions\ConfigAiAction;
use PHPUnit\Framework\TestCase;

/**
 * ConfigAiAction adapter: wraps legacy config entries (closure or array) with SAFE DEFAULTS
 * and invokes the wrapped closure with backward-compatible positional args.
 *
 * Pure PHPUnit TestCase — no framework boot needed.
 */
class ConfigAiActionTest extends TestCase
{
    /** @test */
    public function it_invokes_entity_closure_with_legacy_id_and_text_args(): void
    {
        $action = new ConfigAiAction('profile', [
            'action' => fn($id, $text = 'View') => "person:{$id}:{$text}",
            'label' => 'View Profile',
        ], 'Person');

        $result = $action->handle([], ['entity_id' => '152463', 'text' => 'John']);

        $this->assertSame('person:152463:John', $result);
    }

    /** @test */
    public function it_invokes_generic_closure_with_text_arg(): void
    {
        $action = new ConfigAiAction('settings', [
            'action' => fn($text = 'Settings') => "go:{$text}",
        ]);

        $this->assertSame('go:Prefs', $action->handle([], ['text' => 'Prefs']));
    }

    /** @test */
    public function it_supports_bare_closure_entries_with_safe_defaults(): void
    {
        $action = new ConfigAiAction('quick', fn($text = 'x') => "q:{$text}");

        $this->assertSame('quick', $action->key());
        $this->assertSame('quick', $action->label());
        $this->assertSame([], $action->aliases());
        $this->assertSame([], $action->inputSchema());
        $this->assertSame('link', $action->presentation());
        $this->assertFalse($action->requiresApproval());
        $this->assertTrue($action->authorize(null, []));
        $this->assertSame('q:hi', $action->handle([], ['text' => 'hi']));
    }

    /** @test */
    public function it_passes_validated_params_as_trailing_arg_to_new_style_closures(): void
    {
        $action = new ConfigAiAction('charge', [
            'action' => fn($id, $text, array $input) => "charge:{$id}:{$input['amount']}",
            'input_schema' => ['amount' => ['type' => 'int']],
        ], 'Account');

        $result = $action->handle(['amount' => 450], ['entity_id' => '7', 'text' => 'Charge']);

        $this->assertSame('charge:7:450', $result);
    }

    /** @test */
    public function it_reads_approval_and_authorize_from_array_config(): void
    {
        $action = new ConfigAiAction('delete', [
            'action' => fn($id, $text = 'Delete') => "del:{$id}",
            'requires_approval' => true,
            'authorize' => fn($user, $input) => $user === 'admin',
        ], 'Record');

        $this->assertTrue($action->requiresApproval());
        $this->assertTrue($action->authorize('admin', []));
        $this->assertFalse($action->authorize('guest', []));
    }

    /** @test */
    public function it_returns_null_when_action_closure_is_missing(): void
    {
        $action = new ConfigAiAction('broken', ['label' => 'Broken']);

        $this->assertNull($action->handle([], []));
        $this->assertNull($action->resolver());
    }

    /** @test */
    public function it_respects_closure_arity_for_zero_arg_closures(): void
    {
        $action = new ConfigAiAction('ping', ['action' => fn() => 'pong']);

        $this->assertSame('pong', $action->handle([], ['text' => 'ignored']));
    }
}
