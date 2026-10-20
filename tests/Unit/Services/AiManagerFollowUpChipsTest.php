<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\Attach\ActionAttacher;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Response\FollowUpWriter;
use Condoedge\Ai\Support\RecordingPipelineObserver;
use Condoedge\Ai\Tests\Fixtures\ScriptedUsageLlm;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Follow-up question chips, end to end through AiManager — one test per branch that
 * assembles `metadata.response.invocations` (what AskQuestionUseCase persists on the
 * assistant message):
 *
 *  - CLARIFY: every candidate reading is a chip; the branch still makes no LLM call;
 *  - DIGEST: a section that shows only a part of its records gets its "go through" chip;
 *    the composer's call stays the turn's only one;
 *  - KNOWLEDGE: the chips are computed AFTER composition by the focused follow-up call
 *    (its own observed stage, after 'composition');
 *  - DATA: no follow-up call, the existing chips unchanged.
 */
class AiManagerFollowUpChipsTest extends TestCase
{
    private ContextRetrieverInterface $context;
    private QueryGeneratorInterface $generator;
    private QueryExecutorInterface $executor;
    private ResponseGeneratorInterface $responseGenerator;
    private LlmProviderInterface $llm;

    /** The follow-up writer's own model (the query-understanding role in production). */
    private ScriptedUsageLlm $followUpLlm;

    /** @var array<int, array<string, mixed>> */
    private array $files = [];

    /** @var list<string> the order in which the turn's model calls happened */
    private array $calls = [];

