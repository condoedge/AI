<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions;

use Condoedge\Ai\Services\Actions\ActionInvocation;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Response\ActionLinkHandler;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\CapturingAction;
use PHPUnit\Framework\TestCase;

/**
 * ActionInvocation: the canonical value object markdown link regex captures (fromLinkMatch())
 * and persisted message-metadata records (fromArray()) converge on before anything reaches
 * the shared dispatch pipeline.
 *
 * The link fixtures are the SAME strings ActionLinkHandlerParamsTest uses, matched against
 * the handler's OWN regex constants (read via reflection), so parity is pinned at the source
 * patterns, not a copy of them.
 */
class ActionInvocationTest extends TestCase
{
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
    public function to_array_from_array_round_trips_constructed_and_link_invocations(): void
    {
        $constructed = new ActionInvocation('profile', 'Person', '152463', ['field' => 'email'], '');
        $fromLink = ActionInvocation::fromLinkMatch(
            $this->matchLink('ACTION_PATTERN', '[Charge](action://charge?unit_id=123)')
        );

        foreach ([$constructed, $fromLink] as $original) {
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

        $filtered = (new ActionInvocation('table', null, null, [], ''))
            ->filtered($action, $registry);

        $this->assertSame(['limit' => 10], $filtered->params);
    }

    /** @test */
    public function fingerprints_match_between_a_typed_record_and_a_link_for_the_same_logical_action(): void
    {
        // Generic: typed params vs string link params, different order, different text.
        $typed = new ActionInvocation('charge', null, null, ['amount' => 450, 'unit_id' => 123], '');
        $link = ActionInvocation::fromLinkMatch(
            $this->matchLink('ACTION_PATTERN', '[Pay now](action://charge?unit_id=123&amount=450)')
        );
        $this->assertSame($typed->fingerprint(), $link->fingerprint());

        // Entity: typed int id vs id via path segment.
        $typedEntity = new ActionInvocation('profile', 'Person', 152463, ['field' => 'email'], '');
        $linkEntity = ActionInvocation::fromLinkMatch(
            $this->matchLink('ENTITY_PATTERN', '[John](entity://Person/152463/profile?field=email)')
        );
        $this->assertSame($typedEntity->fingerprint(), $linkEntity->fingerprint());
    }

    /** @test */
    public function fingerprints_differ_for_distinct_logical_actions(): void
    {
        $base = new ActionInvocation('charge', null, null, ['unit_id' => 123], '');

        $otherKey = new ActionInvocation('refund', null, null, ['unit_id' => 123], '');
        $otherArgs = new ActionInvocation('charge', null, null, ['unit_id' => 999], '');
        $otherEntity = new ActionInvocation('charge', 'Person', 1, ['unit_id' => 123], '');

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
