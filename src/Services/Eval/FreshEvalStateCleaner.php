<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Eval;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\AiManager;
use Condoedge\Ai\Services\Discovery\SchemaInspector;
use Condoedge\Ai\Services\Resilience\CircuitBreaker;
use Illuminate\Support\Facades\Cache;

/**
 * --fresh support for ai:eval: clears the AI-owned caches + the pinned user's
 * EVAL conversation state so a re-run reflects config changes.
 *
 * HONESTY RULES (Laravel Cache has no wildcard delete):
 *  - only keys we can DERIVE are cleared, via each component's own invalidation
 *    API where one exists (SchemaInspector::clearAllCaches, CircuitBreaker::reset,
 *    AiManager::clearStoredQueries) and targeted Cache::forget() otherwise;
 *  - every action (or skip, with its reason) is returned as a line for the
 *    command to print, so the operator sees EXACTLY what --fresh did;
 *  - what cannot be cleared is NOT claimed. Known un-cleared state (also
 *    printed by clean() so the operator sees it on every --fresh run):
 *      - ActionDispatcher's single-use approval nonces (keys embed per-token ids
 *        we cannot enumerate; per-approval transient state irrelevant to eval
 *        re-runs anyway);
 *      - AiQueryLog rows written by query execution: they feed --from-logs
 *        question sets and are kept as history, not cleared.
 *
 * Inventory of AI-owned state this covers (verified against src/):
 *  - SchemaInspector      ai.schema.{table}.{type}          → clearAllCaches()
 *  - CircuitBreaker       circuit_breaker.{llm|neo4j}.*     → reset() per name
 *  - RateLimiter          rate_limiter.llm_api              → Cache::forget
 *  - VectorPayloadBuilder ai.vector_collection.exists.{col} → Cache::forget per configured collection
 *  - Stored question->cypher few-shot examples in the 'questions' VECTOR
 *    collection (written by AiManager::storeQuery, read back as the SIMILAR
 *    QUERIES few-shot block): cleared via AiManager::clearStoredQueries().
 *    Poisoned examples from old interactions anchor generation — a byte-identical
 *    "87% similar" example beats the clarified intent — so --fresh must reset
 *    this store, not just suppress new eval writes (store_examples=false).
 *  - Conversation state lives in the DATABASE (AiConversation.context_snapshot
 *    + ai_messages), not the cache — eval-created conversations for the pinned
 *    user are soft-deleted. (config ai.chat.session_key_prefix 'ai_chat_history'
 *    has NO reader in src/ — session history is dead config, nothing to clear.)
 */
class FreshEvalStateCleaner
{
    /**
     * Circuit breaker names in use (Neo4jStore:55 → 'neo4j',
     * AbstractHttpLlmProvider:287 → 'llm').
     */
    private const BREAKER_NAMES = ['llm', 'neo4j'];

    /**
     * The vector collection AiManager::storeQuery defaults to (also
     * ContextRetriever::DEFAULT_COLLECTION — the SIMILAR QUERIES source).
     */
    private const STORED_QUERY_COLLECTION = 'questions';

    /**
     * @param AiManager|null $aiManager Owner of the stored-example store; resolved
     *        lazily from the container when not injected (unit tests inject a mock).
     */
    public function __construct(private ?AiManager $aiManager = null)
    {
    }

