<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Console;

use Condoedge\Ai\Console\Commands\DocumentMapCommand;
use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\VectorStoreInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Services\Files\DocumentMapBuilder;
use Condoedge\Ai\Support\DocumentMap;
use Condoedge\Ai\Tests\Fixtures\CallbackUsageLlm;
use Condoedge\Ai\Tests\Fixtures\InMemoryMapChunkStore;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Mockery;

/**
 * ai:documents:map — builds the document map outside the ingest: one file, or
 * every file of a collection that lacks a complete map (backfill). A dry run
 * lists what would be built and asks the model nothing.
 */
class DocumentMapCommandTest extends TestCase
{
    private const DOCS = 'documentation_chunks';
    private const FILES = 'test_file_chunks';

    private InMemoryMapChunkStore $store;

    private CallbackUsageLlm $gist;

    private CallbackUsageLlm $scope;

    /** @var list<string> collections the vector store was scrolled for */
    private array $scrolled = [];

    /** @var list<string> */
    private array $existing = [self::DOCS, self::FILES];

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Placeholder keys: building the console application resolves the other
        // commands' providers. No call is ever made with them.
        $app['config']->set('ai.llm.default', 'openai');
        $app['config']->set('ai.llm.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
        $app['config']->set('ai.embedding.default', 'openai');
        $app['config']->set('ai.embedding.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
    }

    public function setUp(): void
    {
        parent::setUp();

        config(['ai.file_context.physical_collection' => self::DOCS]);

        $this->store = new InMemoryMapChunkStore();
        $this->store->search = function (string $query, int $limit, array $filters, ?string $collection, InMemoryMapChunkStore $store) {
            // The gist names its passage: that passage comes back first.
            $hits = [];
            foreach ($store->getFileChunks($filters['file_id'], $collection) as $chunk) {
                $hits[] = ['chunk' => $chunk, 'score' => str_contains($query, "«{$chunk->content}»") ? 0.9 : 0.4];
            }
            usort($hits, static fn (array $a, array $b) => $b['score'] <=> $a['score']);

            return array_slice($hits, 0, $limit);
        };

        $this->gist = new CallbackUsageLlm(function (array $messages) {
            preg_match('/\nText:\n(.+)\n<\/passage>/s', $messages[1]['content'], $m);

            return ['AA' => ['gist' => "Gist of «{$m[1]}»"]];
        }, 'small-model');
        $this->scope = new CallbackUsageLlm(fn () => [], 'accurate-model');

        $vectorStore = Mockery::mock(VectorStoreInterface::class)->shouldIgnoreMissing();
        $vectorStore->shouldReceive('collectionExists')->andReturnUsing(fn (string $name) => in_array($name, $this->existing, true));
        $vectorStore->shouldReceive('count')->andReturnUsing(fn (string $collection) => count($this->points($collection)));
        $vectorStore->shouldReceive('scroll')->andReturnUsing(function (string $collection, array $filter = [], int $limit = 100) {
            $this->scrolled[] = $collection;

            return array_slice($this->points($collection), 0, $limit);
        });

        $this->app->instance(VectorStoreInterface::class, $vectorStore);
        $this->app->instance(ChunkStoreInterface::class, $this->store);
        $this->app->bind(DocumentMapBuilder::class, fn () => new DocumentMapBuilder(
            $this->gist,
            $this->scope,
            $this->store,
            ['application' => 'A portal.']
        ));

        // Registered here: the provider registration is the integrator's step.
        $this->app[Kernel::class]->registerCommand($this->app->make(DocumentMapCommand::class));
    }

    /** The points a scroll of the collection returns: one per stored chunk. */
    private function points(string $collection): array
    {
        $points = [];
        foreach ($this->store->files[$collection] ?? [] as $chunks) {
            foreach ($chunks as $chunk) {
                $points[] = ['id' => $chunk->getVectorId(), 'payload' => ['file_id' => $chunk->fileId, 'chunk_index' => $chunk->chunkIndex]];
            }
        }

        return $points;
    }

    /**
     * A two-level document: a preamble and $count content chunks, mapped or not.
     * $records overrides the record of a chunk (chunk_index => record, or false for none).
     *
     * @param array<int, array<string, mixed>|false> $records
     * @param array<int, list<string>> $paths chunk_index => heading path (a divided document)
     */
    private function document(int|string $fileId, string $collection, int $count, bool $mapped = false, array $records = [], array $paths = []): void
    {
        $chunks = [new FileChunk($fileId, "doc-{$fileId}.md", '# Title', [], 0, $count + 1, 0, 7, ['heading_path' => ['Title'], 'is_preamble' => true])];
        for ($index = 1; $index <= $count; $index++) {
            $content = "Passage {$index} of {$fileId}.";
            $metadata = ['heading_path' => $paths[$index] ?? ['Title', 'Section'], 'is_preamble' => false];
            if (is_array($records[$index] ?? null)) {
                $metadata['map'] = $records[$index] + ['v' => DocumentMap::VERSION, 'hash' => DocumentMap::hash($content)];
            } elseif ($mapped && ($records[$index] ?? null) !== false) {
                $metadata['map'] = ['v' => DocumentMap::VERSION, 'hash' => DocumentMap::hash($content), 'gist' => 'Stored gist.'];
            }
            $chunks[] = new FileChunk($fileId, "doc-{$fileId}.md", $content, [], $index, $count + 1, 0, mb_strlen($content), $metadata);
        }
        $this->store->put($fileId, $chunks, $collection);
    }

    /** @return array{0: int, 1: string} exit code, output */
    private function runMap(array $options): array
    {
        $code = Artisan::call('ai:documents:map', $options);

        return [$code, Artisan::output()];
    }

    /** @test */
    public function a_dry_run_lists_the_files_and_their_chunk_counts_and_asks_nothing(): void
    {
        config(['ai.file_context.document_map.uploads' => true]);
        $this->document('physical:laws/code.md', self::DOCS, 3);
        $this->document('physical:docs/guide.md', self::DOCS, 2, mapped: true);
        $this->document(7, self::FILES, 4);

        [$code, $output] = $this->runMap(['--backfill' => true, '--dry-run' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('/physical:laws\/code\.md\s+3 chunks, 0 mapped\s+to build/', $output);
        $this->assertMatchesRegularExpression('/physical:docs\/guide\.md\s+2 chunks, 2 mapped\s+complete/', $output);
        $this->assertMatchesRegularExpression('/\b7\s+4 chunks, 0 mapped\s+to build/', $output);
        $this->assertStringContainsString(self::DOCS, $output);
        $this->assertStringContainsString(self::FILES, $output);
        $this->assertCount(0, $this->gist->calls, 'no LLM call');
        $this->assertSame([], $this->store->writes);
        $this->assertSame([], $this->store->searches);
    }

    // --------------------------------------------------- which collections

    /** @test */
    public function a_backfill_maps_the_hosts_own_documents_only_until_uploads_are_switched_on(): void
    {
        $this->document('physical:laws/code.md', self::DOCS, 2);
        $this->document(7, self::FILES, 2);

        [$code, $output] = $this->runMap(['--backfill' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertSame([self::DOCS], $this->scrolled, 'the uploads collection is not even listed');
        $this->assertTrue(DocumentMap::isUsable($this->store->getFileChunks('physical:laws/code.md', self::DOCS)));
        $this->assertFalse(DocumentMap::isUsable($this->store->getFileChunks(7, self::FILES)));

        // Asked for by name while switched off: refused, nothing read or written.
        $writes = count($this->store->writes);
        [$code, $output] = $this->runMap(['--backfill' => true, '--collection' => self::FILES]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('AI_DOCUMENT_MAP_UPLOADS', $output);
        $this->assertCount($writes, $this->store->writes);

        [$code] = $this->runMap(['--file' => '7']);
        $this->assertFalse(DocumentMap::isUsable($this->store->getFileChunks(7, self::FILES)), 'a single uploaded file neither');

        config(['ai.file_context.document_map.uploads' => true]);
        [$code, $output] = $this->runMap(['--backfill' => true]);
        $this->assertSame(0, $code, $output);
        $this->assertTrue(DocumentMap::isUsable($this->store->getFileChunks(7, self::FILES)));
    }

    // ------------------------------------------ what was attempted, what is owed

    /** @test */
    public function a_backfill_does_not_pay_again_for_a_chunk_already_attempted_unless_asked(): void
    {
        // 10 passages: 9 mapped, the 10th rejected twice by an earlier run.
        $this->document('physical:laws/code.md', self::DOCS, 10, mapped: true, records: [10 => ['failed' => 'rejected', 'attempts' => 1]]);

        [$code, $output] = $this->runMap(['--backfill' => true]);
        $this->assertSame(0, $code, $output);
        $this->assertCount(0, $this->gist->calls);
        $this->assertMatchesRegularExpression('/code\.md\s.*complete.*1 without a gist/', $output);

        [$code, $output] = $this->runMap(['--backfill' => true, '--dry-run' => true]);
        $this->assertMatchesRegularExpression('/10 chunks, 9 mapped, 1 failed\s+complete/', $output);

        [$code, $output] = $this->runMap(['--backfill' => true, '--retry-failed' => true]);
        $this->assertSame(0, $code, $output);
        $this->assertCount(1, $this->gist->calls, 'the one chunk that had failed');
        $this->assertSame(10, DocumentMap::state($this->store->getFileChunks('physical:laws/code.md', self::DOCS))['mapped']);
    }

    /** @test */
    public function a_file_whose_map_cannot_be_used_fails_the_command_and_says_how_to_retry(): void
    {
        $this->document('physical:laws/code.md', self::DOCS, 2);
        // Nothing the model writes retrieves its passage.
        $this->store->search = fn () => [];

        [$code, $output] = $this->runMap(['--backfill' => true]);

        $this->assertSame(1, $code, 'rejected gists that leave no usable map are a failure');
        $this->assertStringContainsString('rejected 2', $output);
        $this->assertStringContainsString('--retry-failed', $output);

        // The next plain backfill pays nothing for it — and still says it is not usable.
        $calls = count($this->gist->calls);
        [$code, $output] = $this->runMap(['--backfill' => true]);
        $this->assertCount($calls, $this->gist->calls);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('not usable', $output);
    }

    /** @test */
    public function a_divided_document_without_its_scope_verdict_is_not_complete_and_rescope_judges_again(): void
    {
        config(['ai.project.description' => 'A portal.']);
        $paths = [1 => ['Title', 'Part A', 'S'], 2 => ['Title', 'Part B', 'S']];
        $this->document('physical:laws/code.md', self::DOCS, 2, mapped: true, paths: $paths);
        $verdict = 'yes';
        $this->scope = new CallbackUsageLlm(function () use (&$verdict) {
            return ['P1' => ['applies' => 'yes', 'reason' => 'Theirs.'], 'P2' => ['applies' => $verdict === 'yes' ? 'no' : 'yes', 'reason' => 'Other.']];
        }, 'accurate-model');
        $summaries = fn (): int => count(array_filter($this->gist->calls, static fn (array $c) => ($c['options']['json_schema']['name'] ?? '') === 'section_summary'));
        $this->gist = new CallbackUsageLlm(fn () => ['summary' => 'What the part covers.'], 'small-model');

        [, $output] = $this->runMap(['--backfill' => true, '--dry-run' => true]);
        $this->assertMatchesRegularExpression('/2 chunks, 2 mapped\s+scope pending/', $output);

        [$code, $output] = $this->runMap(['--backfill' => true]);
        $this->assertSame(0, $code, $output);
        $this->assertSame(2, $summaries(), 'the scope pass ran: no gist was rebuilt');
        $this->assertCount(1, $this->scope->calls);
        $this->assertSame('out', $this->store->chunk('physical:laws/code.md', 2, self::DOCS)->metadata['map']['scope']);
        $this->assertStringContainsString('scope: in 1, out 1', $output);

        // Complete now: a plain backfill skips it; --rescope judges again.
        $this->runMap(['--backfill' => true]);
        $this->assertCount(1, $this->scope->calls);

        $verdict = 'no';
        [$code] = $this->runMap(['--file' => 'physical:laws/code.md', '--rescope' => true]);
        $this->assertSame(0, $code);
        $this->assertCount(2, $this->scope->calls);
        $this->assertSame('in', $this->store->chunk('physical:laws/code.md', 2, self::DOCS)->metadata['map']['scope']);
    }

    /** @test */
    public function backfill_builds_the_files_that_lack_a_complete_map_and_skips_the_others(): void
    {
        $this->document('physical:laws/code.md', self::DOCS, 3);
        $this->document('physical:docs/guide.md', self::DOCS, 2, mapped: true);

        [$code, $output] = $this->runMap(['--backfill' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertCount(3, $this->gist->calls, 'only the passages of the unmapped file');
        $this->assertTrue(DocumentMap::isUsable($this->store->getFileChunks('physical:laws/code.md', self::DOCS)));
        $this->assertSame(['physical:laws/code.md'], array_values(array_unique(array_column($this->store->writes, 'file_id'))));
        $this->assertMatchesRegularExpression('/physical:laws\/code\.md 3\/3 gists/', $output);
        $this->assertMatchesRegularExpression('/physical:docs\/guide\.md\s.*complete/', $output);
    }

    /** @test */
    public function it_ends_with_the_calls_and_tokens_spent_per_model(): void
    {
        $this->document('physical:laws/code.md', self::DOCS, 3);

        [, $output] = $this->runMap(['--backfill' => true]);

        $this->assertMatchesRegularExpression('/small-model\D+3 calls\D+300 input\D+60 output/', $output);
        $this->assertStringContainsString('tokens only', $output, 'no price table in the package: no USD estimate');
        $this->assertStringNotContainsString('$', $output);
    }

    /** @test */
    public function force_rebuilds_a_complete_map(): void
    {
        $this->document('physical:docs/guide.md', self::DOCS, 2, mapped: true);

        [$code] = $this->runMap(['--backfill' => true, '--force' => true]);

        $this->assertSame(0, $code);
        $this->assertCount(2, $this->gist->calls);
        $this->assertStringStartsWith('Gist of «Passage 1', $this->store->chunk('physical:docs/guide.md', 1, self::DOCS)->metadata['map']['gist']);
    }

    /** @test */
    public function one_file_is_built_in_the_collection_it_lives_in(): void
    {
        config(['ai.file_context.document_map.uploads' => true]);
        $this->document(7, self::FILES, 2);
        $this->document(8, self::FILES, 2);

        [$code, $output] = $this->runMap(['--file' => '7']);

        $this->assertSame(0, $code, $output);
        $this->assertCount(2, $this->gist->calls);
        $this->assertTrue(DocumentMap::isUsable($this->store->getFileChunks(7, self::FILES)));
        $this->assertFalse(DocumentMap::isUsable($this->store->getFileChunks(8, self::FILES)));
        $this->assertSame([], $this->scrolled, 'a single file needs no enumeration');
    }

    /** @test */
    public function the_collection_option_restricts_the_backfill(): void
    {
        config(['ai.file_context.document_map.uploads' => true]);
        $this->document('physical:laws/code.md', self::DOCS, 2);
        $this->document(7, self::FILES, 2);

        $this->runMap(['--backfill' => true, '--collection' => self::FILES]);

        $this->assertSame([self::FILES], $this->scrolled);
        $this->assertFalse(DocumentMap::isUsable($this->store->getFileChunks('physical:laws/code.md', self::DOCS)));
        $this->assertTrue(DocumentMap::isUsable($this->store->getFileChunks(7, self::FILES)));
    }

    /** @test */
    public function a_collection_that_does_not_exist_is_skipped_not_created(): void
    {
        config(['ai.file_context.document_map.uploads' => true]);
        $this->existing = [self::FILES];
        $this->document(7, self::FILES, 1);

        [$code, $output] = $this->runMap(['--backfill' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertSame([self::FILES], $this->scrolled);
        $this->assertTrue(DocumentMap::isUsable($this->store->getFileChunks(7, self::FILES)));
    }

    /** @test */
    public function it_asks_for_a_file_or_a_backfill(): void
    {
        [$code, $output] = $this->runMap([]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--backfill', $output);
        $this->assertCount(0, $this->gist->calls);
    }

    /** @test */
    public function a_build_that_reports_errors_fails_the_command_but_not_the_other_files(): void
    {
        $this->document('physical:a.md', self::DOCS, 1);
        $this->document('physical:b.md', self::DOCS, 1);
        $this->store->failWritesFor = [];
        $failing = new CallbackUsageLlm(function (array $messages) {
            if (str_contains($messages[1]['content'], 'physical:a.md')) {
                throw new \RuntimeException('429 rate limited');
            }
            preg_match('/\nText:\n(.+)\n<\/passage>/s', $messages[1]['content'], $m);

            return ['AA' => ['gist' => "Gist of «{$m[1]}»"]];
        }, 'small-model');
        $this->gist = $failing;

        [$code, $output] = $this->runMap(['--backfill' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('429 rate limited', $output);
        $this->assertTrue(DocumentMap::isUsable($this->store->getFileChunks('physical:b.md', self::DOCS)));
    }

    // ------------------------------------- the remembered "any map stored?" answer

    /** @test */
    public function the_question_turn_and_the_command_share_one_cache_key(): void
    {
        $this->assertSame('ai:document_map:any', DocumentMap::ANY_STORED_CACHE_KEY);
    }

    /** @test */
    public function a_run_that_built_a_gist_forgets_the_remembered_answer(): void
    {
        // A question asked before the build remembered "no chunk carries a map".
        Cache::put(DocumentMap::ANY_STORED_CACHE_KEY, false, 60);
        $this->document('physical:laws/code.md', self::DOCS, 2);

        [$code, $output] = $this->runMap(['--backfill' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertFalse(Cache::has(DocumentMap::ANY_STORED_CACHE_KEY), 'the next question counts again');
    }

    /** @test */
    public function it_is_forgotten_too_when_another_file_of_the_run_reported_errors(): void
    {
        Cache::put(DocumentMap::ANY_STORED_CACHE_KEY, false, 60);
        $this->document('physical:a.md', self::DOCS, 1);
        $this->document('physical:b.md', self::DOCS, 1);
        $this->gist = new CallbackUsageLlm(function (array $messages) {
            if (str_contains($messages[1]['content'], 'physical:a.md')) {
                throw new \RuntimeException('429 rate limited');
            }
            preg_match('/\nText:\n(.+)\n<\/passage>/s', $messages[1]['content'], $m);

            return ['AA' => ['gist' => "Gist of «{$m[1]}»"]];
        }, 'small-model');

        [$code] = $this->runMap(['--backfill' => true]);

        $this->assertSame(1, $code);
        $this->assertFalse(Cache::has(DocumentMap::ANY_STORED_CACHE_KEY), 'b.md has a map now');
    }

    /** @test */
    public function a_run_that_wrote_no_map_leaves_the_remembered_answer_alone(): void
    {
        $this->document('physical:laws/code.md', self::DOCS, 2);
        $this->document('physical:docs/guide.md', self::DOCS, 2, mapped: true);

        // A dry run writes nothing.
        Cache::put(DocumentMap::ANY_STORED_CACHE_KEY, false, 60);
        $this->runMap(['--backfill' => true, '--dry-run' => true]);
        $this->assertFalse(Cache::get(DocumentMap::ANY_STORED_CACHE_KEY, 'forgotten'));

        // A complete file is skipped.
        Cache::put(DocumentMap::ANY_STORED_CACHE_KEY, true, 60);
        $this->runMap(['--file' => 'physical:docs/guide.md']);
        $this->assertTrue(Cache::get(DocumentMap::ANY_STORED_CACHE_KEY, 'forgotten'));

        // Every gist call failed: the attempts are recorded, no gist was stored.
        Cache::put(DocumentMap::ANY_STORED_CACHE_KEY, false, 60);
        $this->gist = new CallbackUsageLlm(static function (): array {
            throw new \RuntimeException('provider down');
        }, 'small-model');
        [$code] = $this->runMap(['--file' => 'physical:laws/code.md']);
        $this->assertSame(1, $code);
        $this->assertSame(['error', 'error'], array_map(static fn (array $w): ?string => $w['map']['failed'] ?? null, $this->store->writes));
        $this->assertFalse(Cache::get(DocumentMap::ANY_STORED_CACHE_KEY, 'forgotten'));
    }

    /** @test */
    public function a_cache_that_cannot_be_reached_never_fails_the_command(): void
    {
        $this->document('physical:laws/code.md', self::DOCS, 2);
        Cache::shouldReceive('forget')
            ->once()
            ->with(DocumentMap::ANY_STORED_CACHE_KEY)
            ->andThrow(new \RuntimeException('cache store unreachable'));

        [$code, $output] = $this->runMap(['--backfill' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertTrue(DocumentMap::isUsable($this->store->getFileChunks('physical:laws/code.md', self::DOCS)));
        $this->assertStringContainsString('1 file(s) built', $output);
        $this->assertStringNotContainsString('cache store unreachable', $output);
    }
}
