<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Discovery\EntityLabelIndex;

/**
 * PII masker for everything the response LLM sees (owner decision, Phase 8 batch A).
 *
 * ENFORCES config('ai.access_control.identifying_fields') — documented as the PII
 * list kept away from the third-party LLM — plus any entity-config
 * `security.sensible_columns` of the labels in the turn:
 *
 *   - values under a listed column (matched by NAME SEGMENT: `email` matches
 *     `email`, `member_email`, `contactEmail`, `emails`; never a substring such as
 *     `telephone_count` for `phone`) are replaced by stable per-turn tokens
 *     (`[email#1]`, `[phone#1]` — same value, same token, case-insensitive);
 *   - when `email` is listed, an email-FORMATTED value under any other column is
 *     masked too (a format check, so an LLM-chosen alias like `contact` cannot
 *     smuggle an address through);
 *   - {@see unmask()} substitutes the real values back into LLM output, so the
 *     user sees real data while the prompt never contained it.
 *
 * The per-turn instance rides the PipelineContext ({@see for()}) so every prompt
 * section of one turn shares ONE token map; ResponseGenerator resets it per turn.
 * Kill switch: config('ai.access_control.mask_identifying_fields') = false.
 */
final class PiiMasker
{
    /** Values shorter than this are never text-substituted (too collision-prone). */
    private const MIN_TEXT_REPLACE_LENGTH = 4;

    /** Structural email-address format (not a vocabulary). */
    private const EMAIL_FORMAT = '/^[^\s@<>()\[\],;:"]+@[^\s@<>()\[\],;:"]+\.[A-Za-z]{2,}$/';

    /** @var array<string, list<string>> field name => its lowercased name segments */
    private array $fields = [];

    /** @var array<string, string> token => real value */
    private array $tokenToValue = [];

    /** @var array<string, string> lowercased real value => token */
    private array $valueToToken = [];

    /** @var array<string, int> kind => last issued index */
    private array $counters = [];

    /**
     * @param array<int, string> $fields Identifying field names (any case/format).
     */
    public function __construct(array $fields, private readonly bool $enabled = true)
    {
        $this->addFields($fields);
    }

    /** @param array<int, mixed> $fields */
    private function addFields(array $fields): void
    {
        foreach ($fields as $field) {
            if (!is_string($field) || trim($field) === '') {
                continue;
            }
            $segments = self::segments($field);
            if ($segments !== []) {
                $this->fields[implode('_', $segments)] = $segments;
            }
        }
    }

    /**
     * Widen the identifying fields with more entity labels (the per-turn map
     * created before QU learns the turn's own labels once the query exists).
     *
     * @param array<int, string> $labels
     */
    public function addLabels(array $labels): self
    {
        $this->addFields(self::fieldsFor($labels));

        return $this;
    }

    /**
     * Build from config for the given entity labels: the `*` identifying fields,
     * each label's own identifying fields, and each label's entity-config
     * security.sensible_columns.
     *
     * @param array<int, string> $labels
     */
    public static function fromConfig(array $labels = []): self
    {
        return new self(self::fieldsFor($labels), (bool) self::config('ai.access_control.mask_identifying_fields', true));
    }

    /**
     * The turn's masker built from the CONVERSATION, before QU runs (P-1): the
     * previous turn's labels (last_entity_types / focus / mentioned) select the
     * identifying + sensible fields, and the previous sample is masked once so its
     * values hold tokens for every later maskText()/maskConversation() call.
     */
    public static function forConversation(array $conversation): self
    {
        $masker = self::fromConfig(self::conversationLabels($conversation));
        if (is_array($conversation['last_result_sample'] ?? null)) {
            $masker->maskRows($conversation['last_result_sample']);
        }

        return $masker;
    }

    /**
     * A copy of the conversation context safe for an LLM prompt: the result sample
     * masked by column, the previous answers/questions masked as free text (known
     * values + email-formatted strings). Structure is untouched; a conversation
     * without identifying values comes back identical.
     */
    public function maskConversation(array $conversation): array
    {
        if (!$this->enabled()) {
            return $conversation;
        }

        if (is_array($conversation['last_result_sample'] ?? null)) {
            $conversation['last_result_sample'] = $this->maskRows($conversation['last_result_sample']);
        }
        foreach (['last_answer_summary'] as $key) {
            if (is_string($conversation[$key] ?? null)) {
                $conversation[$key] = $this->maskFreeText($conversation[$key]);
            }
        }
        if (is_array($conversation['recent_exchanges'] ?? null)) {
            foreach ($conversation['recent_exchanges'] as $i => $exchange) {
                if (!is_array($exchange)) {
                    continue;
                }
                foreach (['question', 'answer_summary'] as $key) {
                    if (is_string($exchange[$key] ?? null)) {
                        $exchange[$key] = $this->maskFreeText($exchange[$key]);
                    }
                }
                $conversation['recent_exchanges'][$i] = $exchange;
            }
        }

        return $conversation;
    }

