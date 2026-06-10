<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\DTOs\LlmToolCall;
use Condoedge\Ai\Services\Actions\ActionInvocation;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Response\ActionLinkHandler;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\CapturingAction;
use PHPUnit\Framework\TestCase;

/**
 * ActionInvocation (P1-C1 / T2): the canonical value object BOTH transports converge on —
 * native tool calls via fromToolCall(), markdown link regex captures via fromLinkMatch() —
 * before anything reaches the shared dispatch pipeline.
 *
 * The link fixtures are the SAME strings ActionLinkHandlerParamsTest uses, matched against
 * the handler's OWN regex constants (read via reflection), so cross-channel parity is pinned
 * at the source patterns, not a copy of them.
 */
class ActionInvocationTest extends TestCase
{
    /** @test */
    public function from_tool_call_parses_a_generic_call(): void
    {
        $invocation = ActionInvocation::fromToolCall(
            new LlmToolCall('call_1', 'charge', ['unit_id' => 123, 'amount' => 450.0])
        );

        $this->assertSame('charge', $invocation->key);
        $this->assertNull($invocation->entityType);
        $this->assertNull($invocation->entityId);
        $this->assertSame(['unit_id' => 123, 'amount' => 450.0], $invocation->params);
        $this->assertSame('', $invocation->text);
    }

    /** @test */
    public function from_tool_call_parses_an_entity_call_and_routes_the_id_out_of_params(): void
    {
        $invocation = ActionInvocation::fromToolCall(
            new LlmToolCall('call_2', 'Person__profile', ['entity_id' => '152463', 'field' => 'email'])
        );

        $this->assertSame('profile', $invocation->key);
        $this->assertSame('Person', $invocation->entityType);
        $this->assertSame('152463', $invocation->entityId);
        // The id is context-routed like the entity:// path segment — never left among params.
        $this->assertSame(['field' => 'email'], $invocation->params);
    }

    /** @test */
    public function from_tool_call_tolerates_a_missing_or_blank_entity_id(): void
    {
        $missing = ActionInvocation::fromToolCall(new LlmToolCall('c', 'Person__profile', ['field' => 'email']));
        $this->assertNull($missing->entityId);
        $this->assertSame(['field' => 'email'], $missing->params);

        $blank = ActionInvocation::fromToolCall(new LlmToolCall('c', 'Person__profile', ['entity_id' => '']));
        $this->assertNull($blank->entityId);
        $this->assertSame([], $blank->params);
    }

    /** @test */
    public function from_link_match_parses_a_generic_action_link(): void
    {
        $match = $this->matchLink(
            'ACTION_PATTERN',
            '[Charge](action://charge?unit_id=123&amount=450&evil=DROP)'
        );

        $invocation = ActionInvocation::fromLinkMatch($match);

        $this->assertSame('charge', $invocation->key);
        $this->assertNull($invocation->entityType);
        $this->assertNull($invocation->entityId);
        $this->assertSame(['unit_id' => '123', 'amount' => '450', 'evil' => 'DROP'], $invocation->params);
        $this->assertSame('Charge', $invocation->text);
    }

    /** @test */
    public function from_link_match_parses_an_entity_link(): void
    {
        $match = $this->matchLink(
            'ENTITY_PATTERN',
            '[John](entity://Person/152463/profile?field=email&forbidden=x)'
        );

        $invocation = ActionInvocation::fromLinkMatch($match);

        $this->assertSame('profile', $invocation->key);
        $this->assertSame('Person', $invocation->entityType);
        $this->assertSame('152463', $invocation->entityId);
        $this->assertSame(['field' => 'email', 'forbidden' => 'x'], $invocation->params);
        $this->assertSame('John', $invocation->text);
    }

    /** @test */
    public function from_link_match_handles_param_less_links(): void
    {
        $generic = ActionInvocation::fromLinkMatch(
            $this->matchLink('ACTION_PATTERN', '[Settings](action://settings)')
        );
        $this->assertSame('settings', $generic->key);
        $this->assertSame([], $generic->params);

        $entity = ActionInvocation::fromLinkMatch(
            $this->matchLink('ENTITY_PATTERN', '[John](entity://Person/1/profile)')
        );
        $this->assertSame('profile', $entity->key);
        $this->assertSame('1', $entity->entityId);
        $this->assertSame([], $entity->params);
    }

    /** @test */
    public function to_array_from_array_round_trips_both_transports(): void
    {
        $fromTool = ActionInvocation::fromToolCall(
            new LlmToolCall('c', 'Person__profile', ['entity_id' => '152463', 'field' => 'email'])
        );
        $fromLink = ActionInvocation::fromLinkMatch(
            $this->matchLink('ACTION_PATTERN', '[Charge](action://charge?unit_id=123)')
        );

        foreach ([$fromTool, $fromLink] as $original) {
            $revived = ActionInvocation::fromArray($original->toArray());

            $this->assertNotNull($revived);
            $this->assertSame($original->key, $revived->key);
            $this->assertSame($original->entityType, $revived->entityType);
            $this->assertSame($original->entityId, $revived->entityId);
            $this->assertSame($original->params, $revived->params);
            $this->assertSame($original->text, $revived->text);
            $this->assertSame($original->fingerprint(), $revived->fingerprint());
        }
    }

