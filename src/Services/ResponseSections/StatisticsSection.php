<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * StatisticsSection
 *
 * Shows execution statistics.
 * Priority: 60
 */
class StatisticsSection extends BaseResponseSection
{
    protected string $name = 'statistics';
    protected int $priority = 60;

    public function format(PipelineContext $context, array $options = []): string
    {
        $stats = $context->executionResult->stats;
        $data = $context->executionResult->data;

        if (empty($stats) && empty($data)) {
            return '';
        }

        $output = "Statistics:\n";

        if (!empty($stats['execution_time_ms'])) {
            $output .= "- Execution time: {$stats['execution_time_ms']}ms\n";
        }

        $rowCount = $stats['rows_returned'] ?? count($data);
        $output .= "- Rows returned: {$rowCount}\n";

        return $output . "\n";
    }

    public function shouldInclude(PipelineContext $context, array $options = []): bool
    {
        return !empty($context->executionResult->stats) || !empty($context->executionResult->data);
    }
}
