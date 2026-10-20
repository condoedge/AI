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
use Condoedge\Ai\EmbeddingProviders\MemoizedEmbeddingProvider;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Retrieval\DocumentProbeWriter;
use Condoedge\Ai\Tests\Fixtures\ScriptedUsageLlm;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Search probes ADD breadth; they never outvote the evidence (eval p16).
 *
 * The probe writer returns up to 2 probes per corpus language. Each used to be
 * one equal vote in the cross-angle rank fusion while the clarified question no
 * longer searched: four probes stating ONE hypothesis were four of six votes and
 * evicted the passage the user's own wording found.
 *
 * The fusion is now by vote GROUP:
 *  - the search query, the user's question and the clarified question are one
 *    vote each, and the clarified question searches again;
 *  - the language renderings of ONE probe slot are one vote together;
 *  - a probe slot that nearly duplicates an earlier slot, and a probe rendering
 *    that nearly duplicates the question, is not searched at all (similarity of
 *    the angle embeddings — no vocabulary);
 *  - the question angle's own best passage of a file always keeps a slot;
 *  - when the reference budget cuts a file's passages, the file keeps its
 *    consensus passages, not the ones a single phrasing scored highest.
 */
class AiManagerProbeFusionTest extends TestCase
{
    private const FILE = 'physical:laws/code.md';

    private const QUESTION = 'Can I just swap out this broken part myself?';

    /** @var list<string> */
    private array $searched = [];

