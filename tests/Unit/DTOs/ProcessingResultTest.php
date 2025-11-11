<?php

namespace Condoedge\Ai\Tests\Unit\DTOs;

use Condoedge\Ai\DTOs\ProcessingResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ProcessingResult DTO
 */
class ProcessingResultTest extends TestCase
{
    public function test_creates_successful_result()
    {
        $result = ProcessingResult::success(
            fileId: 1,
            chunksCreated: 10,
            embeddingsGenerated: 10,
            processingTimeSeconds: 5.5,
            metadata: ['file_size' => 1024]
        );

        $this->assertTrue($result->success);
        $this->assertEquals(1, $result->fileId);
        $this->assertEquals(10, $result->chunksCreated);
        $this->assertEquals(10, $result->embeddingsGenerated);
        $this->assertEquals(5.5, $result->processingTimeSeconds);
        $this->assertNull($result->error);
        $this->assertEquals(['file_size' => 1024], $result->metadata);
    }

    public function test_creates_failed_result()
    {
        $result = ProcessingResult::failure(
            fileId: 1,
            error: 'File not found',
            processingTimeSeconds: 0.5,
            metadata: ['attempted' => true]
        );

        $this->assertFalse($result->success);
        $this->assertEquals(1, $result->fileId);
        $this->assertEquals(0, $result->chunksCreated);
        $this->assertEquals(0, $result->embeddingsGenerated);
        $this->assertEquals('File not found', $result->error);
        $this->assertEquals(['attempted' => true], $result->metadata);
    }

    public function test_failed_method_returns_correct_value()
    {
        $successResult = ProcessingResult::success(1, 10, 10, 1.0);
        $failureResult = ProcessingResult::failure(1, 'Error');

        $this->assertFalse($successResult->failed());
        $this->assertTrue($failureResult->failed());
    }

    public function test_converts_to_array()
    {
        $result = ProcessingResult::success(
            fileId: 1,
            chunksCreated: 5,
            embeddingsGenerated: 5,
            processingTimeSeconds: 2.5
        );

        $array = $result->toArray();

        $this->assertArrayHasKey('success', $array);
        $this->assertArrayHasKey('file_id', $array);
        $this->assertArrayHasKey('chunks_created', $array);
        $this->assertArrayHasKey('embeddings_generated', $array);
        $this->assertArrayHasKey('processing_time_seconds', $array);
        $this->assertArrayHasKey('error', $array);
        $this->assertArrayHasKey('metadata', $array);

        $this->assertTrue($array['success']);
        $this->assertEquals(5, $array['chunks_created']);
    }

    public function test_calculates_processing_rate()
    {
        $result = ProcessingResult::success(
            fileId: 1,
            chunksCreated: 10,
            embeddingsGenerated: 10,
            processingTimeSeconds: 2.0
        );

        $this->assertEquals(5.0, $result->getProcessingRate());
    }

    public function test_processing_rate_handles_zero_time()
    {
        $result = ProcessingResult::success(
            fileId: 1,
            chunksCreated: 10,
            embeddingsGenerated: 10,
            processingTimeSeconds: 0.0
        );

        $this->assertEquals(0.0, $result->getProcessingRate());
    }

    public function test_generates_success_summary()
    {
        $result = ProcessingResult::success(
            fileId: 123,
            chunksCreated: 15,
            embeddingsGenerated: 15,
            processingTimeSeconds: 3.14159
        );

        $summary = $result->getSummary();

        $this->assertStringContainsString('Successfully processed', $summary);
        $this->assertStringContainsString('file 123', $summary);
        $this->assertStringContainsString('15 chunks', $summary);
        $this->assertStringContainsString('15 embeddings', $summary);
    }

    public function test_generates_failure_summary()
    {
        $result = ProcessingResult::failure(
            fileId: 456,
            error: 'Unsupported file type'
        );

        $summary = $result->getSummary();

        $this->assertStringContainsString('Processing failed', $summary);
        $this->assertStringContainsString('file 456', $summary);
        $this->assertStringContainsString('Unsupported file type', $summary);
    }

    public function test_handles_empty_metadata()
    {
        $result = ProcessingResult::success(
            fileId: 1,
            chunksCreated: 1,
            embeddingsGenerated: 1,
            processingTimeSeconds: 1.0
        );

        $this->assertEquals([], $result->metadata);
    }

    public function test_constructor_creates_result_with_all_fields()
    {
        $result = new ProcessingResult(
            success: true,
            fileId: 1,
            chunksCreated: 5,
            embeddingsGenerated: 5,
            processingTimeSeconds: 1.5,
            error: null,
            metadata: ['test' => true]
        );

        $this->assertTrue($result->success);
        $this->assertEquals(1, $result->fileId);
        $this->assertEquals(5, $result->chunksCreated);
        $this->assertNull($result->error);
    }
}
