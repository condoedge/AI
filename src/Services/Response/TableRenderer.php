<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Services\Chat\Exporter\ResultSetExporter;
use Condoedge\Ai\Services\Discovery\EntityLabelIndex;
use Condoedge\Ai\Services\Query\FanOutCollapse;
use Condoedge\Ai\Support\AliasNormalizer;

/**
 * TableRenderer — THE one renderer from result rows to a table (Phase 8 batch A).
 *
 * Replaces the three former renderers (RenderTableAction::normalize, which did no
 * redaction; ExportResponseComposer::markdownTable; ResultSetExporter::toMatrix):
 *
 *   - redaction is the exporter's ({@see ResultSetExporter::redact()} — internal
 *     id-ish columns, the export deny-list, UI decoration keys), reused, not copied;
 *   - headers are humanized column keys (or caller-supplied labels) — and, for a column the
 *     query projects as a plain `v.prop AS alias` whose alias shares no word with the
 *     property nor with its node's label, the humanized PROPERTY ({@see columnProjections()},
 *     {@see label()}) — said in the answer's language through the HOST's translator when the
 *     caller gives a header locale ({@see headers()}, {@see HostText}): the header itself is
 *     the translation key, kept as it is when the host ships no line for it;
 *   - a display cap (config ai.response_generation.table_max_rows, default 25)
 *     with an honest shown/total/truncated report;
 *   - optional PII masking ({@see PiiMasker}) for LLM-facing renders.
 *
 * It also hosts the STRUCTURAL row analyses the deterministic data answer needs —
 * all driven by value types and value distributions, never by column-name word
 * lists: summable (decimal-valued) columns, query-computed total columns
 * (a decimal column constant on every row), and homonym groups (rows sharing a
 * name value but differing in a coarser identifying column).
 */
final class TableRenderer
{
    public const DEFAULT_MAX_ROWS = 25;

    /** Largest whole-result group still treated as homonyms (see homonyms()). */
    private const WHOLE_RESULT_HOMONYM_MAX = 10;

    /** A column keyed by an unaliased `variable.property` projection. */
    private const PROPERTY_KEY = '/^([A-Za-z_]\w*)\.([A-Za-z_]\w*)$/';

    private ResultSetExporter $exporter;

    public function __construct(?ResultSetExporter $exporter = null)
    {
        $this->exporter = $exporter ?? new ResultSetExporter();
    }

    /**
     * Rows as the table shows them: decorations stripped always, redaction
     * (internal ids / export deny-list) unless disabled, non-array rows dropped.
     *
     * @param array<int, mixed> $rows
     * @param array<int, string>|null $piiColumns explicit export deny-list (null = config)
     * @return array<int, array<string, mixed>>
     */
    public function prepare(array $rows, bool $redact = true, ?array $piiColumns = null): array
    {
        $rows = $redact
            ? $this->dropEntityIds($this->exporter->redact($rows, $piiColumns))
            : $this->exporter->stripDecorations($rows);

        return array_values(array_filter($rows, static fn ($row) => is_array($row) && $row !== []));
    }

    /**
     * Chat-facing renders (redact = true) also drop the configured entity id fields
     * (ai.entity_id_fields — the list the action-link enricher reads ids from): a
     * bare `id` must never be shown in the chat (the privacy policy allows ids only
     * inside action-link targets). File exports keep their own policy (callers
     * redact via the exporter and render with redact = false).
     *
     * @param array<int, mixed> $rows
     * @return array<int, mixed>
     */
    private function dropEntityIds(array $rows): array
    {
        try {
            $fields = function_exists('config') ? config('ai.entity_id_fields', ['id', '_id', 'neo4j_id']) : ['id', '_id', 'neo4j_id'];
        } catch (\Throwable $e) {
            $fields = ['id', '_id', 'neo4j_id'];
        }
        $drop = [];
        foreach ((array) $fields as $field) {
            if (is_string($field) && $field !== '') {
                $drop[strtolower($field)] = true;
            }
        }
        if ($drop === []) {
            return $rows;
        }

        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (array_keys($row) as $key) {
                if (isset($drop[strtolower((string) $key)])) {
                    unset($row[$key]);
                }
            }
            $rows[$i] = $row;
        }

