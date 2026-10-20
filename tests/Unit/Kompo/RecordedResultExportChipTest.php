<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\Kompo\MessagesQuery;
use Condoedge\Ai\Kompo\Support\MessageBubbleRenderer;
use Condoedge\Ai\Kompo\Support\RichDataRenderer;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\ActionInvocation;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\BuiltIn\ExportExcelAction;
use Condoedge\Ai\Services\Actions\BuiltIn\RecordedResultExport;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Chat\Exporter\RecordedQueryRerun;
use Condoedge\Ai\Services\Chat\Exporter\ResultSetExporter;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Services\Response\ActionLinkHandler;
use Condoedge\Ai\Services\Response\ContentLinkProcessor;
use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Condoedge\Ai\Services\UI\ChatThemeInterface;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * The export chip of a CAPPED list, end to end on the chat surface (eval p18-A roster).
 *
 * Every other persisted invocation is dispatched when the bubble RENDERS (an export's file
 * is embedded in the page). A re-run export must not be: it executes a query. Pinned here:
 *
 *  - RENDER runs nothing: the chip is a link that posts back (message id + invocation
 *    fingerprint) and a panel for the result — shown only to who may export, and only when
 *    the message recorded its query (else nothing: today's behaviour);
 *  - the CLICK (MessagesQuery::exportRecordedResult) re-runs the query recorded WITH THAT
 *    MESSAGE as the user who clicks, through the action dispatcher (fresh authorize) and
 *    the scoped executor, and answers with the download — or a visible, friendly failure;
 *  - what the client posts only SELECTS a server-stored invocation of the user's own
 *    conversation: a foreign message, a forged fingerprint or a stored-row chip runs nothing.
 */
class RecordedResultExportChipTest extends TestCase
{
    use RefreshDatabase;

    private const ROSTER = 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id '
        . 'RETURN u.name AS name, u.email AS email LIMIT 100';

    /** @var list<array{cypher: ?string, parameters: array, options: array}> */
    public array $executions = [];

    /** @var list<array{rows: array, title: string, headers: array}> */
    public array $written = [];

    /** Rows the scoped executor returns to the acting user (null = it throws). */
    public ?array $rows = null;

    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('entities', []);
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('ai.query_execution.default_limit', 100);
        $app['config']->set('ai.export.document_row_limit', 2000);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The production (Kompo element) render path, whatever ran before (see MessageInvocationRenderTest).
        require_once __DIR__ . '/../../../vendor/kompo/kompo/src/Core/KompoHelpers.php';
        require_once __DIR__ . '/../../../vendor/kompo/kompo/src/Core/HelperUtils.php';

        $this->rows = array_map(static fn (int $i): array => ['name' => "Member {$i}", 'email' => "m{$i}@example.com"], range(1, 523));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function user(int $id): AuthUser
    {
        $user = new AuthUser();
        $user->id = $id;

        return $user;
    }

    private function executor(): QueryExecutorInterface
    {
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')->andReturnUsing(function (?string $cypher, array $parameters, array $options) {
            $this->executions[] = ['cypher' => $cypher, 'parameters' => $parameters, 'options' => $options];
            if ($this->rows === null) {
                throw new \RuntimeException('SecurityException: scope could not be proven');
            }

            return new QueryExecutionResult(success: true, data: $this->rows, stats: [], metadata: []);
        });

        return $executor;
    }

    private function exporter(): ResultSetExporter
    {
        $test = $this;

        return new class($test) extends ResultSetExporter {
            public function __construct(private RecordedResultExportChipTest $test)
            {
            }

            public function documentRowsToExcel(array $rows, string $title = 'document', array $headers = []): AiActionResult
            {
                $this->test->written[] = ['rows' => $rows, 'title' => $title, 'headers' => $headers];

                return AiActionResult::export('xlsx-bytes', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Team_members.xlsx', 'ok');
            }

            public function toExcel(array $rows, string $title = 'export'): AiActionResult
            {
                $this->test->written[] = ['rows' => $rows, 'title' => $title, 'headers' => [], 'stored' => true];

                return AiActionResult::export('stored-bytes', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'stored.xlsx', 'ok');
            }
        };
    }

    /**
     * @param callable(mixed): bool|null $authorize the export action's gate (null = everyone)
     * @return array{0: MessageBubbleRenderer, 1: ActionDispatcher}
     */
    private function makeRenderer(?callable $authorize = null): array
    {
        $exporter = $this->exporter();
        $recorded = new RecordedResultExport(new RecordedQueryRerun($this->executor()), $exporter);
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new class($exporter, $recorded, $authorize) extends ExportExcelAction {
            public function __construct(ResultSetExporter $exporter, RecordedResultExport $recorded, private $gate)
            {
                parent::__construct($exporter, $recorded);
            }

            public function authorize(mixed $user, array $input): bool
            {
                return $this->gate === null || ($this->gate)($user);
            }
        });

        $dispatcher = new ActionDispatcher($registry);
        $renderer = new MessageBubbleRenderer(
            Mockery::mock(ChatThemeInterface::class),
            Mockery::mock(ChatSettingsInterface::class),
            Mockery::mock(RichDataRenderer::class),
            new ContentLinkProcessor(new ActionLinkHandler(Mockery::mock(EntityAutoDiscovery::class), $dispatcher)),
            '',
            '',
            $dispatcher,
        );

        return [$renderer, $dispatcher];
    }

    /** The chip ActionAttacher persists on a capped list. */
    private function rerunRecord(): array
    {
        return [
            'key' => 'export_excel',
            'entity_type' => null,
            'entity_id' => null,
            'params' => ['title' => 'Team members', 'mode' => 'rerun', 'language' => 'en'],
            'text' => 'Download Excel',
        ];
    }

    /** A persisted roster answer: 25 stored rows, its recorded query, the re-run chip. */
    private function rosterMessage(AiConversation $conversation, array $overrides = [], ?array $record = null)
    {
        return $conversation->addMessage('assistant', 'There are 523 members in your team.', $overrides + [
            'cypher_query' => self::ROSTER,
            'response_data' => array_map(static fn (int $i): array => ['name' => "Stored {$i}", 'email' => "s{$i}@example.com"], range(1, 25)),
            'metadata' => ['invocations' => [$record ?? $this->rerunRecord()]],
        ]);
    }

    private function fingerprint(?array $record = null): string
    {
        return ActionInvocation::fromArray($record ?? $this->rerunRecord())->fingerprint();
    }

    /** MessagesQuery as Kompo boots it for a round trip of $conversation's owner. */
    private function query(?AiConversation $conversation, MessageBubbleRenderer $renderer): MessagesQuery
    {
        $class = new \ReflectionClass(MessagesQuery::class);
        $query = $class->newInstanceWithoutConstructor();
        foreach (['conversation' => $conversation, 'bubbleRenderer' => $renderer] as $name => $value) {
            $property = $class->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($query, $value);
        }

        return $query;
    }

    private function text(mixed $element): string
    {
        return is_object($element) ? (string) ($element->label ?? '') : (string) (is_array($element) ? json_encode($element) : $element);
    }

    // ------------------------------------------------------------------ render

    /** @test */
    public function rendering_the_chip_executes_nothing_and_offers_a_click_that_posts_back(): void
    {
        [$renderer] = $this->makeRenderer();
        $message = $this->rosterMessage(AiConversation::create(['user_id' => 13]));

        $elements = $renderer->renderPersistedInvocations($message, ['user' => $this->user(13), 'result_set' => $message->response_data]);

        $this->assertSame([], $this->executions, 'no query runs when a bubble renders');
        $this->assertSame([], $this->written, 'no file is built when a bubble renders');
        $this->assertCount(2, $elements, 'the chip and the panel its result lands in');
        $this->assertInstanceOf(\Kompo\Link::class, $elements[0]);
        $this->assertSame('Download Excel', (string) $elements[0]->label);
        $this->assertInstanceOf(\Kompo\Panel::class, $elements[1]);
        $this->assertSame(MessageBubbleRenderer::exportPanelId($message->id, $this->fingerprint()), $elements[1]->id);
    }

    /** @test */
    public function the_chip_is_not_rendered_for_a_user_the_export_is_not_offered_to(): void
    {
        [$renderer] = $this->makeRenderer(fn ($user): bool => is_object($user) && $user->id === 13);
        $message = $this->rosterMessage(AiConversation::create(['user_id' => 13]));

        $this->assertCount(2, $renderer->renderPersistedInvocations($message, ['user' => $this->user(13)]));
        $this->assertSame([], $renderer->renderPersistedInvocations($message, ['user' => $this->user(40)]));
        $this->assertSame([], $this->executions);
    }

    /** @test */
    public function a_message_that_recorded_no_query_shows_no_chip_and_never_falls_back_to_its_stored_rows(): void
    {
        [$renderer] = $this->makeRenderer();
        $conversation = AiConversation::create(['user_id' => 13]);

        foreach ([null, '', '   '] as $cypher) {
            $message = $this->rosterMessage($conversation, ['cypher_query' => $cypher]);

            $this->assertSame([], $renderer->renderPersistedInvocations($message, ['user' => $this->user(13), 'result_set' => $message->response_data]));
        }
        $this->assertSame([], $this->executions);
        $this->assertSame([], $this->written, 'the 25 stored rows are never exported as if they were the list');
    }

    /** @test */
    public function a_stored_row_chip_still_renders_its_file_at_render(): void
    {
        [$renderer] = $this->makeRenderer();
        $record = ['params' => ['title' => 'Team members', 'mode' => 'table']] + $this->rerunRecord();
        $message = $this->rosterMessage(AiConversation::create(['user_id' => 13]), [], $record);

        $elements = $renderer->renderPersistedInvocations($message, ['user' => $this->user(13), 'result_set' => $message->response_data]);

        $this->assertCount(1, $elements);
        $this->assertSame([], $this->executions);
        $this->assertTrue($this->written[0]['stored'] ?? false, 'mode=table is unchanged: the stored rows, at render');
    }

    // ------------------------------------------------------------------- click

    /** @test */
    public function clicking_reruns_the_messages_recorded_query_as_the_clicking_user_and_answers_with_the_download(): void
    {
        [$renderer] = $this->makeRenderer();
        $conversation = AiConversation::create(['user_id' => 13]);
        $message = $this->rosterMessage($conversation, ['metadata' => [
            'invocations' => [$this->rerunRecord()],
            'cypher_parameters' => ['pii_0123456789ab' => 'ana@example.com', 'ai_team_ids' => [9]],
        ]]);
        $clicker = $this->user(13);
        $this->actingAs($clicker);

        $element = $this->query($conversation, $renderer)->exportRecordedResult($message->id, $this->fingerprint());

        $this->assertCount(1, $this->executions, 'one execution: one rate-limit charge');
        $execution = $this->executions[0];
        $this->assertSame($clicker, $execution['options']['user'], 'scoped to the user who clicks');
        $this->assertTrue($execution['options']['read_only']);
        $this->assertSame($conversation->id, $execution['options']['conversation_id']);
        $this->assertStringEndsWith('RETURN u.name AS name, u.email AS email LIMIT 2001', $execution['cypher']);
        $this->assertSame(['pii_0123456789ab' => 'ana@example.com'], $execution['parameters']);

        $this->assertCount(523, $this->written[0]['rows'], 'every row of the re-run, not the 25 stored ones');
        $this->assertSame('Team members', $this->written[0]['title']);
        $this->assertInstanceOf(\Kompo\Link::class, $element);
        $this->assertSame('data:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet;base64,' . base64_encode('xlsx-bytes'), $element->href);
    }

    /** @test */
    public function the_click_reauthorizes_the_clicking_user(): void
    {
        $allowed = true;
        [$renderer] = $this->makeRenderer(function () use (&$allowed): bool {
            return $allowed;
        });
        $conversation = AiConversation::create(['user_id' => 13]);
        $message = $this->rosterMessage($conversation);
        $this->actingAs($this->user(13));

        // The chip was rendered while the user could export; the permission is gone at click.
        $this->assertCount(2, $renderer->renderPersistedInvocations($message, ['user' => $this->user(13)]));
        $allowed = false;
        $element = $this->query($conversation, $renderer)->exportRecordedResult($message->id, $this->fingerprint());

        $this->assertSame([], $this->executions, 'a user no longer allowed to export runs nothing');
        $this->assertSame([], $this->written);
        $this->assertNotInstanceOf(\Kompo\Link::class, $element);
        $this->assertNotSame('', $this->text($element));
    }

    /** @test */
    public function a_rerun_the_security_layer_rejects_or_empties_is_a_visible_friendly_failure(): void
    {
        [$renderer] = $this->makeRenderer();
        $conversation = AiConversation::create(['user_id' => 13]);
        $message = $this->rosterMessage($conversation);
        $this->actingAs($this->user(13));

        foreach ([[], null] as $rows) {
            $this->rows = $rows;
            $element = $this->query($conversation, $renderer)->exportRecordedResult($message->id, $this->fingerprint());

            $this->assertNotInstanceOf(\Kompo\Link::class, $element, 'no download');
            $this->assertNotSame('', $this->text($element));
            $this->assertStringNotContainsString('SecurityException', $this->text($element), 'never the internal reason');
        }
        $this->assertSame([], $this->written);
    }

    /** @test */
    public function a_rerun_cut_at_the_document_limit_says_so_beside_the_download(): void
    {
        config(['ai.export.document_row_limit' => 250]);
        [$renderer] = $this->makeRenderer();
        $conversation = AiConversation::create(['user_id' => 13]);
        $message = $this->rosterMessage($conversation);
        $this->actingAs($this->user(13));

        $element = $this->query($conversation, $renderer)->exportRecordedResult($message->id, $this->fingerprint());

        $this->assertCount(250, $this->written[0]['rows']);
        $this->assertInstanceOf(\Kompo\Rows::class, $element, 'the download and an honest note');
        $this->assertInstanceOf(\Kompo\Link::class, $element->elements[0]);
        $this->assertStringContainsString('250', (string) $element->elements[1]->label);
    }

    /**
     * @test What the client posts only SELECTS a server-stored re-run invocation of a
     * message in the user's own conversation.
     */
    public function a_foreign_message_a_forged_fingerprint_or_a_stored_row_chip_runs_nothing(): void
    {
        [$renderer] = $this->makeRenderer();
        $mine = AiConversation::create(['user_id' => 13]);
        $theirs = AiConversation::create(['user_id' => 40]);
        $myMessage = $this->rosterMessage($mine);
        $theirMessage = $this->rosterMessage($theirs);
        $tableRecord = ['params' => ['title' => 'Team members', 'mode' => 'table']] + $this->rerunRecord();
        $tableMessage = $this->rosterMessage($mine, [], $tableRecord);
        $userMessage = $mine->addMessage('user', 'who are the members?', ['cypher_query' => self::ROSTER, 'metadata' => ['invocations' => [$this->rerunRecord()]]]);
        $this->actingAs($this->user(13));
        $query = $this->query($mine, $renderer);

        $attempts = [
            'a message of another conversation' => [$theirMessage->id, $this->fingerprint()],
            'a fingerprint matching no stored invocation' => [$myMessage->id, sha1('forged')],
            'a rerun fingerprint forged from other params' => [$myMessage->id, $this->fingerprint(['params' => ['title' => 'x', 'mode' => 'rerun']] + $this->rerunRecord())],
            'the stored-row chip of a message' => [$tableMessage->id, $this->fingerprint($tableRecord)],
            'a user message' => [$userMessage->id, $this->fingerprint()],
            'no message' => [null, $this->fingerprint()],
            'a non-string fingerprint' => [$myMessage->id, ['x']],
            'an unknown message' => [999999, $this->fingerprint()],
        ];
        foreach ($attempts as $case => [$messageId, $fingerprint]) {
            $element = $query->exportRecordedResult($messageId, $fingerprint);

            $this->assertSame([], $this->executions, $case);
            $this->assertSame([], $this->written, $case);
            $this->assertNotInstanceOf(\Kompo\Link::class, $element, $case);
        }

        // Without a conversation of the user (none selected, or not theirs): nothing either.
        $this->assertNotInstanceOf(\Kompo\Link::class, $this->query(null, $renderer)->exportRecordedResult($myMessage->id, $this->fingerprint()));
        $this->assertSame([], $this->executions);
    }
}
