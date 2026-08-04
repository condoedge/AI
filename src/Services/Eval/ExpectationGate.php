<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Eval;

use Condoedge\Ai\DTOs\Eval\EvalRow;

/**
 * Deterministic fact-gate evaluator for suite `expect` blocks.
 *
 * STRUCTURE ONLY, ZERO LLM: gates check row counts, column keys, export payload
 * markers, threaded-context availability, file citations, latency, literal
 * presence of the turn's own runtime numbers/identity values, and export-table
 * cell emptiness. They never regex-classify meaning — semantic grading is done
 * out-of-process by an agent reading the trace + standard from the report.
 *
 * Gate vocabulary:
 *  - min_rows (int >= 0)            — execution returned at least N rows.
 *  - columns_include (string[])     — every named column concept is present in
 *                                     the result rows (or the export payload's
 *                                     header row when no result rows exist).
 *  - produces_file ('pdf'|'excel')  — the response carries the export payload
 *                                     contract for that format.
 *  - previous_result_available (bool) — the turn RECEIVED a non-empty
 *                                     last_result_sample in its context.
 *  - cites_document (bool)          — the response carries file citations.
 *  - max_latency_ms (int > 0)       — wall-clock budget for the turn.
 *  - discloses_total (bool)         — when more rows exist than were shown
 *                                     (row_count / result_total > sample), the
 *                                     answer must contain the EXACT total AND
 *                                     (the shown count OR the exact remainder)
 *                                     as literal numbers — a bare total
 *                                     ("more than 523 results") is not a
 *                                     disclosure.
 *  - disambiguation_column_present (bool) — the result sample (or the export
 *                                     payload's data rows when no sample
 *                                     exists) carries >= 2 identifying columns
 *                                     and no two rows are fully identical
 *                                     across all columns (homonym safety for
 *                                     name-matched entities).
 *  - no_self_contact (bool)         — when the answer contains the pinned
 *                                     user's own email (literal,
 *                                     case-insensitive), a "that's-you" marker
 *                                     (SelfReferenceNoticeSection wordings)
 *                                     must also be present.
 *  - promised_columns_nonempty (bool) — the export payload's table has no
 *                                     header column whose EVERY data cell is
 *                                     empty (or a bare null placeholder).
 *
 * Offline honesty: produces_file, cites_document:true and
 * promised_columns_nonempty:true inspect the final response payload, so they
 * CANNOT pass on --offline runs (the command warns up front). discloses_total
 * and no_self_contact inspect the final ANSWER: offline (answer '') they
 * either red-gate (rows were withheld) or pass only VACUOUSLY — a pass whose
 * condition never fired verifies nothing and is marked 'vacuous' => true in
 * the gate result so reporting/reconciliation never mistakes it for evidence.
 *
 * Unknown expect keys are a CONFIG ERROR and throw: a typo'd gate silently
 * passing would be worse than no gate at all. The suite loader validates keys
 * at load time; evaluate() re-asserts as defense in depth.
 */
class ExpectationGate
{
    /**
     * The full vocabulary of supported expect keys.
     * @var string[]
     */
    public const KNOWN_KEYS = [
        'min_rows',
        'columns_include',
        'produces_file',
        'previous_result_available',
        'cites_document',
        'max_latency_ms',
        'discloses_total',
        'no_self_contact',
        'promised_columns_nonempty',
        'disambiguation_column_present',
    ];

    /**
     * Table cell values that count as EMPTY for promised_columns_nonempty:
     * the empty string and the bare serializations of a null value. Structural
     * placeholders, not a semantic word list.
     * @var string[]
     */
    private const EMPTY_CELL_VALUES = ['', 'none', 'null'];

    /** Formats produces_file accepts (matches ExportResponseComposer's export_format values). */
    public const FILE_FORMATS = ['pdf', 'excel'];

    /**
     * Throw a config error if $expect contains a key this gate cannot evaluate.
     *
     * @param array<string, mixed> $expect
     * @param string               $where  Human location for the error ("conversation X turn N").
     */
    public static function assertKnownKeys(array $expect, string $where): void
    {
        foreach (array_keys($expect) as $key) {
            if (!in_array((string) $key, self::KNOWN_KEYS, true)) {
                throw new \RuntimeException(
                    "Unknown expect key '{$key}' in {$where}. Supported keys: " . implode(', ', self::KNOWN_KEYS) . '.'
                );
            }
        }
    }

