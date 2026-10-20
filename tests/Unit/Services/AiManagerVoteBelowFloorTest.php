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
use Condoedge\Ai\Services\Retrieval\DocumentProbeWriter;
use Condoedge\Ai\Tests\Fixtures\ScriptedUsageLlm;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * An angle whose best match is under the relevance floor still VOTES — for the
 * passage it ranks first. The floor decides what an angle admits, not whether it
 * has a say (eval p16-p21 tear-down).
 *
 * Measured on that turn: the user's own wording scored its best passage 0.38 and
 * QU's search query — a free-text rewrite that differs from run to run on a
 * byte-identical prompt — scored the same passage 0.37 to 0.45. With the floor at
 * 0.40 the question never voted and the search query voted in some runs only; when
 * it did, its ballot was that one passage. So the passage every plain phrasing
 * ranked FIRST led the list in the runs where the rewrite scored 0.41 and came
 * third in the runs where it scored 0.37.
 *
 * A cosine is relative to the wording that produced it (a probe written in the
 * documents' register scores every passage far higher than plain words do); which
 * passage a phrasing ranks first is not. An angle that admits nothing therefore
 * casts the same ballot it casts when its best match just clears the floor: that
 * one passage. It admits no passage by it — a candidate still needs one angle over
 * the floor.
 *
 * NOT the angle's full ranking: voting with every chunk an angle ranks turns the
 * fusion into counting in how many rankings a passage appears at all, and the
 * passage the probes' hypothesis prefers then led in 9 of 9 replayed runs; the
 * live answers anchored on it and cited the user's own best passage as [1] in 0 of
 * 5 runs (5 of 5 before).
 */
class AiManagerVoteBelowFloorTest extends TestCase
{
    private const FILE = 'physical:laws/code.md';

    private const QUESTION = 'Can I just swap out this broken part myself?';

    private const CLARIFIED = 'What are the rules for replacing a part yourself?';

    /** @var callable(string): array<string, array<int, float>> One angle's full ranking: file id => chunk_index => score. */
    private $rankings;

    /** @var list<string> The angles asked for their ranking without the floor, in order. */
    private array $ranked = [];

    /** @var array<string, mixed> */
    private array $trace = [];

    public function setUp(): void
    {
        parent::setUp();

        config([
            'logging.default' => 'null',
            'ai.file_context.enabled' => true,
            'ai.file_context.min_relevance_score' => 0.4,
            'ai.file_context.max_references' => 5,
            'ai.file_context.max_chunks_per_file' => 3,
            'ai.file_context.context_window' => 2,
            'ai.file_context.recall_floor' => 0,
        ]);
        $this->ranked = [];
        $this->trace = [];
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Drive the real fan-out over scripted FULL rankings. Like the real provider,
     * each angle's references are its passages over the relevance floor, and
     * chunkRankings() answers with the angle's ranking of every file without it.
     */
    private function fanOut(string $searchQuery, bool $providerRanks = true): array
    {
        $provider = Mockery::mock(FileContextProvider::class);
        $provider->shouldReceive('getFileContext')->andReturnUsing(function ($query, $user, $options = []) {
            $floor = (float) ($options['min_score'] ?? config('ai.file_context.min_relevance_score'));
            $full = ($this->rankings)($query);

            $files = [];
            foreach ($full as $fileId => $scores) {
                $confident = array_filter($scores, static fn (float $score): bool => $score >= $floor);
                arsort($confident);
                $emitted = 0;
                foreach ($confident as $index => $score) {
                    if ($emitted++ >= (int) ($options['chunks_per_file'] ?? 1)) {
                        break;
                    }
                    $files[] = [
                        'file_id' => $fileId,
                        'filename' => basename((string) $fileId),
                        'snippet' => "chunk {$index}",
                        'relevance' => $score,
                        'chunk_index' => $index,
                        'source' => 'physical',
                        'is_preamble' => false,
                        'chunk_scores' => $confident,
                    ];
                }
            }
            usort($files, static fn (array $a, array $b) => $b['relevance'] <=> $a['relevance']);

            return ['relevant_files' => $files, 'file_count' => count($files)];
        });
        if ($providerRanks) {
            $provider->shouldReceive('chunkRankings')->andReturnUsing(function ($query, $user, $options = []) {
                $this->ranked[] = $query;

                return ($this->rankings)($query);
            });
        }

        $writer = new DocumentProbeWriter(
            new ScriptedUsageLlm(json: ['fr_1' => 'sonde un', 'en_1' => 'probe one', 'fr_2' => 'sonde deux', 'en_2' => 'probe two']),
            ['corpus_languages' => ['fr', 'en'], 'corpus_register' => 'aviation maintenance regulation terminology']
        );

        $manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            Mockery::mock(ContextRetrieverInterface::class),
            Mockery::mock(EmbeddingProviderInterface::class),
            Mockery::mock(LlmProviderInterface::class),
            Mockery::mock(QueryGeneratorInterface::class),
            Mockery::mock(QueryExecutorInterface::class),
            Mockery::mock(ResponseGeneratorInterface::class),
            Mockery::mock(VectorStoreInterface::class),
            $provider,
            probeWriter: $writer,
        );

        $ctx = new PipelineContext();
        $ctx->question = self::QUESTION;
        $ctx->qu = new QueryUnderstandingResult(
            searchQuery: $searchQuery,
            questionType: 'knowledge_query',
            clarifiedQuestion: self::CLARIFIED,
            language: 'en',
        );
        $trace = [];
        $method = new \ReflectionMethod($manager, 'fanOutFileContext');
        $method->setAccessible(true);
        $ctx = $method->invokeArgs($manager, [$ctx, self::QUESTION, ['user' => (object) ['id' => 13]], &$trace]);
        $this->trace = $trace;

        return $ctx->fileContext['relevant_files'] ?? [];
    }

    /**
     * The tear-down turn as measured. Every plain phrasing ranks the file 38, 113,
     * 37 — the user's wording at 0.38 (under the floor), the clarified question at
     * 0.44 / 0.41, and QU's search query at 0.41 in one run ("… cleared") and 0.37
     * in the next ("… missed"). Both probe slots rank 113, 61, 84, then 38.
     */
    private function tearDownRankings(): callable
    {
        return static fn (string $query): array => [self::FILE => match (true) {
            str_contains($query, 'probe') || str_contains($query, 'sonde') => [113 => 0.71, 61 => 0.66, 84 => 0.62, 38 => 0.50],
            $query === self::QUESTION => [38 => 0.38, 113 => 0.34, 37 => 0.33, 61 => 0.30],
            $query === self::CLARIFIED => [38 => 0.44, 113 => 0.41, 37 => 0.39, 61 => 0.36],
            $query === 'rewrite that cleared' => [38 => 0.41, 113 => 0.36, 37 => 0.34, 61 => 0.31],
            default => [38 => 0.37, 113 => 0.33, 37 => 0.32, 61 => 0.28],
        }];
    }

    /** @test */
    public function the_passage_list_does_not_depend_on_whether_a_rewrite_happened_to_clear_the_floor(): void
    {
        $this->rankings = $this->tearDownRankings();

        $cleared = array_column($this->fanOut('rewrite that cleared'), 'chunk_index');
        $missed = array_column($this->fanOut('rewrite that missed'), 'chunk_index');

        $this->assertSame($cleared, $missed, 'both rewrites rank the same passage first: same list, same order');
        $this->assertSame([38, 113, 61], $cleared, 'the passage every plain phrasing ranks first leads');
    }

    /** @test */
    public function an_angle_under_the_floor_votes_for_the_passage_it_ranks_first(): void
    {
        $this->rankings = $this->tearDownRankings();

        $this->fanOut('rewrite that missed');

        $this->assertSame(['rewrite that missed', self::QUESTION], $this->ranked, 'only the angles that admitted nothing are asked');
        // The vote groups that admitted no passage of the file and still voted.
        $this->assertSame(['search_query', 'question'], $this->trace['votes_below_floor'][self::FILE] ?? null);
    }

    /** @test */
    public function an_angle_that_admits_passages_votes_with_those_passages_only(): void
    {
        // The plain angles admit 38 alone and rank 113 and 61 just under the floor;
        // both probe slots prefer 113, 61, 84. Were every ranked chunk a vote, 113
        // (in all five rankings) would outvote 38 (in three): the fusion would count
        // appearances instead of preferences.
        $this->rankings = static fn (string $query): array => [self::FILE => str_contains($query, 'probe') || str_contains($query, 'sonde')
            ? [113 => 0.71, 61 => 0.66, 84 => 0.62]
            : [38 => 0.44, 113 => 0.39, 61 => 0.38]];

        $indexes = array_column($this->fanOut('rewrite'), 'chunk_index');

        $this->assertSame(38, $indexes[0]);
        $this->assertSame([], $this->ranked, 'every angle admitted the file: no ranking is fetched, the turn costs nothing more');
        $this->assertArrayNotHasKey('votes_below_floor', $this->trace);
    }

    /** @test */
    public function the_users_own_wording_keeps_its_first_ranked_passage_even_from_under_the_floor(): void
    {
        // Budget 3. The user's wording puts 38 first at 0.38 — under the floor, so
        // it is no passage of that angle; the clarified question admits it (0.44).
        // Every other angle prefers 113, 61, 84: consensus alone would cut 38.
        $this->rankings = static fn (string $query): array => [self::FILE => match (true) {
            $query === self::QUESTION => [38 => 0.38, 113 => 0.30],
            $query === self::CLARIFIED => [113 => 0.47, 61 => 0.46, 38 => 0.44],
            default => [113 => 0.71, 61 => 0.66, 84 => 0.62],
        }];

        $indexes = array_column($this->fanOut('rewrite'), 'chunk_index');

        $this->assertSame(113, $indexes[0], 'the consensus passage still leads');
        $this->assertContains(38, $indexes, "the user's own first-ranked passage keeps a slot");
        $this->assertNotContains(84, $indexes, 'it takes the place of the weakest consensus passage');
        $this->assertCount(3, $indexes);
        $this->assertSame([self::FILE => 38], $this->trace['question_passages']);
    }

    /** @test */
    public function the_passage_whose_window_holds_the_users_first_ranked_chunk_keeps_the_slot(): void
    {
        // The user's wording ranks chunk 37 first; no angle admits 37 itself, but the
        // clarified question admits 38, whose snippet window (±2) holds 37.
        $this->rankings = static fn (string $query): array => [self::FILE => match (true) {
            $query === self::QUESTION => [37 => 0.38, 113 => 0.30],
            $query === self::CLARIFIED => [113 => 0.47, 61 => 0.46, 38 => 0.44],
            default => [113 => 0.71, 61 => 0.66, 84 => 0.62],
        }];

        $indexes = array_column($this->fanOut('rewrite'), 'chunk_index');

        $this->assertContains(38, $indexes);
        $this->assertNotContains(84, $indexes);
        $this->assertSame([self::FILE => 38], $this->trace['question_passages']);
    }

    /** @test */
    public function a_vote_from_under_the_floor_admits_no_passage_and_no_file(): void
    {
        // Only the law file clears the floor (on the probes). The other document is
        // ranked by every angle, never over the floor; inside the law file, 37 is
        // the plain angles' first-ranked chunk, never over the floor either.
        $this->rankings = static fn (string $query): array => str_contains($query, 'probe') || str_contains($query, 'sonde')
            ? [self::FILE => [113 => 0.71, 61 => 0.66], 'physical:docs/other.md' => [4 => 0.31]]
            : [self::FILE => [37 => 0.39, 113 => 0.35], 'physical:docs/other.md' => [4 => 0.39]];

        $files = $this->fanOut('rewrite');

        $this->assertSame([self::FILE], array_values(array_unique(array_column($files, 'file_id'))));
        $this->assertEqualsCanonicalizing([113, 61], array_column($files, 'chunk_index'));
        $this->assertArrayNotHasKey('physical:docs/other.md', $this->trace['votes_below_floor'] ?? []);
        $this->assertArrayNotHasKey('question_passages', $this->trace, 'no admitted passage holds the first-ranked chunk');
    }

    /** @test */
    public function votes_from_under_the_floor_can_be_switched_off(): void
    {
        config(['ai.file_context.vote_below_floor' => false]);
        $this->rankings = $this->tearDownRankings();

        $missed = array_column($this->fanOut('rewrite that missed'), 'chunk_index');

        $this->assertSame([], $this->ranked, 'no ranking is asked for');
        $this->assertSame(113, $missed[0], 'floor-bound ballots: only the clarified question votes for 38');
        $this->assertArrayNotHasKey('votes_below_floor', $this->trace);
    }

    /** @test */
    public function votes_from_under_the_floor_are_on_by_default(): void
    {
        $defaults = require __DIR__ . '/../../../config/ai.php';

        $this->assertTrue((bool) $defaults['file_context']['vote_below_floor']);
    }

    /** @test */
    public function a_provider_that_reports_no_rankings_leaves_the_ballots_floor_bound(): void
    {
        $this->rankings = $this->tearDownRankings();

        $missed = array_column($this->fanOut('rewrite that missed', providerRanks: false), 'chunk_index');

        $this->assertSame(113, $missed[0]);
        $this->assertArrayNotHasKey('votes_below_floor', $this->trace);
    }
}
