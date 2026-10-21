<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

class QueryUnderstandingResult
{
    /** A clarifying question offers at most this many readings. */
    public const MAX_CANDIDATE_READINGS = 4;

    /** $possessiveScope: the asker's OWN records ("my unit", "do I have…"). */
    public const SCOPE_PERSONAL = 'personal';

    /** $possessiveScope: the records of the asker's GROUP ("our meetings", "we owe…"). */
    public const SCOPE_COLLECTIVE = 'collective';

    public function __construct(
        /** @var string[] Entity labels relevant to the question */
        public readonly array $entityTypes = [],
        /** @var string[] Person/contact names mentioned */
        public readonly array $personNames = [],
        /** @var string[] Property concepts ("insurance", "parking") */
        public readonly array $propertyConcepts = [],
        /** Rewritten query optimized for semantic search */
        public readonly string $searchQuery = '',
        /**
         * Whether the user is asking about their own data — or their group's: see
         * $possessiveScope, and {@see self::isPersonalPossessive()} for "records
         * linked to the asker in person".
         *
         * DERIVED, never the model's own yes/no (asked for a boolean, it answered
         * true for "Show me the balance."): Query Understanding quotes the
         * first-person words of the question and this is true only when at least
         * one of them, used as a subject or a possessive, literally occurs in the
         * question ({@see \Condoedge\Ai\Services\QueryUnderstandingService}).
         */
        public readonly bool $isPossessive = false,
        /** count|list|lookup|aggregate|compare|other */
        public readonly string $intent = 'other',
        /** data_query|knowledge_query|hybrid|conversational (verified: {@see self::isConversational()}) */
        public readonly string $questionType = 'hybrid',
        /** Clarified underlying question for better search/prompting */
        public readonly string $clarifiedQuestion = '',
        /** @var string[] Broad keyword tags for document/file search (5-10 terms covering the semantic space) */
        public readonly array $searchTags = [],
        /**
         * DOCUMENT-SEARCH PROBE SLOT 1 — one probe per corpus language, keyed by
         * language code (e.g. ['fr' => '...', 'en' => '...']): the question
         * rewritten in THAT language and in the source documents' own wording.
         * Each value is one extra file-search fan-out angle, so a question in one
         * language still retrieves documents written in another.
         *
         * NOT produced by the Query-Understanding call (eval p15: inside that
         * prompt the probes came out copied, in everyday words or in the wrong
         * language, and cost ~530 prompt tokens on every turn). It is filled by
         * {@see self::withDocumentProbes()} from the focused
         * {@see \Condoedge\Ai\Services\Retrieval\DocumentProbeWriter} call, made
         * only on turns where document search runs. Empty otherwise.
         *
         * @var array<string,string>
         */
        public readonly array $corpusTranslations = [],
        /**
         * DOCUMENT-SEARCH PROBE SLOT 2 — the further probes (a second, distinct
         * line of attack per corpus language), each one more file-search fan-out
         * angle. Same producer and lifecycle as $corpusTranslations.
         *
         * @var string[]
         */
        public readonly array $registerExpansions = [],
        /**
         * The QU LLM's semantic judgment of whether the user is asking to RECEIVE
         * the information as a downloadable document/spreadsheet file, and which
         * format: 'pdf' | 'excel' | null (null = a normal in-chat answer).
         * The ONLY export-intent signal: AiManager::resolveExportIntent reads it
         * (the former regex fallback was removed in Phase 2.B).
         */
        public readonly ?string $exportFormat = null,
        /**
         * ISO 639-1 code of the language the QUESTION is written in (e.g. 'en',
         * 'fr'), as judged by the QU LLM. '' when unknown. Threaded into the
         * response layer so error/fallback answers follow the language policy
         * (answer in the question's language) instead of the app locale.
         */
        public readonly string $language = '',
        /**
         * The QU LLM's semantic judgment that this request OPERATES ON the result
         * the user was just shown ("export it", "convert that to excel", "and as
         * a PDF?") rather than asking for new data. When true: (a) export routing
         * prefers the PREVIOUS turn's recorded result over any freshly generated
         * query (anaphora misresolution otherwise exports the wrong data), and
         * (b) the turn is NEVER stored as a few-shot example — a context-dependent
         * question paired with a context-dependent cypher is meaningless outside
         * its conversation and would poison future generations.
         */
        public readonly bool $followUpReference = false,
        /**
         * The QU LLM's semantic judgment that the user asked BROADLY what needs
         * their attention across the workspace (an attention-digest ask) rather
         * than a single-topic question. Emitted by the QU prompt ONLY when the
         * host configured at least one enabled ai.attention_sources entry;
         * absent/malformed values degrade to false, so the normal pipeline is
         * the fail-safe. Consumed by AiManager::digestResponse (together with
         * followUpReference === false — a follow-up on a previous result is
         * never a digest) to route the turn through the deterministic
         * multi-source digest walk instead of query generation + file search.
         */
        public readonly bool $digestRequest = false,
        /**
         * The question is GENUINELY ambiguous: it names no subject and two or
         * more kinds of records are each a natural reading, so answering would
         * be a guess ("Show me the balance." as a first message).
         *
         * NOT produced by the Query-Understanding call (eval p15: as a QU output
         * key it came back true on 0 of 148 turns). It is set by
         * {@see self::withClarification()} from the focused
         * {@see \Condoedge\Ai\Services\Understanding\ClarityChecker} call, made
         * only when that checker's structural preconditions hold; false
         * otherwise, and never true together with followUpReference or
         * digestRequest. Consumed by AiManager::clarifyResponse, which SKIPS
         * file search and query generation and answers with $clarifyingQuestion.
         */
        public readonly bool $needsClarification = false,
        /**
         * 2-4 SHORT plain-word descriptions of the distinct readings, in the
         * question's language. Ride the response metadata
         * (response.clarification.candidate_readings) for graders/UI. Empty
         * when $needsClarification is false.
         *
         * @var string[]
         */
        public readonly array $candidateReadings = [],
        /**
         * ONE short question, in the question's language, asking which reading
         * is meant (naming the candidate readings in plain words). This VERBATIM
         * text is the clarify turn's answer — composed by the model in the
         * clarity call, so there is no package-side canned phrasing (language
         * policy holds by construction).
         * '' (unusable): AiManager asks a lang-key fallback listing
         * $candidateReadings in the question's language, or — with no readings
         * either — falls back to the normal pipeline.
         */
        public readonly string $clarifyingQuestion = '',
        /**
         * Structural marker: the QU call FAILED (exception / provider error) and
         * this is the neutral fallback — so "no export ask / no follow-up" means
         * "unknown", not "judged absent". Never set by a successful analysis.
         * Surfaced as response metadata export_intent_unavailable='qu_failed'.
         */
        public readonly bool $failed = false,
        /**
         * The QU LLM's semantic judgment (actions plan B2, any language) that the user asks
         * to see / open / get a link to their OWN profile/account/personal settings
         * ('own_profile') or their OWN team page ('own_team'); null otherwise. A structured
         * output only — never added to entity_types or retrieval; the action attacher
         * attaches the matching page, and an empty turn answers by pointing at it.
         */
        public readonly ?string $navigationTarget = null,
        /**
         * A short human title (2-6 words) of the request's SUBJECT, written in the
         * QUESTION's language, with no delivery/format words (eval p12-B: a French
         * export was titled by the English-normalized $searchQuery). USER-VISIBLE text
         * only — export/document titles, the recorded conversation subject; never fed
         * to semantic search or embeddings ($searchQuery stays that). '' when the LLM
         * gave none — callers fall back to $searchQuery.
         */
        public readonly string $subjectLabel = '',
        /**
         * The words of the question that make it possessive, as the model quoted
         * them and after verification: each one literally occurs in the question
         * and was tagged a subject or a possessive (the object of "show me…" is
         * not one). Empty exactly when $isPossessive is false. Trace / debugging.
         *
         * @var string[]
         */
        public readonly array $possessiveWords = [],
        /**
         * WHOSE data a possessive question is about: SCOPE_PERSONAL (the asker
         * alone — "my unit", "do I have…"), SCOPE_COLLECTIVE (the asker's group or
         * organization — "our meetings", "we owe…"), or null: not possessive, or
         * possessive with a scope the model did not state. The scope is the
         * model's reading of the verified words (first-person singular / plural);
         * it is kept only next to a verified word.
         */
        public readonly ?string $possessiveScope = null,
        /**
         * The keys the Query-Understanding prompt asked for that the reply did NOT
         * write, in the prompt's order. Each such field holds its neutral default
         * (no navigation target, no export, not a digest…), which then means "not
         * answered" — not "judged absent". Empty on a complete reply: an explicit
         * null / false IS the model's judgment. With a strict output schema the
         * provider guarantees every key, so a name here says the schema was not
         * applied (a provider without structured outputs, or a refused schema).
         * Trace only (`missing_keys`): no route reads it.
         *
         * @var string[]
         */
        public readonly array $missingKeys = [],
    ) {}

