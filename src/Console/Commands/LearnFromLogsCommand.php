<?php
// src/Console/Commands/LearnFromLogsCommand.php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands;

use Condoedge\Ai\Services\Learning\QueryLearner;
use Illuminate\Console\Command;

class LearnFromLogsCommand extends Command
{
    protected $signature = 'ai:learn
                            {--min-confidence=80 : Minimum confidence score to learn from}
                            {--limit=100 : Maximum queries to process}';

    protected $description = 'Learn from successful queries in the logs';

    public function handle(QueryLearner $learner): int
    {
        $this->info('Learning from successful queries...');

        $result = $learner->learnFromLogs(
            minConfidence: (int) $this->option('min-confidence'),
            limit: (int) $this->option('limit')
        );

        $this->info("Processed: {$result['processed']}");
        $this->info("Learned: {$result['learned']}");
        $this->info("Skipped (already known): {$result['skipped']}");

        return self::SUCCESS;
    }
}
