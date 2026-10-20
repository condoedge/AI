<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\VectorStore;

use Condoedge\Ai\VectorStore\QdrantStore;
use Condoedge\Ai\Tests\TestCase;

/**
 * Unit tests for QdrantStore payload-index creation (Task B1).
 *
 * Uses a test double that overrides the protected request() method so no real
 * HTTP/Qdrant instance is needed — we just capture the API calls QdrantStore
 * makes and assert on them.
 */
class QdrantStorePayloadIndexTest extends TestCase
{
    /**
     * Build a QdrantStore subclass that records every request() call and returns
     * scripted responses keyed by "METHOD endpoint".
     *
     * @param array<string,mixed> $responses
     * @return object{store: QdrantStore, calls: array}
     */
    private function makeRecordingStore(array $responses = []): object
    {
        $calls = [];

        $store = new class($responses, $calls) extends QdrantStore {
            public array $recordedCalls = [];
            private array $scripted;

            public function __construct(array $scripted, array &$calls)
            {
                parent::__construct(['host' => 'localhost', 'port' => 6333]);
                $this->scripted = $scripted;
            }

            protected function request(string $method, string $endpoint, array|object $data = []): array
            {
                $this->recordedCalls[] = [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'data' => $data,
                ];

                $key = "{$method} {$endpoint}";
                if (array_key_exists($key, $this->scripted)) {
                    $response = $this->scripted[$key];
                    if ($response instanceof \Throwable) {
                        throw $response;
                    }
                    return $response;
                }

                // Default: a successful generic response.
                return ['result' => true];
            }
        };

        return (object) ['store' => $store, 'wrapper' => $store];
    }

    public function test_create_collection_issues_payload_index_for_file_name(): void
    {
        $bundle = $this->makeRecordingStore([
            'PUT /collections/test_chunks' => ['result' => true],
        ]);
        $store = $bundle->store;

        $result = $store->createCollection('test_chunks', 1536, 'cosine');

        $this->assertTrue($result);

        // Find the index-creation call.
        $indexCalls = array_values(array_filter(
            $store->recordedCalls,
            fn ($c) => $c['method'] === 'PUT' && $c['endpoint'] === '/collections/test_chunks/index'
        ));

        $this->assertCount(1, $indexCalls, 'Expected exactly one PUT to the index endpoint');
        $this->assertEquals('file_name', $indexCalls[0]['data']['field_name']);
        $this->assertEquals('text', $indexCalls[0]['data']['field_schema']);
    }

    public function test_create_collection_does_not_index_when_creation_fails(): void
    {
        $bundle = $this->makeRecordingStore([
            'PUT /collections/test_chunks' => ['result' => false],
        ]);
        $store = $bundle->store;

        $result = $store->createCollection('test_chunks', 1536, 'cosine');

        $this->assertFalse($result);

        $indexCalls = array_filter(
            $store->recordedCalls,
            fn ($c) => $c['endpoint'] === '/collections/test_chunks/index'
        );

        $this->assertCount(0, $indexCalls, 'Index must not be created when collection creation fails');
    }

    public function test_create_collection_succeeds_even_if_index_creation_throws(): void
    {
        // The index PUT 404s/errors, but createCollection must still succeed.
        $bundle = $this->makeRecordingStore([
            'PUT /collections/test_chunks' => ['result' => true],
            'PUT /collections/test_chunks/index' => new \RuntimeException('Qdrant returned error 404'),
        ]);
        $store = $bundle->store;

        $result = $store->createCollection('test_chunks', 1536, 'cosine');

        $this->assertTrue($result, 'A failed payload-index call must not abort collection creation');
    }

    public function test_ensure_payload_index_returns_false_on_error(): void
    {
        $bundle = $this->makeRecordingStore([
            'PUT /collections/c/index' => new \RuntimeException('boom'),
        ]);
        $store = $bundle->store;

        $this->assertFalse($store->ensurePayloadIndex('c', 'file_name', 'text'));
    }

    public function test_scroll_returns_points_from_result(): void
    {
        $bundle = $this->makeRecordingStore([
            'POST /collections/c/points/scroll' => [
                'result' => [
                    'points' => [
                        ['id' => 1, 'payload' => ['file_name' => 'a.txt']],
                        ['id' => 2, 'payload' => ['file_name' => 'b.txt']],
                    ],
                ],
            ],
        ]);
        $store = $bundle->store;

        $points = $store->scroll('c', [], 100);

        $this->assertCount(2, $points);
        $this->assertEquals('a.txt', $points[0]['payload']['file_name']);
    }
}