    /**
     * Validate expect VALUES fail-fast at load time (types/enums), so a malformed
     * suite dies with a clear message before any pipeline work is done.
     *
     * @param array<string, mixed> $expect
     */
    public static function assertValidValues(array $expect, string $where): void
    {
        self::assertKnownKeys($expect, $where);

        foreach ($expect as $key => $value) {
            $valid = match ($key) {
                'min_rows' => is_int($value) && $value >= 0,
                'columns_include' => is_array($value) && $value !== []
                    && array_filter($value, static fn ($c) => !is_string($c) || trim($c) === '') === [],
                'produces_file' => is_string($value) && in_array(strtolower($value), self::FILE_FORMATS, true),
                'previous_result_available', 'cites_document', 'discloses_total',
                'no_self_contact', 'promised_columns_nonempty',
                'disambiguation_column_present' => is_bool($value),
                'max_latency_ms' => is_int($value) && $value > 0,
                default => false,
            };

            if (!$valid) {
                throw new \RuntimeException(
                    "Invalid value for expect key '{$key}' in {$where}: " . json_encode($value)
                    . '. See ExpectationGate for the expected type.'
                );
            }
        }
    }

    /**
     * Evaluate every expect key against the row's deterministic signals.
     *
     * @param array<string, mixed> $expect
     * @return array<int, array{gate: string, expected: mixed, actual: mixed, passed: bool, detail: string}>
     */
    public function evaluate(array $expect, EvalRow $row): array
    {
        self::assertKnownKeys($expect, 'expect block');

        $results = [];
        foreach ($expect as $key => $value) {
            $results[] = match ($key) {
                'min_rows' => $this->gateMinRows((int) $value, $row),
                'columns_include' => $this->gateColumnsInclude((array) $value, $row),
                'produces_file' => $this->gateProducesFile(strtolower((string) $value), $row),
                'previous_result_available' => $this->gatePreviousResultAvailable((bool) $value, $row),
                'cites_document' => $this->gateCitesDocument((bool) $value, $row),
                'max_latency_ms' => $this->gateMaxLatency((int) $value, $row),
                'discloses_total' => $this->gateDisclosesTotal((bool) $value, $row),
                'no_self_contact' => $this->gateNoSelfContact((bool) $value, $row),
                'promised_columns_nonempty' => $this->gatePromisedColumnsNonempty((bool) $value, $row),
                'disambiguation_column_present' => $this->gateDisambiguationColumnPresent((bool) $value, $row),
            };
        }

        return $results;
    }

    // ---------------------------------------------------------------------
    // Individual gates
    // ---------------------------------------------------------------------

    private function gateMinRows(int $min, EvalRow $row): array
    {
        $passed = $row->rowCount >= $min;

        return $this->result('min_rows', $min, $row->rowCount, $passed, $passed
            ? ''
            : "Execution returned {$row->rowCount} row(s), expected at least {$min}.");
    }

    /**
     * Every named column must be present in the inspected columns. Matching is
     * case-insensitive SUBSTRING matching after normalizing separators: the
     * expected 'name' matches actual keys 'name', 'user_name', 'Member Name' —
     * suites name the CONCEPT, generators are free to prefix/alias it.
     *
     * Source of the inspected columns, in order:
     *  1. the result-row sample (union of keys — Cypher rows may be sparse), else
     *  2. when no result rows exist but the response carries an export document
     *     payload, the payload's table HEADER row (exports from previous-turn
     *     context are legitimately not backed by result rows on this turn).
     * The failure detail names which source was inspected.
     *
     * @param string[] $columns
     */
    private function gateColumnsInclude(array $columns, EvalRow $row): array
    {
        $present = [];
        foreach ($row->dataSample as $sampleRow) {
            if (is_array($sampleRow)) {
                foreach (array_keys($sampleRow) as $key) {
                    $present[strtolower((string) $key)] = (string) $key;
                }
            }
        }
        $source = 'result rows';

        if ($present === []) {
            $headers = $this->exportPayloadHeaders($row);
            if ($headers !== []) {
                foreach ($headers as $header) {
                    $present[strtolower($header)] = $header;
                }
                $source = 'export payload header row (no result rows)';
            }
        }

        $missing = [];
        foreach ($columns as $column) {
            if (!$this->columnPresent((string) $column, $present)) {
                $missing[] = (string) $column;
            }
        }

        $passed = $missing === [];

        return $this->result('columns_include', $columns, array_values($present), $passed, $passed
            ? ''
            : 'Missing column(s): ' . implode(', ', $missing)
                . ($present === []
                    ? ' (no result rows and no export payload to inspect)'
                    : " (inspected {$source})"));
    }

