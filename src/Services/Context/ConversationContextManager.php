<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Context;

use Condoedge\Ai\Models\AiConversation;
use Illuminate\Support\Str;

/**
 * ConversationContextManager
 *
 * Owns the conversation context snapshot on AiConversation: it is written ONCE
 * per turn, AFTER the pipeline ran (recordResponse), and read back as the
 * prompt context of the next turn (buildPromptContext).
 *
 * There is deliberately NO pre-pipeline question analysis here (Phase 2.B):
 * follow-up detection, entity focus and query type of the CURRENT question are
 * semantic judgments made by the Query Understanding LLM call
 * (QueryUnderstandingResult::$followUpReference / $entityTypes / $intent) — never
 * by regex or word lists. The only entity extraction left is structural: the
 * labels of the Cypher that actually executed.
 */
class ConversationContextManager
{
    /**
     * Upper bound of the snapshot's mentioned_entities list (most recent last).
     * Readers (action gate, entity-action section, conversation prompt section)
     * want the conversation's RECENT entity types; an uncapped list grew every
     * turn of a long conversation.
     */
    public const MAX_MENTIONED_ENTITIES = 10;

    public function __construct(
        private readonly EntityExtractor $entityExtractor
    ) {
    }

    /**
     * Record an AI response and update context with enhanced entity data.
     *
     * Stores query results, entity filters, and answer summaries so the next
     * turn (and its QU call) can resolve references like "those", "them",
     * "the same" against what the user was last shown.
     *
     * Focus: the entity actually QUERIED (first label of the executed cypher)
     * wins. A turn that executed no cypher (documents answer, export of a
     * previous result, digest) falls back to THIS turn's QU entity types, which
     * the pipeline stamps on metadata.entity_types; with neither, the previous
     * focus is preserved. The QU intent (metadata.intent) becomes
     * last_query_type.
     */
    public function recordResponse(
        AiConversation $conversation,
        string $response,
        ?string $cypherQuery,
        array $queryResult
    ): void {
        $snapshot = $conversation->context_snapshot ?? [];

        // Extract entities from the Cypher query (if available)
        $cypherEntities = ['entities' => [], 'relationships' => []];
        if ($cypherQuery) {
            $cypherEntities = $this->entityExtractor->extractFromCypher($cypherQuery);
        }

        // QU's entity types for THIS turn (post-QU, stamped by the pipeline).
        $quEntityTypes = array_values(array_filter(
            (array) ($queryResult['metadata']['entity_types'] ?? []),
            static fn ($label): bool => is_string($label) && trim($label) !== ''
        ));

        // Update context with the entities from the executed query (or, without
        // one, QU's entity types) — most recent last, bounded.
        $turnEntities = !empty($cypherEntities['entities'])
            ? array_values($cypherEntities['entities'])
            : $quEntityTypes;
        $newEntities = $this->recentEntities($conversation->getMentionedEntities(), $turnEntities);

        $focusedEntity = $conversation->getFocusedEntity();
        // Always update focused entity from Cypher when a query was executed.
        // The entity actually queried is the real focus, not the previous turn's entity.
        if (!empty($cypherEntities['entities'])) {
            $focusedEntity = array_values($cypherEntities['entities'])[0];
        } elseif ($quEntityTypes !== []) {
            // No query ran: QU's first entity type is this turn's focus.
            $focusedEntity = $quEntityTypes[0];
        }

        // The query the GENERATOR wrote (eval p12-1001-A). A FanOutCollapse turn
        // executed a per-anchor REWRITE (`WITH u, collect(DISTINCT tr.role) AS
        // role` — every other pattern variable out of scope) and returns it as the
        // turn's cypher, with the original on metadata.query.pre_collapse_cypher.
        // The rewrite stays last_cypher_query (what re-executes for an export of
        // the rows shown); the generator's own query is what a follow-up EXTENDS
        // (ConversationContextSection) and what the active filter is read from.
        $preCollapse = $queryResult['metadata']['query']['pre_collapse_cypher'] ?? null;
        $generatedCypher = is_string($preCollapse) && trim($preCollapse) !== '' ? $preCollapse : $cypherQuery;

        // Extract entity filter from Cypher WHERE clause
        $entityFilter = $this->extractEntityFilter($generatedCypher);

        // Store result sample: the first 10 rows for an ordinary query turn (a
        // display sample — the follow-up export path recovers the full set by
        // re-executing last_cypher_query). A DIGEST turn keeps its rows IN FULL:
        // it records no cypher, so this sample IS the only source a follow-up
        // "export that" can materialize (B1 promises the file carries everything
        // shown), and the rows are already display-capped per source by the
        // DigestRunner — bounded by max_sources x row cap, never a raw result set.
        $isDigestTurn = is_array($queryResult['metadata']['response']['digest'] ?? null);
        $resultSample = $isDigestTurn
            ? array_values($queryResult['data'] ?? [])
            : array_slice($queryResult['data'] ?? [], 0, 10);

        $referencedFiles = $queryResult['referenced_files'] ?? [];
        // QU's intent (count|list|lookup|aggregate|compare), stamped by the pipeline;
        // an explicit query_type still wins for direct callers.
        $queryType = $queryResult['query_type'] ?? $queryResult['metadata']['intent'] ?? null;
        if (!is_string($queryType) || $queryType === '') {
            $queryType = null;
        }

        // Build snapshot update - only include non-empty values to preserve previous context
        $snapshotUpdate = [
            'focused_entity' => $focusedEntity,
            'mentioned_entities' => $newEntities,
            'last_relationships' => $cypherEntities['relationships'],
            'last_answer_summary' => Str::limit($response, 500),
            'updated_at' => now()->toIso8601String(),
        ];

        // PRESERVE-ON-EMPTY (live failure 2026-07-23): a turn that showed the user
        // no rows — a documents-only answer, a misrouted follow-up, a 0-row query,
        // an internally-caught error — must NOT erase what the user was last SHOWN.
        // Follow-ups ("ok, can you export that?", "add the roles to it") refer to
        // the last visible result; unconditional overwrite here made a docs turn
        // wipe the roster and the next export answer "No results were found".
        // Only a turn that actually produced rows advances the result memory.
        $rowCount = count($queryResult['data'] ?? []);
        if ($rowCount > 0) {
            $snapshotUpdate['last_cypher_query'] = $cypherQuery;
            // Always written with last_cypher_query, so the pair never mixes turns.
            $snapshotUpdate['last_generated_cypher'] = $generatedCypher;
            // The label of the records the rows ARE (the companion count's anchor,
            // stamped on capped / collapsed results), or null when unknown — it
            // names the subject a follow-up's requested attributes must belong to.
            $anchorLabel = $queryResult['metadata']['execution']['anchor_label'] ?? null;
            $snapshotUpdate['last_anchor_label'] = is_string($anchorLabel) && trim($anchorLabel) !== '' ? trim($anchorLabel) : null;
            // P-1: `$pii_…` parameters the recorded query binds (PII tokens rebound to
            // real values). Always written with the query so a stale set never pairs
            // with a newer query.
            $snapshotUpdate['last_cypher_parameters'] = is_array($queryResult['cypher_parameters'] ?? null)
                ? $queryResult['cypher_parameters']
                : [];
            $snapshotUpdate['last_result_count'] = $rowCount;
            $snapshotUpdate['last_result_sample'] = $resultSample;
            $snapshotUpdate['focused_entity_filter'] = $entityFilter;
            // AUGMENTATION ANCHOR (item B): EVERY entity of the executed cypher —
            // not just the first — so a later column-add follow-up ("add their
            // last login to it") can deterministically re-seed retrieval with the
            // previous turn's entity types when QU comes back empty. Written only
            // on row-producing turns (same preserve-on-empty rule as
            // last_cypher_query: a docs turn must not erase the data anchor).
            if (!empty($cypherEntities['entities'])) {
                $snapshotUpdate['last_entity_types'] = array_values($cypherEntities['entities']);
            }
        }

        // last_subject is the subject OF THE RECORDED RESULT (it titles a
        // follow-up export of those rows and labels the result memory QU sees),
        // so it moves only together with the rows: written when THIS turn both
        // recorded rows and had a subject. A subject-less follow-up ("Ok, give
        // me the excel") must not erase it, and a row-less turn (a documents
        // answer) must not overwrite it — eval p9-B resilience t3: the docs
        // turn "Where can I add new members?" re-titled the preserved roster's
        // export "Add new team members".
        $subject = $queryResult['metadata']['subject'] ?? null;
        if ($rowCount > 0 && is_string($subject) && trim($subject) !== '') {
            $snapshotUpdate['last_subject'] = trim($subject);
        }

        // Only update last_referenced_files if there are new files
        // Otherwise preserve the previous reference for follow-up questions
        if (!empty($referencedFiles)) {
            $snapshotUpdate['last_referenced_files'] = $referencedFiles;
        }

        if ($queryType) {
            $snapshotUpdate['last_query_type'] = $queryType;
        }

        $conversation->updateContextSnapshot($snapshotUpdate);
    }

