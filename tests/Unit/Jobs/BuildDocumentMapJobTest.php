<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Jobs;

use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Jobs\BuildDocumentMapJob;
use Condoedge\Ai\Services\Files\DocumentMapBuilder;
use Condoedge\Ai\Support\DocumentMap;
use Condoedge\Ai\Tests\Fixtures\CallbackUsageLlm;
use Condoedge\Ai\Tests\Fixtures\InMemoryMapChunkStore;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;

/**
 * BuildDocumentMapJob: builds the map of one file on the queue, in SLICES short
 * enough for a queue whose jobs are handed out again after 90 seconds — each
 * slice re-dispatches the next one — and never two at a time for one file. A map
 * is an annotation of an ingest that already succeeded: the job never throws for
 * it, it retries what failed a bounded number of times and says what is left.
 */
class BuildDocumentMapJobTest extends TestCase
{
    /** @param int $count Content chunks of file 12 */
    private function store(int $count = 1): InMemoryMapChunkStore
    {
        $store = new InMemoryMapChunkStore();
        $chunks = [];
        for ($index = 0; $index < $count; $index++) {
            $content = "Passage {$index}: a contact is a person recorded in the directory.";
            $chunks[] = new FileChunk(12, 'guide.md', $content, [], $index, $count, 0, mb_strlen($content), ['heading_path' => ['Guide'], 'is_preamble' => false]);
        }
        $store->put(12, $chunks, 'file_chunks');
        $store->search = function (string $query, int $limit, array $filters, ?string $collection, InMemoryMapChunkStore $s) {
            preg_match('/passage (\d+)/', $query, $m);

            return [['chunk' => $s->chunk(12, (int) $m[1], $collection), 'score' => 0.8]];
        };

        return $store;
    }

    private function llm(?callable $override = null): CallbackUsageLlm
    {
        return new CallbackUsageLlm(function (array $messages, array $options, int $call) use ($override) {
            if ($override !== null && ($reply = $override($messages, $options, $call)) !== null) {
                return $reply;
            }
            preg_match('/Passage (\d+):/', $messages[1]['content'], $m);

            return ['AA' => ['gist' => "Who counts as a contact (passage {$m[1]})."]];
        });
    }

    private function builder(InMemoryMapChunkStore $store, CallbackUsageLlm $llm): DocumentMapBuilder
    {
        return new DocumentMapBuilder($llm, $llm, $store);
    }

    /** @test */
    public function it_builds_the_map_of_its_file_in_its_collection_and_stops_when_nothing_is_left(): void
    {
        Bus::fake();
        $store = $this->store();
        $job = new BuildDocumentMapJob(12, 'file_chunks');

        $job->handle($this->builder($store, $this->llm()));

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame('Who counts as a contact (passage 0).', $store->chunk(12, 0, 'file_chunks')->metadata['map']['gist']);
        $this->assertTrue(DocumentMap::isUsable($store->getFileChunks(12, 'file_chunks')));
        $this->assertSame(['ai-document-map', 'file:12'], $job->tags());
        Bus::assertNotDispatched(BuildDocumentMapJob::class);
    }

    /** @test */
    public function a_slice_fits_a_queue_that_hands_a_job_out_again_after_ninety_seconds(): void
    {
        $job = new BuildDocumentMapJob(12, 'file_chunks');

        $this->assertLessThan(90, $job->timeout, 'the worker kills the slice before the queue re-reserves it');
        $this->assertLessThanOrEqual($job->timeout - 30, BuildDocumentMapJob::SLICE_SECONDS, 'room for the last passage and the scope pass');
    }

    /** @test */
    public function a_slice_that_ran_out_of_time_dispatches_the_next_one_at_once(): void
    {
        Bus::fake();
        $store = $this->store(3);
        $llm = $this->llm();
        $job = (new BuildDocumentMapJob(12, 'file_chunks'))->onConnection('database')->onQueue('files');
        $job->sliceSeconds = 0; // no time: one passage per slice

        $job->handle($this->builder($store, $llm));

        $this->assertCount(1, $llm->calls, 'the slice did its one passage');
        Bus::assertDispatched(BuildDocumentMapJob::class, function (BuildDocumentMapJob $next): bool {
            return $next->fileId === 12 && $next->collection === 'file_chunks' && $next->attempt === 0
                && $next->sliceSeconds === 0 && $next->connection === 'database' && $next->queue === 'files' && $next->delay === null;
        });

        // Slice after slice the file is completed, each passage paid for once.
        for ($slice = 0; $slice < 3; $slice++) {
            $next = new BuildDocumentMapJob(12, 'file_chunks');
            $next->sliceSeconds = 0;
            $next->handle($this->builder($store, $llm));
        }
        $this->assertCount(3, $llm->calls);
        $this->assertTrue(DocumentMap::isUsable($store->getFileChunks(12, 'file_chunks')));
        Bus::assertDispatchedTimes(BuildDocumentMapJob::class, 2);
    }

