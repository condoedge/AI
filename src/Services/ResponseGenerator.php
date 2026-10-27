<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\ResponseGeneratorInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\ResponseSectionInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Actions\Attach\AttachedActions;
use Condoedge\Ai\Services\Response\AnswerValidator;
use Condoedge\Ai\Services\Response\DataAnswerPlan;
use Condoedge\Ai\Services\Response\EntityIdIndex;
use Condoedge\Ai\Services\Response\HostText;
use Condoedge\Ai\Services\Response\NarrativeLinkFilter;
use Condoedge\Ai\Services\Response\PiiMasker;
use Condoedge\Ai\Services\Response\QueriedKinds;
use Condoedge\Ai\Services\ResponseSections\SystemPromptSection;
use Condoedge\Ai\Services\ResponseSections\ResponseCurrentUserContextSection;
use Condoedge\Ai\Services\ResponseSections\ResponseProjectContextSection;
use Condoedge\Ai\Services\ResponseSections\ResultsDataSection;
use Condoedge\Ai\Services\ResponseSections\GuidelinesSection;
use Condoedge\Ai\Contracts\PipelineObserverInterface;
use Condoedge\Ai\Support\PipelineObserverScope;
use Condoedge\Ai\Support\ArticleLocator;
use Condoedge\Ai\Support\StatedNumber;
use Condoedge\Ai\Support\TokenUsage;

/**
 * ResponseGenerator — turns a PipelineContext into a natural-language answer.
 *
 * generate() assembles a role-split prompt via the priority-ordered section
 * pipeline (buildPrompts() returns {system, user}: identity + security policy in
 * the system role, the untrusted question + data in the user role) and calls the
 * LLM. Sections are configured under `config('ai.response_generator_sections')`
 * and managed through the HasInternalModules trait (addModule/removeModule/
 * getModule). Returns: answer, format, metadata.
 *
 * Links and actions are attached by CODE (actions plan B1–B5, ActionAttacher via
 * PipelineContext::$attachedActions): the model only narrates — AttachedActionsSection
 * gives it one line + the attached chip labels, and it may reference a row inline with
 * {{record:N}}, which generate() substitutes with that row's record link.
 *
 * @uses HasInternalModules<ResponseSectionInterface>
 * @see ResponseSectionInterface For creating custom sections.
 */
class ResponseGenerator implements ResponseGeneratorInterface
{
    use HasInternalModules;

    protected $defaultModulesConfigKey = 'ai.response_generator_sections';

    /**
     * Styles whose explicit contract forbids inline citation markers (mirrors
     * GuidelinesSection/FileContextSection TERSE_STYLES) — citation validation
     * must never fight an instruction the prompt itself gave.
     */
    private const CITATION_TERSE_STYLES = ['minimal', 'concise'];

    /** The PII masker of the generation in progress (null outside generate()). */
    private ?PiiMasker $turnMasker = null;

