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

        // Need at least one of these context elements to be useful
        return !empty($conversationContext['focused_entity'])
            || !empty($conversationContext['recent_exchanges'])
            || !empty($conversationContext['last_cypher_query'])
            || !empty($conversationContext['last_result_sample'])
            || !empty($conversationContext['last_referenced_files']);
    }

    /**
     * Format the conversation context for the prompt
     *
     * Provides rich context to enable the AI to:
     * - Understand follow-up questions and references
     * - Build upon previous queries
     * - Resolve pronouns like "those", "them", "the same"
     */
    public function format(string $question, array $context, array $options = []): string
    {
        $conversationContext = $context['conversation_context'] ?? [];

        if (empty($conversationContext)) {
            return '';
        }

        $output = "## Conversation Context\n\n";

        // Focused entity with filter
        if (!empty($conversationContext['focused_entity'])) {
            $output .= "**Current Focus:** {$conversationContext['focused_entity']}";

            if (!empty($conversationContext['last_query_type'])) {
                $output .= " ({$conversationContext['last_query_type']} query)";
            }

            $output .= "\n";

            if (!empty($conversationContext['focused_entity_filter'])) {
                $output .= "**Active Filter:** `{$conversationContext['focused_entity_filter']}`\n";
            }
        }

        // Previous query reference
        if (!empty($conversationContext['last_cypher_query'])) {
            $output .= "\n**Previous Query:**\n```cypher\n{$conversationContext['last_cypher_query']}\n```\n";
            $resultCount = $conversationContext['last_result_count'] ?? 0;
            $output .= "Returned {$resultCount} results.\n";
        }

        // Result sample for reference
        if (!empty($conversationContext['last_result_sample'])) {
            $output .= "\n**Sample of Previous Results:**\n```json\n";
            $output .= json_encode($conversationContext['last_result_sample'], JSON_PRETTY_PRINT);
            $output .= "\n```\n";
        }

        // Recent exchanges
        if (!empty($conversationContext['recent_exchanges'])) {
            $output .= "\n**Recent Conversation:**\n";

            foreach ($conversationContext['recent_exchanges'] as $exchange) {
                if (!empty($exchange['question'])) {
                    $userContent = $this->truncate($exchange['question'], 100);
                    $output .= "- User: {$userContent}\n";
                }
                if (!empty($exchange['answer_summary'])) {
                    $assistantContent = $this->truncate($exchange['answer_summary'], 150);
                    $output .= "  Assistant: {$assistantContent}\n";
                }
            }
        }

        // Mentioned entities for reference
        if (!empty($conversationContext['mentioned_entities'])) {
            $entities = implode(', ', $conversationContext['mentioned_entities']);
            $output .= "\n**Entities discussed:** {$entities}\n";
        }

        // Referenced files from previous response
        if (!empty($conversationContext['last_referenced_files'])) {
            $output .= "\n**Files Referenced in Previous Response:**\n";
            foreach ($conversationContext['last_referenced_files'] as $file) {
                $filename = $file['filename'] ?? 'Unknown file';
                $fileId = $file['file_id'] ?? '';
                $snippet = $this->truncate($file['snippet'] ?? '', 100);
                $output .= "- [{$filename}] (ID: {$fileId}): {$snippet}\n";
            }
        }

        // Follow-up hint (enhanced instructions)
        $output .= "\n**Instructions:** Use the above context to understand follow-up questions. ";
        $output .= "If user references 'those', 'them', 'the same', etc., use the previous results/filter. ";
        $output .= "If user asks about 'the file', 'that file', etc., refer to the files referenced above.\n";

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
