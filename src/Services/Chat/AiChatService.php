<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat;

use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\Chat\Inputs\AskQuestionInput;
use Condoedge\Ai\Services\Chat\Results\ChatTurnResult;
use Condoedge\Ai\Services\Chat\UseCases\AskQuestionUseCase;
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
     *
     * Composes the single, model-agnostic identity (config('ai.identity')) so chat and
     * the RAG pipeline share ONE persona and ONE non-disclosure rule. A host app can
     * still fully override this via config('ai.chat.system_prompt').
     */
    protected function getDefaultSystemPrompt(): string
    {
        $identity = \Condoedge\Ai\Services\AssistantIdentity::render();
        $projectDescription = (string) config('ai.project.description', '');

        $parts = array_filter([
            $identity,
            $projectDescription,
        ], fn ($part) => trim((string) $part) !== '');

        return implode("\n\n", $parts);
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

        // AskQuestionUseCase owns chat-turn orchestration (security gate, DB::transaction,
        // returns a created AiMessage). This service is config-`new`ed and has no DI, so
        // resolve the use case via the container.
        $result = app(AskQuestionUseCase::class)->execute(new AskQuestionInput(
            question: $question,
            conversation: $conversation,
            style: $options['style'] ?? 'friendly',
            user: $options['user'] ?? null,
            allowAnonymous: $options['allow_anonymous'] ?? false,
        ));

        return $this->resultToArray($result);
    }

    /**
     * Map a ChatTurnResult back to the legacy array shape returned by this interface.
     */
    private function resultToArray(ChatTurnResult $result): array
    {
        return [
            'answer' => $result->answer,
            'data' => $result->data,
            'suggestions' => $result->suggestions,
            'sources' => $result->sources,
            'cypher_query' => $result->cypher,
        ];
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

        // AskQuestionUseCase::regenerate runs the shared turn flow with
        // persistUserMessage=false. Trailing-message truncation is the caller's
        // responsibility.
        $result = app(AskQuestionUseCase::class)->regenerate(
            conversation: $conversation,
            userMessage: $userMessage,
            style: $options['style'] ?? 'friendly',
            user: $options['user'] ?? null,
            allowAnonymous: $options['allow_anonymous'] ?? false,
        );

        return $this->resultToArray($result);
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
