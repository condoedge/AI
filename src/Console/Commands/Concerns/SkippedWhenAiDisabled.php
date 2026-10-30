<?php

declare(strict_types=1);

namespace Condoedge\Ai\Console\Commands\Concerns;

use Condoedge\Ai\Support\AiFeature;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * For commands that write to, or talk to, the graph, the vectors or a provider: while the
 * master switch is off (AI_ENABLED=false) they say so and succeed, so a deploy script or a
 * scheduler that runs them carries on.
 */
trait SkippedWhenAiDisabled
{
    public function run(InputInterface $input, OutputInterface $output): int
    {
        if (!AiFeature::enabled()) {
            $output->writeln('AI is disabled (AI_ENABLED=false): nothing was done');

            return self::SUCCESS;
        }

        return parent::run($input, $output);
    }
}