    /**
     * Whether an expected column concept matches any inspected key: exact
     * (case-insensitive), whole normalized equality, or TOKEN equality —
     * 'name' matches 'user_name' / 'Member Name' because 'name' is one of the
     * key's word tokens. Deliberately NOT free substring matching: 'id' must
     * not pass against 'Paid Amount' ('paidamount' contains 'id') — a gate
     * that can be satisfied by coincidental substrings stops being a gate.
     *
     * @param array<string, string> $present lowercased key => original key
     */
    private function columnPresent(string $expected, array $present): bool
    {
        if (isset($present[strtolower($expected)])) {
            return true;
        }

        $needle = $this->normalizeColumn($expected);
        if ($needle === '') {
            return false;
        }

        foreach ($present as $key) {
            if ($this->normalizeColumn($key) === $needle) {
                return true;
            }
            // Word-token match: split the ORIGINAL key on separators so each
            // token is a whole word ('user_name' → user|name; 'Member Name' →
            // member|name), then require token equality with the concept.
            $tokens = preg_split('/[^a-z0-9]+/i', $key) ?: [];
            foreach ($tokens as $token) {
                if ($token !== '' && strtolower($token) === $needle) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeColumn(string $column): string
    {
        return preg_replace('/[\s_\-]+/', '', strtolower($column)) ?? strtolower($column);
    }

    /**
     * Header cells of the FIRST markdown table in the response's export document
     * payload ([] when the response carries no payload or no table).
     *
     * @return string[]
     */
    private function exportPayloadHeaders(EvalRow $row): array
    {
        return $this->exportPayloadTable($row)['headers'];
    }

    /**
     * The FIRST markdown table in the export document payload, parsed into a
     * header row and data rows. Data-row cells keep their positional alignment
     * with the headers (empty cells preserved — promised_columns_nonempty needs
     * them); the separator row is skipped.
     *
     * @return array{headers: string[], rows: array<int, string[]>}
     */
    private function exportPayloadTable(EvalRow $row): array
    {
        $markdown = (string) ($row->responseMetadata['export_document_markdown'] ?? '');
        $headers = [];
        $rows = [];

        if (trim($markdown) === '') {
            return ['headers' => [], 'rows' => []];
        }

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_starts_with($line, '|')) {
                if ($headers !== []) {
                    break; // table ended
                }
                continue;
            }

            // Positional cells (empty cells preserved for row alignment).
            // Split honors the composer's own \| escaping (escapeMarkdownCell):
            // only UNESCAPED pipes delimit cells, and the escape is undone so
            // cell values compare against the real data.
            $cells = array_map(
                static fn (string $cell) => str_replace('\\|', '|', trim($cell)),
                preg_split('/(?<!\\\\)\|/', trim($line, '|')) ?: []
            );

            $nonEmpty = array_values(array_filter($cells, static fn ($c) => $c !== ''));
            $isSeparator = $nonEmpty !== [] && array_filter($nonEmpty, static fn ($c) => preg_match('/^:?-{3,}:?$/', $c) !== 1) === [];
            if ($isSeparator) {
                continue;
            }

            if ($headers === []) {
                $headers = $nonEmpty;
                continue;
            }

            $rows[] = $cells;
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * HONEST LIMITATION: this gates the export RESPONSE PAYLOAD (export_intent /
     * export_format / export_document_markdown emitted by ExportResponseComposer::
     * generateExportDocumentResponse) — NOT a physical file on disk. Physical
     * rendering happens in the host Kompo runtime (action:// dispatch), which is
     * not available inside the eval harness. The payload IS the pipeline's export
     * contract, so gating it catches every pipeline-side export failure.
     */
    private function gateProducesFile(string $format, EvalRow $row): array
    {
        $meta = $row->responseMetadata;
        $isExport = ($meta['export_intent'] ?? false) === true;
        $actualFormat = $meta['export_format'] ?? null;
        $hasDocument = trim((string) ($meta['export_document_markdown'] ?? '')) !== '';

        $passed = $isExport && $actualFormat === $format && $hasDocument;

        $detail = '';
        if (!$passed) {
            $detail = !$isExport
                ? 'Response is not an export response (no export_intent in response metadata).'
                : ($actualFormat !== $format
                    ? "Export format is '{$actualFormat}', expected '{$format}'."
                    : 'Export response carries no document payload (empty export_document_markdown).');
        }

        return $this->result('produces_file', $format, [
            'export_intent' => $isExport,
            'export_format' => $actualFormat,
            'has_document_payload' => $hasDocument,
        ], $passed, $detail);
    }

    /**
     * Did this turn actually RECEIVE a non-empty last_result_sample in its
     * conversation context? The runner records that evidence at thread-in time,
     * so a broken production writer shows up here as an honest red — the harness
     * never fabricates context production could not produce.
     */
    private function gatePreviousResultAvailable(bool $expected, EvalRow $row): array
    {
        $count = (int) ($row->conversationContextMeta['last_result_sample_count'] ?? 0);
        $available = $count > 0;
        $passed = $available === $expected;

        return $this->result('previous_result_available', $expected, $available, $passed, $passed
            ? ''
            : ($expected
                ? 'Turn received no last_result_sample in its conversation context (previous turn produced/persisted nothing).'
                : "Turn unexpectedly received a last_result_sample of {$count} row(s)."));
    }

    private function gateCitesDocument(bool $expected, EvalRow $row): array
    {
        $count = count($row->referencedFiles);
        $cites = $count > 0;
        $passed = $cites === $expected;

        return $this->result('cites_document', $expected, $count, $passed, $passed
            ? ''
            : ($expected
                ? 'Response carries no file citations (referenced_files empty).'
                : "Response unexpectedly cites {$count} file(s)."));
    }

    /**
     * discloses_total: when more rows exist than were shown to the user
     * (rowCount, or the companion-count result_total from response metadata,
     * exceeds the rendered sample), the answer must contain the EXACT TOTAL as
     * a literal standalone number AND additionally the SHOWN count or the
     * exact REMAINDER (shared StatedNumber::disclosesTruncation — the SAME
     * code path the runtime truncation guard runs, so gate and guard can never
     * disagree). A bare total ("more than 523 results are available") is NOT a
     * disclosure: the reader cannot tell how much was withheld. "Shown" is the
     * generator's own result_shown evidence (the live section maxItems) when
     * present, else the driver's sample size. Trivially (vacuously) passes
     * when nothing was withheld or the gate is declared false.
     */
    private function gateDisclosesTotal(bool $expected, EvalRow $row): array
    {
        $shown = is_numeric($row->responseMetadata['result_shown'] ?? null)
            ? (int) $row->responseMetadata['result_shown']
            : count($row->dataSample);
        $total = max((int) ($row->responseMetadata['result_total'] ?? 0), $row->rowCount);

        if (!$expected || $total <= $shown) {
            return $this->result('discloses_total', $expected, ['total' => $total, 'shown' => $shown], true, '', vacuous: true);
        }

        $remainder = $total - $shown;
        $passed = \Condoedge\Ai\Support\StatedNumber::disclosesTruncation($row->answer, $total, $shown);

        return $this->result(
            'discloses_total',
            $expected,
            ['total' => $total, 'shown' => $shown, 'remainder' => $remainder],
            $passed,
            $passed
                ? ''
                : "Only {$shown} of {$total} rows were shown but the answer does not state the exact total ({$total}) "
                    . "together with the shown count ({$shown}) or the remainder ({$remainder})."
        );
    }

    /**
     * disambiguation_column_present: homonym safety for name-matched entities.
     * The inspected rows — the result sample, or the export payload's table
     * data rows when the turn carries no result sample — must (a) project at
     * least TWO identifying columns and (b) contain no two rows that are fully
     * identical across all columns. Fully identical rows mean the projection
     * lost the distinguishing dimension (three units named "310" rendered as
     * three indistinguishable rows). Sparse Cypher rows are aligned over the
     * full column union before comparison (a missing key equals an explicit
     * null). STRUCTURAL only — column names and cell values are never
     * interpreted. Nothing to inspect (no result sample AND no export payload)
     * passes VACUOUSLY: the suite's standards allow clarify / "none recorded"
     * answer paths that execute no query, and rows-required turns enforce rows
     * via min_rows — the vacuous flag keeps such a green from being read as
     * verified compliance. When rows collide, the failure detail names which.
     */
    private function gateDisambiguationColumnPresent(bool $expected, EvalRow $row): array
    {
        if (!$expected) {
            return $this->result('disambiguation_column_present', $expected, null, true, '', vacuous: true);
        }

        $rows = [];
        $columns = [];
        $source = 'result rows';
        foreach ($row->dataSample as $sampleRow) {
            if (!is_array($sampleRow)) {
                continue;
            }
            $rows[] = $sampleRow;
            foreach (array_keys($sampleRow) as $key) {
                $columns[(string) $key] = true;
            }
        }

        if ($rows === []) {
            ['headers' => $headers, 'rows' => $tableRows] = $this->exportPayloadTable($row);
            if ($headers !== []) {
                $source = 'export payload rows';
                $columns = array_fill_keys($headers, true);
                foreach ($tableRows as $cells) {
                    $assoc = [];
                    foreach (array_values($headers) as $i => $header) {
                        $assoc[$header] = (string) ($cells[$i] ?? '');
                    }
                    $rows[] = $assoc;
                }
            }
        }

        if ($rows === []) {
            // Nothing to inspect is NOT a structural homonym failure: the suite's
            // standards (rubric 6) allow clarify / "none recorded" answer paths
            // that execute no query. Turns that REQUIRE rows enforce that via
            // min_rows. Vacuous — a green here verifies nothing by itself.
            return $this->result('disambiguation_column_present', $expected, ['rows' => 0], true,
                'No result sample and no export payload rows to inspect — nothing verified structurally; '
                . 'a clarify/none-recorded answer path is judged by the semantic standard.', vacuous: true);
        }

        $columnNames = array_keys($columns);
        if (count($columnNames) < 2) {
            return $this->result(
                'disambiguation_column_present',
                $expected,
                ['columns' => $columnNames, 'rows' => count($rows), 'source' => $source],
                false,
                'Rows project only ' . count($columnNames) . ' column (' . implode(', ', $columnNames)
                    . ") — no second identifying column to distinguish same-name rows (inspected {$source})."
            );
        }

        // Align every row over the full column union, then group identical rows.
        // The grouping key is serialize(), which is byte-faithful and can never
        // fail (json_encode returns false on malformed UTF-8, which would
        // collapse every unencodable row into one '' fingerprint and report
        // genuinely different rows as identical). json_encode is kept only for
        // the human-readable detail, with substitution flags so it degrades
        // instead of vanishing.
        $fingerprints = [];
        $display = [];
        foreach ($rows as $index => $assoc) {
            $normalized = [];
            foreach ($columnNames as $name) {
                $normalized[$name] = $assoc[$name] ?? null;
            }
            $key = serialize($normalized);
            $fingerprints[$key][] = $index + 1; // 1-based for humans
            $display[$key] ??= (string) (json_encode(
                $normalized,
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
            ) ?: '[unencodable row]');
        }

        $collisions = array_filter($fingerprints, static fn (array $indexes) => count($indexes) > 1);
        $passed = $collisions === [];

        $detail = '';
        if (!$passed) {
            $parts = [];
            foreach ($collisions as $key => $indexes) {
                $parts[] = 'rows ' . implode(', ', $indexes) . ' are identical (' . $display[$key] . ')';
            }
            $detail = 'No two rows may be fully identical across all ' . count($columnNames)
                . " columns, but (inspected {$source}): " . implode('; ', $parts)
                . ' — the projection cannot distinguish same-name entities.';
        }

        return $this->result(
            'disambiguation_column_present',
            $expected,
            [
                'columns' => $columnNames,
                'rows' => count($rows),
                'source' => $source,
                'identical_row_groups' => count($collisions),
            ],
            $passed,
            $detail
        );
    }

    /**
     * no_self_contact: the answer containing the pinned user's OWN email
     * (literal, case-insensitive) must also contain a "that's-you" marker.
     * Marker wordings come from SelfReferenceNoticeSection::markerPhrases() —
     * the SAME source the response prompt instructs the model with — never a
     * gate-local list. Trivially passes when the email is absent (or no pinned
     * identity exists).
     */
    private function gateNoSelfContact(bool $expected, EvalRow $row): array
    {
        $email = (string) ($row->pinnedUserEmail ?? '');

        if (!$expected || $email === '' || $row->answer === ''
            || mb_stripos($row->answer, $email) === false) {
            return $this->result('no_self_contact', $expected, [
                'email_present' => $email !== '' && $row->answer !== '' && mb_stripos($row->answer, $email) !== false,
            ], true, '', vacuous: true);
        }

        // Typographic normalization (same single source as the runtime guard):
        // U+2019 "that’s you" / "c’est vous" count as compliant markers.
        $answerLower = \Condoedge\Ai\Services\ResponseSections\SelfReferenceNoticeSection::normalizeMarkerText($row->answer);
        $marked = false;
        foreach (\Condoedge\Ai\Services\ResponseSections\SelfReferenceNoticeSection::markerPhrases() as $marker) {
            if ($marker !== '' && mb_strpos($answerLower, $marker) !== false) {
                $marked = true;
                break;
            }
        }

        return $this->result(
            'no_self_contact',
            $expected,
            ['email_present' => true, 'marker_present' => $marked],
            $marked,
            $marked
                ? ''
                : "Answer contains the pinned user's own email without any that's-you marker (self-presented as contact information)."
        );
    }

    /**
     * promised_columns_nonempty: the export payload's table must not promise a
     * header column whose EVERY data cell is empty (or a bare null placeholder —
     * see EMPTY_CELL_VALUES). A missing payload/table, or a table with zero data
     * rows, FAILS honestly: a promised-but-uninspectable export must never look
     * like a pass.
     */
    private function gatePromisedColumnsNonempty(bool $expected, EvalRow $row): array
    {
        if (!$expected) {
            return $this->result('promised_columns_nonempty', $expected, null, true, '', vacuous: true);
        }

        ['headers' => $headers, 'rows' => $rows] = $this->exportPayloadTable($row);

        if ($headers === []) {
            return $this->result('promised_columns_nonempty', $expected, ['headers' => []], false,
                'Response carries no export payload table to inspect.');
        }
        if ($rows === []) {
            return $this->result('promised_columns_nonempty', $expected, ['headers' => $headers, 'data_rows' => 0], false,
                'Export payload table has no data rows — every promised column is empty.');
        }

        $emptyColumns = [];
        foreach ($headers as $index => $header) {
            $allEmpty = true;
            foreach ($rows as $cells) {
                $cell = mb_strtolower(trim((string) ($cells[$index] ?? '')));
                if (!in_array($cell, self::EMPTY_CELL_VALUES, true)) {
                    $allEmpty = false;
                    break;
                }
            }
            if ($allEmpty) {
                $emptyColumns[] = $header;
            }
        }

        $passed = $emptyColumns === [];

        return $this->result(
            'promised_columns_nonempty',
            $expected,
            ['headers' => $headers, 'data_rows' => count($rows), 'empty_columns' => $emptyColumns],
            $passed,
            $passed
                ? ''
                : 'Promised column(s) empty in every row: ' . implode(', ', $emptyColumns) . '.'
        );
    }

    private function gateMaxLatency(int $maxMs, EvalRow $row): array
    {
        // Prefer the turn's wall-clock; fall back to summed stage timings for
        // rows produced outside the conversation runner.
        $actual = $row->totalDurationMs > 0 ? $row->totalDurationMs : array_sum($row->timings);
        $passed = $actual <= $maxMs;

        return $this->result('max_latency_ms', $maxMs, $actual, $passed, $passed
            ? ''
            : "Turn took {$actual}ms, budget {$maxMs}ms.");
    }

    /**
     * $vacuous marks a TRIVIAL pass: the gate's condition never fired, so
     * nothing was actually verified (nothing withheld / email absent / gate
     * declared false). Reconciliation must never treat a vacuous pass as
     * evidence. The key is only present when true, keeping triggered results
     * byte-identical to before.
     *
     * @return array{gate: string, expected: mixed, actual: mixed, passed: bool, detail: string, vacuous?: bool}
     */
    private function result(string $gate, mixed $expected, mixed $actual, bool $passed, string $detail, bool $vacuous = false): array
    {
        $result = [
            'gate' => $gate,
            'expected' => $expected,
            'actual' => $actual,
            'passed' => $passed,
            'detail' => $detail,
        ];
        if ($vacuous) {
            $result['vacuous'] = true;
        }

        return $result;
    }
}
