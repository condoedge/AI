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
use Condoedge\Ai\Services\Retrieval\PassageSelector;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * PASSAGE SELECTION in the fan-out (eval p16–p21 tear-down): what the search
 * selected stays; one small call may name candidates the search ranked too low,
 * and the code adds those it can verify — joined to the selected passage they
 * overlap, or as a passage after the selected ones.
 *
 * The scripted turn is the measured one: the plain phrasings admit 38 and rank the
 * general-rule chunk 58 at 0.30 (never over the floor); the search selects 38, 113
 * and 61, whose window is 59–63.
 */
class AiManagerPassageSelectionTest extends TestCase
{
    private const FILE = 'physical:laws/code.md';

    private const QUESTION = 'Can I just swap out this broken part myself?';

    /** @var array<int, float> What every angle scores (the file's chunks). */
    private array $scores = [38 => 0.44, 113 => 0.43, 61 => 0.42, 58 => 0.30, 110 => 0.29, 90 => 0.27, 12 => 0.26, 20 => 0.25];

    /** @var array<string, mixed>|null The selector's reply (null = the call fails). */
    private ?array $reply = ['needed' => []];

    /** @var list<array> The messages of every selector call. */
    private array $prompts = [];

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
            'ai.file_context.max_chunks_per_file' => 5,
            'ai.file_context.context_window' => 2,
            'ai.file_context.recall_floor' => 0,
            'ai.file_context.passage_selection' => ['enabled' => true, 'pool_size' => 20, 'max_added' => 2],
        ]);
        $this->prompts = [];
        $this->trace = [];
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Chunks under 30 sit in another part of the document than the rest. */
    private function headingPath(int $index): array
    {
        return $index < 30 ? ['CODE', 'PART ONE', 'Rights'] : ['CODE', 'PART TWO', $index < 100 ? 'Rights' : 'Meetings'];
    }

    private function fanOut(array $quOverrides = [], array $conversation = [], bool $withSelector = true): array
    {
        $window = 2;
        $passage = function (int $index, float $score) use ($window): array {
            $chunks = [];
            foreach (range($index - $window, $index + $window) as $n) {
                $chunks[$n] = "chunk {$n}";
            }

            return [
                'file_id' => self::FILE,
                'filename' => 'code.md',
                'snippet' => implode(FileContextProvider::SNIPPET_SEPARATOR, $chunks),
                'snippet_chunks' => $chunks,
                'relevance' => $score,
                'chunk_index' => $index,
                'source' => 'physical',
                'is_preamble' => false,
                'heading' => implode(' › ', $this->headingPath($index)),
                'heading_path' => $this->headingPath($index),
            ];
        };

        $provider = Mockery::mock(FileContextProvider::class);
        $provider->shouldReceive('getFileContext')->andReturnUsing(function ($query, $user, $options = []) use ($passage) {
            $confident = array_filter($this->scores, static fn (float $s): bool => $s >= 0.4);
            $files = [];
            foreach ($confident as $index => $score) {
                $files[] = $passage($index, $score) + ['chunk_scores' => $confident];
            }

            return ['relevant_files' => $files, 'file_count' => count($files)];
        });
        $provider->shouldReceive('chunkRankings')->andReturn([self::FILE => $this->scores]);
        $provider->shouldReceive('rankedChunks')->andReturnUsing(function () {
            $chunks = [];
            foreach ($this->scores as $index => $score) {
                $chunks[$index] = [
                    'score' => $score,
                    'content' => "**{$index}.** text of chunk {$index}",
                    'heading' => implode(' › ', $this->headingPath($index)),
                    'heading_path' => $this->headingPath($index),
                ];
            }

            return [self::FILE => ['result' => ['file_id' => self::FILE], 'chunks' => $chunks]];
        });
        $provider->shouldReceive('passageAt')->andReturnUsing(
            fn (array $result, int $index) => isset($this->scores[$index]) ? $passage($index, $this->scores[$index]) : null
        );

        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(function (array $messages) {
            $this->prompts[] = $messages;
            if ($this->reply === null) {
                throw new \RuntimeException('provider down');
            }

            return $this->reply;
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
            $provider,
            passageSelector: $withSelector ? new PassageSelector($llm) : null,
        );

        $ctx = new PipelineContext();
        $ctx->question = self::QUESTION;
        $ctx->qu = new QueryUnderstandingResult(...($quOverrides + [
            'searchQuery' => 'swap broken part',
            'questionType' => 'knowledge_query',
            'clarifiedQuestion' => 'What are the rules for replacing a part yourself?',
            'language' => 'en',
        ]));
        $ctx->conversationContext = $conversation;
        $trace = [];
        $method = new \ReflectionMethod($manager, 'fanOutFileContext');
        $method->setAccessible(true);
        $ctx = $method->invokeArgs($manager, [$ctx, self::QUESTION, ['user' => (object) ['id' => 13]], &$trace]);
        $this->trace = $trace;

        return $ctx->fileContext['relevant_files'] ?? [];
    }

    /** The id the selector shows a chunk under, read from its prompt. */
    private function idOf(int $chunk): string
    {
        $this->assertSame(1, preg_match('/\[([A-Z]{2})\][^\n]*\n\*\*' . $chunk . '\.\*\*/', $this->prompts[0][1]['content'], $m), "chunk {$chunk} is not a candidate");

        return $m[1];
    }

    /** Run once to learn the ids, then again with the model naming these chunks. */
    private function fanOutPicking(array $chunks, array $quOverrides = [], array $conversation = []): array
    {
        $this->reply = ['needed' => []];
        $this->fanOut($quOverrides, $conversation);
        $this->reply = ['needed' => array_map(fn (int $c): string => $this->idOf($c), $chunks)];
        $this->prompts = [];

        return $this->fanOut($quOverrides, $conversation);
    }

    /** @test */
    public function a_candidate_next_to_a_selected_passage_is_joined_to_it(): void
    {
        $files = $this->fanOutPicking([58]);

        $this->assertSame([38, 113, 61], array_column($files, 'chunk_index'), 'the selected passages stay, in their order');
        $this->assertSame(
            implode(FileContextProvider::SNIPPET_SEPARATOR, array_map(static fn (int $n): string => "chunk {$n}", range(58, 63))),
            $files[2]['snippet'],
            'the named chunk alone in front of chunks 59–63, in reading order'
        );
        $this->assertSame([['file' => self::FILE, 'chunk_index' => 58, 'as' => 'joined', 'into' => 61]], $this->trace['passage_selection']['added']);
        $this->assertArrayNotHasKey('snippet_chunks', $files[2], 'structure keys do not reach the prompt');
        $this->assertArrayNotHasKey('heading_path', $files[0]);
    }

    /** @test */
    public function a_candidate_away_from_every_selected_passage_becomes_a_passage_after_them(): void
    {
        $files = $this->fanOutPicking([90]);

        $this->assertSame([38, 113, 61, 90], array_column($files, 'chunk_index'));
        $this->assertEqualsWithDelta(0.27, $files[3]['relevance'], 1e-9, 'its own similarity');
        $this->assertSame('chunk 90', $files[3]['snippet'], 'the chunk the model named, without neighbours nobody judged');
        $this->assertFalse($files[3]['question_supported']);
        $this->assertSame('passage', $this->trace['passage_selection']['added'][0]['as']);
    }

    /** @test */
    public function the_pool_holds_only_what_the_selected_passages_do_not(): void
    {
        $this->fanOut();

        $candidates = array_column($this->trace['passage_selection']['candidates'], 'chunk_index');
        $this->assertSame([58, 90, 110], $candidates, 'reading order; 38, 113, 61 are selected, and their windows hold nothing else ranked');
        $this->assertStringContainsString('Selected:', $this->prompts[0][1]['content']);
        $this->assertSame(3, $this->trace['passage_selection']['pool']);
    }

    /** @test */
    public function a_candidate_outside_the_part_of_the_document_the_search_selected_from_is_not_offered(): void
    {
        // 12 and 20 sit under PART ONE; every selected passage is under PART TWO.
        $this->fanOut();

        $candidates = array_column($this->trace['passage_selection']['candidates'], 'chunk_index');
        $this->assertNotContains(12, $candidates);
        $this->assertNotContains(20, $candidates);
    }

    /** @test */
    public function nothing_reported_missing_leaves_the_selection_as_it_is(): void
    {
        $this->reply = ['needed' => []];
        $files = $this->fanOut();

        $this->assertSame([38, 113, 61], array_column($files, 'chunk_index'));
        $this->assertSame([], $this->trace['passage_selection']['added']);
    }

    /** @test */
    public function a_failed_call_leaves_the_selection_as_it_is(): void
    {
        $this->reply = null;
        $files = $this->fanOut();

        $this->assertSame([38, 113, 61], array_column($files, 'chunk_index'));
        $this->assertSame('no_report', $this->trace['passage_selection']['fallback']);
        $this->assertArrayNotHasKey('snippet_chunks', $files[0]);
    }

    /** @test */
    public function an_id_outside_the_pool_adds_nothing(): void
    {
        $this->reply = ['needed' => ['1063', 'ZZ']];
        $files = $this->fanOut();

        $this->assertSame([38, 113, 61], array_column($files, 'chunk_index'));
        $this->assertSame(2, $this->trace['passage_selection']['unknown_ids']);
    }

    /** @test */
    public function at_most_max_added_candidates_are_added(): void
    {
        config(['ai.file_context.passage_selection.max_added' => 1]);
        // A pool of three is offered; the model names two.
        $files = $this->fanOutPicking([90, 110]);

        $this->assertSame([38, 113, 61, 90], array_column($files, 'chunk_index'));
        $this->assertCount(1, $this->trace['passage_selection']['added']);
    }

    /** @test */
    public function no_call_when_the_pool_holds_no_more_candidates_than_may_be_added(): void
    {
        $this->scores = [38 => 0.44, 113 => 0.43, 61 => 0.42, 58 => 0.30, 90 => 0.27];
        $files = $this->fanOut();

        $this->assertSame([], $this->prompts);
        $this->assertSame('small_pool', $this->trace['passage_selection']['skipped']);
        $this->assertSame([38, 113, 61], array_column($files, 'chunk_index'));
    }

    /** @test */
    public function no_call_on_an_ask_the_application_answers(): void
    {
        $files = $this->fanOut(['navigationTarget' => 'own_team']);

        $this->assertSame([], $this->prompts);
        $this->assertArrayNotHasKey('passage_selection', $this->trace);
        $this->assertSame([38, 113, 61], array_column($files, 'chunk_index'));
    }

    /** @test */
    public function no_call_when_switched_off_or_not_wired(): void
    {
        config(['ai.file_context.passage_selection.enabled' => false]);
        $this->fanOut();
        $this->assertSame([], $this->prompts);
        $this->assertArrayNotHasKey('passage_selection', $this->trace);

        config(['ai.file_context.passage_selection.enabled' => true]);
        $files = $this->fanOut(withSelector: false);
        $this->assertSame([], $this->prompts);
        $this->assertSame([38, 113, 61], array_column($files, 'chunk_index'));
    }

    /** @test */
    public function on_a_follow_up_a_passage_the_previous_answer_cited_is_not_offered(): void
    {
        $conversation = ['last_referenced_files' => [['file_id' => self::FILE, 'chunk_index' => 90]]];

        $this->fanOut(['followUpReference' => true], $conversation);
        $this->assertNotContains(90, array_column($this->trace['passage_selection']['candidates'] ?? [], 'chunk_index'));
        $this->assertSame('small_pool', $this->trace['passage_selection']['skipped'] ?? null, 'two candidates left: nothing to choose between');

        // The same conversation memory on a turn that is not a follow-up changes nothing.
        $this->fanOut([], $conversation);
        $this->assertContains(90, array_column($this->trace['passage_selection']['candidates'], 'chunk_index'));
    }

    /** @test */
    public function the_default_pool_excerpt_and_addition_budget(): void
    {
        $defaults = require __DIR__ . '/../../../config/ai.php';

        $this->assertTrue((bool) $defaults['file_context']['passage_selection']['enabled'], 'on by default; AI_FILE_PASSAGE_SELECTION=false turns it off');
        $this->assertSame(20, (int) $defaults['file_context']['passage_selection']['pool_size']);
        $this->assertSame(350, (int) $defaults['file_context']['passage_selection']['excerpt_chars']);
        $this->assertSame(1, (int) $defaults['file_context']['passage_selection']['max_added'], 'the most necessary candidate only: asked for two, the model named two on every turn');
    }
}