    /**
     * Constructor
     *
     * @param LlmProviderInterface $llm LLM provider for response generation
     * @param array $config Configuration options
     */
    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly array $config = []
    ) {
        $this->registerDefaultModules();
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
     * @return array Response with answer, format, metadata
     * @throws \RuntimeException If response generation fails
     */
    public function generate(
        PipelineContext $context,
        array $options = []
    ): array {
        $format = $options['format'] ?? $this->config['default_format'] ?? 'text';
        $style = $options['style'] ?? $this->config['default_style'] ?? 'detailed';
        $maxLength = $options['max_length'] ?? $this->config['default_max_length'] ?? 500;
        $temperature = $options['temperature'] ?? 0.3;

        $sectionOptions = [
            'format' => $format,
            'style' => $style,
            'max_length' => $maxLength,
        ];

        // DETERMINISTIC DATA ANSWERS + PII MASKING (Phase 8 batch A). One fresh
        // per-turn token map and data plan, shared by every section of this
        // generation: the LLM sees a compact masked summary + facts/system-computed
        // totals and writes only the narrative with a {{table}} placeholder; the
        // table is rendered by code from ALL rows (up to the display cap) after
        // generation, and the real identifying values are substituted back.
        // The token map is the TURN's (created before QU by ContextRetriever — P-1):
        // reused, never reset, so a token means the same value in every prompt of
        // the turn; PiiMasker::for() widens it with this turn's query labels.
        $context->dataAnswerPlan = null;
        $masker = PiiMasker::for($context);
        $this->turnMasker = $masker;
        $plan = !empty($context->executionResult->data) ? DataAnswerPlan::for($context) : null;
        $sectionOptions[DataAnswerPlan::OPTION] = DataAnswerPlan::tableModeFor($sectionOptions);
        $tableMode = $plan !== null && $sectionOptions[DataAnswerPlan::OPTION] && $plan->hasTable();

        // Build prompt using section pipeline, split into a system message
        // (identity + security policy) and a user message (context + data).
        ['system' => $systemPrompt, 'user' => $userPrompt] = $this->buildPrompts($context, $sectionOptions);

        // p20-A roster: the identity values the prompt just GAVE the model (the user's own
        // name, their team's) are known words when code judges whether a lead only restates.
        $plan?->knownIdentity($this->promptIdentityValues($context, $sectionOptions));

        // Safety net: any identifying value that received a token this turn is
        // replaced wherever else it surfaced in the composed prompt (entity-action
        // listings, the question itself, host-added sections).
        $userPrompt = $masker->maskText($userPrompt);
        $systemPrompt = $masker->maskText($systemPrompt);

        $data = $context->executionResult->data;

        $llmOptions = [
            'temperature' => $temperature,
            'max_tokens' => $this->calculateMaxTokens($maxLength),
        ];

        try {
            // Generate response — identity/security ride in the real system role,
            // untrusted context/data stays in the user role.
            $answer = $this->observedComplete(
                PipelineObserverScope::for($context),
                $userPrompt,
                $systemPrompt !== '' ? $systemPrompt : null,
                $llmOptions
            );

            // ONE post-composition validation pass (Phase 8 batch B) on the MASKED
            // narrative — the retry prompt quotes the draft, so it must never carry a
            // real value; a real value the model echoed is normalized to its token.
            $answer = $masker->maskText((string) $answer);

            $answerLocale = $this->answerLocale($context, $options);
            $validator = $this->answerValidator(
                $context,
                $options,
                $style,
                $plan,
                $tableMode,
                $masker,
                $answerLocale,
                $systemPrompt . "\n" . $userPrompt
            );
            $draft = $answer;
            [$answer, $validation, $guardFlags] = $this->validateAnswer(
                $validator,
                $answer,
                $systemPrompt,
                $userPrompt,
                $llmOptions,
                PipelineObserverScope::for($context)
            );
            $guardFlags += $this->truncationAndSelfReferenceFlags($validator, $validation, $draft, $answer, $tableMode, $plan);


            // What the USER sees: real values back in the narrative, then the
            // code-rendered table (or single record) in place of {{table}} — the answer
            // ENDS there: text the model wrote after the placeholder is dropped, and so
            // is a list / table of the lead that restates the rendered rows (p17 — judged
            // on the unmasked lead against the real rows; DataAnswerPlan::finalize).
            // Stray placeholders are stripped on every other path.
            // B5, enforced in code: every link the MODEL wrote becomes plain text (only an
            // external URL quoted from a cited passage or a mailto/tel of a row value
            // survives) — then {{record:N}} becomes the row's code-built, authorized link
            // (an unknown / unauthorized N is stripped). Both run on the NARRATIVE only,
            // BEFORE unmasking and the code-rendered table, so record CONTENT (a cell
            // value, a real identifying value) is never parsed as a link or placeholder.
            $answer = $this->narrativeLinkFilter($context, $context->executionResult->data, $masker)->apply($answer);
            $answer = $context->attachedActions !== null
                ? $context->attachedActions->substitute($answer)
                : AttachedActions::strip($answer);

            // THE UNGROUNDED CLOSING PARAGRAPH (eval p16–p20: a platitude, "Next steps are
            // attached below…" after a fully cited answer, against the prompt's own ban). A
            // document-grounded answer cites every claim, so its last paragraph, when it carries
            // no marker after two cited blocks AND is grounded in nothing — its words are neither
            // the passages', nor the cited blocks', nor the question's — is dropped, by position
            // and structure (AnswerValidator::withoutUncitedClosing). An uncited SUMMARY of the
            // cited blocks stays: on a yes/no question it is the verdict (p20-A tear-down lost
            // its only conclusion to the former passage-only test). After the link filter: a
            // pointer whose model-written link was just turned to plain text is judged as the
            // prose it now is. Knowledge turns only: on a data turn the narrative is a lead in
            // front of code-rendered rows, bounded by DataAnswerPlan.
            $closingDropped = null;
            if ($plan === null && $validator->requiresCitations()) {
                [$answer, $closingDropped] = $validator->withoutUncitedClosing($answer, (string) $context->question);
            }

            $answer = $masker->unmask($answer);

            // THE LABEL ECHO (eval p21-B rights t2: "Attached below this answer: Receivables
            // report." — the narrator instruction's own label, copied into the answer). A
            // sentence of the MODEL's narrative that is nothing but this turn's button labels
            // after a colon is dropped: the buttons are right below, and the instruction says
            // never to list them. Structure only (AttachedActions::withoutLabelEcho), on the
            // unmasked narrative BEFORE the code-rendered block: a record's own value line of
            // that block is never read. A data lead may go whole — code's lines and the table
            // are the answer; any other answer is never left empty.
            $labelEcho = [];
            if ($context->attachedActions !== null) {
                [$answer, $labelEcho] = $context->attachedActions->withoutLabelEcho($answer, $plan !== null);
            }

            $selfGuardOn = (bool) config('ai.response_generation.self_reference_guard', true);
            if ($plan !== null) {
                // F1: the table carries REAL values and is inserted after validation —
                // it marks the user's own identity values itself.
                $answer = $plan->finalize($answer, $tableMode, $answerLocale, $selfGuardOn ? $this->currentUser($options) : null);
            }
            // F1: the self-reference guard also judges what SHIPS (after unmask + table),
            // so nothing inserted after validation can present the user's own email unmarked.
            if ($selfGuardOn) {
                [$answer, $guardFlags] = $this->finalSelfReferenceGuard($answer, $guardFlags, $options, $answerLocale, $tableMode, $plan);
            }

            $metadata = [
                'style' => $style,
                'result_count' => count($data),
                // The rows the user was actually shown: the code-rendered table on
                // the table path, else the rows rendered into the prompt sample
                // (read from the live ResultsDataSection — hosts may raise
                // setMaxDataItems). The eval discloses_total gate reads this, so
                // gate and runtime share one trigger.
                'result_shown' => $tableMode ? $plan->shown() : $this->renderedRowCount($context),
                'summarized' => count($data) > 10,
            ];
            if ($tableMode) {
                $metadata['table_rendered'] = true;
                // Trace evidence (D2 / D5): the block is ONE record's fields; text the
                // model wrote after {{table}} was dropped (code owns the answer's tail).
                if ($plan->isRecord()) {
                    $metadata['record_rendered'] = true;
                }
                if ($plan->tailDropped()) {
                    $metadata['narrative_tail_dropped'] = true;
                }
                // p17: list / table blocks of the lead that restated the rendered rows were
                // dropped in front of the block (a count of blocks — never their content).
                if ($plan->rowEchoDropped() > 0) {
                    $metadata['row_echo_dropped'] = $plan->rowEchoDropped();
                }
                // G3: headers of the columns the rendered block leaves out because no
                // retrieved row holds a value for them (column names only, never values).
                if ($plan->hiddenColumns() !== []) {
                    $metadata['empty_columns_hidden'] = $plan->hiddenColumns();
                }
                // p18: which facts CODE printed on the line above the block (count / groups /
                // homonyms / not_recorded — the part names, never their content).
                if ($plan->factsStated() !== []) {
                    $metadata['facts_line'] = $plan->factsStated();
                }
                // p19: what code cut from the model's lead beside that line — the text after
                // its first sentence ('one_sentence'), a lead that only restated the question /
                // the line ('restatement'). The text is the PII-masked narrative's.
                if ($plan->leadTrimmed() !== []) {
                    $metadata['lead_trimmed'] = $plan->leadTrimmed();
                }
            }
            // p19: the uncited closing paragraph dropped from a document-grounded answer (the
            // masked narrative's own text — what the model wrote, never a passage or a row).
            if ($closingDropped !== null) {
                $metadata['closing_dropped'] = $closingDropped;
            }
            // p21: the sentences that only listed this turn's button labels (the model's own
            // text around labels the response already carries in `attached_actions`).
            if ($labelEcho !== []) {
                $metadata['label_echo_dropped'] = $labelEcho;
            }
            if ($masker->map() !== []) {
                // Evidence only (a count — never the values themselves).
                $metadata['pii_masked_values'] = count($masker->map());
            }
            // The population's TRUE total when the companion count established one
            // beyond the retrieved rows — gate/grader evidence beside result_count.
            if ($this->resultTotal($context) > count($data)) {
                $metadata['result_total'] = $this->resultTotal($context);
            }
            // The validation outcome (types only — details of unresolved violations
            // ride `unresolved`), plus the former guards' flags derived from it so the
            // eval trace keeps reading them: citation_enforcement =
            // recovered|failed, truncation_honesty =
            // clean|recovered|patched|table, self_reference_guard = clean|recovered|patched|table
            // (table = the code-rendered table marked the user's own value itself).
            $metadata['validation'] = $validation;
            foreach ($guardFlags as $flag => $value) {
                $metadata[$flag] = $value;
            }

            return [
                'answer' => trim($answer),
                'format' => $format,
                'metadata' => $metadata,
            ];

        } catch (\Exception $e) {
            // Wrap-and-rethrow (same contract as the previous bare RuntimeException —
            // ResponseGenerationException extends it). The verbatim prompt already
            // reached the turn observer (promptBuilt), so a failure stays inspectable.
            throw new \Condoedge\Ai\Exceptions\ResponseGenerationException(
                "Response generation failed: " . $e->getMessage(),
                0,
                $e
            );
        } finally {
            $this->turnMasker = null;
        }
    }

    /**
     * ONE validation pass: validate the draft → ONE combined retry listing every
     * violation (skipped when every violation is deterministically fixable) → keep the better candidate
     * (fewer violations needing the LLM, then fewer overall; ties go to the retry,
     * which was told about all of them) → deterministic patches → final validate.
     *
     * Returns [answer, validation metadata, guard flags]: validation =
     * {violations_first, retried, violations_final, patched[, retry_skipped][, unresolved]}.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function validateAnswer(
        AnswerValidator $validator,
        string $draft,
        string $systemPrompt,
        string $userPrompt,
        array $llmOptions,
        PipelineObserverInterface $observer
    ): array {
        // "[1, art. 12]" is a citation: normalize it to the standard marker BEFORE judging,
        // so a cited answer is never retried as marker-less and its source links resolve.
        $draft = $validator->normalizeAnnotatedCitations($draft);
        $first = $validator->validate($draft);
        [$answer, $current] = [$draft, $first];
        $retried = false;
        // M4: when every violation has a deterministic fix, the patch is the answer —
        // an LLM round trip could only add new violations.
        $allFixable = $first !== [] && AnswerValidator::severity($first)[0] === 0;

        if ($first !== [] && !$allFixable) {
            $retried = true;
            try {
                $retry = trim((string) $this->observedComplete(
                    $observer,
                    $userPrompt . $validator->retryInstruction($first, $draft),
                    $systemPrompt !== '' ? $systemPrompt : null,
                    $llmOptions
                ));
            } catch (\Throwable $e) {
                $retry = ''; // the draft stands; the deterministic patches still run
            }

            if ($retry !== '') {
                $retry = $validator->normalizeAnnotatedCitations($retry);
                $retryViolations = $validator->validate($retry);
                if (AnswerValidator::severity($retryViolations) <= AnswerValidator::severity($first)) {
                    [$answer, $current] = [$retry, $retryViolations];
                }
            }
        }

        [$answer, $patched] = $current !== [] ? $validator->patch($answer, $current) : [$answer, []];
        $final = $patched !== [] ? $validator->validate($answer) : $current;

        $validation = [
            'violations_first' => AnswerValidator::types($first),
            'retried' => $retried,
            'violations_final' => AnswerValidator::types($final),
            'patched' => $patched,
        ];
        if ($allFixable) {
            $validation['retry_skipped'] = 'all_fixable';
        }
        if ($final !== []) {
            $validation['unresolved'] = array_map(
                static fn (array $v) => ['type' => $v['type'], 'detail' => $v['detail']],
                $final
            );
        }

        $flags = [];
        if (in_array(AnswerValidator::CITATION_MISSING, $validation['violations_first'], true)) {
            // 'recovered' = the shipped answer cites; 'failed' = it still does not (the
            // retry — possibly an honest decline — ships as-is).
            $flags['citation_enforcement'] = in_array(AnswerValidator::CITATION_MISSING, $validation['violations_final'], true)
                ? 'failed'
                : 'recovered';
        }

        return [$answer, $validation, $flags];
    }

    /**
     * The former truncation / self-reference guard flags, derived from the one
     * validation pass: 'clean' (never violated), 'recovered' (the retry fixed it),
     * 'patched' (the deterministic fix applied), 'table' (the code-rendered table
     * carries its own "Showing X of Y" line). The self-reference flag only exists
     * when the user's own email (token) appears in the draft or the shipped answer.
     *
     * @param array<string, mixed> $validation
     * @return array<string, string>
     */
    private function truncationAndSelfReferenceFlags(
        AnswerValidator $validator,
        array $validation,
        string $draft,
        string $answer,
        bool $tableMode,
        ?DataAnswerPlan $plan
    ): array {
        $flag = static fn (string $type): string => in_array($type, $validation['patched'], true)
            ? 'patched'
            : (in_array($type, $validation['violations_first'], true) ? 'recovered' : 'clean');

        $flags = [];
        if ($tableMode && $plan !== null && $plan->truncated()) {
            $flags['truncation_honesty'] = 'table';
        } elseif ($validator->checksTruncation()) {
            $flags['truncation_honesty'] = $flag(AnswerValidator::TRUNCATION_UNDISCLOSED);
        }

        if ($validator->mentionsSelf($draft) || $validator->mentionsSelf($answer)) {
            $flags['self_reference_guard'] = $flag(AnswerValidator::SELF_REFERENCE_UNMARKED);
        }

        return $flags;
    }

    /**
     * F1 — the self-reference check on the SHIPPED answer (real values, table
     * included), with the REAL email as needle: any line still presenting the
     * user's own email without a that's-you marker gets the existing deterministic
     * patch (AnswerValidator, ai.response.self_reference_patch) — every such line,
     * not only the first. A line already marked (the narrative patch, the table's
     * cell marker) is never marked twice. Flags: 'patched' when this pass patched;
     * else 'table' when only the code-rendered table carried the user's value.
     *
     * @param array<string, string> $flags
     * @return array{0: string, 1: array<string, string>}
     */
    private function finalSelfReferenceGuard(
        string $answer,
        array $flags,
        array $options,
        ?string $locale,
        bool $tableMode,
        ?DataAnswerPlan $plan
    ): array {
        $ownEmail = $this->currentUserEmail($options);
        if ($ownEmail !== null) {
            $guard = new AnswerValidator(selfNeedle: $ownEmail, locale: $locale);
            $unmarked = static fn (string $text): array => array_values(array_filter(
                $guard->validate($text),
                static fn (array $v) => $v['type'] === AnswerValidator::SELF_REFERENCE_UNMARKED
            ));

            $patchedAny = false;
            // One patch per unmarked line; bounded by the line count.
            for ($i = 0, $max = substr_count($answer, "\n") + 1; $i < $max; $i++) {
                $violations = $unmarked($answer);
                if ($violations === []) {
                    break;
                }
                [$next, $patched] = $guard->patch($answer, $violations);
                if ($patched === [] || $next === $answer) {
                    break;
                }
                [$answer, $patchedAny] = [$next, true];
            }

            if ($patchedAny) {
                $flags['self_reference_guard'] = 'patched';

                return [$answer, $flags];
            }
        }

        if (!isset($flags['self_reference_guard']) && $tableMode && $plan !== null && $plan->selfMarked()) {
            $flags['self_reference_guard'] = 'table';
        }

        return [$answer, $flags];
    }

    /**
     * The identity values the composition prompt states about the user (their own name, their
     * current team's name) — read from the SAME section instance that printed them, and only
     * when it is part of this prompt: a host that removed the section told the model nothing.
     *
     * @return list<string>
     */
    private function promptIdentityValues(PipelineContext $context, array $options): array
    {
        try {
            $section = $this->getModule('current_user_context');

            return $section instanceof ResponseCurrentUserContextSection && $section->shouldInclude($context, $options)
                ? $section->identityValues()
                : [];
        } catch (\Throwable $e) {
            return []; // a wording nicety — never breaks a turn
        }
    }

    /**
     * The current user as an object (SelfReferenceNoticeSection::selfValuesIn input):
     * the threaded $options['user'] first, the auth container next — the same
     * resolution order as currentUserEmail().
     */
    private function currentUser(array $options): ?object
    {
        $user = $options['user'] ?? null;
        if (is_array($user)) {
            return (object) $user;
        }
        if (is_object($user)) {
            return $user;
        }

        try {
            return function_exists('auth') && auth()->check() ? auth()->user() : null;
        } catch (\Throwable $e) {
            return null; // no auth context (console/queue)
        }
    }

    /**
     * Build the turn's validator from the evidence the composition prompt was
     * built from: the passages as numbered in RELEVANT FILE CONTENT, the real
     * rows + DataAnswerPlan figures + counts + every number in the prompt the
     * model saw, the rows' entity ids per label, the truncation counts (narrative
     * path only) and the user's own email token.
     */
    private function answerValidator(
        PipelineContext $context,
        array $options,
        string $style,
        ?DataAnswerPlan $plan,
        bool $tableMode,
        PiiMasker $masker,
        ?string $locale,
        string $prompt
    ): AnswerValidator {
        $terse = in_array($style, self::CITATION_TERSE_STYLES, true);
        $citationsOn = (bool) config('ai.response_generation.citation_enforcement', true) && !$terse;

        $files = (array) ($context->fileContext['relevant_files'] ?? []);
        $enumeration = !empty($context->fileContext['enumeration']);
        $passages = [];
        $passageArticles = [];
        foreach (array_values($files) as $i => $file) {
            $passages[$i + 1] = is_array($file)
                ? (string) ($enumeration ? ($file['filename'] ?? '') : ($file['snippet'] ?? ''))
                : '';
            // What each passage DEFINES: line-start numbers, else chunk metadata (M1/M3).
            $passageArticles[$i + 1] = is_array($file) && !$enumeration ? ArticleLocator::forReference($file) : [];
        }

        $rows = is_array($context->executionResult->data) ? $context->executionResult->data : [];
        $grounded = $rows !== [] || ($passages !== [] && !$enumeration);

        $evidence = null;
        $grandTotals = [];
        $moneyRows = [];
        $keyFigures = [];
        if (config('ai.response_generation.validate_numbers', true) && $grounded) {
            $rowFigures = $this->figuresIn($rows);
            $total = $this->resultTotal($context);
            $shown = $tableMode && $plan !== null ? $plan->shown() : $this->renderedRowCount($context);
            $keyFigures = $plan !== null ? array_values(array_unique($plan->statedFigures(), SORT_REGULAR)) : [];
            $evidence = [
                ...$rowFigures,
                ...$keyFigures,
                ...$this->figuresIn([$prompt, $context->question]),
                (float) count($rows), (float) $total, (float) $shown, (float) ($total - $shown),
            ];
            $grandTotals = $plan !== null ? $plan->grandTotals() : [];
            $moneyRows = $plan !== null ? $plan->moneyRowFigures() : [];
        }

        $selfNeedle = null;
        $ownEmail = config('ai.response_generation.self_reference_guard', true) ? $this->currentUserEmail($options) : null;
        if ($ownEmail !== null) {
            $selfNeedle = $masker->tokenForValue($ownEmail) ?? $ownEmail;
        }

        return new AnswerValidator(
            passages: $passages,
            citationsRequired: $citationsOn && $passages !== [] && !$enumeration
                && $context->qu->questionType === 'knowledge_query',
            citationChecks: $citationsOn,
            evidence: $evidence,
            grandTotals: $grandTotals,
            moneyRowFigures: $moneyRows,
            keyFigures: $keyFigures,
            entityIds: config('ai.response_generation.validate_links', true)
                ? EntityIdIndex::forContext($context)->withIdentity($this->currentUserId($options), $this->currentTeamIds($options))
                : null,
            truncation: !$tableMode && $this->truncationHonestyApplies($context)
                ? ['total' => $this->resultTotal($context), 'shown' => $this->renderedRowCount($context)]
                : null,
            selfNeedle: $selfNeedle,
            locale: $locale,
            passageArticles: $passageArticles,
        );
    }

    /**
     * The turn's link filter: cited passages (external URLs the documents themselves carry),
     * the rows (contact values) and the PII tokens minted this turn.
     *
     * @param array<int, mixed> $rows
     */
    private function narrativeLinkFilter(PipelineContext $context, array $rows, PiiMasker $masker): NarrativeLinkFilter
    {
        $passages = [];
        foreach ((array) ($context->fileContext['relevant_files'] ?? []) as $file) {
            if (is_array($file)) {
                $passages[] = (string) ($file['snippet'] ?? '');
                $passages[] = (string) ($file['content'] ?? '');
            }
        }

        return new NarrativeLinkFilter(
            passages: array_values(array_filter($passages, static fn (string $p) => $p !== '')),
            rows: $rows,
            tokens: array_keys($masker->map()),
        );
    }

    /** The current user's id: the threaded $options['user'] first, the auth container next. */
    private function currentUserId(array $options): ?string
    {
        $user = $options['user'] ?? null;
        $id = is_object($user) ? ($user->id ?? null) : (is_array($user) ? ($user['id'] ?? null) : null);
        if ($id === null) {
            try {
                $id = function_exists('auth') && auth()->check() ? auth()->id() : null;
            } catch (\Throwable $e) {
                $id = null; // no auth context (console/queue)
            }
        }

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    /**
     * The user's team ids — the active team (host currentTeamId() helper, else the
     * user's current_team_id) plus the host's getTeamIds() when it exposes one.
     * Action links legitimately carry these (team_settings → currentTeamId) even
     * when no result row does (Phase 8-B I3).
     *
     * @return list<string>
     */
    private function currentTeamIds(array $options): array
    {
        $ids = [];
        try {
            if (function_exists('currentTeamId')) {
                $ids[] = currentTeamId();
            }
            $user = $options['user'] ?? (function_exists('auth') && auth()->check() ? auth()->user() : null);
            if (is_object($user)) {
                $ids[] = $user->current_team_id ?? null;
                if (method_exists($user, 'getTeamIds')) {
                    $ids = [...$ids, ...array_values((array) $user->getTeamIds())];
                }
            }
        } catch (\Throwable $e) {
            // no team context — nothing seeded
        }

        return array_values(array_unique(array_map('strval', array_filter($ids, static fn ($id) => is_scalar($id) && (string) $id !== ''))));
    }

    /**
     * Every figure in a nested value: numbers, numeric strings, the quantities
     * stated inside strings ("Order 310", "11 493,17 $") and — evidence is a
     * superset — every bare digit run, so a line-start article number ("2104. A
     * member…") or a date part the answer restates is never "unsupported".
     *
     * @return list<float>
     */
    private function figuresIn(mixed $value, int $depth = 0): array
    {
        if (is_int($value) || is_float($value)) {
            return [(float) $value];
        }
        if (is_string($value)) {
            $figures = is_numeric(trim($value)) ? [(float) trim($value)] : [];
            foreach (StatedNumber::quantities($value) as $quantity) {
                array_push($figures, ...$quantity['values']);
            }
            if (preg_match_all('/\d+/', $value, $runs)) {
                array_push($figures, ...array_map('floatval', $runs[0]));
            }

            return $figures;
        }
        if (!is_array($value) || $depth > 8) {
            return [];
        }

        $figures = [];
        foreach ($value as $item) {
            array_push($figures, ...$this->figuresIn($item, $depth + 1));
        }

        return $figures;
    }

    // =========================================================================
    // TURN COUNTS + IDENTITY (validator inputs)
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
     * complete() with observation: the verbatim messages and the call's token
     * usage are reported to the turn's observer under 'composition'. Same
     * arguments reach the provider as a bare complete() call.
     */
    private function observedComplete(
        PipelineObserverInterface $observer,
        string $userPrompt,
        ?string $systemPrompt,
        array $llmOptions
    ): string {
        $observer->promptBuilt('composition', TokenUsage::completeMessages($userPrompt, $systemPrompt));

        try {
            $out = $this->llm->complete($userPrompt, $systemPrompt, $llmOptions);

            // Inside generate(): every draft AND guard retry is normalized to the
            // masked form, so guard checks and later retry prompts never carry a
            // real identifying value (unmasking happens once, at the very end).
            return $this->turnMasker !== null && is_string($out) ? $this->turnMasker->maskText($out) : $out;
        } finally {
            TokenUsage::report($observer, 'composition', $this->llm);
        }
    }

    /**
     * Build the prompt split by role.
     *
     * Each section declares role() => 'system' | 'user'. System sections (identity,
     * security policy) are concatenated into the system message; everything else into
     * the user message. Section ORDER is preserved within each role (the pipeline is
     * still processed in priority order).
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
            function (ResponseSectionInterface $section) use (&$system, &$user, $context, $options) {
                if (!$section->shouldInclude($context, $options)) {
                    return;
                }

                $content = $section->format($context, $options);

                if ($section->role() === 'system') {
                    $system .= $content;
                } else {
                    $user .= $content;
                }
            }
        );

        // Recency anchor for the reply language: the guideline names it in the system
        // prompt, but a long block of French rows right above the question still pulled
        // gpt-4o-mini into French for an English question (eval p12-B digest t1).
        $language = trim((string) ($context->qu->language ?? ''));
        if ($user !== '' && $language !== '') {
            $user = rtrim($user) . "\n\nWrite the answer in the question's language ('{$language}'), "
                . "not in the language of the data or documents above.\n";
        }

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

        // B3/B5: the next steps (the entity's list page, its create form, identity links)
        // are attached by CODE — the model is told which, and writes no link itself.
        $attached = $options['attached_actions'] ?? null;
        $attached = $attached instanceof AttachedActions ? $attached : AttachedActions::none();

        // A navigation ask — QU's navigation_target (own profile / team page) — with an
        // attached page is not an abstention: "the link to my profile" is answered by the
        // attached chip, never by "no records were found" (live 2026-10-01), even when a
        // query ran and matched nothing. Only QU's judgment makes a turn a navigation ask: a
        // query-less DATA turn that merely carries chips (an empty result's list/create) is
        // an honest abstention (eval p14 review).
        $navigation = !$attached->isEmpty() && is_string($options['navigation_target'] ?? null);
        // Docs only (ai.query_generation.enabled = false): nothing was looked up.
        $docsOnly = !$navigation && ($options['docs_only'] ?? false) === true;
        // The query writer judged the data does not hold the answer: no lookup ran, so none is claimed.
        $notInData = !$navigation && !$docsOnly && ($options['not_in_data'] ?? false) === true;
        $noLookup = $docsOnly || $notInData;

        $prompt .= match (true) {
            $navigation => "This request needs no data lookup: it asks to open or reach a page of the app. The matching "
                . "page(s) are attached as buttons below your answer. In one or two sentences, tell the user it "
                . "is attached below, naming it. Do NOT say that nothing was found, and do not end with an offer.\n\n",
            $notInData => "No lookup was run: the data you can read does not hold this information. Say so briefly and plainly, "
                . "without guessing an answer or a figure, and do NOT say that records were searched or that nothing was found. "
                . "Do NOT end with a bare invitation like \"feel free to ask\" or an offer (\"let me know\", \"would you like…\").\n\n",
            $docsOnly => "None of the documents answers this question. Say so briefly and plainly, without guessing an "
                . "answer, a figure or a place in the application. Do NOT end with a bare invitation like \"feel free to ask\" "
                . "or an offer (\"let me know\", \"would you like…\").\n\n",
            default => "No matching records were found for this question. Briefly and helpfully tell the "
                . "user that no results were found. Do NOT end with a bare invitation like \"feel free to ask\" "
                . "or an offer (\"let me know\", \"would you like…\").\n\n",
        };

        // WHAT WAS CHECKED (Task 1.27 c, live rights t2): a bare "no results were
        // found" tells the user nothing. The subject is QU's STRUCTURED field threaded by
        // AiManager — no word lists — and the model names the record kinds in plain words.
        //
        // The KINDS are the EXECUTED query's (eval p18-B rights t2: "I checked … in invoices,
        // team records, and fund records" — QU's Invoice / Team / Fund — while the query
        // summed the invoices of the user's accounts): the entities its projections read
        // (QueriedKinds), by the host's own word for them. QU's kinds stand only when no
        // query ran or nothing can be read from it.
        //
        // The SCOPE is the EXECUTED query's (eval p16-A meetings), never QU's possessive
        // flag: "When is our next meeting?" ran a team-wide query and was answered "no
        // meetings linked to you". checked_scope = 'own' (the query is anchored on the
        // user), 'team' (tenant-scoped, not anchored on the user) or absent (unknown —
        // no scope is claimed). checked_own_data=true is the former spelling of 'own'.
        $subject = trim((string) ($options['checked_subject'] ?? ''));
        $scope = is_string($options['checked_scope'] ?? null)
            ? $options['checked_scope']
            : (($options['checked_own_data'] ?? false) === true ? 'own' : null);
        $ownData = $scope === 'own';
        $teamWide = $scope === 'team';
        $ownership = is_array($options['ownership_check'] ?? null) ? $options['ownership_check'] : null;

        // …AND THE LINE THAT SAYS IT IS CODE'S (eval p20-B rights t2: the model left out what
        // was checked; p19 wrote "invoices and other related records"). The kinds the executed
        // query read, the scope it covered and that nothing matched are FACTS — printed on a
        // line of their own right below the model's answer ({@see checkedLine()}), in the
        // host's plurals and the answer's language. The model is told, so it does not restate
        // them. Null wherever a fact or a word is missing: the model's instruction stands.
        $checkedLine = $navigation || $noLookup ? null : $this->checkedLine($cypherQuery, $scope, $ownership, $this->questionLocale($options));
        $printed = $checkedLine === null
            ? ''
            : 'The system itself prints, on a line right below your answer, what was checked: "' . str_replace('"', "'", $checkedLine)
                . '" — do NOT restate it: do not list the record kinds and do not say again that they were checked.';

        $checkedKinds = [];
        if ($subject !== '' && !$navigation && !$noLookup) {
            $types = QueriedKinds::names($cypherQuery);
            if ($types === []) {
                $types = array_values(array_filter((array) ($options['checked_entity_types'] ?? []), 'is_string'));
            }
            $checkedKinds = $types;
            $prompt .= "WHAT WAS CHECKED: {$subject}"
                . ($types !== [] ? ' (record kinds: ' . implode(', ', $types) . ')' : '')
                . ($ownData ? " — within the user's OWN linked records" : '')
                . ($teamWide ? " — across the records of the user's whole team / organization that they may access "
                    . '(not only records linked to them personally)' : '')
                . ".\n" . ($printed !== '' ? $printed . ' Your answer says plainly that nothing matched' : 'State plainly what was checked and that nothing matched')
                // Possessive framing only for the user's own data (Task 1.28 M5).
                . ($ownData ? ' (e.g. that no such records are linked to them)' : '')
                . ($teamWide ? ' for the team as a whole — never say that nothing is "linked to" the user' : '')
                . " — never a bare \"no results were found\". Keep the question's own qualifiers (a period, a "
                . 'status, a name): say that none matched THAT, never that no such records exist at all.'
                . ($printed !== '' ? '' : ' Describe the record kinds in everyday words, never as raw type names.')
                . "\n\n";
        } elseif ($printed !== '') {
            $prompt .= $printed . "\n\n";
        }

        // WHY an own-data result is empty (eval p16-B rights t2): AiManager counted, through
        // the scoped executor, the records of the entity the question hangs on (the host's
        // ownership path — e.g. the user's accounts). A FACT, stated as the reason; the display
        // name is the host's. Only for a query that was anchored on the user.
        $kind = trim((string) ($ownership['display_name'] ?? ''));
        if ($ownData && !$navigation && $kind !== '' && is_int($ownership['count'] ?? null)) {
            $prompt .= $ownership['count'] === 0
                ? "VERIFIED BY THE SYSTEM: this user has NO {$kind} linked to their account. That is the reason "
                    . "nothing matched — lead with it, in plain words: they have no {$kind} of their own, so there "
                    . "is nothing of their own to show for this question. It is NOT a result of zero (never present it "
                    . "as an amount of 0 or as nothing being due), and it says nothing about the records of the team / "
                    . "organization: never state or imply that the team has none or owes nothing.\n\n"
                : "VERIFIED BY THE SYSTEM: this user DOES have {$kind} records linked to their account "
                    . "({$ownership['count']} found), so those are not missing: what matched nothing is the specific "
                    . "thing asked about them. Say that — never that they have no {$kind}.\n\n";
        }

        // Follow-up awareness: "make a PDF of that" / "export it" style requests reference the
        // PREVIOUS turn, not new data. Without the previous turn's information this path can
        // only say "no results" to a request the user just watched succeed — worse than wrong.
        $conversation = $options['conversation_context'] ?? [];
        $lastSample = is_array($conversation['last_result_sample'] ?? null) ? $conversation['last_result_sample'] : [];
        // Identifying fields never reach the LLM (Phase 8 batch A); the answer is unmasked below.
        // Labels of the previous turn + this turn's checked types, so entity-config
        // sensible_columns apply (review minor); seeded from the conversation.
        $masker = PiiMasker::forConversation(is_array($conversation) ? $conversation : [])
            ->addLabels(array_values(array_unique(array_merge(
                array_values(array_filter((array) ($options['checked_entity_types'] ?? []), 'is_string')),
                QueriedKinds::labels($cypherQuery)
            ))));
        if (!empty($lastSample)) {
            $sampleJson = json_encode($masker->maskRows(array_slice($lastSample, 0, 10)), JSON_UNESCAPED_UNICODE);
            $prompt .= "EXCEPTION — information from the previous turn (most recently shown to the user): {$sampleJson}\n";
            $prompt .= "If the question is actually a request ABOUT that previous information — format it, "
                . "summarize it, turn it into a document — do NOT say no results were found: answer about "
                . "that previous information instead, concisely.\n\n";
        }
        $prompt .= $attached->narratorInstruction()
            . ($navigation || $attached->isEmpty() ? '' : ' When one of the attached actions is the natural next step, say so in one short sentence.')
            . "\n\n";
        // The QU-detected language, when known (live 2026-10-01: an English navigation ask
        // was answered in the app's French without it).
        $language = $this->questionLocale($options);
        $prompt .= "Respond in the SAME language as the user's question above"
            . ($language !== null ? " (the question's language: '{$language}')." : '.');

        try {
            $answer = $this->observedComplete(PipelineObserverScope::current(), $prompt, $systemPrompt !== '' ? $systemPrompt : null, [
                'temperature' => $temperature,
                'max_tokens' => 200,
            ]);

            $metadata = [
                'empty_result' => true,
                'result_count' => 0,
            ];
            // Trace evidence: the record kinds the answer was told were checked.
            if ($checkedKinds !== []) {
                $metadata['checked_kinds'] = $checkedKinds;
            }

            $answer = trim($masker->unmask(AttachedActions::strip(
                (new NarrativeLinkFilter(rows: $lastSample, tokens: array_keys($masker->map())))->apply($answer)
            )));
            // p21-B rights t2: "Attached below this answer: Receivables report." — the prompt's
            // own label, copied. A sentence that only lists this turn's buttons after a colon
            // is dropped (AttachedActions::withoutLabelEcho; never the whole answer). Not on a
            // navigation ask: there the model is ASKED to name the attached page.
            if (!$navigation) {
                [$answer, $labelEcho] = $attached->withoutLabelEcho($answer);
                if ($labelEcho !== []) {
                    $metadata['label_echo_dropped'] = $labelEcho;
                }
            }
            // p20-B rights t2: what was checked, written by code below the model's answer
            // (host nouns and package lang only — never a value of a row).
            // p23-B rights t2: the model copied that line from the prompt (which quotes it so the
            // model does not restate it) and it stood twice. The model's copy goes — recognised
            // by its words in order, never by its bytes ({@see withoutLineEcho()}) — before
            // code prints its own; an answer that was only the copy is the line itself.
            if ($checkedLine !== null && $answer !== '') {
                [$answer, $lineEcho] = $this->withoutLineEcho($answer, $checkedLine);
                if ($lineEcho !== []) {
                    $metadata['checked_line_echo'] = $lineEcho;
                }
                $answer = $answer !== '' ? $answer . "\n\n" . $checkedLine : $checkedLine;
                $metadata['checked_line'] = $checkedLine;
            }

            return [
                'answer' => $answer,
                'format' => $format,
                'metadata' => $metadata,
            ];

        } catch (\Exception $e) {
            $locale = $this->questionLocale($options);

            return [
                'answer' => trans('ai.response.no_results_fallback', ['question' => $originalQuestion], $locale),
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
     * The answer without the model's copies of a line CODE prints itself.
     *
     * The prompt quotes that line so the model does not restate it; a model that copies the
     * quote instead leaves the line twice in the answer (eval p23-B rights t2). A copy is the
     * line's own WORDS, all of them, in order, on one line — whatever stands between and around
     * them: another dash, no final period, other casing, the prompt's quotes or an emphasis, a
     * list bullet, or the model's own sentence in front of it. Structure only: the words are the
     * line's, read off it; a sentence of the model's own that says the same in other words is
     * prose and stays. A line of fewer than three words is never searched for.
     *
     * @return array{0: string, 1: list<string>} the answer, and each copy as the model wrote it
     */
    private function withoutLineEcho(string $answer, string $line): array
    {
        try {
            $words = preg_match_all('/[\p{L}\p{N}]+/u', $line, $m) > 0 ? $m[0] : [];
            if (count($words) < 3) {
                return [$answer, []];
            }

            $between = '[^\p{L}\p{N}\r\n]+';
            $pattern = '/(?:^[ \t]*(?:[-*+•]|\d+[.)])[ \t]+)?'      // a list bullet in front of it
                . '[^\p{L}\p{N}\s.,;:!?]*'                           // quotes / emphasis opening it
                . '(?<![\p{L}\p{N}])'
                . implode($between, array_map(static fn (string $word): string => preg_quote($word, '/'), $words))
                . '(?![\p{L}\p{N}])'
                . '[^\p{L}\p{N}\s]*/imu';                            // its period, quotes / emphasis closing it

            $echo = [];
            $stripped = preg_replace_callback($pattern, static function (array $match) use (&$echo): string {
                $echo[] = trim($match[0]);

                return '';
            }, $answer);
            if (!is_string($stripped) || $echo === []) {
                return [$answer, []];
            }

            $stripped = (string) preg_replace('/[ \t]+$/mu', '', $stripped);
            $stripped = (string) preg_replace('/\n{3,}/u', "\n\n", str_replace("\r\n", "\n", $stripped));

            return [trim($stripped), $echo];
        } catch (\Throwable $e) {
            return [$answer, []];
        }
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
            'format' => $format,
            'metadata' => $metadata,
        ];
    }

    /**
     * WHAT WAS CHECKED, as a line code prints below an empty answer (eval p20-B rights t2) —
     * "Checked: invoices, teams for your accounts — nothing matched your question." Built ONLY from
     * what is established, and null as soon as one piece is missing:
     *
     *   - the KINDS: the entities the EXECUTED query's projections read ({@see QueriedKinds::labels()}
     *     — never Query Understanding's guess, never a node the query only walks through), each by
     *     the plural its host declares (`metadata.display_name_plural`), said in the answer's
     *     language ({@see HostText::inLanguage()}). A structural entity (`askable => false`) is
     *     not named; a kind without a sayable plural leaves NO line (a partial list would
     *     misstate what was checked);
     *   - the SCOPE, by the executed query (EmptyResultInspector::scope()): 'own' — the query is
     *     anchored on the user; with the ownership check's entity (the path its emptiness hangs
     *     on, counted by AiManager) it reads "… for your <owned>", unless that entity is itself
     *     what was looked for; 'team' — tenant-scoped, not anchored on the user; else no scope
     *     is claimed;
     *   - "nothing matched your question": the query ran and returned no row. Never "none
     *     exist" — the question's qualifiers (a period, a status) are the model's to keep.
     *
     * Wording is the package's lang templates (ai.response.checked*); nothing is inflected.
     *
     * @param array<string, mixed>|null $ownership EmptyResultInspector::ownershipCheck()
     */
    private function checkedLine(?string $cypher, ?string $scope, ?array $ownership, ?string $locale): ?string
    {
        try {
            $plural = static function (string $label) use ($locale): ?string {
                $config = \Condoedge\Ai\Services\Discovery\EntityLabelIndex::shared()->configForLabel($label) ?? [];

                return HostText::inLanguage($config['metadata']['display_name_plural'] ?? $config['display_name_plural'] ?? null, $locale);
            };

            $kinds = [];
            foreach (QueriedKinds::labels($cypher) as $label) {
                $config = \Condoedge\Ai\Services\Discovery\EntityLabelIndex::shared()->configForLabel($label) ?? [];
                if (($config['metadata']['askable'] ?? true) === false) {
                    continue;
                }
                $noun = $plural($label);
                if ($noun === null) {
                    return null;
                }
                if (!in_array($noun, $kinds, true)) {
                    $kinds[] = $noun;
                }
            }
            if ($kinds === []) {
                return null;
            }

            $replace = ['kinds' => implode(', ', $kinds)];
            $key = 'ai.response.checked';
            if ($scope === 'own') {
                $entity = is_string($ownership['entity'] ?? null) && is_int($ownership['count'] ?? null) ? trim($ownership['entity']) : '';
                $owned = $entity !== '' ? $plural($entity) : null;
                if ($owned !== null && !in_array($owned, $kinds, true)) {
                    [$key, $replace['owned']] = ['ai.response.checked_own_path', $owned];
                } else {
                    $key = 'ai.response.checked_own';
                }
            } elseif ($scope === 'team') {
                $key = 'ai.response.checked_team';
            }

            $candidates = [$locale];
            if ($locale !== null && preg_match('/^([A-Za-z]+)[-_]/', $locale, $m) === 1) {
                $candidates[] = strtolower($m[1]);
            }
            foreach ($candidates as $candidate) {
                $line = trans($key, $replace, $candidate);
                if (is_string($line) && trim($line) !== '' && $line !== $key) {
                    return trim($line);
                }
            }
        } catch (\Throwable $e) {
            // a wording nicety — never breaks a turn
        }

        return null;
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
     * Calculate max tokens based on word limit
     */
    private function calculateMaxTokens(int $maxWords): int
    {
        return (int) ceil($maxWords / 0.75);
    }
}