    /** @var callable(string): array<string, array<int, float>> One angle's file id => chunk_index => score. */
    private $rankings;

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
            'ai.file_context.probe_duplicate_similarity' => 0.8,
        ]);
        $this->searched = [];
        $this->trace = [];
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Drive the real fan-out. Each angle "searches" through $this->rankings and
     * emits, like the real provider, its best DISTINCT passages of the file (up to
     * the per-file budget), every reference carrying the angle's full ranking.
     *
     * @param array<string, string>|null       $probeReply  The probe writer's reply (null = no writer).
     * @param array<string, list<float>>|null  $vectors     text => embedding (null = a provider without the memo).
     */
    private function fanOut(QueryUnderstandingResult $qu, ?array $probeReply = null, ?array $vectors = null): array
    {
        $provider = Mockery::mock(FileContextProvider::class);
        $provider->shouldReceive('getFileContext')->andReturnUsing(function ($query, $user, $options = []) {
            $this->searched[] = $query;
            $files = [];
            foreach (($this->rankings)($query) as $fileId => $scores) {
                arsort($scores);
                $emitted = 0;
                foreach ($scores as $index => $score) {
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
                        'chunk_scores' => $scores,
                    ];
                }
            }
            usort($files, static fn (array $a, array $b) => $b['relevance'] <=> $a['relevance']);

            return ['relevant_files' => $files, 'file_count' => count($files)];
        });

        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        if ($vectors !== null) {
            $inner = Mockery::mock(EmbeddingProviderInterface::class);
            $inner->shouldReceive('embedBatch')->andReturnUsing(
                fn (array $texts): array => array_map(fn (string $t): array => $this->vectorFor($t, $vectors), $texts)
            );
            $inner->shouldReceive('embed')->andReturnUsing(fn (string $t): array => $this->vectorFor($t, $vectors));
            $embedding = new MemoizedEmbeddingProvider($inner);
        }

        $writer = $probeReply === null ? null : new DocumentProbeWriter(new ScriptedUsageLlm(json: $probeReply), [
            'corpus_languages' => ['fr', 'en'],
            'corpus_register' => 'aviation maintenance regulation terminology (airworthiness directive)',
        ]);

        $manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            Mockery::mock(ContextRetrieverInterface::class),
            $embedding,
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
        $ctx->qu = $qu;
        $trace = [];
        $method = new \ReflectionMethod($manager, 'fanOutFileContext');
        $method->setAccessible(true);
        $ctx = $method->invokeArgs($manager, [$ctx, self::QUESTION, ['user' => (object) ['id' => 13]], &$trace]);
        $this->trace = $trace;

        return $ctx->fileContext['relevant_files'] ?? [];
    }

    /** A text's scripted embedding; an unscripted text is orthogonal to every other text. */
    private function vectorFor(string $text, array $vectors): array
    {
        if (isset($vectors[$text])) {
            return array_pad($vectors[$text], 64, 0.0);
        }
        $vector = array_fill(0, 64, 0.0);
        $vector[8 + (crc32($text) % 56)] = 1.0;

        return $vector;
    }

    private function qu(string $clarified = '', array $tags = []): QueryUnderstandingResult
    {
        return new QueryUnderstandingResult(
            searchQuery: 'swap broken part',
            questionType: 'knowledge_query',
            clarifiedQuestion: $clarified,
            searchTags: $tags,
            language: 'en',
        );
    }

    /** @test */
    public function the_language_renderings_of_one_probe_slot_cast_one_vote_together(): void
    {
        // The two evidence angles put passage 10 first; the ONE probe (written in
        // two languages) puts passage 40 first, with the higher cosine a probe in
        // the documents' own wording always gets. Counted as two votes the probe
        // ties the evidence and wins on cosine; counted as one it cannot.
        $this->rankings = fn (string $query): array => [self::FILE => in_array($query, ['sonde un', 'probe one'], true)
            ? [40 => 0.72, 10 => 0.66]
            : [10 => 0.52, 40 => 0.48]];

        $files = $this->fanOut($this->qu(), ['fr_1' => 'sonde un', 'en_1' => 'probe one']);

        $this->assertSame(['swap broken part', self::QUESTION, 'sonde un', 'probe one'], $this->searched);
        $this->assertSame(10, $files[0]['chunk_index'], 'the passage both evidence angles rank first leads');
        $this->assertSame([10, 40], array_column($files, 'chunk_index'));
    }

    /** @test */
    public function four_probes_stating_one_hypothesis_cannot_evict_the_evidence_passage(): void
    {
        // Eval p16 tear-down, as measured: the user's wording clears the relevance
        // floor on no passage, the search query finds 38 alone, the clarified
        // question 61 then 38; all four probes rank 113, 61, 84. Budget 3.
        $this->rankings = fn (string $query): array => match (true) {
            str_contains($query, 'probe') || str_contains($query, 'sonde') => [self::FILE => [113 => 0.71, 61 => 0.66, 84 => 0.62]],
            $query === self::QUESTION => [],
            $query === 'swap broken part' => [self::FILE => [38 => 0.44]],
            default => [self::FILE => [61 => 0.45, 38 => 0.43]],
        };

        $files = $this->fanOut(
            $this->qu('What are the rules for replacing a part yourself?'),
            ['fr_1' => 'sonde un', 'en_1' => 'probe one', 'fr_2' => 'sonde deux', 'en_2' => 'probe two']
        );

        $this->assertContains('What are the rules for replacing a part yourself?', $this->searched, 'the clarified question searches again');
        $this->assertContains(38, array_column($files, 'chunk_index'), 'the passage the plain angles agree on is kept');
        $this->assertNotContains(84, array_column($files, 'chunk_index'));
    }

    /** @test */
    public function a_probe_slot_that_nearly_duplicates_an_earlier_slot_is_not_searched(): void
    {
        $this->rankings = fn (): array => [self::FILE => [10 => 0.6]];

        $this->fanOut(
            $this->qu(),
            ['fr_1' => 'sonde un', 'en_1' => 'probe one', 'fr_2' => 'sonde deux', 'en_2' => 'probe two'],
            [
                'sonde un' => [1.0, 0.0, 0.0, 0.0],
                'probe one' => [0.0, 1.0, 0.0, 0.0],
                // The French rendering of slot 2 restates slot 1 (cosine 0.95).
                'sonde deux' => [0.95, 0.0, 0.3122, 0.0],
                'probe two' => [0.0, 0.0, 0.0, 1.0],
            ]
        );

        $this->assertSame(['swap broken part', self::QUESTION, 'sonde un', 'probe one'], $this->searched);
        $this->assertSame('probe', $this->trace['probes_dropped'][0]['duplicate_of'] ?? null);
        $this->assertEqualsCanonicalizing(
            ['sonde deux', 'probe two'],
            array_column($this->trace['probes_dropped'], 'angle'),
            'the whole slot goes: its renderings are one line of attack'
        );
    }

    /** @test */
    public function distinct_probe_slots_all_search(): void
    {
        $this->rankings = fn (): array => [self::FILE => [10 => 0.6]];

        $this->fanOut(
            $this->qu(),
            ['fr_1' => 'sonde un', 'en_1' => 'probe one', 'fr_2' => 'sonde deux', 'en_2' => 'probe two'],
            [
                'sonde un' => [1.0, 0.0, 0.0, 0.0],
                'probe one' => [0.8, 0.6, 0.0, 0.0],
                // 0.7 to slot 1: related, not a restatement.
                'sonde deux' => [0.7, 0.0, 0.7141, 0.0],
                'probe two' => [0.0, 0.0, 0.0, 1.0],
            ]
        );

        $this->assertSame(
            ['swap broken part', self::QUESTION, 'sonde un', 'probe one', 'sonde deux', 'probe two'],
            $this->searched
        );
        $this->assertArrayNotHasKey('probes_dropped', $this->trace);
    }

    /** @test */
    public function a_probe_rendering_that_nearly_duplicates_the_question_is_not_searched(): void
    {
        $this->rankings = fn (): array => [self::FILE => [10 => 0.6]];

        $this->fanOut(
            $this->qu('What are the rules for replacing a part yourself?'),
            ['fr_1' => 'sonde un', 'en_1' => 'probe one', 'fr_2' => 'sonde deux', 'en_2' => 'probe two'],
            [
                self::QUESTION => [1.0, 0.0, 0.0, 0.0, 0.0, 0.0],
                'What are the rules for replacing a part yourself?' => [0.0, 1.0, 0.0, 0.0, 0.0, 0.0],
                // Copies the question, in the question's language.
                'probe one' => [0.9, 0.0, 0.4359, 0.0, 0.0, 0.0],
                // Its other-language rendering is still a different search.
                'sonde un' => [0.5, 0.0, 0.0, 0.866, 0.0, 0.0],
                // Copies the clarified question.
                'probe two' => [0.0, 0.85, 0.0, 0.0, 0.5268, 0.0],
                'sonde deux' => [0.0, 0.0, 0.0, 0.0, 0.0, 1.0],
            ]
        );

        $this->assertSame(
            ['swap broken part', self::QUESTION, 'What are the rules for replacing a part yourself?', 'sonde un', 'sonde deux'],
            $this->searched
        );
        $this->assertEqualsCanonicalizing(['probe one', 'probe two'], array_column($this->trace['probes_dropped'], 'angle'));
        $this->assertSame(['question'], array_values(array_unique(array_column($this->trace['probes_dropped'], 'duplicate_of'))));
    }

    /** @test */
    public function without_angle_embeddings_every_probe_searches(): void
    {
        // A provider without the request memo is not batch-embedded: there is no
        // similarity to judge by, so nothing is dropped.
        $this->rankings = fn (): array => [self::FILE => [10 => 0.6]];

        $this->fanOut($this->qu(), ['fr_1' => 'sonde un', 'en_1' => 'probe one', 'fr_2' => 'sonde un bis', 'en_2' => 'probe one bis']);

        $this->assertCount(6, $this->searched);
    }

    /** @test */
    public function duplicate_detection_can_be_switched_off(): void
    {
        config(['ai.file_context.probe_duplicate_similarity' => 0]);
        $this->rankings = fn (): array => [self::FILE => [10 => 0.6]];

        $this->fanOut(
            $this->qu(),
            ['fr_1' => 'sonde un', 'en_1' => 'probe one', 'fr_2' => 'sonde deux', 'en_2' => 'probe two'],
            ['sonde un' => [1.0, 0.0], 'sonde deux' => [1.0, 0.0]]
        );

        $this->assertCount(6, $this->searched);
    }

    /** @test */
    public function the_question_angles_best_passage_of_a_file_always_keeps_a_slot(): void
    {
        // Search query, clarified question and both probe slots agree on 113, 61,
        // 84; only the user's own wording finds 38. Budget 3: consensus alone would
        // keep 113, 61, 84.
        $this->rankings = fn (string $query): array => [self::FILE => $query === self::QUESTION
            ? [38 => 0.47, 113 => 0.41]
            : [113 => 0.71, 61 => 0.66, 84 => 0.62]];

        $files = $this->fanOut(
            $this->qu('What are the rules for replacing a part yourself?'),
            ['fr_1' => 'sonde un', 'en_1' => 'probe one', 'fr_2' => 'sonde deux', 'en_2' => 'probe two']
        );

        $indexes = array_column($files, 'chunk_index');
        $this->assertSame(113, $indexes[0], 'the consensus passage still leads');
        $this->assertContains(38, $indexes, "the user's own wording keeps its best passage");
        $this->assertCount(3, $indexes, 'within the per-file budget');
        $this->assertNotContains(84, $indexes, 'it takes the place of the weakest consensus passage');
    }

    /** @test */
    public function the_question_angles_best_passage_survives_the_overall_reference_budget(): void
    {
        // Budget 3 and another document takes a slot: the law file has two. Its
        // consensus order is 113, 61, then the question's own 38 — which keeps the
        // file's second slot.
        config(['ai.file_context.max_references' => 3]);
        $this->rankings = fn (string $query): array => $query === self::QUESTION
            ? [self::FILE => [38 => 0.47, 113 => 0.41], 'physical:docs/a.md' => [1 => 0.60]]
            : [self::FILE => [113 => 0.71, 61 => 0.66, 84 => 0.62]];

        $files = $this->fanOut(
            $this->qu('What are the rules for replacing a part yourself?'),
            ['fr_1' => 'sonde un', 'en_1' => 'probe one', 'fr_2' => 'sonde deux', 'en_2' => 'probe two']
        );

        $this->assertSame(
            [[self::FILE, 113], ['physical:docs/a.md', 1], [self::FILE, 38]],
            array_map(static fn (array $f): array => [$f['file_id'], $f['chunk_index']], $files)
        );
        $this->assertArrayNotHasKey('question_passage', $files[2], 'the internal mark does not reach the prompt context');
        $this->assertEquals([self::FILE => 38, 'physical:docs/a.md' => 1], $this->trace['question_passages']);
    }

    /** @test */
    public function the_question_angles_best_passage_is_not_repeated_when_a_kept_window_already_holds_it(): void
    {
        // 112 sits inside the snippet window of 113 (context_window 2).
        $this->rankings = fn (string $query): array => [self::FILE => $query === self::QUESTION
            ? [112 => 0.47]
            : [113 => 0.71, 61 => 0.66, 84 => 0.62]];

        $files = $this->fanOut($this->qu('What are the rules for replacing a part yourself?'));

        $this->assertSame([113, 61, 84], array_column($files, 'chunk_index'));
    }

    /** @test */
    public function a_file_cut_by_the_reference_budget_keeps_its_consensus_passages(): void
    {
        // Budget 4, and two other documents take a slot each: the law file gets
        // two. By cosine alone they would go to 113 and 84 (the probe's picks);
        // the file's consensus order is 113, 38, 84.
        config(['ai.file_context.max_references' => 4]);
        $this->rankings = function (string $query): array {
            if (in_array($query, ['sonde un', 'probe one'], true)) {
                return [self::FILE => [113 => 0.71, 84 => 0.69, 38 => 0.50]];
            }

            return [
                self::FILE => [113 => 0.45, 38 => 0.44],
                'physical:docs/a.md' => [1 => 0.60],
                'physical:docs/b.md' => [1 => 0.58],
            ];
        };

        $files = $this->fanOut(
            $this->qu('What are the rules for replacing a part yourself?'),
            ['fr_1' => 'sonde un', 'en_1' => 'probe one']
        );

        $law = array_values(array_filter($files, static fn (array $f) => $f['file_id'] === self::FILE));
        $this->assertCount(4, $files);
        $this->assertEqualsCanonicalizing([113, 38], array_column($law, 'chunk_index'));
    }

    /** @test */
    public function a_files_passages_are_listed_in_its_consensus_order_not_by_the_cosine_of_the_angle_that_found_them(): void
    {
        // The three plain angles agree on 38 then 61; the probe scores its own pick
        // (113) far higher than plain words score anything. A cosine is relative to
        // the angle that produced it, so by cosine the list read 38, 113, 61 and the
        // passage every angle agrees on came after the probe's.
        $this->rankings = fn (string $query): array => [self::FILE => in_array($query, ['sonde un', 'probe one'], true)
            ? [113 => 0.71, 61 => 0.66, 38 => 0.50]
            : [38 => 0.45, 61 => 0.44]];

        $files = $this->fanOut(
            $this->qu('What are the rules for replacing a part yourself?'),
            ['fr_1' => 'sonde un', 'en_1' => 'probe one']
        );

        $this->assertSame([38, 61, 113], array_column($files, 'chunk_index'));
        $this->assertEqualsWithDelta(0.71, $files[0]['relevance'], 1e-9, 'the file still ranks by its strongest angle');
    }

    /** @test */
    public function across_files_the_list_still_follows_relevance(): void
    {
        // By relevance the list reads law 0.71, law 0.71, other document 0.60, law
        // 0.52. The other document keeps the third place; the law's three places are
        // taken by its passages in consensus order (38, 61, 113).
        $this->rankings = function (string $query): array {
            if (in_array($query, ['sonde un', 'probe one'], true)) {
                return [self::FILE => [113 => 0.71, 61 => 0.52, 38 => 0.50]];
            }

            return [self::FILE => [38 => 0.45, 61 => 0.44], 'physical:docs/a.md' => [1 => 0.60]];
        };

        $files = $this->fanOut(
            $this->qu('What are the rules for replacing a part yourself?'),
            ['fr_1' => 'sonde un', 'en_1' => 'probe one']
        );

        $this->assertSame(
            [[self::FILE, 38], [self::FILE, 61], ['physical:docs/a.md', 1], [self::FILE, 113]],
            array_map(static fn (array $f): array => [$f['file_id'], $f['chunk_index']], $files)
        );
    }

    /** @test */
    public function the_trace_names_each_angles_vote_group(): void
    {
        $this->rankings = fn (): array => [self::FILE => [10 => 0.6, 20 => 0.5]];

        $this->fanOut(
            $this->qu('What are the rules for replacing a part yourself?'),
            ['fr_1' => 'sonde un', 'en_1' => 'probe one', 'fr_2' => 'sonde deux', 'en_2' => 'probe two']
        );

        $this->assertSame(
            ['search_query', 'question', 'clarified_question', 'probe_1', 'probe_1', 'probe_2', 'probe_2'],
            $this->trace['angle_groups']
        );
        $this->assertCount(7, $this->trace['angles']);
        // Which passages each angle preferred, so a retrieval regression can be traced.
        $this->assertSame([10, 20], $this->trace['angle_chunks'][0][self::FILE]);
    }

    /** @test */
    public function the_tags_angle_searches_only_when_no_probe_does(): void
    {
        $this->rankings = fn (): array => [self::FILE => [10 => 0.6]];

        $this->fanOut($this->qu('clarified form', ['part', 'replacement']), ['fr_1' => 'sonde un', 'en_1' => 'probe one']);
        $this->assertNotContains('part replacement', $this->searched);

        $this->searched = [];
        $this->fanOut($this->qu('clarified form', ['part', 'replacement']));
        $this->assertSame(['swap broken part', self::QUESTION, 'clarified form', 'part replacement'], $this->searched);
    }

    /** @test */
    public function the_default_per_file_passage_budget_is_five_within_the_overall_reference_budget(): void
    {
        $defaults = require __DIR__ . '/../../../config/ai.php';

        $this->assertSame(5, (int) $defaults['file_context']['max_chunks_per_file']);
        $this->assertSame(5, (int) $defaults['file_context']['max_references'], 'the total sent stays bounded');
        $this->assertEqualsWithDelta(0.8, (float) $defaults['file_context']['probe_duplicate_similarity'], 1e-9);
    }
}
