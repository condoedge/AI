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
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\ResponseSections\FileContextSection;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * ai.file_context.uploads_yield_to_data: a host whose data questions also search the documents
 * (skip_for_question_types = []) keeps its help articles there, while an uploaded file, a dated
 * picture of the data, never competes with rows the data just returned.
 */
class AiManagerUploadsYieldToDataTest extends TestCase
{
    private const HELP = ['file_id' => 'physical:help.md', 'filename' => 'help.md', 'snippet' => 'Open the page.', 'relevance' => 0.8, 'source' => 'physical'];
    private const UPLOAD = ['file_id' => 7, 'filename' => 'minutes-2020.pdf', 'snippet' => 'Budget: 100.', 'relevance' => 0.9, 'source' => 'database'];

    private ?string $lastAnswer = null;

    private array $factsReply = ['answered' => false, 'answer' => ''];

    public function setUp(): void
    {
        parent::setUp();

        config(['ai.security.enabled' => false, 'ai.file_context.enabled' => true, 'ai.file_context.skip_for_question_types' => []]);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** One turn, documents found as given, the query returning $rows. */
    private function turn(string $questionType, array $rows, string $cypher = 'MATCH (t:Thing) RETURN t.amount', array $found = [self::HELP, self::UPLOAD]): PipelineContext
    {
        $ctx = new PipelineContext();
        $ctx->question = 'What is the amount?';
        $ctx->qu = new QueryUnderstandingResult(searchQuery: 'amount', intent: 'lookup', questionType: $questionType);
        $ctx->similarQueries = [];
        $ctx->graphSchema = ['labels' => ['Thing']];

        $retriever = Mockery::mock(ContextRetrieverInterface::class);
        $retriever->shouldReceive('retrieveContext')->andReturn($ctx);
        $store = Mockery::mock(VectorStoreInterface::class);
        $store->shouldReceive('collectionExists')->andReturn(true);
        $store->shouldReceive('upsert')->andReturn(true);
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embed')->andReturn(array_fill(0, 1536, 0.1));
        $generator = Mockery::mock(QueryGeneratorInterface::class);
        $generator->shouldReceive('generate')->andReturn(new QueryGenerationResult(cypher: $cypher, explanation: '', confidence: 0.9, warnings: [], metadata: []));
        $executor = Mockery::mock(QueryExecutorInterface::class);
        $executor->shouldReceive('execute')->andReturn(new QueryExecutionResult(success: true, data: $rows, stats: [], metadata: [], errors: []));
        $responder = Mockery::mock(ResponseGeneratorInterface::class);
        $responder->shouldReceive('generate')->andReturn(['answer' => 'ok', 'insights' => [], 'visualizations' => [], 'metadata' => []]);
        $files = Mockery::mock(FileContextProvider::class);
        $files->shouldReceive('getFileContext')->andReturn(['relevant_files' => $found, 'file_count' => count($found)]);

        $llm = Mockery::mock(LlmProviderInterface::class)->shouldIgnoreMissing();
        $llm->shouldReceive('chatJson')->andReturn($this->factsReply);

        $this->lastAnswer = (new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            $retriever,
            $embedding,
            $llm,
            $generator,
            $executor,
            $responder,
            $store,
            $files
        ))->answerQuestion($ctx->question, ['user' => (object) ['id' => 1]])['answer'] ?? null;

        return $ctx;
    }

    private function givenFacts(): void
    {
        $this->app->instance('test.facts', new class implements \Condoedge\Ai\Contracts\AskerFactsInterface {
            public function factsFor(mixed $user): array
            {
                return ['Their share is 2 %.'];
            }
        });
        config(['ai.audiences.facts_resolver' => 'test.facts']);
    }

    private function limitAudience(): void
    {
        config(['ai.file_context.uploads_yield_to_data' => true, 'ai.audiences.resolver' => 'test.audience', 'ai.audiences.map.resident' => ['data_entities' => ['Thing']]]);
        $this->app->instance('test.audience', new class implements \Condoedge\Ai\Contracts\AudienceResolverInterface {
            public function resolve(mixed $user): ?\Condoedge\Ai\DTOs\ResolvedAudience
            {
                return new \Condoedge\Ai\DTOs\ResolvedAudience('resident');
            }
        });
    }

