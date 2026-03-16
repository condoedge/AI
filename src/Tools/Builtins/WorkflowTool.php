<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tools\Builtins;

use Condoedge\Ai\Contracts\WorkflowHandlerInterface;
use Condoedge\Ai\Tools\ToolDefinition;
use Condoedge\Ai\Tools\ToolRegistry;

/**
 * Registers workflow triggering tools for the LLM.
 * Workflows are defined via config and implement WorkflowHandlerInterface.
 */
class WorkflowTool
{
    /** @var array<string, WorkflowHandlerInterface> */
    private array $workflows;

    /**
     * @param array<string, WorkflowHandlerInterface|string> $workflows Map of name => handler instance or class name
     */
    public function __construct(array $workflows = [])
    {
        $this->workflows = [];

        foreach ($workflows as $name => $handler) {
            if (is_string($handler) && class_exists($handler)) {
                $handler = app($handler);
            }

            if ($handler instanceof WorkflowHandlerInterface) {
                $this->workflows[$name] = $handler;
            }
        }
    }

    /**
     * Register workflow tools in the registry.
     */
    public function register(ToolRegistry $registry): void
    {
        if (empty($this->workflows)) {
            return;
        }

        // Register a single trigger_workflow tool that dispatches to the right handler
        $workflows = $this->workflows;

        $workflowNames = array_keys($workflows);
        $workflowDescriptions = array_map(
            fn(WorkflowHandlerInterface $w) => $w->getName() . ': ' . $w->getDescription(),
            $workflows
        );

        $registry->register(new ToolDefinition(
            name: 'trigger_workflow',
            description: 'Trigger a business workflow. Available workflows: ' . implode('; ', $workflowDescriptions),
            parameters: [
                'type' => 'object',
                'properties' => [
                    'workflow_name' => [
                        'type' => 'string',
                        'description' => 'The workflow to trigger',
                        'enum' => $workflowNames,
                    ],
                    'parameters' => [
                        'type' => 'object',
                        'description' => 'Parameters for the workflow',
                    ],
                ],
                'required' => ['workflow_name'],
            ],
            handler: function (array $args, array $context) use ($workflows): array {
                $workflowName = $args['workflow_name'];
                $parameters = $args['parameters'] ?? [];

                if (!isset($workflows[$workflowName])) {
                    throw new \RuntimeException("Unknown workflow: {$workflowName}");
                }

                return $workflows[$workflowName]->execute($parameters, $context);
            },
            requiresConfirmation: true,
        ));
    }
}
