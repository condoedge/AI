<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Console;

use Condoedge\Ai\Console\Commands\IngestEntitiesCommand;
use PHPUnit\Framework\TestCase;

/**
 * ai:ingest printed "Unknown error" for every failed row: BatchDataIngestionService reports
 * batch errors as plain STRINGS, but the command read $error['message'].
 */
class IngestEntitiesCommandErrorFormatTest extends TestCase
{
    /** @test */
    public function string_batch_errors_are_reported_verbatim(): void
    {
        $this->assertSame(
            'Vector ingestion failed',
            IngestEntitiesCommand::formatBatchError('Vector ingestion failed')
        );
    }

    /** @test */
    public function array_batch_errors_use_their_message(): void
    {
        $this->assertSame(
            'Neo4j timeout',
            IngestEntitiesCommand::formatBatchError(['message' => 'Neo4j timeout', 'id' => 7])
        );
    }

    /** @test */
    public function array_batch_errors_without_a_message_are_json_encoded_not_unknown(): void
    {
        $this->assertSame(
            '{"code":42,"id":7}',
            IngestEntitiesCommand::formatBatchError(['code' => 42, 'id' => 7])
        );
    }
}
