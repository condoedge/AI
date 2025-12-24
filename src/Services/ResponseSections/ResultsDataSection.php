<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

/**
 * ResultsDataSection
 *
 * Shows the query results data.
 * Priority: 50
 */
class ResultsDataSection extends BaseResponseSection
{
    protected string $name = 'data';
    protected int $priority = 50;

    private int $maxItems = 10;

    /**
     * Set maximum items to show
     */
    public function setMaxItems(int $max): self
    {
        $this->maxItems = $max;
        return $this;
    }

    public function format(array $context, array $options = []): string
    {
        $data = $context['data'] ?? [];

        if (empty($data)) {
            return "Results: No data returned\n\n";
        }

        // Summarize if too large
        $displayData = count($data) > $this->maxItems
            ? array_slice($data, 0, $this->maxItems)
            : $data;

        $output = "Results:\n" . json_encode($displayData, JSON_PRETTY_PRINT) . "\n\n";

        if (count($data) > $this->maxItems) {
            $remaining = count($data) - $this->maxItems;
            $output .= "(Showing first {$this->maxItems} of " . count($data) . " results, {$remaining} more not shown)\n\n";
        }

        return $output;
    }
}
