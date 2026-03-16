<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tools\Builtins;

use Condoedge\Ai\Contracts\QueryExecutorInterface;
use Condoedge\Ai\Tools\ToolDefinition;
use Condoedge\Ai\Tools\ToolRegistry;

/**
 * Registers a Cypher query execution tool for the LLM.
 * Queries go through QueryExecutor which enforces read-only mode, timeouts, and rate limiting.
 */
class QueryTool
{
    public function __construct(
        private readonly QueryExecutorInterface $queryExecutor,
    ) {}

    /**
     * Register the query tool in the registry.
     */
    public function register(ToolRegistry $registry): void
    {
        $registry->register(new ToolDefinition(
            name: 'execute_query',
            description: 'Execute a Cypher query against the Neo4j graph database. Read-only queries only. Use this to search, filter, aggregate, or explore data.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'cypher' => [
                        'type' => 'string',
                        'description' => 'The Cypher query to execute (read-only, no CREATE/DELETE/SET/MERGE)',
                    ],
                    'parameters' => [
                        'type' => 'object',
                        'description' => 'Optional query parameters for parameterized queries',
                    ],
                ],
                'required' => ['cypher'],
            ],
            handler: function (array $args, array $context): array {
                $cypher = $args['cypher'];
                $parameters = $args['parameters'] ?? [];

                $options = [];
                if (isset($context['user'])) {
                    $options['user'] = $context['user'];
                }

                return $this->queryExecutor->execute($cypher, $parameters, $options);
            },
        ));
    }
}