        return $rows;
    }

    /**
     * Column keys in first-seen order across the rows.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    public function columns(array $rows): array
    {
        $columns = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (array_keys($row) as $key) {
                $key = (string) $key;
                if (!in_array($key, $columns, true)) {
                    $columns[] = $key;
                }
            }
        }

        return $columns;
    }

    /**
     * Columns holding no value on ANY row — the export's emptiness
     * (ExportResponseComposer::withoutEmptyColumns): null, a blank string or an empty list
     * is empty; 0, false and "0" are values; a row missing the key is empty for it. Purely
     * value-based, in first-seen column order. Such a column says nothing in a table (eval
     * p16-A: "Bm Role", "Team Address", "Oldest Due" headed blank columns).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return list<string>
     */
    public function emptyColumns(array $rows): array
    {
        $empty = [];
        foreach ($this->columns($rows) as $column) {
            foreach ($rows as $row) {
                $value = is_array($row) ? ($row[$column] ?? null) : null;
                if ($value !== null && $value !== [] && !(is_string($value) && trim($value) === '')) {
                    continue 2;
                }
            }
            $empty[] = $column;
        }

        return $empty;
    }

    /**
     * Render rows to a markdown table.
     *
     * Options:
     *   - max_rows (int|null): display cap; null = config table_max_rows; 0 = no cap
     *   - total (int|null): the population's true total (defaults to count(rows))
     *   - redact (bool, default true), pii_columns (array|null): see prepare()
     *   - masker (PiiMasker|null): mask identifying values (LLM-facing renders)
     *   - humanize_values (bool, default false): grouped decimals, readable dates
     *   - locale (string|null): number formatting locale for humanized values
     *   - labels (array<string,string>): column => header label overrides
     *   - header_locale (string|null): the language the HEADERS are said in — each humanized
     *     header is translated through the host's translator for it ({@see headers()}); absent
     *     / null = headers are not translated. Explicit `labels` are never rewritten
     *   - links_column (bool, default false): append the rows' action_links chips as "Links"
     *   - variable_labels (array<string,string>): cypher variable => node label, to tell
     *     colliding unaliased `v.prop` headers apart (see headers())
     *   - projections (array<string,array{0:string,1:string,2?:?string}>): result column => [variable,
     *     property, node label] of the query's plain property projections ({@see headerProjections()}) —
     *     a column whose alias names neither its property nor its record is headed by the property
     *   - marked_values (array<string,string>): cell value => marker appended as " (marker)"
     *     to every string cell equal to it (case-insensitive, trimmed); counted in `marked`
     *   - layout ('table'|'record', default 'table'): 'record' renders each row as a compact
     *     "Field: value" block ({@see recordMarkdown()}) — how ONE record is shown
     *   - omit_columns (array<int,string>): column keys left out of the headers and cells
     *     (the caller's {@see emptyColumns()} over ALL its rows — a column empty on every
     *     row). The remaining headers keep the names they have in the full column set;
     *     ignored when it would leave no column at all
     *
     * @param array<int, mixed> $rows
     * @param array<string, mixed> $options
     * @return array{markdown: string, headers: string[], columns: string[], matrix: array<int, string[]>, shown: int, total: int, truncated: bool, marked: int}
     */
    public function render(array $rows, array $options = []): array
    {
        $redact = (bool) ($options['redact'] ?? true);
        $pii = $options['pii_columns'] ?? null;

        // links_column: keep each row's entity-action chips (a UI decoration that
        // prepare() strips) as a trailing "Links" column — the digest LLM must be
        // able to copy the per-row links verbatim.
        $prepared = [];
        $links = [];
        foreach ($rows as $row) {
            $kept = $this->prepare([$row], $redact, $pii)[0] ?? null;
            if ($kept === null) {
                continue;
            }
            $prepared[] = $kept;
            $rowLinks = is_array($row) && is_array($row['action_links'] ?? null) ? $row['action_links'] : [];
            $links[] = implode(' ', array_filter($rowLinks, 'is_string'));
        }
        $withLinks = ($options['links_column'] ?? false) === true && array_filter($links) !== [];

        $maxRows = $options['max_rows'] ?? $this->configuredMaxRows();
        $maxRows = (int) $maxRows;
        $visible = $maxRows > 0 ? array_slice($prepared, 0, $maxRows) : $prepared;

        $total = isset($options['total']) && is_numeric($options['total'])
            ? max((int) $options['total'], count($prepared))
            : count($prepared);

        [$headers, $matrix, $columns, $marked] = $this->build($visible, $options);
        if ($withLinks) {
            $headers[] = 'Links';
            foreach ($matrix as $i => $cells) {
                $matrix[$i][] = $links[$i] ?? '';
            }
        }

        // User-facing renders escape markdown syntax (P-5); LLM-facing (masked)
        // renders do not, unless told otherwise, so tokens/links stay readable.
        $escape = (bool) ($options['escape_markdown'] ?? !(($options['masker'] ?? null) instanceof PiiMasker));

        return [
            'markdown' => ($options['layout'] ?? 'table') === 'record'
                ? $this->recordMarkdown($headers, $matrix, $escape)
                : $this->markdown($headers, $matrix, $escape),
            'headers' => $headers,
            'columns' => $columns,
            'matrix' => $matrix,
            'shown' => count($matrix),
            'total' => $total,
            'truncated' => $total > count($matrix),
            'marked' => $marked,
        ];
    }

    /**
     * [headers, matrix] for file exports and the render_table payload — every row
     * (no cap), redacted unless options.redact === false, raw cell values unless
     * options.humanize_values.
     *
     * @param array<int, mixed> $rows
     * @param array<string, mixed> $options
     * @return array{0: string[], 1: array<int, string[]>}
     */
    public function matrix(array $rows, array $options = []): array
    {
        $prepared = $this->prepare($rows, (bool) ($options['redact'] ?? true), $options['pii_columns'] ?? null);
        [$headers, $matrix] = $this->build($prepared, $options);

        return [$headers, $matrix];
    }

    /**
     * Humanized header for a column key ("due_amount" / "dueAmount" => "Due Amount").
     *
     * THE HEADER TELLS WHAT THE COLUMN HOLDS (eval p17-B cold-roster-excel: `u.email AS
     * contact_info` headed 523 emails "Contact Info"; p13 `u.email AS contact`; p14
     * `n.due_amount AS contribution`). The generation prompt asks for property-named aliases
     * but the model follows it about half the time, so the rule is enforced here: a column
     * that $projections ({@see columnProjections()}) knows as a plain `v.prop AS alias` is
     * headed by its PROPERTY when the alias is tied to nothing the column is made of
     * ({@see headingProperty()}): it shares no word ({@see shareWord()}) with the property
     * — an alias that does (member_name ↔ name) is the generator's disambiguated name and
     * stays — nor with the node label of its variable — an alias that does names the RECORD
     * the value belongs to (`s.name AS supplier` for `(s:Supplier)`; the live `cd.value AS
     * contact_info` for `(cd:ContactDirectory)`) and says more than a bare "Name" / "Value".
     * Aggregates and expressions are not in the map: their alias is all there is. Only the
     * header changes — the row keys never do.
     *
     * @param array<string, string> $labels explicit overrides
     * @param array<string, array{0: string, 1: string, 2?: ?string}> $projections result column => [variable, property, node label]
     */
    public function label(string $key, array $labels = [], array $projections = []): string
    {
        if (isset($labels[$key]) && is_string($labels[$key]) && trim($labels[$key]) !== '') {
            return trim($labels[$key]);
        }

        // An unaliased projection (`RETURN c.first_name`) keys the column by the
        // whole expression: the header is the PROPERTY, never "C.first Name" (F8).
        if (preg_match(self::PROPERTY_KEY, $key, $m) === 1) {
            $key = $m[2];
        } elseif (($property = self::headingProperty($key, $projections)) !== null) {
            $key = $property;
        }

        $header = self::headerWords($key);

        return $header === '' ? 'Value' : $header;
    }

    /** An identifier as the words of a header: `due_amount` → "Due Amount". */
    private static function headerWords(string $key): string
    {
        $header = preg_replace('/(?<=[a-z0-9])([A-Z])/', ' $1', $key) ?? $key;
        $header = str_replace(['_', '-'], ' ', $header);

        return ucwords(trim(preg_replace('/\s+/', ' ', $header) ?? $header));
    }

    /**
     * Headers for a column set: label() per column, then any headers that COLLIDE
     * (case-insensitive) are told apart — an unaliased `v.prop` column is prefixed
     * with its node label (from $variableLabels, see variableLabels()), else with the
     * variable itself; when the node label still collides (two variables of the
     * same label) the variable is used. Explicit $labels are never rewritten.
     *
     * A column headed by its property through $projections ({@see label()}) is told apart
     * the same way; when even its variable cannot (the same `v.prop` projected twice under
     * two aliases) its alias heads it after all. A column that keeps its own alias is never
     * rewritten.
     *
     * IN THE ANSWER'S LANGUAGE (eval p18-B french t0 / t1: a French roster headed "Name / Email /
     * Role" whenever the generator aliased in English). With a $locale every header that is
     * not an explicit label is said through the HOST's translator ({@see HostText::header()}:
     * its dedicated `ai.header.<Header>` line in any language, else — in another language than
     * the schema's — its own line for that word, the header itself being the key as for a
     * legend label; kept when the host ships no line).
     * Headers are told apart FIRST (those rules read schema words), and a translation that
     * would make two headers read the same is not applied to them. Null = no translation.
     *
     * @param array<int, string> $columns
     * @param array<string, string> $labels explicit overrides
     * @param array<string, string> $variableLabels cypher variable => node label
     * @param array<string, array{0: string, 1: string, 2?: ?string}> $projections result column => [variable, property, node label]
     * @param string|null $locale the language the headers are said in (null = not translated)
     * @return array<int, string>
     */
    public function headers(array $columns, array $labels = [], array $variableLabels = [], array $projections = [], ?string $locale = null): array
    {
        $headers = [];
        /** @var array<int, array{0: string, 1: string, 2: ?string}> $owners [variable, property, node label] of each column headed by its property */
        $owners = [];
        /** @var array<int, true> $aliased the columns among them that carry an alias */
        $aliased = [];
        /** @var array<int, true> $explicit the columns headed by a caller-supplied label */
        $explicit = [];
        foreach ($columns as $i => $column) {
            $headers[$i] = $this->label($column, $labels, $projections);
            if (isset($labels[$column]) && is_string($labels[$column]) && trim($labels[$column]) !== '') {
                $explicit[$i] = true;
                continue;
            }
            if (preg_match(self::PROPERTY_KEY, $column, $m) === 1) {
                $owners[$i] = [$m[1], $m[2], $this->nodeLabel($variableLabels[$m[1]] ?? null)];
            } elseif (self::headingProperty($column, $projections) !== null) {
                [$variable, $property] = [(string) $projections[$column][0], (string) $projections[$column][1]];
                $owners[$i] = [
                    $variable,
                    $property,
                    $this->nodeLabel($variableLabels[$variable] ?? null) ?? $this->nodeLabel($projections[$column][2] ?? null),
                ];
                $aliased[$i] = true;
            }
        }

        foreach ([true, false] as $useNodeLabel) {
            $counts = array_count_values(array_map('mb_strtolower', $headers));
            foreach ($owners as $i => [$variable, $property, $nodeLabel]) {
                if ($counts[mb_strtolower($headers[$i])] < 2) {
                    continue;
                }
                $owner = $useNodeLabel && $nodeLabel !== null ? $nodeLabel : $variable;
                $headers[$i] = $this->label($owner) . ' ' . $this->label($property);
            }
        }

        $counts = array_count_values(array_map('mb_strtolower', $headers));
        foreach (array_keys($aliased) as $i) {
            if ($counts[mb_strtolower($headers[$i])] > 1) {
                $headers[$i] = $this->label($columns[$i]);
            }
        }

        return array_values($locale === null ? $headers : $this->translatedHeaders($headers, $explicit, $locale));
    }

    /**
     * $headers said in $locale through the host's translator — except the explicit ones, and
     * except those a translation would make read like another header (case-insensitive):
     * these keep the name that told them apart.
     *
     * @param array<int, string> $headers
     * @param array<int, true> $explicit indexes of caller-supplied labels
     * @return array<int, string>
     */
    private function translatedHeaders(array $headers, array $explicit, string $locale): array
    {
        $translated = $headers;
        foreach ($headers as $i => $header) {
            if (!isset($explicit[$i])) {
                $translated[$i] = $this->translateHeader($header, $locale);
            }
        }

        // Reverting one header can make it equal another translation, so settle in passes
        // (at most one per header: each pass reverts at least one or stops).
        for ($pass = count($headers); $pass > 0; $pass--) {
            $counts = array_count_values(array_map('mb_strtolower', $translated));
            $reverted = false;
            foreach ($translated as $i => $header) {
                if ($header !== $headers[$i] && $counts[mb_strtolower($header)] > 1) {
                    $translated[$i] = $headers[$i];
                    $reverted = true;
                }
            }
            if (!$reverted) {
                break;
            }
        }

        return $translated;
    }

    /**
     * ONE header (or humanized field name) as a table in $locale heads it: the host's line for
     * it, else the header unchanged. Null / blank locale = unchanged.
     */
    public function translateHeader(string $header, ?string $locale): string
    {
        return $locale === null || trim($locale) === '' ? $header : HostText::header($header, $locale);
    }

    /**
     * Tenant-entered text for a code-written SENTENCE (not a table cell): whitespace collapsed
     * and markdown link / emphasis / code syntax escaped, exactly as a cell is (P-5) — a
     * record named "[Verify](https://phish…)" never renders as a link. Pipes need no escape
     * outside a table.
     */
    public function escapeInline(string $value): string
    {
        return $this->escape($this->validUtf8($value), true, false);
    }

    /** A usable node label, or null. */
    private function nodeLabel(mixed $label): ?string
    {
        return is_string($label) && $label !== '' ? $label : null;
    }

    /**
     * Result column => [variable, property, node label] for every column the query's FINAL
     * RETURN projects as exactly `v.prop [AS alias]` (an unaliased one is keyed "v.prop", as
     * Neo4j names it; the node label is the variable's, from {@see variableLabels()}, or
     * null). A function, an aggregate, any expression, a bare variable carried from a WITH
     * and every column of a UNION are absent: only a plain property projection names what
     * its column holds. The parse is FanOutCollapse's (the same final-RETURN items the
     * export's requested fields are read from). Structural only.
     *
     * @return array<string, array{0: string, 1: string, 2: ?string}>
     */
    public static function columnProjections(?string $cypher): array
    {
        if (trim((string) $cypher) === '') {
            return [];
        }

        try {
            $items = FanOutCollapse::returnItems((string) $cypher);
        } catch (\Throwable $e) {
            return []; // a header nicety never breaks a render
        }

        $nodes = self::variableLabels($cypher);
        $projections = [];
        foreach ($items as $item) {
            if (preg_match(self::PROPERTY_KEY, $item['expression'], $m) === 1) {
                $projections[$item['column']] ??= [$m[1], $m[2], $nodes[$m[1]] ?? null];
            }
        }

        return $projections;
    }

    /**
     * {@see columnProjections()} for an answer in $language — or [] when property names are
     * not words of that language. A property name is a word of the SCHEMA's language
     * (ai.response_generation.property_header_language, default "en"); in an answer in
     * another language the generator's alias is the only header in the user's language
     * (eval p16/p17 french-flow: `u.name AS membre` reads "Membre", never "Name", in a French
     * table). Language CODES are compared (primary subtag) — never words. No question
     * language = the app locale (the package's language policy); a null / blank setting
     * turns property headers off.
     *
     * @return array<string, array{0: string, 1: string, 2: ?string}>
     */
    public static function headerProjections(?string $cypher, ?string $language = null): array
    {
        try {
            $schema = function_exists('config') ? config('ai.response_generation.property_header_language', 'en') : 'en';
            $locale = function_exists('app') ? app()->getLocale() : null;
        } catch (\Throwable $e) {
            [$schema, $locale] = ['en', null];
        }

        $schema = self::primaryLanguage($schema);
        $answer = self::primaryLanguage($language) ?? self::primaryLanguage($locale);

        $projections = self::columnProjections($cypher);
        if ($schema !== null && $schema === $answer) {
            return $projections;
        }
        if ($schema === null || $answer === null) {
            return [];
        }

        // A figure is what decides an answer: `b.due_amount AS frais` beside `b.total_amount AS coût`
        // told the model the fees were 0,00 $ on 6 of 6 runs (2026-10-30). So the alias of an amount
        // yields to the host's own word for its property. No word, and the alias stays.
        $money = MoneyColumns::fromCypher($cypher);

        return array_filter(
            $projections,
            static function (array $projection, string $column) use ($money, $answer): bool {
                $words = self::headerWords($projection[1]);

                return in_array($column, $money, true) && HostText::header($words, $answer) !== $words;
            },
            ARRAY_FILTER_USE_BOTH
        );
    }

    /** "fr-CA" / "FR_ca" → "fr"; null for a non-string or blank code. */
    private static function primaryLanguage(mixed $code): ?string
    {
        if (!is_string($code) || trim($code) === '') {
            return null;
        }

        return strtolower((string) (preg_split('/[-_]/', trim($code))[0] ?? ''));
    }

    /**
     * The property that heads $column in place of its alias: the column is a plain property
     * projection and its alias shares no word with that property NOR with the node label of
     * the variable it is read from (an alias naming the property, or the record it belongs
     * to, stays). Null otherwise.
     *
     * @param array<string, array{0: string, 1: string, 2?: ?string}> $projections
     */
    private static function headingProperty(string $column, array $projections): ?string
    {
        $projection = $projections[$column] ?? null;
        if (!is_array($projection) || !is_string($projection[0] ?? null) || !is_string($projection[1] ?? null)
            || $projection[1] === '') {
            return null;
        }

        $nodeLabel = $projection[2] ?? null;
        if (self::shareWord($column, $projection[1])
            || (is_string($nodeLabel) && $nodeLabel !== '' && self::shareWord($column, $nodeLabel))) {
            return null;
        }

        return $projection[1];
    }

    /**
     * Whether two identifiers share a word. Words are split at `_` / `-`, camelCase and
     * letter↔digit boundaries and compared after {@see AliasNormalizer} folding (case,
     * accents, plural mark): member_name ↔ name, lastLogin ↔ last_logged_at, rôle ↔ role,
     * emails ↔ email. A digit run is never a shared WORD (line2 ↔ address2). Structural —
     * no vocabulary.
     */
    private static function shareWord(string $a, string $b): bool
    {
        $words = static function (string $identifier): array {
            $spaced = (string) preg_replace('/(?<=\p{L})(?=\p{N})|(?<=\p{N})(?=\p{L})/u', ' ', $identifier);

            return array_filter(
                AliasNormalizer::tokens($spaced),
                static fn (string $token): bool => preg_match('/\p{L}/u', $token) === 1
            );
        };

        return array_intersect($words($a), $words($b)) !== [];
    }

    /**
     * Node variable => label from the cypher's node patterns (`(c:Contact)`), first
     * binding wins. Structural only — used to tell colliding headers apart.
     *
     * @return array<string, string>
     */
    public static function variableLabels(?string $cypher): array
    {
        $cypher = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/", "''", (string) $cypher);
        $labels = [];
        if (preg_match_all('/\(\s*([A-Za-z_]\w*)\s*:\s*`?([A-Za-z_]\w*)`?/', $cypher, $m, PREG_SET_ORDER)) {
            foreach ($m as $node) {
                $labels[$node[1]] ??= $node[2];
            }
        }

        return $labels;
    }

    /**
     * Result column => the node variable it is projected from: `v.prop AS alias` (in any
     * WITH / RETURN), or a bare `v.prop` (keyed "v.prop") — for variables bound to a node
     * pattern only. The same projection parse as RecordResolver (the record chips'
     * source of truth); an expression (`trim(a.x + b.y) AS z`, an aggregate) has no single
     * owner and is absent. Structural only — tells a record's OWN columns from another
     * node's (a parent beside each of its children).
     *
     * @return array<string, string>
     */
    public static function columnVariables(?string $cypher): array
    {
        $cypher = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/", "''", (string) $cypher);
        $nodes = self::variableLabels($cypher);
        $columns = [];
        if ($nodes !== []
            && preg_match_all('/\b([A-Za-z_]\w*)\.([A-Za-z_]\w*)\b(?:\s+AS\s+([A-Za-z_]\w*))?/i', $cypher, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                if (isset($nodes[$hit[1]])) {
                    $columns[($hit[3] ?? '') !== '' ? $hit[3] : $hit[1] . '.' . $hit[2]] ??= $hit[1];
                }
            }
        }

        return $columns;
    }

    // =========================================================================
    // A name split over several properties → ONE column (eval p19-A board)
    // =========================================================================

    /** The word a merged name column is keyed by — humanized and translated like any header. */
    private const NAME_KEY = 'name';

    /**
     * The NAME columns to merge for display (eval p17–p19 A board: a person's name spread over
     * "First Name | Last Name"). An entity whose name is split over several properties declares
     * them (`metadata.name_properties`, e.g. Contact: ['first_name', 'last_name'] — what the
     * record chips already name a record by, {@see \Condoedge\Ai\Services\Actions\Attach\RecordResolver}).
     * When the query's final RETURN projects ALL of them as plain property columns of the SAME
     * node variable ({@see columnProjections()}), they are ONE column for the reader.
     *
     * Nothing is merged when only some of the parts are projected, when they come from
     * different variables (two contacts of one row stay two names), when a part's column was
     * left out of $rows (redacted) or holds anything but text / numbers, or when the merged
     * key is already a column.
     *
     * The merged column's KEY names what it holds, so the header rules head it like any column:
     *   - the aliases carry the same qualifier in front of their property (`o.first_name AS
     *     owner_first_name`, `o.last_name AS owner_last_name`) → `owner_name` ("Owner Name");
     *   - else `<variable>.name` — an unaliased-projection key: headed "Name", said in the
     *     answer's language through the host's translator, and told apart by its node label
     *     ("Contact Name") when another column is headed the same ({@see headers()}).
     *
     * @param array<int, mixed> $rows the rows the merge will be applied to
     * @return list<array{key: string, columns: list<string>, variable: string, label: string, properties: list<string>}>
     *         `columns` in the DECLARED order of the name properties
     */
    public static function nameMerges(?string $cypher, array $rows): array
    {
        $projections = self::columnProjections($cypher);
        if ($projections === []) {
            return [];
        }

        $present = [];
        foreach ($rows as $row) {
            foreach (is_array($row) ? array_keys($row) : [] as $key) {
                $present[(string) $key] = true;
            }
        }

        /** @var array<string, array{label: ?string, columns: array<string, string>}> $byVariable property => first column */
        $byVariable = [];
        foreach ($projections as $column => [$variable, $property, $label]) {
            if (isset($present[(string) $column])) {
                $byVariable[$variable]['label'] = $label;
                $byVariable[$variable]['columns'][$property] ??= (string) $column;
            }
        }

        $merges = [];
        $taken = $present;
        foreach ($byVariable as $variable => $owned) {
            $properties = self::namePropertiesOf($owned['label'] ?? null);
            if (count($properties) < 2 || array_diff($properties, array_keys($owned['columns'])) !== []) {
                continue;
            }
            $columns = array_map(static fn (string $property): string => $owned['columns'][$property], $properties);
            if (!self::mergeable($rows, $columns)) {
                continue;
            }
            $key = self::mergedKey((string) $variable, array_combine($properties, $columns));
            if (isset($taken[$key])) {
                continue;
            }
            $taken[$key] = true;
            $merges[] = [
                'key' => $key,
                'columns' => $columns,
                'variable' => (string) $variable,
                'label' => (string) $owned['label'],
                'properties' => $properties,
            ];
        }

        return $merges;
    }

    /**
     * $rows with each merge's columns replaced by ONE column — at the position of the first of
     * them the row holds — whose value is their non-empty values joined by a space in the
     * merge's (declared) order; null when the row holds none of them. Every other column and
     * the row order are untouched. Apply it AFTER masking: a masked part stays its token.
     *
     * @param array<int, mixed> $rows
     * @param list<array{key: string, columns: list<string>}> $merges {@see nameMerges()}
     * @return array<int, mixed>
     */
    public function mergeColumns(array $rows, array $merges): array
    {
        if ($merges === []) {
            return $rows;
        }

        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($merges as $merge) {
                $parts = [];
                foreach ($merge['columns'] as $column) {
                    $value = $row[$column] ?? null;
                    if (is_scalar($value) && !is_bool($value) && trim((string) $value) !== '') {
                        $parts[] = trim((string) $value);
                    }
                }
                $merged = [];
                $placed = false;
                foreach ($row as $column => $value) {
                    if (!in_array((string) $column, $merge['columns'], true)) {
                        $merged[$column] = $value;
                    } elseif (!$placed) {
                        $merged[$merge['key']] = $parts === [] ? null : implode(' ', $parts);
                        $placed = true;
                    }
                }
                $row = $merged;
            }
            $rows[$i] = $row;
        }

        return $rows;
    }

    /**
     * The properties a label's entity config declares as its name (`metadata.name_properties`,
     * read through EntityLabelIndex); [] when it declares none or is not configured.
     *
     * @return list<string>
     */
    private static function namePropertiesOf(?string $label): array
    {
        if ($label === null || $label === '') {
            return [];
        }

        try {
            $config = EntityLabelIndex::shared()->configForLabel($label) ?? [];
        } catch (\Throwable $e) {
            return []; // a display nicety never breaks a render
        }

        return array_values(array_unique(array_filter(
            (array) ($config['metadata']['name_properties'] ?? []),
            static fn ($property): bool => is_string($property) && $property !== ''
        )));
    }

    /**
     * Whether every value of $columns is text / a number / nothing on every row (a collected
     * list or a map is never joined into a name).
     *
     * @param array<int, mixed> $rows
     * @param list<string> $columns
     */
    private static function mergeable(array $rows, array $columns): bool
    {
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $value = is_array($row) ? ($row[$column] ?? null) : null;
                if ($value !== null && (!is_scalar($value) || is_bool($value))) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The key of a merged name column ({@see nameMerges()}): the qualifier every alias puts in
     * front of its property, then "name" — else `<variable>.name`.
     *
     * @param array<string, string> $columns name property => its result column
     */
    private static function mergedKey(string $variable, array $columns): string
    {
        $words = static fn (string $identifier): array => AliasNormalizer::tokens(
            (string) preg_replace('/(?<=\p{L})(?=\p{N})|(?<=\p{N})(?=\p{L})/u', ' ', $identifier)
        );

        $qualifier = null;
        foreach ($columns as $property => $column) {
            if (preg_match(self::PROPERTY_KEY, $column) === 1) {
                $own = [];
            } else {
                [$alias, $suffix] = [$words($column), $words((string) $property)];
                if ($suffix === [] || array_slice($alias, -count($suffix)) !== $suffix) {
                    return $variable . '.' . self::NAME_KEY; // the alias does not follow its property
                }
                $own = array_slice($alias, 0, -count($suffix));
            }
            if ($qualifier !== null && $qualifier !== $own) {
                return $variable . '.' . self::NAME_KEY;
            }
            $qualifier = $own;
        }

        return $qualifier === null || $qualifier === []
            ? $variable . '.' . self::NAME_KEY
            : implode('_', $qualifier) . '_' . self::NAME_KEY;
    }

    /**
     * One cell's display string.
     */
    public function cell(mixed $value, bool $humanize = false, ?string $locale = null): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_float($value) && $humanize && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            return (string) (int) $value;
        }
        if (is_float($value)) {
            return $humanize ? $this->formatDecimal($value, $locale) : $this->plainFloat($value);
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return $humanize ? $this->humanizeString($value) : $value;
        }
        if (is_array($value)) {
            return $this->nestedCell($value, $humanize, $locale);
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A money amount for display (E2, eval p11-A): formatDecimal() plus the configured
     * currency symbol (ai.response_generation.currency_symbol, default null = no symbol),
     * placed where the locale's own currency pattern puts it ("$1,211.48" in en,
     * "1 211,48 $" in fr). Only for DECLARED money columns ({@see MoneyColumns}).
     */
    public function formatMoney(int|float|string $value, ?string $locale = null): string
    {
        $number = $this->formatDecimal((float) $value, $locale);
        $symbol = self::currencySymbol();
        if ($symbol === null) {
            return $number;
        }

        $sign = '';
        foreach (['-', "\u{2212}"] as $minus) {
            if (str_starts_with($number, $minus)) {
                $sign = $minus;
                $number = substr($number, strlen($minus));
                break;
            }
        }

        return $sign . ($this->symbolFollows($locale ?? $this->appLocale())
            ? $number . ' ' . $symbol
            : $symbol . $number);
    }

    /** The configured display currency symbol, or null (none configured). */
    public static function currencySymbol(): ?string
    {
        try {
            $symbol = function_exists('config') ? config('ai.response_generation.currency_symbol') : null;
        } catch (\Throwable $e) {
            return null;
        }

        return is_string($symbol) && trim($symbol) !== '' ? trim($symbol) : null;
    }

    /** Whether the locale's currency pattern puts the symbol AFTER the number (¤ after the digits). */
    private function symbolFollows(?string $locale): bool
    {
        if ($locale === null || $locale === '' || !class_exists(\NumberFormatter::class)) {
            return false;
        }

        try {
            $pattern = (string) (new \NumberFormatter($locale, \NumberFormatter::CURRENCY))->getPattern();
        } catch (\Throwable $e) {
            return false;
        }
        $symbolAt = strpos($pattern, '¤');
        $digitAt = strcspn($pattern, '#0');

        return $symbolAt !== false && $digitAt < strlen($pattern) && $symbolAt > $digitAt;
    }

    /** Decimal formatted for display: 2 fraction digits, locale grouping. */
    public function formatDecimal(float $value, ?string $locale = null): string
    {
        $locale = $locale ?? $this->appLocale();
        if ($locale !== null && $locale !== '' && class_exists(\NumberFormatter::class)) {
            try {
                $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
                $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 2);
                $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);
                $formatted = $formatter->format($value);
                if (is_string($formatted) && $formatted !== '') {
                    return $formatted;
                }
            } catch (\Throwable $e) {
                // fall through to the plain en formatting
            }
        }

        return number_format($value, 2, '.', ',');
    }

    // =========================================================================
    // Structural analyses (value types / distributions — no word lists)
    // =========================================================================

    /**
     * Columns the system may SUM (P-3, Phase 8-A review): decimal-valued AND
     * declared money ({@see MoneyColumns} — the column's source property is listed
     * in the entity config's metadata.money_properties). A rate, unit price, share
     * or tax rate is decimal but not money; when the money columns are unknown
     * nothing is summed.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $moneyColumns
     * @return array<int, string>
     */
    public function summableColumns(array $rows, array $moneyColumns): array
    {
        return array_values(array_intersect($this->decimalColumns($rows), $moneyColumns));
    }

    /**
     * Columns whose every non-null value is numeric and at least one is a DECIMAL
     * (a float, or a numeric string with a fraction part). Integers — ids, years,
     * counts, order numbers — and numeric-looking strings without a fraction never
     * qualify. Run it on prepared (redacted) rows so id columns are gone.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    public function decimalColumns(array $rows): array
    {
        if (count($rows) < 2) {
            return [];
        }

        $out = [];
        foreach ($this->columns($rows) as $column) {
            $sawDecimal = false;
            $ok = true;
            foreach ($rows as $row) {
                $value = $row[$column] ?? null;
                if ($value === null) {
                    continue;
                }
                if (!$this->isNumericValue($value)) {
                    $ok = false;
                    break;
                }
                $sawDecimal = $sawDecimal || $this->isDecimal($value);
            }
            if ($ok && $sawDecimal) {
                $out[] = $column;
            }
        }

        return $out;
    }

    /** @param array<int, array<string, mixed>> $rows */
    public function sum(array $rows, string $column): float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            $value = $row[$column] ?? null;
            if ($value !== null && $this->isNumericValue($value)) {
                $sum += (float) $value;
            }
        }

        return round($sum, 2);
    }

    /**
     * Query-computed total columns (P-4): a decimal column holding the SAME value on
     * every row (a grand total the query repeated per row) is the total of a
     * VARYING decimal column only when
     *   - complete result: it EQUALS that column's sum (±0.01);
     *   - truncated result: it is ≥ the sum of the visible rows of that column AND
     *     that column is declared money (the grand total of a money column).
     * A constant fee (monthly_fee 350) or rate (tax_rate 0.15) is never a total.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $moneyColumns
     * @return array<int, string>
     */
    public function constantTotalColumns(array $rows, bool $complete, array $moneyColumns = []): array
    {
        if (count($rows) < 2) {
            return [];
        }

        $summable = $this->decimalColumns($rows);
        $constant = [];
        $varying = [];
        foreach ($summable as $column) {
            $values = array_map(static fn ($row) => $row[$column] ?? null, $rows);
            if (in_array(null, $values, true)) {
                $varying[$column] = $values;
                continue;
            }
            $first = (float) $values[0];
            $same = true;
            foreach ($values as $v) {
                if (abs((float) $v - $first) > 0.000001) {
                    $same = false;
                    break;
                }
            }
            if ($same) {
                $constant[$column] = $first;
            } else {
                $varying[$column] = $values;
            }
        }

        $out = [];
        foreach ($constant as $column => $value) {
            foreach ($varying as $varyingColumn => $values) {
                $sum = round(array_sum(array_map(static fn ($v) => (float) $v, array_filter($values, static fn ($v) => $v !== null))), 2);
                $isTotal = $complete
                    ? abs($value - $sum) <= 0.01
                    : $value + 0.005 >= $sum && in_array($varyingColumn, $moneyColumns, true);
                if ($isTotal) {
                    $out[] = $column;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Homonym groups: ≥2 rows sharing a value in a text column while differing in
     * another text column that is COARSER (fewer distinct values across the
     * table — a parent attribute such as the team of a project), or when the group
     * is the whole result ("project 310" matched in three teams). A parent column
     * that repeats because it has many children (team → projects) is therefore
     * not reported: its children are the finer column.
     *
     * VALUE-ONLY fallback: by values alone a parent repeated beside each child mirrors real
     * homonyms, so callers with record ids use RecordResolver::homonyms() (identity) first.
     * Here a column holding a date / time value ({@see isTemporalValue()}) or listed in
     * $nonIdentifying (the cypher's aggregate aliases) is never `by` — a per-row fact never
     * tells records apart — and a group whose only differing columns are such is skipped.
     *
     * @param array<int, array<string, mixed>> $rows prepared rows
     * @param array<int, string> $nonIdentifying columns never used as `by` (aggregate aliases)
     * @return array<int, array{column: string, value: string, count: int, by: string, values: string[]}>
     */
    public function homonyms(array $rows, int $max = 5, array $nonIdentifying = []): array
    {
        $rows = array_values(array_filter($rows, 'is_array'));
        if (count($rows) < 2) {
            return [];
        }

        $textColumns = [];
        $identifying = [];
        foreach ($this->columns($rows) as $column) {
            $isText = true;
            $any = false;
            $temporal = false;
            foreach ($rows as $row) {
                $value = $row[$column] ?? null;
                if ($value === null) {
                    continue;
                }
                if (!is_string($value)) {
                    $isText = false;
                    break;
                }
                $any = $any || trim($value) !== '';
                $temporal = $temporal || self::isTemporalValue($value);
            }
            if ($isText && $any) {
                $textColumns[] = $column;
                $identifying[$column] = !$temporal && !in_array($column, $nonIdentifying, true);
            }
        }
        if (count($textColumns) < 2) {
            return [];
        }

        $distinct = [];
        foreach ($textColumns as $column) {
            $distinct[$column] = count(array_unique(array_map(
                fn ($row) => $this->key($row[$column] ?? null),
                $rows
            )));
        }

        $groups = [];
        foreach ($textColumns as $name) {
            $byValue = [];
            foreach ($rows as $row) {
                $key = $this->key($row[$name] ?? null);
                if ($key !== '') {
                    $byValue[$key][] = $row;
                }
            }

            foreach ($byValue as $group) {
                if (count($group) < 2) {
                    continue;
                }

                $best = null;
                foreach ($textColumns as $other) {
                    if ($other === $name || !$identifying[$other]) {
                        continue;
                    }
                    $values = [];
                    foreach ($group as $row) {
                        $v = $row[$other] ?? null;
                        if (is_string($v) && trim($v) !== '' && !in_array(trim($v), $values, true)) {
                            $values[] = trim($v);
                        }
                    }
                    if (count($values) < 2) {
                        continue;
                    }
                    // Whole-result exception ("project 310" in three teams) only for a
                    // SMALL group: 100 members sharing role 'contact' is not a homonym.
                    $coarser = $distinct[$other] <= $distinct[$name]
                        || (count($group) === count($rows) && count($group) <= self::WHOLE_RESULT_HOMONYM_MAX);
                    if ($coarser && ($best === null || $distinct[$other] < $distinct[$best['by']])) {
                        $best = ['by' => $other, 'values' => $values];
                    }
                }

                if ($best !== null) {
                    $groups[] = [
                        'column' => $name,
                        'value' => trim((string) $group[0][$name]),
                        'count' => count($group),
                        'by' => $best['by'],
                        'values' => $best['values'],
                    ];
                    if (count($groups) >= $max) {
                        return $groups;
                    }
                }
            }
        }

        return $groups;
    }

    /**
     * An ISO-8601 date, date-time or time of day (Neo4j / Laravel serializations) — by
     * value SHAPE: a per-row fact, never what tells two records apart.
     */
    public static function isTemporalValue(string $value): bool
    {
        return preg_match(
            '/^\s*(?:\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?(?:\[[^\]]+\])?)?|\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)\s*$/',
            $value
        ) === 1;
    }

    public function isNumericValue(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)));
    }

    public function isDecimal(mixed $value): bool
    {
        return is_float($value) || (is_string($value) && is_numeric(trim($value)) && str_contains($value, '.'));
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * @param array<int, array<string, mixed>> $rows prepared rows
     * @param array<string, mixed> $options
     * @return array{0: string[], 1: array<int, string[]>, 2: string[]}
     */
    private function build(array $rows, array $options): array
    {
        $masker = $options['masker'] ?? null;
        if ($masker instanceof PiiMasker) {
            $rows = $masker->maskRows($rows);
        }

        $humanize = (bool) ($options['humanize_values'] ?? false);
        $locale = isset($options['locale']) && is_string($options['locale']) && $options['locale'] !== ''
            ? $options['locale']
            : null;
        $labels = is_array($options['labels'] ?? null) ? $options['labels'] : [];
        $variableLabels = is_array($options['variable_labels'] ?? null) ? $options['variable_labels'] : [];
        $projections = is_array($options['projections'] ?? null) ? $options['projections'] : [];
        $marks = $this->markedValues($options['marked_values'] ?? null);

        $headerLocale = isset($options['header_locale']) && is_string($options['header_locale']) && trim($options['header_locale']) !== ''
            ? trim($options['header_locale'])
            : null;

        $columns = $this->columns($rows);
        $headers = $this->headers($columns, $labels, $variableLabels, $projections, $headerLocale);
        // omit_columns: named AFTER the headers were told apart over the full column set, so
        // dropping a column never renames another ("Contact Name" stays, beside no "Team Name").
        $omit = is_array($options['omit_columns'] ?? null)
            ? array_intersect($columns, array_filter($options['omit_columns'], 'is_string'))
            : [];
        if ($omit !== [] && count($omit) < count($columns)) {
            $headers = array_values(array_diff_key($headers, $omit));
            $columns = array_values(array_diff_key($columns, $omit));
        }
        $money = $humanize && is_array($options['money_columns'] ?? null)
            ? array_flip(array_filter($options['money_columns'], 'is_string'))
            : [];
        $dateOnly = $humanize ? $this->dateOnlyColumns($rows, $columns) : [];

        $matrix = [];
        $marked = 0;
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                $display = match (true) {
                    isset($money[$column]) && $value !== null && !is_bool($value) && $this->isNumericValue($value)
                        => $this->formatMoney($value, $locale),
                    isset($dateOnly[$column]) && is_string($value) => $this->humanizeString($value, true),
                    default => $this->cell($value, $humanize, $locale),
                };
                $cell = $this->validUtf8($display);
                $mark = is_string($value) ? ($marks[mb_strtolower(trim($value))] ?? null) : null;
                if ($mark !== null) {
                    $cell .= ' (' . $mark . ')';
                    $marked++;
                }
                $cells[] = $cell;
            }
            $matrix[] = $cells;
        }

        return [$headers, $matrix, $columns, $marked];
    }

    /**
     * marked_values option: lowercased trimmed cell value => marker text appended to
     * an exactly-equal string cell as " (marker)" (F1: the user's own identity values
     * carry the localized "that's you").
     *
     * @return array<string, string>
     */
    private function markedValues(mixed $option): array
    {
        if (!is_array($option)) {
            return [];
        }

        $marks = [];
        foreach ($option as $value => $marker) {
            $key = mb_strtolower(trim((string) $value));
            if ($key !== '' && is_string($marker) && trim($marker) !== '') {
                $marks[$key] = trim($marker);
            }
        }

        return $marks;
    }

    /**
     * @param string[] $headers
     * @param array<int, string[]> $matrix
     */
    private function markdown(array $headers, array $matrix, bool $escapeMarkdown = true): string
    {
        if ($headers === []) {
            return '';
        }

        $escape = fn (string $v): string => $this->escape($v, $escapeMarkdown);
        $out = '| ' . implode(' | ', array_map($escape, $headers)) . " |\n";
        $out .= '| ' . implode(' | ', array_fill(0, count($headers), '---')) . " |\n";
        foreach ($matrix as $cells) {
            $out .= '| ' . implode(' | ', array_map($escape, $cells)) . " |\n";
        }

        return rtrim($out);
    }

    /**
     * Cell text for a markdown table: whitespace collapsed, pipes escaped, and
     * (P-5) markdown link / citation / emphasis / code syntax backslash-escaped so a
     * tenant-entered "[Verify](https://phish…)" or "[3]" renders as plain text.
     * LLM-facing renders keep tokens like [email#1] readable (escaping off).
     */
    private function escape(string $value, bool $escapeMarkdown = true, bool $escapePipes = true): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        if ($escapeMarkdown) {
            $value = (string) preg_replace('/([\\\\\[\]()*_`])/', '\\\\$1', $value);
        }

        return $escapePipes ? str_replace('|', '\|', $value) : $value;
    }

    /** What an empty field shows in the record layout (a blank after the label reads as a glitch). */
    private const EMPTY_FIELD = '—';

    /**
     * The 'record' layout: each row as one "- **Header:** value" line per column, rows
     * separated by a blank line. ONE record reads as its fields, not as a one-row table
     * (D2, eval p16-B digest t1). Same headers and cells as the table — only the shape
     * differs — with the same escaping (P-5); a pipe needs no escape outside a table.
     *
     * @param string[] $headers
     * @param array<int, string[]> $matrix
     */
    private function recordMarkdown(array $headers, array $matrix, bool $escapeMarkdown = true): string
    {
        $blocks = [];
        foreach ($matrix as $cells) {
            $lines = [];
            foreach ($headers as $i => $header) {
                $value = $this->escape((string) ($cells[$i] ?? ''), $escapeMarkdown, false);
                $lines[] = '- **' . $this->escape($header, $escapeMarkdown, false) . ':** '
                    . ($value === '' ? self::EMPTY_FIELD : $value);
            }
            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }

    /** @param array<int|string, mixed> $value */
    private function nestedCell(array $value, bool $humanize, ?string $locale): string
    {
        if ($value === []) {
            return '';
        }

        if (!array_is_list($value)) {
            $pairs = [];
            foreach ($value as $k => $v) {
                $pairs[] = $this->label((string) $k) . ': ' . $this->cell($v, $humanize, $locale);
            }

            return implode(', ', $pairs);
        }

        $items = array_map(fn ($v) => $this->cell($v, $humanize, $locale), $value);
        $separator = array_filter($value, 'is_array') !== [] ? '; ' : ', ';

        return implode($separator, $items);
    }

    /**
     * ISO-8601 timestamps read as a date, plus the time when it is a real time of day
     * ({@see localDateTime()}); $dateOnly (a date column, {@see dateOnlyColumns()}) drops it.
     */
    private function humanizeString(string $value, bool $dateOnly = false): string
    {
        $parts = self::localDateTime($value);
        if ($parts === null) {
            return $value;
        }
        [$date, $time] = $parts;

        return $time === null || $dateOnly ? $date : "{$date} {$time}";
    }

    /**
     * The calendar day an ISO-8601 date / date-time value falls on for the user (the
     * app timezone, {@see localDateTime()}); null when the value is not date-shaped.
     */
    public static function localDay(string $value): ?string
    {
        $parts = self::localDateTime($value);
        if ($parts !== null) {
            return $parts[0];
        }

        return preg_match('/^\s*(\d{4}-\d{2}-\d{2})\s*$/', $value, $m) === 1 ? $m[1] : null;
    }

    /**
     * [date, "HH:MM" or null] of an ISO-8601 date-time, by value SHAPE (E2, eval p11-A):
     *   - a midnight time (00:00:00, any offset) is a DATE — time null;
     *   - a value carrying an offset (Z / ±hh:mm) is read in the app timezone: Laravel
     *     serializes a local-midnight date in UTC ("2022-01-01T05:00:00Z" in
     *     America/New_York), which is the date 2022-01-01, never "05:00"; a real
     *     timestamp shows its LOCAL time ("17:51Z" → "13:51");
     *   - without an offset the wall-clock value is kept as is.
     *
     * @return array{0: string, 1: ?string}|null null when the value is no ISO date-time
     */
    private static function localDateTime(string $value): ?array
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?(Z|[+-]\d{2}:?\d{2})?$/', trim($value), $m) !== 1) {
            return null;
        }

        [$date, $hour, $minute] = [$m[1], $m[2], $m[3]];
        $second = ($m[4] ?? '') !== '' ? $m[4] : '00';
        $fraction = $m[5] ?? '';
        $offset = $m[6] ?? '';
        if ($hour === '00' && $minute === '00' && $second === '00' && trim($fraction, '0') === '') {
            return [$date, null];
        }

        $timezone = $offset !== '' ? self::displayTimezone() : null;
        if ($timezone !== null) {
            try {
                $local = (new \DateTimeImmutable("{$date}T{$hour}:{$minute}:{$second}" . ($offset === 'Z' ? '+00:00' : $offset)))
                    ->setTimezone($timezone);

                return $local->format('H:i:s') === '00:00:00'
                    ? [$local->format('Y-m-d'), null]
                    : [$local->format('Y-m-d'), $local->format('H:i')];
            } catch (\Throwable $e) {
                // unparseable offset — the wall-clock value below
            }
        }

        return [$date, "{$hour}:{$minute}"];
    }

    /**
     * Columns whose date-time values are MOSTLY dates (more than half have no real time
     * of day, {@see localDateTime()}): a date column — every cell shows its date only, so
     * a stray non-midnight entry ("2021-12-01T15:00Z" among local midnights) does not
     * read as a time. A column of real timestamps keeps its times. Value distribution only.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $columns
     * @return array<string, true>
     */
    private function dateOnlyColumns(array $rows, array $columns): array
    {
        $out = [];
        foreach ($columns as $column) {
            $temporal = 0;
            $dates = 0;
            foreach ($rows as $row) {
                $value = $row[$column] ?? null;
                $parts = is_string($value) ? self::localDateTime($value) : null;
                if ($parts === null) {
                    continue;
                }
                $temporal++;
                $dates += $parts[1] === null ? 1 : 0;
            }
            if ($temporal > 0 && $dates * 2 > $temporal) {
                $out[$column] = true;
            }
        }

        return $out;
    }

    /** The app timezone (config app.timezone) date-times are shown in; null = unknown. */
    private static function displayTimezone(): ?\DateTimeZone
    {
        try {
            $name = function_exists('config') ? config('app.timezone') : null;

            return is_string($name) && $name !== '' ? new \DateTimeZone($name) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function plainFloat(float $value): string
    {
        // Shortest round-trip representation without exponent noise for money values.
        $out = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

        return $out === '-0' ? '0' : $out;
    }

    private function key(mixed $value): string
    {
        return is_scalar($value) ? mb_strtolower(trim((string) $value)) : '';
    }

    private function configuredMaxRows(): int
    {
        try {
            $value = function_exists('config') ? config('ai.response_generation.table_max_rows', self::DEFAULT_MAX_ROWS) : self::DEFAULT_MAX_ROWS;
        } catch (\Throwable $e) {
            $value = self::DEFAULT_MAX_ROWS;
        }

        return is_numeric($value) ? max(0, (int) $value) : self::DEFAULT_MAX_ROWS;
    }

    private function appLocale(): ?string
    {
        try {
            return function_exists('app') ? (string) app()->getLocale() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function validUtf8(string $value): string
    {
        if ($value === '' || @mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

        return is_string($converted) ? $converted : '';
    }
}
