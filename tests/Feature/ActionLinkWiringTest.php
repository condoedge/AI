<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Feature;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Services\Response\ActionLinkHandler;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Action-link element creation, stripping, and deduplication for the [text](entity://…) /
 * [text](action://…) grammar.
 *
 * Drives the live {@see ActionLinkHandler} (which replaced the old ResponseActionLinkProcessor):
 * it parses the markdown links, resolves each link's action through the {@see AiActionRegistry}
 * + {@see ActionDispatcher}, runs the read-only action, and surfaces whatever that action's
 * handle() renders. So the test registers a real Person/profile entity action that returns a
 * link INTENT (the typed Element-kind renderable — an action's handle() returns DATA + INTENT,
 * never a Kompo element), then asserts the handler turns the markdown links into one element per
 * unique target, deduplicates identical targets, strips links to plain text, and renders nothing
 * when no action resolves.
 *
 * Pure PHPUnit (no booted provider): the registry + dispatcher are container-tolerant, and the
 * handler's discovery dependency is only consulted to resolve actions through the dispatcher's
 * registry — so a Mockery double for EntityAutoDiscovery (whose real construction needs a live
 * DB/schema and segfaults teardown under the fully-booted graph) keeps this a fast, hermetic
 * unit. The action returns a plain intent array rather than a live Kompo element for the same
 * teardown-safety reason; the renderable INTENT is the actual contract the handler surfaces,
 * with the downstream Kompo element exercised by the booted ApprovalCard / ContentLinkProcessor
 * tests.
 */
class ActionLinkWiringTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * A read-only entity action whose handle() returns a link INTENT carrying the entity id. The
     * handler surfaces this verbatim (Element-kind results pass through as their renderable), so
     * each unique link produces exactly one intent element.
     */
    private function linkAction(string $key): AiActionInterface
    {
        return new class($key) implements AiActionInterface {
            public function __construct(private string $key) {}

            public function key(): string { return $this->key; }
            public function label(): string { return 'View'; }
            public function aliases(): array { return []; }
            public function description(): string { return ''; }
            public function inputSchema(): array { return []; }
            public function mutates(): bool { return false; }
            public function authorize(mixed $user, array $input): bool { return true; }

            public function handle(array $input, ActionContext $context): AiActionResult
            {
                return AiActionResult::element(
                    [
                        'action' => 'link',
                        'href' => '/person/' . (string) ($context->entityId ?? '0'),
                        'text' => (string) ($context->text ?? 'View'),
                    ],
                    'Provided a link.'
                );
            }
        };
    }

    /**
     * Build an ActionLinkHandler backed by a real (container-free) registry. By default it carries
     * a Person/profile entity action; pass $withProfile=false for the "no action resolves" case.
     */
    private function handler(bool $withProfile = true): ActionLinkHandler
    {
        // registerBuiltIns=false: avoid reading config() so the registry stays container-free.
        $registry = new AiActionRegistry(registerBuiltIns: false);
        if ($withProfile) {
            $registry->registerEntity('Person', $this->linkAction('profile'));
        }

        // Mockery double avoids constructing the real EntityAutoDiscovery (needs a live DB/schema).
        // The handler never calls discovery directly on the create path — it resolves via the
        // dispatcher's registry — so the double needs no expectations.
        $discovery = Mockery::mock(EntityAutoDiscovery::class);

        return new ActionLinkHandler($discovery, new ActionDispatcher($registry));
    }

    /** @test */
    public function it_creates_action_elements_for_direct_rendering(): void
    {
        $handler = $this->handler();

        $content = '[View John](entity://Person/123/profile)';
        $elements = $handler->createElements($content);

        $this->assertCount(1, $elements);
        // The element is the resolved action's renderable intent, keyed to the link's entity id.
        $this->assertSame('link', $elements[0]['action']);
        $this->assertSame('/person/123', $elements[0]['href']);
    }

    /** @test */
    public function it_strips_action_links_to_plain_text(): void
    {
        // Stripping is grammar-only (no action resolution needed) — the empty registry suffices.
        $handler = $this->handler(withProfile: false);

        $content = 'See [John](entity://Person/123/profile) and [settings](action://settings).';
        $result = $handler->stripLinks($content);

        $this->assertEquals('See John and settings.', $result);
    }

    /** @test */
    public function it_handles_multiple_action_links(): void
    {
        $handler = $this->handler();

        $content = '[John](entity://Person/123/profile) and [Jane](entity://Person/456/profile)';
        $elements = $handler->createElements($content);

        // Two distinct entity ids => two unique elements.
        $this->assertCount(2, $elements);
        $this->assertSame('/person/123', $elements[0]['href']);
        $this->assertSame('/person/456', $elements[1]['href']);
    }

    /** @test */
    public function it_deduplicates_same_action_links(): void
    {
        $handler = $this->handler();

        // Same entity/id/action twice — different display text must NOT defeat deduplication.
        $content = '[John](entity://Person/123/profile) [John Again](entity://Person/123/profile)';
        $elements = $handler->createElements($content);

        $this->assertCount(1, $elements);
    }

    /** @test */
    public function it_returns_empty_when_no_action_resolves(): void
    {
        // Empty registry: the Person/profile action does not resolve, so nothing renders.
        $handler = $this->handler(withProfile: false);

        $content = '[John](entity://Person/123/profile)';
        $elements = $handler->createElements($content);

        $this->assertCount(0, $elements);
    }
}
