<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Understanding;

use Condoedge\Ai\Contracts\EmbeddingProviderInterface;
use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\PipelineObserverInterface;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Discovery\EntityLabelIndex;
use Condoedge\Ai\Services\Retrieval\CalculationMatcher;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Support\AliasNormalizer;
use Condoedge\Ai\Support\GuardedPipelineObserver;
use Condoedge\Ai\Support\PipelineObserverScope;
use Condoedge\Ai\Support\TokenUsage;
use Illuminate\Support\Facades\Log;

/**
 * ClarityChecker
 *
 * Decides whether a turn must be answered with ONE clarifying question instead
 * of a guess: the question asks for something that several kinds of records
 * carry and says neither which records nor whose ("Show me the balance." as the
 * first message of a conversation).
 *
 * Why its own call (eval p15, 2026-10-06): the same judgment used to ride the
 * Query-Understanding call as three extra output keys. Inside that ~20-key prompt
 * the small model never flagged anything — "needs_clarification" was true on 0 of
 * 148 eval turns, the turn written for it included, and an explicit rule added in
 * a live A/B changed nothing — while the key docs cost ~264 prompt tokens on EVERY
 * turn. Here the model has one job, and it is asked only when the question could
 * be a fork at all.
 *
 * Two steps:
 *
 *  1. {@see self::blockedBy()} — STRUCTURAL preconditions, no LLM. The call is
 *     considered only for a first message (no conversation, no page) that asks
 *     for data — not for documents, a file, a page, an export, a digest — and
 *     whose own TEXT names no kind of record (no entity name or synonym, in any
 *     spelling) and no person. What Query Understanding GUESSED about such a
 *     question is deliberately not a gate: measured live, it invents an owner
 *     (is_possessive true on 3 of 3 runs of "Show me the balance."), returns no
 *     property concept for "What is the total?" and types that question
 *     data_query or knowledge_query from one run to the next.
 *
 *  2. {@see self::check()} — ONE small call on the Query-Understanding model.
 *     The model does not answer "is it ambiguous?" (the judgment it never makes
 *     reliably); it reports facts about the question, and the verdict is derived
 *     from their STRUCTURE:
 *       - what is asked ("" for a greeting, thanks, a question about the
 *         assistant: nothing to ask about);
 *       - the first-person words of the question and the grammatical form of
 *         each: a subject or a possessive states whose records are meant, an
 *         object only says who the answer is for ("show me…");
 *       - the words that name the records (a name, a number, an identifier);
 *       - the kinds of records, from the host's own list, that can carry what
 *         is asked.
 *     A quoted word the question does not contain is an invention and is
 *     ignored. No owner, no named record and two or more kinds = a fork: the
 *     readings and the clarifying question the model wrote are returned.
 *
 * NAMED QUANTITIES (eval p17-A, 2026-10-06): the kinds of records alone gave
 * readings nobody means — "the balance of an account, an invoice, a bill or a
 * transaction" — while the host's own calculation for that very question had
 * matched and was not offered. The host's `ai.calculations` are the
 * application's named, askable quantities, so those the question matches
 * ({@see self::quantities()}, {@see CalculationMatcher}) are handed over WITH
 * the question. The model only rewords them, in the asker's language; they are
 * the first readings, the kinds of records fill the rest, and a kind a quantity
 * is already about ({@see self::covered()}) is neither listed nor counted — it
 * would be the same reading twice. A quantity adds a reading; whether the turn
 * asks at all is still decided by what the question states (whose, which): a
 * matched calculation never turns a clear question into a clarification, and
 * the preconditions do not consult them.
 *
 * KINDS A USER CAN OPEN (eval p18-A, 2026-10-06): every configured entity used to
 * be offered, link tables included — "…balance of an account, of a team, or of
 * an order line?". A reading is only worth offering when the application shows
 * such records somewhere, so a kind is listed (and counted) when the host exposes
 * a page for it: a record action (`ai.entity_actions`) or a generic action
 * declared for it (`ai.generic_actions` `entity`, with or without a placement
 * `kind`) — {@see self::kinds()}. A host that places no page on any kind keeps
 * every askable kind.
 *
 * Inputs are host configuration only (white-label rule): the kinds of records
 * are the configured entities, by display name with their real synonyms
 * ({@see EntityLabelIndex::labelledRecordKinds()}); the named quantities are the
 * configured calculations, by label and description. The prompt describes the
 * task structurally and carries no domain vocabulary of its own.
 *
 * Never throws: any failure (provider error, timeout, invalid JSON, unusable
 * shape) is "no clarification" — the turn is answered the normal way.
 */