    /**
     * The conversation's recent entity types: previous list + this turn's, de-duplicated
     * (a re-mentioned type moves to the end), most recent last, capped.
     *
     * @param array<int, mixed> $previous
     * @param array<int, string> $turn
     * @return array<int, string>
     */
    private function recentEntities(array $previous, array $turn): array
    {
        $merged = [];
        foreach (array_merge($previous, $turn) as $label) {
            if (!is_string($label) || $label === '') {
                continue;
            }
            unset($merged[$label]);
            $merged[$label] = true;
        }

        return array_slice(array_keys($merged), -self::MAX_MENTIONED_ENTITIES);
    }

    /**
     * Extract WHERE clause conditions from Cypher query.
     *
     * Used to track entity filters for reference resolution in follow-up questions.
     */
    protected function extractEntityFilter(?string $cypherQuery): ?string
    {
        if (!$cypherQuery) {
            return null;
        }

        // The FIRST WHERE clause's predicate, up to the next clause keyword (Cypher
        // grammar): ending only at RETURN/ORDER/LIMIT swallowed every following
        // OPTIONAL MATCH / WITH into the "filter" (eval p12-1001-A). `STARTS WITH`
        // / `ENDS WITH` are operators, not the WITH clause.
        if (preg_match(
            '/\bWHERE\s+(.+?)(?=\s+(?:OPTIONAL\s+MATCH|MATCH|(?<!STARTS\s)(?<!ENDS\s)WITH|UNWIND|CALL|RETURN|ORDER\s+BY|SKIP|LIMIT|UNION)\b|\s*$)/is',
            $cypherQuery,
            $matches
        )) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Build enhanced prompt context with entity data.
     *
     * Provides comprehensive context for prompt building including:
     * - Current focused entity and filter conditions
     * - Sample results from last query for reference resolution
     * - Recent conversation exchanges for continuity
     */
    public function buildPromptContext(AiConversation $conversation, ?int $maxHistory = null): array
    {
        $maxHistory ??= (int) config('ai.chat.max_history_messages', 10);
        $snapshot = $conversation->context_snapshot ?? [];

        // Get recent messages for exchange formatting
        $recentMessages = $conversation->getRecentMessages($maxHistory);

        return [
            'focused_entity' => $snapshot['focused_entity'] ?? null,
            'focused_entity_filter' => $snapshot['focused_entity_filter'] ?? null,
            'last_result_sample' => $snapshot['last_result_sample'] ?? [],
            'last_result_count' => $snapshot['last_result_count'] ?? 0,
            'last_cypher_query' => $snapshot['last_cypher_query'] ?? null,
            // Older snapshots predate the split: their recorded query is the generated one.
            'last_generated_cypher' => $snapshot['last_generated_cypher'] ?? $snapshot['last_cypher_query'] ?? null,
            'last_anchor_label' => $snapshot['last_anchor_label'] ?? null,
            'last_cypher_parameters' => $snapshot['last_cypher_parameters'] ?? [],
            'last_entity_types' => $snapshot['last_entity_types'] ?? [],
            'last_subject' => $snapshot['last_subject'] ?? null,
            'last_query_type' => $snapshot['last_query_type'] ?? null,
            'last_relationships' => $snapshot['last_relationships'] ?? [],
            'mentioned_entities' => $snapshot['mentioned_entities'] ?? [],
            'last_answer_summary' => $snapshot['last_answer_summary'] ?? null,
            'last_referenced_files' => $snapshot['last_referenced_files'] ?? [],
            'recent_exchanges' => $this->formatRecentExchanges($recentMessages),
        ];
    }

    /**
     * Format recent messages as exchanges for prompt context.
     *
     * Structures messages into a format suitable for context injection,
     * with summarized answers to keep prompt size manageable.
     */
    protected function formatRecentExchanges(array $messages): array
    {
        $answerChars = max(1, (int) config('ai.chat.history_answer_chars', 800));

        $exchanges = [];
        foreach ($messages as $message) {
            $role = $message['role'] ?? 'unknown';
            $content = $message['content'] ?? '';

            $exchanges[] = [
                'role' => $role,
                'question' => $role === 'user' ? $content : null,
                'answer_summary' => $role === 'assistant'
                    ? Str::limit($content, $answerChars)
                    : null,
            ];
        }
        return $exchanges;
    }
}
