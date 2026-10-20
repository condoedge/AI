<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\ContextRetrieverInterface;
use Condoedge\Ai\Contracts\DataIngestionServiceInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Contracts\QueryGeneratorInterface;
use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Context\FilenameExtractor;
use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\Services\ResponseSections\FileContextSection;
use Condoedge\Ai\Services\ResponseSections\QuestionAnalysisSection;
use Condoedge\Ai\Services\Retrieval\DocumentMapReader;
use Condoedge\Ai\Services\Retrieval\DocumentMapTurn;
use Condoedge\Ai\Services\Retrieval\DocumentProbeWriter;
use Condoedge\Ai\Services\Retrieval\PassageSelector;
use Condoedge\Ai\Support\DocumentMap;
use Condoedge\Ai\Tests\Fixtures\CallbackUsageLlm;
use Condoedge\Ai\Tests\Fixtures\InMemoryMapChunkStore;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * The document map IN THE TURN (AiManager): which turns the reader reads, what a
 * closed and an open gate hand to the composer, and what happens whenever the
 * map does not apply — today's path (probes + passage selection), entered ONCE,
 * without searching again what the first pass already found.
 *
 * The document: a preamble (chunk 0) and articles 1001–1012 in chunks 1–12, four
 * to a section, all under one part; chunk n's gist is "Gist of chunk n.". The
 * question's plain angles admit chunks 6, 5 and 11 (≥ 0.40); chunk 2 — the
 * general rule — scores 0.28 for every phrasing.
 */
class AiManagerDocumentMapTest extends TestCase
{
    private const FILE = 'physical:laws/code.md';

    private const QUESTION = 'Can I remove this wall?';

    /** @var array<int, float> How every phrasing ranks the chunks of the file. */
    private array $scores = [6 => 0.62, 5 => 0.55, 11 => 0.45, 2 => 0.28, 9 => 0.2, 1 => 0.19, 3 => 0.18, 4 => 0.17, 8 => 0.16];

    /** @var list<array{0: int, 1: string}>|null What the reader names (null: its call fails). */
    private ?array $needs = [];

    /** @var list<string> One line per search the store was asked for: "<query>|<floor>|<articles>". */
    private array $searches = [];

    private InMemoryMapChunkStore $store;

    private CallbackUsageLlm $readerLlm;

    private CallbackUsageLlm $probeLlm;

    private CallbackUsageLlm $selectorLlm;

    /** @var array<string, mixed> */
    private array $trace = [];

    /** Whether any stored chunk carries a gist (what the vector store's count says). */
    private bool $anyMapStored = true;

    /** @var list<array> The filters of the "any map stored?" counts. */
    private array $countFilters = [];

