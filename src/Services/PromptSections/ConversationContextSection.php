<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

/**
 * ConversationContextSection
 *
 * Adds conversation history and context to the LLM prompt.
 * Enables the LLM to understand follow-up questions and references.
 *
 * Priority 55 places this after similar_queries (50) but before
 * detected_entities (60).
 */
class ConversationContextSection extends BasePromptSection
{
    protected string $name = 'conversation_context';
    protected int $priority = 55;

    /**
     * Only include when there's actual conversation context
     */
    public function shouldInclude(string $question, array $context, array $options = []): bool
    {
        $conversationContext = $context['conversation_context'] ?? [];

        // Need at least a focused entity or recent exchanges
        return !empty($conversationContext['focused_entity'])
            || !empty($conversationContext['recent_exchanges']);
    }

    /**
     * Format the conversation context for the prompt
     */
    public function format(string $question, array $context, array $options = []): string
    {
        $conversationContext = $context['conversation_context'] ?? [];

        $output = $this->header('CONVERSATION CONTEXT');

        // Current focus
        if (!empty($conversationContext['focused_entity'])) {
            $output .= "**Current Focus:** {$conversationContext['focused_entity']}";

            if (!empty($conversationContext['last_query_type'])) {
                $output .= " ({$conversationContext['last_query_type']} query)";
            }

            $output .= "\n\n";
        }

        // Recent exchanges
        if (!empty($conversationContext['recent_exchanges'])) {
            $output .= "**Recent Conversation:**\n";

            foreach ($conversationContext['recent_exchanges'] as $i => $exchange) {
                $num = $i + 1;

                if (!empty($exchange['user']['content'])) {
                    $userContent = $this->truncate($exchange['user']['content'], 100);
                    $output .= "  [{$num}] User: {$userContent}\n";
                }

                if (!empty($exchange['assistant']['content'])) {
                    $assistantContent = $this->truncate($exchange['assistant']['content'], 150);
                    $output .= "      Assistant: {$assistantContent}\n";

                    if (!empty($exchange['assistant']['cypher_query'])) {
                        $output .= "      Query: {$exchange['assistant']['cypher_query']}\n";
                    }
                }
            }

            $output .= "\n";
        }

        // Last Cypher query (if not already shown in exchanges)
        if (!empty($conversationContext['last_cypher_query'])
            && empty($conversationContext['recent_exchanges'])) {
            $output .= "**Previous Query:**\n";
            $output .= "```cypher\n{$conversationContext['last_cypher_query']}\n```\n\n";
        }

        // Follow-up hint
        if (!empty($conversationContext['is_follow_up'])) {
            $output .= "**Note:** This is a continuation of the previous conversation. ";
            $output .= "Consider building upon or modifying the previous query. ";
            $output .= "Pronouns like 'those', 'them', 'it' refer to the ";
            $output .= "{$conversationContext['focused_entity']} entity.\n\n";
        }

        // Mentioned entities for reference
        if (!empty($conversationContext['mentioned_entities'])) {
            $entities = implode(', ', $conversationContext['mentioned_entities']);
            $output .= "**Entities discussed:** {$entities}\n\n";
        }

        return $output;
    }

    /**
     * Truncate text to specified length
     */
    private function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }

        return substr($text, 0, $length) . '...';
    }
}
