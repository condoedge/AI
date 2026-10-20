<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands\Concerns;

use Condoedge\Ai\Services\Schema\GraphSchemaCache;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * For commands that change the graph or the indexes the prompt is built from
 * (ai:ingest, ai:sync-relationships, ai:setup, ai:index-*, ai:process-files):
 * flush the cached graph schema + per-label property samples once the command
 * finishes — on EVERY exit path, success or failure (a partial run still changed
 * the graph). Same effect as AI::flushSchemaCache(), without resolving the whole
 * AI manager from a console command (Task 5.3).
 */
trait FlushesGraphSchemaCache
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::execute($input, $output);
        } finally {
            (new GraphSchemaCache())->flush();
        }
    }
}
