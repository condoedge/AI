<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;

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

    public function format(PipelineContext $context, array $options = []): string
    {
        if (!$this->includeQuery) {
            return '';
        }

        $cypher = $context->queryResult->cypher;

        if (empty($cypher)) {
            return '';
        }

        return "Query Executed:\n{$cypher}\n\n";
    }

    public function shouldInclude(PipelineContext $context, array $options = []): bool
    {
        return $this->includeQuery && !empty($context->queryResult->cypher);
    }
}
