<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Console;

use Condoedge\Ai\Console\Commands\IngestEntitiesCommand;
use Condoedge\Ai\Contracts\GraphStoreInterface;
use Condoedge\Ai\Services\BatchDataIngestionService;
use Illuminate\Console\OutputStyle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Every node is written with MERGE on its id, and every relationship MATCHes both ends by id: without an
 * index on (label, id) each write scans the whole label, so an ingest slows down as the graph grows
 * (measured: 200 k orders, 2 000 a minute after the first 30 k). The ingest creates the index first.
 */
class IngestEntitiesCommandIndexTest extends TestCase
{
    /** @test */
    public function the_ingest_indexes_the_id_of_every_label_it_writes(): void
    {
        $queries = [];
        $graph = $this->createMock(GraphStoreInterface::class);
        $graph->method('query')->willReturnCallback(function (string $cypher) use (&$queries): array {
            $queries[] = $cypher;

            return [];
        });

        $command = new IngestEntitiesCommand($this->createMock(BatchDataIngestionService::class), $graph);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));

        $ensure = new \ReflectionMethod($command, 'ensureIdIndexes');
        $ensure->setAccessible(true);
        $ensure->invoke($command, ['Order', 'OrderLine', 'Order']);

        $this->assertSame([
            'CREATE INDEX ai_order_id IF NOT EXISTS FOR (n:`Order`) ON (n.id)',
            'CREATE INDEX ai_orderline_id IF NOT EXISTS FOR (n:`OrderLine`) ON (n.id)',
        ], $queries);
    }

    /** @test */
    public function an_index_the_store_refuses_does_not_stop_the_ingest(): void
    {
        $graph = $this->createMock(GraphStoreInterface::class);
        $graph->method('query')->willThrowException(new \RuntimeException('equivalent index exists'));

        $command = new IngestEntitiesCommand($this->createMock(BatchDataIngestionService::class), $graph);
        $buffer = new BufferedOutput();
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        $ensure = new \ReflectionMethod($command, 'ensureIdIndexes');
        $ensure->setAccessible(true);
        $ensure->invoke($command, ['Order']);

        $this->assertStringContainsString('Order', $buffer->fetch());
    }
}
