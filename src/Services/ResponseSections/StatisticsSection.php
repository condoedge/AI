<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

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

    public function format(array $context, array $options = []): string
    {
        $stats = $context['stats'] ?? [];
        $data = $context['data'] ?? [];

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

    public function shouldInclude(array $context, array $options = []): bool
    {
        return !empty($context['stats']) || !empty($context['data']);
    }
}
