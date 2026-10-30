<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Contracts\PipelineObserverInterface;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Exceptions\LlmInvalidJsonException;
use Condoedge\Ai\Services\Discovery\EntityLabelIndex;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Support\AliasNormalizer;
use Condoedge\Ai\Support\DomainProfile;
use Condoedge\Ai\Support\GuardedPipelineObserver;
use Condoedge\Ai\Support\PipelineObserverScope;
use Condoedge\Ai\Support\TokenUsage;
use Condoedge\Ai\Support\UntrustedLabel;

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
 * references like "John Smith" -> Person entity, "balance" -> financial property,
 * "volunteers" -> PersonTeam with role filter.
 */
class QueryUnderstandingService
{
    /**
     * Real synonyms shown per entity in the catalog (after AliasNormalizer drops
     * the derivable spellings). Measured on the host config, 73 entities, 2026-10-06:
     * the previous "first 5 raw aliases" cost 13,559 chars / 2,910 tokens and showed
     * 115 of the 311 real synonyms; 12 real synonyms cost 12,998 chars / 2,784
     * tokens (-4.3%) and show 296. The cap only bounds an entity with a very long
     * synonym list — the catalog is sent on every question.
     */
    public const CATALOG_MAX_ALIASES = 12;

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
     * @param array  $conversationContext  The turn's conversation_context
     *        (ConversationContextManager::buildPromptContext shape). Rendered as a
     *        compact CONVERSATION block so follow_up_reference
     *        is judged against what the user was actually shown. Empty (default)
     *        = no block, zero added tokens.
     * @param PipelineObserverInterface|null $observer The turn's observer: receives the
     *        VERBATIM QU messages (promptBuilt) and each call's token usage, stage
     *        'understanding'. Observation only.
     * @return QueryUnderstandingResult Structured result with entity types, person names, intent, etc.
     */
    public function analyze(
        string $question,
        array $entityCatalog,
        array $conversationContext = [],
        ?PipelineObserverInterface $observer = null
    ): QueryUnderstandingResult {
        $this->logger->startTimer('query_understanding');
        $observer = $observer !== null ? GuardedPipelineObserver::wrap($observer) : PipelineObserverScope::current();

        try {
            $messages = $this->buildMessages($question, $entityCatalog, $conversationContext);

            $response = $this->requestJson($messages, $observer);

            $result = $this->normalizeResponse($response, $entityCatalog, $question);

            $ms = $this->logger->stopTimer('query_understanding');
            $this->logger->info('query_understanding', 'Analysis complete', [
                'duration_ms' => $ms,
                'entity_types' => $result->entityTypes,
                'person_names' => $result->personNames,
                'property_concepts' => $result->propertyConcepts,
                'intent' => $result->intent,
                'question_type' => $result->questionType,
                // Keys the reply did not write (their fields hold neutral defaults).
            ] + ($result->missingKeys !== [] ? ['missing_keys' => $result->missingKeys] : []));

            return $result;
        } catch (\Throwable $e) {
            $this->logger->stopTimer('query_understanding');
            $this->logger->error('query_understanding', 'Analysis failed, using empty result', [
                'error' => $e->getMessage(),
            ]);
            // Observation only: the recovered failure (e.g. a provider 429) is
            // otherwise invisible in the turn trace — the turn continues on an
            // empty understanding.
            $observer->stageError('understanding', $e);

            return QueryUnderstandingResult::failed();
        }
    }

    /**
     * The QU JSON call with ONE truncation retry (Task 1.17).
     *
     * A reply cut at the output budget is unterminated JSON, which used to
     * degrade the whole turn to an empty QU result. When the parse failure is a
     * truncation — the provider reported it (finish_reason=length /
     * stop_reason=max_tokens) or, unreported, the reply is long and not closed
     * — the tail is logged and the call is retried once with double
     * max_tokens. Any other failure (and a second truncation) propagates to
     * analyze()'s existing fail-safe (empty result).
     *
     * @return object|array
     */
    private function requestJson(array $messages, PipelineObserverInterface $observer): object|array
    {
        $maxTokens = $this->maxTokens();
        $temperature = $this->config['temperature'] ?? 0.0;
        // The reply's strict schema (a provider without structured outputs ignores it).
        $schema = $this->usesStrictSchema() ? ['json_schema' => $this->replySchema()] : [];

        $observer->promptBuilt('understanding', $messages);

        try {
            $response = $this->llm->chatJson($messages, ['max_tokens' => $maxTokens, 'temperature' => $temperature] + $schema);
            TokenUsage::report($observer, 'understanding', $this->llm);

            return $response;
        } catch (LlmInvalidJsonException $e) {
            // The truncated reply still consumed tokens.
            TokenUsage::report($observer, 'understanding', $this->llm);
            if (!$this->looksTruncated($e, $maxTokens)) {
                throw $e;
            }

            $this->logger->warning('query_understanding', 'QU reply truncated at max_tokens; retrying once with double budget', [
                'max_tokens' => $maxTokens,
                'retry_max_tokens' => $maxTokens * 2,
                'provider_reported' => $e->truncated,
                'reply_chars' => mb_strlen($e->rawText),
                'tail' => mb_substr($e->rawText, -200),
            ]);

            $observer->promptBuilt('understanding', $messages);
            try {
                return $this->llm->chatJson($messages, ['max_tokens' => $maxTokens * 2, 'temperature' => $temperature] + $schema);
            } finally {
                TokenUsage::report($observer, 'understanding', $this->llm);
            }
        }
    }

