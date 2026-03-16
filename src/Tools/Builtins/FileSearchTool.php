<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tools\Builtins;

use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\Tools\ToolDefinition;
use Condoedge\Ai\Tools\ToolRegistry;

/**
 * Registers a semantic file search tool for the LLM.
 */
class FileSearchTool
{
    public function __construct(
        private readonly FileSearchService $fileSearch,
    ) {}

    /**
     * Register the file search tool in the registry.
     */
    public function register(ToolRegistry $registry): void
    {
        $registry->register(new ToolDefinition(
            name: 'search_files',
            description: 'Search files using semantic similarity. Finds relevant documents, PDFs, text files based on content meaning.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'The search query describing what content to find',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of results (default: 5)',
                    ],
                ],
                'required' => ['query'],
            ],
            handler: function (array $args, array $context): array {
                $query = $args['query'];
                $limit = $args['limit'] ?? 5;

                $options = ['limit' => $limit];

                if (isset($context['user'])) {
                    $options['user'] = $context['user'];
                }

                return $this->fileSearch->searchByContent($query, $options);
            },
        ));
    }
}
