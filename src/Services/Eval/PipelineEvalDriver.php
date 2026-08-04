<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Eval;

use Condoedge\Ai\DTOs\Eval\EvalRow;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\Facades\AI;

/**
 * Reusable driver that walks ONE question through the real AI pipeline,
 * stage by stage, and returns a populated {@see EvalRow}.
 *
 * This generalises the stage-walk in TestQuestionCommand (context retrieval →
 * file search → query generation → execution → response) so both the test
 * command and the eval harness share one path.
 *
 * Two safety features make it eval-friendly:
 *
 *  1. PER-STAGE try/catch — a failure in any stage is attributed to THAT stage
 *     (verdictLabel = 'stage-error', erroredStage set) and the walk stops,
 *     instead of throwing. Offline stores (Qdrant/Neo4j/LLM down) therefore
 *     never crash the run.
 *
 *  2. GENERATION GATE — after retrieval + file search, if NOTHING was retrieved
 *     (empty selected_entities AND empty relevant_files AND no cypher) the row
 *     is marked 'context-not-retrieved' and the driver STOPS before generating
 *     a response.
 *
 * The driver only gathers the cheap deterministic signals + the answer; answer
 * grading is done out-of-process by an agent, not in-process.
 */
class PipelineEvalDriver
{
    /**
     * @param object|null $aiFacade Optional AI manager (defaults to the AI facade).
     *                              Injectable for testing.
     */
    public function __construct(private readonly mixed $aiFacade = null) {}

