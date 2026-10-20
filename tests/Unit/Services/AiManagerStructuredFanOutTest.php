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
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Phase 7 fan-out over structure-aware chunks:
 *  - 7.2  a question naming an article ("art. 1069") adds an exact-article angle
 *         (payload filter, no relevance floor) and the named article LEADS;
 *  - 7.1b preamble references never set a file's relevance and a preamble-only
 *         file is dropped from the top-k unless nothing else matched.
 */
class AiManagerStructuredFanOutTest extends TestCase
{
    /** @var list<array{0: string, 1: array}> */
    private array $calls = [];

    /** @var callable(string, array): array */
    private $respond;

    public function setUp(): void
    {
        parent::setUp();
        config(['ai.file_context.enabled' => true, 'ai.file_context.min_relevance_score' => 0.4, 'ai.file_context.max_references' => 5]);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function fanOut(QueryUnderstandingResult $qu, string $question): array
    {
        $provider = Mockery::mock(FileContextProvider::class);
        $provider->shouldReceive('getFileContext')->andReturnUsing(function ($query, $user, $options = []) {
            $this->calls[] = [$query, $options];
            $files = ($this->respond)($query, $options);
            return ['relevant_files' => $files, 'file_count' => count($files)];
        });

        $manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            Mockery::mock(ContextRetrieverInterface::class),
            Mockery::mock(EmbeddingProviderInterface::class),
            Mockery::mock(LlmProviderInterface::class),
            Mockery::mock(QueryGeneratorInterface::class),
            Mockery::mock(QueryExecutorInterface::class),
            Mockery::mock(ResponseGeneratorInterface::class),
            Mockery::mock(VectorStoreInterface::class),
            $provider
        );

        $ctx = new PipelineContext();
        $ctx->question = $question;
        $ctx->qu = $qu;
        $trace = [];
        $method = new \ReflectionMethod($manager, 'fanOutFileContext');
        $method->setAccessible(true);
        $ctx = $method->invokeArgs($manager, [$ctx, $question, ['user' => (object) ['id' => 13]], &$trace]);

        return $ctx->fileContext['relevant_files'] ?? [];
    }

    private static function ref(string $fileId, int $chunkIndex, float $relevance, array $extra = []): array
    {
        return [
            'file_id' => $fileId,
            'filename' => basename($fileId),
            'snippet' => "chunk {$chunkIndex}",
            'relevance' => $relevance,
            'chunk_index' => $chunkIndex,
            'source' => 'physical',
        ] + $extra;
    }

    /** @test */
    public function a_question_naming_an_article_adds_an_exact_article_angle_and_the_article_leads(): void
    {
        $this->respond = function (string $query, array $options): array {
            if (!empty($options['article_numbers'])) {
                return [self::ref('physical:laws/ccq.md', 66, 0.33, ['article_no' => '1069', 'article_match' => true])];
            }
            return [
                self::ref('physical:laws/ccq.md', 20, 0.62, ['article_no' => '1029']),
                self::ref('physical:docs/units.md', 1, 0.55),
            ];
        };

        $files = $this->fanOut(
            new QueryUnderstandingResult(searchQuery: 'buyer liability article 1069', questionType: 'knowledge_query'),
            'What does art. 1069 say about a buyer?'
        );

        $articleCalls = array_values(array_filter($this->calls, fn ($c) => !empty($c[1]['article_numbers'])));
        $this->assertCount(1, $articleCalls, 'one exact-article angle');
        $this->assertSame(['1069'], $articleCalls[0][1]['article_numbers']);
        $this->assertSame(0.0, $articleCalls[0][1]['min_score']);

        $this->assertSame(66, $files[0]['chunk_index'], 'the named article leads');
        $this->assertSame('1069', $files[0]['article_no']);
    }

    /** @test */
    public function every_angle_asks_for_the_per_file_passage_budget(): void
    {
        config(['ai.file_context.max_chunks_per_file' => 4]);
        $this->respond = fn () => [self::ref('physical:laws/ccq.md', 66, 0.6)];

        $this->fanOut(new QueryUnderstandingResult(searchQuery: 'angle-a', questionType: 'knowledge_query'), 'angle-b');

        $this->assertNotEmpty($this->calls);
        foreach ($this->calls as [, $options]) {
            $this->assertSame(4, $options['chunks_per_file'] ?? null);
        }
    }

    /** @test */
    public function every_search_of_one_fan_out_shares_one_memo_and_the_next_fan_out_gets_a_new_one(): void
    {
        // Access resolution and file reads are the same for every angle of a turn
        // (FileContextProviderTurnMemoTest); the memo must not outlive the turn.
        config(['ai.file_context.recall_floor' => 0.3]);
        $this->respond = fn (string $query, array $options): array => !empty($options['article_numbers'])
            ? [self::ref('physical:laws/ccq.md', 66, 0.33, ['article_match' => true])]
            : [];
        $qu = new QueryUnderstandingResult(searchQuery: 'buyer liability', questionType: 'knowledge_query', clarifiedQuestion: 'clarified');

        $this->fanOut($qu, 'What does art. 1069 say about a buyer?');
        $first = array_map(static fn (array $call) => $call[1][FileContextProvider::TURN_MEMO] ?? null, $this->calls);

        $this->assertGreaterThanOrEqual(4, count($first), 'plain angles + the exact-article angle');
        $this->assertInstanceOf(\ArrayObject::class, $first[0]);
        foreach ($first as $memo) {
            $this->assertSame($first[0], $memo);
        }

        $this->calls = [];
        $this->respond = fn (): array => [];
        $this->fanOut($qu, 'A question no document answers');
        $second = array_map(static fn (array $call) => $call[1][FileContextProvider::TURN_MEMO] ?? null, $this->calls);

        $this->assertGreaterThan(3, count($second), 'the angles, then the recall-floor retry of each');
        foreach ($second as $memo) {
            $this->assertSame($second[0], $memo);
        }
        $this->assertNotSame($first[0], $second[0]);
    }