    private function sources(PipelineContext $ctx): array
    {
        return array_values(array_unique(array_column($ctx->fileContext['relevant_files'] ?? [], 'source')));
    }

    /** @test */
    public function rows_on_a_data_question_drop_the_uploaded_files_and_keep_the_help_articles(): void
    {
        config(['ai.file_context.uploads_yield_to_data' => true]);

        $ctx = $this->turn('data_query', [['amount' => 12]]);

        $this->assertSame(['physical'], $this->sources($ctx));
        $this->assertSame(1, $ctx->fileContext['file_count']);
        $this->assertFalse($ctx->fileContext['has_database']);
        $this->assertTrue($ctx->fileContext['has_physical']);
    }

    /** @test */
    public function a_data_question_with_no_rows_keeps_its_uploaded_files(): void
    {
        config(['ai.file_context.uploads_yield_to_data' => true]);

        $ctx = $this->turn('data_query', []);

        $this->assertContains('database', $this->sources($ctx));
        $this->assertTrue($ctx->fileContext['uploads_are_dated']);
    }

    /** @test */
    public function a_question_that_also_asks_the_documents_keeps_them_beside_the_rows(): void
    {
        config(['ai.file_context.uploads_yield_to_data' => true]);

        $this->assertContains('database', $this->sources($this->turn('hybrid', [['amount' => 12]])));
    }

    /** @test */
    public function a_limited_audience_never_gets_a_figure_from_an_upload_on_a_data_question(): void
    {
        $this->limitAudience();

        $ctx = $this->turn('data_query', []);

        $this->assertSame(['physical'], $this->sources($ctx));
        $this->assertFalse($ctx->dataOutOfReach, 'The query ran: there is simply nothing.');
    }

    /** @test */
    public function a_limited_audience_keeps_the_uploads_on_a_question_about_the_documents(): void
    {
        $this->limitAudience();

        $this->assertContains('database', $this->sources($this->turn('knowledge_query', [])));
    }

    /** @test */
    public function a_data_question_that_could_not_be_queried_is_refused_when_nothing_else_answers_it(): void
    {
        $this->limitAudience();

        $ctx = $this->turn('data_query', [], '', []);

        $this->assertTrue($ctx->dataOutOfReach);
        $this->assertSame((string) trans('ai.error.no_access'), $this->lastAnswer);
    }

    /** @test */
    public function a_fact_answers_a_data_question_with_no_rows_in_its_own_small_call(): void
    {
        $this->givenFacts();
        $this->factsReply = ['answered' => true, 'answer' => 'Your share is 2 %.'];

        $ctx = $this->turn('data_query', [], '', []);

        $this->assertSame(['Their share is 2 %.'], $ctx->askerFacts);
        $this->assertSame('Your share is 2 %.', $this->lastAnswer);
    }

    /** @test */
    public function when_no_fact_answers_a_question_out_of_reach_it_is_refused(): void
    {
        $this->limitAudience();
        $this->givenFacts();
        $this->factsReply = ['answered' => false, 'answer' => ''];

        $this->turn('data_query', [], '', []);

        $this->assertSame((string) trans('ai.error.no_access'), $this->lastAnswer);
    }

    /** @test */
    public function rows_never_ask_the_facts(): void
    {
        $this->givenFacts();
        $this->factsReply = ['answered' => true, 'answer' => 'from the facts'];

        $this->turn('data_query', [['amount' => 12]]);

        $this->assertSame('ok', $this->lastAnswer);
    }

    /** @test */
    public function off_by_default_nothing_is_dropped(): void
    {
        $ctx = $this->turn('data_query', [['amount' => 12]]);

        $this->assertContains('database', $this->sources($ctx));
        $this->assertArrayNotHasKey('uploads_are_dated', $ctx->fileContext);
    }

    /** @test */
    public function an_uploaded_figure_must_name_its_document_and_year_only_on_a_turn_flagged_for_it(): void
    {
        $ctx = new PipelineContext();
        $ctx->question = 'What is the amount?';
        $ctx->fileContext = ['relevant_files' => [self::HELP, self::UPLOAD], 'file_count' => 2];
        $section = new FileContextSection();

        $this->assertStringNotContainsString('as of that document', $section->format($ctx), 'Not opted in: the prompt is unchanged.');

        $ctx->fileContext['uploads_are_dated'] = true;
        $this->assertStringContainsString('as of that document', $section->format($ctx));
    }
}
