<?php

declare(strict_types=1);

namespace Condoedge\Ai\Exceptions;

/**
 * DataConsistencyException
 *
 * Thrown when data consistency cannot be maintained across dual stores (Neo4j + Qdrant).
 * This exception indicates that an operation partially succeeded in one store but failed in another,
 * and the rollback/compensation was successful.
 *
 * Example Scenarios:
 * - Entity created in Neo4j but failed in Qdrant → rolled back from Neo4j
 * - Entity updated in Qdrant but failed in Neo4j → rolled back from Qdrant
 * - Relationship creation partially succeeded → compensated
 */
class DataConsistencyException extends \RuntimeException
{
    private array $context = [];

    public function __construct(
        string $message,
        array $context = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Get context information about the consistency failure
     *
     * @return array Context with keys: entity_id, graph_success, vector_success, operation
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Check if rollback was successful
     */
    public function wasRolledBack(): bool
    {
        return $this->context['rolled_back'] ?? false;
    }
}