    public function setUp(): void
    {
        parent::setUp();

        config([
            'logging.default' => 'null',
            'ai.security.enabled' => false,
            'ai.file_context.enabled' => true,
            'ai.file_context.security_enabled' => false,
            'ai.file_context.min_relevance_score' => 0.4,
            'ai.file_context.max_references' => 5,
            'ai.file_context.max_chunks_per_file' => 5,
            'ai.file_context.context_window' => 0,
            'ai.file_context.snippet_chunks' => 1,
            'ai.file_context.recall_floor' => 0,
            'ai.file_context.branch_coherence_share' => 0,
            'ai.file_context.passage_selection' => ['enabled' => true, 'pool_size' => 20, 'max_added' => 1],
            'ai.file_context.document_map' => ['enabled' => true, 'reader' => ['enabled' => true, 'max_chunks' => 150]],
        ]);

        $this->searches = [];
        $this->store = new class extends InMemoryMapChunkStore {
            public int $reads = 0;

            public function getFileChunks(int|string $fileId, ?string $collection = null): array
            {
                $this->reads++;

                return parent::getFileChunks($fileId, $collection);
            }
        };

        $this->readerLlm = new CallbackUsageLlm(function (array $messages): array {
            if ($this->needs === null) {
                throw new \RuntimeException('429');
            }
            preg_match_all('/^\[([A-Z]{2})\] Gist of chunk (\d+)\.$/m', $messages[1]['content'], $m, PREG_SET_ORDER);
            $ids = [];
            foreach ($m as $line) {
                $ids[(int) $line[2]] = $line[1];
            }
            $reported = [];
            foreach ($this->needs as [$chunk, $role]) {
                if (isset($ids[$chunk])) {
                    $reported[] = ['id' => $ids[$chunk], 'role' => $role];
                }
            }

            return ['issue' => 'Whether a member may alter a shared part.', 'answerable' => 'yes', 'needs' => $reported];
        });
        $this->probeLlm = new CallbackUsageLlm(static fn (): array => ['en_1' => 'alteration of a common portion', 'en_2' => 'works requiring a vote of the assembly']);
        $this->selectorLlm = new CallbackUsageLlm(static fn (): array => ['needed' => []]);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- fixtures

    /** @return list<FileChunk> */
    private function chunks(bool $mapped = true): array
    {
        $chunks = [];
        for ($index = 0; $index <= 12; $index++) {
            $content = $index === 0 ? '# THE LAW' : sprintf('**10%02d.** Rule %d of the law.', $index, $index);
            $metadata = [
                'heading_path' => $index === 0 ? ['THE LAW'] : ['THE LAW', 'Section ' . (int) ceil($index / 4)],
                'is_preamble' => $index === 0,
            ];
            if ($index > 0) {
                $metadata['article_no'] = sprintf('10%02d', $index);
                $metadata['articles'] = [sprintf('10%02d', $index)];
                if ($mapped) {
                    $metadata['map'] = ['v' => DocumentMap::VERSION, 'hash' => DocumentMap::hash($content), 'gist' => "Gist of chunk {$index}."];
                }
            }
            $chunks[] = new FileChunk(self::FILE, 'code.md', $content, [], $index, 13, 0, mb_strlen($content), $metadata);
        }

        return $chunks;
    }

    /** A real provider over a scripted search: every phrasing ranks the chunks alike. */
    private function provider(array $chunks): FileContextProvider
    {
        $this->store->put(self::FILE, $chunks, 'documentation_chunks');
        $byIndex = [];
        foreach ($chunks as $chunk) {
            $byIndex[$chunk->chunkIndex] = $chunk;
        }

        $search = Mockery::mock(FileSearchService::class);
        $search->shouldReceive('searchByContent')->andReturnUsing(function (string $query, array $options = []) use ($byIndex): array {
            $articles = array_map('strval', (array) ($options['article_numbers'] ?? []));
            $floor = (float) ($options['min_score'] ?? 0.0);
            $this->searches[] = $query . '|' . $floor . '|' . implode(',', $articles);

            $scores = $this->scores;
            if ($articles !== []) {
                // The exact-article angle: the chunks defining those articles, whatever they score.
                $scores = [];
                foreach ($byIndex as $index => $chunk) {
                    if (array_intersect($articles, (array) ($chunk->metadata['articles'] ?? [])) !== []) {
                        $scores[$index] = $this->scores[$index] ?? 0.1;
                    }
                }
            }
            $scores = array_filter($scores, static fn (float $score): bool => $score >= $floor);
            if ($scores === []) {
                return [];
            }
            arsort($scores);

            return [[
                'file_id' => self::FILE,
                'score' => reset($scores),
                'chunk_count' => count($scores),
                'best_chunk' => $byIndex[array_key_first($scores)],
                'chunks' => array_map(static fn (int $index): FileChunk => $byIndex[$index], array_keys($scores)),
                'chunk_scores' => $scores,
                'match_type' => 'content',
                'collection' => 'documentation_chunks',
            ]];
        });

        $access = Mockery::mock(FileAccessResolverInterface::class);
        $access->shouldReceive('shouldEnforceSecurity')->andReturn(false);
        $access->shouldReceive('isPhysicalFile')->andReturn(true);
        $access->shouldReceive('filterAccessibleFileIds')->andReturnUsing(static fn (array $ids): array => $ids);
        $access->shouldReceive('getAccessibleFileIds')->andReturn([self::FILE]);

        $filenames = Mockery::mock(FilenameExtractor::class);
        $filenames->shouldReceive('extract')->andReturn([]);

        return new FileContextProvider($search, $access, $filenames, null, $this->store);
    }

    /**
     * One file-context stage. $with: 'map' wires the document-map turn, 'today'
     * wires probes + passage selection; both by default (the host's wiring).
     *
     * @param array<string, mixed> $qu Overrides of the QU result.
     * @param list<string> $with
     */
    private function fanOut(array $qu = [], array $conversation = [], array $with = ['map', 'today'], bool $mapped = true, string $question = self::QUESTION): PipelineContext
    {
        $provider = $this->provider($this->chunks($mapped));

        $vectors = Mockery::mock(VectorStoreInterface::class);
        $vectors->shouldReceive('collectionExists')->andReturn(true);
        $vectors->shouldReceive('count')->andReturnUsing(function (string $collection, array $filter = []): int {
            $this->countFilters[] = $filter;

            return $this->anyMapStored ? 12 : 0;
        });

        $manager = new AiManager(
            Mockery::mock(DataIngestionServiceInterface::class),
            Mockery::mock(ContextRetrieverInterface::class),
            Mockery::mock(EmbeddingProviderInterface::class),
            Mockery::mock(LlmProviderInterface::class),
            Mockery::mock(QueryGeneratorInterface::class),
            Mockery::mock(QueryExecutorInterface::class),
            Mockery::mock(ResponseGeneratorInterface::class),
            $vectors,
            $provider,
            probeWriter: in_array('today', $with, true) ? new DocumentProbeWriter($this->probeLlm, ['corpus_languages' => ['en']]) : null,
            passageSelector: in_array('today', $with, true) ? new PassageSelector($this->selectorLlm) : null,
            documentMapTurn: in_array('map', $with, true)
                ? new DocumentMapTurn(new DocumentMapReader($this->readerLlm, ['application' => '']), $provider, [], $this->store)
                : null,
        );

        $ctx = new PipelineContext();
        $ctx->question = $question;
        $ctx->qu = new QueryUnderstandingResult(...($qu + [
            'searchQuery' => 'remove wall',
            'questionType' => 'knowledge_query',
            'clarifiedQuestion' => 'May a member remove a wall of the building?',
            'language' => 'en',
        ]));
        $ctx->conversationContext = $conversation;

        $trace = [];
        $method = new \ReflectionMethod($manager, 'fanOutFileContext');
        $method->setAccessible(true);
        $ctx = $method->invokeArgs($manager, [$ctx, $question, ['user' => (object) ['id' => 13]], &$trace]);
        $this->trace = $trace;

        return $ctx;
    }

    /** The composition prompt of the document part of the turn: the analysis block, then the passages. */
    private function prompt(PipelineContext $ctx): string
    {
        $options = ['style' => 'friendly'];
        $analysis = new QuestionAnalysisSection();

        return ($analysis->shouldInclude($ctx, $options) ? $analysis->format($ctx, $options) : '')
            . (new FileContextSection())->format($ctx, $options);
    }

    /** @return list<int> */
    private function centres(PipelineContext $ctx): array
    {
        return array_map(static fn (array $file): int => (int) $file['chunk_index'], $ctx->fileContext['relevant_files'] ?? []);
    }

    private function assertNoSearchWasRepeated(): void
    {
        $this->assertSame([], array_keys(array_filter(array_count_values($this->searches), static fn (int $n): bool => $n > 1)), 'no search was run twice');
    }

    // ------------------------------------------------------------ the reader path

    /** @test */
    public function a_first_question_over_a_mapped_file_costs_one_reader_call_and_no_probe_or_selection_call(): void
    {
        $this->needs = [[6, 'general_rule']]; // what the search already found

        $ctx = $this->fanOut();

        $this->assertCount(1, $this->readerLlm->calls);
        $this->assertCount(0, $this->probeLlm->calls, 'no probe call');
        $this->assertCount(0, $this->selectorLlm->calls, 'no selection call');
        $this->assertArrayNotHasKey('search_probes', $this->trace);
        $this->assertArrayNotHasKey('passage_selection', $this->trace);
        $this->assertSame('closed', $this->trace['document_map']['gate']['state']);
        $this->assertArrayNotHasKey('passages', $this->trace['document_map'], 'nothing was applied: nothing is claimed');
        $this->assertSame([6, 5, 11], $this->centres($ctx));
        $this->assertNoSearchWasRepeated();
        $this->assertSame([['must_not' => [['is_empty' => ['key' => 'map.gist']]]]], array_values(array_unique($this->countFilters, SORT_REGULAR)), 'a failed gist is not "a map"');
    }

    /** @test */
    public function a_closed_gate_hands_the_composer_exactly_the_plain_search_prompt(): void
    {
        $this->needs = [[6, 'general_rule'], [5, 'classification'], [11, 'procedure_or_approval']];
        $closed = $this->fanOut();
        $this->assertSame('closed', $this->trace['document_map']['gate']['state']);

        // The same turn with nothing of the feature wired, and no probes or selection either.
        $plain = $this->fanOut(with: []);

        $this->assertSame($plain->fileContext, $closed->fileContext);
        $this->assertSame($this->prompt($plain), $this->prompt($closed), 'byte for byte');
        $this->assertArrayNotHasKey('analysis', $closed->fileContext);
        $this->assertStringNotContainsString('QUESTION ANALYSIS', $this->prompt($closed));
    }

    /** @test */
    public function an_open_gate_puts_the_picks_first_and_tells_the_composer_which_rules_govern(): void
    {
        $this->needs = [[2, 'general_rule'], [11, 'procedure_or_approval']];

        $ctx = $this->fanOut();

        $this->assertSame('open', $this->trace['document_map']['gate']['state']);
        $this->assertSame(['2:general_rule'], $this->trace['document_map']['gate']['opened_by']);
        $this->assertSame([2, 11, 6, 5], $this->centres($ctx), 'the rule the search did not find leads');
        $this->assertSame(
            ['rules' => [
                ['role' => 'general_rule', 'articles' => [1002], 'passage' => 1],
                ['role' => 'procedure_or_approval', 'articles' => [1011], 'passage' => 2],
            ], 'has_classification' => false],
            $ctx->fileContext['analysis']
        );

        $prompt = $this->prompt($ctx);
        $this->assertStringStartsWith(
            "\n=== QUESTION ANALYSIS ===\n\nThe rules that govern the question, in order:\n"
            . "1. the general rule — art. 1002, in passage [1]\n"
            . "2. who approves or decides, and how — art. 1011, in passage [2]\n",
            $prompt
        );
        $this->assertStringContainsString("\n=== RELEVANT FILE CONTENT ===\n", $prompt);
        $this->assertStringNotContainsString('Whether a member may alter', $prompt, 'the reader\'s sentence stays in the trace');
        $this->assertSame('Whether a member may alter a shared part.', $this->trace['document_map']['reader']['issue']);
        foreach ($ctx->fileContext['relevant_files'] as $file) {
            $this->assertArrayNotHasKey('snippet_chunks', $file, 'structure keys do not reach the prompt');
            $this->assertArrayNotHasKey('heading_path', $file);
        }
        $this->assertCount(0, $this->probeLlm->calls);
        $this->assertCount(0, $this->selectorLlm->calls);
    }

    /** @test */
    public function an_open_gate_never_drops_the_article_the_user_named_by_number(): void
    {
        // Chunk 9 (art. 1009) scores 0.2: only the exact-article angle returns it.
        $this->needs = [[2, 'general_rule'], [1, 'classification'], [3, 'procedure_or_approval'], [4, 'procedure_or_approval'], [8, 'general_rule']];
        config(['ai.file_context.max_references' => 2]);

        $ctx = $this->fanOut(question: 'What does article 1009 say about removing a wall?');

        $this->assertSame('open', $this->trace['document_map']['gate']['state']);
        $this->assertSame(9, $this->centres($ctx)[0], 'the named article leads, as on the plain path');
        $this->assertTrue($ctx->fileContext['relevant_files'][0]['article_match']);
        $this->assertContains(2, $this->centres($ctx));
    }

    // ---------------------------------------------------------- not a map turn

    /** @test */
    public function a_failed_reader_falls_back_to_todays_path_once_without_searching_again(): void
    {
        $this->needs = null;

        $ctx = $this->fanOut();

        $this->assertCount(1, $this->readerLlm->calls);
        $this->assertSame(['fallback' => 'no_report'], $this->trace['document_map']);
        $this->assertCount(1, $this->probeLlm->calls, 'today\'s path: the probes are written…');
        $this->assertSame(2, $this->trace['search_probes']['count']);
        $this->assertArrayHasKey('passage_selection', $this->trace, '…and the passage selection runs');
        $this->assertContains('alteration of a common portion', $this->trace['angles'], 'the probes are searched');
        $this->assertContains(self::QUESTION, $this->trace['angles']);
        $this->assertNoSearchWasRepeated();
        $this->assertSame(1, $this->store->reads, 'the file is read once for the whole turn');
        $this->assertSame([6, 5, 11], $this->centres($ctx));
        $this->assertArrayNotHasKey('analysis', $ctx->fileContext);
    }

    /** @test */
    public function the_fallback_gives_exactly_what_the_turn_gives_without_the_feature(): void
    {
        $this->needs = null;
        $fellBack = $this->fanOut();
        $fellBackTrace = array_diff_key($this->trace, ['document_map' => true]);

        $today = $this->fanOut(with: ['today']);
        $todayTrace = $this->trace;

        $this->assertSame($today->fileContext, $fellBack->fileContext);
        foreach (['angles', 'angle_groups', 'angle_chunks', 'question_passages'] as $key) {
            $this->assertSame($todayTrace[$key] ?? null, $fellBackTrace[$key] ?? null, $key);
        }
    }

    /** @test */
    public function a_file_without_a_map_keeps_todays_path(): void
    {
        $ctx = $this->fanOut(mapped: false);

        $this->assertCount(0, $this->readerLlm->calls, 'nothing to read');
        $this->assertSame(['fallback' => 'unmapped_file'], $this->trace['document_map']);
        $this->assertCount(1, $this->probeLlm->calls);
        $this->assertArrayHasKey('passage_selection', $this->trace);
        $this->assertNoSearchWasRepeated();
        $this->assertSame([6, 5, 11], $this->centres($ctx));
    }

    /** @test */
    public function a_host_that_never_built_a_map_pays_nothing_and_runs_one_pass(): void
    {
        $this->anyMapStored = false;

        $this->fanOut();

        $this->assertCount(0, $this->readerLlm->calls);
        $this->assertArrayNotHasKey('document_map', $this->trace, 'not even planned');
        $this->assertCount(1, $this->probeLlm->calls);
        $this->assertNoSearchWasRepeated();
    }

    // ------------------------------------------- which turns the reader reads

    /** @test */
    public function a_follow_up_turn_keeps_probes_and_selection(): void
    {
        $conversation = ['last_referenced_files' => [['file_id' => self::FILE, 'chunk_index' => 6, 'cited_articles' => [1006]]]];
        $this->needs = [[2, 'general_rule']];

        $this->fanOut(['followUpReference' => true], $conversation);

        $this->assertCount(0, $this->readerLlm->calls, 'the reader does not name what a deepening answer needs (measured)');
        $this->assertCount(1, $this->probeLlm->calls);
        $this->assertArrayHasKey('passage_selection', $this->trace);
        $this->assertArrayNotHasKey('document_map', $this->trace, 'decided before any search: one pass');
        $this->assertNoSearchWasRepeated();
    }

    /** @test */
    public function a_new_question_later_in_a_conversation_is_read_by_the_reader(): void
    {
        // An earlier answer cited another part of the same law; this question is not a follow-up.
        $conversation = ['last_referenced_files' => [['file_id' => self::FILE, 'chunk_index' => 12, 'cited_articles' => [1012]]]];
        $this->needs = [[2, 'general_rule']];

        $ctx = $this->fanOut([], $conversation);

        $this->assertCount(1, $this->readerLlm->calls, 'eligibility is decided per turn, not once per conversation');
        $this->assertSame('open', $this->trace['document_map']['gate']['state']);
        $this->assertSame(2, $this->centres($ctx)[0]);
        $this->assertCount(0, $this->probeLlm->calls);
    }

    /** @test */
    public function a_question_whose_own_wording_lands_on_what_the_previous_answer_cited_is_a_follow_up(): void
    {
        // Measured live: "Tell me more about my duty to contribute to common expenses"
        // after an overview of rights and duties. Query Understanding does NOT call it
        // a follow-up (the sentence stands on its own) — but the passage its own
        // wording ranks first (chunk 6 here) is one the overview was built on.
        $conversation = ['last_referenced_files' => [['file_id' => self::FILE, 'chunk_index' => 6, 'cited_articles' => [1006]]]];
        $this->needs = [[2, 'general_rule']];

        $ctx = $this->fanOut([], $conversation);

        $this->assertCount(0, $this->readerLlm->calls, 'decided before the reader is paid for');
        $this->assertSame(['fallback' => 'follow_up'], $this->trace['document_map']);
        $this->assertCount(1, $this->probeLlm->calls, 'probes…');
        $this->assertArrayHasKey('passage_selection', $this->trace, '…and selection');
        $this->assertArrayHasKey('deepening', $this->trace, 'the contract says what is new');
        $this->assertNoSearchWasRepeated();
        $this->assertNotContains(2, $this->centres($ctx));

        // The same when the answer cited another chunk that defines the same article.
        $conversation = ['last_referenced_files' => [['file_id' => self::FILE, 'chunk_index' => 99, 'cited_articles' => [1006]]]];
        $this->fanOut([], $conversation);
        $this->assertSame(['fallback' => 'follow_up'], $this->trace['document_map']);
    }

    /** @test */
    public function a_new_question_whose_passages_overlap_an_earlier_answer_is_still_a_new_question(): void
    {
        // Measured live (an overview of rights, then "can I tear down this wall?"): the
        // overview had cited articles the new question's search returns among others.
        // That overlap does not make a follow-up — taken as one, the benchmark question
        // lost its reader on every later turn. What the question's OWN wording ranks
        // first (chunk 6) is new ground: the earlier answer cited chunk 11.
        $conversation = ['last_referenced_files' => [['file_id' => self::FILE, 'chunk_index' => 11, 'cited_articles' => [1011]]]];
        $this->needs = [[2, 'general_rule']];

        $ctx = $this->fanOut([], $conversation);

        $this->assertCount(1, $this->readerLlm->calls);
        $this->assertSame('open', $this->trace['document_map']['gate']['state']);
        $this->assertSame(2, $this->centres($ctx)[0]);
        $this->assertCount(0, $this->probeLlm->calls);
        // The analysis governs the answer: no "already covered" contract beside it.
        $this->assertArrayNotHasKey('deepening', $this->trace);
        $ctx->qu = new QueryUnderstandingResult(questionType: 'knowledge_query');
        $this->assertStringNotContainsString('ALREADY COVERED', $this->prompt($ctx));

        // With a closed gate the turn is the plain-search turn, contract included.
        $this->needs = [[6, 'general_rule']];
        $closed = $this->fanOut([], $conversation);
        $this->assertSame('closed', $this->trace['document_map']['gate']['state']);
        $this->assertArrayHasKey('deepening', $this->trace);
        $this->assertStringContainsString('ALREADY COVERED', $this->prompt($closed));
    }

    /** @test */
    public function an_ask_the_application_answers_and_a_failed_understanding_never_call_the_reader(): void
    {
        $this->needs = [[2, 'general_rule']];

        $this->fanOut(['navigationTarget' => 'own_team']);
        $this->assertCount(0, $this->readerLlm->calls, 'a how-to turn: its how-to document, no document work');
        $this->assertArrayNotHasKey('document_map', $this->trace);

        $this->fanOut(['failed' => true]);
        $this->assertCount(0, $this->readerLlm->calls);
        $this->assertArrayNotHasKey('document_map', $this->trace);
    }

    // ------------------------------------------------------------- the switches

    /** @test */
    public function with_the_feature_switched_off_nothing_of_it_runs(): void
    {
        config(['ai.file_context.document_map.enabled' => false]);
        $this->needs = [[2, 'general_rule']];

        $off = $this->fanOut();

        $this->assertCount(0, $this->readerLlm->calls);
        $this->assertSame([], $this->countFilters, 'the store is not even asked whether a map exists');
        $this->assertArrayNotHasKey('document_map', $this->trace);
        $this->assertCount(1, $this->probeLlm->calls, 'today\'s path');
        $this->assertArrayHasKey('passage_selection', $this->trace);

        $today = $this->fanOut(with: ['today']);
        $this->assertSame($today->fileContext, $off->fileContext);
    }

    /** @test */
    public function with_the_reader_switched_off_the_turn_is_todays_path(): void
    {
        config(['ai.file_context.document_map.reader.enabled' => false]);
        $this->needs = [[2, 'general_rule']];

        $ctx = $this->fanOut();

        $this->assertCount(0, $this->readerLlm->calls);
        $this->assertArrayNotHasKey('document_map', $this->trace);
        $this->assertCount(1, $this->probeLlm->calls);
        $this->assertArrayHasKey('passage_selection', $this->trace);
        $this->assertArrayNotHasKey('analysis', $ctx->fileContext);
    }

    /** @test */
    public function there_is_no_way_to_run_the_reader_without_its_analysis(): void
    {
        // The reader as retrieval only measured WORSE than today's path: a host key
        // that once meant "no block" must not leave the picks leading without it.
        config(['ai.file_context.document_map.analysis.enabled' => false]);
        $this->needs = [[2, 'general_rule']];

        $ctx = $this->fanOut();

        $defaults = require __DIR__ . '/../../../config/ai.php';
        $this->assertSame(['enabled', 'uploads', 'reader'], array_keys($defaults['file_context']['document_map']), 'the switch is gone');
        $this->assertTrue((bool) $defaults['file_context']['document_map']['enabled']);
        $this->assertTrue((bool) $defaults['file_context']['document_map']['reader']['enabled']);
        $this->assertFalse((bool) $defaults['file_context']['document_map']['uploads'], 'uploaded files are mapped only when the host says so');
        // Open means applied: the picks lead AND the block is there — never one without the other.
        $this->assertSame('open', $this->trace['document_map']['gate']['state']);
        $this->assertSame(2, $this->centres($ctx)[0]);
        $this->assertArrayHasKey('analysis', $ctx->fileContext);
    }
}
