<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Support\AiLogger;

/**
 * QueryUnderstandingService
 *
 * Pre-processes natural language questions before semantic context selection
 * to improve entity matching. Uses a fast LLM call to identify:
 * - Entity types mentioned or implied in the question
 * - Person names that need fuzzy search
 * - Property concepts the query is about
 * - A rewritten search query optimized for semantic matching
 * - The user's intent (count, list, lookup, aggregate, etc.)
 *
 * This layer runs BEFORE SemanticContextSelector and helps resolve ambiguous
 * references like "John Smith" -> Person entity, "rent" -> financial property,
 * "volunteers" -> PersonTeam with role filter.
 */
class QueryUnderstandingService
{
    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly AiLogger $logger,
        private readonly array $config = []
    ) {
    }

    /**
     * Analyze a question to extract structured understanding.
     *
     * @param string $question  The user's natural language question
     * @param array  $entityCatalog  Compact catalog built from entity configs (see buildEntityCatalog)
     * @return QueryUnderstandingResult Structured result with entity types, person names, intent, etc.
     */
    public function analyze(string $question, array $entityCatalog): QueryUnderstandingResult
    {
        $this->logger->startTimer('query_understanding');

        try {
            $messages = $this->buildMessages($question, $entityCatalog);

            $response = $this->llm->chatJson($messages, [
                'max_tokens' => $this->config['max_tokens'] ?? 500,
                'temperature' => $this->config['temperature'] ?? 0.0,
            ]);

            $result = $this->normalizeResponse($response, $entityCatalog);

            $ms = $this->logger->stopTimer('query_understanding');
            $this->logger->info('query_understanding', 'Analysis complete', [
                'duration_ms' => $ms,
                'entity_types' => $result->entityTypes,
                'person_names' => $result->personNames,
                'property_concepts' => $result->propertyConcepts,
                'intent' => $result->intent,
                'question_type' => $result->questionType,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->stopTimer('query_understanding');
            $this->logger->error('query_understanding', 'Analysis failed, using empty result', [
                'error' => $e->getMessage(),
            ]);

            return QueryUnderstandingResult::empty($question);
        }
    }

    /**
     * Build a compact entity catalog from full entity configs.
     *
     * The catalog is designed to be token-efficient: only labels, top aliases,
     * and short descriptions are included. Property groups are NOT included
     * (too many tokens); property concepts should be inferred by the LLM.
     *
     * @param array $entityConfigs Full entity configs keyed by FQCN
     * @return array Compact catalog: [ ['label' => 'Contact', 'aliases' => [...], 'description' => '...'], ... ]
     */
    public static function buildEntityCatalog(array $entityConfigs): array
    {
        $catalog = [];

        foreach ($entityConfigs as $fqcn => $config) {
            $metadata = $config['metadata'] ?? [];
            $label = $config['graph']['label'] ?? class_basename($fqcn);
            $aliases = array_slice($metadata['aliases'] ?? [], 0, 5);
            $description = $metadata['description'] ?? '';

            // Truncate description to keep prompt compact
            if (strlen($description) > 120) {
                $description = substr($description, 0, 117) . '...';
            }

            $catalog[] = [
                'label' => $label,
                'aliases' => $aliases,
                'description' => $description,
            ];
        }

        return $catalog;
    }

    /**
     * Build the chat messages for the LLM call.
     */
    private function buildMessages(string $question, array $entityCatalog): array
    {
        $catalogText = $this->formatCatalog($entityCatalog);
        $translationKeyDoc = $this->translationInstruction($this->corpusLanguages());
        $registerKeyDoc = $this->registerExpansionInstruction($this->corpusRegister());
        $digestKeyDoc = $this->digestRequestInstruction();
        $clarifyKeyDoc = $this->clarifyInstruction();

        $system = <<<PROMPT
You are a query understanding assistant for a graph database system. Your job is to analyze a natural language question and extract structured information to help find the right data.

AVAILABLE ENTITY TYPES:
{$catalogText}

TASK: Given the user's question, return a JSON object with exactly these keys:
- "entity_types": array of entity label strings from the list above that are relevant to the question. Include entities that are explicitly mentioned AND entities that are implicitly needed (e.g., if asking about "rent payments", include both the payment entity and the unit/property entity).
- "person_names": array of person/contact name strings mentioned in the question (e.g., "John Smith", "Maria"). Empty array if no names mentioned.
- "property_concepts": array of SHORT single-word or two-word property concept strings (e.g., "insurance", "email", "balance", "birthday", "parking"). Keep each concept as short as possible — one word preferred. These are abstract data concepts that may be stored as properties on entities, NOT entity types themselves.
- "search_query": a rewritten version of the question optimized for semantic search against entity descriptions. Remove filler words, expand abbreviations, use domain terminology. It is the SUBJECT of the request only — never include delivery or format words (export, download, excel, pdf, file, document-as-artifact): "can I get an excel with the team members' contact info" → "team members contact information".
- "is_possessive": boolean, true if the user is asking about THEIR OWN data ("my unit", "my invoices", "do I have", "am I", etc.)
- "intent": one of: "count", "list", "lookup", "aggregate", "compare", "file_listing", "page_explanation", "other". Use "file_listing" when the user asks to see or list the documents/files available to them (e.g. "which files do I have access to?", "show my documents", "list the files I can see"). Use "page_explanation" when the user asks what the current page/screen is or what they can do on it (e.g. "what is this page?", "what can I do here?", "explain this screen") — they want a description of the page itself, not a data lookup.
- "question_type": one of "data_query" (needs database/graph lookup), "knowledge_query" (needs file/document content — rules, regulations, policies, "can I do X?"), "hybrid" (needs both data and documents)
- "clarified_question": "Usually the concrete asking of the user hides a more abstract context and intent. If the question is "How many parking spaces do I have?" the clarified_question might be "How many parking space entities are related to the person entity representing me in the graph?" This is meant to clarify the underlying data relationships and intent in a way that can help with debugging and prompt engineering. Keep it concise.
But also it could have a base significance. let's say the user asks "Can i tear down a wall in my apartment?" the clarified question might be "What are the rules and regulations regarding modifying a residential unit in my building, more specifically tearing down a wall, and how do they apply to me as a tenant?"
- "search_tags": array of 5 to 10 keyword tags for searching documents and files. Include: (1) literal terms from the question, (2) domain synonyms that would appear in the relevant documents, (3) the broader category or domain the question belongs to, (4) related technical terms, and (5) if the project's documents are multilingual, translations of the key terms into the other language(s). Think: what words would appear in a document, rule, or regulation that answers this question?
- "export_format": "pdf", "excel", or null. Set a format ONLY when the user is asking to receive the information as a downloadable document or spreadsheet file — an artifact they can save or open outside the chat — regardless of how they phrase that request. Judge the intent semantically, not by specific wording. Use "excel" for spreadsheet/workbook-style files and "pdf" for document-style files. Use null when the user just wants an answer in the chat, or merely mentions a file/document as the SUBJECT of the question rather than asking to receive one.
- "language": the two-letter ISO 639-1 code of the language the question is written in.
- "follow_up_reference": boolean. True ONLY when the request operates on the result the user was JUST SHOWN in this conversation — it refers back to previous output rather than asking for new information (the request would be meaningless without the previous answer). Judge semantically from the conversation context, not by specific words. False for self-contained questions, even inside a long conversation.{$translationKeyDoc}{$registerKeyDoc}{$digestKeyDoc}{$clarifyKeyDoc}

Rules:
- Only use entity labels from the AVAILABLE ENTITY TYPES list.
- For person names, extract the actual name strings, not entity types.
- Property concepts should be abstract data concepts, not Neo4j property names.
- When the user asks for their own profile/contact details in any language (for example "my contact information", "my profile", "my email", "my name", "information about me"), treat that as authenticated user/team identity context. Do not return ambiguous profile/contact entity labels for those phrases unless the user clearly asks for contact records, a contacts list, another person's contact, or a domain contact profile record.
- Keep arrays empty rather than guessing if uncertain.
- Return ONLY valid JSON, no markdown fences or explanation.
PROMPT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $question],
        ];
    }

    /**
     * The configured corpus languages (e.g. ['fr', 'en']). Empty by default, which
     * keeps the prompt and result unchanged (no translation, zero added cost).
     *
     * @return string[] Normalized, de-duplicated, non-empty language codes.
     */
    private function corpusLanguages(): array
    {
        $languages = $this->config['corpus_languages'] ?? [];
        if (!is_array($languages)) {
            return [];
        }

        $normalized = [];
        foreach ($languages as $code) {
            if (is_string($code) && trim($code) !== '') {
                $normalized[] = trim($code);
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Build the extra prompt instruction asking the model to translate the question
     * into each configured corpus language. Returns '' when none are configured so
     * the base prompt is untouched.
     */
    private function translationInstruction(array $languages): string
    {
        if (empty($languages)) {
            return '';
        }

        $list = implode('", "', $languages);

        // White-label invariant: NO domain vocabulary or worked domain example is
        // baked in here. When the host configured a corpus register, its OWN text
        // steers the terminology (same route as register_expansions); otherwise the
        // instruction stays purely structural.
        $registerHint = '';
        $register = $this->corpusRegister();
        if ($register !== '') {
            $registerHint = " The source documents' register: {$register} — prefer "
                . "that register's own terms (in the target language) in each rendering.";
        }

        return "\n- \"corpus_translations\": an object mapping each of these corpus "
            . "language codes [\"{$list}\"] to a search-optimized rendering of the "
            . "question in THAT language only (a single fluent, pure-language "
            . "phrasing — never mixed-language keyword soup). Do NOT translate the "
            . "surface words literally and do NOT keep it minimal: render the intent "
            . "using the concrete domain/technical terms the relevant SOURCE "
            . "DOCUMENTS in that language actually use. You MUST name, in that "
            . "language: (a) the category of action or activity as the documents "
            . "themselves name it — the documents' own umbrella term for the kind "
            . "of action being asked about, never a word-for-word translation of "
            . "the user's casual phrasing; (b) the specific object or subject "
            . "affected; and (c) the governing context (the rules, framework or "
            . "setting that applies). These renderings are additional "
            . "document-search probes so a question asked in one language still "
            . "retrieves documents written in another." . $registerHint;
    }

    /**
     * The extra prompt key asking the model to judge whether the turn is a
     * BROAD "what needs my attention" ask (attention digest). Emitted ONLY when
     * the host configured at least one enabled ai.attention_sources entry —
     * hosts without the feature pay zero tokens and see zero behavior change
     * (same conditional-line pattern as translationInstruction()). The concept
     * is described semantically; no phrase lists and no host-domain vocabulary
     * live in package code (white-label rule).
     */
    private function digestRequestInstruction(): string
    {
        if (!$this->hasEnabledAttentionSources()) {
            return '';
        }

        return "\n- \"digest_request\": boolean. True ONLY when the user asks BROADLY what "
            . 'needs their attention across their workspace — what to take care of next, '
            . 'what is coming up, what is pending or overdue overall — WITHOUT restricting '
            . 'the question to a single topic or domain. Judge the scope semantically, in '
            . 'any language or phrasing. False when the question targets one specific '
            . 'subject (one entity type, one record, one metric), even when it is '
            . 'urgency-flavored, and false for follow-ups that operate on a previous answer.';
    }

    /**
     * The extra prompt keys asking the model to flag GENUINE ambiguity and
     * compose the single clarifying question (item C — clarify branch). Emitted
     * only when ai.understanding.clarify_enabled (default true). Concept-
     * described, no phrase lists, no domain vocabulary (white-label rule); the
     * clarifying question is composed by the model IN THE QUESTION'S LANGUAGE,
     * so the pipeline's clarify branch needs no package-side canned phrasing
     * and no additional LLM call.
     */
    private function clarifyInstruction(): string
    {
        if (!$this->clarifyEnabled()) {
            return '';
        }

        return "\n- \"needs_clarification\": boolean. True ONLY when the question, read in its "
            . 'conversation context, is GENUINELY ambiguous: two or more materially different '
            . 'readings (different subjects, different data, or data-vs-document intent) are '
            . 'each plausible and answering the wrong one would mislead the user. False whenever '
            . 'context (the conversation, the page, possessive phrasing) makes one reading '
            . 'clearly dominant, false for requests that operate on a previous answer, and '
            . 'false for broad what-needs-my-attention asks. Prefer answering over asking: '
            . 'flag only genuine forks, never mild vagueness.'
            . "\n- \"candidate_readings\": array of 2 to 4 SHORT descriptions (under ~12 words "
            . "each) of the genuinely distinct readings, written in the question's language. "
            . 'Empty when needs_clarification is false.'
            . "\n- \"clarifying_question\": ONE short question, in the question's language, "
            . 'asking which reading is meant — naming each candidate reading naturally. '
            . 'Empty when needs_clarification is false.';
    }

    /**
     * Whether the clarify branch is enabled (ai.understanding.clarify_enabled,
     * default true). Container-tolerant for unit tests: an injected config key
     * wins; without a container the default applies.
     */
    private function clarifyEnabled(): bool
    {
        if (array_key_exists('clarify_enabled', $this->config)) {
            return (bool) $this->config['clarify_enabled'];
        }

        return function_exists('config')
            ? (bool) config('ai.understanding.clarify_enabled', true)
            : true;
    }

    /**
     * Whether the host configured at least one enabled attention source.
     * Container-tolerant for unit tests (an injected config wins; without a
     * container the feature is simply off).
     */
    private function hasEnabledAttentionSources(): bool
    {
        $sources = $this->config['attention_sources']
            ?? (function_exists('config') ? config('ai.attention_sources', []) : []);

        if (!is_array($sources)) {
            return false;
        }

        foreach ($sources as $entry) {
            if (is_array($entry)
                && (($entry['enabled'] ?? true) !== false)
                && trim((string) ($entry['cypher'] ?? '')) !== ''
                && trim((string) ($entry['label'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The configured corpus register (a host-supplied plain-text description of
     * the terminology the source documents are written in). Empty by default,
     * which keeps the prompt and result unchanged (no expansion, zero added cost).
     * The register text is pure HOST configuration — the package never ships
     * domain vocabulary of its own.
     */
    private function corpusRegister(): string
    {
        $register = $this->config['corpus_register'] ?? '';

        return is_string($register) ? trim($register) : '';
    }

    /**
     * Build the extra prompt instruction asking the model to reformulate the
     * question in the corpus register's own terminology (lay -> domain-register
     * expansion). Returns '' when no register is configured so the base prompt
     * is untouched.
     */
    private function registerExpansionInstruction(string $register): string
    {
        if ($register === '') {
            return '';
        }

        return "\n- \"register_expansions\": an array of 1 to 3 SHORT reformulations "
            . "of the question, each written in the register the source documents "
            . "use: {$register}. Do NOT repeat the user's casual wording — rephrase "
            . "the SAME question using that register's own terms for (a) the action "
            . "or situation as the documents name it, (b) the specific object or "
            . "subject affected, and (c) the governing context. One reformulation "
            . "per distinct line of attack, each under ~20 words. These are "
            . "additional document-search probes: wording that would literally "
            . "appear in such documents matters more than fluency.";
    }

    /**
     * Format the entity catalog as a compact text block for the prompt.
     */
    private function formatCatalog(array $catalog): string
    {
        $lines = [];

        foreach ($catalog as $entry) {
            $label = $entry['label'] ?? '';
            $aliases = $entry['aliases'] ?? [];
            $description = $entry['description'] ?? '';

            $line = "- {$label}";
            if (!empty($aliases)) {
                $line .= ' (aka: ' . implode(', ', $aliases) . ')';
            }
            if (!empty($description)) {
                $line .= ": {$description}";
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Normalize the LLM response into the expected structure.
     *
     * @param object|array $response      Raw LLM JSON response
     * @param array        $entityCatalog Catalog used to validate entity_types (see buildEntityCatalog)
     */
    private function normalizeResponse(object|array $response, array $entityCatalog = []): QueryUnderstandingResult
    {
        $data = is_object($response) ? (array) $response : $response;
        $personNames = $this->ensureStringArray($data['person_names'] ?? []);
        $propertyConcepts = $this->ensureStringArray($data['property_concepts'] ?? []);
        $isPossessive = (bool) ($data['is_possessive'] ?? false);
        $intent = $this->normalizeIntent($data['intent'] ?? 'other');

        $entityTypes = $this->validateEntityTypes(
            $this->ensureStringArray($data['entity_types'] ?? []),
            $entityCatalog
        );
        $entityTypes = $this->removeOwnProfileAmbiguousEntities($entityTypes, $isPossessive, $intent, $personNames);

        $followUpReference = (bool) ($data['follow_up_reference'] ?? false);
        $digestRequest = (bool) ($data['digest_request'] ?? false);

        // CLARIFY GUARD (item C, enforced at parse time — defence in depth for
        // the pipeline-side guard): a follow-up on a previous answer or a broad
        // digest ask can NEVER come out needing clarification, and a malformed /
        // non-boolean value degrades to false (the normal pipeline is the
        // fail-safe). The readings/question are only honoured alongside a
        // surviving needs_clarification.
        $needsClarification = ($data['needs_clarification'] ?? false) === true
            && $this->clarifyEnabled()
            && !$followUpReference
            && !$digestRequest;
        $clarifyingQuestion = is_string($data['clarifying_question'] ?? null)
            ? trim($data['clarifying_question'])
            : '';

        return new QueryUnderstandingResult(
            entityTypes: $entityTypes,
            personNames: $personNames,
            propertyConcepts: $propertyConcepts,
            searchQuery: is_string($data['search_query'] ?? null) ? $data['search_query'] : '',
            isPossessive: $isPossessive,
            intent: $intent,
            questionType: $this->normalizeQuestionType($data['question_type'] ?? 'hybrid'),
            clarifiedQuestion: is_string($data['clarified_question'] ?? null) ? $data['clarified_question'] : '',
            searchTags: array_slice($this->ensureStringArray($data['search_tags'] ?? []), 0, 10),
            corpusTranslations: $this->parseCorpusTranslations($data['corpus_translations'] ?? null),
            registerExpansions: $this->parseRegisterExpansions($data['register_expansions'] ?? null),
            exportFormat: $this->parseExportFormat($data['export_format'] ?? null),
            language: $this->parseLanguage($data['language'] ?? null),
            followUpReference: $followUpReference,
            digestRequest: $digestRequest,
            needsClarification: $needsClarification,
            candidateReadings: $needsClarification
                ? array_slice($this->ensureStringArray($data['candidate_readings'] ?? []), 0, 4)
                : [],
            clarifyingQuestion: $needsClarification ? $clarifyingQuestion : '',
        );
    }

    /**
     * Parse the LLM's export_format into 'pdf' | 'excel' | null. Anything the
     * result DTO does not define degrades to null so a malformed value can never
     * force (or block) an export downstream.
     */
    private function parseExportFormat(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return in_array($value, ['pdf', 'excel'], true) ? $value : null;
    }

    /**
     * Parse the LLM's language into a two-letter ISO 639-1 code ('' when the
     * value is unusable). A locale-style value ('en-US', 'fr_CA') degrades to its
     * primary subtag.
     */
    private function parseLanguage(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = strtolower(trim($value));
        if (preg_match('/^([a-z]{2})([_-][a-z0-9]+)?$/i', $value, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    /**
     * Parse the LLM's corpus_translations into a clean language => translation map.
     *
     * Only the configured corpus languages are kept (a model that volunteers an
     * unrequested language is ignored), blank translations are dropped, and any
     * non-map / malformed value degrades silently to no translations so retrieval
     * never breaks.
     *
     * @param mixed $value Raw corpus_translations from the LLM response.
     * @return array<string,string>
     */
    private function parseCorpusTranslations(mixed $value): array
    {
        $languages = $this->corpusLanguages();
        if (empty($languages)) {
            return [];
        }

        // chatJson decodes nested JSON objects to stdClass; coerce to an
        // associative array so an object map is handled like an array map.
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return [];
        }

        $translations = [];
        foreach ($languages as $code) {
            $translation = $value[$code] ?? null;
            if (is_string($translation) && trim($translation) !== '') {
                $translations[$code] = trim($translation);
            }
        }

        return $translations;
    }

    /**
     * Parse the LLM's register_expansions into a clean list of 1-3 non-empty
     * reformulations.
     *
     * Only honoured when a corpus register is configured (a model that
     * volunteers unrequested expansions is ignored); blanks/non-strings are
     * dropped and any malformed value degrades silently to no expansions so
     * retrieval never breaks.
     *
     * @param mixed $value Raw register_expansions from the LLM response.
     * @return string[]
     */
    private function parseRegisterExpansions(mixed $value): array
    {
        if ($this->corpusRegister() === '') {
            return [];
        }

        $expansions = [];
        foreach ($this->ensureStringArray($value) as $expansion) {
            $expansion = trim($expansion);
            if ($expansion !== '') {
                $expansions[] = $expansion;
            }
        }

        return array_slice(array_values(array_unique($expansions)), 0, 3);
    }

    /**
     * Ensure a value is an array of strings.
     */
    private function ensureStringArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn($v) => is_string($v) ? $v : null, $value)
        ));
    }

    /**
     * Filter LLM-returned entity types to only labels present in the catalog.
     *
     * The downstream pipeline force-injects QU entity_types into semantic selection
     * at a high score (~0.8), so a hallucinated label would pollute retrieval. We
     * keep only labels that exist in the entity catalog and log any rejected ones
     * at debug. Matching is case-insensitive against the catalog's canonical label;
     * the canonical casing is preserved in the output.
     *
     * @param string[] $entityTypes   Labels returned by the LLM
     * @param array    $entityCatalog Catalog built from entity configs
     * @return string[] Validated labels (canonical casing)
     */
    private function validateEntityTypes(array $entityTypes, array $entityCatalog): array
    {
        // No catalog to validate against → pass through unchanged (back-compat).
        if (empty($entityCatalog)) {
            return $entityTypes;
        }

        // Map lowercase label => canonical label for case-insensitive matching.
        $allowed = [];
        foreach ($entityCatalog as $entry) {
            $label = is_array($entry) ? ($entry['label'] ?? null) : null;
            if (is_string($label) && $label !== '') {
                $allowed[strtolower($label)] = $label;
            }
        }

        $valid = [];
        $rejected = [];
        foreach ($entityTypes as $label) {
            $key = strtolower($label);
            if (isset($allowed[$key])) {
                $valid[] = $allowed[$key];
            } else {
                $rejected[] = $label;
            }
        }

        if (!empty($rejected)) {
            $this->logger->debug('query_understanding', 'Rejected entity_types not in catalog', [
                'rejected' => $rejected,
                'allowed_labels' => array_values($allowed),
            ]);
        }

        return array_values(array_unique($valid));
    }

    /**
     * Some entity labels are also everyday profile words. For own-profile lookup
     * requests, those labels should not be force-injected into retrieval unless
     * QU also says the request is a contact-record style operation.
     *
     * This deliberately uses structured QU fields instead of raw language regexes,
     * so French/English/etc. all follow the same path once QU classifies the turn.
     *
     * @param string[] $entityTypes
     * @param string[] $personNames
     * @return string[]
     */
    private function removeOwnProfileAmbiguousEntities(
        array $entityTypes,
        bool $isPossessive,
        string $intent,
        array $personNames
    ): array {
        $ambiguousLabels = $this->ownProfileAmbiguousEntityLabels();
        if (
            empty($ambiguousLabels)
            || !$isPossessive
            || !empty($personNames)
            || in_array($intent, ['list', 'count', 'aggregate', 'compare'], true)
        ) {
            return $entityTypes;
        }

        $ambiguous = array_fill_keys(array_map('strtolower', $ambiguousLabels), true);

        return array_values(array_filter(
            $entityTypes,
            static fn(string $label): bool => !isset($ambiguous[strtolower($label)])
        ));
    }

    /**
     * @return string[]
     */
    private function ownProfileAmbiguousEntityLabels(): array
    {
        $labels = $this->config['own_profile_ambiguous_entity_labels']
            ?? (function_exists('config') ? config('ai.query_understanding.own_profile_ambiguous_entity_labels', ['Contact']) : ['Contact']);

        if (!is_array($labels)) {
            return [];
        }

        $normalized = [];
        foreach ($labels as $label) {
            if (!is_string($label)) {
                continue;
            }

            $label = trim($label);
            if ($label !== '') {
                $normalized[] = $label;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Normalize intent to one of the known values.
     */
    private function normalizeIntent(mixed $intent): string
    {
        if (!is_string($intent)) {
            return 'other';
        }

        $valid = ['count', 'list', 'lookup', 'aggregate', 'compare', 'file_listing', 'page_explanation', 'other'];

        $normalized = strtolower(trim($intent));

        return in_array($normalized, $valid, true) ? $normalized : 'other';
    }

    /**
     * Normalize question type to one of the known values.
     */
    private function normalizeQuestionType(mixed $type): string
    {
        if (!is_string($type)) {
            return 'hybrid';
        }

        $valid = ['data_query', 'knowledge_query', 'hybrid'];
        $normalized = strtolower(trim($type));

        return in_array($normalized, $valid, true) ? $normalized : 'hybrid';
    }
}