    /**
     * The QU output budget: an injected config value wins (unit tests / the
     * service-provider wiring), else ai.understanding.max_tokens (default 1200).
     */
    private function maxTokens(): int
    {
        $value = $this->config['max_tokens']
            ?? (function_exists('config') ? config('ai.understanding.max_tokens', 1200) : 1200);

        return max(1, (int) $value);
    }

    /** The values each enumerated key of the reply may hold (the prompt names the same ones). */
    private const INTENTS = ['count', 'list', 'lookup', 'aggregate', 'compare', 'file_listing', 'page_explanation', 'other'];
    private const QUESTION_TYPES = ['data_query', 'knowledge_query', 'hybrid'];
    private const NAVIGATION_TARGETS = ['own_profile', 'own_team'];
    /** The intents that ask for a SET of records (or a figure over one) — never for one record or a page. */
    private const RECORD_SET_INTENTS = ['list', 'count', 'aggregate', 'compare'];
    private const EXPORT_FORMATS = ['pdf', 'excel'];

    /**
     * Whether the reply is asked for under a strict schema: an injected config value
     * wins, else ai.understanding.strict_schema (default true). Off = the plain JSON call.
     */
    private function usesStrictSchema(): bool
    {
        $value = $this->config['strict_schema']
            ?? (function_exists('config') ? config('ai.understanding.strict_schema', true) : true);

        return (bool) $value;
    }

    /**
     * The reply as a STRICT JSON Schema (chatJson's optional `json_schema`).
     *
     * Eval p23-B lost two turns to ONE routing field of this reply that came back with
     * another value on unchanged instructions. Measured live (it9, gpt-4o-mini,
     * temperature 0, the stored prompts replayed): in 438 plain-JSON replies no key was
     * ever left out — the VALUE moved: the digest flag of "Give me an excel of everything
     * needing my attention" came back false on 32 of 164 replays over the 15 stored
     * conversations (26 of 35 on one of them). Under this schema: 0 of 143, with the
     * routing fields of 50 other questions unchanged (a regression set of both suites and
     * near-miss asks; 3 replies each). The schema also makes the reply's shape a
     * guarantee instead of a habit: every key the prompt documents is required, an
     * optional value is an explicit null, and an enumerated key can only hold a value the
     * prompt allows (38 of the 438 plain replies gave an intent outside its list).
     *
     * The ORDER is the prompt's, on purpose: with the routing keys moved first (prompt,
     * schema or both — measured on the same set) the model set them before it had read
     * the question into entities, and they came back true on single-topic questions
     * (digest_request on overdue tasks / invoices and balance lists, follow_up_reference
     * on two suite turns, own_team on a count of members).
     *
     * @return array{name: string, schema: array<string, mixed>}
     */
    private function replySchema(): array
    {
        $properties = $this->replyProperties();

        return [
            'name' => 'query_understanding',
            'schema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => array_keys($properties),
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * The keys of the reply and their JSON Schema, in the ORDER the prompt documents
     * them (a strict reply is written in this order; a test holds the two lists
     * together). "digest_request" only when the prompt asks for it.
     *
     * @return array<string, array<string, mixed>>
     */
    private function replyProperties(): array
    {
        $text = ['type' => 'string'];
        $list = ['type' => 'array', 'items' => $text];
        $oneOrNull = static fn (array $values): array => ['type' => ['string', 'null'], 'enum' => [...$values, null]];

        $properties = [
            'entity_types' => $list,
            'person_names' => $list,
            'property_concepts' => $list,
            'search_query' => $text,
            'subject_label' => $text,
            'possessive_words' => $list,
            'possessive_forms' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['subject', 'possessive', 'object']]],
            'possessive_scope' => $oneOrNull([QueryUnderstandingResult::SCOPE_PERSONAL, QueryUnderstandingResult::SCOPE_COLLECTIVE]),
            'intent' => ['type' => 'string', 'enum' => self::INTENTS],
            'question_type' => ['type' => 'string', 'enum' => self::QUESTION_TYPES],
            'clarified_question' => $text,
            'search_tags' => $list,
            'export_format' => $oneOrNull(self::EXPORT_FORMATS),
            'language' => $text,
            'navigation_target' => $oneOrNull(self::NAVIGATION_TARGETS),
            'follow_up_reference' => ['type' => 'boolean'],
        ];
        if ($this->hasEnabledAttentionSources()) {
            $properties['digest_request'] = ['type' => 'boolean'];
        }

        return $properties;
    }

    /**
     * Whether an invalid-JSON reply was cut at the budget. Provider-reported
     * truncation is authoritative; otherwise a reply that opens a JSON object
     * but does not close it AND is long relative to the budget (>= ~2 chars
     * per token — a reply cut at the cap is typically 3-4 chars/token) is
     * treated as truncated. Short malformed replies are not.
     */
    private function looksTruncated(LlmInvalidJsonException $e, int $maxTokens): bool
    {
        if ($e->truncated) {
            return true;
        }

        $text = trim($e->rawText);

        return $text !== ''
            && str_contains($text, '{')
            && !str_ends_with($text, '}')
            && !str_ends_with($text, '```')
            && strlen($text) >= $maxTokens * 2;
    }