    /**
     * Free text (a previous answer, a question): every value tokenized this turn,
     * plus — when `email` is identifying — any email-formatted substring.
     */
    public function maskFreeText(string $text): string
    {
        if (!$this->enabled() || $text === '') {
            return $text;
        }

        if (isset($this->fields['email'])) {
            $text = (string) preg_replace_callback(
                '/(?<![\w.+-])[A-Za-z0-9._%+\'-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}(?![\w-])/u',
                fn (array $m) => $this->token('email', $m[0]),
                $text
            );
        }

        return $this->maskText($text);
    }

    /**
     * Rebind every turn token in generated cypher as a QUERY PARAMETER holding the
     * real value (P-1: "only the ones with that email" must still work). The
     * literal is NEVER inlined: a quoted literal that is exactly a token becomes
     * `$pii_…`; a token inside a longer literal becomes a concatenation; a bare
     * token becomes the parameter. Parameter names are a keyed hash of the value
     * (stable across turns, no collisions, reveal nothing), so a follow-up that
     * EXTENDS the previous query keeps resolving its `$pii_…` from $previous.
     *
     * @param array<string, string> $previous last turn's pii parameters (snapshot)
     * @return array{0: string, 1: array<string, string>} [cypher, parameters]
     */
    public function bindCypher(string $cypher, array $previous = []): array
    {
        $params = [];
        $tokenPattern = '\[[a-z0-9_]+#\d+\]';

        if ($this->tokenToValue !== [] && preg_match('/' . $tokenPattern . '/', $cypher) === 1) {
            // ONE left-to-right scan over the lexical spans of the query, so literal
            // pairing can never be shifted (review #2): comments and backtick
            // identifiers are skipped untouched, string literals are rebound piecewise,
            // and only a token OUTSIDE every span is a bare token.
            $span = '(?P<comment>\/\/[^\n]*|\/\*.*?\*\/)'
                . '|(?P<tick>`[^`]*`)'
                . "|(?P<sq>'(?:[^'\\\\]|\\\\.)*')"
                . '|(?P<dq>"(?:[^"\\\\]|\\\\.)*")'
                . '|(?P<token>' . $tokenPattern . ')';
            $replaced = preg_replace_callback(
                '/' . $span . '/s',
                function (array $m) use (&$params, $tokenPattern): string {
                    if (($m['token'] ?? '') !== '') {
                        $name = $this->paramFor($m['token'], $params);

                        return $name !== null ? '$' . $name : $m[0];
                    }
                    if (($m['sq'] ?? '') === '' && ($m['dq'] ?? '') === '') {
                        return $m[0]; // comment or backtick identifier
                    }

                    return $this->bindLiteral($m[0], $tokenPattern, $params);
                },
                $cypher
            );
            $cypher = is_string($replaced) ? $replaced : $cypher;
        }

        // `$pii_…` the cypher references but this pass did not bind: an extended
        // previous-turn query (snapshot parameters) or an already-bound draft
        // (idempotent — this turn's own values).
        if (preg_match_all('/\$(pii_[a-f0-9]{12})\b/', $cypher, $refs)) {
            $own = [];
            foreach ($this->tokenToValue as $value) {
                $own[$this->paramName($value)] = $value;
            }
            foreach (array_unique($refs[1]) as $name) {
                if (isset($params[$name])) {
                    continue;
                }
                if (is_string($previous[$name] ?? null)) {
                    $params[$name] = $previous[$name];
                } elseif (isset($own[$name])) {
                    $params[$name] = $own[$name];
                }
            }
        }

        return [$cypher, $params];
    }

