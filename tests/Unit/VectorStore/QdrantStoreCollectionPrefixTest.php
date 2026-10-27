<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\VectorStore;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\VectorStore\QdrantStore;

/**
 * Two hosts on one Qdrant: `collection_prefix` puts every collection a host names under its
 * own prefix (the package's fixed names included), and the host only ever sees its own.
 * No prefix (the default): the names are sent as they are.
 */
class QdrantStoreCollectionPrefixTest extends TestCase
{
    private function store(string $prefix, array $collections = []): QdrantStore
    {
        return new class(['host' => 'localhost', 'port' => 6333, 'collection_prefix' => $prefix], $collections) extends QdrantStore {
            public array $urls = [];

            public function __construct(array $config, private array $collections)
            {
                parent::__construct($config);
            }

            protected function send(string $method, string $url, array|object $data = []): array
            {
                $this->urls[] = "{$method} {$url}";

                return str_ends_with($url, '/collections')
                    ? ['result' => ['collections' => array_map(fn ($n) => ['name' => $n], $this->collections)]]
                    : ['result' => ['points' => [], 'count' => 0, 'status' => 'ok']];
            }
        };
    }

    /** @test */
    public function every_collection_is_named_under_the_prefix(): void
    {
        $store = $this->store('coolecto_');
        $store->search('documentation_chunks', [0.1], 5);
        $store->count('file_chunks');

        $this->assertSame([
            'POST http://localhost:6333/collections/coolecto_documentation_chunks/points/search',
            'POST http://localhost:6333/collections/coolecto_file_chunks/points/count',
        ], $store->urls);
    }

    /** @test */
    public function a_collection_exists_only_under_the_hosts_prefix(): void
    {
        $store = $this->store('coolecto_', ['questions', 'coolecto_documentation_chunks']);

        $this->assertTrue($store->collectionExists('documentation_chunks'));
        $this->assertFalse($store->collectionExists('questions'), "another host's collection");
    }

    /** @test */
    public function the_host_lists_only_its_own_collections_by_their_own_names(): void
    {
        $store = $this->store('coolecto_', ['questions', 'coolecto_questions', 'context_index', 'coolecto_documentation_chunks']);

        $this->assertSame(['questions', 'documentation_chunks'], $store->listCollections());
    }

    /** @test */
    public function without_a_prefix_nothing_changes(): void
    {
        $store = $this->store('', ['questions', 'context_index']);
        $store->count('questions');

        $this->assertSame(['POST http://localhost:6333/collections/questions/points/count'], $store->urls);
        $this->assertTrue($store->collectionExists('questions'));
        $this->assertSame(['questions', 'context_index'], $store->listCollections());
    }
}
