<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Response\PiiMasker;
use Condoedge\Ai\Support\UntrustedLabel;

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
    public function shouldInclude(string $question, PipelineContext $context, array $options = []): bool
    {
        $conversationContext = $context->conversationContext;

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
    public function format(string $question, PipelineContext $context, array $options = []): string
    {
        $conversationContext = $context->conversationContext;

        if (empty($conversationContext)) {
            return '';
        }

        // P-1 (Phase 8-A review): previous answers now carry the real-value table and
        // the sample carries real rows — both reach the query generator masked with the
        // turn's ONE token map. A token the model copies into its cypher is rebound as a
        // query parameter carrying the real value (PiiMasker::bindCypher).
        $conversationContext = PiiMasker::for($context)->maskConversation($conversationContext);

        $output = "## Conversation Context\n\n";

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

        // The query the generator wrote last turn (eval p12-1001-A): a FanOutCollapse
        // turn executed a per-anchor rewrite (`WITH u, collect(…) AS role` — every
        // other pattern variable out of scope); extending THAT made the model use
        // `t` after the WITH ("Variable `t` not defined"). The generator extends its
        // own query; an extension that fans out again is collapsed again at
        // execution. last_cypher_query (the rewrite) stays the re-execution source.
        $previousQuery = $this->previousQuery($conversationContext);
        if ($previousQuery !== null) {
            $output .= "\n**Previous Query:**\n```cypher\n{$previousQuery}\n```\n";
            $resultCount = $conversationContext['last_result_count'] ?? 0;
            $output .= "Returned {$resultCount} results.\n";
        }

        if (!empty($conversationContext['last_result_sample'])) {
            $output .= "\n**Sample of Previous Results:**\n```json\n";
            $output .= json_encode($conversationContext['last_result_sample'], JSON_PRETTY_PRINT);
            $output .= "\n```\n";
        }

        if (!empty($conversationContext['recent_exchanges'])) {
            $output .= "\n**Recent Conversation:**\n";

            foreach ($conversationContext['recent_exchanges'] as $exchange) {
                if (!empty($exchange['question'])) {
                    $userContent = $this->truncate($exchange['question'], 200);
                    $output .= "- User: {$userContent}\n";
                }
                if (!empty($exchange['answer_summary'])) {
                    $assistantContent = $this->truncate($exchange['answer_summary'], 300);
                    $output .= "  Assistant: {$assistantContent}\n";
                }
            }
        }

        if (!empty($conversationContext['mentioned_entities'])) {
            $entities = implode(', ', $conversationContext['mentioned_entities']);
            $output .= "\n**Entities discussed:** {$entities}\n";
        }

        if (!empty($conversationContext['last_referenced_files'])) {
            $output .= "\n**Files Referenced in Previous Response:**\n";
            foreach ($conversationContext['last_referenced_files'] as $file) {
                // Handle both structures: name/id (from ResponseFileEnricher) and filename/file_id
                $file = (array) $file;
                $filename = UntrustedLabel::fileName((string) ($file['name'] ?? $file['filename'] ?? ''));
                $fileId = $file['id'] ?? $file['file_id'] ?? '';
                $fileId = is_int($fileId) ? (string) $fileId : UntrustedLabel::of(is_scalar($fileId) ? (string) $fileId : '');
                $snippet = $this->truncate((string) ($file['snippet'] ?? ''), 100);
                $output .= "- file (ID: {$fileId})\n";
                // The file's name and the excerpt came from an untrusted document (an
                // uploaded file is named by its uploader): both are spotlighted so they
                // stay data, never instructions (same wrapper as current file text).
                $output .= $this->spotlight(
                    'File: ' . ($filename !== '' ? $filename : 'Unknown file') . ($snippet !== '' ? "\n" . $snippet : ''),
                    'retrieved_document',
                    ['source' => 'previous_turn']
                ) . "\n";
            }
        }

        // AUGMENTATION ANCHOR (item B): QU judged this turn a follow-up that
        // OPERATES ON the previous result AND a previous query is recorded —
        // instruct the generator to EXTEND that query rather than start blind
        // (the exact live failure: "add their last login to it" regenerated
        // from nothing and lost the members table entirely).
        if (($context->qu->followUpReference ?? false)
            && !empty($conversationContext['last_cypher_query'])) {
            $output .= "\n**THIS REQUEST OPERATES ON THE PREVIOUS RESULT.** "
                . "EXTEND the Previous Query above: keep its MATCH/WHERE and returned columns, "
                . "and ADD the newly requested data (extra columns / relationships) to it. "
                . "Do NOT write an unrelated fresh query and do NOT drop columns the user was already shown.\n";
        }

        // Follow-up instructions: each bullet is gated on the data it references, so the model
        // is never handed guidance about context that isn't present.
        $output .= $this->formatFollowUpInstructions($conversationContext);

        return $output;
    }

    /**
     * Build the follow-up instruction bullets, including only those whose referent data is
     * present. Returns '' when nothing to follow up on, so the block is omitted entirely.
     */
    private function formatFollowUpInstructions(array $conversationContext): string
    {
        $hasPreviousQuery = !empty($conversationContext['last_cypher_query']);
        $hasPreviousResultsOrFilter = !empty($conversationContext['last_result_sample'])
            || !empty($conversationContext['focused_entity_filter'])
            || $hasPreviousQuery;
        $hasFocus = !empty($conversationContext['focused_entity']);
        $hasReferencedFiles = !empty($conversationContext['last_referenced_files']);

        $bullets = [];

        if ($hasPreviousResultsOrFilter) {
            $bullets[] = "- If the user references 'those', 'them', 'the same', 'it', use the previous results/filter.";
        }
        if ($hasPreviousQuery) {
            $bullets[] = "- If the user asks 'what about X?' or 'and the Y?', modify the previous query for entity X/Y.";
        }
        if ($hasFocus) {
            $bullets[] = "- If the user asks about a DIFFERENT topic/entity than the current focus, generate a FRESH query for that entity. Do NOT force the previous entity into the new query.";
        }
        if ($hasPreviousQuery) {
            $bullets[] = "- If the user asks for an aggregation ('total amount', 'sum', 'average') of previous results, reuse the previous query pattern but change the RETURN clause.";
        }
        if ($hasReferencedFiles) {
            $bullets[] = "- If the user asks about 'the file', 'that file', refer to the files referenced above.";
        }

        if (empty($bullets)) {
            return '';
        }

        return "\n**Instructions for using conversation context:**\n" . implode("\n", $bullets) . "\n";
    }

    /**
     * The generator-facing previous query: last_generated_cypher (pre-collapse),
     * else the recorded last_cypher_query (snapshots that predate the split).
     */
    private function previousQuery(array $conversationContext): ?string
    {
        foreach (['last_generated_cypher', 'last_cypher_query'] as $key) {
            $query = $conversationContext[$key] ?? null;
            if (is_string($query) && trim($query) !== '') {
                return $query;
            }
        }

        return null;
    }

    /**
     * Truncate text to specified length
     */
    private function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }

        return mb_strcut($text, 0, $length, 'UTF-8') . '...';
    }
}