    /**
     * The question is about records linked to the asker IN PERSON ("my unit", "do I
     * have…"): the own-data path. A possessive question of unknown scope reads as
     * personal — what every possessive question was before the scope existed.
     */
    public function isPersonalPossessive(): bool
    {
        return $this->isPossessive && $this->possessiveScope !== self::SCOPE_COLLECTIVE;
    }

    /**
     * The question is about what the asker's GROUP has ("our meetings", "we owe…"):
     * the team's records, not the ones linked to the asker in person.
     */
    public function isCollectivePossessive(): bool
    {
        return $this->isPossessive && $this->possessiveScope === self::SCOPE_COLLECTIVE;
    }

    /** $questionType of a greeting, thanks, small talk or a question about the assistant itself. */
    public const TYPE_CONVERSATIONAL = 'conversational';

    /**
     * Intents that ask for something to be looked up or shown even with nothing else
     * named: a conversational reply carrying one of them is not small talk.
     */
    private const LOOKUP_INTENTS = ['file_listing', 'page_explanation'];

    /**
     * The turn is conversation with the assistant — a greeting, thanks, small talk, a
     * question about the assistant itself — and nothing is to be looked up for it.
     *
     * The model classifies (questionType 'conversational'); this VERIFIES it
     * structurally: no entity type, person, property concept, navigation target,
     * export format, digest ask, follow-up on a previous result, file listing / page
     * explanation intent or clarification need — and the QU call did not fail. One
     * such signal and the turn is not conversational (a mixed "Hi! Who is on our
     * board?" names a record kind). No word list: only the reply's structure is read.
     */
    public function isConversational(): bool
    {
        return $this->questionType === self::TYPE_CONVERSATIONAL
            && !self::namesSomethingToLookUp(
                $this->entityTypes,
                $this->personNames,
                $this->propertyConcepts,
                $this->navigationTarget,
                $this->exportFormat,
                $this->digestRequest,
                $this->followUpReference,
                $this->intent
            )
            && !$this->needsClarification
            && !$this->failed;
    }

