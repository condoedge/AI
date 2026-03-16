<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * Interface for workflow handlers that can be triggered by the AI tool system.
 */
interface WorkflowHandlerInterface
{
    /**
     * Execute the workflow with given parameters.
     *
     * @param array $parameters Workflow parameters from the LLM
     * @param array $context Security and user context
     * @return array Result data
     * @throws \Exception If workflow execution fails
     */
    public function execute(array $parameters, array $context = []): array;

    /**
     * Get the workflow name for display/logging.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get the workflow description for the LLM.
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Get the JSON Schema for the workflow parameters.
     *
     * @return array
     */
    public function getParametersSchema(): array;
}
