<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat;

use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\Context\ConversationContextManager;
use Condoedge\Ai\Services\Context\EntityExtractor;
use Condoedge\Ai\Services\Context\ReferenceResolver;
use Illuminate\Support\Facades\Log;

/**
 * Default AI Chat service implementation.
 * Bridges the chat UI with the core AI system.
 */
class AiChatService implements AiChatServiceInterface
{
    /**
     * Context manager instance (lazy-loaded)
     */
    protected ?ConversationContextManager $contextManager = null;

    public function __construct(
        protected array $config = [],
    ) {
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Get the conversation context manager instance.
     * Lazy-loads the manager on first access.
     */
    public function getContextManager(): ConversationContextManager
    {
        if ($this->contextManager === null) {
            $this->contextManager = new ConversationContextManager(
                new EntityExtractor(),
                new ReferenceResolver()
            );
        }

        return $this->contextManager;
    }

    /**
     * Get default configuration.
     */
    protected function getDefaultConfig(): array
    {
        return [
            'style' => config('ai.response_generation.default_style', 'friendly'),
            'include_suggestions' => true,
            'include_metrics' => config('ai.chat.show_metrics', false),
            'max_suggestions' => 3,
            'max_history_messages' => config('ai.chat.max_history_messages', 10),
            'system_prompt' => config('ai.chat.system_prompt', $this->getDefaultSystemPrompt()),
        ];
    }

    /**
     * Get default system prompt for chat.
     */
    protected function getDefaultSystemPrompt(): string
    {
        $appName = config('app.name', 'Application');
        $projectDescription = config('ai.project.description', '');

        return "You are a helpful AI assistant for {$appName}. " .
            ($projectDescription ? "{$projectDescription} " : '') .
            "Answer questions clearly and concisely. If you don't know something, say so.";
    }

    /**
     * {@inheritdoc}
     */
    public function askWithConversation(
        string $question,
        AiConversation $conversation,
        array $options = []
    ): array {
        $options = array_merge($this->config, $options);

        // SECURITY: Require user context unless explicitly marked as anonymous-safe
        $user = $options['user'] ?? null;
        $allowAnonymous = $options['allow_anonymous'] ?? false;

        if ($user === null && !$allowAnonymous) {
            throw new \InvalidArgumentException(
                'User context required for AI queries. Pass user in options or set allow_anonymous=true for public queries.'
            );
        }

        try {
            // 1. Get schema for entity extraction
            $schema = $this->getSchema();
            $contextManager = $this->getContextManager();

            // 2. Process question through context system
            // This extracts entities, resolves references, updates context
            $contextResult = $contextManager->processQuestion($conversation, $question, $schema);

            // 3. Build conversation context for the prompt
            $conversationContext = $contextManager->buildPromptContext($conversation);

            // 4. Use enriched question if references were resolved
            $enrichedQuestion = $contextResult['enriched_question'] ?? $question;

            // Store the user message
            $conversation->addMessage('user', $question, [
                'context_used' => $conversationContext,
                'is_follow_up' => $contextResult['is_follow_up'] ?? false,
                'resolved_entity' => $contextResult['resolved_entity'] ?? null,
            ]);

            // 5. Call AI with full context
            $aiResponse = AI::answerQuestion($enrichedQuestion, [
                'style' => $options['style'] ?? 'friendly',
                'conversation_id' => $conversation->id,
                'conversation_context' => $conversationContext,
                'user' => $options['user'] ?? null,
            ]);

            // Extract response data
            $answerText = $aiResponse['answer'] ?? '';
            $cypherQuery = $aiResponse['cypher'] ?? null;
            $queryData = $aiResponse['data'] ?? [];

            // 6. Record response with enhanced context tracking
            // Always record to update conversation context, even without cypher
            // Include file references for follow-up resolution
            $contextManager->recordResponse(
                $conversation,
                $answerText,
                $cypherQuery ?? '',
                [
                    'data' => $queryData,
                    'referenced_files' => $aiResponse['referenced_files'] ?? [],
                ]
            );

            // 7. Store message in conversation
            $conversation->addMessage('assistant', $answerText, [
                'response_data' => $queryData,
                'cypher_query' => $cypherQuery,
                'suggestions' => $aiResponse['suggestions'] ?? [],
                'referenced_files' => $aiResponse['referenced_files'] ?? [],
            ]);

            // 8. Return structured response
            return [
                'answer' => $answerText,
                'data' => $queryData,
                'suggestions' => $aiResponse['suggestions'] ?? [],
                'sources' => $aiResponse['referenced_files'] ?? [],
                'cypher_query' => $cypherQuery,
            ];

        } catch (\Exception $e) {
            Log::error('AI Chat with conversation error', [
                'question' => $question,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            $errorMessage = $this->getUserFriendlyError($e);

            // Store error response in conversation
            $conversation->addMessage('assistant', $errorMessage, [
                'error' => true,
            ]);

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
     * Regenerate response for an existing user message.
     *
     * Unlike askWithConversation(), this does NOT store the user message again.
     * It only generates and stores a new assistant response.
     *
     * @param AiConversation $conversation The conversation
     * @param AiMessage $userMessage The existing user message to regenerate from
     * @param array $options Additional options (user, style, etc.)
     * @return array The AI response
     */
    public function regenerateResponse(
        AiConversation $conversation,
        AiMessage $userMessage,
        array $options = []
    ): array {
        $options = array_merge($this->config, $options);
        $question = $userMessage->content;

        // SECURITY: Require user context unless explicitly marked as anonymous-safe
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

            $conversationContext = $contextManager->buildPromptContext($conversation);

            $aiResponse = AI::answerQuestion($question, [
                'style' => $options['style'] ?? 'friendly',
                'conversation_id' => $conversation->id,
                'conversation_context' => $conversationContext,
                'user' => $options['user'] ?? null,
            ]);

            $answerText = $aiResponse['answer'] ?? '';
            $cypherQuery = $aiResponse['cypher'] ?? null;
            $queryData = $aiResponse['data'] ?? [];

            $contextManager->recordResponse(
                $conversation,
                $answerText,
                $cypherQuery ?? '',
                [
                    'data' => $queryData,
                    'referenced_files' => $aiResponse['referenced_files'] ?? [],
                ]
            );

            $conversation->addMessage('assistant', $answerText, [
                'response_data' => $queryData,
                'cypher_query' => $cypherQuery,
                'suggestions' => $aiResponse['suggestions'] ?? [],
                'referenced_files' => $aiResponse['referenced_files'] ?? [],
                'regenerated' => true,
            ]);

            return [
                'answer' => $answerText,
                'data' => $queryData,
                'suggestions' => $aiResponse['suggestions'] ?? [],
                'sources' => $aiResponse['referenced_files'] ?? [],
                'cypher_query' => $cypherQuery,
            ];

        } catch (\Exception $e) {
            Log::error('AI regenerate response error', [
                'user_message_id' => $userMessage->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            $errorMessage = $this->getUserFriendlyError($e);

            $conversation->addMessage('assistant', $errorMessage, [
                'error' => true,
                'regenerated' => true,
            ]);

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
     * Get schema for context processing.
     * Uses schema from options if provided, otherwise fetches from AI facade.
     */
    protected function getSchemaForContext(array $options): array
    {
        if (isset($options['schema'])) {
            return $options['schema'];
        }

        try {
            return AI::getSchema();
        } catch (\Exception $e) {
            // Return empty schema if unable to fetch
            Log::warning('Unable to fetch schema for context', ['error' => $e->getMessage()]);
            return ['labels' => [], 'relationships' => []];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        try {
            // Check if the AI facade is available and LLM is configured
            return config('ai.llm.default') !== null
                && config('ai.llm.' . config('ai.llm.default') . '.api_key') !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getSchema(): array
    {
        return $this->getSchemaForContext([]);
    }

    /**
     * Convert exception to user-friendly message.
     */
    protected function getUserFriendlyError(\Exception $e): string
    {
        $message = $e->getMessage();

        // Map technical errors to friendly messages
        $errorMap = [
            'connection' => 'I\'m having trouble connecting to the database. Please try again.',
            'timeout' => 'The request took too long. Try a simpler question.',
            'api' => 'There was an issue with the AI service. Please try again.',
        ];

        foreach ($errorMap as $keyword => $friendlyMessage) {
            if (stripos($message, $keyword) !== false) {
                return $friendlyMessage;
            }
        }

        return 'I encountered an error processing your question. Please try rephrasing or try again later.';
    }
}