    /** @test */
    public function what_failed_is_retried_later_with_a_backoff_a_bounded_number_of_times(): void
    {
        Bus::fake();
        Log::spy();
        $store = $this->store(2);
        $down = fn () => $this->llm(function () {
            throw new \RuntimeException('429 rate limited');
        });

        (new BuildDocumentMapJob(12, 'file_chunks'))->handle($this->builder($store, $down()));

        $this->assertSame('error', $store->chunk(12, 0, 'file_chunks')->metadata['map']['failed'], 'the attempt is recorded');
        Bus::assertDispatched(BuildDocumentMapJob::class, fn (BuildDocumentMapJob $next): bool => $next->attempt === 1 && $next->delay === 30);

        // The retries take the failed passages up again…
        $llm = $down();
        (new BuildDocumentMapJob(12, 'file_chunks', 1))->handle($this->builder($store, $llm));
        $this->assertCount(2, $llm->calls);
        Bus::assertDispatched(BuildDocumentMapJob::class, fn (BuildDocumentMapJob $next): bool => $next->attempt === 2 && $next->delay === 60);

        // …and the last one gives up, says so, and dispatches nothing more.
        $dispatchedBefore = count(Bus::dispatched(BuildDocumentMapJob::class));
        (new BuildDocumentMapJob(12, 'file_chunks', BuildDocumentMapJob::MAX_RETRIES))->handle($this->builder($store, $down()));
        $this->assertCount($dispatchedBefore, Bus::dispatched(BuildDocumentMapJob::class));
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context): bool => str_contains($message, 'left incomplete') && $context['file_id'] === 12
        )->once();
    }

    /** @test */
    public function a_retry_that_succeeds_completes_the_file(): void
    {
        Bus::fake();
        $store = $this->store(2);
        (new BuildDocumentMapJob(12, 'file_chunks'))->handle($this->builder($store, $this->llm(function () {
            throw new \RuntimeException('429 rate limited');
        })));

        (new BuildDocumentMapJob(12, 'file_chunks', 1))->handle($this->builder($store, $this->llm()));

        $this->assertTrue(DocumentMap::isUsable($store->getFileChunks(12, 'file_chunks')));
        Bus::assertDispatchedTimes(BuildDocumentMapJob::class, 1);
    }

    /** @test */
    public function two_workers_never_build_the_same_file_at_once(): void
    {
        Bus::fake();
        $store = $this->store(2);
        $llm = $this->llm();
        $busy = Cache::lock(DocumentMapBuilder::lockKey(12, 'file_chunks'), 60);
        $this->assertTrue($busy->get(), 'another worker is on the file');

        $queued = Mockery::mock(Job::class);
        $queued->shouldReceive('release')->once()->with(BuildDocumentMapJob::BUSY_DELAY);
        $job = new BuildDocumentMapJob(12, 'file_chunks');
        $job->setJob($queued);
        $job->handle($this->builder($store, $llm));

        $this->assertCount(0, $llm->calls, 'nothing is paid for twice');
        $this->assertSame([], $store->writes);
        Bus::assertNotDispatched(BuildDocumentMapJob::class);

        // Once the other worker is done, the job runs.
        $busy->release();
        (new BuildDocumentMapJob(12, 'file_chunks'))->handle($this->builder($store, $llm));
        $this->assertCount(2, $llm->calls);
        $this->assertTrue(Cache::lock(DocumentMapBuilder::lockKey(12, 'file_chunks'), 5)->get(), 'the lock is given back');
    }

    /** @test */
    public function nothing_runs_when_the_feature_is_switched_off(): void
    {
        Bus::fake();
        config(['ai.file_context.document_map.enabled' => false]);
        $store = $this->store();
        $llm = $this->llm();

        (new BuildDocumentMapJob(12, 'file_chunks'))->handle($this->builder($store, $llm));

        $this->assertCount(0, $llm->calls);
        $this->assertSame([], $store->writes);
    }

    /** @test */
    public function it_survives_being_serialized_onto_a_queue(): void
    {
        $job = unserialize(serialize(new BuildDocumentMapJob('physical:docs/guide.md', 'documentation_chunks', 2)));

        $this->assertSame('physical:docs/guide.md', $job->fileId);
        $this->assertSame('documentation_chunks', $job->collection);
        $this->assertSame(2, $job->attempt);
    }

    /** @test */
    public function whatever_still_escapes_is_swallowed(): void
    {
        Bus::fake();
        // The report cannot be logged: even that must not fail the job.
        Log::shouldReceive('info')->andThrow(new \RuntimeException('log channel down'));
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
        $store = $this->store();

        (new BuildDocumentMapJob(12, 'file_chunks'))->handle($this->builder($store, $this->llm()));

        $this->assertCount(1, $store->writes);
    }
}
