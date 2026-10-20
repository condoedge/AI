<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\VectorStore;

use Condoedge\Ai\Contracts\UpdatesPayloadInterface;
use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\VectorStore\QdrantStore;

/**
 * QdrantStore::setPayload() — the additive payload write used to attach data to
 * points that already exist (their vectors and other payload keys stay as they
 * are). A test double overrides the protected request() so the Qdrant REST call
 * is captured instead of sent.
 */
class QdrantStoreSetPayloadTest extends TestCase
{
    /**
     * @param array<string,mixed> $responses Scripted responses keyed by "METHOD endpoint"
     */
    private function makeRecordingStore(array $responses = []): QdrantStore
    {
        return new class($responses) extends QdrantStore {
            public array $recordedCalls = [];

            public function __construct(private array $scripted)
            {
                parent::__construct(['host' => 'localhost', 'port' => 6333]);
            }

            protected function request(string $method, string $endpoint, array|object $data = []): array
            {
                $this->recordedCalls[] = ['method' => $method, 'endpoint' => $endpoint, 'data' => $data];

                $response = $this->scripted["{$method} {$endpoint}"] ?? ['result' => ['status' => 'completed']];
                if ($response instanceof \Throwable) {
                    throw $response;
                }

                return $response;
            }
        };
    }

    public function test_the_store_advertises_the_payload_update_capability(): void
    {
        $this->assertInstanceOf(UpdatesPayloadInterface::class, $this->makeRecordingStore());
    }

    public function test_set_payload_posts_the_payload_and_point_ids_synchronously(): void
    {
        $store = $this->makeRecordingStore();

        $result = $store->setPayload('file_chunks', ['map' => ['v' => 1, 'scope' => 'out']], ['id-a', 'id-b']);

        $this->assertTrue($result);
        $this->assertCount(1, $store->recordedCalls);
        $this->assertSame('POST', $store->recordedCalls[0]['method']);
        // wait=true: the write must be visible to a read that follows immediately.
        $this->assertSame('/collections/file_chunks/points/payload?wait=true', $store->recordedCalls[0]['endpoint']);
        $this->assertSame([
            'payload' => ['map' => ['v' => 1, 'scope' => 'out']],
            'points' => ['id-a', 'id-b'],
        ], $store->recordedCalls[0]['data']);
    }

    public function test_set_payload_sends_the_ids_as_a_json_list_even_when_the_array_is_keyed(): void
    {
        $store = $this->makeRecordingStore();

        // e.g. the survivors of an array_filter — a keyed array would encode as a
        // JSON object, which Qdrant rejects for "points".
        $store->setPayload('file_chunks', ['map' => ['v' => 1]], [3 => 'id-a', 7 => 'id-b']);

        $this->assertSame(['id-a', 'id-b'], $store->recordedCalls[0]['data']['points']);
    }

    public function test_set_payload_with_no_ids_succeeds_without_a_request(): void
    {
        $store = $this->makeRecordingStore();

        $this->assertTrue($store->setPayload('file_chunks', ['map' => ['v' => 1]], []));
        $this->assertSame([], $store->recordedCalls);
    }

    public function test_set_payload_returns_false_when_qdrant_does_not_confirm_the_write(): void
    {
        $store = $this->makeRecordingStore([
            'POST /collections/file_chunks/points/payload?wait=true' => ['result' => ['status' => 'failed']],
        ]);

        $this->assertFalse($store->setPayload('file_chunks', ['map' => ['v' => 1]], ['id-a']));
    }

    public function test_set_payload_reports_a_request_failure_like_the_other_writes(): void
    {
        $store = $this->makeRecordingStore([
            'POST /collections/file_chunks/points/payload?wait=true' => new \RuntimeException('Qdrant returned error 404'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Failed to set payload in 'file_chunks'");

        $store->setPayload('file_chunks', ['map' => ['v' => 1]], ['id-a']);
    }
}
