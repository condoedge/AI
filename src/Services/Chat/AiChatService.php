<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat;

use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\Models\AiConversation;
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
    public function ask(string $question, array $options = []): AiChatMessage
    {
        $startTime = microtime(true);
        $options = array_merge($this->config, $options);

        try {
            // Use the AI facade to process the question
            $aiResponse = AI::answerQuestion($question, [
                'style' => $options['style'] ?? 'friendly',
            ]);

            $executionTime = (int) ((microtime(true) - $startTime) * 1000);

            // Extract the answer text from the response array
            $answerText = $aiResponse['answer'] ?? 'I could not generate a response.';

            // Build response data with additional context from AI response
            $responseData = $this->buildResponseData($question, $answerText, $executionTime, $options);

            // Add data from AI response if available
            if (!empty($aiResponse['data'])) {
                $responseData = $this->enrichResponseData($responseData, $aiResponse);
            }

            return AiChatMessage::assistant($answerText, $responseData);

        } catch (\Exception $e) {
            Log::error('AI Chat error', [
                'question' => $question,
                'error' => $e->getMessage(),
            ]);

            $errorData = AiChatResponseData::error(
                $this->getUserFriendlyError($e)
            );

            return AiChatMessage::assistant(
                $this->getUserFriendlyError($e),
                $errorData
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function askWithHistory(string $question, array $history, array $options = []): AiChatMessage
    {
        $startTime = microtime(true);
        $options = array_merge($this->config, $options);

        try {
            // Build context-enriched question with conversation history
            $enrichedQuestion = $this->buildQuestionWithHistory($question, $history, $options);

            // Use answerQuestion with RAG - pass enriched question
            $aiResponse = AI::answerQuestion($enrichedQuestion, [
                'style' => $options['style'] ?? 'friendly',
            ]);

            $executionTime = (int) ((microtime(true) - $startTime) * 1000);

            // Extract the answer text from the response array
            $answerText = $aiResponse['answer'] ?? 'I could not generate a response.';

            // Build response data
            $responseData = $this->buildResponseData($question, $answerText, $executionTime, $options);

            // Add data from AI response if available
            if (!empty($aiResponse['data'])) {
                $responseData = $this->enrichResponseData($responseData, $aiResponse);
            }

            return AiChatMessage::assistant($answerText, $responseData);

        } catch (\Exception $e) {
            Log::error('AI Chat with history error', [
                'question' => $question,
                'history_count' => count($history),
                'error' => $e->getMessage(),
            ]);

            $errorData = AiChatResponseData::error($this->getUserFriendlyError($e));

            return AiChatMessage::assistant(
                $this->getUserFriendlyError($e),
                $errorData
            );
        }
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
            $contextManager->recordResponse(
                $conversation,
                $answerText,
                $cypherQuery ?? '',
                ['data' => $queryData]
            );

            // 7. Store message in conversation
            $conversation->addMessage('assistant', $answerText, [
                'response_data' => $queryData,
                'cypher_query' => $cypherQuery,
                'suggestions' => $aiResponse['suggestions'] ?? [],
                'sources' => $aiResponse['referenced_files'] ?? [],
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
     * Prepare a question with conversation context without executing.
     *
     * Use this to preview how a question will be enriched before sending to AI,
     * or to get the enriched question for custom processing.
     *
     * @param string $question The user's question
     * @param AiConversation $conversation The conversation context
     * @param array $schema Schema with available entity labels
     * @return array Context result including enriched_question, is_follow_up, focused_entity, etc.
     */
    public function prepareQuestionWithContext(
        string $question,
        AiConversation $conversation,
        array $schema
    ): array {
        // Process question through context manager
        $contextResult = $this->getContextManager()->processQuestion(
            $conversation,
            $question,
            $schema
        );

        // Build the conversation context
        $conversationContext = $this->getContextManager()->buildPromptContext($conversation);

        return [
            'is_follow_up' => $contextResult['is_follow_up'],
            'enriched_question' => $contextResult['enriched_question'],
            'focused_entity' => $contextResult['focused_entity'],
            'query_type' => $contextResult['query_type'],
            'mentioned_entities' => $contextResult['mentioned_entities'],
            'resolved_entity' => $contextResult['resolved_entity'],
            'context' => $conversationContext,
        ];
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
     * Build a question enriched with conversation history context.
     * This allows RAG to work while still having conversation context.
     */
    protected function buildQuestionWithHistory(string $question, array $history, array $options): string
    {
        $maxHistory = $options['max_history_messages'] ?? 10;
        $recentHistory = array_slice($history, -$maxHistory);

        // If no history or only the current message, return question as-is
        if (count($recentHistory) <= 1) {
            return $question;
        }

        // Build conversation context (exclude the last message which is the current question)
        $contextMessages = array_slice($recentHistory, 0, -1);

        if (empty($contextMessages)) {
            return $question;
        }

        $contextParts = [];
        foreach ($contextMessages as $message) {
            $role = $message instanceof AiChatMessage ? $message->role : ($message['role'] ?? 'user');
            $content = $message instanceof AiChatMessage ? $message->content : ($message['content'] ?? '');

            if ($role === 'user') {
                $contextParts[] = "User asked: {$content}";
            } else {
                // Truncate long assistant responses
                $truncated = strlen($content) > 200 ? substr($content, 0, 200) . '...' : $content;
                $contextParts[] = "Assistant replied: {$truncated}";
            }
        }

        $contextString = implode("\n", $contextParts);

        return <<<EOT
[Previous conversation context:]
{$contextString}

[Current question:]
{$question}
EOT;
    }

    /**
     * {@inheritdoc}
     */
    public function getSuggestions(string $question, string $response): array
    {
        $question = strtolower($question);
        $suggestions = [];

        // Context-aware suggestions based on question content
        $suggestionMap = [
            'customer' => [
                'How many active customers do we have?',
                'Show top customers by revenue',
                'Recent customer activity',
            ],
            'order' => [
                'Show recent orders',
                'What is the average order value?',
                'Orders by status',
            ],
            'product' => [
                'Top selling products',
                'Low stock products',
                'Product categories',
            ],
            'revenue' => [
                'Monthly revenue trend',
                'Revenue by customer',
                'Compare to last period',
            ],
            'count' => [
                'Show me the details',
                'Break down by category',
                'Compare to last month',
            ],
        ];

        foreach ($suggestionMap as $keyword => $keywordSuggestions) {
            if (str_contains($question, $keyword)) {
                $suggestions = array_merge($suggestions, $keywordSuggestions);
            }
        }

        // Default suggestions if none matched
        if (empty($suggestions)) {
            $suggestions = [
                'Tell me more',
                'Show related data',
                'What else can you tell me?',
            ];
        }

        return array_slice(array_unique($suggestions), 0, $this->config['max_suggestions']);
    }

    /**
     * {@inheritdoc}
     */
    public function getExampleQuestions(): array
    {
        return config('ai.chat.example_questions', [
            'How many records do we have?',
            'Show me recent activity',
            'What are the top items?',
            'Give me a summary',
        ]);
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
     * Build response data with optional metrics and suggestions.
     */
    protected function buildResponseData(
        string $question,
        string $response,
        int $executionTime,
        array $options
    ): AiChatResponseData {
        $responseData = AiChatResponseData::text();

        // Add metrics if enabled
        if ($options['include_metrics'] ?? false) {
            $responseData = $responseData->withMetrics($executionTime, 0);
        }

        // Add suggestions if enabled
        if ($options['include_suggestions'] ?? true) {
            $suggestions = $this->getSuggestions($question, $response);
            $responseData = $responseData->withSuggestions($suggestions);
        }

        return $responseData;
    }

    /**
     * Enrich response data with information from AI response.
     */
    protected function enrichResponseData(AiChatResponseData $responseData, array $aiResponse): AiChatResponseData
    {
        // If there's tabular data, convert to table format
        if (!empty($aiResponse['data']) && is_array($aiResponse['data'])) {
            $data = $aiResponse['data'];

            // Check if it's tabular data (array of arrays/objects)
            if (!empty($data) && (is_array($data[0]) || is_object($data[0]))) {
                $firstRow = (array) $data[0];
                $headers = array_keys($firstRow);
                $rows = array_map(fn($row) => array_values((array) $row), $data);

                return AiChatResponseData::table($headers, $rows)
                    ->withSuggestions($responseData->suggestions)
                    ->withMetrics(
                        $responseData->executionTimeMs ?? 0,
                        count($rows),
                        $aiResponse['cypher'] ?? null
                    );
            }
        }

        // Add insights as suggestions if available
        if (!empty($aiResponse['insights'])) {
            $insights = array_slice($aiResponse['insights'], 0, $this->config['max_suggestions']);
            $responseData = $responseData->withSuggestions(
                array_merge($responseData->suggestions, $insights)
            );
        }

        return $responseData;
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
