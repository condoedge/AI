<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\Kompo\MessagesQuery;
use Condoedge\Ai\Kompo\Support\MessageBubbleRenderer;
use Condoedge\Ai\Kompo\Support\RichDataRenderer;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\ActionInvocation;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Services\Response\ActionLinkHandler;
use Condoedge\Ai\Services\Response\ContentLinkProcessor;
use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Condoedge\Ai\Services\UI\ChatThemeInterface;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\CapturingAction;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\MutatingSpyAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;
use Mockery;

/**
 * The render hook for persisted native-turn invocations (design 94b §6).
 *
 * MessageBubbleRenderer::renderPersistedInvocations() revives `invocations` from the
 * assistant message metadata and re-dispatches each through the SAME ActionDispatcher the
 * markdown link path uses (fresh authorize(), fresh token, fresh TTL), rendering through the
 * shared ActionResultPresenter. Pinned here:
 *
 *  - fail-closed revival: malformed records render nothing, never throw mid-bubble;
 *  - fingerprint dedupe: parsed action:// links render first and win, so a model imitating
 *    its own history (link + tool call for the same action) yields ONE element;
 *  - per-request memo: N bubbles re-rendering one invocation dispatch once per request;
 *  - approved-resolution markers: resolved_invocations[fingerprint]='approved' skips
 *    dispatch entirely and shows a static done-note instead of re-offering the card;
 *  - narrate-then-deny: a persisted MUTATING invocation denied at render shows the neutral
 *    unavailable-notice (read-only failures keep failing closed, link-path parity);
 *  - MessagesQuery::approveAiAction stamps the marker after a confirmed approval.
 */
class MessageInvocationRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('entities', []);
        // Kompo's selfPost() (the approval card's confirm wiring) encrypts its target.
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Always render through the PRODUCTION (Kompo element) path. Without this the result
        // depended on test order: Kompo's helpers are global functions, so the presenter/card
        // returned plain strings/arrays alone but Kompo elements once any earlier test had
        // booted the Kompo provider. The helper files are function_exists()-guarded.
        require_once __DIR__ . '/../../../vendor/kompo/kompo/src/Core/KompoHelpers.php';
        require_once __DIR__ . '/../../../vendor/kompo/kompo/src/Core/HelperUtils.php';
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    /**
     * The visible text of a note element (done-note / unavailable-notice): a Kompo Html
     * element carries its content as its label.
     */
    private function noteText(mixed $element): string
    {
        $this->assertInstanceOf(\Kompo\Html::class, $element, 'notes render as a Kompo Html element');

        return (string) $element->label;
    }

    /**
     * The signed token an approval card carries (data-pending-action attribute).
     */
    private function cardToken(mixed $card): string
    {
        $this->assertInstanceOf(\Kompo\Rows::class, $card, 'a pending action renders the approval card');

        return (string) $card->attr('data-pending-action');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return array{0: MessageBubbleRenderer, 1: ContentLinkProcessor, 2: ActionDispatcher}
     */
    private function makeRenderer(AiActionRegistry $registry): array
    {
        $dispatcher = new ActionDispatcher($registry);
        $processor = new ContentLinkProcessor(
            new ActionLinkHandler(Mockery::mock(EntityAutoDiscovery::class), $dispatcher)
        );

        $renderer = new MessageBubbleRenderer(
            Mockery::mock(ChatThemeInterface::class),
            Mockery::mock(ChatSettingsInterface::class),
            Mockery::mock(RichDataRenderer::class),
            $processor,
            '',
            '',
            $dispatcher,
        );

        return [$renderer, $processor, $dispatcher];
    }

    private function message(array $metadata, string $content = 'Here you go.'): object
    {
        return new class($metadata, $content) {
            public int $id = 42;

            public function __construct(public array $metadata, public string $content)
            {
            }
        };
    }

    /**
     * A persisted record in the exact toArray() shape the loop stores.
     *
     * @param array<string, mixed> $params
     */
    private function record(
        string $key,
        ?string $entityType = null,
        int|string|null $entityId = null,
        array $params = [],
        string $text = ''
    ): array {
        return [
            'key' => $key,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'params' => $params,
            'text' => $text,
        ];
    }

    /** @test */
    public function a_persisted_generic_invocation_renders_through_the_shared_presenter(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new CapturingAction('render_chart', fn () => 'chart-element'));
        [$renderer] = $this->makeRenderer($registry);

        $elements = $renderer->renderPersistedInvocations(
            $this->message(['invocations' => [$this->record('render_chart')]]),
            ['result_set' => []],
        );

        $this->assertSame(['chart-element'], $elements);
    }

    /** @test */
    public function a_persisted_entity_invocation_routes_entity_id_into_the_dispatch_context(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerEntity('Person', new CapturingAction(
            'profile',
            fn ($input, $context) => 'profile-element:' . $context->entityId,
        ));
        [$renderer] = $this->makeRenderer($registry);

        $elements = $renderer->renderPersistedInvocations(
            $this->message(['invocations' => [$this->record('profile', 'Person', '152463')]]),
            ['result_set' => []],
        );

        $this->assertSame(['profile-element:152463'], $elements);
    }

    /**
     * @test Actions plan B1: a code-attached record chip (a config entity action, exactly the
     * shape ActionAttacher persists) renders with its record label as the link text, and is
     * re-authorized FRESH at every render — a permission revoked after the answer hides it.
     */
    public function a_persisted_record_chip_is_reauthorized_at_render_and_hidden_once_revoked(): void
    {
        $allowed = true;
        config(['ai.entity_actions' => [
            'Unit' => [
                'view' => [
                    'action' => fn ($id, $text = 'Open unit') => "unit-link:{$id}:{$text}",
                    'label' => 'Open unit',
                    'authorize' => function ($user, array $input = []) use (&$allowed): bool {
                        return $allowed;
                    },
                ],
            ],
        ]]);
        $chip = $this->record('view', 'Unit', 159, [], 'Unit 310 — Carré Soho Phase 3');

        [$renderer] = $this->makeRenderer(new AiActionRegistry());
        $this->assertSame(
            ['unit-link:159:Unit 310 — Carré Soho Phase 3'],
            $renderer->renderPersistedInvocations($this->message(['invocations' => [$chip]]), ['result_set' => []])
        );

        // Permission revoked after the answer was stored: the next render (a new request,
        // so a fresh renderer) re-runs authorize() and the chip is gone — no stale link.
        $allowed = false;
        [$fresh] = $this->makeRenderer(new AiActionRegistry());
        $this->assertSame(
            [],
            $fresh->renderPersistedInvocations($this->message(['invocations' => [$chip]]), ['result_set' => []])
        );
    }

    /** @test */
    public function malformed_invocation_metadata_renders_nothing_and_never_throws(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new CapturingAction('render_chart', fn () => 'chart-element'));
        [$renderer] = $this->makeRenderer($registry);

        // Records that must each fail closed via ActionInvocation::fromArray().
        $malformed = [
            'not-an-array-record',
            ['entity_type' => 'Person'],
            ['key' => ''],
            ['key' => 'render_chart', 'params' => 'not-an-array'],
        ];
        $this->assertSame(
            [],
            $renderer->renderPersistedInvocations($this->message(['invocations' => $malformed]), ['result_set' => []])
        );

        // The whole key being garbage must also render nothing.
        $this->assertSame(
            [],
            $renderer->renderPersistedInvocations($this->message(['invocations' => 'garbage']), ['result_set' => []])
        );

        // And absent metadata is a no-op.
        $this->assertSame(
            [],
            $renderer->renderPersistedInvocations($this->message([]), ['result_set' => []])
        );
    }

    /** @test */
    public function params_are_refiltered_against_the_schema_at_render(): void
    {
        $captured = null;
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new CapturingAction('charge', function ($input) use (&$captured) {
            $captured = $input;
            return 'charged';
        }, ['amount' => ['type' => 'float']]));
        [$renderer] = $this->makeRenderer($registry);

        // The persisted record is data, never authority: a tampered/legacy record with an
        // undeclared param is re-filtered by the dispatcher at render.
        $record = $this->record('charge', params: ['amount' => '450', 'evil' => 'DROP']);
        $renderer->renderPersistedInvocations($this->message(['invocations' => [$record]]), ['result_set' => []]);

        $this->assertSame(['amount' => 450.0], $captured);
    }

    /** @test */
    public function an_invocation_equal_to_a_parsed_markdown_link_renders_once(): void
    {
        $calls = 0;
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new CapturingAction('render_chart', function () use (&$calls) {
            $calls++;
            return 'chart-element';
        }));
        [$renderer, $processor] = $this->makeRenderer($registry);

        $content = 'Here is your chart: [Chart](action://render_chart)';

        // The markdown link renders first (existing path)…
        $processed = $processor->processForDirectRendering($content, ['result_set' => []]);
        $this->assertCount(1, $processed['elements']);
        $this->assertSame(1, $calls);

        // …and the equal persisted invocation is skipped by fingerprint: one element total.
        $message = $this->message(['invocations' => [$this->record('render_chart')]], $content);
        $this->assertSame([], $renderer->renderPersistedInvocations($message, ['result_set' => []]));
        $this->assertSame(1, $calls);
    }

    /** @test */
    public function equal_records_within_one_message_render_once(): void
    {
        $calls = 0;
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new CapturingAction('render_chart', function () use (&$calls) {
            $calls++;
            return 'chart-element';
        }));
        [$renderer] = $this->makeRenderer($registry);

        $record = $this->record('render_chart');
        $elements = $renderer->renderPersistedInvocations(
            $this->message(['invocations' => [$record, $record]]),
            ['result_set' => []],
        );

        $this->assertSame(['chart-element'], $elements);
        $this->assertSame(1, $calls);
    }

    /** @test */
    public function the_per_request_memo_dispatches_once_for_n_bubbles(): void
    {
        $calls = 0;
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new CapturingAction('render_chart', function () use (&$calls) {
            $calls++;
            return 'chart-element';
        }));
        [$renderer] = $this->makeRenderer($registry);

        $metadata = ['invocations' => [$this->record('render_chart')]];

        // The renderer is built once per request; a thread re-rendering the same invocation
        // in N bubbles pays authorize()/handle() once, while every bubble still gets its element.
        $first = $renderer->renderPersistedInvocations($this->message($metadata), ['result_set' => []]);
        $second = $renderer->renderPersistedInvocations($this->message($metadata), ['result_set' => []]);

        $this->assertSame(['chart-element'], $first);
        $this->assertSame(['chart-element'], $second);
        $this->assertSame(1, $calls);
    }

    /** @test */
    public function an_approved_marker_skips_dispatch_and_shows_the_done_note(): void
    {
        $calls = 0;
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new CapturingAction('render_chart', function () use (&$calls) {
            $calls++;
            return 'chart-element';
        }));
        [$renderer] = $this->makeRenderer($registry);

        $record = $this->record('render_chart');
        $fingerprint = ActionInvocation::fromArray($record)->fingerprint();

        $elements = $renderer->renderPersistedInvocations(
            $this->message([
                'invocations' => [$record],
                'resolved_invocations' => [$fingerprint => 'approved'],
            ]),
            ['result_set' => []],
        );

        $this->assertSame(0, $calls, 'a resolved invocation must skip dispatch entirely');
        $this->assertCount(1, $elements);
        $this->assertStringContainsString('approved', $this->noteText($elements[0]));
    }

    /** @test */
    public function an_approved_mutating_invocation_shows_the_done_note_instead_of_a_card(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new MutatingSpyAction(
            'archive',
            authorized: true,
            onHandle: function (): void {
                throw new \LogicException('a mutating action must never execute at render');
            },
        ));
        [$renderer] = $this->makeRenderer($registry);

        $record = $this->record('archive');
        $fingerprint = ActionInvocation::fromArray($record)->fingerprint();

        $elements = $renderer->renderPersistedInvocations(
            $this->message([
                'invocations' => [$record],
                'resolved_invocations' => [$fingerprint => 'approved'],
            ]),
            ['result_set' => []],
        );

        $this->assertCount(1, $elements);
        // The done-note (an Html note, not the Rows approval card) replaces the card.
        $this->assertStringContainsString('approved', $this->noteText($elements[0]));
    }

    /** @test */
    public function a_pending_mutating_invocation_renders_the_approval_card_and_never_executes(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new MutatingSpyAction(
            'archive',
            authorized: true,
            onHandle: function (): void {
                throw new \LogicException('a mutating action must never execute at render');
            },
        ));
        [$renderer] = $this->makeRenderer($registry);

        $elements = $renderer->renderPersistedInvocations(
            $this->message(['invocations' => [$this->record('archive')]]),
            ['result_set' => []],
        );

        $this->assertCount(1, $elements);
        $token = $this->cardToken($elements[0]);
        $this->assertNotSame('', $token);
        // The confirm round-trip is a self-method post (method name is encrypted in the Kompo
        // target) carrying the SAME signed token + the invocation's fingerprint.
        $json = json_encode($elements[0], JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('"X-Kompo-Action":"self-method"', $json);
        $this->assertStringContainsString('"token":"' . $token . '"', $json);
        $fingerprint = ActionInvocation::fromArray($this->record('archive'))->fingerprint();
        $this->assertStringContainsString('"fingerprint":"' . $fingerprint . '"', $json);
    }

    /** @test */
    public function a_denied_mutating_invocation_shows_the_unavailable_notice(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new MutatingSpyAction(
            'archive',
            authorized: false,
            onHandle: function (): void {
                throw new \LogicException('a denied mutating action must never execute');
            },
        ));
        [$renderer] = $this->makeRenderer($registry);

        $elements = $renderer->renderPersistedInvocations(
            $this->message(['invocations' => [$this->record('archive')]]),
            ['result_set' => []],
        );

        $this->assertCount(1, $elements);
        $this->assertStringContainsString('no longer available', $this->noteText($elements[0]));
    }

    /** @test */
    public function a_failing_read_only_invocation_stays_silent(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new CapturingAction(
            'render_chart',
            fn () => AiActionResult::error('chart failed for some internal reason'),
        ));
        [$renderer] = $this->makeRenderer($registry);

        // Link-path parity: a read-only failure renders nothing (no leaked internals).
        $this->assertSame(
            [],
            $renderer->renderPersistedInvocations(
                $this->message(['invocations' => [$this->record('render_chart')]]),
                ['result_set' => []],
            )
        );
    }

    /** @test */
    public function an_unknown_invocation_key_renders_nothing(): void
    {
        [$renderer] = $this->makeRenderer(new AiActionRegistry());

        $this->assertSame(
            [],
            $renderer->renderPersistedInvocations(
                $this->message(['invocations' => [$this->record('does_not_exist')]]),
                ['result_set' => []],
            )
        );
    }

    /** @test */
    public function approve_ai_action_writes_the_resolution_marker_and_later_renders_show_the_done_note(): void
    {
        $handled = 0;
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new MutatingSpyAction(
            'archive',
            authorized: true,
            onHandle: function () use (&$handled) {
                $handled++;
                return 'archived';
            },
        ));
        [$renderer, , $dispatcher] = $this->makeRenderer($registry);
        $this->app->instance(ActionDispatcher::class, $dispatcher);

        $conversation = AiConversation::create(['user_id' => 1]);
        $record = $this->record('archive');
        $fingerprint = ActionInvocation::fromArray($record)->fingerprint();
        $message = $conversation->addMessage('assistant', 'I can archive that for you.', [
            'metadata' => ['invocations' => [$record]],
        ]);

        // Render mints a fresh signed token (the card) without executing anything.
        $elements = $renderer->renderPersistedInvocations($message, ['result_set' => []]);
        $token = $this->cardToken($elements[0]);
        $this->assertSame(0, $handled);

        // The confirm round-trip: approveAiAction posts token + messageId + fingerprint.
        $query = (new \ReflectionClass(MessagesQuery::class))->newInstanceWithoutConstructor();
        $conversationProp = (new \ReflectionClass(MessagesQuery::class))->getProperty('conversation');
        $conversationProp->setAccessible(true);
        $conversationProp->setValue($query, $conversation);

        $query->approveAiAction($token, $message->id, $fingerprint);

        $this->assertSame(1, $handled, 'approval executes handle() exactly once');
        $this->assertSame(
            'approved',
            $message->fresh()->metadata['resolved_invocations'][$fingerprint] ?? null,
            'a confirmed approval must stamp the resolution marker on the message'
        );

        // A later render of the same message shows the static done-note, not another card.
        [$secondRenderer] = $this->makeRenderer($registry);
        $rerendered = $secondRenderer->renderPersistedInvocations($message->fresh(), ['result_set' => []]);
        $this->assertCount(1, $rerendered);
        $this->assertStringContainsString('approved', $this->noteText($rerendered[0]));
        $this->assertSame(1, $handled, 'the done-note render must not dispatch or execute again');
    }

    /** @test */
    public function a_fingerprint_that_does_not_match_the_approved_action_is_never_stamped(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new MutatingSpyAction('archive', authorized: true, onHandle: fn () => 'archived'));
        $registry->registerGeneric(new MutatingSpyAction('purge', authorized: true, onHandle: fn () => 'purged'));
        [$renderer, , $dispatcher] = $this->makeRenderer($registry);
        $this->app->instance(ActionDispatcher::class, $dispatcher);

        $conversation = AiConversation::create(['user_id' => 1]);
        $archive = $this->record('archive');
        $purge = $this->record('purge');
        $message = $conversation->addMessage('assistant', 'Two proposals.', [
            'metadata' => ['invocations' => [$archive, $purge]],
        ]);

        $query = (new \ReflectionClass(MessagesQuery::class))->newInstanceWithoutConstructor();
        $conversationProp = (new \ReflectionClass(MessagesQuery::class))->getProperty('conversation');
        $conversationProp->setAccessible(true);
        $conversationProp->setValue($query, $conversation);

        // Approving the ARCHIVE token while claiming PURGE's fingerprint: the approval itself
        // succeeds, but the stamp is refused — the fingerprint's invocation does not match the
        // approved token's action identity, so purge must not show as done.
        $elements = $renderer->renderPersistedInvocations($message, ['result_set' => []]);
        $query->approveAiAction($this->cardToken($elements[0]), $message->id, ActionInvocation::fromArray($purge)->fingerprint());
        $this->assertSame([], $message->fresh()->metadata['resolved_invocations'] ?? []);

        // A fingerprint matching nothing persisted on the message is refused the same way.
        [$secondRenderer] = $this->makeRenderer($registry);
        $elements = $secondRenderer->renderPersistedInvocations($message->fresh(), ['result_set' => []]);
        $query->approveAiAction($this->cardToken($elements[0]), $message->id, sha1('forged-fingerprint'));
        $this->assertSame([], $message->fresh()->metadata['resolved_invocations'] ?? []);

        // The matching fingerprint still lands.
        [$thirdRenderer] = $this->makeRenderer($registry);
        $elements = $thirdRenderer->renderPersistedInvocations($message->fresh(), ['result_set' => []]);
        $archiveFingerprint = ActionInvocation::fromArray($archive)->fingerprint();
        $query->approveAiAction($this->cardToken($elements[0]), $message->id, $archiveFingerprint);
        $this->assertSame(
            ['approved'],
            array_values($message->fresh()->metadata['resolved_invocations'] ?? []),
        );
        $this->assertSame(
            'approved',
            $message->fresh()->metadata['resolved_invocations'][$archiveFingerprint] ?? null
        );
    }
}