    /**
     * Whether a Query-Understanding reading names anything to look up, show or act on
     * (the structural half of {@see self::isConversational()}; QueryUnderstandingService
     * applies it to the reply before the result exists).
     *
     * @param array<int, mixed> $entityTypes
     * @param array<int, mixed> $personNames
     * @param array<int, mixed> $propertyConcepts
     */
    public static function namesSomethingToLookUp(
        array $entityTypes,
        array $personNames,
        array $propertyConcepts,
        ?string $navigationTarget,
        ?string $exportFormat,
        bool $digestRequest,
        bool $followUpReference,
        string $intent
    ): bool {
        return $entityTypes !== []
            || $personNames !== []
            || $propertyConcepts !== []
            || $navigationTarget !== null
            || $exportFormat !== null
            || $digestRequest
            || $followUpReference
            || in_array($intent, self::LOOKUP_INTENTS, true);
    }

    /** The user-visible subject: the localized label, else the search subject ('' when neither). */
    public function displaySubject(): string
    {
        $label = trim($this->subjectLabel);

        return $label !== '' ? $label : trim($this->searchQuery);
    }

    /**
     * A copy judged as a follow-up that operates on the previous result, with
     * the clarify fields cleared. Used by AiManager's structured clarify guard
     * (an export ask while a result is recorded) so the export path sources the
     * recorded result instead of a fresh, possibly re-anchored query.
     */
    public function asFollowUpOnPreviousResult(): self
    {
        return new self(
            entityTypes: $this->entityTypes,
            personNames: $this->personNames,
            propertyConcepts: $this->propertyConcepts,
            searchQuery: $this->searchQuery,
            isPossessive: $this->isPossessive,
            intent: $this->intent,
            questionType: $this->questionType,
            clarifiedQuestion: $this->clarifiedQuestion,
            searchTags: $this->searchTags,
            corpusTranslations: $this->corpusTranslations,
            registerExpansions: $this->registerExpansions,
            exportFormat: $this->exportFormat,
            language: $this->language,
            followUpReference: true,
            digestRequest: $this->digestRequest,
            needsClarification: false,
            candidateReadings: [],
            clarifyingQuestion: '',
            failed: $this->failed,
            navigationTarget: $this->navigationTarget,
            subjectLabel: $this->subjectLabel,
            possessiveWords: $this->possessiveWords,
            possessiveScope: $this->possessiveScope,
            missingKeys: $this->missingKeys,
        );
    }

