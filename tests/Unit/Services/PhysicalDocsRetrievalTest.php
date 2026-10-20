<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\Contracts\FileChunkerInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Context\FilenameExtractor;
use Condoedge\Ai\Services\FileExtractorRegistry;
use Condoedge\Ai\Services\FileProcessor;
use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\Services\Extractors\TextExtractor;
use Condoedge\Ai\Services\SemanticChunker;
use Illuminate\Database\Eloquent\Model;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;

/**
 * Dual-collection routing for physical docs (Task B4).
 *
 * Verifies that:
 *  1. FileProcessor honors the `collection` option, writing physical doc chunks
 *     to the documentation collection (not the default file_chunks).
 *  2. File retrieval searches BOTH collections, so a physical doc indexed in the
 *     documentation collection is returned by content search and classified as
 *     source `physical`.
 */
class PhysicalDocsRetrievalTest extends TestCase
{
    private string $docsCollection = 'documentation_chunks';
    private string $primaryCollection = 'file_chunks';

    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai.file_context.min_relevance_score', 0.4);
        $app['config']->set('ai.file_context.max_references', 5);
        $app['config']->set('ai.file_context.snippet_length', 200);
        $app['config']->set('ai.file_context.security_enabled', true);
        $app['config']->set('ai.file_context.physical_collection', $this->docsCollection);
        $app['config']->set('ai.file_processing.collection', $this->primaryCollection);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function file_processor_routes_physical_docs_to_the_documentation_collection(): void
    {
        // Write a small physical doc to disk.
        $tmp = sys_get_temp_dir() . '/b4_docs_' . uniqid();
        @mkdir($tmp, 0777, true);
        $path = $tmp . '/policy.txt';
        file_put_contents($path, 'Pets are not permitted in common areas of the building.');

        try {
            $registry = new FileExtractorRegistry();
            $registry->registerMany([new TextExtractor()]);

            $chunker = new SemanticChunker();

            $embedding = Mockery::mock(EmbeddingProviderInterface::class);
            $embedding->shouldReceive('embedBatch')
                ->andReturnUsing(fn ($texts) => array_map(fn ($t) => array_fill(0, 1536, 0.1), $texts));

            $chunkStore = Mockery::mock(ChunkStoreInterface::class);
            $chunkStore->shouldReceive('hasFileChunks')->andReturn(false);

            // The key assertion: storeChunks must be called with the docs collection.
            $captured = null;
            $chunkStore->shouldReceive('storeChunks')
                ->once()
                ->withArgs(function ($chunks, $collection) use (&$captured) {
                    $captured = $collection;
                    return $collection === $this->docsCollection && !empty($chunks);
                })
                ->andReturn(true);

            $processor = new FileProcessor($registry, $chunker, $embedding, $chunkStore);

            $file = (object) [
                'id' => 'physical:policy.txt',
                'name' => 'policy.txt',
                'path' => $path,
            ];

            $result = $processor->processFile($file, ['collection' => $this->docsCollection]);

            $this->assertTrue($result->success, 'Processing should succeed');
            $this->assertEquals($this->docsCollection, $captured);
        } finally {
            @unlink($path);
            @rmdir($tmp);
        }
    }

    /** @test */
    public function content_search_returns_physical_docs_from_the_documentation_collection(): void
    {
        $chunkStore = Mockery::mock(ChunkStoreInterface::class);

        $physicalId = 'physical:docs/policy.txt';
        $physicalChunk = new FileChunk(
            fileId: $physicalId,
            fileName: 'policy.txt',
            content: 'Pets are not permitted in common areas.',
            embedding: [],
            chunkIndex: 0,
            totalChunks: 1,
            startPosition: 0,
            endPosition: 39,
            metadata: []
        );

        // Primary collection (no override) returns nothing...
        $chunkStore->shouldReceive('searchByContent')
            ->once()
            ->with(Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn([]);

        // ...but the documentation collection (override) returns the physical doc.
        $chunkStore->shouldReceive('searchByContent')
            ->once()
            ->with(Mockery::any(), Mockery::any(), Mockery::any(), $this->docsCollection)
            ->andReturn([
                ['chunk' => $physicalChunk, 'score' => 0.82],
            ]);

        // FileModel lookup for the (string) physical id resolves to nothing —
        // physical files don't need a DB model row.
        $this->mockEmptyFileModel();

        $graphStore = Mockery::mock(GraphStoreInterface::class);
        $searchService = new FileSearchService($chunkStore, $graphStore, [$this->docsCollection]);

        // Drive it through FileContextProvider to assert source classification.
        $accessResolver = Mockery::mock(FileAccessResolverInterface::class);
        $accessResolver->shouldReceive('shouldEnforceSecurity')->andReturn(true);
        $accessResolver->shouldReceive('isPhysicalFile')
            ->andReturnUsing(fn ($id) => is_string($id) && str_starts_with($id, 'physical:'));
        // Physical ids always pass the access filter.
        $accessResolver->shouldReceive('filterAccessibleFileIds')
            ->andReturnUsing(fn ($ids, $user) => $ids);

        $filenameExtractor = Mockery::mock(FilenameExtractor::class);
        $filenameExtractor->shouldReceive('extract')->andReturn([]);

        $provider = new FileContextProvider($searchService, $accessResolver, $filenameExtractor);

        $user = (object) ['id' => 1];
        $context = $provider->getFileContext('Can I have a pet?', $user);

        $this->assertEquals(1, $context['file_count']);
        $this->assertTrue($context['has_physical']);
        $this->assertFalse($context['has_database']);

        $this->assertCount(1, $context['relevant_files']);
        $this->assertEquals($physicalId, $context['relevant_files'][0]['file_id']);
        $this->assertEquals('physical', $context['relevant_files'][0]['source']);
    }

    /**
     * Bind an empty file-model so FileSearchService's FileModel::whereIn(...)->get()
     * returns no rows (physical files have string ids and no DB model).
     */
    private function mockEmptyFileModel(): void
    {
        $builder = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $builder->shouldReceive('get')->andReturn(collect([]));

        $model = Mockery::mock(Model::class)->makePartial();
        $model->shouldReceive('whereIn')->andReturn($builder);

        $this->app->instance('file-model', $model);
    }
}
