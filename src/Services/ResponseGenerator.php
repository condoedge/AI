<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\ResponseSectionInterface;
use Condoedge\Ai\Contracts\ToolCallingLlmInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Actions\ActionToolChannel;
use Condoedge\Ai\Services\Actions\ActionToolGate;
use Condoedge\Ai\Services\Actions\ToolLoopRunner;
use Condoedge\Ai\Services\ResponseSections\SystemPromptSection;
use Condoedge\Ai\Services\ResponseSections\ResponseProjectContextSection;
use Condoedge\Ai\Services\ResponseSections\ResultsDataSection;
use Condoedge\Ai\Services\ResponseSections\GuidelinesSection;
use Condoedge\Ai\Support\StatedNumber;

/**
 * ResponseGenerator — turns a PipelineContext into a natural-language answer.
 *
 * generate() assembles a role-split prompt via the priority-ordered section
 * pipeline (buildPrompts() returns {system, user}: identity + security policy in
 * the system role, the untrusted question + data in the user role) and calls the
 * LLM. Sections are configured under `config('ai.response_generator_sections')`
 * and managed through the HasInternalModules trait (addModule/removeModule/
 * replaceModule/extendAfter). Returns: answer, insights, visualizations, format,
 * metadata.
 *
 * Action channel: when an {@see ActionToolGate} + {@see ToolLoopRunner} are wired,
 * generate() consults the gate ONCE per turn — Native runs the bounded tool loop
 * instead of complete(); Markdown/None (and legacy construction without the
 * collaborators) keep today's single complete() call byte-identical.
 *
 * @uses HasInternalModules<ResponseSectionInterface>
 * @see ResponseSectionInterface For creating custom sections.
 */
class ResponseGenerator implements ResponseGeneratorInterface
{
    use HasInternalModules;

    protected $defaultModulesConfigKey = 'ai.response_generator_sections';

    /**
     * Literal [N] citation-marker pattern. Checking for the marker's PRESENCE is
     * a structure check (the prompt's own citation grammar), not semantics.
     */
    private const CITATION_MARKER_PATTERN = '/\[\d+\]/';

    /**
     * Styles whose explicit contract forbids inline [N] markers (mirrors
     * GuidelinesSection/FileContextSection TERSE_STYLES) — citation enforcement
     * must never fight an instruction the prompt itself gave.
     */
    private const CITATION_TERSE_STYLES = ['minimal', 'concise'];

