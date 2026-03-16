<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat;

use Condoedge\Ai\Agents\AgentResolver;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\ToolAwareLlmProviderInterface;
use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Tools\ToolCallLoop;
use Condoedge\Ai\Tools\ToolRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Tool-aware chat service that extends AiChatService with tool calling capabilities.
 *
 * When the LLM provider supports tools and tools are enabled, this service
 * injects a tool calling loop that allows the LLM to call tools and process results
 * before generating the final response.
 */
class ToolAwareChatService extends AiChatService
{
    /**
     * {@inheritdoc}
     */
    public function askWithConversation(
        string $question,
        AiConversation $conversation,
        array $options = []
    ): array {
        // Check if tool calling is available
        if (!$this->isToolCallingAvailable()) {
            return parent::askWithConversation($question, $conversation, $options);
        }

        $options = array_merge($this->config, $options);

        // SECURITY: Require user context
        $user = $options['user'] ?? null;
        $allowAnonymous = $options['allow_anonymous'] ?? false;

        if ($user === null && !$allowAnonymous) {
            throw new \InvalidArgumentException(
                'User context required for AI queries. Pass user in options or set allow_anonymous=true for public queries.'
            );
        }

        try {
            $schema = $this->getSchema();
            $contextManager = $this->getContextManager();

            $contextResult = $contextManager->processQuestion($conversation, $question, $schema);

            // SECURITY: Check for prompt injection
            $injectionCheck = $this->checkForInjection($question, $conversation, $user);
            if ($injectionCheck !== null) {
                return $injectionCheck;
            }

            $conversationContext = $contextManager->buildPromptContext($conversation);
            $enrichedQuestion = $contextResult['enriched_question'] ?? $question;

            $conversation->addMessage('user', $question, [
                'context_used' => $conversationContext,
                'is_follow_up' => $contextResult['is_follow_up'] ?? false,
                'resolved_entity' => $contextResult['resolved_entity'] ?? null,
            ]);

            // Resolve agent
            $resolvedAgent = null;
            if (app()->bound(AgentResolver::class)) {
                $agentResolver = app(AgentResolver::class);
                $resolvedAgent = $agentResolver->resolve(
                    $conversation->agent_id,
                    $user?->id ?? null,
                    $conversation->team_id
                );
            }

            // Build messages for tool calling loop
            $messages = $this->buildToolCallMessages($enrichedQuestion, $conversationContext, $resolvedAgent, $options);

            // Get tools for this agent
            $toolRegistry = app(ToolRegistry::class);
            $tools = $resolvedAgent
                ? $toolRegistry->toOpenAiSchemaForAgent($resolvedAgent)
                : $toolRegistry->toOpenAiSchema();

            // Run tool calling loop
            $llm = app(LlmProviderInterface::class);
            $toolCallLoop = app(ToolCallLoop::class);

            $context = [
                'user' => $user,
                'resolved_agent' => $resolvedAgent,
                'conversation_id' => $conversation->id,
                'team_id' => $conversation->team_id,
            ];

            $loopResult = $toolCallLoop->run($llm, $messages, $tools, $context);

            $answerText = $loopResult->finalResponse;

            // Record response
            $contextManager->recordResponse($conversation, $answerText, '', [
                'data' => [],
                'referenced_files' => [],
                'tool_calls' => $loopResult->getToolNames(),
            ]);

            $conversation->addMessage('assistant', $answerText, [
                'tool_calls' => $loopResult->toolCallHistory,
                'tool_iterations' => $loopResult->iterations,
            ]);

            return [
                'answer' => $answerText,
                'data' => [],
                'suggestions' => [],
                'sources' => [],
                'cypher_query' => null,
                'tool_calls' => $loopResult->getToolNames(),
            ];

        } catch (\Exception $e) {
            Log::error('ToolAware chat error', [
                'question' => $question,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            $errorMessage = $this->getUserFriendlyError($e);

            $conversation->addMessage('assistant', $errorMessage, ['error' => true]);

            return [
                'answer' => $errorMessage,
                'data' => [],
                'suggestions' => [],
                'sources' => [],
                'cypher_query' => null,
            ];
        }
    }

    /**
     * Check if tool calling is available (provider supports it + tools enabled).
     */
    private function isToolCallingAvailable(): bool
    {
        if (!config('ai.tools.enabled', false)) {
            return false;
        }

        try {
            $llm = app(LlmProviderInterface::class);

            return $llm instanceof ToolAwareLlmProviderInterface && $llm->supportsTools();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Build the initial messages for the tool calling loop.
     */
    private function buildToolCallMessages(
        string $question,
        string $conversationContext,
        $resolvedAgent,
        array $options,
    ): array {
        $messages = [];

        // System prompt
        $systemPrompt = $resolvedAgent
            ? $resolvedAgent->getSystemPrompt()
            : ($options['system_prompt'] ?? $this->getDefaultSystemPrompt());

        $systemPrompt .= "\n\nYou have access to tools that let you search data, read entities, execute queries, and trigger workflows. "
            . "Use tools when you need to look up real data to answer the user's question. "
            . "Always prefer using tools over guessing or making up data.";

        if (!empty($conversationContext)) {
            $systemPrompt .= "\n\nConversation context:\n" . $conversationContext;
        }

        $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        $messages[] = ['role' => 'user', 'content' => $question];

        return $messages;
    }

    /**
     * Check for injection attempts (mirrors parent private method).
     */
    private function checkForInjection(string $question, AiConversation $conversation, $user): ?array
    {
        try {
            $inputSanitizer = app(\Condoedge\Ai\Services\Security\InputSanitizer::class);
            $inputAnalysis = $inputSanitizer->analyze($question);

            if ($inputAnalysis['has_injection_risk']) {
                Log::warning('Prompt injection attempt blocked in tool-aware chat', [
                    'conversation_id' => $conversation->id,
                    'user_id' => $user?->id,
                ]);

                return [
                    'answer' => 'I cannot process this request as it appears to contain instructions that could compromise system security.',
                    'data' => [],
                    'suggestions' => [],
                    'sources' => [],
                    'cypher_query' => null,
                ];
            }
        } catch (\Exception $e) {
            // InputSanitizer not available, skip check
        }

        return null;
    }

    /**
     * Get default system prompt.
     */
    private function getDefaultSystemPrompt(): string
    {
        $appName = config('app.name', 'Application');
        $projectDescription = config('ai.project.description', '');

        return "You are a helpful AI assistant for {$appName}. " .
            ($projectDescription ? "{$projectDescription} " : '') .
            "Answer questions clearly and concisely.";
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        $provider = config('ai.llm.default');

        if ($provider === 'ollama') {
            try {
                $llm = app(LlmProviderInterface::class);

                return $llm instanceof \Condoedge\Ai\LlmProviders\OllamaLlmProvider
                    && $llm->isReachable();
            } catch (\Exception $e) {
                return false;
            }
        }

        return parent::isAvailable();
    }
}
