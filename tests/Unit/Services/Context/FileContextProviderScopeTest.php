<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Context;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Context\FilenameExtractor;
use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\Services\Retrieval\DocumentScope;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * The provider hands the turn's SCOPE to every search it makes — the passages
 * and the rankings alike — whether the caller passes it as an option or left it
 * in the turn memo; and a reference says which record its file is filed on and
 * how many copies of the passage stand behind it.
 */
class FileContextProviderScopeTest extends TestCase
{
    /** @var list<array> the options each content search was made with */
    private array $searches = [];

    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai.file_context.min_relevance_score', 0.4);
        $app['config']->set('ai.file_context.max_references', 5);
        $app['config']->set('ai.file_context.context_window', 0);
        $app['config']->set('ai.file_context.security_enabled', true);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function provider(): FileContextProvider
    {
        $chunk = new FileChunk(20, 'declaration.pdf', 'Article 5', [], 4, 9, 0, 9, []);
        $law = new FileChunk('physical:laws/code.md', 'code.md', 'Art. 1063', [], 3, 9, 0, 9, []);

        $search = Mockery::mock(FileSearchService::class);
        $search->shouldReceive('searchByContent')->andReturnUsing(function (string $query, array $options) use ($chunk, $law): array {
            $this->searches[] = $options;

            return [
                ['file_id' => 20, 'score' => 0.8, 'chunk_count' => 1, 'best_chunk' => $chunk, 'chunks' => [$chunk],
                    'chunk_scores' => [4 => 0.8], 'owner' => 'unit:309', 'ancestors' => ['union:14'], 'duplicates' => [4 => 35]],
                ['file_id' => 'physical:laws/code.md', 'score' => 0.7, 'chunk_count' => 1, 'best_chunk' => $law, 'chunks' => [$law],
                    'chunk_scores' => [3 => 0.7], 'collection' => 'documentation_chunks'],
            ];
        });

        $access = Mockery::mock(FileAccessResolverInterface::class);
        $access->shouldReceive('shouldEnforceSecurity')->andReturn(true);
        $access->shouldReceive('isPhysicalFile')->andReturnUsing(fn ($id): bool => is_string($id) && str_starts_with($id, 'physical:'));
        $access->shouldReceive('getAccessibleFileIds')->andReturn([20]);
        $access->shouldReceive('filterAccessibleFileIds')->andReturnUsing(fn (array $ids): array => array_values($ids));

        $filenames = Mockery::mock(FilenameExtractor::class);
        $filenames->shouldReceive('extract')->andReturn([]);

        return new FileContextProvider($search, $access, $filenames, null, Mockery::mock(ChunkStoreInterface::class));
    }

    /** @test */
    public function the_scope_given_as_an_option_reaches_the_search_with_the_turn_memo(): void
    {
        $memo = new \ArrayObject();
        $scope = new DocumentScope('unit:310', ['union:14'], DocumentScope::PAGE);

        $this->provider()->searchRelevantFiles('pets', (object) ['id' => 1], [
            FileContextProvider::TURN_MEMO => $memo,
            FileContextProvider::SCOPE => $scope,
        ]);

        $this->assertSame(['focus' => 'unit:310', 'ancestors' => ['union:14']], $this->searches[0]['scope']);
        $this->assertSame($memo, $this->searches[0]['memo']);
        $this->assertSame([20], $this->searches[0]['acl_file_ids'], 'the ACL stays beside the scope');
    }

    /** @test */
    public function the_scope_left_in_the_turn_memo_reaches_the_passages_and_the_rankings(): void
    {
        $memo = new \ArrayObject([FileContextProvider::SCOPE => new DocumentScope('union:66', [], DocumentScope::CURRENT)]);
        $provider = $this->provider();
        $user = (object) ['id' => 1];

        $provider->searchRelevantFiles('pets', $user, [FileContextProvider::TURN_MEMO => $memo]);
        $provider->rankedChunks('animals', $user, [FileContextProvider::TURN_MEMO => $memo]);

        $this->assertCount(2, $this->searches);
        foreach ($this->searches as $options) {
            $this->assertSame(['focus' => 'union:66', 'ancestors' => []], $options['scope']);
        }
    }

    /** @test */
    public function an_unknown_scope_narrows_nothing(): void
    {
        $this->provider()->searchRelevantFiles('pets', (object) ['id' => 1], [FileContextProvider::SCOPE => DocumentScope::unknown()]);

        $this->assertArrayNotHasKey('scope', $this->searches[0]);
    }

    /** @test */
    public function a_reference_says_whose_document_it_is_and_how_many_copies_stand_behind_it(): void
    {
        $files = $this->provider()->searchRelevantFiles('pets', (object) ['id' => 1]);

        $upload = collect($files)->firstWhere('file_id', 20);
        $this->assertSame('unit:309', $upload['owner']);
        $this->assertSame(['union:14'], $upload['ancestors']);
        $this->assertSame(35, $upload['duplicates']);

        $project = collect($files)->firstWhere('file_id', 'physical:laws/code.md');
        $this->assertSame([], array_intersect(['owner', 'ancestors', 'duplicates'], array_keys($project)));
    }
}