    /**
     * Build a compact entity catalog from full entity configs.
     *
     * The catalog is designed to be token-efficient: only labels, the entity's
     * real synonyms, and short descriptions are included. Property groups are NOT
     * included (too many tokens); property concepts should be inferred by the LLM.
     *
     * Aliases that are only another spelling of the label or of an earlier alias
     * (plural, accent-less, snake_case, glued — AliasNormalizer) tell the model
     * nothing and are not shown: they used to fill the slots and hide the synonyms.
     *
     * @param array $entityConfigs Full entity configs keyed by FQCN
     * @return array Compact catalog: [ ['label' => 'Contact', 'aliases' => [...], 'description' => '...'], ... ]
     */
    public static function buildEntityCatalog(array $entityConfigs): array
    {
        $catalog = [];

        foreach ($entityConfigs as $fqcn => $config) {
            $metadata = $config['metadata'] ?? [];
            // Structural-only entity (`metadata.askable => false`): still mapped, joinable
            // and tenant-scoped, but never offered as the SUBJECT of a question — a label
            // QU would otherwise pick by name resemblance (eval p12–p16: the GL snapshot
            // "AccountBalance" for every "balance" question).
            if (($metadata['askable'] ?? true) === false) {
                continue;
            }
            $label = $config['graph']['label'] ?? class_basename($fqcn);
            $aliases = array_slice(
                AliasNormalizer::distinct((array) ($metadata['aliases'] ?? []), (string) $label),
                0,
                self::CATALOG_MAX_ALIASES
            );
            $description = $metadata['description'] ?? '';

            // Truncate description to keep prompt compact
            if (strlen($description) > 120) {
                $description = mb_strcut($description, 0, 117, 'UTF-8') . '...';
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
    private function buildMessages(string $question, array $entityCatalog, array $conversationContext = []): array
    {
        $catalogText = $this->formatCatalog($entityCatalog);
        // Appended AFTER the static prompt so the (large, identical) prefix stays
        // provider-cacheable; '' when there is no conversation (zero tokens).
        $conversationBlock = $this->conversationBlock($conversationContext, $question);
        // The document-search probes are NOT asked for here (eval p15): their two key docs
        // cost ~530 prompt tokens on every turn and this many-key prompt wrote them badly.
        // Retrieval\DocumentProbeWriter writes them in its own focused call, only when
        // document search runs for the turn.
        // Nor is the "is this question genuinely ambiguous?" judgment (eval p15): asked here
        // as three more keys (~264 prompt tokens on every turn) it came back true on 0 of
        // 148 turns. Understanding\ClarityChecker judges it in its own focused call, only
        // on a first message that names no subject.
        // "Is the user asking about THEIR OWN data?" is not asked as a judgment either: as
        // a bare "is_possessive" boolean it came back true for "Show me the balance." and
        // "Show me the overdue invoices." (2 of 2 live runs each), with a rewrite that
        // invented the owner. The model QUOTES the first-person words, tags the grammatical
        // form of each and states the scope; {@see self::verifiedOwnership()} derives the
        // boolean from the quotes the question really contains. The three key docs stand
        // where the boolean's doc stood and keep its example phrases: measured live, without
        // them unrelated keys shifted (a French "factures" question went from Invoice to Bill).
        $digestKeyDoc = $this->digestRequestInstruction();

        // The examples are the host's (ai.domain.understanding), used verbatim: see the note above.
        $implicitEntityExample = DomainProfile::text('understanding.implicit_entity_example');
        $propertyConceptExamples = DomainProfile::text('understanding.property_concept_examples');
        $possessiveExamples = DomainProfile::text('understanding.possessive_examples');
        $clarifiedQuestion = DomainProfile::text('understanding.clarified_example.question');
        $clarifiedAnswer = DomainProfile::text('understanding.clarified_example.clarified');
        $noOwnerQuestion = DomainProfile::text('understanding.no_owner_example.question');
        $noOwnerAnswer = DomainProfile::text('understanding.no_owner_example.clarified');
        $knowledgeQuestion = DomainProfile::text('understanding.knowledge_example.question');
        $knowledgeAnswer = DomainProfile::text('understanding.knowledge_example.clarified');
        // Starts with the file's own line break, like the line it replaces.
        $knowledgeExample = $knowledgeQuestion === '' || $knowledgeAnswer === '' ? '' : <<<LINE

But also it could have a base significance. let's say the user asks "{$knowledgeQuestion}" the clarified question might be "{$knowledgeAnswer}"
LINE;

        $system = <<<PROMPT
You are a query understanding assistant for a graph database system. Your job is to analyze a natural language question and extract structured information to help find the right data.

AVAILABLE ENTITY TYPES:
{$catalogText}

TASK: Given the user's question, return a JSON object with exactly these keys:
- "entity_types": array of entity label strings from the list above that are relevant to the question. Include entities that are explicitly mentioned AND entities that are implicitly needed (e.g., {$implicitEntityExample}).
- "person_names": array of person/contact name strings mentioned in the question (e.g., "John Smith", "Maria"). Empty array if no names mentioned.
- "property_concepts": array of SHORT single-word or two-word property concept strings (e.g., {$propertyConceptExamples}). Keep each concept as short as possible — one word preferred. These are abstract data concepts that may be stored as properties on entities, NOT entity types themselves.
- "search_query": a rewritten version of the question optimized for semantic search against entity descriptions. Remove filler words, expand abbreviations, use domain terminology. It is the SUBJECT of the request only — never include delivery or format words (export, download, excel, pdf, file, document-as-artifact): "can I get an excel with the team members' contact info" → "team members contact information".
- "subject_label": a short human title (2 to 6 words) of that same subject, written in the SAME language as the question, with the same exclusion of delivery/format words; used as a document title.
- "possessive_words": array of the words of the question, copied EXACTLY as written, that show the user is asking about THEIR OWN data or THEIR OWN GROUP's data (as in {$possessiveExamples}, etc.): each first-person possessive and each first-person pronoun, singular or plural, in any language. [] when the question has none.
- "possessive_forms": the grammatical form of each of those words, in the same order: "subject" (subject of a verb), "possessive", or "object" (object of a verb or of a preposition).
- "possessive_scope": "personal" when the "subject" and "possessive" words are first-person SINGULAR (the user alone), "collective" when they are first-person PLURAL (the user's group or organization), null when there is no "subject" or "possessive" word (none at all, or only "object" ones).
- "intent": one of: "count", "list", "lookup", "aggregate", "compare", "file_listing", "page_explanation", "other". Use "file_listing" when the user asks to see or list the documents/files available to them (e.g. "which files do I have access to?", "show my documents", "list the files I can see"). Never "file_listing" when the user asks to RECEIVE records or a result AS a file (that is the data request's own intent, with export_format set). Use "page_explanation" when the user asks what the current page/screen is or what they can do on it (e.g. "what is this page?", "what can I do here?", "explain this screen") — they want a description of the page itself, not a data lookup.
- "question_type": one of "data_query" (needs database/graph lookup, or works on figures or records of an earlier answer: a calculation, a comparison, a share), "knowledge_query" (needs file/document content — rules, regulations, policies, "can I do X?"), "hybrid" (needs both data and documents)
- "clarified_question": "Usually the concrete asking of the user hides a more abstract context and intent. If the question is "{$clarifiedQuestion}" the clarified_question might be "{$clarifiedAnswer}" This is meant to clarify the underlying data relationships and intent in a way that can help with debugging and prompt engineering. Keep it concise. Never add an owner the question does not state: "{$noOwnerQuestion}" names no owner (the one who receives the answer is not an owner), so it stays "{$noOwnerAnswer}" — the user or the user's group appears in the clarified question ONLY when "possessive_scope" is not null.{$knowledgeExample}
- "search_tags": array of 5 to 10 keyword tags for searching documents and files. Include: (1) literal terms from the question, (2) domain synonyms that would appear in the relevant documents, (3) the broader category or domain the question belongs to, (4) related technical terms, and (5) if the project's documents are multilingual, translations of the key terms into the other language(s). Think: what words would appear in a document, rule, or regulation that answers this question? Never use the name or title of a document, code, statute or corpus as a tag (even when the user names it): every passage of that document shares its name, so such a tag matches the title page instead of the passage that answers.
- "export_format": "pdf", "excel", or null. Set a format ONLY when the user is asking to receive the information as a downloadable document or spreadsheet file — an artifact they can save or open outside the chat — regardless of how they phrase that request. Judge the intent semantically, not by specific wording. Use "excel" for spreadsheet/workbook-style files and "pdf" for document-style files. Use null when the user just wants an answer in the chat, or merely mentions a file/document as the SUBJECT of the question rather than asking to receive one.
- "language": the two-letter ISO 639-1 code of the language the question is written in.
- "navigation_target": "own_profile", "own_team", or null. "own_profile" when the user asks to see, open, reach or get a link to THEIR OWN profile, account or personal settings page; "own_team" when they ask to see, open, reach or get a link to THEIR OWN team page (team settings, members, invitations). Judge the intent semantically in any language, not by specific wording. Asking WHERE or HOW to do something on that page (add or invite members, change settings) IS a navigation ask. Use null when the user asks to RECEIVE information, values or records (their balance, their email, the list of their team's members or roles, an export of them): the answer is the DATA, not a page — null even when the data concerns their own profile or team. Also null for other people's profiles or other pages.
- "follow_up_reference": boolean. True when the request operates on the result the user was JUST SHOWN in this conversation — the SAME records: delivering it again (in chat or as a downloadable file), reshaping it (sorting, narrowing, totalling, reformatting), or extending it with more fields about those same records. Such a request refers back to the previous output (it would be ambiguous or meaningless without it) — it stays a follow-up even when it ALSO asks for additional fields, because the records are still the ones shown. Judge semantically from the CONVERSATION SO FAR block at the end of this prompt — it is the authoritative record of what the user was just shown — not by specific words. When there is no such block this is the first turn and the value is false. False when the request asks about a different set of records or a new subject, and for self-contained questions, even inside a long conversation.{$digestKeyDoc}

Rules:
- Only use entity labels from the AVAILABLE ENTITY TYPES list.
- For person names, extract the actual name strings, not entity types.
- Property concepts should be abstract data concepts, not Neo4j property names.
- When the user asks for their own profile/contact details in any language (for example "my contact information", "my profile", "my email", "my name", "information about me"), treat that as authenticated user/team identity context. Do not return ambiguous profile/contact entity labels for those phrases unless the user clearly asks for contact records, a contacts list, another person's contact, or a domain contact profile record.
- Keep arrays empty rather than guessing if uncertain.
- Return ONLY valid JSON, no markdown fences or explanation.{$conversationBlock}
PROMPT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $question],
        ];
    }

    /** Last 3 exchanges = up to 6 user/assistant messages. */
    private const CONVERSATION_MAX_MESSAGES = 6;
    private const CONVERSATION_MAX_TEXT_CHARS = 200;
    private const CONVERSATION_MAX_COLUMNS = 15;
    private const CONVERSATION_MAX_ENTITY_TYPES = 8;
    private const CONVERSATION_MAX_FILES = 5;

    /**
     * Render the turn's conversation_context as a compact CONVERSATION block for
     * the QU prompt (Task 1.22 — live eval 2026-09-29: QU never saw the
     * conversation, so follow_up_reference was false on every turn and a French
     * pronoun export was sent to the clarify branch).
     *
     * Contents:
     *  - the last <= 3 exchanges: the user's question verbatim and the
     *    assistant's answer summary, each capped at 200 chars. These are
     *    USER-VISIBLE text and may contain values the user was shown (names,
     *    amounts); they are kept on purpose because they carry the anaphora
     *    referents ("la", "those", "it") QU must resolve.
     *  - the STRUCTURED result memory, which carries no values at all: subject,
     *    entity types, COLUMN NAMES of the sample (keys of its first row), row
     *    count and the names of the documents last cited. Row values from
     *    last_result_sample and the recorded cypher are never included.
     * Target < ~600 tokens.
     *
     * Returns '' when there is neither history nor a recorded result, so a
     * first turn pays zero tokens and sees the exact legacy prompt.
     */
    private function conversationBlock(array $context, string $question): string
    {
        if ($context === []) {
            return '';
        }

        $lines = [];

        $messages = $this->conversationMessages($context['recent_exchanges'] ?? [], $question);
        if ($messages !== []) {
            $lines[] = 'Recent exchanges (oldest first):';
            foreach ($messages as [$role, $text]) {
                $lines[] = "- {$role}: {$text}";
            }
        }

        $memory = $this->resultMemoryLines($context);
        if ($memory !== []) {
            $lines[] = 'Most recent recorded result (what follow-up requests would operate on):';
            array_push($lines, ...$memory);
        }

        if ($lines === []) {
            return '';
        }

        return "\n\nCONVERSATION SO FAR (authoritative context for \"follow_up_reference\"; "
            . "the user's CURRENT question is the next message and is not part of it):\n"
            . implode("\n", $lines)
            // Closing reminder: the block ends the prompt with conversational
            // text, so restate the output contract after it.
            . "\n\nReply with ONLY the JSON object described above — no prose, no markdown.";
    }

    /**
     * Flatten recent_exchanges ([role, question|answer_summary] per message) into
     * the last CONVERSATION_MAX_MESSAGES [label, text] pairs. The current question
     * is dropped when it is already the trailing user message (regenerate path:
     * the user message was persisted before the snapshot was built).
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function conversationMessages(mixed $exchanges, string $question): array
    {
        if (!is_array($exchanges)) {
            return [];
        }

        $messages = [];
        foreach ($exchanges as $exchange) {
            if (!is_array($exchange)) {
                continue;
            }
            $userText = is_string($exchange['question'] ?? null) ? trim($exchange['question']) : '';
            $answerText = is_string($exchange['answer_summary'] ?? null) ? trim($exchange['answer_summary']) : '';

            if ($userText !== '') {
                $messages[] = ['User', $userText];
            }
            if ($answerText !== '') {
                $messages[] = ['Assistant (summary)', $answerText];
            }
        }

        $last = end($messages);
        if ($last !== false && $last[0] === 'User' && $last[1] === trim($question)) {
            array_pop($messages);
        }

        return array_map(
            fn(array $m): array => [$m[0], $this->compactText($m[1], self::CONVERSATION_MAX_TEXT_CHARS)],
            array_slice($messages, -self::CONVERSATION_MAX_MESSAGES)
        );
    }

    /**
     * The result-memory lines (subject, entity types, columns, row count, cited
     * documents). Structure only — never row values.
     *
     * @return string[]
     */
    private function resultMemoryLines(array $context): array
    {
        $lines = [];

        $subject = is_string($context['last_subject'] ?? null) ? trim($context['last_subject']) : '';
        if ($subject !== '') {
            $lines[] = '- subject: ' . $this->compactText($subject, self::CONVERSATION_MAX_TEXT_CHARS);
        }

        $entityTypes = array_slice($this->ensureStringArray($context['last_entity_types'] ?? []), 0, self::CONVERSATION_MAX_ENTITY_TYPES);
        if ($entityTypes !== []) {
            $lines[] = '- entity types: ' . implode(', ', $entityTypes);
        }

        $sample = $context['last_result_sample'] ?? [];
        $firstRow = is_array($sample) && $sample !== [] ? reset($sample) : null;
        if (is_object($firstRow)) {
            $firstRow = (array) $firstRow;
        }
        $columns = is_array($firstRow)
            ? array_values(array_filter(array_keys($firstRow), static fn($k): bool => is_string($k) && $k !== ''))
            : [];
        if ($columns !== []) {
            $shown = array_slice($columns, 0, self::CONVERSATION_MAX_COLUMNS);
            $more = count($columns) - count($shown);
            $lines[] = '- columns: ' . implode(', ', $shown) . ($more > 0 ? " (+{$more} more)" : '');
        }

        $count = is_numeric($context['last_result_count'] ?? null) ? (int) $context['last_result_count'] : 0;
        if ($count > 0) {
            $lines[] = "- rows: {$count}";
        } elseif (is_array($sample) && $sample !== []) {
            $lines[] = '- rows: ' . count($sample) . ' (sample)';
        }

        $files = [];
        foreach ((array) ($context['last_referenced_files'] ?? []) as $file) {
            $name = is_array($file) ? ($file['name'] ?? $file['filename'] ?? $file['title'] ?? null) : null;
            // A file is named by whoever uploaded it: the shared sanitiser (one line,
            // no markup, capped) — this block is read as authoritative context.
            $name = is_string($name) ? UntrustedLabel::of($name, 80) : '';
            if ($name !== '') {
                $files[] = $name;
            }
        }
        $files = array_slice(array_values(array_unique($files)), 0, self::CONVERSATION_MAX_FILES);
        if ($files !== []) {
            $lines[] = '- documents cited: ' . implode(', ', $files);
        }

        return $lines;
    }

    /**
     * Single-line, multibyte-safe truncation for prompt text.
     */
    private function compactText(string $text, int $maxChars): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $maxChars
            ? rtrim(mb_substr($text, 0, $maxChars - 1)) . '…'
            : $text;
    }

    /**
     * The extra prompt key asking the model to judge whether the turn is a
     * BROAD "what needs my attention" ask (attention digest). Emitted ONLY when
     * the host configured at least one enabled ai.attention_sources entry —
     * hosts without the feature pay zero tokens and see zero behavior change
     * (a conditional prompt line). The concept
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
            . 'urgency-flavored, and false for follow-ups that operate on a previous answer. '
            . 'A broad what-needs-my-attention ask is a NEW digest request, not a follow-up, '
            . 'even when the previous result in the conversation was about a single topic or '
            . 'record and even when the ask also requests a file: set "digest_request" true and '
            . '"follow_up_reference" false — a single-topic previous result cannot hold '
            . 'everything that needs the user\'s attention.';
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
     * @param string       $question      The user's question, verbatim: what the reply's quotes are checked against
     */
    private function normalizeResponse(object|array $response, array $entityCatalog = [], string $question = ''): QueryUnderstandingResult
    {
        $data = is_object($response) ? (array) $response : $response;
        $personNames = $this->ensureStringArray($data['person_names'] ?? []);
        $propertyConcepts = $this->ensureStringArray($data['property_concepts'] ?? []);
        $ownership = $this->verifiedOwnership($data, $question);
        $isPossessive = $ownership['words'] !== [];
        $intent = $this->normalizeIntent($data['intent'] ?? 'other');

        $entityTypes = $this->validateEntityTypes(
            $this->ensureStringArray($data['entity_types'] ?? []),
            $entityCatalog
        );
        $entityTypes = $this->removeOwnProfileAmbiguousEntities($entityTypes, $isPossessive, $intent, $personNames);

        $followUpReference = (bool) ($data['follow_up_reference'] ?? false);
        $digestRequest = (bool) ($data['digest_request'] ?? false);

        // DIGEST ⟹ NOT A FOLLOW-UP (Task 1.28 I2): a broad attention ask never
        // operates on the previous single-topic result. Normalized here, not only
        // in AiManager's precedence, because when every digest source fails the
        // turn falls through to the normal pipeline — where a surviving
        // followUpReference re-exported the previous result. Only when the digest
        // feature is live: without attention sources the flag is inert and the
        // follow-up judgment must stand.
        if ($digestRequest && $followUpReference && $this->hasEnabledAttentionSources()) {
            $followUpReference = false;
        }

        // The clarify fields of the result are NOT read from this reply (the prompt no
        // longer asks for them): they stay at their defaults until the focused
        // ClarityChecker call fills them through QueryUnderstandingResult::withClarification(),
        // which carries the guard this parse used to enforce — a follow-up on a previous
        // answer or a broad digest ask can never come out needing clarification.

        // NAVIGATION GUARD (eval p13): QU set navigation_target on nearly every team
        // question — rosters, exports, "show me the balance" — and the empty-result path
        // then answered "view your balance on My profile". A DATA question that names
        // attributes to return asks for VALUES, never for a page: structural signals only
        // (question_type + property_concepts). Real navigation asks ("where can I add
        // members?", "link to my profile") are knowledge asks or name no attribute.
        //
        // TWO signals, either one enough (it9): the guard hung on property_concepts alone,
        // and the model's own target for a roster ("Who are the members of my team and
        // what are their roles?") is own_team on every reply (20 of 20 measured) — on the
        // one reply whose property_concepts came back empty the target survived. A data
        // question that asks for a SET of records (intent list / count / aggregate /
        // compare) asks for those records, whatever it names.
        $questionType = $this->normalizeQuestionType($data['question_type'] ?? 'hybrid');
        $navigationTarget = $this->parseNavigationTarget($data['navigation_target'] ?? null);
        if ($navigationTarget !== null && $questionType === 'data_query'
            && ($propertyConcepts !== [] || in_array($intent, self::RECORD_SET_INTENTS, true))) {
            $navigationTarget = null;
        }

        // REWRITE GUARD: measured live, whenever the model named an owner the question
        // does not state (the "me" of "Show me the overdue invoices." listed with scope
        // "personal"), its rewrite carried that owner too ("…overdue for the user?") — and
        // the rewrite is shown to the query generator as the clarified intent. A rewrite
        // written under an ownership that verification rejects is not passed on: every
        // reader falls back to the question itself / the search query.
        $clarifiedQuestion = is_string($data['clarified_question'] ?? null) ? $data['clarified_question'] : '';
        if ($ownership['unverified_claim']) {
            $clarifiedQuestion = '';
        }

        return new QueryUnderstandingResult(
            entityTypes: $entityTypes,
            personNames: $personNames,
            propertyConcepts: $propertyConcepts,
            searchQuery: is_string($data['search_query'] ?? null) ? $data['search_query'] : '',
            isPossessive: $isPossessive,
            intent: $intent,
            questionType: $questionType,
            clarifiedQuestion: $clarifiedQuestion,
            searchTags: array_slice($this->ensureStringArray($data['search_tags'] ?? []), 0, 10),
            exportFormat: $this->parseExportFormat($data['export_format'] ?? null),
            language: $this->parseLanguage($data['language'] ?? null),
            followUpReference: $followUpReference,
            digestRequest: $digestRequest,
            navigationTarget: $navigationTarget,
            subjectLabel: $this->parseSubjectLabel($data['subject_label'] ?? null),
            possessiveWords: $ownership['words'],
            possessiveScope: $ownership['scope'],
            // The keys asked for that the reply did not write (never with a strict
            // schema): their fields above hold defaults, and the trace says so.
            missingKeys: array_values(array_diff(array_keys($this->replyProperties()), array_keys($data))),
        );
    }

    /** Grammatical forms of a first-person word that state an owner ("object" only receives the answer). */
    private const OWNER_FORMS = ['subject', 'possessive'];

    /**
     * Scripts written without spaces between words: a quote in one of them cannot be
     * matched word by word, so it is matched as a run of characters of the question.
     */
    private const UNSPACED_SCRIPTS = '/^[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}]+$/u';

    /**
     * Whose data the question asks about — the model quotes, code verifies.
     *
     * The reply lists the first-person words of the question ("possessive_words"), the
     * grammatical form of each ("possessive_forms", same order) and the scope
     * ("possessive_scope"). A word states an owner only when BOTH hold:
     *   - its form is "subject" or "possessive" — "object" is the person the answer is
     *     shown or given to ("show me…"), and a missing or unknown form is no statement;
     *   - it literally occurs in the question ({@see self::quotesTheQuestion()}): a word
     *     the question does not contain is the model's invention.
     * The scope is kept only next to a verified owner word. No word list: which words are
     * first-person, in which language, is the model's reading; that they are THERE is checked.
     *
     * @param array<string, mixed> $data the decoded reply
     * @return array{words: list<string>, scope: ?string, unverified_claim: bool}
     *         words: the verified owner words, as quoted (empty = not possessive);
     *         scope: 'personal' | 'collective' | null (unknown, or no verified word);
     *         unverified_claim: the reply named an owner (an owner-form word or a scope)
     *         and none of it could be verified.
     */
    private function verifiedOwnership(array $data, string $question): array
    {
        $words = $data['possessive_words'] ?? null;
        $forms = $data['possessive_forms'] ?? null;
        $words = is_array($words) && array_is_list($words) ? $words : [];
        $forms = is_array($forms) && array_is_list($forms) ? $forms : [];

        $questionWords = $this->wordsOf($question);
        $verified = [];
        $rejected = [];
        $claimed = false;
        foreach ($words as $i => $word) {
            $form = is_string($forms[$i] ?? null) ? strtolower(trim($forms[$i])) : '';
            if (!is_string($word) || !in_array($form, self::OWNER_FORMS, true)) {
                continue;
            }
            $claimed = true;
            if ($this->quotesTheQuestion($word, $questionWords)) {
                $verified[] = trim($word);
            } else {
                $rejected[] = $word;
            }
        }
        $verified = array_values(array_unique($verified));

        $scope = is_string($data['possessive_scope'] ?? null) ? strtolower(trim($data['possessive_scope'])) : '';
        $scope = in_array($scope, [QueryUnderstandingResult::SCOPE_PERSONAL, QueryUnderstandingResult::SCOPE_COLLECTIVE], true)
            ? $scope
            : null;
        $claimed = $claimed || $scope !== null;

        if ($rejected !== []) {
            $this->logger->debug('query_understanding', 'Ignored possessive words the question does not contain', [
                'ignored' => $rejected,
                'kept' => $verified,
            ]);
        }

        return [
            'words' => $verified,
            'scope' => $verified !== [] ? $scope : null,
            'unverified_claim' => $verified === [] && $claimed,
        ];
    }

    /**
     * Whether a quote is made of consecutive whole words of the question.
     *
     * Both are read as their runs of letters and digits ({@see self::wordsOf()}), so
     * case, accents and punctuation do not matter and every other character separates
     * words: an elided French pronoun matches with or without its apostrophe and its
     * verb ("j'ai", "j'", "j" in "est-ce que j'ai…"), the pronoun of a hyphenated
     * inversion matches alone ("nous" in "devons-nous"), and a word is never found
     * INSIDE another ("I" in "invoices", "me" in "members"). A quote of several words
     * must be consecutive words of the question. In a script written without spaces the
     * quote is matched as a run of characters instead.
     *
     * @param list<string> $questionWords the question's words ({@see self::wordsOf()})
     */
    private function quotesTheQuestion(string $quote, array $questionWords): bool
    {
        $quoteWords = $this->wordsOf($quote);
        $length = count($quoteWords);
        if ($length === 0) {
            return false;
        }

        for ($start = 0, $last = count($questionWords) - $length; $start <= $last; $start++) {
            if (array_slice($questionWords, $start, $length) === $quoteWords) {
                return true;
            }
        }

        $run = implode('', $quoteWords);

        return $length === 1
            && preg_match(self::UNSPACED_SCRIPTS, $run) === 1
            && str_contains(implode(' ', $questionWords), $run);
    }

    /**
     * The words of a text for quote verification: lower-cased, accents removed
     * (precomposed or combining), split at everything that is not a letter or a digit —
     * spaces, punctuation, apostrophes (straight or typographic) and hyphens alike.
     * Nothing else is normalized: no plural, no stemming.
     *
     * @return list<string>
     */
    private function wordsOf(string $text): array
    {
        $folded = (string) preg_replace('/\p{Mn}+/u', '', AliasNormalizer::fold($text));

        return preg_match_all('/[\p{L}\p{N}]+/u', $folded, $matches) > 0 ? $matches[0] : [];
    }

    /** Longest subject_label kept (a title, not prose); structural bound only. */
    private const SUBJECT_LABEL_MAX_CHARS = 80;

    /**
     * Parse the LLM's subject_label: whitespace collapsed and trimmed, bounded to
     * SUBJECT_LABEL_MAX_CHARS; a non-string or blank value is '' (callers fall back
     * to search_query).
     */
    private function parseSubjectLabel(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $label = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if (mb_strlen($label, 'UTF-8') > self::SUBJECT_LABEL_MAX_CHARS) {
            $label = rtrim(mb_substr($label, 0, self::SUBJECT_LABEL_MAX_CHARS, 'UTF-8'));
        }

        return $label;
    }

    /**
     * Parse the LLM's navigation_target into 'own_profile' | 'own_team' | null (anything
     * else — a malformed or unknown value — is null: nothing is attached).
     */
    private function parseNavigationTarget(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));

        return in_array($value, self::NAVIGATION_TARGETS, true) ? $value : null;
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

        // Format-code aliases (the model sometimes answers with the file
        // extension instead of the enum value) — not a semantic word list.
        if (in_array($value, ['xlsx', 'xls'], true)) {
            $value = 'excel';
        }

        return in_array($value, self::EXPORT_FORMATS, true) ? $value : null;
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
     * A type that is another SPELLING of a catalog label ("visit_details", "Contacts"),
     * or any spelling of a synonym exactly one entity declares ("fobs" → Access), is
     * resolved to that label instead of being dropped (AliasNormalizer — structural,
     * so the config does not have to list every spelling). A synonym several
     * entities share stays ambiguous and is dropped.
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

        // Map lowercase label => canonical label for case-insensitive matching,
        // and spelling key => canonical label for the label's other spellings.
        $allowed = [];
        $spellings = [];
        foreach ($entityCatalog as $entry) {
            $label = is_array($entry) ? ($entry['label'] ?? null) : null;
            if (is_string($label) && $label !== '') {
                $allowed[strtolower($label)] = $label;
                $spellings[AliasNormalizer::key($label)] ??= $label;
            }
        }

        $valid = [];
        $rejected = [];
        foreach ($entityTypes as $label) {
            $canonical = $allowed[strtolower($label)]
                ?? $spellings[AliasNormalizer::key($label)]
                ?? $allowed[strtolower((string) EntityLabelIndex::shared()->labelForTerm($label))]
                ?? null;

            if ($canonical !== null) {
                $valid[] = $canonical;
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
            || in_array($intent, self::RECORD_SET_INTENTS, true)
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
        $labels = array_filter(array_map('trim', DomainProfile::list('ambiguous_profile_labels')), static fn (string $label) => $label !== '');

        return array_values(array_unique($labels));
    }

    /**
     * Normalize intent to one of the known values.
     */
    private function normalizeIntent(mixed $intent): string
    {
        if (!is_string($intent)) {
            return 'other';
        }

        $normalized = strtolower(trim($intent));

        return in_array($normalized, self::INTENTS, true) ? $normalized : 'other';
    }

    /**
     * Normalize question type to one of the known values.
     */
    private function normalizeQuestionType(mixed $type): string
    {
        if (!is_string($type)) {
            return 'hybrid';
        }

        $normalized = strtolower(trim($type));

        return in_array($normalized, self::QUESTION_TYPES, true) ? $normalized : 'hybrid';
    }
}
