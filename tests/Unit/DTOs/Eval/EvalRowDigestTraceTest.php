<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\DTOs\Eval;

use Condoedge\Ai\DTOs\Eval\EvalRow;
use PHPUnit\Framework\TestCase;

/**
 * Digest turns need NO EvalRow schema change (design §4.3): the composer's
 * metadata.response.digest block rides the existing responseMetadata channel
 * into trace.response_metadata VERBATIM — per-source row counts, true totals,
 * fallback/error markers, skipped sources (B2) and the combined-turn export
 * acknowledgment (B1) are all gradable out-of-process from the trace alone.
 */
class EvalRowDigestTraceTest extends TestCase
{
    /** @test */
    public function the_digest_metadata_rides_the_trace_verbatim_for_out_of_process_grading(): void
    {
        $row = new EvalRow(question: 'What should I take care of next?', tags: ['suite']);
        $row->conversationId = 'whats-next-digest';
        $row->turnIndex = 0;
        $row->responseMetadata = [
            'digest' => [
                'sources' => [
                    ['key' => 'open_tasks', 'row_count' => 5, 'true_total' => 130, 'used_fallback' => false, 'error' => null],
                    ['key' => 'upcoming_meetings', 'row_count' => 3, 'true_total' => null, 'used_fallback' => true, 'error' => null],
                    ['key' => 'broken', 'row_count' => 0, 'true_total' => null, 'used_fallback' => false, 'error' => 'rate limit'],
                ],
                'empty_sources' => ['bills_pending_approval', 'upcoming_meetings'],
                'failed_sources' => ['broken'],
                'skipped_sources' => [],
                'export_followup_offered' => 'excel',
            ],
            'digest_truncation_appended' => ['open_tasks'],
        ];

        $trace = $row->toArray()['trace'];

        $digest = $trace['response_metadata']['digest'];
        $this->assertSame('open_tasks', $digest['sources'][0]['key']);
        $this->assertSame(130, $digest['sources'][0]['true_total']);
        $this->assertTrue($digest['sources'][1]['used_fallback']);
        $this->assertSame('rate limit', $digest['sources'][2]['error']);
        $this->assertSame(['broken'], $digest['failed_sources']);
        $this->assertSame([], $digest['skipped_sources']);
        $this->assertSame('excel', $digest['export_followup_offered'], 'B1 acknowledgment is gradable from the trace');
        $this->assertSame(['open_tasks'], $trace['response_metadata']['digest_truncation_appended']);
    }
}
