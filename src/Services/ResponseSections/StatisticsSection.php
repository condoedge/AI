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

        // Companion-count true total (stamped by AiManager when execution hit the
        // row cap): surface it beside the returned rows so the model knows the
        // real population size, not just the capped retrieval.
        $trueTotal = $stats['true_total'] ?? $context->executionResult->metadata['true_total'] ?? null;
        if (is_numeric($trueTotal) && (int) $trueTotal > (int) $rowCount) {
            $output .= "- Rows returned: {$rowCount} (of " . (int) $trueTotal . " total matching rows)\n";
        } else {
            $output .= "- Rows returned: {$rowCount}\n";
        }

        return $output . "\n";
    }

    public function shouldInclude(PipelineContext $context, array $options = []): bool
    {
        return !empty($context->executionResult->stats) || !empty($context->executionResult->data);
    }
}