    /**
     * A copy carrying the turn's document-search probes in the two slots the
     * file-search fan-out reads: the FIRST probe of each language in
     * $corpusTranslations (language => probe), every further probe in
     * $registerExpansions. Blank / non-string probes are ignored. Every other
     * field is carried over unchanged.
     *
     * @param array<string, mixed> $probesByLanguage language code => list of probes
     *        ({@see \Condoedge\Ai\Services\Retrieval\DocumentProbeWriter::write()}).
     */
    public function withDocumentProbes(array $probesByLanguage): self
    {
        $perLanguage = [];
        $further = [];
        foreach ($probesByLanguage as $language => $probes) {
            if (!is_array($probes)) {
                continue;
            }
            foreach ($probes as $probe) {
                if (!is_string($probe) || trim($probe) === '') {
                    continue;
                }
                if (!isset($perLanguage[(string) $language])) {
                    $perLanguage[(string) $language] = trim($probe);
                } else {
                    $further[] = trim($probe);
                }
            }
        }

        // Promoted properties and constructor parameters share their names.
        return new self(...array_merge(get_object_vars($this), [
            'corpusTranslations' => $perLanguage,
            'registerExpansions' => $further,
        ]));
    }

    /**
     * A copy that needs clarification: the distinct readings (trimmed strings, at
     * most MAX_CANDIDATE_READINGS) and the ONE clarifying question (single-spaced)
     * of the focused clarity call
     * ({@see \Condoedge\Ai\Services\Understanding\ClarityChecker::check()}). Every
     * other field is carried over unchanged.
     *
     * CLARIFY GUARD (formerly enforced when the QU reply was parsed): a follow-up
     * on a previous answer or a broad digest ask can NEVER come out needing
     * clarification, and with nothing to ask (no question, no reading) there is no
     * clarification either — the result is returned as it is.
     *
     * @param array<int, mixed> $readings
     */
    public function withClarification(array $readings, string $clarifyingQuestion): self
    {
        $kept = [];
        foreach ($readings as $reading) {
            if (is_string($reading) && trim($reading) !== '') {
                $kept[] = trim($reading);
            }
        }
        $kept = array_slice($kept, 0, self::MAX_CANDIDATE_READINGS);
        $clarifyingQuestion = trim((string) preg_replace('/\s+/u', ' ', $clarifyingQuestion));

        if ($this->followUpReference || $this->digestRequest || ($kept === [] && $clarifyingQuestion === '')) {
            return $this;
        }

        // Promoted properties and constructor parameters share their names.
        return new self(...array_merge(get_object_vars($this), [
            'needsClarification' => true,
            'candidateReadings' => $kept,
            'clarifyingQuestion' => $clarifyingQuestion,
        ]));
    }

    /**
     * Snake-case snapshot of what QU understood (pipeline trace / observer).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'question_type' => $this->questionType,
            'intent' => $this->intent,
            'entity_types' => $this->entityTypes,
            'person_names' => $this->personNames,
            'property_concepts' => $this->propertyConcepts,
            'search_query' => $this->searchQuery,
            'clarified_question' => $this->clarifiedQuestion,
            'search_tags' => $this->searchTags,
            'is_possessive' => $this->isPossessive,
            'possessive_words' => $this->possessiveWords,
            'possessive_scope' => $this->possessiveScope,
            'corpus_translations' => $this->corpusTranslations,
            'register_expansions' => $this->registerExpansions,
            'follow_up_reference' => $this->followUpReference,
            'digest_request' => $this->digestRequest,
            'export_format' => $this->exportFormat,
            'language' => $this->language,
            'needs_clarification' => $this->needsClarification,
            'candidate_readings' => $this->candidateReadings,
            'clarifying_question' => $this->clarifyingQuestion,
            'navigation_target' => $this->navigationTarget,
            'subject_label' => $this->subjectLabel,
            // Only when the reply left a key out: a complete reply's snapshot is unchanged.
        ] + ($this->missingKeys !== [] ? ['missing_keys' => $this->missingKeys] : []);
    }

    /**
     * The "QU produced nothing" result (QU unavailable or its call failed):
     * every semantic signal is at its neutral default — including isPossessive,
     * which is NOT guessed from the question text (no word lists; Phase 2.B).
     * $question is accepted for call-site compatibility and deliberately unused.
     */
    public static function empty(string $question = ''): self
    {
        return new self();
    }

    /**
     * The neutral fallback for a QU call that FAILED — same signals as empty(),
     * flagged $failed so downstream can report the judgment as unavailable.
     */
    public static function failed(): self
    {
        return new self(failed: true);
    }
}
