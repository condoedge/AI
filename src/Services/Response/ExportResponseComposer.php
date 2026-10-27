<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Actions\Attach\RecordResolver;
use Condoedge\Ai\Services\Chat\Exporter\ResultSetExporter;
use Condoedge\Ai\Services\Discovery\EntityLabelIndex;

/**
 * Export Response Composer
 *
 * Owns export/document response composition for the chat pipeline: identity-field
 * projection, markdown-table rendering, localized copy, and the deterministic
 * action:// download link. AiManager delegates to it so the orchestrator stays
 * free of export presentation.
 *
 * Semantics are NOT decided here (Phase 2.B): whether the user asked for a file
 * (and which format) is QueryUnderstandingResult::$exportFormat, and the language
 * of every string is QueryUnderstandingResult::$language, threaded in by
 * AiManager. Copy lives in the package lang JSON (ai.export.*); with no QU
 * language the app locale applies (same policy as the error responses) — there
 * is no word-list language guess.
 *
 * @package Condoedge\Ai\Services\Response
 */
class ExportResponseComposer
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function previousResultSample(PipelineContext $ctx, array $options): array
    {
        $conversation = is_array($ctx->conversationContext) ? $ctx->conversationContext : [];
        if (empty($conversation) && is_array($options['conversation_context'] ?? null)) {
            $conversation = $options['conversation_context'];
        }

        $sample = $conversation['last_result_sample'] ?? [];
        return is_array($sample) ? array_values(array_filter($sample, 'is_array')) : [];
    }

    /**
     * Build a one-row export source from the current identity context.
     *
     * This is the general fallback for possessive export requests when no query
     * rows exist. It is driven by QU structure and config, not phrase matching:
     * hosts can map these fields to User, Contact, Person, Team, or any other
     * auth/profile representation through ai.export.identity_fields.
     *
     * @return array<int, array<string, mixed>>
     */
    public function identityExportRows(PipelineContext $ctx, array $options): array
    {
        if (!$this->canUseIdentityExportSource($ctx)) {
            return [];
        }

        $sources = [
            'user' => $options['user'] ?? $this->currentAuthUser(),
            'team' => $options['team'] ?? $this->currentTeam(),
        ];

        $row = [];
        $configuredAttributeKeys = [];
        foreach ($this->identityExportFields() as $field) {
            $label = $field['label'] ?? null;
            $paths = $field['path'] ?? null;
            $this->rememberConfiguredIdentityPaths($configuredAttributeKeys, $paths);

            if (!is_string($label) || trim($label) === '') {
                continue;
            }

            $value = $this->firstConfiguredValue($sources, $paths);
            if ($value === null || $value === '') {
                continue;
            }

            $row[$label] = $value;
        }

        foreach ($this->autoIdentityExportFields($sources, array_keys($row), $configuredAttributeKeys) as $label => $value) {
            $row[$label] = $value;
        }

        return $row === [] ? [] : [$row];
    }

    private function canUseIdentityExportSource(PipelineContext $ctx): bool
    {
        // Personal possession only: "export OUR roster" is the team's data, not the user's identity.
        if (!$ctx->qu->isPersonalPossessive()) {
            return false;
        }

        $identityLabels = array_map('strtolower', $this->configuredIdentityLabels());
        $entityTypes = array_map('strtolower', $ctx->qu->entityTypes);

        if (empty($entityTypes)) {
            return true;
        }

        foreach ($entityTypes as $label) {
            if (!in_array($label, $identityLabels, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string[]
     */
    private function configuredIdentityLabels(): array
    {
        $labels = config('ai.semantic_context.always_include_identity_labels', ['User', 'Team']);
        if (!is_array($labels)) {
            return ['User', 'Team'];
        }

        return array_values(array_filter(array_map(
            static fn($label) => is_string($label) ? trim($label) : '',
            $labels
        )));
    }

    /**
     * @return array<int, array{label: string, path: string|array<int, string>}>
     */
    private function identityExportFields(): array
    {
        $fields = config('ai.export.identity_fields', [
            ['label' => 'User Name', 'path' => 'user.name'],
            ['label' => 'User Email', 'path' => 'user.email'],
            ['label' => 'Team Name', 'path' => ['team.team_name', 'team.name']],
        ]);

        return is_array($fields) ? $fields : [];
    }

    /**
     * @param array<string, array<string, true>> $configuredAttributeKeys
     * @param string|array<int, string>|null $paths
     */
    private function rememberConfiguredIdentityPaths(array &$configuredAttributeKeys, string|array|null $paths): void
    {
        foreach ((array) $paths as $path) {
            if (!is_string($path) || !str_contains($path, '.')) {
                continue;
            }

            $segments = explode('.', $path);
            $source = array_shift($segments);
            $attribute = array_pop($segments);

            if (is_string($source) && is_string($attribute) && $source !== '' && $attribute !== '') {
                $configuredAttributeKeys[$source][strtolower($attribute)] = true;
            }
        }
    }

    /**
     * Add safe scalar fields from the current identity objects. This lets host
     * apps expose richer own-profile exports without changing package code: if a
     * User has phone/address/etc., it appears; internal/security fields do not.
     *
     * @param array<string, mixed> $sources
     * @param string[] $existingLabels
     * @param array<string, array<string, true>> $configuredAttributeKeys
     * @return array<string, mixed>
     */
    private function autoIdentityExportFields(array $sources, array $existingLabels, array $configuredAttributeKeys): array
    {
        if (!(bool) config('ai.export.auto_identity_fields.enabled', true)) {
            return [];
        }

        $sourceLabels = config('ai.export.auto_identity_fields.sources', [
            'user' => 'User',
            'team' => 'Team',
        ]);
        if (!is_array($sourceLabels)) {
            return [];
        }

        $seen = array_fill_keys(array_map('strtolower', $existingLabels), true);
        $fields = [];

        foreach ($sourceLabels as $sourceKey => $sourceLabel) {
            if (!is_string($sourceKey) || !array_key_exists($sourceKey, $sources)) {
                continue;
            }

            $sourceLabel = is_string($sourceLabel) && trim($sourceLabel) !== ''
                ? trim($sourceLabel)
                : $this->humanizeHeader($sourceKey);

            foreach ($this->scalarAttributes($sources[$sourceKey]) as $key => $value) {
                if (isset($configuredAttributeKeys[$sourceKey][strtolower((string) $key)])) {
                    continue;
                }

                if ($this->shouldSkipIdentityAttribute($key, $value)) {
                    continue;
                }

                $label = $this->identityAttributeLabel($sourceLabel, (string) $key);
                $labelKey = strtolower($label);
                if (isset($seen[$labelKey])) {
                    continue;
                }

                $fields[$label] = $value;
                $seen[$labelKey] = true;
            }
        }

        return $fields;
    }

    private function identityAttributeLabel(string $sourceLabel, string $key): string
    {
        $fieldLabel = $this->humanizeHeader($key);
        $sourceLower = strtolower($sourceLabel);
        $fieldLower = strtolower($fieldLabel);

        if ($fieldLower === $sourceLower || str_starts_with($fieldLower, $sourceLower . ' ')) {
            return $fieldLabel;
        }

        return trim($sourceLabel . ' ' . $fieldLabel);
    }

    /**
     * @return array<string, mixed>
     */
    private function scalarAttributes(mixed $source): array
    {
        if ($source === null) {
            return [];
        }

        if (is_array($source)) {
            return $source;
        }

        if (is_object($source) && method_exists($source, 'toArray')) {
            try {
                $array = $source->toArray();
                if (is_array($array)) {
                    return $array;
                }
            } catch (\Throwable $e) {
                // Fall through to public properties.
            }
        }

        return is_object($source) ? get_object_vars($source) : [];
    }

    private function shouldSkipIdentityAttribute(string|int $key, mixed $value): bool
    {
        if (!is_scalar($value) || $value === '') {
            return true;
        }

        $key = (string) $key;
        $lower = strtolower($key);

        $excluded = config('ai.export.auto_identity_fields.exclude', [
            'id',
            'password',
            'remember_token',
            'api_token',
            'token',
            'secret',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'created_at',
            'updated_at',
            'deleted_at',
            'email_verified_at',
        ]);

        if (is_array($excluded) && in_array($lower, array_map('strtolower', array_map('strval', $excluded)), true)) {
            return true;
        }

        foreach ((array) config('ai.export.internal_patterns', ['/_id$/i', '/^_/']) as $pattern) {
            if (is_string($pattern) && @preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $sources
     * @param string|array<int, string>|null $paths
     */
    private function firstConfiguredValue(array $sources, string|array|null $paths): mixed
    {
        foreach ((array) $paths as $path) {
            if (!is_string($path) || trim($path) === '') {
                continue;
            }

            $value = function_exists('data_get') ? data_get($sources, $path) : null;
            if ($value === null || $value === '') {
                continue;
            }

            if (is_scalar($value)) {
                return $value;
            }
        }

        return null;
    }

    private function currentAuthUser(): mixed
    {
        try {
            if (function_exists('auth') && function_exists('app') && app()->bound('auth')) {
                return auth()->user();
            }
        } catch (\Throwable $e) {
            // No authenticated runtime available.
        }

        return null;
    }

    private function currentTeam(): mixed
    {
        try {
            if (function_exists('safeCurrentTeam')) {
                return safeCurrentTeam();
            }
        } catch (\Throwable $e) {
            // No team helper/runtime available.
        }

        return null;
    }

    /**
     * Rows a generated document may carry (and an export re-execution fetches):
     * ai.export.document_row_limit; a PDF document is further capped by
     * ai.export.pdf_document_row_limit (unset = the document limit). The PDF
     * renderer (dompdf) lays the whole table out in memory and grows faster than
     * linearly — measured on a host (3 columns): 523 rows ~10.6 s / 104 MB peak,
     * 1000 rows ~28 s / 194 MB — while the .xlsx writer handles 2000 rows in
     * ~1 s / ~24 MB. A host can thus cover a full roster in Excel without
     * exposing the PDF path to the same row count.
     */
    public static function documentRowLimit(?string $format = null): int
    {
        $limit = max(1, (int) config('ai.export.document_row_limit', 250));
        $pdfLimit = config('ai.export.pdf_document_row_limit');
        if ($format === 'pdf' && is_numeric($pdfLimit) && (int) $pdfLimit > 0) {
            return min($limit, (int) $pdfLimit);
        }

        return $limit;
    }

    /**
     * @param array{format: string} $intent
     * @param array<int, array<string, mixed>> $rows
     * @param array{complete?: bool, total_rows?: int, entity?: ?string, language?: ?string} $sourceMeta
     *        Optional source metadata threaded by AiManager: whether $rows is the
     *        COMPLETE result set or a sample fallback, the total row count of the
     *        underlying result (for honest "showing X of Y" disclosure), the
     *        focused entity (document title subject) and the QU-detected question
     *        language (language policy). All keys optional — defaults preserve
     *        the previous behavior.
     */
    public function generateExportDocumentResponse(
        string $question,
        array $intent,
        array $rows,
        string $source,
        array $sourceMeta = []
    ): array {
        $format = $intent['format'];
        $language = $this->normalizeLanguage($sourceMeta['language'] ?? null);
        $entity = is_string($sourceMeta['entity'] ?? null) && trim((string) $sourceMeta['entity']) !== ''
            ? (string) $sourceMeta['entity']
            : null;
        // Title/filename basis: the distilled subject when available (short,
        // content-only — "team members details"), never the raw question echo
        // ("Can you give me an excel with..."), which made ugly filenames.
        // For previous-result exports the question is BANNED as a fallback —
        // a subject-less follow-up ("Ok, give me the excel") must fall through
        // to the entity label / generic title, never echo itself.
        // A distilled subject is the WHOLE title: the entity prefix (a raw schema
        // label, in the code's language) only qualifies the question-echo / entity
        // fallbacks — before a localized subject it made a mixed-language heading
        // ("User — Rôles des membres de l'équipe", eval p12-B).
        if (is_string($sourceMeta['subject'] ?? null) && trim((string) $sourceMeta['subject']) !== '') {
            $subjectSource = trim((string) $sourceMeta['subject']);
            $entity = null;
        } elseif ($source === 'previous_result') {
            $subjectSource = '';
        } else {
            $subjectSource = $question;
        }
        $title = $source === 'current_identity'
            ? $this->t('ai.export.identity_title', [], $language)
            : $this->exportDocumentTitle($subjectSource, $language, $entity);

        $redactedRows = (new ResultSetExporter())->redact(
            $rows,
            $source === 'current_identity' ? [] : null
        );
        $redactedRows = array_values(array_filter($redactedRows, fn ($row) => is_array($row) && !empty($row)));

        if (empty($redactedRows)) {
            // Thread the already-resolved (QU) language to the clarification.
            return $this->generateExportClarificationResponse($question, $intent, $language);
        }

        $rowCount = count($redactedRows);
        // The total the user's underlying result actually holds: >= $rowCount when
        // the rows passed in are a sample fallback (complete === false).
        $totalRows = max((int) ($sourceMeta['total_rows'] ?? $rowCount), $rowCount);
        $documentRows = array_slice($redactedRows, 0, self::documentRowLimit($format));
        // THE FILE SHOWS WHAT THE SCREEN SHOWED (eval p17: A roster-excel, B resilience t3,
        // B french t1 — the chat table read "Account manager" / "Gestionnaire", the .xlsx held
        // `manager` / `contact`): coded values the host declares a legend for are written as
        // their LABEL, in the answer's language — the same mapping, over the same source
        // query, as the chat table (DataAnswerPlan::build), a fan-out-collapsed "a; b" cell
        // included. The rows of a previous-result export were re-executed from the RECORDED
        // query, which is the cypher threaded here. Row keys and columns never change; an
        // identity export has no query, so nothing is mapped.
        $cypher = is_string($sourceMeta['cypher'] ?? null) ? $sourceMeta['cypher'] : null;
        if ($cypher !== null && $source !== 'current_identity') {
            try {
                $documentRows = CodedColumns::labelled($documentRows, $cypher, $language);
            } catch (\Throwable $e) {
                // display nicety only: the raw values stand
            }
        }
        // …MONEY INCLUDED (eval p18-A "Create a PDF of this" after the order-310 table: the chat
        // table read "$0.00", the PDF a raw `0`). A column the source query projects from a
        // DECLARED money property (MoneyColumns — the chat table's own rule) is written as
        // money, in the answer's locale. Only in a PDF: it is rendered from the document as
        // text. An EXCEL file keeps its numbers — the .xlsx writer types a numeric cell as a
        // number and carries no number format (ResultSetExporter::writeTypedCell), so a
        // "$1,211.48" string would land as text and no longer add up in a spreadsheet.
        if ($format === 'pdf' && $cypher !== null && $source !== 'current_identity') {
            try {
                $documentRows = $this->withMoneyFormatted($documentRows, MoneyColumns::fromCypher($cypher), $language);
            } catch (\Throwable $e) {
                // display nicety only: the raw values stand
            }
        }
        // …AND A PERSON'S NAME IN ONE COLUMN (eval p19-A board: "First Name | Last Name"). The
        // chat table merges the columns of an entity's declared name (metadata.name_properties,
        // all projected from one node variable — TableRenderer::nameMerges, the chat table's own
        // rule over the same source query); the file's rows follow the SAME merge, key included,
        // so its header row, its document table and "Fields included" match the screen.
        $nameMerges = [];
        if ($cypher !== null && $source !== 'current_identity') {
            try {
                $nameMerges = TableRenderer::nameMerges($cypher, $documentRows);
                $documentRows = (new TableRenderer())->mergeColumns($documentRows, $nameMerges);
            } catch (\Throwable $e) {
                $nameMerges = []; // display nicety only: the query's own columns stand
            }
        }
        // A column empty in EVERY row of the file carries no information, yet it
        // would be promised under "Fields included" and written into the header
        // (eval p9 cold-roster: "Contact Reduced Mobility", never populated).
        [$documentRows, $emptyColumns] = $this->withoutEmptyColumns($documentRows);
        $shownRows = count($documentRows);

        // What each column is CALLED in the file (eval p17-B cold-roster-excel: 523 emails
        // under "Contact Info"): the source query's plain property projections head a
        // column by the property it holds when its alias says something else — the same
        // rule, and the same collision handling, as the chat table (TableRenderer::headers).
        // The row keys are untouched; the headers ride beside them for the .xlsx writer.
        // IN THE ANSWER'S LANGUAGE (eval p18-B french t1: "Champs inclus : Name, Email, Role." and
        // English headers in a French file, the generator having aliased in English): each
        // humanized header goes through the HOST's translator for the answer's locale — the
        // chat table's rule again — and stays as it is when the host ships no line for it.
        $projections = TableRenderer::headerProjections($cypher, $language);
        $headerLocale = $this->headerLocale($language);
        $renderer = new TableRenderer();
        $columns = $renderer->columns($documentRows);
        $headers = $columns === []
            ? []
            : array_combine($columns, $renderer->headers($columns, [], TableRenderer::variableLabels($cypher), $projections, $headerLocale));

        $table = $this->markdownTable($documentRows, $headers);
        $asOfLine = $this->t('ai.export.as_of', ['date' => $this->asOfDate()], $language);
        $documentMarkdown = '# ' . $title . "\n\n" . $asOfLine . "\n\n" . $table;
        // Honest disclosure IN THE PAYLOAD too, whenever the document shows fewer
        // rows than the underlying result holds (trimmed or sample fallback) —
        // or when the source hit the executor's row cap, where the true total is
        // unknown (complete=false with total==shown): say "more may exist".
        $capped = ($sourceMeta['complete'] ?? true) === false && $totalRows <= $shownRows;
        $counts = ['shown' => $shownRows, 'total' => $totalRows];
        if ($totalRows > $shownRows) {
            $documentMarkdown .= "\n\n" . $this->t('ai.export.document_showing_of', $counts, $language);
        } elseif ($capped) {
            $documentMarkdown .= "\n\n" . $this->t('ai.export.document_showing_first', $counts, $language);
        }
        $fieldSummary = $this->exportFieldSummary(
            array_map(static fn ($key): string => $headers[(string) $key] ?? (string) $key, array_keys($documentRows[0] ?? [])),
            $language
        );
        if ($emptyColumns !== []) {
            $emptySummary = $this->t('ai.export.empty_fields_omitted', [
                'fields' => implode(', ', array_map(
                    fn (string $c): string => $renderer->translateHeader($renderer->label($c, [], $projections), $headerLocale),
                    $emptyColumns
                )),
            ], $language);
            $fieldSummary = $fieldSummary === '' ? $emptySummary : $fieldSummary . "\n\n" . $emptySummary;
        }
        // Columns left out for having no values are already disclosed above —
        // they are not "requested fields not included".
        $emptyKeys = array_map(static fn (string $c): string => strtolower(trim($c)), $emptyColumns);
        $missingSummary = $this->missingRequestedFieldsSummary(
            array_values(array_filter(
                is_array($sourceMeta['requested_fields'] ?? null) ? $sourceMeta['requested_fields'] : [],
                static fn ($f): bool => !is_string($f) || !in_array(strtolower(trim($f)), $emptyKeys, true)
            )),
            // (A name part merged into the file's name column IS in the file.)
            [...$documentRows, ...$this->mergedNameParts($nameMerges, $sourceMeta)],
            $language,
            // The requested fields are the FRESH query's columns: named through its projections.
            is_string($sourceMeta['requested_cypher'] ?? null)
                ? TableRenderer::headerProjections($sourceMeta['requested_cypher'], $language)
                : $projections,
            $headerLocale
        );
        if ($missingSummary !== '') {
            $fieldSummary = $fieldSummary === '' ? $missingSummary : $fieldSummary . "\n\n" . $missingSummary;
        }

        $label = $this->t($format === 'excel' ? 'ai.export.download_excel' : 'ai.export.download_pdf', [], $language);
        $action = $format === 'excel' ? 'export_excel' : 'export_pdf';
        $link = '[' . $label . '](action://' . $action . '?mode=document&title=' . rawurlencode($title) . ')';

        // THE SENTENCE STATES WHAT THE FILE HOLDS (eval p20: "I prepared the Excel file with the
        // available information." above a complete 523-row file — a hedge, and no row count —
        // on A roster-excel / order-310 PDF and B resilience t3 / cold-roster / french t1).
        // One code-written sentence, by the three cases the counts establish: the WHOLE result
        // ("with all 523 members" — the host's noun when the rows are proven to be that many
        // records of one kind, else "rows"); a file CUT at the document limit or built from a
        // sample ("the first 250 of 523 rows"); a source that hit the executor's cap with no
        // known total ("the first 100 rows — more may exist"). A one-row file is no list: no count.
        $answer = $this->preparedSentence(
            $format,
            $shownRows,
            $totalRows,
            $capped,
            $totalRows > $shownRows || $capped || $source === 'current_identity'
                ? null
                : $this->countedKind($rows, $cypher, $sourceMeta, $language, $shownRows),
            $language
        );
        if ($fieldSummary !== '') {
            $answer .= "\n\n" . $fieldSummary;
        }

        $answer .= "\n\n" . $link;

        return [
            'answer' => $answer,
            'format' => 'markdown',
            'referenced_files' => [],
            'metadata' => [
                'export_intent' => true,
                'export_format' => $format,
                'export_source' => $source,
                'export_source_complete' => (bool) ($sourceMeta['complete'] ?? true),
                'export_document_markdown' => $documentMarkdown,
                // The SAME rows as structured data: the Excel document export writes
                // them directly (typed cells) instead of re-parsing the markdown.
                'export_document_rows' => $documentRows,
                // Column key => the header the file gives it (the document table's own
                // header row): the .xlsx writer heads the rows with it.
                'export_document_headers' => $headers,
                'result_count' => $rowCount,
                'result_total' => $totalRows,
            ],
        ];
    }

    /**
     * The export answer's opening sentence (package lang, ai.export.prepared_<format>[_…]):
     *
     *   - `_partial` — the file holds fewer rows than the result: "the first :shown of :total rows";
     *   - `_first`   — the source hit the executor's row cap, total unknown: "the first :shown
     *                  rows — more may exist";
     *   - `_kind` / `_rows` — the WHOLE result, two rows or more: "all :count :kind" in the
     *                  host's noun ({@see countedKind()}), else "all :count rows";
     *   - the bare line — one row (a record, the user's own profile).
     *
     * Never a hedge: each line says exactly what the counts establish. A host lang override
     * that predates a variant falls back to the bare line.
     */
    private function preparedSentence(string $format, int $shown, int $total, bool $capped, ?string $kind, ?string $language): string
    {
        $base = $format === 'excel' ? 'ai.export.prepared_excel' : 'ai.export.prepared_pdf';
        [$variant, $replace] = match (true) {
            $total > $shown => ['_partial', ['shown' => $shown, 'total' => $total]],
            $capped => ['_first', ['shown' => $shown]],
            $shown < 2 => ['', []],
            $kind !== null => ['_kind', ['count' => $shown, 'kind' => $kind]],
            default => ['_rows', ['count' => $shown]],
        };

        if ($variant !== '') {
            $line = $this->t($base . $variant, $replace, $language);
            if ($line !== $base . $variant) {
                return $line;
            }
        }

        return $this->t($base, [], $language);
    }

    /**
     * The plural the HOST gives the records a complete file's rows ARE, in the answer's language
     * — only when that is PROVEN, never guessed (the chat table's own rule,
     * DataAnswerPlan::recordKindOf()):
     *
     *   - the source's companion count counted DISTINCT records of its anchor (sourceMeta
     *     anchor_total / anchor_label) and that count IS the row count; a fan-out (fewer
     *     records than rows) is counted in rows;
     *   - else every row carries ONE record of the SAME entity, all different (RecordResolver
     *     over the ids the source query projects — the chips' source of truth).
     *
     * Null when the kind is unknown, the host declares no plural for it
     * (metadata.display_name_plural) or cannot say it in that language: the sentence then
     * counts "rows". Never breaks an export.
     *
     * @param array<int, mixed> $rows the rows as received (ids included)
     * @param array<string, mixed> $sourceMeta
     */
    private function countedKind(array $rows, ?string $cypher, array $sourceMeta, ?string $language, int $count): ?string
    {
        if ($count < 2) {
            return null;
        }

        try {
            $anchorTotal = $sourceMeta['anchor_total'] ?? null;
            if (is_numeric($anchorTotal)) {
                $label = (int) $anchorTotal === $count && is_string($sourceMeta['anchor_label'] ?? null) ? trim($sourceMeta['anchor_label']) : '';
            } else {
                $rows = array_values(array_filter($rows, 'is_array'));
                $labels = array_values(array_unique(TableRenderer::variableLabels($cypher)));
                if ($cypher === null || $labels === [] || count($rows) !== $count) {
                    return null;
                }
                $preferred = is_string($sourceMeta['entity'] ?? null) ? [$sourceMeta['entity']] : [];
                $records = (new RecordResolver($labels, $cypher, $preferred))->resolve($rows);
                if (count($records) !== $count) {
                    return null;
                }
                $identities = [];
                foreach ($records as $record) {
                    $identities[(string) ($record['label'] ?? '')][(string) ($record['id'] ?? '')] = true;
                }
                $label = (string) array_key_first($identities);
                if (count($identities) !== 1 || count($identities[$label]) !== $count) {
                    return null;
                }
            }
            if ($label === '') {
                return null;
            }

            $config = EntityLabelIndex::shared()->configForLabel($label) ?? [];

            return HostText::inLanguage($config['metadata']['display_name_plural'] ?? $config['display_name_plural'] ?? null, $language);
        } catch (\Throwable $e) {
            return null; // a wording nicety — the file is counted in rows
        }
    }

    /**
     * Drop every column that is empty (null, or a blank string) in ALL rows —
     * purely data-driven, no column names involved. 0 / false / "0" are values.
     * A row missing the key counts as empty for it. When every column is empty
     * the rows are returned untouched (nothing meaningful to drop down to).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>} [rows, dropped column keys in first-seen order]
     */
    private function withoutEmptyColumns(array $rows): array
    {
        $hasValue = [];
        foreach ($rows as $row) {
            foreach ($row as $key => $value) {
                $hasValue[$key] = ($hasValue[$key] ?? false)
                    || !($value === null || (is_string($value) && trim($value) === '') || $value === []);
            }
        }

        $empty = array_keys(array_filter($hasValue, static fn (bool $v): bool => !$v));
        if ($empty === [] || count($empty) === count($hasValue)) {
            return [$rows, []];
        }

        $drop = array_fill_keys($empty, true);
        $rows = array_map(static fn (array $row): array => array_diff_key($row, $drop), $rows);

        return [$rows, array_map('strval', $empty)];
    }

    /**
     * The requested-field names a merged name column of the file covers, as one pseudo-row
     * (keys only) for {@see missingRequestedFieldsSummary()}: the parts' columns in the file's
     * own query, and — the requested fields being the FRESH query's columns — the columns that
     * query projects from the same properties of the same entity.
     *
     * @param list<array{key: string, columns: list<string>, label: string, properties: list<string>}> $nameMerges
     * @param array<string, mixed> $sourceMeta
     * @return list<array<string, true>> [] or one row
     */
    private function mergedNameParts(array $nameMerges, array $sourceMeta): array
    {
        if ($nameMerges === []) {
            return [];
        }

        $requested = is_string($sourceMeta['requested_cypher'] ?? null)
            ? TableRenderer::columnProjections($sourceMeta['requested_cypher'])
            : [];
        $parts = [];
        foreach ($nameMerges as $merge) {
            foreach ($merge['columns'] as $column) {
                $parts[$column] = true;
            }
            foreach ($requested as $column => [, $property, $label]) {
                if ($label === $merge['label'] && in_array($property, $merge['properties'], true)) {
                    $parts[(string) $column] = true;
                }
            }
        }

        return [$parts];
    }

    /**
     * $rows with the DECLARED money columns written as money ({@see TableRenderer::formatMoney()}:
     * the configured currency symbol, two decimals, $locale's grouping) — the cell a chat table
     * shows for the same value. Only a numeric value is formatted (null, a flag and text stay);
     * keys and every other column are untouched.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $moneyColumns result columns holding a declared money property
     * @return array<int, array<string, mixed>>
     */
    private function withMoneyFormatted(array $rows, array $moneyColumns, ?string $locale): array
    {
        if ($moneyColumns === [] || $rows === []) {
            return $rows;
        }

        $renderer = new TableRenderer();
        foreach ($rows as $i => $row) {
            foreach ($moneyColumns as $column) {
                $value = $row[$column] ?? null;
                if ($value !== null && !is_bool($value) && $renderer->isNumericValue($value)) {
                    $rows[$i][$column] = $renderer->formatMoney($value, $locale);
                }
            }
        }

        return $rows;
    }

    /**
     * The language the file's headers are said in: the QU language, else the app locale (the
     * package's language policy) — always a locale, so a host line for a header applies.
     */
    private function headerLocale(?string $language): ?string
    {
        if ($language !== null && trim($language) !== '') {
            return $language;
        }

        try {
            $locale = function_exists('app') ? (string) app()->getLocale() : '';
        } catch (\Throwable $e) {
            $locale = '';
        }

        return $locale !== '' ? $locale : null;
    }

    /** ISO-8601 date for the document's "as of" line. */
    private function asOfDate(): string
    {
        try {
            if (function_exists('now')) {
                return now()->toDateString();
            }
        } catch (\Throwable $e) {
            // Fall through to native date.
        }

        return date('Y-m-d');
    }

    /**
     * @param string[] $fields the file's column HEADERS (as its table shows them)
     */
    private function exportFieldSummary(array $fields, ?string $language): string
    {
        $fields = array_values(array_filter(
            array_map(static fn ($field): string => (string) $field, $fields),
            fn ($field) => trim($field) !== ''
        ));
        if (empty($fields)) {
            return '';
        }

        $visible = array_slice($fields, 0, 8);
        $hidden = count($fields) - count($visible);

        return $hidden > 0
            ? $this->t('ai.export.fields_included_more', ['fields' => implode(', ', $visible), 'count' => $hidden], $language)
            : $this->t('ai.export.fields_included', ['fields' => implode(', ', $visible)], $language);
    }

    /**
     * Honesty line for requested fields absent from the file. "Requested" is
     * structural — the columns THIS turn's fresh query produced for the ask
     * (threaded by AiManager as sourceMeta.requested_fields) — never a word list.
     * Internal id-ish columns are ignored (they are never exported); a requested
     * column dropped by the export deny-list or absent from the exported source
     * is named, so "Fields included" never reads as the complete ask.
     *
     * @param array<int, mixed> $requested
     * @param array<int, array<string, mixed>> $documentRows
     * @param array<string, array{0: string, 1: string}> $projections the requesting query's plain
     *        property projections (TableRenderer::headerProjections): a missing field is named by
     *        the property it would have held when its alias says something else
     * @param string|null $headerLocale the language field names are said in (the file's headers')
     */
    private function missingRequestedFieldsSummary(array $requested, array $documentRows, ?string $language, array $projections = [], ?string $headerLocale = null): string
    {
        // Requested fields arrive lower-cased (AiManager::resultColumns); aliases keep their case.
        $projections += array_change_key_case($projections, CASE_LOWER);
        $renderer = new TableRenderer();

        $requested = array_values(array_filter($requested, fn ($f) => is_string($f) && trim($f) !== ''));
        if ($requested === []) {
            return '';
        }

        // Internal-only filter (no PII deny-list): a PII-redacted requested field
        // IS missing from the file and must be disclosed.
        $exportable = array_keys((new ResultSetExporter())->redact([array_fill_keys($requested, 1)], [])[0] ?? []);

        $exported = [];
        foreach ($documentRows as $row) {
            foreach (array_keys($row) as $key) {
                $exported[strtolower(trim((string) $key))] = true;
            }
        }

        $missing = [];
        foreach ($exportable as $field) {
            if (!isset($exported[strtolower(trim((string) $field))])) {
                $missing[] = $renderer->translateHeader($renderer->label((string) $field, [], $projections), $headerLocale);
            }
        }

        if ($missing === []) {
            return '';
        }

        return $this->t('ai.export.requested_fields_missing', ['fields' => implode(', ', $missing)], $language);
    }

    /**
     * @param array{format: string} $intent
     * @param string|null $language QU-detected question language (ISO 639-1);
     *        null/'' = the app locale (no word-list language guess).
     */
    public function generateExportClarificationResponse(string $question, array $intent, ?string $language = null): array
    {
        $format = strtoupper($intent['format']);
        $language = $this->normalizeLanguage($language);

        $answer = $this->t('ai.export.clarification', ['format' => $format], $language);

        return [
            'answer' => $answer,
            'format' => 'text',
            'referenced_files' => [],
            'metadata' => [
                'export_intent' => true,
                'export_format' => $intent['format'],
                'needs_export_source' => true,
                'result_count' => 0,
            ],
        ];
    }

    /**
     * Derive the document title from the SUBJECT of the request: the focused
     * entity (when the pipeline knows one) plus the user's own question, cleaned
     * for use as a heading. Deliberately config-free and semantics-free — no
     * keyword lists — so it stays correct for any host domain; a bare generic
     * fallback only remains for an empty question.
     */
    private function exportDocumentTitle(string $question, ?string $language, ?string $entity = null): string
    {
        $subject = $this->questionAsTitle($question);
        $entityLabel = $entity !== null ? $this->humanizeHeader($entity) : '';

        if ($entityLabel !== '' && $subject !== '') {
            // Avoid "Order — ... order ..." stutter when the question already names the entity.
            if (stripos($subject, $entityLabel) !== false) {
                return $subject;
            }

            return $entityLabel . ' — ' . $subject;
        }

        if ($subject !== '') {
            return $subject;
        }

        if ($entityLabel !== '') {
            return $entityLabel;
        }

        return $this->t('ai.export.default_title', [], $language);
    }

    /** The question cleaned into a heading: collapsed whitespace, no trailing punctuation, bounded length. */
    private function questionAsTitle(string $question): string
    {
        $title = trim(preg_replace('/\s+/', ' ', $question) ?? $question);
        $title = rtrim($title, " \t.?!,;:");
        if ($title === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($title, 'UTF-8') > 80) {
                $title = rtrim(mb_substr($title, 0, 77, 'UTF-8')) . '...';
            }
            $first = mb_substr($title, 0, 1, 'UTF-8');
            return mb_strtoupper($first, 'UTF-8') . mb_substr($title, 1, null, 'UTF-8');
        }

        if (strlen($title) > 80) {
            $title = rtrim(substr($title, 0, 77)) . '...';
        }

        return ucfirst($title);
    }

    /**
     * The document table — THE TableRenderer (Phase 8 batch A; the former private
     * markdownTable/stringValue/escapeMarkdownCell copies are gone). Rows arrive
     * already redacted and sliced to the document row limit.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $headers column key => header (already told apart)
     */
    private function markdownTable(array $rows, array $headers = []): string
    {
        return (new TableRenderer())->render($rows, ['max_rows' => 0, 'redact' => false, 'labels' => $headers])['markdown'];
    }

    private function humanizeHeader(string $header): string
    {
        return (new TableRenderer())->label($header);
    }

    /**
     * The QU language code as a translator locale ('fr', 'en', ...), or null to
     * use the app locale. Structural normalization only — no language guess.
     */
    private function normalizeLanguage(mixed $language): ?string
    {
        if (!is_string($language) || trim($language) === '') {
            return null;
        }

        return strtolower(trim($language));
    }

    /**
     * Localized export copy (package lang JSON, ai.export.*). An unknown locale
     * falls back through the translator's fallback locale.
     *
     * @param array<string, scalar> $replace
     */
    private function t(string $key, array $replace, ?string $language): string
    {
        $line = trans($key, $replace, $language);

        return is_string($line) ? $line : $key;
    }
}
