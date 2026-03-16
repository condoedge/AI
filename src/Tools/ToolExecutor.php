<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tools;

use Condoedge\Ai\Services\Security\AiSecurityService;
use Illuminate\Support\Facades\Log;

/**
 * Executes tools with parameter validation and security checks.
 */
class ToolExecutor
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly AiSecurityService $security,
    ) {}

    /**
     * Execute a tool call from the LLM.
     *
     * @param string $toolName The tool to execute
     * @param string $toolCallId The unique ID for this tool call
     * @param array $arguments Parsed arguments from the LLM
     * @param array $context Security context (user, team, etc.)
     * @return ToolResult
     */
    public function execute(string $toolName, string $toolCallId, array $arguments, array $context = []): ToolResult
    {
        $tool = $this->registry->get($toolName);

        if ($tool === null) {
            return ToolResult::error($toolName, $toolCallId, "Unknown tool: {$toolName}");
        }

        // Check required permissions
        if (!empty($tool->requiredPermissions) && !$this->checkPermissions($tool, $context)) {
            Log::warning('Tool execution denied: insufficient permissions', [
                'tool' => $toolName,
                'user_id' => $context['user']->id ?? null,
            ]);

            return ToolResult::error($toolName, $toolCallId, 'Insufficient permissions to execute this tool.');
        }

        // Check if confirmation is required for write operations
        if ($tool->requiresConfirmation && empty($context['confirmed'])) {
            return ToolResult::requiresConfirmation($toolName, $toolCallId, $arguments);
        }

        try {
            $handler = $tool->getHandler();
            $result = $handler($arguments, $context);

            return ToolResult::success($toolName, $toolCallId, $result);
        } catch (\Exception $e) {
            Log::error('Tool execution failed', [
                'tool' => $toolName,
                'error' => $e->getMessage(),
                'arguments' => $arguments,
            ]);

            return ToolResult::error($toolName, $toolCallId, 'Tool execution failed: ' . $e->getMessage());
        }
    }

    /**
     * Check if the current user has required permissions for the tool.
     */
    private function checkPermissions(ToolDefinition $tool, array $context): bool
    {
        $user = $context['user'] ?? null;

        if ($user === null) {
            return false;
        }

        foreach ($tool->requiredPermissions as $permission) {
            if (!$user->can($permission)) {
                return false;
            }
        }

        return true;
    }
}