    /**
     * Clear everything derivable, returning printable lines describing exactly
     * what happened. Never throws: each step degrades to a "skipped" line.
     *
     * @param mixed $user The pinned eval user (whose eval conversations get cleared).
     * @return string[]
     */
    public function clean(mixed $user): array
    {
        $lines = [];

        // ---- Schema inspection cache (ai.schema.{table}.{type}) ----
        try {
            (new SchemaInspector())->clearAllCaches();
            $lines[] = 'Cleared schema-inspection cache (ai.schema.{table}.{type} for every DB table) via SchemaInspector::clearAllCaches().';
        } catch (\Throwable $e) {
            $lines[] = 'SKIPPED schema-inspection cache: ' . $e->getMessage();
        }

        // ---- Circuit breakers (circuit_breaker.{name}.*) ----
        foreach (self::BREAKER_NAMES as $name) {
            try {
                (new CircuitBreaker($name))->reset();
                $lines[] = "Reset circuit breaker '{$name}' (circuit_breaker.{$name}.*) via CircuitBreaker::reset().";
            } catch (\Throwable $e) {
                $lines[] = "SKIPPED circuit breaker '{$name}': " . $e->getMessage();
            }
        }

        // ---- LLM rate limiter window ----
        try {
            Cache::forget('rate_limiter.llm_api');
            $lines[] = 'Cleared LLM rate-limiter window (rate_limiter.llm_api).';
        } catch (\Throwable $e) {
            $lines[] = 'SKIPPED rate-limiter key: ' . $e->getMessage();
        }

        // ---- Vector collection existence flags (per configured collection) ----
        foreach ($this->configuredCollections() as $collection) {
            try {
                Cache::forget("ai.vector_collection.exists.{$collection}");
                $lines[] = "Cleared vector-collection existence flag (ai.vector_collection.exists.{$collection}).";
            } catch (\Throwable $e) {
                $lines[] = "SKIPPED vector-collection flag '{$collection}': " . $e->getMessage();
            }
        }

        // ---- Stored question->cypher few-shot examples (vector store) ----
        $lines[] = $this->clearStoredQueryExamples();

        // ---- Pinned user's eval conversations (DB state, not cache) ----
        $lines[] = $this->clearEvalConversations($user);

        // ---- Honest declaration of what --fresh does NOT clear ----
        $lines[] = 'NOT cleared: AiQueryLog execution-log rows (kept as history; they feed --from-logs).';

        return $lines;
    }

    /**
     * Purge the stored question->cypher few-shot example store (the SIMILAR
     * QUERIES retrieval source) via the owning service's targeted API. Stored
     * examples from old interactions otherwise anchor generation across runs,
     * defeating --fresh. Degrades to an honest SKIPPED/NOT-cleared line when the
     * manager or the vector store is unavailable.
     */
    private function clearStoredQueryExamples(): string
    {
        $collection = self::STORED_QUERY_COLLECTION;

        try {
            $manager = $this->aiManager ?? app('ai');
            $result = $manager->clearStoredQueries($collection);

            if (($result['success'] ?? false) === true) {
                $deleted = (int) ($result['deleted'] ?? 0);

                return "Cleared {$deleted} stored question->cypher few-shot example(s) "
                    . "('{$collection}' vector collection) via AiManager::clearStoredQueries() "
                    . '— stored examples no longer anchor this run\'s query generation.';
            }

            $reason = implode('; ', (array) ($result['errors'] ?? [])) ?: 'unknown error';

            return "SKIPPED (NOT cleared) stored question->cypher few-shot examples ('{$collection}'): "
                . $reason . ' — examples from past runs/production traffic still serve as retrieval examples.';
        } catch (\Throwable $e) {
            return "SKIPPED (NOT cleared) stored question->cypher few-shot examples ('{$collection}'): "
                . $e->getMessage() . ' — examples from past runs/production traffic still serve as retrieval examples.';
        }
    }

    /**
     * Collections derivable from config — the only ones VectorPayloadBuilder can
     * have flagged. @return string[]
     */
    private function configuredCollections(): array
    {
        $collections = [
            config('ai.semantic_context.collection'),
            config('ai.scope_matching.collection'),
            config('ai.file_processing.collection'),
        ];

        return array_values(array_unique(array_filter(array_map(
            static fn ($c) => is_string($c) ? trim($c) : '',
            $collections
        ))));
    }

    /**
     * Soft-delete ONLY conversations this harness created (metadata.eval = true)
     * for the pinned user — never the user's real chat history. JSON filtering is
     * done in PHP for cross-driver portability (sqlite/mysql).
     */
    private function clearEvalConversations(mixed $user): string
    {
        $userId = $user->id ?? null;

        try {
            $candidates = AiConversation::query()
                ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
                ->when($userId === null, fn ($q) => $q->whereNull('user_id'))
                ->get();

            $deleted = 0;
            foreach ($candidates as $conversation) {
                if (($conversation->metadata['eval'] ?? false) === true) {
                    $conversation->delete();
                    $deleted++;
                }
            }

            $who = $userId !== null ? "user {$userId}" : 'the anonymous eval user';

            return "Cleared {$deleted} eval conversation(s) (metadata.eval=true) for {$who} — real chat history untouched.";
        } catch (\Throwable $e) {
            return 'SKIPPED eval-conversation cleanup: ' . $e->getMessage();
        }
    }
}