    /**
     * One quoted literal → concatenation of literal pieces and `$pii_…` parameters.
     * A piece before a token that ends in an ODD run of backslashes would turn the
     * closing quote we add into an escaped quote (review #1: the string would swallow
     * the rest of the query, including its scoping clause) — such a literal is left
     * untouched.
     *
     * @param array<string, string> $params
     */
    private function bindLiteral(string $literal, string $tokenPattern, array &$params): string
    {
        $quote = $literal[0];
        $body = substr($literal, 1, -1);
        if (preg_match('/' . $tokenPattern . '/', $body) !== 1) {
            return $literal;
        }

        $parts = preg_split('/(' . $tokenPattern . ')/', $body, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $local = [];
        $pieces = [];
        foreach ($parts as $part) {
            if (isset($this->tokenToValue[$part])) {
                $pieces[] = '$' . $this->paramFor($part, $local);
                continue;
            }
            if (preg_match('/(\\\\+)$/', $part, $run) === 1 && strlen($run[1]) % 2 === 1) {
                return $literal;
            }
            $pieces[] = $quote . $part . $quote;
        }

        $params += $local;

        return implode(' + ', $pieces);
    }

    /** @param array<string, string> $params */
    private function paramFor(string $maybeToken, array &$params): ?string
    {
        $value = $this->tokenToValue[$maybeToken] ?? null;
        if ($value === null) {
            return null;
        }
        $name = $this->paramName($value);
        $params[$name] = $value;

        return $name;
    }

    /** Keyed hash of the value: stable across turns, collision-free, reveals nothing. */
    private function paramName(string $value): string
    {
        return 'pii_' . substr(hash_hmac('sha256', mb_strtolower($value), (string) self::config('app.key', 'kompo-ai')), 0, 12);
    }

    /** @return array<int, string> */
    private static function conversationLabels(array $conversation): array
    {
        $labels = [];
        foreach (['last_entity_types', 'mentioned_entities'] as $key) {
            foreach ((array) ($conversation[$key] ?? []) as $label) {
                if (is_string($label) && $label !== '') {
                    $labels[] = $label;
                }
            }
        }
        if (is_string($conversation['focused_entity'] ?? null) && $conversation['focused_entity'] !== '') {
            $labels[] = $conversation['focused_entity'];
        }

        return array_values(array_unique($labels));
    }

    /**
     * Identifying field names for the labels: `*` + each label's own list + each
     * label's entity-config security.sensible_columns.
     *
     * @param array<int, string> $labels
     * @return array<int, string>
     */
    private static function fieldsFor(array $labels): array
    {
        $identifying = self::config('ai.access_control.identifying_fields', []);
        $identifying = is_array($identifying) ? $identifying : [];

        $fields = is_array($identifying['*'] ?? null) ? $identifying['*'] : [];
        foreach (array_unique(array_filter($labels, 'is_string')) as $label) {
            if (is_array($identifying[$label] ?? null)) {
                $fields = array_merge($fields, $identifying[$label]);
            }

            try {
                $sensible = EntityLabelIndex::shared()->configForLabel($label)['security']['sensible_columns'] ?? [];
            } catch (\Throwable $e) {
                $sensible = [];
            }
            if (is_array($sensible)) {
                $fields = array_merge($fields, $sensible);
            }
        }

        return array_values(array_filter($fields, 'is_string'));
    }

    /**
     * The turn's shared masker, memoized on the context. Created early (before QU,
     * by ContextRetriever) from the conversation; every later call widens it with
     * the turn's own structure: QU entity types + the node labels of the query.
     */
    public static function for(PipelineContext $context): self
    {
        $labels = self::turnLabels($context);

        if ($context->piiMasker instanceof self) {
            return $context->piiMasker->addLabels($labels);
        }

        $masker = self::forConversation($context->conversationContext);

        return $context->piiMasker = $masker->addLabels($labels);
    }

    /** @return array<int, string> */
    private static function turnLabels(PipelineContext $context): array
    {
        $labels = $context->qu->entityTypes;
        $cypher = (string) ($context->queryResult->cypher ?? '');
        if ($cypher !== '' && preg_match_all('/\(\s*[A-Za-z_][A-Za-z0-9_]*\s*((?::\s*`?[A-Za-z_][A-Za-z0-9_]*`?)+)/', $cypher, $m)) {
            foreach ($m[1] as $group) {
                foreach (preg_split('/\s*:\s*/', trim($group, " :")) ?: [] as $label) {
                    $label = trim($label, '` ');
                    if ($label !== '') {
                        $labels[] = $label;
                    }
                }
            }
        }

        return $labels;
    }

    public function enabled(): bool
    {
        return $this->enabled && $this->fields !== [];
    }

    /**
     * The configured field a column name matches (by name segments), else null.
     */
    public function maskedKind(string $column): ?string
    {
        if (!$this->enabled()) {
            return null;
        }

        $segments = self::segments($column);
        if ($segments === []) {
            return null;
        }

        foreach ($this->fields as $kind => $fieldSegments) {
            if (self::endsWithSequence($segments, $fieldSegments)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Mask every identifying value in the rows (recursively through nested
     * maps/lists). Non-identifying values are returned untouched.
     *
     * @param array<int|string, mixed> $rows
     * @return array<int|string, mixed>
     */
    public function maskRows(array $rows): array
    {
        if (!$this->enabled()) {
            return $rows;
        }

        return $this->maskNode($rows, null);
    }

    /**
     * Token for a value of a known field — the value unchanged when the field is
     * not an identifying one (or masking is off).
     */
    public function maskField(string $field, mixed $value): mixed
    {
        $kind = $this->maskedKind($field);
        if ($kind === null || !is_scalar($value) || trim((string) $value) === '') {
            return $value;
        }

        return $this->token($kind, (string) $value);
    }

    /** The token already issued for a real value this turn, else null. */
    public function tokenForValue(string $value): ?string
    {
        return $this->valueToToken[mb_strtolower(trim($value))] ?? null;
    }

    /**
     * Replace every real value issued a token this turn (case-insensitive) with
     * its token — the safety net for prompt text built outside maskRows().
     */
    public function maskText(string $text): string
    {
        if ($this->tokenToValue === [] || $text === '') {
            return $text;
        }

        // Longest first, so a value never partially replaces a longer one.
        $values = array_values($this->tokenToValue);
        usort($values, static fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($values as $value) {
            if (mb_strlen($value) < self::MIN_TEXT_REPLACE_LENGTH) {
                continue;
            }
            $token = $this->valueToToken[mb_strtolower($value)];
            // Whole values only (P-2): never inside a longer word/number.
            $replaced = preg_replace(
                '/(?<![\p{L}\p{N}_])' . preg_quote($value, '/') . '(?![\p{L}\p{N}_])/iu',
                $token,
                $text
            );
            $text = is_string($replaced) ? $replaced : $text;
        }

        return $text;
    }

    /** Substitute the real values back for every token in the text. */
    public function unmask(string $text): string
    {
        if ($this->tokenToValue === [] || $text === '') {
            return $text;
        }

        return strtr($text, $this->tokenToValue);
    }

    /** @return array<string, string> token => real value */
    public function map(): array
    {
        return $this->tokenToValue;
    }

    private function maskNode(mixed $node, ?string $kind): mixed
    {
        if (is_array($node)) {
            $out = [];
            foreach ($node as $key => $child) {
                // A string key names a column: it decides the kind for its subtree.
                // A list index inherits the kind of the column holding the list.
                $childKind = is_string($key) ? ($this->maskedKind($key) ?? $kind) : $kind;
                $out[$key] = $this->maskNode($child, $childKind);
            }

            return $out;
        }

        // Identifying fields are text (emails, phones, SINs, dates): a number under
        // such a column is a count/flag, never masked (P-2 — counts must not
        // collapse into one shared token).
        if (!is_string($node)) {
            return $node;
        }

        $value = (string) $node;
        if (trim($value) === '') {
            return $node;
        }

        if ($kind !== null) {
            return $this->token($kind, $value);
        }

        if (isset($this->fields['email']) && preg_match(self::EMAIL_FORMAT, trim($value)) === 1) {
            return $this->token('email', $value);
        }

        return $node;
    }

    private function token(string $kind, string $value): string
    {
        $key = mb_strtolower(trim($value));
        if (isset($this->valueToToken[$key])) {
            return $this->valueToToken[$key];
        }

        $this->counters[$kind] = ($this->counters[$kind] ?? 0) + 1;
        $token = '[' . $kind . '#' . $this->counters[$kind] . ']';
        $this->valueToToken[$key] = $token;
        $this->tokenToValue[$token] = trim($value);

        return $token;
    }

    /**
     * Lowercased name segments: split on non-alphanumerics and camelCase humps.
     * A trailing plural "s" on a segment is folded (`emails` ~ `email`) —
     * character-level normalization of identifier names, not a vocabulary.
     *
     * @return list<string>
     */
    private static function segments(string $name): array
    {
        $name = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $name);
        $parts = preg_split('/[^A-Za-z0-9]+/', strtolower($name)) ?: [];

        return array_values(array_filter($parts, static fn ($p) => $p !== ''));
    }

    /**
     * The field's segments must END the column name (P-2): `member_email`,
     * `contactEmail`, `emails` match `email`; `email_status`, `email_sent_at`,
     * `phone_count` do not — a status, timestamp or count about the field is not
     * the identifying value. Only the LAST segment may carry a plural "s".
     *
     * @param list<string> $haystack column segments
     * @param list<string> $needle   field segments
     */
    private static function endsWithSequence(array $haystack, array $needle): bool
    {
        $n = count($needle);
        $offset = count($haystack) - $n;
        if ($n === 0 || $offset < 0) {
            return false;
        }

        for ($j = 0; $j < $n; $j++) {
            $segment = $haystack[$offset + $j];
            $isLast = $j === $n - 1;
            if ($segment !== $needle[$j] && !($isLast && $segment === $needle[$j] . 's')) {
                return false;
            }
        }

        return true;
    }

    private static function config(string $key, mixed $default): mixed
    {
        if (!function_exists('config')) {
            return $default;
        }

        try {
            return config($key, $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
