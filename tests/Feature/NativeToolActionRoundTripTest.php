<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Feature;

use Condoedge\Ai\DTOs\LlmToolCall;
use Condoedge\Ai\DTOs\LlmToolTurn;
use Condoedge\Ai\Kompo\MessagesQuery;
use Condoedge\Ai\Kompo\Support\MessageBubbleRenderer;
use Condoedge\Ai\Kompo\Support\RichDataRenderer;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\Actions\ActionInvocation;
use Condoedge\Ai\Services\Actions\ActionToolSpec;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\ModelMethodMutationAction;
use Condoedge\Ai\Services\Actions\PendingActionToken;
use Condoedge\Ai\Services\Actions\ToolLoopResult;
use Condoedge\Ai\Services\Actions\ToolLoopRunner;
use Condoedge\Ai\Services\Response\ContentLinkProcessor;
use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Condoedge\Ai\Services\UI\ChatThemeInterface;
use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\ScriptedToolProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/**
 * The native-channel centerpiece (P1-C1 / design 94b §10 T10): the booted analogue of
 * ActionApprovalRoundTripTest, driving the WHOLE native tool-calling round-trip against a real
 * DB row a ModelMethodMutationAction wraps:
 *
 *   1. A scripted tool-calling provider proposes the mutation through the container's
 *      ToolLoopRunner → ActionDispatcher::dispatch() defers it — NO DB MUTATION, the loop-time
 *      token is discarded, and the model is told the action was NOT performed.
 *   2. The assistant message persists the schema-FILTERED invocation rows (the exact write
 *      AskQuestionUseCase makes) — action references, never tokens, never results.
 *   3. Every bubble render revives the invocation and re-dispatches through the SAME
 *      dispatcher: a real booted ApprovalCard element carrying a fresh signed, user-bound
 *      token — and still nothing executes.
 *   4. MessagesQuery::approveAiAction (token + messageId + fingerprint) re-verifies the token,
 *      re-runs authorize(), executes the wrapped domain write EXACTLY ONCE, and stamps the
 *      resolved_invocations marker on the message.
 *   5. A replayed confirm dies on the single-use nonce; a later render shows the static
 *      done-note instead of re-offering the card.
 *
 * Note: the acting user is created LOCALLY per test (never stored on the TestCase) and all
 * collaborators are method-local — retaining instances on properties segfaults teardown under
 * the fully-booted provider graph (see ActionApprovalRoundTripTest).
 */
class NativeToolActionRoundTripTest extends TestCase
{
    use RefreshDatabase;

