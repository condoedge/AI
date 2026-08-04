<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;

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

    /**
     * How many rows this section renders at most — read by the ResponseGenerator's
     * truncation-honesty guard so trigger and prompt can never disagree.
     */
    public function getMaxItems(): int
    {
        return $this->maxItems;
    }

    public function format(PipelineContext $context, array $options = []): string
    {
        $data = $context->executionResult->data;

        if (empty($data)) {
            return "Results: No data returned\n\n";
        }

        $displayData = count($data) > $this->maxItems
            ? array_slice($data, 0, $this->maxItems)
            : $data;

        $output = "Results:\n"
            . $this->spotlight(json_encode($displayData, JSON_PRETTY_PRINT), 'retrieved_document', ['id' => 'results'])
            . "\n\n";

        // The disclosed total prefers the companion count's TRUE total (stamped by
        // AiManager when execution hit the row cap) over the retrieved row count,
        // so a cap-hit turn shows "first 10 of 250", never a false "of 100".
        $trueTotal = $context->executionResult->metadata['true_total'] ?? null;
        $total = is_numeric($trueTotal) && (int) $trueTotal >= count($data) ? (int) $trueTotal : count($data);

        if ($total > count($displayData)) {
            $shown = count($displayData);
            $remaining = $total - $shown;
            $output .= "(Showing first {$shown} of {$total} results, {$remaining} more not shown)\n\n";
        }

        return $output;
    }
}