    public function setUp(): void
    {
        parent::setUp();

        config([
            'logging.default' => 'null',
            'ai.security.enabled' => false,
            'ai.file_context.enabled' => true,
            'ai.understanding.clarify_enabled' => true,
            'ai.attention_sources' => [],
            'ai.entity_actions' => [
                'Unit' => ['view' => ['action' => fn ($id, $text = 'Open unit') => "unit:{$id}", 'label' => 'Open unit']],
            ],
            'ai.generic_actions' => [],
        ]);

        $this->context = Mockery::mock(ContextRetrieverInterface::class);
        $this->generator = Mockery::mock(QueryGeneratorInterface::class);
        $this->executor = Mockery::mock(QueryExecutorInterface::class);
        $this->executor->shouldReceive('executeCount')->andReturn(0)->byDefault();
        $this->responseGenerator = Mockery::mock(ResponseGeneratorInterface::class);
        $this->llm = Mockery::mock(LlmProviderInterface::class);
        // Per excerpt the writer offers (the passages the answer did not use): the fact it
        // states, and the question that asks for it.
        $this->followUpLlm = new ScriptedUsageLlm(json: [
            'f1' => 'A defect may be deferred only when the list allows it',
            'q1' => 'When may a defect be deferred?',
            'f2' => 'Maintenance records are kept for five years',
            'q2' => 'How long are maintenance records kept?',
        ]);
        $this->files = [];
        $this->calls = [];
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function manager(): AiManager
    {
        $files = Mockery::mock(FileContextProvider::class);
        $files->shouldReceive('getFileContext')
            ->andReturnUsing(fn () => ['relevant_files' => $this->files, 'file_count' => count($this->files)]);

        $vectorStore = Mockery::mock(VectorStoreInterface::class);
        $vectorStore->shouldReceive('collectionExists')->andReturn(true)->byDefault();
        $vectorStore->shouldReceive('upsert')->andReturn(true)->byDefault();
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embed')->andReturn(array_fill(0, 8, 0.1))->byDefault();

        return new AiManager(
            ingestion: Mockery::mock(DataIngestionServiceInterface::class),
            context: $this->context,
            embedding: $embedding,
            llm: $this->llm,
            queryGenerator: $this->generator,
            queryExecutor: $this->executor,
            responseGenerator: $this->responseGenerator,
            vectorStore: $vectorStore,
            fileContextProvider: $files,
            actionAttacher: new ActionAttacher(new AiActionRegistry(), new FollowUpWriter($this->followUpLlm)),
        );
    }

    private function turn(QueryUnderstandingResult $qu, string $question): void
    {
        $ctx = new PipelineContext();
        $ctx->question = $question;
        $ctx->qu = $qu;
        $ctx->graphSchema = ['labels' => ['Unit']];
        $this->context->shouldReceive('retrieveContext')->once()->andReturn($ctx);
    }

    private function user(): object
    {
        return (object) ['id' => 13, 'name' => 'Lea Lead', 'email' => 'lead@example.com', 'current_team_id' => 3];
    }

    private function threePassages(): void
    {
        // One passage per document, descending relevance: the pipeline ranks the documents
        // by score, and [1], [2], [3] follow that rank.
        $passage = static fn (int $file, string $name, float $relevance, string $heading, string $text): array => [
            'file_id' => $file, 'filename' => $name, 'snippet' => $text, 'relevance' => $relevance,
            'chunk_index' => 0, 'source' => 'database', 'heading' => $heading,
        ];
        $this->files = [
            $passage(77, 'line-maintenance.pdf', 0.9, 'Part 4 › Line maintenance', 'The operator inspects the aircraft before each flight.'),
            $passage(78, 'deferred-defects.pdf', 0.8, 'Part 4 › Deferred defects', 'A defect may be deferred only when the minimum equipment list allows it.'),
            $passage(79, 'records.pdf', 0.7, 'Part 5 › Records', 'Maintenance records are kept for five years.'),
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return list<array<string, mixed>> the follow-up chips of a response
     */
    private function followUps(array $response): array
    {
        return array_values(array_filter(
            (array) ($response['metadata']['response']['invocations'] ?? []),
            static fn (array $invocation): bool => $invocation['key'] === 'ask_follow_up'
        ));
    }

    // ------------------------------------------------------------- knowledge

    /** @test */
    public function a_knowledge_turn_gets_follow_up_chips_computed_after_composition(): void
    {
        $this->threePassages();
        $this->turn(
            new QueryUnderstandingResult(searchQuery: 'pre-flight checks', intent: 'lookup', questionType: 'knowledge_query', language: 'en'),
            'What must be checked before each flight?'
        );
        $this->generator->shouldReceive('generate')->never();
        $this->responseGenerator->shouldReceive('generate')->once()->andReturnUsing(function (): array {
            // Composition happens BEFORE the follow-up call: nothing was asked of it yet.
            $this->calls[] = 'composition (follow-up calls so far: ' . count($this->followUpLlm->calls) . ')';

            return ['answer' => 'Before each flight the operator inspects the aircraft [1].', 'metadata' => ['style' => 'friendly']];
        });

        $observer = new RecordingPipelineObserver();
        $result = $this->manager()->answerQuestion('What must be checked before each flight?', ['observer' => $observer, 'user' => $this->user()]);

        $this->assertSame(['composition (follow-up calls so far: 0)'], $this->calls);
        $this->assertCount(1, $this->followUpLlm->calls, 'ONE follow-up call');

        $this->assertSame([
            ['key' => 'ask_follow_up', 'entity_type' => null, 'entity_id' => null, 'params' => ['question' => 'When may a defect be deferred?'], 'text' => 'When may a defect be deferred?'],
            ['key' => 'ask_follow_up', 'entity_type' => null, 'entity_id' => null, 'params' => ['question' => 'How long are maintenance records kept?'], 'text' => 'How long are maintenance records kept?'],
        ], $result['metadata']['response']['invocations']);
        $this->assertSame([
            ['passage' => 2, 'question' => 'When may a defect be deferred?'],
            ['passage' => 3, 'question' => 'How long are maintenance records kept?'],
        ], $result['metadata']['response']['follow_ups']);
        $this->assertSame('Before each flight the operator inspects the aircraft [1].', $result['answer']);

        // Its own stage, after the composition stage closed (the composition's wall time
        // does not include it).
        $this->assertSame(
            ['understanding', 'file_context', 'knowledge_query', 'composition', 'follow_ups'],
            $observer->finishedStages()
        );
        $this->assertSame([2, 3], $observer->stageData('follow_ups')['passages']);

        // The writer was given the user's question, the final answer and — as excerpts —
        // the passages that answer did not use ([1], which it cites, is not one of them).
        $prompt = $this->followUpLlm->calls[0][1]['content'];
        $this->assertStringContainsString('QUESTION (written in "en"): What must be checked before each flight?', $prompt);
        $this->assertStringContainsString('ANSWER: Before each flight the operator inspects the aircraft [1].', $prompt);
        $this->assertStringContainsString('E1 — deferred-defects.pdf — Part 4 › Deferred defects', $prompt);
        $this->assertStringContainsString('E2 — records.pdf — Part 5 › Records', $prompt);
        $this->assertStringNotContainsString('Line maintenance', $prompt);
    }

    /**
     * @test CODE-GATED writer. The unused passages were found by a REWRITE of the question
     * only (here QU's search query), never by the user's own wording: retrieval may have
     * drifted there, so they are no source of follow-ups — no call, no chip. (Live
     * 2026-10-06, the small model: a wall-removal question got chips about common
     * expenses and a movable hypothec.)
     */
    public function passages_the_users_own_wording_did_not_find_give_no_follow_up(): void
    {
        $this->threePassages();
        $question = 'What must be checked before each flight?';
        $this->turn(
            new QueryUnderstandingResult(searchQuery: 'pre-flight checks', intent: 'lookup', questionType: 'knowledge_query', language: 'en'),
            $question
        );
        $this->responseGenerator->shouldReceive('generate')->once()->andReturn([
            'answer' => 'Before each flight the operator inspects the aircraft [1].',
            'metadata' => [],
        ]);

        // The question finds the first document only; the search query finds all three.
        $files = Mockery::mock(FileContextProvider::class);
        $files->shouldReceive('getFileContext')->andReturnUsing(function ($query) use ($question): array {
            $found = $query === $question ? array_slice($this->files, 0, 1) : $this->files;

            return ['relevant_files' => $found, 'file_count' => count($found)];
        });
        $manager = new AiManager(
            ingestion: Mockery::mock(DataIngestionServiceInterface::class),
            context: $this->context,
            embedding: tap(Mockery::mock(EmbeddingProviderInterface::class), fn ($m) => $m->shouldReceive('embed')->andReturn(array_fill(0, 8, 0.1))),
            llm: $this->llm,
            queryGenerator: $this->generator,
            queryExecutor: $this->executor,
            responseGenerator: $this->responseGenerator,
            vectorStore: tap(Mockery::mock(VectorStoreInterface::class), function ($m): void {
                $m->shouldReceive('collectionExists')->andReturn(true);
                $m->shouldReceive('upsert')->andReturn(true);
            }),
            fileContextProvider: $files,
            // The code-gated writer (for a model that cannot decline by itself): no floor here.
            actionAttacher: new ActionAttacher(new AiActionRegistry(), new FollowUpWriter($this->followUpLlm, null, false)),
        );
        config(['ai.attached_actions.follow_ups.min_relevance' => 0]);

        $observer = new RecordingPipelineObserver();
        $result = $manager->answerQuestion($question, ['observer' => $observer, 'user' => $this->user()]);

        $support = array_column($observer->stageData('file_context')['files'], 'angle_groups');
        $this->assertSame([['search_query', 'question'], ['search_query'], ['search_query']], $support);
        $this->assertSame([], $this->followUpLlm->calls, 'no excerpt to ask about: no call');
        $this->assertArrayNotHasKey('invocations', $result['metadata']['response']);
        $this->assertArrayNotHasKey('follow_ups', $result['metadata']['response']);
    }

    /** @test "The documents do not say": an answer that cites no passage gets no follow-up call. */
    public function a_knowledge_answer_that_cites_nothing_gets_no_follow_up_call(): void
    {
        $this->threePassages();
        $this->turn(
            new QueryUnderstandingResult(searchQuery: 'night flights', intent: 'lookup', questionType: 'knowledge_query', language: 'en'),
            'Are night flights allowed?'
        );
        $this->responseGenerator->shouldReceive('generate')->once()->andReturn([
            'answer' => 'The documents I can read do not cover night flights.',
            'metadata' => [],
        ]);

        $observer = new RecordingPipelineObserver();
        $result = $this->manager()->answerQuestion('Are night flights allowed?', ['observer' => $observer, 'user' => $this->user()]);

        $this->assertSame([], $this->followUpLlm->calls);
        $this->assertArrayNotHasKey('invocations', $result['metadata']['response']);
        $this->assertNotContains('follow_ups', $observer->finishedStages());
    }

    /** @test The follow-up call is decoration: its failure never costs the answer. */
    public function a_failing_follow_up_call_leaves_the_answer_intact(): void
    {
        $this->threePassages();
        $this->turn(
            new QueryUnderstandingResult(searchQuery: 'pre-flight checks', intent: 'lookup', questionType: 'knowledge_query', language: 'en'),
            'What must be checked before each flight?'
        );
        $this->responseGenerator->shouldReceive('generate')->once()->andReturn([
            'answer' => 'Before each flight the operator inspects the aircraft [1].',
            'metadata' => [],
        ]);
        $failing = Mockery::mock(LlmProviderInterface::class);
        $failing->shouldReceive('chatJson')->once()->andThrow(new \RuntimeException('provider timeout'));

        $manager = new AiManager(
            ingestion: Mockery::mock(DataIngestionServiceInterface::class),
            context: $this->context,
            embedding: tap(Mockery::mock(EmbeddingProviderInterface::class), fn ($m) => $m->shouldReceive('embed')->andReturn(array_fill(0, 8, 0.1))),
            llm: $this->llm,
            queryGenerator: $this->generator,
            queryExecutor: $this->executor,
            responseGenerator: $this->responseGenerator,
            vectorStore: tap(Mockery::mock(VectorStoreInterface::class), function ($m): void {
                $m->shouldReceive('collectionExists')->andReturn(true);
                $m->shouldReceive('upsert')->andReturn(true);
            }),
            fileContextProvider: tap(Mockery::mock(FileContextProvider::class), fn ($m) => $m->shouldReceive('getFileContext')
                ->andReturnUsing(fn () => ['relevant_files' => $this->files, 'file_count' => count($this->files)])),
            actionAttacher: new ActionAttacher(new AiActionRegistry(), new FollowUpWriter($failing)),
        );

        $result = $manager->answerQuestion('What must be checked before each flight?', ['user' => $this->user()]);

        $this->assertSame('Before each flight the operator inspects the aircraft [1].', $result['answer']);
        $this->assertArrayNotHasKey('invocations', $result['metadata']['response']);
    }

    // ------------------------------------------------------------- data

    /** @test */
    public function a_data_turn_makes_no_follow_up_call_and_keeps_its_chips(): void
    {
        $this->turn(
            new QueryUnderstandingResult(entityTypes: ['Unit'], searchQuery: 'units', intent: 'list', questionType: 'data_query', language: 'en'),
            'List the units.'
        );
        $this->generator->shouldReceive('generate')->once()->andReturn(new QueryGenerationResult(
            cypher: 'MATCH (u:Unit) RETURN u.id AS unit_id, u.name AS unit_name',
            confidence: 0.9,
        ));
        $this->executor->shouldReceive('execute')->andReturn(new QueryExecutionResult(
            success: true,
            data: [['unit_id' => 1001, 'unit_name' => 'Lot 1'], ['unit_id' => 1002, 'unit_name' => 'Lot 2']],
        ));
        $this->responseGenerator->shouldReceive('generate')->once()->andReturn(['answer' => 'Two units.', 'metadata' => []]);

        $observer = new RecordingPipelineObserver();
        $result = $this->manager()->answerQuestion('List the units.', ['observer' => $observer, 'user' => $this->user()]);

        $this->assertSame([], $this->followUpLlm->calls);
        $this->assertNotContains('follow_ups', $observer->finishedStages());
        $this->assertSame(['view', 'view'], array_column($result['metadata']['response']['invocations'], 'key'));
        $this->assertSame([], $this->followUps($result));
        $this->assertArrayNotHasKey('follow_ups', $result['metadata']['response']);
    }

    // ------------------------------------------------------------- clarify

    /** @test */
    public function a_clarify_turn_carries_one_chip_per_reading_and_makes_no_llm_call(): void
    {
        $this->turn(new QueryUnderstandingResult(
            searchQuery: 'balance',
            intent: 'lookup',
            questionType: 'data_query',
            language: 'en',
            needsClarification: true,
            candidateReadings: ['the balance of your own unit', 'the receivables of the whole building'],
            clarifyingQuestion: 'Do you mean the balance of your own unit, or the receivables of the whole building?',
        ), 'Show me the balance.');
        $this->generator->shouldReceive('generate')->never();
        $this->responseGenerator->shouldReceive('generate')->never();
        // $this->llm is a strict mock: any call on it would fail the test.

        $observer = new RecordingPipelineObserver();
        $result = $this->manager()->answerQuestion('Show me the balance.', ['observer' => $observer, 'user' => $this->user()]);

        $this->assertSame('Do you mean the balance of your own unit, or the receivables of the whole building?', $result['answer']);
        $this->assertSame([
            ['key' => 'ask_follow_up', 'entity_type' => null, 'entity_id' => null, 'params' => ['question' => 'the balance of your own unit'], 'text' => 'The balance of your own unit'],
            ['key' => 'ask_follow_up', 'entity_type' => null, 'entity_id' => null, 'params' => ['question' => 'the receivables of the whole building'], 'text' => 'The receivables of the whole building'],
        ], $result['metadata']['response']['invocations']);
        $this->assertSame(['follow_up', 'follow_up'], array_column($result['metadata']['response']['attached_actions'], 'role'));
        $this->assertSame(
            ['the balance of your own unit', 'the receivables of the whole building'],
            $result['metadata']['response']['clarification']['candidate_readings'],
            'the readings still ride the metadata'
        );

        $this->assertSame([], $this->followUpLlm->calls, 'zero extra LLM call');
        $this->assertSame(['understanding', 'clarify', 'composition'], $observer->finishedStages());
    }

    /** @test Without an authenticated user the clarifying question is as before: no chip. */
    public function a_clarify_turn_without_a_user_has_no_chip(): void
    {
        $this->turn(new QueryUnderstandingResult(
            searchQuery: 'balance',
            questionType: 'data_query',
            needsClarification: true,
            candidateReadings: ['reading one', 'reading two'],
            clarifyingQuestion: 'Which one?',
        ), 'Show me the balance.');

        $result = $this->manager()->answerQuestion('Show me the balance.');

        $this->assertSame('Which one?', $result['answer']);
        $this->assertArrayNotHasKey('invocations', $result['metadata']['response']);
    }

    // ------------------------------------------------------------- digest

    /** @test */
    public function a_digest_turn_gets_a_follow_up_for_its_truncated_section_and_one_llm_call(): void
    {
        config(['ai.attention_sources' => [
            'open_units' => [
                'label' => 'Units to review',
                'cypher' => 'MATCH (u:Unit) RETURN u.id AS id, u.name AS unit_name',
                'entity' => 'Unit',
                'limit' => 2,
            ],
        ]]);
        $this->turn(
            new QueryUnderstandingResult(intent: 'list', questionType: 'data_query', language: 'en', digestRequest: true, searchQuery: 'attention'),
            'What should I take care of next?'
        );
        // The section's rows hit their cap and the companion count says 40 exist.
        $this->executor->shouldReceive('execute')->andReturn(new QueryExecutionResult(
            success: true,
            data: [['id' => 1001, 'unit_name' => 'Lot 1'], ['id' => 1002, 'unit_name' => 'Lot 2']],
        ));
        $this->executor->shouldReceive('executeCount')->andReturn(40);
        $prompts = [];
        $this->llm->shouldReceive('chat')->once()->andReturnUsing(function (array $messages) use (&$prompts): string {
            $prompts[] = implode("\n", array_column($messages, 'content'));

            return "Here is what needs you.\n{{section:open_units}} 40 units are waiting.\n{{nothing_pending}}\nWant me to go through the units one by one?";
        });

        $result = $this->manager()->answerQuestion('What should I take care of next?', ['user' => $this->user()]);

        $invocations = $result['metadata']['response']['invocations'];
        $this->assertSame(['view', 'view', 'ask_follow_up'], array_column($invocations, 'key'), 'after the record chips');
        $this->assertSame('Go through Units to review', $invocations[2]['text']);
        $this->assertSame(['question' => 'Go through Units to review'], $invocations[2]['params']);
        $this->assertStringContainsString('Showing 2 of 40.', $result['answer']);

        $this->assertCount(1, $prompts, 'the composer\'s call is the turn\'s only one');
        $this->assertStringNotContainsString('Go through', $prompts[0], 'the model is not told about the follow-up chips');
        $this->assertSame([], $this->followUpLlm->calls);
    }
}