final class ClarityChecker
{
    /** Observer stage of the call's prompt, usage and recovered errors. */
    public const STAGE = 'clarity_check';

    /**
     * Real synonyms shown per kind of record. Measured on the host config
     * (73 entities, 2026-10-06): names alone = 714 prompt tokens, but the model
     * then picks kinds by resemblance of their NAME to the question; with the
     * synonyms (1,666 tokens) it finds the kinds people actually mean and writes
     * the question in the asker's language. The cap only bounds an entity with a
     * very long list — the call is made on few turns.
     */
    public const MAX_SYNONYMS_PER_KIND = 12;

    /**
     * Two things that can be what is asked — kinds of records that can carry it, named
     * quantities — make a fork (and two readings a question).
     */
    private const MIN_READINGS = 2;

    /** Output budget: the reply measured 45-105 tokens; a longer one is not a crisp question. */
    private const MAX_OUTPUT_TOKENS = 300;

    /** "One or two sentences": a longer clarifying question is dropped (the readings still ask). */
    private const MAX_QUESTION_CHARS = 300;

    /** Query-Understanding question types that ask for a value held in records. */
    private const DATA_QUESTION_TYPES = ['data_query', 'hybrid'];

    /** Query-Understanding intents that ask for the user's files or about the page, not for records. */
    private const NON_DATA_INTENTS = ['file_listing', 'page_explanation'];

    /** Grammatical form of a first-person word that states no owner: it only receives the answer. */
    private const FORM_OBJECT = 'object';

    /**
     * Margin handed to the {@see CalculationMatcher} (similarities span [-1, 1], so
     * this keeps EVERY calculation that clears its minimum). The matcher's own margin
     * picks the one formula to render for an answer; a clarifying question is about
     * the others too — measured live, "How much is owed?" scores 0.62 / 0.57 / 0.55 /
     * 0.53 on four calculations of the host: one "match", four readings.
     */
    private const ANY_MARGIN = 2.0;

    /** A kind of record is named in a quantity's text by a run of at most this many words. */
    private const MAX_KIND_WORDS = 3;

    /** Verdicts that found something asked, no owner, no named record — and fewer than two readings. */
    private const ONE_READING = ['single_reading', 'no_readings'];

    /** A reply entry that is a quantity's bare tag ("Q2", "q2:"), not a wording of it. */
    private const TAG_ONLY = '/^\W*Q\s*\d+\W*$/iu';