    /**
     * Walk a single question through the pipeline.
     *
     * @param string             $question
     * @param mixed              $user        Authenticated user (already logged in by caller).
     * @param string[]           $tags        Tags to carry onto the row.
     * @param bool               $offline     When true, skip response generation (no LLM answer).
     * @param array<string,mixed>|null $pageContext The PageContext array (which page/record the
     *                                        chat was opened from), or null when none — threaded
     *                                        through every stage exactly as AiManager::answerQuestion.
     * @param array<string,mixed> $turnOptions Conversation threading + trace options:
     *                                        - conversation_id: the AiConversation id (production
     *                                          threads it into execution logging);
     *                                        - conversation_context: the array built by
     *                                          ConversationContextManager::buildPromptContext —
     *                                          threaded into EVERY stage exactly as
     *                                          AskQuestionUseCase → AiManager::answerQuestion does;
     *                                        - capture_prompts: attach verbatim LLM prompts to the trace;
     *                                        - style: response style (defaults 'friendly', the same
     *                                          default every production web-chat turn sends).
     */
    public function run(string $question, mixed $user, array $tags = [], bool $offline = false, ?array $pageContext = null, array $turnOptions = []): EvalRow
    {
        $row = new EvalRow(question: $question, tags: $tags);

        // Pinned identity evidence for the no_self_contact gate: the same $user
        // every stage is threaded with (never re-resolved from auth), so the gate
        // grades against the identity the pipeline actually ran as.
        $pinnedEmail = is_object($user) ? ($user->email ?? null) : (is_array($user) ? ($user['email'] ?? null) : null);
        $row->pinnedUserEmail = is_string($pinnedEmail) && trim($pinnedEmail) !== '' ? trim($pinnedEmail) : null;

        // Identity + page prior threaded through EVERY stage the same way
        // AskQuestionUseCase::runTurn builds the options for AiManager::answerQuestion,
        // so the eval scores production rather than a context-less variant.
        $options = ['user' => $user, 'page_context' => $pageContext ?? []];

        // Conversation threading — same option keys, same shape, as the production
        // web-chat turn (AskQuestionUseCase::runTurn). ContextRetriever reads
        // conversation_context.focused_entity from these options, so it must be
        // present BEFORE stage 1, not stamped only on the PipelineContext.
        if (!empty($turnOptions['conversation_context']) && is_array($turnOptions['conversation_context'])) {
            $options['conversation_context'] = $turnOptions['conversation_context'];
        }
        if (!empty($turnOptions['conversation_id'])) {
            $options['conversation_id'] = $turnOptions['conversation_id'];
        }
        if (($turnOptions['capture_prompts'] ?? false) === true) {
            $options['capture_prompts'] = true;
        }

        // Response style: every production web-chat turn sends one (AskQuestionInput
        // defaults 'friendly', threaded at AskQuestionUseCase::runTurn), so the eval
        // must too — otherwise ResponseGenerator falls back to 'detailed' and graded
        // answers come from a prompt style production never uses.
        $options['style'] = is_string($turnOptions['style'] ?? null) && ($turnOptions['style'] ?? '') !== ''
            ? $turnOptions['style']
            : 'friendly';

        // ---- Stage 1: Context retrieval (understanding + retrieval signals) ----
        $context = $this->stage($row, 'retrieval', 'context_retrieval', function () use ($question, $options) {
            return $this->ai()->retrieveContext($question, $options);
        });

        if ($row->verdictLabel === 'stage-error') {
            return $row;
        }

        /** @var PipelineContext $context */

        // Page context is a soft prior: stamp it onto the context after retrieval,
        // mirroring AiManager::answerQuestion, so the PageContextSection renders in
        // prompt assembly instead of emitting zero tokens.
        if (!empty($options['page_context'])) {
            $context->pageContext = $options['page_context'];
        }

        // Conversation context stamped onto the PipelineContext after retrieval —
        // the EXACT mirror of AiManager::answerQuestion (which sets
        // $ctx->conversationContext from $options['conversation_context']). The
        // export follow-up path (ExportResponseComposer::previousResultSample)
        // and the response prompt sections read it from here.
        if (!empty($options['conversation_context'])) {
            $context->conversationContext = $options['conversation_context'];
        }

        $this->captureContextSignals($row, $context);

        // ---- Stage 1a: Attention digest — the PRODUCTION branch, via the AiManager seam ----
        // composeDigestResponse delegates to the exact digestResponse branch
        // answerQuestion runs BEFORE file search and query generation. A digest
        // turn is fully owned here (its trace carries response_metadata.digest);
        // a non-digest turn returns null and the walk continues unchanged.
        // Guards: offline runs skip it (the digest's composition is an LLM
        // call); the QU flag gates entry so ordinary turns never pay the stage;
        // method_exists keeps injected test fakes (duck-typed $aiFacade) valid.
        if (!$offline && $context->qu->digestRequest && method_exists($this->ai(), 'composeDigestResponse')) {
            $digest = $this->stage($row, 'prompt', 'digest_composition', function () use ($question, $context, $options) {
                return $this->ai()->composeDigestResponse($context, $question, $options);
            });

            if ($row->verdictLabel === 'stage-error') {
                return $row;
            }

            if (is_array($digest)) {
                $row->answer = (string) ($digest['answer'] ?? '');
                $row->referencedFiles = is_array($digest['referenced_files'] ?? null) ? $digest['referenced_files'] : [];
                $responseMeta = $digest['metadata']['response'] ?? [];
                $row->responseMetadata = is_array($responseMeta) ? $responseMeta : [];
                $data = is_array($digest['data'] ?? null) ? $digest['data'] : [];
                $row->rowCount = count($data);
                $row->dataSample = array_slice($data, 0, 10);
                // Full rows carried TRANSIENTLY for the production conversation
                // write-back — a follow-up "export that" must see the digest rows.
                $row->executionData = $data;

                return $row;
            }
        }

        // ---- Stage 1a-bis: Clarify branch — the PRODUCTION branch, via the AiManager seam ----
        // composeClarifyResponse delegates to the exact clarifyResponse branch
        // answerQuestion runs AFTER the digest check and BEFORE file search and
        // query generation. Deterministic (zero LLM calls — the clarifying
        // question rode the QU call), so it runs in offline mode too. A
        // non-clarify turn returns null and the walk continues unchanged.
        if ($context->qu->needsClarification && method_exists($this->ai(), 'composeClarifyResponse')) {
            $clarify = $this->stage($row, 'prompt', 'clarify_composition', function () use ($question, $context, $options) {
                return $this->ai()->composeClarifyResponse($context, $question, $options);
            });

            if ($row->verdictLabel === 'stage-error') {
                return $row;
            }

            if (is_array($clarify)) {
                $row->answer = (string) ($clarify['answer'] ?? '');
                $responseMeta = $clarify['metadata']['response'] ?? [];
                $row->responseMetadata = is_array($responseMeta) ? $responseMeta : [];

                return $row;
            }
        }

        // ---- Stage 1b: File search — the PRODUCTION fan-out, via the AiManager seam ----
        // retrieveFileContextInto delegates to the exact retrieveAndSetFileContext
        // production runs inside answerQuestion (corpus-translation cross-lingual
        // angles, file-listing enumeration intent, page-context angle, recall-floor
        // safety net, snippet-depth merge). A re-implemented subset here would grade
        // suites against a weaker retrieval than production has.
        $start = microtime(true);
        try {
            $context = $this->ai()->retrieveFileContextInto($context, $question, $options);
        } catch (\Throwable $e) {
            // File search failures are non-fatal: degrade to "no files" — but record
            // the error on the ROW too, so retrieved_chunks=[] is distinguishable
            // from "no relevant documents existed" in the trace.
            $context->addError('file search: ' . $e->getMessage());
            $row->contextErrors[] = 'file search: ' . $e->getMessage();
        }
        $row->timings['files'] = (int) round((microtime(true) - $start) * 1000);
        $row->relevantFiles = $context->fileContext['relevant_files'] ?? [];
        $row->retrievedChunks = $this->citeChunks($row->relevantFiles);

        // ---- Stage 2: Query generation ----
        // AUGMENTATION ANCHOR PARITY (item B): production bypasses the
        // knowledge-skip for QU-flagged follow-ups whose conversation snapshot
        // records a data anchor (AiManager::hasAugmentationAnchor) — the driver
        // must take the same branch or the eval grades a blinder pipeline.
        // Duck-typed like composeDigestResponse so injected test fakes stay valid.
        //
        // FILE-CONTEXT PARITY: production's knowledge-skip (generateAndExecute)
        // also requires documents to have actually been retrieved — a DOCLESS
        // knowledge_query falls through to query generation so the empty result
        // becomes an explicit no-source abstention. Same condition here, read
        // from the file-search stage that just ran above.
        $hasAnchor = method_exists($this->ai(), 'hasAugmentationAnchor')
            && $this->ai()->hasAugmentationAnchor($context);
        $hasFileContext = !empty($context->fileContext['relevant_files'] ?? []);
        $isKnowledge = $context->qu->questionType === 'knowledge_query' && $hasFileContext && !$hasAnchor;
        $queryResult = ['cypher' => null, 'confidence' => 0, 'warnings' => [], 'explanation' => '', 'metadata' => []];

        if (!$isKnowledge) {
            $queryResult = $this->stage($row, 'understanding', 'query_generation', function () use ($question, $context, $options) {
                // store_examples=false: deliberate, documented divergence — the eval
                // must not write its synthetic questions into the shared few-shot
                // store (they would pollute production RAG example retrieval and make
                // successive eval runs depend on each other's stored examples).
                return $this->ai()->generateQuery($question, $context, $options + ['store_examples' => false]);
            }) ?? $queryResult;

            if ($row->verdictLabel === 'stage-error') {
                return $row;
            }
        }

        $row->cypher = $queryResult['cypher'] ?? null;

        // Verbatim query-generation prompts (present only when capture_prompts
        // was requested — the generator's own seam guarantees that).
        if (!empty($queryResult['metadata']['prompts'])) {
            $row->prompts['query_generation'] = $queryResult['metadata']['prompts'];
        }

        // ---- GENERATION GATE: nothing retrieved → stop before generating a response ----
        if ($row->contextIsEmpty()) {
            $row->verdictLabel = 'context-not-retrieved';
            $row->blameReason = 'No entities selected, no files found and no cypher generated.';
            return $row;
        }

        // ---- Stage 3: Query execution ----
        // Attributed to the 'retrieval' scorecard stage (query+execution share it by
        // design) but timed and error-tagged as its own STEP, so a Neo4j failure is
        // never mistaken for a vector-retrieval failure in the trace.
        $executionResult = ['data' => [], 'metadata' => [], 'errors' => [], 'stats' => []];
        if (!empty($row->cypher)) {
            // Thread identity + provenance into execution so the executor team-filters
            // against the real user (not auth()->user()) and logs the real question and
            // conversation, matching AiManager::generateAndExecute.
            $execOptions = $options + [
                'original_question' => $question,
                'conversation_id' => $options['conversation_id'] ?? null,
            ];
            $executionResult = $this->stage($row, 'retrieval', 'query_execution', function () use ($row, $execOptions) {
                return $this->ai()->executeQuery($row->cypher, [], $execOptions);
            }) ?? $executionResult;

            if ($row->verdictLabel === 'stage-error') {
                return $row;
            }
        }

        // ---- Rebuild the typed results on the ORIGINAL context ----
        // Production threads ONE PipelineContext end-to-end (qu, semantic selection,
        // conversation/page context all intact); rebuilding a bare context here would
        // hand response composition a blinder pipeline than production runs.
        $context->queryResult = new QueryGenerationResult(
            cypher: $queryResult['cypher'] ?? null,
            explanation: $queryResult['explanation'] ?? '',
            confidence: (float) ($queryResult['confidence'] ?? 0.0),
            warnings: $queryResult['warnings'] ?? [],
            metadata: $queryResult['metadata'] ?? [],
        );
        // Entity-action enrichment through the production seam: generateAndExecute
        // enriches rows (has_actions / available_actions / entity_type) BEFORE the
        // response prompt sees them and before they reach recordResponse — the eval's
        // row sample and write-back must carry the same enriched shape.
        $context->executionResult = $this->ai()->enrichExecutionResult(new QueryExecutionResult(
            success: !empty($executionResult['data']) || empty($executionResult['errors'] ?? []),
            data: $executionResult['data'] ?? [],
            stats: $executionResult['stats'] ?? [],
            metadata: $executionResult['metadata'] ?? [],
            errors: $executionResult['errors'] ?? [],
        ));

        $row->rowCount = count($context->executionResult->data);
        $row->dataSample = array_slice($context->executionResult->data, 0, 10);
        // Full rows are carried TRANSIENTLY (never serialized) so the conversation
        // runner can feed the production write-back exactly like AskQuestionUseCase
        // passes $aiResponse['data'] to recordResponse.
        $row->executionData = $context->executionResult->data;

        // Offline: do not generate an answer (no LLM). Signals are enough for
        // deterministic attribution, which assigns the terminal label.
        if ($offline) {
            return $row;
        }

        // ---- Stage 4: Final response via the PRODUCTION composition ----
        // composeResponse is the same code path answerQuestion() ends with:
        // export detection (incl. previous-result export follow-ups), abstention,
        // infra-error surfacing, file enrichment, scope prefix. Using it (instead
        // of calling generateResponse directly) is what lets the produces_file
        // and cites_document gates check the REAL payload production would emit.
        $final = $this->stage($row, 'prompt', 'response_composition', function () use ($question, $context, $options) {
            return $this->ai()->composeResponse($question, $context, $options);
        }, onError: function (\Throwable $e) use ($row) {
            // A response-stage failure must not lose the verbatim prompts that were
            // in flight: the generator attaches them to its failure exception
            // (capture mode only) — lift them into the trace where a grader needs them.
            for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof \Condoedge\Ai\Exceptions\CarriesPrompts && $cause->getPrompts() !== null) {
                    $row->prompts['response_generation'] = $cause->getPrompts();
                    break;
                }
            }
        });

        if ($row->verdictLabel === 'stage-error') {
            return $row;
        }

        $final = is_array($final) ? $final : [];
        $row->answer = (string) ($final['answer'] ?? '');
        $row->referencedFiles = is_array($final['referenced_files'] ?? null) ? $final['referenced_files'] : [];

        $responseMeta = $final['metadata']['response'] ?? [];
        $responseMeta = is_array($responseMeta) ? $responseMeta : [];
        // Verbatim response-generation prompts move to the trace's prompts block
        // (single home), leaving the rest of the response metadata for the gates.
        if (!empty($responseMeta['prompts'])) {
            $row->prompts['response_generation'] = $responseMeta['prompts'];
            unset($responseMeta['prompts']);
        }
        $row->responseMetadata = $responseMeta;

        return $row;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Run a pipeline step, timing it under its OWN key and trapping failures.
     * Returns the callback result, or null if it threw (verdictLabel updated).
     *
     * @param string        $stage   The ATTRIBUTION stage the failure is blamed on
     *                               (understanding|retrieval|prompt|generation — what
     *                               AttributionService scores).
     * @param string        $step    The fine-grained pipeline step (context_retrieval,
     *                               query_execution, ...) — what timings_ms and
     *                               errored_step report, so e.g. a Neo4j execution
     *                               failure is never conflated with vector retrieval.
     * @param callable|null $onError Invoked with the throwable before returning null
     *                               (e.g. to lift captured prompts off the exception).
     */
    private function stage(EvalRow $row, string $stage, string $step, callable $fn, ?callable $onError = null): mixed
    {
        $start = microtime(true);
        try {
            $result = $fn();
            $row->timings[$step] = ($row->timings[$step] ?? 0) + (int) round((microtime(true) - $start) * 1000);
            return $result;
        } catch (\Throwable $e) {
            $row->timings[$step] = ($row->timings[$step] ?? 0) + (int) round((microtime(true) - $start) * 1000);
            $row->verdictLabel = 'stage-error';
            $row->erroredStage = $stage;
            $row->erroredStep = $step;
            $row->error = $e->getMessage();
            if ($onError !== null) {
                $onError($e);
            }
            return null;
        }
    }

    private function captureContextSignals(EvalRow $row, PipelineContext $context): void
    {
        $row->selectedEntities = $context->selectionInfo['selected_entities'] ?? [];
        $row->quEntityTypes = $context->qu->entityTypes ?? [];
        $row->questionType = $context->qu->questionType ?: 'hybrid';

        // Normalise context quality to 0-1.
        $score = (float) ($context->quality['score'] ?? 0);
        $max = (float) ($context->quality['max'] ?? 0);
        $row->contextQuality = $max > 0 ? round(min(1.0, $score / $max), 4) : 0.0;

        // QU output snapshot for the trace: what the pipeline UNDERSTOOD, verbatim,
        // so an out-of-process grader can spot understanding failures without re-running.
        $row->understanding = [
            'question_type' => $context->qu->questionType,
            'intent' => $context->qu->intent,
            'entity_types' => $context->qu->entityTypes,
            'search_query' => $context->qu->searchQuery,
            'clarified_question' => $context->qu->clarifiedQuestion,
            'search_tags' => $context->qu->searchTags,
            'is_possessive' => $context->qu->isPossessive,
            // The extra retrieval-angle families QU can emit (cross-lingual
            // corpus translations; lay->register reformulations). Captured so a
            // missed-document trace shows whether QU emitted zero angles (config/
            // parse problem) or bad ones (prompt problem) — without these the
            // levers are invisible to the eval that grades them.
            'corpus_translations' => $context->qu->corpusTranslations,
            'register_expansions' => $context->qu->registerExpansions,
            // Routing judgments (items C + D): QU's follow-up/digest/export/
            // language calls and the clarify signal, verbatim, so a grader can
            // audit WHICH branch owned the turn and why. follow_up_reference is
            // the AUTHORITATIVE follow-up signal (the regex is only the pre-QU
            // fallback, recorded separately in conversation_context_received).
            'follow_up_reference' => $context->qu->followUpReference,
            'digest_request' => $context->qu->digestRequest,
            'export_format' => $context->qu->exportFormat,
            'language' => $context->qu->language,
            'needs_clarification' => $context->qu->needsClarification,
            'candidate_readings' => $context->qu->candidateReadings,
            'clarifying_question' => $context->qu->clarifyingQuestion,
        ];
    }

    /**
     * Compact citation view of the retrieved file chunks for the trace:
     * id + score + snippet, defensively keyed since physical and DB sources
     * shape their entries slightly differently.
     *
     * @param array<int, mixed> $files
     * @return array<int, array{id: mixed, score: mixed, snippet: string}>
     */
    private function citeChunks(array $files): array
    {
        return array_values(array_map(static function ($file) {
            $file = is_array($file) ? $file : [];

            return [
                'id' => $file['file_id'] ?? $file['filename'] ?? null,
                'score' => $file['priority_score'] ?? $file['relevance'] ?? null,
                'snippet' => (string) ($file['snippet'] ?? ''),
            ];
        }, $files));
    }

    /**
     * The AI manager/facade to drive the pipeline with.
     */
    private function ai(): mixed
    {
        return $this->aiFacade ?? AI::getFacadeRoot();
    }
}