    /** @test */
    public function from_array_fails_closed_on_malformed_records(): void
    {
        $malformed = [
            'not an array' => 'action://charge',
            'null' => null,
            'empty' => [],
            'non-string key' => ['key' => 123],
            'blank key' => ['key' => '  '],
            'non-array params' => ['key' => 'charge', 'params' => 'unit_id=123'],
            'array entity_id' => ['key' => 'charge', 'entity_id' => ['1']],
            'non-string entity_type' => ['key' => 'charge', 'entity_type' => 42],
            'non-string text' => ['key' => 'charge', 'text' => ['x']],
        ];

        foreach ($malformed as $label => $record) {
            $this->assertNull(ActionInvocation::fromArray($record), "malformed record must render nothing: {$label}");
        }

        // Minimal-but-valid record still revives (absent optional fields default safely).
        $minimal = ActionInvocation::fromArray(['key' => 'charge']);
        $this->assertNotNull($minimal);
        $this->assertSame('charge', $minimal->key);
        $this->assertSame([], $minimal->params);
        $this->assertSame('', $minimal->text);
    }

    /** @test */
    public function filtered_drops_undeclared_params_and_casts_declared_ones(): void
    {
        // Same schema + link fixture as ActionLinkHandlerParamsTest — pins cross-channel parity.
        $registry = new AiActionRegistry();
        $action = new CapturingAction('charge', static fn () => 'ok', [
            'unit_id' => ['type' => 'int'],
            'amount' => ['type' => 'float'],
        ]);

        $invocation = ActionInvocation::fromLinkMatch(
            $this->matchLink('ACTION_PATTERN', '[Charge](action://charge?unit_id=123&amount=450&evil=DROP)')
        );

        $filtered = $invocation->filtered($action, $registry);

        $this->assertSame(['unit_id' => 123, 'amount' => 450.0], $filtered->params);
        $this->assertArrayNotHasKey('evil', $filtered->params);
        // Everything but the params is preserved verbatim.
        $this->assertSame($invocation->key, $filtered->key);
        $this->assertSame($invocation->entityType, $filtered->entityType);
        $this->assertSame($invocation->entityId, $filtered->entityId);
        $this->assertSame($invocation->text, $filtered->text);
        // The original is untouched (value object).
        $this->assertArrayHasKey('evil', $invocation->params);
    }

    /** @test */
    public function filtered_applies_schema_defaults(): void
    {
        $registry = new AiActionRegistry();
        $action = new CapturingAction('table', static fn () => 'ok', [
            'limit' => ['type' => 'int', 'default' => 10],
        ]);

        $filtered = ActionInvocation::fromToolCall(new LlmToolCall('c', 'table', []))
            ->filtered($action, $registry);

        $this->assertSame(['limit' => 10], $filtered->params);
    }

    /** @test */
    public function fingerprints_match_across_transports_for_the_same_logical_action(): void
    {
        // Generic: typed tool args vs string link params, different order, different text.
        $tool = ActionInvocation::fromToolCall(
            new LlmToolCall('c', 'charge', ['amount' => 450, 'unit_id' => 123])
        );
        $link = ActionInvocation::fromLinkMatch(
            $this->matchLink('ACTION_PATTERN', '[Pay now](action://charge?unit_id=123&amount=450)')
        );
        $this->assertSame($tool->fingerprint(), $link->fingerprint());

        // Entity: id via argument vs id via path segment.
        $toolEntity = ActionInvocation::fromToolCall(
            new LlmToolCall('c', 'Person__profile', ['entity_id' => 152463, 'field' => 'email'])
        );
        $linkEntity = ActionInvocation::fromLinkMatch(
            $this->matchLink('ENTITY_PATTERN', '[John](entity://Person/152463/profile?field=email)')
        );
        $this->assertSame($toolEntity->fingerprint(), $linkEntity->fingerprint());
    }

    /** @test */
    public function fingerprints_differ_for_distinct_logical_actions(): void
    {
        $base = ActionInvocation::fromToolCall(new LlmToolCall('c', 'charge', ['unit_id' => 123]));

        $otherKey = ActionInvocation::fromToolCall(new LlmToolCall('c', 'refund', ['unit_id' => 123]));
        $otherArgs = ActionInvocation::fromToolCall(new LlmToolCall('c', 'charge', ['unit_id' => 999]));
        $otherEntity = ActionInvocation::fromToolCall(new LlmToolCall('c', 'Person__charge', ['entity_id' => 1, 'unit_id' => 123]));

        $this->assertNotSame($base->fingerprint(), $otherKey->fingerprint());
        $this->assertNotSame($base->fingerprint(), $otherArgs->fingerprint());
        $this->assertNotSame($base->fingerprint(), $otherEntity->fingerprint());
    }

    /**
     * Run one of the link handler's OWN private regex constants against a link string and
     * return the capture array exactly as the content-link pipeline would produce it.
     *
     * @return array<int, string>
     */
    private function matchLink(string $constant, string $link): array
    {
        $pattern = (new \ReflectionClassConstant(ActionLinkHandler::class, $constant))->getValue();

        $this->assertSame(1, preg_match($pattern, $link, $match), "fixture must match {$constant}: {$link}");

        return $match;
    }
}