    /** @test */
    public function no_article_angle_without_a_numeric_reference(): void
    {
        $this->respond = fn () => [self::ref('physical:laws/ccq.md', 66, 0.6)];

        $this->fanOut(
            new QueryUnderstandingResult(searchQuery: 'buyer previous owner unpaid fees', questionType: 'knowledge_query'),
            "If I buy a unit, am I liable for the previous owner's unpaid fees?"
        );

        foreach ($this->calls as [, $options]) {
            $this->assertArrayNotHasKey('article_numbers', $options);
        }
    }

    /** @test */
    public function a_preamble_reference_never_sets_the_files_relevance(): void
    {
        $this->respond = function (string $query): array {
            return $query === 'Quebec Civil Code'
                ? [self::ref('physical:laws/ccq.md', 0, 0.80, ['is_preamble' => true])]
                : [self::ref('physical:laws/ccq.md', 66, 0.55, ['is_preamble' => false, 'article_no' => '1069'])];
        };

        $files = $this->fanOut(
            new QueryUnderstandingResult(searchQuery: 'buyer unpaid common expenses', questionType: 'knowledge_query', searchTags: ['Quebec', 'Civil', 'Code']),
            'Quebec Civil Code'
        );

        $this->assertSame([66], array_column($files, 'chunk_index'));
        $this->assertEqualsWithDelta(0.55, $files[0]['relevance'], 1e-9);
    }

    /** @test */
    public function with_structural_chunks_the_primary_is_the_most_relevant_passage_not_the_deepest(): void
    {
        // The "deepest snippet within the margin" rule was a proxy for "not the
        // cover page". Structure-aware chunks flag the cover (is_preamble), so the
        // proxy now only demotes the better article for a later one.
        $this->respond = fn (string $query): array => $query === 'angle-a'
            ? [self::ref('physical:laws/ccq.md', 60, 0.52, ['is_preamble' => false])]
            : [self::ref('physical:laws/ccq.md', 77, 0.47, ['is_preamble' => false])];

        $files = $this->fanOut(new QueryUnderstandingResult(searchQuery: 'angle-a', questionType: 'knowledge_query'), 'angle-b');

        $this->assertSame(60, $files[0]['chunk_index']);
    }

    /** @test */
    public function a_files_passages_are_chosen_by_cross_angle_consensus_not_one_angles_cosine(): void
    {
        // Live (buyer question, one article per chunk): art. 1069 ranked 1st-4th
        // on every angle but was never any angle's highest cosine, so max-cosine
        // selection kept 1064.1 / 1074.2 / 1019 and dropped it. Reciprocal-rank
        // fusion of each angle's chunk ranking WITHIN the file picks the passage
        // every phrasing agrees on.
        config(['ai.file_context.max_chunks_per_file' => 2]);
        $ccq = 'physical:laws/ccq.md';
        $angles = [
            'angle-a' => [60 => 0.52, 66 => 0.47, 77 => 0.45],
            'angle-b' => [66 => 0.46, 77 => 0.44, 60 => 0.43],
            'angle-c' => [77 => 0.53, 66 => 0.50, 60 => 0.41],
        ];
        $this->respond = function (string $query) use ($angles, $ccq): array {
            $scores = $angles[$query] ?? [];
            $refs = [];
            foreach (array_slice($scores, 0, 2, true) as $index => $score) {
                $refs[] = self::ref($ccq, $index, $score, ['is_preamble' => false, 'chunk_scores' => $scores]);
            }
            return $refs;
        };

        $files = $this->fanOut(
            new QueryUnderstandingResult(searchQuery: 'angle-a', questionType: 'knowledge_query', clarifiedQuestion: 'angle-c'),
            'angle-b'
        );

        $this->assertSame(66, $files[0]['chunk_index'], 'the consensus passage leads');
        $this->assertCount(2, $files);
        $this->assertArrayNotHasKey('chunk_scores', $files[0], 'the per-angle score maps do not leak into the prompt context');
    }

    /** @test */
    public function a_preamble_only_file_is_dropped_unless_nothing_else_matched(): void
    {
        $this->respond = fn (string $query): array => [
            self::ref('physical:laws/ccq.md', 0, 0.80, ['is_preamble' => true]),
            self::ref('physical:docs/team.md', 1, 0.50, ['is_preamble' => false]),
        ];
        $files = $this->fanOut(new QueryUnderstandingResult(searchQuery: 'add members', questionType: 'knowledge_query'), 'Where can I add new members?');
        $this->assertSame(['physical:docs/team.md'], array_column($files, 'file_id'));

        $this->calls = [];
        $this->respond = fn (): array => [self::ref('physical:laws/ccq.md', 0, 0.80, ['is_preamble' => true])];
        $files = $this->fanOut(new QueryUnderstandingResult(searchQuery: 'civil code', questionType: 'knowledge_query'), 'civil code');
        $this->assertSame(['physical:laws/ccq.md'], array_column($files, 'file_id'));
    }
}
