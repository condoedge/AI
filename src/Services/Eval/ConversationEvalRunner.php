<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Eval;

use Condoedge\Ai\DTOs\Eval\EvalRow;
use Condoedge\Ai\Facades\AI;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Context\ConversationContextManager;

/**
 * Runs ONE suite conversation, turn by turn, threading conversation state through
 * the PRODUCTION mechanism — never an eval-only shortcut.
 *
 * Fidelity contract (mirrors AskQuestionUseCase::runTurn step-for-step):
 *
 *   1. state IN  — ConversationContextManager::processQuestion() +
 *                  buildPromptContext() on a real AiConversation row build the
 *                  conversation_context array, exactly like the web chat;
 *   2. the turn  — PipelineEvalDriver walks the pipeline with those SAME options
 *                  (user / conversation_id / conversation_context), finishing
 *                  through AiManager::composeResponse (the production composition);
 *   3. state OUT — ConversationContextManager::recordResponse() (the ONLY
 *                  last_result_sample writer in the codebase) persists the
 *                  snapshot, and messages are stored via AiConversation::addMessage.
 *
 * BECAUSE the write-back is the production writer, a production bug that fails to
 * persist last_result_sample reproduces here as a red previous_result_available
 * gate — the harness never fabricates context the real path could not produce.
 *
 * Deliberate divergence (documented): ChatSecurityGuard (STEP 0 of the web turn)
 * is NOT run — suite questions are trusted operator input and the guard's
 * injection scan would add an LLM dependency to offline runs. Everything from
 * context processing onward is the production path.
 */
class ConversationEvalRunner
{
    public function __construct(
        private readonly PipelineEvalDriver $driver,
        private readonly ConversationContextManager $contextManager,
        private readonly AttributionService $attribution,
        private readonly ExpectationGate $gate,
        /** Optional AI facade override (schema lookup) — injectable for testing. */
        private readonly mixed $aiFacade = null,
    ) {
    }

    /**
     * Run every turn of one conversation IN ORDER, returning one attributed +
     * gated EvalRow per turn. Conversations are independent of each other; each
     * gets a fresh AiConversation row (tagged metadata.eval so --fresh can find it).
     *
     * @param array{id: string, role: ?string, tags?: string[], turns: array} $conversation
     * @param mixed $user     The pinned eval user (may be null in bare environments).
     * @param bool  $offline  Skip response generation (signals-only).
     * @param bool  $capturePrompts Attach verbatim LLM prompts to each row's trace.
     * @return EvalRow[]
     */
    public function run(array $conversation, mixed $user, bool $offline = false, bool $capturePrompts = true, int $throttleMs = 0): array
    {
        $model = AiConversation::create([
            'user_id' => $user->id ?? null,
            'status' => 'active',
            'metadata' => [
                'eval' => true,
                'suite_conversation_id' => $conversation['id'] ?? null,
            ],
        ]);

        $schema = $this->getSchema();
        $conversationTags = (array) ($conversation['tags'] ?? []);

        $rows = [];
        foreach (array_values($conversation['turns']) as $turnIndex => $turn) {
            // THROTTLE (rate-limit hygiene): a full-speed suite run makes ~3 LLM
            // calls per turn with multi-KB prompts and saturates provider TPM
            // budgets (observed live: mid-run 429 storms opening the circuit
            // breaker and contaminating the whole measurement). Sleeping before
            // every turn paces the run inside the budget; 0 = unchanged.
            if ($throttleMs > 0) {
                usleep($throttleMs * 1000);
            }

            $rows[] = $this->runTurn(
                model: $model,
                conversation: $conversation,
                turn: $turn,
                turnIndex: $turnIndex,
                user: $user,
                schema: $schema,
                conversationTags: $conversationTags,
                offline: $offline,
                capturePrompts: $capturePrompts,
            );
        }

        return $rows;
    }

