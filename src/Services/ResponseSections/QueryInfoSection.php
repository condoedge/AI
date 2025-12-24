<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

/**
 * QueryInfoSection
 *
 * Shows the Cypher query that was executed.
 * Priority: 40
 */
class QueryInfoSection extends BaseResponseSection
{
    protected string $name = 'query';
    protected int $priority = 40;

    private bool $includeQuery = true;

    /**
     * Set whether to include the query in the prompt
     */
    public function setIncludeQuery(bool $include): self
    {
        $this->includeQuery = $include;
        return $this;
    }

    public function format(array $context, array $options = []): string
    {
        if (!$this->includeQuery) {
            return '';
        }

        $cypher = $context['cypher'] ?? '';

        if (empty($cypher)) {
            return '';
        }

        return "Query Executed:\n{$cypher}\n\n";
    }

    public function shouldInclude(array $context, array $options = []): bool
    {
        return $this->includeQuery && !empty($context['cypher']);
    }
}
