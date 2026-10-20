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
 * `ai:ingest --fresh` used to empty EVERY Qdrant collection after clearing the graph. Since
 * the ingest writes the graph only (2026-10-08), nothing it does rebuilds those collections:
 * a --fresh run would have destroyed the uploaded-file chunks, their document maps, the
 * context / scope / question indexes — and left them empty. --fresh now clears what the
 * ingest rebuilds, the graph, and nothing else.
 */
class IngestEntitiesCommandFreshTest extends TestCase
{
    /** @test */
    public function fresh_clears_the_graph_and_never_touches_the_vector_store(): void
    {
        $graph = $this->createMock(GraphStoreInterface::class);
        $queries = [];
        $graph->method('query')->willReturnCallback(function (string $cypher) use (&$queries): array {
            $queries[] = $cypher;

            return [['deleted' => 0]];
        });

        // The command does not even hold a vector store any more.
        foreach ((new \ReflectionMethod(IngestEntitiesCommand::class, '__construct'))->getParameters() as $parameter) {
            $this->assertStringNotContainsString('VectorStore', (string) $parameter->getType());
        }

        $command = new IngestEntitiesCommand($this->createMock(BatchDataIngestionService::class), $graph);
        $buffer = new BufferedOutput();
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        $clear = new \ReflectionMethod($command, 'clearStores');
        $clear->setAccessible(true);
        $clear->invoke($command, []);

        $this->assertCount(2, $queries, 'relationships, then nodes');
        $this->assertStringContainsString('DELETE', $queries[0]);
        $this->assertStringNotContainsString('Qdrant', $buffer->fetch());
    }
}