    // NOTE: setUp() MUST stay `public` — the package base TestCase widened PHPUnit's protected
    // setUp()/tearDown() to public, and narrowing it back segfaults test-class collection.
    public function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('native_tool_records')) {
            Schema::create('native_tool_records', function (Blueprint $table) {
                $table->increments('id');
                $table->string('status')->default('pending');
                $table->unsignedInteger('approvals')->default(0);
            });
        }
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Kompo encrypts selfPost targets (the card's confirm wiring), which needs an app key.
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        // The RefreshDatabase migrate boot resolves the provider's console commands, whose
        // constructors require provider API keys. Dummy values — nothing here calls out.
        $app['config']->set('ai.embedding.openai.api_key', 'test-key');
        $app['config']->set('ai.llm.openai.api_key', 'test-key');
        $app['config']->set('ai.llm.anthropic.api_key', 'test-key');

        // The bubble's action bar uses _Sax icons from condoedge/utils, whose service provider
        // (a host concern) is not booted here; the package's own settings turn that chrome off.
        $app['config']->set('ai.chat.enable_copy', false);
        $app['config']->set('ai.chat.enable_feedback', false);
        $app['config']->set('ai.chat.enable_regenerate', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    /** @test */
    public function a_scripted_tool_proposal_defers_the_mutation_and_records_the_invocation(): void
    {
        $this->actingAs($this->makeUser(7));

        [$record, $result, $provider] = $this->proposeTurn();

        // THE invariant: proposing a mutation through the native channel wrote NOTHING.
        $fresh = $record->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame(0, (int) $fresh->approvals, 'the wrapped domain write must NOT run during the loop');

        // The model was told, in terms it must not paraphrase into success, that nothing ran.
        $toolResult = $provider->results[0]['call-1'] ?? null;
        $this->assertIsString($toolResult);
        $this->assertStringContainsString('pending_approval', $toolResult);
        $this->assertStringContainsString('NOT been performed', $toolResult);

        // One FILTERED invocation recorded for render-time re-dispatch — a reference, no token.
        $this->assertCount(1, $result->invocations);
        $invocation = $result->invocations[0];
        $this->assertSame('approve_record', $invocation->key);
        $this->assertSame('Record', $invocation->entityType);
        $this->assertSame((string) $record->id, (string) $invocation->entityId);
        $this->assertSame([], $invocation->params);
        $this->assertArrayNotHasKey('token', $invocation->toArray());

        $this->assertSame('I have proposed approving the record. Please confirm.', $result->answer);
        $this->assertSame('native', $result->telemetry['channel']);
    }

    /** @test */
    public function the_assistant_message_persists_the_filtered_invocation_rows(): void
    {
        $this->actingAs($this->makeUser(7));

        [$record, $result] = $this->proposeTurn();
        [, $message] = $this->persistTurn($result);

        // The persisted metadata is the invocation's exact toArray() form, intact from the DB.
        $expected = [$result->invocations[0]->toArray()];
        $this->assertSame($expected, $message->fresh()->metadata['invocations'] ?? null);

        // Persisting the turn is pure metadata — still no mutation.
        $this->assertSame('pending', $record->fresh()->status);
    }

    /** @test */
    public function the_bubble_renders_a_real_approval_card_and_render_never_executes(): void
    {
        $this->actingAs($this->makeUser(7));

        [$record, $result] = $this->proposeTurn();
        [, $message] = $this->persistTurn($result);

        $renderer = $this->renderer();
        $elements = $renderer->renderPersistedInvocations($message->fresh(), $this->linkContext());

        $this->assertCount(1, $elements);
        $this->assertIsObject($elements[0], 'with Kompo booted the card must be a real element');

        // The card carries a fresh, decodable, user-bound token minted at RENDER time.
        $token = $elements[0]->attr('data-pending-action');
        $this->assertNotEmpty($token);
        $decoded = PendingActionToken::decode($token);
        $this->assertNotNull($decoded);
        $this->assertSame('approve_record', $decoded->actionKey);
        $this->assertSame('Record', $decoded->entityType);
        $this->assertSame(['entity_id' => (string) $record->id], $decoded->input);
        $this->assertSame('7', (string) $decoded->userId, 'token bound to the acting user');

        // The card rides the full assistant bubble through the renderer hook.
        $bubble = $renderer->render($message->fresh(), []);
        $this->assertNotNull($this->findPendingCard($bubble), 'the assistant bubble must contain the approval card');

        // Rendering (twice, via the memo) mutated nothing.
        $fresh = $record->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame(0, (int) $fresh->approvals, 'render-time dispatch defers, exactly like a link');
    }

    /** @test */
    public function approve_ai_action_mutates_exactly_once_and_stamps_the_resolution_marker(): void
    {
        $this->actingAs($this->makeUser(7));

        [$record, $result] = $this->proposeTurn();
        [$conversation, $message, $fingerprint] = $this->persistTurn($result);

        $elements = $this->renderer()->renderPersistedInvocations($message->fresh(), $this->linkContext());
        $token = $elements[0]->attr('data-pending-action');

        // The real chat-Query round-trip method the card's confirm wiring posts to.
        $rendered = $this->approveVia($conversation, $token, $message->id, $fingerprint);

        $fresh = $record->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame(1, (int) $fresh->approvals, 'the wrapped domain write runs exactly once, after approval');
        // The successful mutation surfaced something renderable (not the failure alert).
        $this->assertNotNull($rendered);

        $this->assertSame(
            'approved',
            $message->fresh()->metadata['resolved_invocations'][$fingerprint] ?? null,
            'a confirmed approval must stamp the resolution marker on the message'
        );
    }

    /** @test */
    public function a_replayed_confirm_is_blocked_and_the_write_happens_once(): void
    {
        $this->actingAs($this->makeUser(7));

        [$record, $result] = $this->proposeTurn();
        [$conversation, $message, $fingerprint] = $this->persistTurn($result);

        $elements = $this->renderer()->renderPersistedInvocations($message->fresh(), $this->linkContext());
        $token = $elements[0]->attr('data-pending-action');

        $this->approveVia($conversation, $token, $message->id, $fingerprint);
        $this->assertSame(1, (int) $record->fresh()->approvals);

        // Replaying the SAME token dies on the single-use nonce — no second write.
        $second = $this->approveVia($conversation, $token, $message->id, $fingerprint);

        $this->assertSame(1, (int) $record->fresh()->approvals, 'a replayed token must not re-execute');
        $this->assertIsObject($second);
        $this->assertStringContainsString('already been confirmed', (string) $second->label);
    }

    /** @test */
    public function a_later_render_shows_the_done_note_instead_of_re_offering_the_card(): void
    {
        $this->actingAs($this->makeUser(7));

        [$record, $result] = $this->proposeTurn();
        [$conversation, $message, $fingerprint] = $this->persistTurn($result);

        $elements = $this->renderer()->renderPersistedInvocations($message->fresh(), $this->linkContext());
        $token = $elements[0]->attr('data-pending-action');
        $this->approveVia($conversation, $token, $message->id, $fingerprint);

        // A NEW renderer (new request: fresh memo) sees the marker — done-note, no card.
        $rerendered = $this->renderer()->renderPersistedInvocations($message->fresh(), $this->linkContext());
        $this->assertCount(1, $rerendered);
        $this->assertIsObject($rerendered[0]);
        $this->assertStringContainsString('This action was approved.', (string) $rerendered[0]->label);

        $bubble = $this->renderer()->render($message->fresh(), []);
        $this->assertNull($this->findPendingCard($bubble), 'a resolved invocation must not re-offer the card');

        $fresh = $record->fresh();
        $this->assertSame(1, (int) $fresh->approvals, 'the done-note render must not dispatch or execute again');
        $this->assertSame('approved', $fresh->status);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /** The acting user exposes getKey() so the token is bound to a real id (not the null path). */
    private function makeUser(int $id): Authenticatable
    {
        return new class($id) implements Authenticatable {
            public function __construct(public int $id) {}
            public function getKey() { return $this->id; }
            public function getAuthIdentifierName() { return 'id'; }
            public function getAuthIdentifier() { return $this->id; }
            public function getAuthPassword() { return ''; }
            public function getAuthPasswordName() { return 'password'; }
            public function getRememberToken() { return null; }
            public function setRememberToken($value) {}
            public function getRememberTokenName() { return ''; }
        };
    }

    /**
     * Register the host's ModelMethodMutationAction on the booted registry singleton — the
     * runtime registration path a host service provider uses for constructor-configured actions
     * (class-string config registration covers no-arg actions; see ActionApprovalRoundTripTest).
     */
    private function registerApproveAction(): ModelMethodMutationAction
    {
        $action = new ModelMethodMutationAction(
            'approve_record',
            'Approve record',
            NativeToolRoundTripRecord::class,
            static function ($record, array $input): void {
                // Stand-in for the EXISTING domain method the action wraps — the sole write site.
                $record->update([
                    'status' => 'approved',
                    'approvals' => $record->approvals + 1,
                ]);
            },
            static fn ($user, $record, array $input) => $user !== null,
            entityType: 'Record',
        );

        app(AiActionRegistry::class)->registerEntity('Record', $action);

        return $action;
    }

    /**
     * Drive the container's ToolLoopRunner with a scripted provider proposing the mutation,
     * exactly as ResponseGenerator's native branch does.
     *
     * @return array{0: NativeToolRoundTripRecord, 1: ToolLoopResult, 2: ScriptedToolProvider}
     */
    private function proposeTurn(): array
    {
        $record = NativeToolRoundTripRecord::create(['status' => 'pending', 'approvals' => 0]);
        $action = $this->registerApproveAction();
        $spec = ActionToolSpec::fromAction($action, 'Record');

        $provider = new ScriptedToolProvider([
            new LlmToolTurn(
                null,
                [new LlmToolCall('call-1', $spec->name, ['entity_id' => (string) $record->id])],
                ['role' => 'assistant'],
                'tool_calls'
            ),
            new LlmToolTurn('I have proposed approving the record. Please confirm.', [], ['role' => 'assistant'], 'stop'),
        ]);

        $result = app(ToolLoopRunner::class)->run(
            $provider,
            [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => 'Approve record ' . $record->id],
            ],
            [$spec],
            ['user' => auth()->user(), 'result_set' => []],
        );

        return [$record, $result, $provider];
    }

    /**
     * Persist the assistant turn with the exact metadata write AskQuestionUseCase makes
     * (design 94b §6.1): the already-FILTERED invocations as toArray() rows.
     *
     * @return array{0: AiConversation, 1: AiMessage, 2: string}
     */
    private function persistTurn(ToolLoopResult $result): array
    {
        $conversation = AiConversation::create(['user_id' => auth()->id()]);

        $message = $conversation->addMessage('assistant', $result->answer, [
            'metadata' => [
                'invocations' => array_map(
                    static fn (ActionInvocation $invocation) => $invocation->toArray(),
                    $result->invocations
                ),
            ],
        ]);

        return [$conversation, $message, $result->invocations[0]->fingerprint()];
    }

    /**
     * A bubble renderer wired exactly like MessagesQuery::bubbleRenderer() — container theme,
     * settings and ContentLinkProcessor, with the dispatcher defaulting to the shared singleton.
     * Built fresh per call: each instance models one request (its dispatch memo included).
     */
    private function renderer(): MessageBubbleRenderer
    {
        return new MessageBubbleRenderer(
            app(ChatThemeInterface::class),
            app(ChatSettingsInterface::class),
            new RichDataRenderer(app(ChatThemeInterface::class), app(ChatSettingsInterface::class)),
            app(ContentLinkProcessor::class),
            '',
            '',
        );
    }

    /** The same context array assistantBubble hands the link path and the invocation hook. */
    private function linkContext(): array
    {
        return ['files' => [], 'result_set' => [], 'user' => auth()->user()];
    }

    /**
     * The REAL MessagesQuery::approveAiAction seam, instantiated without the Kompo Query boot
     * lifecycle (same technique as ActionApprovalRoundTripTest) with the ownership-scoped
     * conversation set so markInvocationApproved can stamp the marker.
     */
    private function approveVia(AiConversation $conversation, string $token, $messageId = null, $fingerprint = null): mixed
    {
        $reflection = new \ReflectionClass(MessagesQuery::class);
        $query = $reflection->newInstanceWithoutConstructor();

        $property = $reflection->getProperty('conversation');
        $property->setAccessible(true);
        $property->setValue($query, $conversation);

        return $query->approveAiAction($token, $messageId, $fingerprint);
    }

    /** Depth-first search of a booted Kompo element tree for the approval card. */
    private function findPendingCard(mixed $element): mixed
    {
        foreach ($this->flatten($element) as $node) {
            if (method_exists($node, 'attr') && $node->attr('data-pending-action')) {
                return $node;
            }
        }

        return null;
    }

    /** @return array<int, object> */
    private function flatten(mixed $element): array
    {
        if (is_array($element)) {
            $nodes = [];
            foreach ($element as $child) {
                $nodes = array_merge($nodes, $this->flatten($child));
            }

            return $nodes;
        }

        if (!is_object($element)) {
            return [];
        }

        // Leaf Kompo elements have a magic __get that throws on unknown properties.
        $children = property_exists($element, 'elements') ? $element->elements : null;

        return array_merge([$element], is_array($children) ? $this->flatten($children) : []);
    }
}

/**
 * The DB-backed domain row the booted round-trip mutates — proof the "no mutation before
 * approval" invariant holds against a real table, not a spy counter.
 */
class NativeToolRoundTripRecord extends Model
{
    protected $table = 'native_tool_records';
    protected $guarded = [];
    public $timestamps = false;
}
