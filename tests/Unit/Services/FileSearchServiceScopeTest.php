<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\Support\FileLink;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Mockery;

/**
 * UPLOADED files are searched within the question's scope and as distinct
 * passages; PROJECT files are global — searched on every turn, never narrowed
 * by a scope, never an ACL.
 */
class FileSearchServiceScopeTest extends TestCase
{
    /** @var array<string, array> collection ('' = the uploads) => the filters it was searched with */
    private array $filters = [];

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function service(array $uploads = []): FileSearchService
    {
        $store = Mockery::mock(ChunkStoreInterface::class);
        $store->shouldReceive('searchByContent')->andReturnUsing(function ($query, $limit, $filters, $collection = null) use ($uploads) {
            $this->filters[$collection ?? ''] = $filters;

            return $collection === null ? $uploads : [[
                'chunk' => new FileChunk('physical:laws/code.md', 'code.md', 'Art. 1063', [], 3, 9, 0, 9, []),
                'score' => 0.6,
            ]];
        });

        $builder = Mockery::mock('Illuminate\Database\Eloquent\Builder');
        $builder->shouldReceive('get')->andReturn(collect());
        $model = Mockery::mock(Model::class)->makePartial();
        $model->shouldReceive('whereIn')->andReturn($builder);
        $this->app->instance('file-model', $model);

        return new FileSearchService($store, Mockery::mock(GraphStoreInterface::class), ['documentation_chunks']);
    }

    /** @test */
    public function the_scope_and_the_distinct_search_apply_to_the_uploads_only(): void
    {
        $memo = new \ArrayObject();
        $scope = ['focus' => 'unit:310', 'ancestors' => ['union:14']];

        $results = $this->service()->searchByContent('pets', ['acl_file_ids' => [7], 'scope' => $scope, 'memo' => $memo]);

        $this->assertSame($scope, $this->filters['']['scope']);
        $this->assertTrue($this->filters['']['distinct']);
        $this->assertSame($memo, $this->filters['']['memo']);
        $this->assertSame([7], $this->filters['']['file_ids']);

        // The project collection: no scope, no ACL, no grouping — and still searched.
        $this->assertSame([], array_intersect(['scope', 'distinct', 'memo', 'file_ids'], array_keys($this->filters['documentation_chunks'])));
        $this->assertSame(['physical:laws/code.md'], array_column($results, 'file_id'));
    }

    /**
     * The same text filed on TWO records (the declaration on two units of one
     * building) is two candidates of the vector search — and ONE passage for the
     * turn: shown twice it took the slots of the other documents (live: no
     * passage of the law on a question that needed both). It is shown once, under
     * the copy with the lowest file id, and says every record it is filed on.
     *
     * @test
     */
    public function the_same_text_under_two_owners_is_one_passage_that_names_both(): void
    {
        $a = FileLink::payload(['owner' => 'unit:873', 'ancestors' => ['union:66']], 'Article 5');
        $b = FileLink::payload(['owner' => 'unit:947', 'ancestors' => ['union:66']], 'Article 5');
        $uploads = [
            // Whichever comes first, the lowest file id represents the text.
            ['chunk' => new FileChunk(53, 'declaration.pdf', "Article  5\n", [], 4, 9, 0, 9, ['link' => $b]), 'score' => 0.9, 'duplicates' => 1],
            ['chunk' => new FileChunk(49, 'declaration.pdf', 'Article 5', [], 4, 9, 0, 9, ['link' => $a]), 'score' => 0.9, 'duplicates' => 3],
            ['chunk' => new FileChunk(53, 'declaration.pdf', 'Article 6', [], 5, 9, 0, 9, ['link' => $b]), 'score' => 0.7, 'duplicates' => 1],
        ];

        $results = collect($this->service($uploads)->searchByContent('destination', []));

        $kept = $results->firstWhere('file_id', 49);
        $this->assertSame([4 => 0.9], $kept['chunk_scores']);
        $this->assertSame('unit:873', $kept['owner']);
        $this->assertSame([4 => [['owner' => 'unit:947', 'ancestors' => ['union:66']]]], $kept['co_owners']);
        $this->assertSame([4 => 3], $kept['duplicates']);

        // File 53 keeps only the passage no other record holds.
        $other = $results->firstWhere('file_id', 53);
        $this->assertSame([5], array_keys($other['chunk_scores']));
        $this->assertArrayNotHasKey('co_owners', $other);
    }

    /** @test */
    public function distinct_search_can_be_switched_off(): void
    {
        config(['ai.file_context.dedupe' => false]);

        $this->service()->searchByContent('pets', []);

        $this->assertArrayNotHasKey('distinct', $this->filters['']);
    }

    /** @test */
    public function a_result_carries_the_link_of_its_file_and_the_copies_behind_each_chunk(): void
    {
        $link = FileLink::payload(['owner' => 'unit:309', 'ancestors' => ['union:14']], 'Article 5');
        $uploads = [
            ['chunk' => new FileChunk(20, 'declaration.pdf', 'Article 5', [], 4, 9, 0, 9, ['link' => $link]), 'score' => 0.9, 'duplicates' => 35],
            ['chunk' => new FileChunk(20, 'declaration.pdf', 'Article 6', [], 5, 9, 0, 9, ['link' => $link]), 'score' => 0.8, 'duplicates' => 0],
        ];

        $results = $this->service($uploads)->searchByContent('destination', []);
        $upload = collect($results)->firstWhere('file_id', 20);

        $this->assertSame('unit:309', $upload['owner']);
        $this->assertSame(['union:14'], $upload['ancestors']);
        $this->assertSame([4 => 35], $upload['duplicates']);

        $project = collect($results)->firstWhere('file_id', 'physical:laws/code.md');
        $this->assertArrayNotHasKey('owner', $project);
    }
}
