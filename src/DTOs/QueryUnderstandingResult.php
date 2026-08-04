<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

class QueryUnderstandingResult
{
    public function __construct(
        /** @var string[] Entity labels relevant to the question */
        public readonly array $entityTypes = [],
        /** @var string[] Person/contact names mentioned */
        public readonly array $personNames = [],
        /** @var string[] Property concepts ("insurance", "parking") */
        public readonly array $propertyConcepts = [],
        /** Rewritten query optimized for semantic search */
        public readonly string $searchQuery = '',
        /** Whether user is asking about their own data */
        public readonly bool $isPossessive = false,
        /** count|list|lookup|aggregate|compare|other */
        public readonly string $intent = 'other',
        /** data_query|knowledge_query|hybrid */
        public readonly string $questionType = 'hybrid',
        /** Clarified underlying question for better search/prompting */
        public readonly string $clarifiedQuestion = '',
        /** @var string[] Broad keyword tags for document/file search (5-10 terms covering the semantic space) */
        public readonly array $searchTags = [],
        /**
         * The user's question translated into each configured corpus language,
         * keyed by language code (e.g. ['fr' => '...', 'en' => '...']). Empty when
         * no corpus languages are configured. Each value is a natural, pure
         * single-language phrasing — used as an extra file-search fan-out angle so
         * a question in one language can retrieve documents written in another.
         *
         * @var array<string,string>
         */
        public readonly array $corpusTranslations = [],
        /**
         * The question reformulated in the corpus register configured by the
         * host (ai.file_context.corpus_register): 1-3 short phrasings using the
         * source documents' own terminology (e.g. a lay "tear down a wall"
         * rendered as "alteration of a private portion" for a legal corpus).
         * Empty when no corpus register is configured. Each is used as an extra
         * file-search fan-out angle so casual wording still retrieves documents
         * written in the domain register.
         *
         * @var string[]
         */
        public readonly array $registerExpansions = [],
        /**
         * The QU LLM's semantic judgment of whether the user is asking to RECEIVE
         * the information as a downloadable document/spreadsheet file, and which
         * format: 'pdf' | 'excel' | null (null = a normal in-chat answer).
         * Consumed FIRST by AiManager::buildFinalResponse; the deterministic
         * regex in ExportResponseComposer::detectExportIntent is only a fallback
         * for when QU produced no result (e.g. QU call failed).
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
         * The QU LLM's semantic judgment that the question, read in context, is
         * GENUINELY ambiguous: two or more materially different readings are
         * each plausible and answering the wrong one would mislead (iteration-11
         * item C: "Show me the balance." was force-resolved into a guessed
         * reading five runs straight). Emitted by the QU prompt ONLY when
         * ai.understanding.clarify_enabled (default true); enforced at parse
         * time to be false whenever followUpReference or digestRequest is true.
         * Consumed by AiManager::clarifyResponse, which SKIPS file search and
         * query generation and answers with $clarifyingQuestion — zero extra
         * LLM calls (the question rides the same single QU call).
         */
        public readonly bool $needsClarification = false,
        /**
         * 2-4 SHORT concept descriptions of the genuinely distinct readings, in
         * the question's language. Ride the response metadata
         * (response.clarification.candidate_readings) for graders/UI. Empty
         * when $needsClarification is false.
         *
         * @var string[]
         */
        public readonly array $candidateReadings = [],
        /**
         * ONE short question, in the question's language, asking which reading
         * is meant (naming the candidate readings naturally). This VERBATIM
         * text is the clarify turn's answer — composed by the QU LLM in the
         * same call, so the branch needs no additional LLM call and no
         * package-side canned phrasing (language policy holds by construction).
         * '' (unusable) falls back to the normal pipeline.
         */
        public readonly string $clarifyingQuestion = '',
    ) {}

    public static function empty(string $question = ''): self
    {
        return new self(
            isPossessive: $question !== '' && self::looksLikePossessive($question),
        );
    }

    private static function looksLikePossessive(string $question): bool
    {
        return (bool) preg_match('/\b(my|mine|i\s+have|i\s+own|belong.*to\s+me)\b/i', $question);
    }
}