    /**
     * One turn = production state-in → pipeline walk → production state-out →
     * attribution → fact-gates.
     *
     * @param array{id: string, role: ?string} $conversation
     * @param array{ask: string, expect?: array, standard?: ?string, tags?: string[], page_context?: ?array} $turn
     */
    private function runTurn(
        AiConversation $model,
        array $conversation,
        array $turn,
        int $turnIndex,
        mixed $user,
        array $schema,
        array $conversationTags,
        bool $offline,
        bool $capturePrompts
    ): EvalRow {
        $ask = (string) $turn['ask'];
        $tags = array_values(array_unique(array_merge($conversationTags, (array) ($turn['tags'] ?? []))));

        $start = microtime(true);

        // ---- 1. State IN: the production context read path -----------------
        // processQuestion extracts entities / resolves references and updates the
        // snapshot; buildPromptContext derives the conversation_context array —
        // byte-for-byte the flow of AskQuestionUseCase::runTurn (persist branch).
        $contextResult = $this->contextManager->processQuestion($model, $ask, $schema);
        $conversationContext = $this->contextManager->buildPromptContext($model);
        $enrichedQuestion = $contextResult['enriched_question'] ?? $ask;

        $model->addMessage('user', $ask, [
            'context_used' => $conversationContext,
            'is_follow_up' => $contextResult['is_follow_up'] ?? false,
            'resolved_entity' => $contextResult['resolved_entity'] ?? null,
        ]);

        // ---- 2. The pipeline walk ------------------------------------------
        // $driverThrew distinguishes the runner's own last-resort catch (mirrors
        // AskQuestionUseCase's catch, which SKIPS recordResponse) from stage-error
        // rows produced by the driver's per-stage traps (which production catches
        // INSIDE answerQuestion and still records — see state OUT below).
        $driverThrew = false;
        try {
            $row = $this->driver->run(
                question: $enrichedQuestion,
                user: $user,
                tags: $tags,
                offline: $offline,
                // Per-turn page prior from the suite (page-scoped probes): threaded
                // exactly as a chat surface would send it; null for ordinary turns.
                pageContext: $turn['page_context'] ?? null,
                turnOptions: [
                    'conversation_id' => $model->id,
                    'conversation_context' => $conversationContext,
                    'capture_prompts' => $capturePrompts,
                ],
            );
        } catch (\Throwable $e) {
            // Driver traps per stage; this is a last-resort guard mirroring
            // EvalCommand::evaluateOne.
            $driverThrew = true;
            $row = new EvalRow(question: $enrichedQuestion, tags: $tags);
            $row->verdictLabel = 'stage-error';
            $row->erroredStage = 'retrieval';
            $row->erroredStep = 'driver';
            $row->error = $e->getMessage();
        }

        // The report's question identity is the suite's VERBATIM ask on every path;
        // the enrichment the pipeline actually ran with is trace evidence beside it,
        // so a grader can audit whether the enrichment itself was the failure.
        $row->question = $ask;
        $row->enrichedQuestion = $enrichedQuestion !== $ask ? $enrichedQuestion : null;

        $row->totalDurationMs = (int) round((microtime(true) - $start) * 1000);

        // Evidence of what this turn RECEIVED — the previous_result_available
        // gate's input. Recorded from the context actually threaded in, so the
        // gate grades production behavior, not harness intent.
        //
        // FOLLOW-UP SIGNALS, BOTH RECORDED (item D): is_follow_up_regex is the
        // pre-QU deterministic fallback (ReferenceResolver::isFollowUp — the
        // only signal available BEFORE the QU call, used for context
        // processing); follow_up_reference_qu is the QU LLM's semantic
        // judgment, which is the AUTHORITY for every post-QU routing decision
        // (export-of-previous, digest guard, augmentation anchor). Observed
        // inverted live — recording both makes disagreement auditable per turn.
        $regexIsFollowUp = (bool) ($contextResult['is_follow_up'] ?? false);
        $row->conversationContextMeta = [
            'last_result_sample_count' => count($conversationContext['last_result_sample'] ?? []),
            'focused_entity' => $conversationContext['focused_entity'] ?? null,
            'is_follow_up' => $regexIsFollowUp,
            'is_follow_up_regex' => $regexIsFollowUp,
            'follow_up_reference_qu' => $row->understanding['follow_up_reference'] ?? null,
            'resolved_entity' => $contextResult['resolved_entity'] ?? null,
            // The page prior this turn ran with (null = page-less), so a grader can
            // audit whether a page-scoped probe's answer actually used its page.
            'page_context' => $turn['page_context'] ?? null,
        ];

        // ---- 3. State OUT: the production write path -----------------------
        if ($driverThrew) {
            // The turn failed OUTSIDE the pipeline (the driver itself threw) —
            // production's analogue is AskQuestionUseCase's catch, which stores an
            // error message and skips recordResponse; the next turn sees the same
            // (stale) snapshot it would.
            $model->addMessage('assistant', $row->error !== '' ? $row->error : 'Turn errored.', [
                'error' => true,
                'regenerated' => false,
            ]);
        } elseif ($row->verdictLabel === 'stage-error') {
            // A PIPELINE stage threw. Production catches this INSIDE
            // AiManager::answerQuestion (its catch returns an error payload with
            // data: []), so AskQuestionUseCase still calls recordResponse.
            // Mirror that exactly: same writer, same empty payload. Under
            // ConversationContextManager's PRESERVE-ON-EMPTY rule the 0-row
            // write KEEPS the previous last_result_sample / anchor (an errored
            // turn showed the user nothing new, so the last visible result
            // survives for later exports) while still updating the summary
            // fields — the next turn sees exactly the snapshot production
            // would produce.
            $errorText = $row->error !== '' ? $row->error : 'Turn errored.';
            $this->contextManager->recordResponse(
                $model,
                $errorText,
                '',
                [
                    'data' => [],
                    'referenced_files' => [],
                ]
            );

            $model->addMessage('assistant', $errorText, [
                'response_data' => [],
                'cypher_query' => null,
                'referenced_files' => [],
                'error' => true,
                'regenerated' => false,
            ]);
        } else {
            // recordResponse is THE last_result_sample writer
            // (ConversationContextManager::recordResponse → updateContextSnapshot),
            // fed the same shape AskQuestionUseCase feeds it.
            $this->contextManager->recordResponse(
                $model,
                $row->answer,
                $row->cypher ?? '',
                [
                    'data' => $row->executionData,
                    'referenced_files' => $row->referencedFiles,
                    // Mirror AskQuestionUseCase: response metadata rides along so a
                    // digest turn's full-row snapshot rule (metadata.response.digest)
                    // applies in eval exactly as in production.
                    'metadata' => ['response' => $row->responseMetadata],
                ]
            );

            $model->addMessage('assistant', $row->answer, [
                'response_data' => $row->executionData,
                'cypher_query' => $row->cypher,
                'referenced_files' => $row->referencedFiles,
                'regenerated' => false,
            ]);
        }

        // ---- 4. Attribute, then gate ---------------------------------------
        $this->attribution->attribute($row);

        $row->conversationId = (string) ($conversation['id'] ?? '');
        $row->turnIndex = $turnIndex;
        $row->role = $conversation['role'] ?? null;
        $row->standard = $turn['standard'] ?? null;
        $row->expect = (array) ($turn['expect'] ?? []);
        $row->gates = $this->gate->evaluate($row->expect, $row);

        // A failed fact-gate marks the row gate-failed UNLESS a harder verdict
        // (stage-error / context-not-retrieved) already explains WHY the gate
        // could not pass — those labels carry more diagnostic signal.
        if ($row->hasGateFailure()
            && !in_array($row->verdictLabel, ['stage-error', 'context-not-retrieved'], true)) {
            $row->verdictLabel = 'gate-failed';
        }

        // The inverse direction: gates that ALL passed override a soft 'failed'
        // from neutral-signal scoring — a turn whose declared deterministic
        // evidence is green must never ship a contradictory 'failed' label.
        $this->attribution->reconcileGateVerdict($row);

        return $row;
    }

    /**
     * Fetch the graph schema for context processing, degrading to an empty schema
     * on failure — the exact tolerance of AskQuestionUseCase::getSchema().
     */
    private function getSchema(): array
    {
        try {
            $schema = $this->ai()->getSchema();

            return is_array($schema) ? $schema : ['labels' => [], 'relationships' => []];
        } catch (\Throwable $e) {
            return ['labels' => [], 'relationships' => []];
        }
    }

    private function ai(): mixed
    {
        return $this->aiFacade ?? AI::getFacadeRoot();
    }
}
