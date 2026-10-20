<?php

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Condoedge\Ai\Services\Context\EntityExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

class ConversationContextManagerTest extends TestCase
{
    use RefreshDatabase;

    private ConversationContextManager $manager;

    /**
     * Don't load the full AI service provider to avoid API key requirements
     */
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Set up in-memory SQLite database for testing
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Run migrations for the AI tables
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations');
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->manager = new ConversationContextManager(new EntityExtractor());
    }

    /** @test */
    public function there_is_no_pre_pipeline_question_analysis(): void
    {
        // Phase 2.B: the regex layer (processQuestion → EntityExtractor word lists +
        // ReferenceResolver) is gone; QU owns follow-up / entities / intent.
        $this->assertFalse(method_exists($this->manager, 'processQuestion'));
    }

    /** @test */
    public function a_turn_without_cypher_takes_its_focus_and_query_type_from_qu(): void
    {
        // Documents answer / export-of-previous: no cypher executed, so the focus
        // comes from THIS turn's QU entity types (stamped on metadata by the
        // pipeline) — never from matching labels inside the question text.
        $conversation = AiConversation::create([
            'user_id' => 1,
            'context_snapshot' => ['focused_entity' => 'Team', 'mentioned_entities' => ['Team']],
        ]);

        $this->manager->recordResponse($conversation, 'The declaration says...', '', [
            'data' => [],
            'metadata' => ['entity_types' => ['Unit', 'Owner'], 'intent' => 'lookup'],
        ]);

        $conversation->refresh();
        $this->assertSame('Unit', $conversation->getFocusedEntity());
        $this->assertSame(['Team', 'Unit', 'Owner'], $conversation->getMentionedEntities());
        $this->assertSame('lookup', $conversation->context_snapshot['last_query_type']);
    }

    /** @test */
    public function the_executed_cypher_focus_wins_over_qu_entity_types(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $this->manager->recordResponse($conversation, '3 invoices.', 'MATCH (i:Invoice) RETURN i.id AS id', [
            'data' => [['id' => 1]],
            'metadata' => ['entity_types' => ['Unit']],
        ]);

        $conversation->refresh();
        $this->assertSame('Invoice', $conversation->getFocusedEntity());
    }

    /** @test P-1: the `$pii_…` parameters travel with the recorded query to the next turn. */
    public function the_recorded_query_keeps_its_pii_parameters_for_the_next_turn(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $this->manager->recordResponse($conversation, 'Karen.', 'MATCH (u:User) WHERE u.email = $pii_0123456789ab RETURN u.name AS name', [
            'data' => [['name' => 'Karen']],
            'cypher_parameters' => ['pii_0123456789ab' => 'karen@x.test'],
        ]);

        $conversation->refresh();
        $context = $this->manager->buildPromptContext($conversation);
        $this->assertSame(['pii_0123456789ab' => 'karen@x.test'], $context['last_cypher_parameters']);
    }

    /** @test */
    public function a_turn_with_neither_cypher_nor_qu_entities_preserves_the_previous_focus(): void
    {
        $conversation = AiConversation::create([
            'user_id' => 1,
            'context_snapshot' => ['focused_entity' => 'Team'],
        ]);

        $this->manager->recordResponse($conversation, 'Hello!', '', ['data' => []]);

        $conversation->refresh();
        $this->assertSame('Team', $conversation->getFocusedEntity());
    }

    /** @test */
    public function mentioned_entities_is_bounded_to_the_most_recent(): void
    {
        $previous = [];
        for ($i = 1; $i <= 12; $i++) {
            $previous[] = "Label{$i}";
        }
        $conversation = AiConversation::create([
            'user_id' => 1,
            'context_snapshot' => ['mentioned_entities' => $previous],
        ]);

        $this->manager->recordResponse($conversation, 'ok', 'MATCH (c:Customer) RETURN c.name AS name', [
            'data' => [['name' => 'A']],
        ]);

        $conversation->refresh();
        $mentioned = $conversation->getMentionedEntities();
        $this->assertCount(ConversationContextManager::MAX_MENTIONED_ENTITIES, $mentioned);
        $this->assertSame('Customer', end($mentioned), 'the newest entity type is kept (last)');
        $this->assertNotContains('Label1', $mentioned, 'the oldest ones fall off');
    }

    /** @test */
    public function it_records_response_and_updates_context(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $this->manager->recordResponse(
            $conversation,
            'There are 150 customers.',
            'MATCH (c:Customer) RETURN count(c) as count',
            ['data' => [['count' => 150]]]
        );

        $conversation->refresh();

        $this->assertEquals('Customer', $conversation->getFocusedEntity());
        $this->assertContains('Customer', $conversation->getMentionedEntities());
    }

    /** @test */
    public function it_persists_the_cypher_and_full_result_count_alongside_the_ten_row_sample(): void
    {
        // The follow-up export path ("give me that as an Excel") re-executes the
        // PREVIOUS turn's cypher to recover the full result set — the snapshot
        // only keeps a 10-row display sample. This pins the contract that path
        // depends on: last_cypher_query + last_result_count persist next to the
        // trimmed last_result_sample, and buildPromptContext exposes all three.
        $conversation = AiConversation::create(['user_id' => 1]);

        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = ['name' => "Member {$i}"];
        }

        $cypher = 'MATCH (u:User) RETURN u.name AS name LIMIT 100';
        $this->manager->recordResponse(
            $conversation,
            'Here are your team members.',
            $cypher,
            ['data' => $rows]
        );

        $conversation->refresh();
        $snapshot = $conversation->context_snapshot;

        $this->assertSame($cypher, $snapshot['last_cypher_query']);
        $this->assertSame(25, $snapshot['last_result_count']);
        $this->assertCount(10, $snapshot['last_result_sample']);

        $promptContext = $this->manager->buildPromptContext($conversation);
        $this->assertSame($cypher, $promptContext['last_cypher_query']);
        $this->assertSame(25, $promptContext['last_result_count']);
        $this->assertCount(10, $promptContext['last_result_sample']);
    }

    /** @test */
    public function a_digest_turn_persists_all_its_rows_as_the_sample_instead_of_the_ten_row_slice(): void
    {
        // B1 ("export that" after a combined digest+export turn): a digest turn
        // records no cypher, so the snapshot sample IS the only export source.
        // Its rows are already display-capped per source by the DigestRunner —
        // the snapshot keeps them ALL, or the promised "file with everything
        // shown here" silently loses every row past the 10-row display slice.
        $conversation = AiConversation::create(['user_id' => 1]);

        $rows = [];
        for ($i = 1; $i <= 28; $i++) {
            $rows[] = ['id' => $i, 'attention_source' => 'open_tasks'];
        }

        $this->manager->recordResponse(
            $conversation,
            'Here is your attention digest.',
            '',
            [
                'data' => $rows,
                'metadata' => ['response' => ['digest' => ['sources' => []]]],
            ]
        );

        $conversation->refresh();
        $snapshot = $conversation->context_snapshot;

        $this->assertCount(28, $snapshot['last_result_sample']);
        $this->assertSame(28, $snapshot['last_result_count']);
    }

    /** @test */
    public function the_distilled_subject_rides_metadata_into_last_subject(): void
    {
        // The composer's write-back contract: metadata.subject (the distilled
        // QU search query) titles a follow-up export of these rows. It only
        // holds end-to-end when the caller threads metadata into recordResponse.
        $conversation = AiConversation::create(['user_id' => 1]);

        $this->manager->recordResponse(
            $conversation,
            'Here are your overdue invoices.',
            'MATCH (i:Invoice) RETURN i.id AS id',
            [
                'data' => [['id' => 1]],
                'metadata' => ['subject' => 'overdue invoices'],
            ]
        );

        $conversation->refresh();

        $this->assertSame('overdue invoices', $conversation->context_snapshot['last_subject']);
    }

    /**
     * @test Eval p9-B resilience t3 (2026-10-01): roster (rows) -> "add their
     * last login" (rows) -> "Where can I add new members?" (documents, 0 rows,
     * QU subject "add new team members") -> "export that members list". The
     * export re-ran the recorded roster query but was titled "Add new team
     * members": the docs turn overwrote last_subject while preserve-on-empty
     * kept the rows. last_subject describes the RECORDED RESULT, so it is
     * written only by a turn that recorded rows.
     */
    public function a_turn_without_rows_does_not_overwrite_the_subject_of_the_recorded_result(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $this->manager->recordResponse(
            $conversation,
            'Here are your members with their last login.',
            'MATCH (u:User) RETURN u.name AS member_name, u.last_login_at AS last_login',
            [
                'data' => [['member_name' => 'Julie', 'last_login' => '2023-11-24']],
                'metadata' => ['subject' => 'team members last login'],
            ]
        );

        $this->manager->recordResponse(
            $conversation,
            'You can add new members from the team settings page.',
            null,
            [
                'data' => [],
                'metadata' => ['subject' => 'add new team members', 'entity_types' => ['TeamInvitation']],
            ]
        );

        $conversation->refresh();
        $snapshot = $conversation->context_snapshot;

        $this->assertSame('team members last login', $snapshot['last_subject']);
        $this->assertStringContainsString('last_login', $snapshot['last_cypher_query']);
    }

    /** @test */
    public function it_builds_context_for_prompt(): void
    {
        $conversation = AiConversation::create([
            'user_id' => 1,
            'context_snapshot' => [
                'focused_entity' => 'Customer',
                'mentioned_entities' => ['Customer', 'Order'],
                'last_cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
                'last_result_count' => 150,
            ],
        ]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'How many customers?',
        ]);

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => '150 customers',
            'cypher_query' => 'MATCH (c:Customer) RETURN count(c)',
        ]);

        $promptContext = $this->manager->buildPromptContext($conversation);

        $this->assertEquals('Customer', $promptContext['focused_entity']);
        $this->assertNotNull($promptContext['last_cypher_query']);
        $this->assertArrayHasKey('recent_exchanges', $promptContext);
    }

    /** @test */
    public function it_limits_conversation_history(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        // Create 20 messages
        for ($i = 0; $i < 20; $i++) {
            $conversation->messages()->create([
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message {$i}",
            ]);
        }

        $promptContext = $this->manager->buildPromptContext($conversation, maxHistory: 5);

        $this->assertCount(5, $promptContext['recent_exchanges']);
    }

    /** @test */
    public function default_history_size_comes_from_chat_config(): void
    {
        config(['ai.chat.max_history_messages' => 7]);
        $conversation = AiConversation::create(['user_id' => 1]);

        for ($i = 0; $i < 12; $i++) {
            $conversation->messages()->create([
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message {$i}",
            ]);
        }

        $promptContext = $this->manager->buildPromptContext($conversation);

        $this->assertCount(7, $promptContext['recent_exchanges']);
    }

    /** @test */
    public function assistant_answer_summary_cap_defaults_to_800_chars(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);
        $conversation->messages()->create(['role' => 'assistant', 'content' => str_repeat('a', 2000)]);

        $summary = $this->manager->buildPromptContext($conversation)['recent_exchanges'][0]['answer_summary'];

        $this->assertSame(str_repeat('a', 800) . '...', $summary);
    }

    /** @test */
    public function assistant_answer_summary_cap_is_configurable(): void
    {
        config(['ai.chat.history_answer_chars' => 50]);
        $conversation = AiConversation::create(['user_id' => 1]);
        $conversation->messages()->create(['role' => 'assistant', 'content' => str_repeat('b', 400)]);

        $summary = $this->manager->buildPromptContext($conversation)['recent_exchanges'][0]['answer_summary'];

        $this->assertSame(str_repeat('b', 50) . '...', $summary);
    }

    /** @test */
    public function it_stores_query_type_in_context(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $this->manager->recordResponse(
            $conversation,
            'There are 150 customers.',
            'MATCH (c:Customer) RETURN count(c)',
            [
                'data' => [['count' => 150]],
                'query_type' => 'aggregation',
            ]
        );

        $conversation->refresh();
        $snapshot = $conversation->context_snapshot;

        $this->assertEquals('aggregation', $snapshot['last_query_type']);
    }
}