    /**
     * Constructor
     *
     * @param LlmProviderInterface $llm LLM provider for response generation
     * @param array $config Configuration options
     * @param ActionToolGate|null $toolGate Channel decision point (null = markdown-only legacy behavior)
     * @param ToolLoopRunner|null $toolLoop Native tool loop (null = markdown-only legacy behavior)
     */
    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly array $config = [],
        private readonly ?ActionToolGate $toolGate = null,
        private readonly ?ToolLoopRunner $toolLoop = null
    ) {
        $this->registerDefaultModules();
        $this->applyGlobalExtensions();
    }

    // =========================================================================
    // CONVENIENCE METHODS
    // =========================================================================

    /**
     * Set project context
     *
     * @param array $context Project context array
     * @return self
     */
    public function setProjectContext(array $context): self
    {
        $section = $this->getModule('project_context');
        if ($section instanceof ResponseProjectContextSection) {
            $section->setContext($context);
        }
        return $this;
    }

    /**
     * Set custom system prompt
     *
     * @param string $prompt Custom system prompt
     * @return self
     */
    public function setSystemPrompt(string $prompt): self
    {
        $section = $this->getModule('system');
        if ($section instanceof SystemPromptSection) {
            $section->setPrompt($prompt);
        }
        return $this;
    }

    /**
     * Add a custom guideline
     *
     * @param string $guideline Guideline text
     * @return self
     */
    public function addGuideline(string $guideline): self
    {
        $section = $this->getModule('guidelines');
        if ($section instanceof GuidelinesSection) {
            $section->addGuideline($guideline);
        }
        return $this;
    }

    /**
     * Set maximum data items to show
     *
     * @param int $max Maximum items
     * @return self
     */
    public function setMaxDataItems(int $max): self
    {
        $section = $this->getModule('data');
        if ($section instanceof ResultsDataSection) {
            $section->setMaxItems($max);
        }
        return $this;
    }

    // =========================================================================
    // CORE GENERATION METHODS
    // =========================================================================

    /**
     * Generate natural language response from pipeline context
     *
     * @param PipelineContext $context Pipeline context with question, query result, execution data, etc.
     * @param array $options Generation options
     * @return array Response with answer, insights, visualizations
     * @throws \RuntimeException If response generation fails
     */
    public function generate(
        PipelineContext $context,
        array $options = []
    ): array {
        $format = $options['format'] ?? $this->config['default_format'] ?? 'text';
        $style = $options['style'] ?? $this->config['default_style'] ?? 'detailed';
        $includeInsights = $options['include_insights'] ?? true;
        $includeVisualization = $options['include_visualization'] ?? true;
        $maxLength = $options['max_length'] ?? $this->config['default_max_length'] ?? 500;
        $temperature = $options['temperature'] ?? 0.3;

        $sectionOptions = [
            'format' => $format,
            'style' => $style,
            'max_length' => $maxLength,
        ];

        // THE channel decision — once per turn, one call. Absent collaborators (legacy
        // construction) and the default-off kill switch both mean Markdown: the pre-existing
        // path, byte-identical, with no spec construction paid. The channel rides the section
        // options so the model is never taught the link grammar while holding real tools.
        $toolSpecs = [];
        $channel = ActionToolChannel::Markdown;
        if ($this->toolGate !== null && $this->toolLoop !== null) {
            [$channel, $toolSpecs] = $this->toolGate->decide($this->llm, $context, $sectionOptions);
        }
        $sectionOptions[ActionToolChannel::OPTION] = $channel;

        // Build prompt using section pipeline, split into a system message
        // (identity + security policy) and a user message (context + data).
        ['system' => $systemPrompt, 'user' => $userPrompt] = $this->buildPrompts($context, $sectionOptions);

        $data = $context->executionResult->data;
        $cypherQuery = $context->queryResult->cypher;

        $llmOptions = [
            'temperature' => $temperature,
            'max_tokens' => $this->calculateMaxTokens($maxLength),
        ];

        try {
            $loopResult = null;
            if ($channel === ActionToolChannel::Native && $this->llm instanceof ToolCallingLlmInterface) {
                // A loop failure with nothing accumulated propagates into this method's
                // wrap — never a markdown complete() call against a possibly-open breaker.
                $loopResult = $this->toolLoop->run(
                    $this->llm,
                    $this->toolLoopMessages($systemPrompt, $userPrompt),
                    $toolSpecs,
                    $this->toolLoopContext($context, $options),
                    $llmOptions,
                );
                $answer = $loopResult->answer;
            } else {
                // Generate response — identity/security ride in the real system role,
                // untrusted context/data stays in the user role.
                $answer = $this->llm->complete($userPrompt, $systemPrompt !== '' ? $systemPrompt : null, $llmOptions);
            }

            // Citation enforcement (structural): a document-grounded turn whose
            // answer ships ZERO [N] markers gets ONE regeneration with a hard
            // cite-or-decline instruction. Detection uses the EXISTING QU
            // question_type signal + the presence of file chunks — never phrase
            // matching on the answer text.
            $citationEnforcement = null;
            if ($this->citationEnforcementApplies($context, $style, $answer)) {
                if ($loopResult !== null
                    && (($loopResult->telemetry['tool_calls'] ?? 0) > 0 || $loopResult->invocations !== [])) {
                    // The draft followed in-loop tool rounds whose results are NOT part
                    // of the initial prompt pair (see the capture honesty note below) —
                    // a complete() regeneration from those prompts alone would be blind
                    // to executed tool results while $metadata['invocations'] still
                    // records them. Keep the draft; surface the skip in the trace.
                    $citationEnforcement = 'skipped_tool_results';
                } else {
                    // Markdown path, or a native loop that made zero tool calls — the
                    // initial prompt pair IS the full transcript, so the bounded retry
                    // regenerates from exactly what produced the draft.
                    [$answer, $citationEnforcement] = $this->enforceCitationOrDecline(
                        $answer,
                        $systemPrompt,
                        $userPrompt,
                        $llmOptions
                    );
                }
            }

            // Whether a bounded regeneration from the initial prompt pair would be
            // FAITHFUL: on a native tool loop that executed calls, the pair is not
            // the full transcript — the guards below then skip their retry and go
            // straight to their deterministic fallback (which needs no LLM).
            $retryIsFaithful = $loopResult === null
                || (($loopResult->telemetry['tool_calls'] ?? 0) === 0 && $loopResult->invocations === []);
            $answerLocale = $this->answerLocale($context, $options);

            // Truncation/count honesty (BLOCKING, structural): more rows exist than
            // were rendered into the prompt sample => the answer must state the
            // total (or the remainder) as a literal number. One bounded retry; a
            // still-missing disclosure is APPENDED deterministically, so a
            // truncated answer can never ship claiming completeness.
            $truncationHonesty = null;
            if ($this->truncationHonestyApplies($context)) {
                [$answer, $truncationHonesty] = $this->enforceTruncationDisclosure(
                    $answer,
                    $context,
                    $systemPrompt,
                    $userPrompt,
                    $llmOptions,
                    $retryIsFaithful,
                    $answerLocale
                );
            }

            // Self-identity-as-contact guard (structural): the current user's own
            // email in the answer must carry a "that's-you" marker on the same
            // line. One bounded retry; a persisting violation gets the localized
            // marker INSERTED deterministically after the first unmarked occurrence.
            $selfReferenceGuard = null;
            $ownEmail = $this->currentUserEmail($options);
            if (config('ai.response_generation.self_reference_guard', true)
                && $ownEmail !== null
                && mb_stripos($answer, $ownEmail) !== false) {
                [$answer, $selfReferenceGuard] = $this->enforceSelfReferenceMarker(
                    $answer,
                    $ownEmail,
                    $systemPrompt,
                    $userPrompt,
                    $llmOptions,
                    $retryIsFaithful,
                    $answerLocale
                );
            }

            // GUARD-COMPOSITION PURITY (deterministic, zero LLM): a later guard's
            // retry replaces the answer checking only its OWN criterion, so it can
            // silently drop what an earlier guard enforced. Re-assert the earlier
            // guarantees on the FINAL answer:
            //  - truncation disclosure is BLOCKING — if the composition dropped the
            //    total/remainder, the localized disclosure line is re-appended and
            //    the flag turns 'patched' (never a stale 'clean'/'recovered').
            //  - citation 'recovered' must describe the shipped answer — if a later
            //    retry dropped the [N] markers, the flag is downgraded to 'failed'
            //    so the eval trace never reads a stale success.
            if ($truncationHonesty !== null) {
                [$answer, $truncationHonesty] = $this->reassertTruncationDisclosure(
                    $answer,
                    $context,
                    $truncationHonesty,
                    $answerLocale
                );
            }
            if ($citationEnforcement === 'recovered'
                && preg_match(self::CITATION_MARKER_PATTERN, $answer) !== 1) {
                $citationEnforcement = 'failed';
            }

            $insights = $includeInsights ? $this->extractInsights($data) : [];

            $visualizations = $includeVisualization
                ? $this->suggestVisualizations($data, $cypherQuery)
                : [];

            $metadata = [
                'style' => $style,
                'result_count' => count($data),
                // The rows ACTUALLY rendered into the prompt sample, read from the
                // live ResultsDataSection (hosts may raise setMaxDataItems) — the
                // eval discloses_total gate reads this instead of assuming 10, so
                // gate and runtime guard share one trigger.
                'result_shown' => $this->renderedRowCount($context),
                'summarized' => count($data) > 10,
            ];
            // The population's TRUE total when the companion count established one
            // beyond the retrieved rows — gate/grader evidence beside result_count.
            if ($this->resultTotal($context) > count($data)) {
                $metadata['result_total'] = $this->resultTotal($context);
            }
            if ($truncationHonesty !== null) {
                // 'clean' = the draft already disclosed the total/remainder;
                // 'recovered' = the single retry added it; 'patched' = the
                // deterministic localized disclosure line was appended — on every
                // path the shipped answer contains the disclosure.
                $metadata['truncation_honesty'] = $truncationHonesty;
            }
            if ($selfReferenceGuard !== null) {
                // 'clean' = every own-email line already carried a that's-you
                // marker; 'recovered' = the single retry fixed it; 'patched' = the
                // localized marker was inserted deterministically.
                $metadata['self_reference_guard'] = $selfReferenceGuard;
            }
            if ($citationEnforcement !== null) {
                // 'recovered' = the single retry produced markers; 'failed' = the
                // retry still shipped none (the retry text — possibly an honest
                // decline — is returned as-is, and the flag makes it visible in
                // the eval trace); 'skipped_tool_results' = enforcement matched but
                // the draft came from a tool loop with executed calls, so the blind
                // regeneration was skipped and the draft kept.
                $metadata['citation_enforcement'] = $citationEnforcement;
            }
            // Prompt-capture seam for the eval harness: the VERBATIM strings sent
            // to the LLM ride the metadata ONLY when explicitly requested, so
            // production payloads never balloon.
            if (($options['capture_prompts'] ?? false) === true) {
                $metadata['prompts'] = ['system' => $systemPrompt, 'user' => $userPrompt];
                if ($loopResult !== null) {
                    // HONESTY: on the native tool-calling path this pair is only the
                    // INITIAL exchange — the final answer may follow further tool-loop
                    // rounds (tool results + follow-up completions) that are not
                    // captured verbatim. Marked so a grader never mistakes the pair
                    // for the full transcript; round/tool counts + invocations ride
                    // the tool_calling telemetry below.
                    $metadata['prompts']['channel'] = 'native_tool_loop';
                }
            }
            if ($loopResult !== null) {
                // Riding the metadata keeps AiManager untouched: buildFinalResponse already
                // passes response metadata through, where the chat persistence layer reads
                // the filtered invocations to store beside response_data/suggestions.
                $metadata['tool_calling'] = $loopResult->telemetry;
                $metadata['invocations'] = array_map(
                    static fn ($invocation) => $invocation->toArray(),
                    $loopResult->invocations
                );
            }

            return [
                'answer' => trim($answer),
                'insights' => $insights,
                'visualizations' => $visualizations,
                'format' => $format,
                'metadata' => $metadata,
            ];

        } catch (\Exception $e) {
            // Wrap-and-rethrow (same contract as the previous bare RuntimeException —
            // ResponseGenerationException extends it), but carry the already-built
            // verbatim prompts when capture was requested so a response-stage failure
            // is still inspectable in the eval trace.
            throw (new \Condoedge\Ai\Exceptions\ResponseGenerationException(
                "Response generation failed: " . $e->getMessage(),
                0,
                $e
            ))->setPrompts(
                ($options['capture_prompts'] ?? false) === true
                    ? ['system' => $systemPrompt, 'user' => $userPrompt]
                    : null
            );
        }
    }

    /**
     * Whether the citation-enforcement retry applies to this turn.
     *
     * All gates are STRUCTURAL — config flag, style contract, presence of file
     * chunks (an enumeration listing is names-only, never content grounding),
     * the EXISTING QU question_type signal, and a literal [N]-marker scan of the
     * draft. No phrase matching, no semantic word lists.
     */
    private function citationEnforcementApplies(PipelineContext $context, string $style, string $answer): bool
    {
        if (!config('ai.response_generation.citation_enforcement', true)) {
            return false;
        }

        // Terse styles are explicitly told NOT to emit [N] markers — nothing to enforce.
        if (in_array($style, self::CITATION_TERSE_STYLES, true)) {
            return false;
        }

        // Only turns actually grounded on retrieved document chunks qualify.
        if (empty($context->fileContext['relevant_files'])) {
            return false;
        }
        if (!empty($context->fileContext['enumeration'])) {
            return false;
        }

        // Document-grounded per the existing QU classification.
        if ($context->qu->questionType !== 'knowledge_query') {
            return false;
        }

        return preg_match(self::CITATION_MARKER_PATTERN, $answer) !== 1;
    }

    /**
     * ONE bounded regeneration with a hard cite-or-decline instruction.
     *
     * Returns [answer, flag]: flag 'recovered' when the retry carries markers
     * (the retry replaces the draft), 'failed' when it still does not — the
     * retry is returned as-is (it may now be the honest "not in the documents"
     * decline the instruction demands) and the flag rides response metadata so
     * the eval trace shows enforcement fired and did not stick. A retry that
     * errors or returns nothing keeps the original draft, flagged 'failed'.
     *
     * @return array{0: string, 1: string}
     */
    private function enforceCitationOrDecline(
        string $answer,
        string $systemPrompt,
        string $userPrompt,
        array $llmOptions
    ): array {
        // Register-neutral wording (mirrors GuidelinesSection's "laws, regulations,
        // policies, or document contents"): the package must not assume the host's
        // corpus is legal — the same instruction must fit medical, HR-policy or
        // technical corpora without mislabelling their claims.
        $instruction = "\n\nIMPORTANT — CITATION ENFORCEMENT (this is a one-time regeneration):\n"
            . "Your previous draft asserted document content without citations. "
            . "Re-answer the question now, citing the provided file content with [N] markers "
            . "for EVERY claim drawn from the documents (rules, regulations, policies, or any "
            . "other document contents). If the documents provided do not actually "
            . "contain the answer, state plainly that the documents provided do not contain "
            . "the answer — never answer such a question from general knowledge.\n"
            . "Your previous draft (do NOT restate its uncited claims):\n"
            . $answer;

        try {
            $retry = trim((string) $this->llm->complete(
                $userPrompt . $instruction,
                $systemPrompt !== '' ? $systemPrompt : null,
                $llmOptions
            ));
        } catch (\Throwable $e) {
            return [$answer, 'failed'];
        }

        if ($retry === '') {
            return [$answer, 'failed'];
        }

        if (preg_match(self::CITATION_MARKER_PATTERN, $retry) === 1) {
            return [$retry, 'recovered'];
        }

        return [$retry, 'failed'];
    }

    // =========================================================================
    // TRUNCATION/COUNT HONESTY GUARD (blocking, structural)
    // =========================================================================

    /**
     * Trigger: config on, rows exist, and the result total (companion-count true
     * total when stamped, else the retrieved row count) EXCEEDS the rows rendered
     * into the prompt's data sample. Purely structural — counts only.
     */
    private function truncationHonestyApplies(PipelineContext $context): bool
    {
        if (!config('ai.response_generation.truncation_honesty', true)) {
            return false;
        }

        if (empty($context->executionResult->data)) {
            return false;
        }

        return $this->resultTotal($context) > $this->renderedRowCount($context);
    }

    /**
     * The total the answer must disclose: the companion count's true_total
     * (AiManager stamps it into executionResult stats+metadata when execution
     * returned exactly the executor cap) when it is at least the retrieved row
     * count, else the retrieved row count itself.
     */
    private function resultTotal(PipelineContext $context): int
    {
        $count = count($context->executionResult->data);
        $trueTotal = $context->executionResult->metadata['true_total']
            ?? $context->executionResult->stats['true_total']
            ?? null;

        return is_numeric($trueTotal) && (int) $trueTotal >= $count ? (int) $trueTotal : $count;
    }

    /**
     * How many rows ResultsDataSection actually rendered into the prompt —
     * read from the SAME module instance that built the prompt, so trigger and
     * prompt can never disagree.
     */
    private function renderedRowCount(PipelineContext $context): int
    {
        $maxItems = 10;
        $section = $this->getModule('data');
        if ($section instanceof ResultsDataSection) {
            $maxItems = $section->getMaxItems();
        }

        return min(count($context->executionResult->data), max(0, $maxItems));
    }

    /**
     * BLOCKING enforcement: [answer, 'clean'|'recovered'|'patched'].
     *
     * 'clean' — the draft already contains the EXACT total as a literal number
     * AND additionally the shown count or the exact remainder (shared
     * StatedNumber::disclosesTruncation rule — a bare total like "more than
     * 523 results" is NOT clean). Otherwise ONE regeneration with a hard
     * instruction (only when the initial prompt pair is the full transcript);
     * if the disclosure is STILL missing (or the retry errored/was skipped),
     * the localized disclosure line is APPENDED deterministically — the shipped
     * answer can NEVER claim completeness silently. The original draft is kept
     * on the patch path: a retry that failed to add the numbers is not trusted
     * to replace the draft.
     *
     * @return array{0: string, 1: string}
     */
    private function enforceTruncationDisclosure(
        string $answer,
        PipelineContext $context,
        string $systemPrompt,
        string $userPrompt,
        array $llmOptions,
        bool $retryIsFaithful,
        ?string $locale
    ): array {
        $total = $this->resultTotal($context);
        $shown = $this->renderedRowCount($context);
        $remainder = $total - $shown;

        if (StatedNumber::disclosesTruncation($answer, $total, $shown)) {
            return [$answer, 'clean'];
        }

        if ($retryIsFaithful) {
            $instruction = "\n\nIMPORTANT — RESULT COMPLETENESS (this is a one-time regeneration):\n"
                . "The data shown to you contains only the first {$shown} of {$total} matching rows. "
                . "Your previous draft presented them without disclosing this properly. Re-answer the "
                . "question now and state the numbers EXACTLY: the total is {$total} — write that exact "
                . "number, never an approximation like 'more than {$total}' or 'over {$total}' — followed "
                . "by the noun for what the rows in the data actually are (their entity), not the word "
                . "'results'. ALSO state either that {$shown} of them are shown or that {$remainder} more "
                . "exist. Never present the visible rows as the complete list.\n"
                . "Your previous draft:\n"
                . $answer;

            try {
                $retry = trim((string) $this->llm->complete(
                    $userPrompt . $instruction,
                    $systemPrompt !== '' ? $systemPrompt : null,
                    $llmOptions
                ));

                if ($retry !== '' && StatedNumber::disclosesTruncation($retry, $total, $shown)) {
                    return [$retry, 'recovered'];
                }
            } catch (\Throwable $e) {
                // Fall through to the deterministic patch — the guarantee holds without an LLM.
            }
        }

        $disclosure = trans('ai.response.truncation_disclosure', ['shown' => $shown, 'total' => $total], $locale);

        return [rtrim($answer) . "\n\n" . $disclosure, 'patched'];
    }

    /**
     * Deterministic post-composition re-check of the BLOCKING truncation
     * guarantee: if a later guard's retry dropped the disclosed total/remainder,
     * the localized disclosure line is appended again and the flag becomes
     * 'patched'. Zero LLM — the guarantee holds without one.
     *
     * @return array{0: string, 1: string}
     */
    private function reassertTruncationDisclosure(
        string $answer,
        PipelineContext $context,
        string $flag,
        ?string $locale
    ): array {
        $total = $this->resultTotal($context);
        $shown = $this->renderedRowCount($context);

        if (StatedNumber::disclosesTruncation($answer, $total, $shown)) {
            return [$answer, $flag];
        }

        $disclosure = trans('ai.response.truncation_disclosure', ['shown' => $shown, 'total' => $total], $locale);

        return [rtrim($answer) . "\n\n" . $disclosure, 'patched'];
    }

    // =========================================================================
    // SELF-IDENTITY-AS-CONTACT GUARD (structural)
    // =========================================================================

    /**
     * The authenticated user's email, THE pipeline way: the threaded
     * $options['user'] first (what production passes end-to-end), the auth
     * container as fallback (mirrors SelfReferenceNoticeSection::authUser()).
     */
    private function currentUserEmail(array $options): ?string
    {
        $user = $options['user'] ?? null;
        $email = null;
        if (is_object($user)) {
            $email = $user->email ?? null;
        } elseif (is_array($user)) {
            $email = $user['email'] ?? null;
        }

        if (!is_string($email) || trim($email) === '') {
            try {
                if (function_exists('auth') && auth()->check()) {
                    $email = auth()->user()->email ?? null;
                }
            } catch (\Throwable $e) {
                $email = null; // no auth context (console/queue)
            }
        }

        return is_string($email) && trim($email) !== '' ? trim($email) : null;
    }

    /**
     * [answer, 'clean'|'recovered'|'patched'].
     *
     * Adjacency = SAME LINE: every line carrying the user's own email must also
     * carry a "that's-you" marker (wordings from
     * SelfReferenceNoticeSection::markerPhrases() — the same source the prompt
     * section instructs the model with, plus the localized lang wordings; never a
     * parallel list). A marker elsewhere in the answer does NOT excuse an
     * unmarked contact-style line — that was the exact observed live failure.
     *
     * @return array{0: string, 1: string}
     */
    private function enforceSelfReferenceMarker(
        string $answer,
        string $email,
        string $systemPrompt,
        string $userPrompt,
        array $llmOptions,
        bool $retryIsFaithful,
        ?string $locale
    ): array {
        $markers = \Condoedge\Ai\Services\ResponseSections\SelfReferenceNoticeSection::markerPhrases($locale);

        if (!$this->hasUnmarkedEmailLine($answer, $email, $markers)) {
            return [$answer, 'clean'];
        }

        if ($retryIsFaithful) {
            $exampleMarker = \Condoedge\Ai\Services\ResponseSections\SelfReferenceNoticeSection::THATS_YOU_MARKERS[0];
            $instruction = "\n\nIMPORTANT — SELF-REFERENCE (this is a one-time regeneration):\n"
                . "The email '{$email}' IS the current user's own email address — it belongs to the very "
                . "person you are answering. Your previous draft presented it without acknowledging that. "
                . "Re-answer the question now: every time you mention '{$email}', acknowledge on the same "
                . "line that it is the user's own (e.g. \"{$exampleMarker}\"), and NEVER present it as "
                . "contact information, as \"how to reach\" anyone, or as belonging to someone else.\n"
                . "Your previous draft:\n"
                . $answer;

            try {
                $retry = trim((string) $this->llm->complete(
                    $userPrompt . $instruction,
                    $systemPrompt !== '' ? $systemPrompt : null,
                    $llmOptions
                ));

                if ($retry !== '') {
                    if (mb_stripos($retry, $email) === false
                        || !$this->hasUnmarkedEmailLine($retry, $email, $markers)) {
                        return [$retry, 'recovered'];
                    }
                    // The retry still violates: patch the retry (the freshest text).
                    $answer = $retry;
                }
            } catch (\Throwable $e) {
                // Fall through to the deterministic patch.
            }
        }

        return [$this->insertSelfReferenceMarker($answer, $email, $locale), 'patched'];
    }

    /**
     * Whether any line contains the email (case-insensitive literal) with no
     * marker phrase on that same line. Comparison runs on the typographically
     * normalized line (SelfReferenceNoticeSection::normalizeMarkerText), so a
     * compliant U+2019 "that’s you" / "c’est vous" counts as marked.
     *
     * @param list<string> $markers normalized marker phrases
     */
    private function hasUnmarkedEmailLine(string $answer, string $email, array $markers): bool
    {
        foreach (preg_split('/\R/', $answer) ?: [] as $line) {
            if (mb_stripos($line, $email) === false) {
                continue;
            }

            $lineLower = \Condoedge\Ai\Services\ResponseSections\SelfReferenceNoticeSection::normalizeMarkerText($line);
            $marked = false;
            foreach ($markers as $marker) {
                if ($marker !== '' && mb_strpos($lineLower, $marker) !== false) {
                    $marked = true;
                    break;
                }
            }

            if (!$marked) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deterministic patch: insert the localized "(that's you — ...)" marker
     * immediately after the FIRST unmarked occurrence of the email. Preserves
     * the answer byte-for-byte otherwise.
     */
    private function insertSelfReferenceMarker(string $answer, string $email, ?string $locale): string
    {
        $patch = ' (' . trans('ai.response.self_reference_patch', [], $locale) . ')';
        $markers = \Condoedge\Ai\Services\ResponseSections\SelfReferenceNoticeSection::markerPhrases($locale);

        $parts = preg_split('/(\R)/', $answer, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$answer];
        foreach ($parts as $i => $line) {
            if ($i % 2 === 1) {
                continue; // newline separators
            }
            $pos = mb_stripos($line, $email);
            if ($pos === false) {
                continue;
            }

            $lineLower = \Condoedge\Ai\Services\ResponseSections\SelfReferenceNoticeSection::normalizeMarkerText($line);
            $marked = false;
            foreach ($markers as $marker) {
                if ($marker !== '' && mb_strpos($lineLower, $marker) !== false) {
                    $marked = true;
                    break;
                }
            }
            if ($marked) {
                continue;
            }

            $end = $pos + mb_strlen($email);
            $parts[$i] = mb_substr($line, 0, $end) . $patch . mb_substr($line, $end);

            return implode('', $parts);
        }

        return $answer;
    }

    /**
     * Locale for guard patch copy: the QU-detected question language first (the
     * answer is written in it), the threaded question_language option next, null
     * (app locale) last.
     */
    private function answerLocale(PipelineContext $context, array $options): ?string
    {
        if ($context->qu->language !== '') {
            return $context->qu->language;
        }

        return $this->questionLocale($options);
    }

    /**
     * The native loop starts from the SAME role-split section prompt as the markdown
     * path, expressed as chat messages.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function toolLoopMessages(string $systemPrompt, string $userPrompt): array
    {
        $messages = [];
        if ($systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $userPrompt];

        return $messages;
    }

    /**
     * Dispatch context for in-loop action execution — the same keys the render-time link
     * path passes (user, result_set). The runner adds entity_type/entity_id per call.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function toolLoopContext(PipelineContext $context, array $options): array
    {
        $dispatch = ['result_set' => $context->executionResult->data];
        if (isset($options['user'])) {
            $dispatch['user'] = $options['user'];
        }

        return $dispatch;
    }

    /**
     * Build the prompt split by role.
     *
     * Each section declares role() => 'system' | 'user'. System sections (identity,
     * security policy) are concatenated into the system message; everything else into
     * the user message. Section ORDER is preserved within each role (the pipeline is
     * still processed in priority order). Before/after callbacks are appended to the
     * user message to preserve their historical placement.
     *
     * @param PipelineContext $context Pipeline context with question, data, etc.
     * @param array $options Options for sections
     * @return array{system: string, user: string}
     */
    public function buildPrompts(PipelineContext $context, array $options = []): array
    {
        $system = '';
        $user = '';

        $this->processModules(
            beforeCallbackProcess: function($callback) use (&$user, $context, $options) {
                $user .= $callback($context, $options);
            },
            moduleProcess: function(ResponseSectionInterface $section) use (&$system, &$user, $context, $options) {
                if (!$section->shouldInclude($context, $options)) {
                    return;
                }

                $content = $section->format($context, $options);

                if ($section->role() === 'system') {
                    $system .= $content;
                } else {
                    $user .= $content;
                }
            },
            afterCallbackProcess: function($callback) use (&$user, $context, $options) {
                $user .= $callback($context, $options);
            }
        );

        return ['system' => $system, 'user' => $user];
    }

    /**
     * Generate response for empty results
     *
     * @param string $originalQuestion User's original question
     * @param string $cypherQuery The query that returned no results
     * @param array $options Generation options
     * @return array Response explaining why no results were found
     */
    public function generateEmptyResponse(
        string $originalQuestion,
        ?string $cypherQuery,
        array $options = []
    ): array {
        $format = $options['format'] ?? 'text';
        $temperature = $options['temperature'] ?? 0.3;

        // Carry the same system-role policy (identity, non-disclosure, privacy) as the
        // grounded path — abstention must not run with a null system prompt, or a
        // "which model are you?" question detours here and self-identifies the provider.
        // The executed query is intentionally NOT placed in the prompt (schema must not leak).
        $context = new PipelineContext();
        $context->question = $originalQuestion;
        ['system' => $systemPrompt] = $this->buildPrompts($context, ['format' => $format]);

        // The LLM-facing scaffold is neutral English on purpose: localizing it (via __()) forces
        // the model into the app locale even when the question was asked in another language. The
        // reply language is driven by the question itself plus the system-prompt language policy.
        $prompt = "The user asked: \"{$originalQuestion}\"\n\n";
        $prompt .= "No matching records were found for this question. Briefly and helpfully tell the "
            . "user that no results were found, then offer the most relevant next step: the closest "
            . "matching action link when one is offered below, or ONE more specific question that is "
            . "likely to succeed. Do NOT end with a bare invitation like \"feel free to ask\".\n\n";

        // Follow-up awareness: "make a PDF of that" / "export it" style requests reference the
        // PREVIOUS turn, not new data. Without the previous turn's information this path can
        // only say "no results" to a request the user just watched succeed — worse than wrong.
        $conversation = $options['conversation_context'] ?? [];
        $lastSample = is_array($conversation['last_result_sample'] ?? null) ? $conversation['last_result_sample'] : [];
        if (!empty($lastSample)) {
            $sampleJson = json_encode(array_slice($lastSample, 0, 10), JSON_UNESCAPED_UNICODE);
            $prompt .= "EXCEPTION — information from the previous turn (most recently shown to the user): {$sampleJson}\n";
            $prompt .= "If the question is actually a request ABOUT that previous information — format it, "
                . "summarize it, turn it into a document, export it as a PDF or Excel — do NOT say no results "
                . "were found. Fulfill it instead: summarize what will be included and provide the matching "
                . "export action link. Keep the visible answer concise; document-mode exports should use the "
                . "clean export document payload supplied by the pipeline when available.\n\n";
        }
        // If the question is really a request to DO something the app supports (book/reserve/contact
        // support/...), offer that action as a link instead of a dead-end "no results".
        $actionsHint = $this->emptyResponseActionsHint();
        if ($actionsHint !== '') {
            $prompt .= $actionsHint . "\n\n";
        }
        $prompt .= "Respond in the SAME language as the user's question above.";

        try {
            $answer = $this->llm->complete($prompt, $systemPrompt !== '' ? $systemPrompt : null, [
                'temperature' => $temperature,
                'max_tokens' => 200,
            ]);

            $metadata = [
                'empty_result' => true,
                'result_count' => 0,
            ];
            // Same capture seam as generate(): the abstention path is a real LLM
            // call, so an eval trace must carry its verbatim prompts too.
            if (($options['capture_prompts'] ?? false) === true) {
                $metadata['prompts'] = ['system' => $systemPrompt, 'user' => $prompt];
            }

            // LANGUAGE POLICY (same as generateErrorResponse): canned strings on
            // the abstention path must follow the QUESTION's language, not the
            // app locale — an fr-locale host answering an English question must
            // not mix French canned insights into an English answer.
            $locale = $this->questionLocale($options);

            return [
                'answer' => trim($answer),
                'insights' => [trans('ai.insight.no_results', [], $locale)],
                'visualizations' => [],
                'format' => $format,
                'metadata' => $metadata,
            ];

        } catch (\Exception $e) {
            $locale = $this->questionLocale($options);

            return [
                'answer' => trans('ai.response.no_results_fallback', ['question' => $originalQuestion], $locale),
                'insights' => [trans('ai.insight.no_results', [], $locale)],
                'visualizations' => [],
                'format' => $format,
                'metadata' => [
                    'empty_result' => true,
                    'result_count' => 0,
                    'fallback' => true,
                ],
            ];
        }
    }

    /**
     * A hint listing the available generic actions, so an action request that returns no data
     * (e.g. "book a reservation") is answered with the matching action link instead of a dead-end
     * "no results". The registry is the single source of truth (the same actions the response
     * sections and the action link handler use). Returns '' when none are configured/resolvable.
     */
    private function emptyResponseActionsHint(): string
    {
        try {
            // 90c W6: only actions THIS user survives authorize() on are offered — the
            // renderer strips the rest, which would read as a dead offer. Same principal
            // resolution as the dispatcher's render-time gate (auth user, null if no context).
            $user = null;
            try {
                $user = function_exists('auth') ? auth()->user() : null;
            } catch (\Throwable $e) {
                // no auth context (console/queue) — gated actions fail closed
            }

            $actions = app(\Condoedge\Ai\Services\Discovery\EntityAutoDiscovery::class)->getAuthorizedGenericActions($user);
        } catch (\Throwable $e) {
            return '';
        }

        $lines = [];
        foreach ($actions as $action) {
            $key = $action['action_key'] ?? null;
            if (!$key) {
                continue;
            }
            $label = $action['label'] ?? $key;
            $aliases = !empty($action['aliases']) ? ' (e.g. ' . implode(', ', $action['aliases']) . ')' : '';
            $lines[] = "- [{$label}](action://{$key}){$aliases}";
        }

        if (empty($lines)) {
            return '';
        }

        return "If the user's request actually asks to DO one of these, offer it as a clickable "
            . "markdown link instead of saying no results were found. Otherwise, when one of these is "
            . "clearly the most relevant next step for the question, offer it as a helpful follow-up:\n"
            . implode("\n", $lines);
    }

    /**
     * Generate response for error cases
     *
     * @param string $originalQuestion User's original question
     * @param \Throwable $error The error that occurred
     * @param array $options Generation options
     * @return array User-friendly error response
     */
    public function generateErrorResponse(
        string $originalQuestion,
        \Throwable $error,
        array $options = []
    ): array {
        $format = $options['format'] ?? 'text';
        $includeDetails = $options['include_details'] ?? false;

        // LANGUAGE POLICY: the error answer must follow the QUESTION's language,
        // not the app locale — a French-locale host answering an English question
        // must not emit the French canned error. The language is the QU-detected
        // question language threaded via options (no in-package detection lists);
        // null keeps the translator on the app locale exactly as before.
        $locale = $this->questionLocale($options);

        // User-friendly error message
        $answer = trans('ai.error.encountered_issue', ['question' => $originalQuestion], $locale) . ' ';

        // Add specific guidance based on error type
        if (str_contains($error->getMessage(), 'timeout')) {
            $answer .= trans('ai.error.timeout_guidance', [], $locale);
        } elseif (str_contains($error->getMessage(), 'syntax')) {
            $answer .= trans('ai.error.syntax_guidance', [], $locale);
        } else {
            $answer .= trans('ai.error.generic_guidance', [], $locale);
        }

        $metadata = [
            'error' => true,
            'error_type' => get_class($error),
        ];

        if ($includeDetails) {
            $metadata['error_message'] = $error->getMessage();
        }

        return [
            'answer' => $answer,
            'insights' => [trans('ai.insight.error_occurred', [], $locale)],
            'visualizations' => [],
            'format' => $format,
            'metadata' => $metadata,
        ];
    }

    /**
     * The locale error/fallback copy should render in: the QU-detected question
     * language when threaded ($options['question_language']), else null so the
     * translator uses the app locale (unchanged previous behavior).
     */
    private function questionLocale(array $options): ?string
    {
        $language = $options['question_language'] ?? null;

        return is_string($language) && trim($language) !== '' ? trim($language) : null;
    }

    /**
     * Extract insights from data
     *
     * @param array $queryResult Query results to analyze
     * @return array Array of insights (patterns, outliers, trends)
     */
    public function extractInsights(array $queryResult): array
    {
        $insights = [];

        // Basic count insight
        $count = count($queryResult);
        $insights[] = trans_choice('ai.insight.found_results', $count, ['count' => $count]);

        // Detect if numeric data
        if ($this->isNumericData($queryResult)) {
            $stats = $this->calculateStatistics($queryResult);

            if ($stats) {
                $insights[] = __('ai.insight.average_value', ['value' => round($stats['avg'], 2)]);

                if ($stats['max'] > $stats['avg'] * 2) {
                    $insights[] = __('ai.insight.high_values');
                }

                if ($stats['min'] < $stats['avg'] * 0.5 && $stats['min'] > 0) {
                    $insights[] = __('ai.insight.low_values');
                }
            }
        }

        // Raw property/column names are schema details that must not leak into
        // user-facing insights, so no "properties: id, name, ..." insight is emitted.
        // The result count already conveys shape.

        return $insights;
    }

    /**
     * Suggest appropriate visualizations
     *
     * @param array $queryResult Query results
     * @param string $cypherQuery Original query
     * @return array Suggested visualization types with rationale
     */
    public function suggestVisualizations(array $queryResult, ?string $cypherQuery): array
    {
        $suggestions = [];

        if (empty($queryResult)) {
            return $suggestions;
        }

        $count = count($queryResult);
        $firstItem = $queryResult[0];

        // Detect count queries
        if (isset($firstItem['count']) || stripos($cypherQuery, 'count(') !== false) {
            $suggestions[] = [
                'type' => 'number',
                'rationale' => 'Query returns a count value, best displayed as a number or KPI card',
            ];
        }

        // Detect relationship queries
        if (stripos($cypherQuery, 'MATCH') !== false && stripos($cypherQuery, '-[') !== false) {
            $suggestions[] = [
                'type' => 'graph',
                'rationale' => 'Query involves relationships, suitable for graph visualization',
            ];
        }

        // Detect list/table data
        if ($count > 1 && count(array_keys($firstItem)) > 2) {
            $suggestions[] = [
                'type' => 'table',
                'rationale' => 'Multiple results with several properties, suitable for table display',
            ];
        }

        // Detect grouping/aggregation
        if (stripos($cypherQuery, 'GROUP BY') !== false ||
            stripos($cypherQuery, 'count(') !== false && $count > 1) {
            $suggestions[] = [
                'type' => 'bar-chart',
                'rationale' => 'Aggregated data, suitable for bar or column chart',
            ];
        }

        // Detect time series
        if ($this->hasTimeComponent($queryResult)) {
            $suggestions[] = [
                'type' => 'line-chart',
                'rationale' => 'Data contains time component, suitable for line chart',
            ];
        }

        // Default fallback
        if (empty($suggestions)) {
            $suggestions[] = [
                'type' => 'table',
                'rationale' => 'General purpose table display for structured data',
            ];
        }

        return $suggestions;
    }

    // =========================================================================
    // PRIVATE HELPER METHODS
    // =========================================================================

    /**
     * Check if data contains numeric values
     */
    private function isNumericData(array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        foreach ($data[0] as $value) {
            if (is_numeric($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate statistics for numeric data
     */
    private function calculateStatistics(array $data): ?array
    {
        $numericValues = [];

        foreach ($data as $row) {
            foreach ($row as $value) {
                if (is_numeric($value)) {
                    $numericValues[] = (float) $value;
                }
            }
        }

        if (empty($numericValues)) {
            return null;
        }

        return [
            'count' => count($numericValues),
            'sum' => array_sum($numericValues),
            'avg' => array_sum($numericValues) / count($numericValues),
            'min' => min($numericValues),
            'max' => max($numericValues),
        ];
    }

    /**
     * Check if data has time component
     */
    private function hasTimeComponent(array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        $firstItem = $data[0];

        foreach (array_keys($firstItem) as $key) {
            $keyLower = strtolower($key);
            if (in_array($keyLower, ['date', 'time', 'timestamp', 'created_at', 'updated_at', 'datetime'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate max tokens based on word limit
     */
    private function calculateMaxTokens(int $maxWords): int
    {
        return (int) ceil($maxWords / 0.75);
    }
}