    /**
     * @param EmbeddingProviderInterface|null $embedding Matches the question against the host's
     *        calculations (the per-request memoized provider: the pipeline already embedded the
     *        question, and the calculations' vectors are cached). Null: kinds of records only.
     * @param AiActionRegistry|null $actions The host's pages (which kinds of records a user can
     *        open or list). Null: the container's registry.
     */
    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly ?EntityLabelIndex $entities = null,
        private readonly ?EmbeddingProviderInterface $embedding = null,
        private readonly ?AiActionRegistry $actions = null
    ) {
    }

    /**
     * The first structural precondition that rules the clarity call out, or null
     * when all of them hold. Cheap and deterministic — no LLM.
     *
     * @param string $question            The user's question, verbatim.
     * @param array  $conversationContext The turn's conversation_context.
     * @param array  $pageContext         The turn's page_context (PageContext array form).
     * @return string|null 'disabled' | 'qu_disabled' | 'qu_failed' | 'empty_question' |
     *         'question_type' | 'intent' | 'person_named' | 'follow_up' | 'digest_request' |
     *         'export_request' | 'navigation_request' | 'conversation_context' |
     *         'page_context' | 'no_record_kinds' | 'entity_named'
     */
    public function blockedBy(
        string $question,
        QueryUnderstandingResult $qu,
        array $conversationContext = [],
        array $pageContext = []
    ): ?string {
        if (!$this->configFlag('ai.understanding.clarify_enabled')) {
            return 'disabled';
        }
        // Without QU the result below is the neutral default: "no follow-up, no
        // export ask" would mean "unknown", not "judged absent".
        if (!$this->configFlag('ai.query_understanding.enabled')) {
            return 'qu_disabled';
        }
        if ($qu->failed) {
            return 'qu_failed';
        }
        if (trim($question) === '') {
            return 'empty_question';
        }
        // A question for documents is not a question about records — when that is a
        // judgment. For a question QU could tie to NOTHING (no entity type, no
        // property concept) the type is a coin toss: measured live, "What is the
        // total?" came back data_query on 3 runs and knowledge_query on 2, with
        // the same empty extraction. Such a question is left to the focused call.
        if (!in_array($qu->questionType, self::DATA_QUESTION_TYPES, true)
            && ($qu->entityTypes !== [] || $qu->propertyConcepts !== [])) {
            return 'question_type';
        }
        if (in_array($qu->intent, self::NON_DATA_INTENTS, true)) {
            return 'intent';
        }
        if ($qu->personNames !== []) {
            return 'person_named';
        }
        if ($qu->followUpReference) {
            return 'follow_up';
        }
        if ($qu->digestRequest) {
            return 'digest_request';
        }
        if ($qu->exportFormat !== null) {
            return 'export_request';
        }
        if ($qu->navigationTarget !== null) {
            return 'navigation_request';
        }
        if ($this->conversationCanSupplyTheSubject($conversationContext, $question)) {
            return 'conversation_context';
        }
        if (array_filter($pageContext) !== []) {
            return 'page_context';
        }
        if (count($this->kinds()) < self::MIN_READINGS) {
            return 'no_record_kinds';
        }
        // The TEXT of the question, not QU's guess of its entity types.
        if ($this->index()->labelMentionedIn($question) !== null) {
            return 'entity_named';
        }

        return null;
    }

    /**
     * Ask the model about one question and derive the verdict.
     *
     * ONE call. A second one — the kinds-only prompt — is made only when the
     * question matched calculations and the first reply left a single reading
     * (see the comment in the body): no question that names an owner or a record,
     * and no question without a matching calculation, ever costs two.
     *
     * @param string $question         The user's question, verbatim.
     * @param string $questionLanguage ISO 639-1 code of the question's language ('' unknown).
     * @return array{ask: bool, reason: string, readings: list<string>, question: string}
     *         ask=true: the 2-4 readings and the ONE clarifying question ('' when the model
     *         wrote none usable — the readings still ask). ask=false: nothing to ask, with
     *         the reason: 'nothing_asked' | 'owner_stated' | 'record_named' |
     *         'single_reading' | 'no_readings' | 'invalid_reply' | 'failed' | 'empty_question'.
     */
    public function check(
        string $question,
        string $questionLanguage = '',
        ?PipelineObserverInterface $observer = null
    ): array {
        $question = trim($question);
        if ($question === '') {
            return $this->answer('empty_question');
        }

        $kinds = $this->kinds();
        $quantities = $this->quantities($question);
        $observer = $observer !== null ? GuardedPipelineObserver::wrap($observer) : PipelineObserverScope::current();
        $questionLanguage = strtolower(trim($questionLanguage));

        try {
            $reply = $this->request($this->buildMessages($question, $questionLanguage, $kinds, $quantities), $observer);
            $verdict = $this->verdict($reply, $question, $kinds, $quantities);

            // With quantities in sight the model's candidates lean on them (see
            // buildMessages): when that leaves a single reading, the fork may simply not
            // have been listed. The kinds are then asked WITHOUT them — the kinds-only
            // call, as if no calculation had matched — and judged together with the
            // quantities already reworded. So a matched calculation adds readings and
            // never costs the question: the turn asks at least whenever it would have
            // asked without calculations. (The second call's sentence does not offer the
            // quantities: the readings ask.)
            if ($quantities !== [] && !$verdict['ask'] && in_array($verdict['reason'], self::ONE_READING, true)) {
                $kindsOnly = $this->request($this->buildMessages($question, $questionLanguage, $kinds), $observer);
                $verdict = $this->verdict(['reworded' => $reply['reworded'] ?? null] + $kindsOnly, $question, $kinds, $quantities, false);
            }

            return $verdict;
        } catch (\Throwable $e) {
            // Asking is an enhancement: without it the turn is answered the normal way.
            try {
                Log::warning('Clarity check unavailable: ' . $e->getMessage());
            } catch (\Throwable) {
                // Logging is best effort.
            }
            $observer->stageError(self::STAGE, $e);

            return $this->answer('failed');
        }
    }

    /**
     * One small deterministic call; the observer sees its verbatim prompt and its usage.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<string, mixed>
     */
    private function request(array $messages, PipelineObserverInterface $observer): array
    {
        $observer->promptBuilt(self::STAGE, $messages);

        try {
            $response = $this->llm->chatJson($messages, [
                'temperature' => 0.0,
                'max_tokens' => self::MAX_OUTPUT_TOKENS,
            ]);
        } finally {
            // A failed or truncated reply still consumed tokens.
            TokenUsage::report($observer, self::STAGE, $this->llm);
        }

        return is_object($response) ? (array) $response : $response;
    }

    /**
     * Whether the conversation holds anything a subject could come from: an
     * earlier exchange, or any recorded focus / result / subject / cited
     * document. The current question stored as the last user message (regenerate
     * path) is not an earlier exchange.
     */
    private function conversationCanSupplyTheSubject(array $context, string $question): bool
    {
        foreach ($context as $key => $value) {
            if ($key !== 'recent_exchanges') {
                if (!empty($value)) {
                    return true;
                }
                continue;
            }

            foreach (is_array($value) ? $value : [] as $exchange) {
                if (!is_array($exchange)) {
                    continue;
                }
                $asked = is_string($exchange['question'] ?? null) ? trim($exchange['question']) : '';
                $answered = is_string($exchange['answer_summary'] ?? null) ? trim($exchange['answer_summary']) : '';
                if ($answered !== '' || ($asked !== '' && $asked !== trim($question))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The kinds of records shown to the model, each with the comparison keys of
     * the words it goes by (to recognise it among the reply's candidates).
     *
     * Only kinds a user can open or list: the askable entities the host exposes a
     * page for ({@see self::openableLabels()}). A link table or a detail row has
     * none — nobody asks for "the balance of an order line". When the host places
     * no page on any of its kinds, every askable kind is kept: the feature must
     * work for a host that configured no action at all.
     *
     * @return list<array{name: string, synonyms: list<string>, keys: array<string, true>}>
     */
    private function kinds(): array
    {
        $records = $this->index()->labelledRecordKinds();
        $openable = $this->openableLabels();
        $exposed = array_values(array_filter(
            $records,
            static fn (array $kind): bool => isset($openable[self::labelKey($kind['label'])])
        ));
        if ($exposed === []) {
            $exposed = $records;
        }

        $kinds = [];
        foreach ($exposed as $kind) {
            $synonyms = array_slice($kind['synonyms'], 0, self::MAX_SYNONYMS_PER_KIND);
            $keys = [];
            foreach ([$kind['name'], ...$synonyms] as $word) {
                $key = AliasNormalizer::key($word);
                if ($key !== '') {
                    $keys[$key] = true;
                }
            }
            $kinds[] = ['name' => $kind['name'], 'synonyms' => $synonyms, 'keys' => $keys];
        }

        return $kinds;
    }

    /**
     * The entity labels the host exposes a page for, as comparison keys: every
     * label with a record action, and every label a generic action is placed on
     * (its declared `entity`, whatever its `kind`). Host configuration read through
     * the action registry's public API — structural, no vocabulary. Any failure =
     * none known (every askable kind is then kept).
     *
     * @return array<string, true>
     */
    private function openableLabels(): array
    {
        try {
            $registry = $this->actions ?? app(AiActionRegistry::class);

            $labels = $registry->entityLabels();
            foreach (array_keys((array) config('ai.generic_actions', [])) as $key) {
                foreach ((array) ($registry->attributes((string) $key)['entity'] ?? []) as $label) {
                    if (is_string($label)) {
                        $labels[] = $label;
                    }
                }
            }
        } catch (\Throwable) {
            return [];
        }

        $keys = [];
        foreach ($labels as $label) {
            if (($key = self::labelKey($label)) !== '') {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /** An entity label as a comparison key (case and separators aside) — the action attacher's rule. */
    private static function labelKey(string $label): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $label));
    }

    /**
     * The named quantities the question may be asking for: the host's calculations
     * (`ai.calculations`) it matches, best match first, each as its label and short
     * description under a tag ("Q1", "Q2", …) that keeps their order in the reply.
     *
     * "Matches" is the {@see CalculationMatcher}'s judgment at ITS minimum similarity
     * (`ai.calculation_matching.min_similarity`), on the question's own text — not on
     * the Query-Understanding rewrite, which for a subject-less question is a guess
     * (see the class comment). At most as many as a clarifying question can offer.
     *
     * An enhancement, never a requirement: no embedding provider, matching switched
     * off, no calculation configured, none matched or any failure = [] and the call
     * is the kinds-only one.
     *
     * @return list<array{tag: string, text: string}>
     */
    private function quantities(string $question): array
    {
        if ($this->embedding === null) {
            return [];
        }

        try {
            $calculations = config('ai.calculations', []);
            if (!is_array($calculations) || $calculations === [] || !config('ai.calculation_matching.enabled', true)) {
                return [];
            }

            $matcher = new CalculationMatcher(
                $this->embedding,
                new AiLogger(),
                (float) config('ai.calculation_matching.min_similarity', CalculationMatcher::DEFAULT_MIN_SIMILARITY),
                self::ANY_MARGIN
            );

            $quantities = [];
            foreach (array_keys($matcher->match($calculations, [$question]) ?? []) as $key) {
                $calculation = $calculations[$key] ?? null;
                if (!is_array($calculation)) {
                    continue;
                }
                // Never a raw config key: the model writes user-facing text from this.
                $label = is_string($calculation['label'] ?? null) && trim($calculation['label']) !== ''
                    ? trim($calculation['label'])
                    : AliasNormalizer::phrase((string) $key);
                if (AliasNormalizer::key($label) === '') {
                    continue;
                }

                $quantities[] = [
                    'tag' => 'Q' . (count($quantities) + 1),
                    'text' => CalculationMatcher::textOf($label, ['label' => $label] + $calculation),
                ];
                if (count($quantities) >= QueryUnderstandingResult::MAX_CANDIDATE_READINGS) {
                    break;
                }
            }

            return $quantities;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param list<array{name: string, synonyms: list<string>, keys: array<string, true>}> $kinds
     * @param list<array{tag: string, text: string}> $quantities
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(string $question, string $questionLanguage, array $kinds, array $quantities = []): array
    {
        // A kind a named quantity is already about is not listed: the quantity IS that
        // reading (see below).
        $covered = $this->covered($kinds, $quantities);
        $lines = [];
        foreach ($kinds as $index => $kind) {
            if (!isset($covered[$index])) {
                $lines[] = '- ' . $kind['name'] . ($kind['synonyms'] !== [] ? ' (' . implode(', ', $kind['synonyms']) . ')' : '');
            }
        }
        $kindList = implode("\n", $lines);

        // What the readings are drawn from. Without a named quantity the prompt is the
        // kinds-only one, word for word (tuned live, see below). With some, the model only
        // REWORDS them (key "reworded") and leaves them room among the readings: each is
        // something the application can actually compute, where a kind of record only
        // "can have" what is asked.
        //
        // Live (2026-10-06, four wordings on the Query-Understanding model): whatever the
        // instruction said — "may be what is asked", "further readings, they do not tell
        // which kind", "not part of the question", "never a kind a figure is about" — a
        // quantity in sight decided the model's "candidates": "Show me the balance." with
        // the one-order quantity came back with the single candidate "order" (the same
        // reading twice, or no fork at all in French), where the kinds-only prompt lists
        // four kinds. What held: the kinds a quantity is already about ({@see
        // self::covered()}) are left out of the list, so the candidates are the OTHER
        // readings; and whether a quantity is a reading is the matcher's judgment, never
        // the model's (it is not asked).
        $maxReadings = QueryUnderstandingResult::MAX_CANDIDATE_READINGS;
        $kindReadings = max(0, $maxReadings - count($quantities));
        $quantitiesDoc = '';
        $readingsDoc = '- "readings": for each of the first ' . $maxReadings . ' candidates, '
            . 'what is asked said of one such record: a short noun phrase (under 8 words) in plain everyday words, of '
            . 'the shape "<what is asked> of a <kind of record>".' . "\n";
        $questionDoc = '- "question": ONE short clarifying question, a single sentence, that names every reading and asks '
            . "which one is meant.\n\n";
        if ($quantities !== []) {
            $readingsDoc = $kindReadings === 0
                ? '- "readings": [].' . "\n"
                : '- "readings": for ' . ($kindReadings === 1 ? 'the first candidate' : "each of the first {$kindReadings} candidates")
                    . ', what is asked said of one such record: a short noun phrase (under 8 words) in plain everyday '
                    . 'words, of the shape "<what is asked> of a <kind of record>".' . "\n";
            $quantitiesDoc = '- "reworded": after the question you are given FIGURES the application can compute, tagged '
                . '"Q1", "Q2", …. For each of them, in that order: that figure said simply, as the readings are — a short '
                . 'noun phrase (under 10 words) in plain everyday words that keeps of what or of whom it is. Reword it: '
                . "never its text copied, no brackets, no slash, no capitals for emphasis.\n";
            $questionDoc = '- "question": ONE short clarifying question, a single sentence, that offers every entry of '
                . '"reworded" and then every entry of "readings", and asks which one is meant.' . "\n\n";
        }
        // Tuned live on the Query-Understanding model (2026-10-06, 27 questions in two
        // languages). What the structure is for:
        //  - the model is never asked "is it ambiguous?" nor "is it about the asker?":
        //    both judgments flipped with every rewording. It lists the first-person
        //    WORDS and tags their grammatical FORM — a task it does reliably — and the
        //    verdict is computed from that;
        //  - "candidates" before "readings": the kinds are settled first, then worded;
        //  - the reading's shape keeps it a noun phrase (asked for "a reading", the
        //    model wrote invented answers with figures).
        $system = 'You check one thing about a question put to the assistant of a business application: does it say '
            . 'which records it is about? The question is the FIRST message of a conversation — nothing said earlier '
            . "and no open page tells what it refers to.\n\n"
            . "THE KINDS OF RECORDS THE APPLICATION HOLDS (with other words people use for them):\n{$kindList}\n\n"
            . "Fill the reply in this order:\n"
            . '- "asked": what the question asks for, in two to four words. "" when it asks for nothing the '
            . "application's records could hold (a greeting, thanks, a question about the assistant itself).\n"
            . '- "first_person": EVERY first-person word of the question (each pronoun and each possessive for the '
            . 'asker or the asker\'s group), copied exactly. [] when the question has none.' . "\n"
            . '- "forms": the grammatical form of each of those words, in the same order: "subject" (subject of a '
            . 'verb), "possessive", or "object" (object of a verb or of a preposition).' . "\n"
            . '- "named": the words of the question, copied exactly, that name the records it is about: a kind of '
            . 'record, a name, a number or another identifier. "" when there are none. The thing asked for is not a '
            . "name.\n"
            . '- "candidates": every kind from the list whose records can have what is asked, the most plausible '
            . "first (up to 8).\n"
            . $readingsDoc
            . $quantitiesDoc
            . $questionDoc
            . "Rules:\n"
            . '- "readings"' . ($quantities !== [] ? ', "reworded"' : '') . ' and "question" are written in the '
            . 'language of the question, for the person who asked it: '
            . 'everyday words, never a technical name' . ($quantities !== [] ? ' or a tag' : '')
            . ", and no owner the question did not state.\n"
            . "- Do not answer the question and do not choose a reading yourself.\n\n"
            . 'Return ONLY JSON of this exact form: {"asked":"…","first_person":["…"],"forms":["…"],"named":"…",'
            . '"candidates":["…","…"],"readings":["…","…"],' . ($quantities !== [] ? '"reworded":["…"],' : '')
            . '"question":"…"}';

        $user = 'Question' . ($questionLanguage !== '' ? " (written in \"{$questionLanguage}\")" : '') . ": {$question}";
        if ($quantities !== []) {
            $user .= "\nFigures the application can compute:";
            foreach ($quantities as $quantity) {
                $user .= "\n{$quantity['tag']}: {$quantity['text']}";
            }
        }
        if ($questionLanguage !== '') {
            // Live: without this line the readings of a non-English question came back in English.
            $user .= $quantities !== []
                ? "\nWrite \"readings\", \"reworded\" and \"question\" in \"{$questionLanguage}\"."
                : "\nWrite \"readings\" and \"question\" in \"{$questionLanguage}\".";
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * The verdict of one reply, from its structure alone.
     *
     * @param list<array{name: string, synonyms: list<string>, keys: array<string, true>}> $kinds
     * @param list<array{tag: string, text: string}> $quantities
     * @param bool $sentenceOffersQuantities false when the reply's sentence was written without the
     *        quantities in sight (the kinds-only call): it cannot offer them
     * @return array{ask: bool, reason: string, readings: list<string>, question: string}
     */
    private function verdict(
        array $reply,
        string $question,
        array $kinds,
        array $quantities = [],
        bool $sentenceOffersQuantities = true
    ): array
    {
        $firstPerson = $reply['first_person'] ?? null;
        $candidates = $reply['candidates'] ?? null;
        $readings = $reply['readings'] ?? null;
        if (!is_array($firstPerson) || !is_array($candidates) || !is_array($readings)) {
            return $this->answer('invalid_reply');
        }

        // WHAT: a message that asks for nothing held in records has no reading to offer.
        if (!is_string($reply['asked'] ?? null) || trim($reply['asked']) === '') {
            return $this->answer('nothing_asked');
        }

        $questionTokens = array_fill_keys(AliasNormalizer::tokens($question), true);

        // WHOSE: a first-person word the question really contains, in any form but
        // "object" (an unknown or missing form counts: answering is the safe side).
        $forms = array_values(is_array($reply['forms'] ?? null) ? $reply['forms'] : []);
        foreach (array_values($firstPerson) as $i => $word) {
            $form = is_string($forms[$i] ?? null) ? strtolower(trim($forms[$i])) : '';
            if ($form !== self::FORM_OBJECT && $this->isQuoted($word, $questionTokens)) {
                return $this->answer('owner_stated');
            }
        }

        // WHICH: words of the question that name the records.
        if ($this->isQuoted($reply['named'] ?? null, $questionTokens)) {
            return $this->answer('record_named');
        }

        // The NAMED QUANTITIES, as the model reworded them (one per quantity handed
        // over, in order): each is a reading by the matcher's judgment, not the
        // model's. An entry that is no wording — missing, blank, a bare tag — is not.
        $quantityReadings = [];
        $reworded = is_array($reply['reworded'] ?? null) ? array_values($reply['reworded']) : [];
        foreach (array_keys($quantities) as $i) {
            $phrase = is_string($reworded[$i] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $reworded[$i])) : '';
            if ($phrase !== '' && preg_match(self::TAG_ONLY, $phrase) !== 1) {
                $quantityReadings[] = $phrase;
            }
        }

        // HOW MANY things can be what is asked: those quantities, and the kinds of
        // records that can carry it — counted among the kinds the application holds,
        // each once. A kind a quantity is already about is that quantity's reading
        // said twice, not one more (the list no longer shows it; the model, with the
        // quantity in sight, names it all the same).
        $covered = $this->covered($kinds, $quantities);
        $carrying = [];
        $repeated = [];
        foreach (array_values($candidates) as $position => $candidate) {
            $key = is_string($candidate) ? AliasNormalizer::key($candidate) : '';
            foreach ($key !== '' ? $kinds : [] as $index => $kind) {
                if (isset($kind['keys'][$key])) {
                    if (isset($covered[$index])) {
                        $repeated[$position] = true;
                    } else {
                        $carrying[$index] = true;
                    }
                    break;
                }
            }
        }
        if (count($quantityReadings) + count($carrying) < self::MIN_READINGS) {
            return $this->answer('single_reading');
        }

        // The readings offered: the quantities first, then the kinds' — each kind's
        // reading stands at its candidate's position, so a repeated kind's goes with it.
        $kept = $quantityReadings;
        $left = [];
        foreach (array_values($readings) as $position => $reading) {
            if (!is_string($reading) || trim($reading) === '') {
                continue;
            }
            $reading = trim((string) preg_replace('/\s+/u', ' ', $reading));
            if (isset($repeated[$position])) {
                $left[] = $reading;
            } else {
                $kept[] = $reading;
            }
        }
        $kept = array_values(array_unique($kept));
        $left = [...$left, ...array_slice($kept, QueryUnderstandingResult::MAX_CANDIDATE_READINGS)];
        $kept = array_slice($kept, 0, QueryUnderstandingResult::MAX_CANDIDATE_READINGS);
        if (count($kept) < self::MIN_READINGS) {
            return $this->answer('no_readings');
        }

        $clarifying = is_string($reply['question'] ?? null)
            ? trim((string) preg_replace('/\s+/u', ' ', $reply['question']))
            : '';
        if (mb_strlen($clarifying, 'UTF-8') > self::MAX_QUESTION_CHARS) {
            $clarifying = '';
        }
        // The model's sentence names every reading IT wrote. With quantities, one of them
        // may have been left out here: the sentence would then offer a reading the turn
        // does not — it is dropped, and the readings ask (the pipeline lists them).
        if ($quantities !== [] && array_diff($left, $kept) !== []) {
            $clarifying = '';
        }
        if ($quantityReadings !== [] && !$sentenceOffersQuantities) {
            $clarifying = '';
        }

        return ['ask' => true, 'reason' => 'ambiguous', 'readings' => $kept, 'question' => $clarifying];
    }

    /**
     * The kinds of records the named quantities are already about: a kind whose
     * name or synonym is a word (or a run of adjacent words) of a quantity's label
     * or description, in any spelling. "Outstanding balance of one order" covers
     * the kind "order": offered next to it, "the balance of an order" would be the
     * same reading twice. Structural — the host's own words on both sides.
     *
     * @param list<array{name: string, synonyms: list<string>, keys: array<string, true>}> $kinds
     * @param list<array{tag: string, text: string}> $quantities
     * @return array<int, true> keyed by the kind's index in $kinds
     */
    private function covered(array $kinds, array $quantities): array
    {
        if ($quantities === []) {
            return [];
        }

        $mentioned = [];
        foreach ($quantities as $quantity) {
            // Words only: "project/team (receivables…)" names the project AND the team.
            $tokens = AliasNormalizer::tokens((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $quantity['text']));
            foreach (array_keys($tokens) as $start) {
                foreach (range(1, self::MAX_KIND_WORDS) as $length) {
                    $key = AliasNormalizer::key(implode(' ', array_slice($tokens, $start, $length)));
                    if ($key !== '') {
                        $mentioned[$key] = true;
                    }
                }
            }
        }

        $covered = [];
        foreach ($kinds as $index => $kind) {
            if (array_intersect_key($kind['keys'], $mentioned) !== []) {
                $covered[$index] = true;
            }
        }

        return $covered;
    }

    /**
     * Whether a quoted value is made of words the question itself contains
     * (case, accents, plural marks and punctuation aside). Anything else is not
     * a quote of the question.
     *
     * @param array<string, true> $questionTokens
     */
    private function isQuoted(mixed $quote, array $questionTokens): bool
    {
        $tokens = is_string($quote) ? AliasNormalizer::tokens($quote) : [];
        if ($tokens === []) {
            return false;
        }

        foreach ($tokens as $token) {
            if (!isset($questionTokens[$token])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{ask: bool, reason: string, readings: list<string>, question: string}
     */
    private function answer(string $reason): array
    {
        return ['ask' => false, 'reason' => $reason, 'readings' => [], 'question' => ''];
    }

    private function index(): EntityLabelIndex
    {
        return $this->entities ?? EntityLabelIndex::shared();
    }

    /**
     * A boolean config flag that defaults to true. Container-tolerant for unit
     * tests: without a container the default applies.
     */
    private function configFlag(string $key): bool
    {
        try {
            return function_exists('config') ? (bool) config($key, true) : true;
        } catch (\Throwable) {
            return true;
        }
    }
}
