<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Chat\Exporter\ResultSetExporter;

/**
 * Export Response Composer
 *
 * Owns export/document response composition for the chat pipeline: export-intent
 * detection, identity-field projection, markdown-table rendering, FR/EN copy, and
 * the deterministic action:// download link. AiManager delegates to it so the
 * orchestrator stays free of export presentation.
 *
 * @package Condoedge\Ai\Services\Response
 */
class ExportResponseComposer
{
    /**
     * Detect explicit document/export requests before the no-source fallback gets a chance to
     * turn them into "no results" prose. This is intentionally small and deterministic: the
     * action layer is hardcoded/sensitive by design, so export/document intent should not depend
     * on the LLM obeying a hint in the abstention prompt.
     *
     * @return array{format: string}|null
     */
    public function detectExportIntent(string $question): ?array
    {
        $q = $this->foldedLower($question);

        $mentionsPdf = preg_match('/\bpdf\b|document\s+pdf|fichier\s+pdf/u', $q) === 1;
        $mentionsExcel = preg_match('/\b(excel|xlsx|spreadsheet)\b|feuille\s+de\s+calcul|tableur/u', $q) === 1;

        if (!$mentionsPdf && !$mentionsExcel) {
            return null;
        }

        $hasActionVerb = preg_match(
            '/\b(create|make|generate|export|download|prepare|convert|turn|build|give)\b'
            . '|creer|generer|exporter|telecharger|preparer|convertir|faire/u',
            $q
        ) === 1;

        $hasDocumentShape = preg_match('/\b(as|to|in|into|of)\s+(a\s+)?(pdf|excel|xlsx)\b|en\s+(pdf|excel)|de\s+(mes?|mon|ma|votre|notre)/u', $q) === 1;

        if (!$hasActionVerb && !$hasDocumentShape) {
            return null;
        }

        return ['format' => $mentionsExcel ? 'excel' : 'pdf'];
    }

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
        if (!$ctx->qu->isPossessive) {
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

    private function exportDocumentRowLimit(): int
    {
        return max(1, (int) config('ai.export.document_row_limit', 250));
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
        $language = is_string($sourceMeta['language'] ?? null) && ($sourceMeta['language'] ?? '') !== ''
            ? strtolower($sourceMeta['language'])
            : $this->questionLanguage($question);
        $entity = is_string($sourceMeta['entity'] ?? null) && trim((string) $sourceMeta['entity']) !== ''
            ? (string) $sourceMeta['entity']
            : null;
        // Title/filename basis: the distilled subject when available (short,
        // content-only — "team members details"), never the raw question echo
        // ("Can you give me an excel with..."), which made ugly filenames.
        // For previous-result exports the question is BANNED as a fallback —
        // a subject-less follow-up ("Ok, give me the excel") must fall through
        // to the entity label / generic title, never echo itself.
        if (is_string($sourceMeta['subject'] ?? null) && trim((string) $sourceMeta['subject']) !== '') {
            $subjectSource = ucfirst(trim((string) $sourceMeta['subject']));
        } elseif ($source === 'previous_result') {
            $subjectSource = '';
        } else {
            $subjectSource = $question;
        }
        $title = $source === 'current_identity'
            ? ($language === 'fr' ? 'Informations utilisateur' : 'User Information')
            : $this->exportDocumentTitle($subjectSource, $language, $entity);

        $redactedRows = (new ResultSetExporter())->redact(
            $rows,
            $source === 'current_identity' ? [] : null
        );
        $redactedRows = array_values(array_filter($redactedRows, fn ($row) => is_array($row) && !empty($row)));

        if (empty($redactedRows)) {
            // Thread the already-resolved language (QU-first) — the clarification
            // must not fall back to the hardcoded word-list detector when the
            // pipeline already knows the question's language.
            return $this->generateExportClarificationResponse($question, $intent, $language);
        }

        $rowCount = count($redactedRows);
        // The total the user's underlying result actually holds: >= $rowCount when
        // the rows passed in are a sample fallback (complete === false).
        $totalRows = max((int) ($sourceMeta['total_rows'] ?? $rowCount), $rowCount);
        $documentRows = array_slice($redactedRows, 0, $this->exportDocumentRowLimit());
        $shownRows = count($documentRows);

        $table = $this->markdownTable($documentRows);
        $asOfLine = ($language === 'fr' ? 'En date du : ' : 'As of: ') . $this->asOfDate();
        $documentMarkdown = '# ' . $title . "\n\n" . $asOfLine . "\n\n" . $table;
        // Honest disclosure IN THE PAYLOAD too, whenever the document shows fewer
        // rows than the underlying result holds (trimmed or sample fallback) —
        // or when the source hit the executor's row cap, where the true total is
        // unknown (complete=false with total==shown): say "more may exist".
        $capped = ($sourceMeta['complete'] ?? true) === false && $totalRows <= $shownRows;
        if ($totalRows > $shownRows) {
            $documentMarkdown .= "\n\n" . ($language === 'fr'
                ? sprintf('Affiche %d lignes sur %d.', $shownRows, $totalRows)
                : sprintf('Showing %d of %d rows.', $shownRows, $totalRows));
        } elseif ($capped) {
            $documentMarkdown .= "\n\n" . ($language === 'fr'
                ? sprintf('Affiche les %d premieres lignes — il peut en exister davantage.', $shownRows)
                : sprintf('Showing the first %d rows — more may exist.', $shownRows));
        }
        $fieldSummary = $this->exportFieldSummary(array_keys($documentRows[0] ?? []), $language);

        $label = $format === 'excel'
            ? ($language === 'fr' ? 'Telecharger Excel' : 'Download Excel')
            : ($language === 'fr' ? 'Telecharger le PDF' : 'Download PDF');
        $action = $format === 'excel' ? 'export_excel' : 'export_pdf';
        $link = '[' . $label . '](action://' . $action . '?mode=document&title=' . rawurlencode($title) . ')';

        if ($language === 'fr') {
            $answer = $format === 'excel'
                ? "J'ai prepare le fichier Excel avec les informations disponibles."
                : "J'ai prepare le PDF avec les informations disponibles.";
            if ($fieldSummary !== '') {
                $answer .= "\n\n" . $fieldSummary;
            }

            if ($totalRows > $shownRows) {
                $answer .= "\n\n" . sprintf('Le document affiche %d lignes sur %d.', $shownRows, $totalRows);
            } elseif ($capped) {
                $answer .= "\n\n" . sprintf('Le document affiche les %d premieres lignes — il peut en exister davantage.', $shownRows);
            }

            $answer .= "\n\n" . $link;
        } else {
            $answer = $format === 'excel'
                ? 'I prepared the Excel file with the available information.'
                : 'I prepared the PDF with the available information.';
            if ($fieldSummary !== '') {
                $answer .= "\n\n" . $fieldSummary;
            }

            if ($totalRows > $shownRows) {
                $answer .= "\n\n" . sprintf('The document shows %d of %d rows.', $shownRows, $totalRows);
            } elseif ($capped) {
                $answer .= "\n\n" . sprintf('The document shows the first %d rows — more may exist.', $shownRows);
            }

            $answer .= "\n\n" . $link;
        }

        return [
            'answer' => $answer,
            'insights' => [],
            'visualizations' => [],
            'format' => 'markdown',
            'referenced_files' => [],
            'metadata' => [
                'export_intent' => true,
                'export_format' => $format,
                'export_source' => $source,
                'export_source_complete' => (bool) ($sourceMeta['complete'] ?? true),
                'export_document_markdown' => $documentMarkdown,
                'result_count' => $rowCount,
                'result_total' => $totalRows,
            ],
        ];
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
     * @param string[] $fields
     */
    private function exportFieldSummary(array $fields, string $language): string
    {
        $fields = array_values(array_filter(
            array_map(fn ($field) => $this->humanizeHeader((string) $field), $fields),
            fn ($field) => trim($field) !== ''
        ));
        if (empty($fields)) {
            return '';
        }

        $visible = array_slice($fields, 0, 8);
        $suffix = count($fields) > count($visible)
            ? ($language === 'fr'
                ? ' et ' . (count($fields) - count($visible)) . ' autres champs'
                : ' and ' . (count($fields) - count($visible)) . ' more fields')
            : '';

        return $language === 'fr'
            ? 'Champs inclus : ' . implode(', ', $visible) . $suffix . '.'
            : 'Fields included: ' . implode(', ', $visible) . $suffix . '.';
    }

    /**
     * @param array{format: string} $intent
     * @param string|null $language QU-detected question language (ISO 639-1) —
     *        wins over the legacy word-list fallback when provided, so the
     *        clarification follows the language policy like the document path.
     */
    public function generateExportClarificationResponse(string $question, array $intent, ?string $language = null): array
    {
        $format = strtoupper($intent['format']);
        $language = is_string($language) && trim($language) !== ''
            ? strtolower(trim($language))
            : $this->questionLanguage($question);

        $answer = $language === 'fr'
            ? "Je peux creer un {$format}, mais j'ai besoin de savoir quelles informations y inclure. "
                . "Demandez d'abord les donnees precises (par exemple les membres de votre equipe, vos contacts ou votre releve), "
                . "ou dites quels champs inclure, puis je pourrai preparer l'export."
            : "I can create a {$format}, but I need to know which information to include. "
                . "Ask for the specific data first, such as your team members, contacts, or statement, "
                . "or tell me which fields to include, then I can prepare the export.";

        return [
            'answer' => $answer,
            'insights' => [],
            'visualizations' => [],
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
    private function exportDocumentTitle(string $question, string $language, ?string $entity = null): string
    {
        $subject = $this->questionAsTitle($question);
        $entityLabel = $entity !== null ? $this->humanizeHeader($entity) : '';

        if ($entityLabel !== '' && $subject !== '') {
            // Avoid "Unit — ... unit ..." stutter when the question already names the entity.
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

        return $language === 'fr' ? 'Informations demandees' : 'Requested Information';
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
     * @param array<int, array<string, mixed>> $rows
     */
    private function markdownTable(array $rows): string
    {
        $headers = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                if (!in_array((string) $key, $headers, true)) {
                    $headers[] = (string) $key;
                }
            }
        }

        if (empty($headers)) {
            return '';
        }

        $labels = array_map(fn ($header) => $this->humanizeHeader($header), $headers);
        $out = '| ' . implode(' | ', array_map([$this, 'escapeMarkdownCell'], $labels)) . " |\n";
        $out .= '| ' . implode(' | ', array_fill(0, count($headers), '---')) . " |\n";

        foreach ($rows as $row) {
            $cells = [];
            foreach ($headers as $header) {
                $cells[] = $this->escapeMarkdownCell($this->stringValue($row[$header] ?? ''));
            }
            $out .= '| ' . implode(' | ', $cells) . " |\n";
        }

        return rtrim($out);
    }

    private function humanizeHeader(string $header): string
    {
        $header = preg_replace('/(?<!^)([A-Z])/', ' $1', $header) ?? $header;
        $header = str_replace(['_', '-'], ' ', $header);
        $header = trim(preg_replace('/\s+/', ' ', $header) ?? $header);

        return $header === '' ? 'Value' : ucwords($header);
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function escapeMarkdownCell(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        return str_replace('|', '\|', $value);
    }

    private function questionLanguage(string $question): string
    {
        $q = $this->foldedLower($question);

        return preg_match(
            '/\b(peux|pouvez|creer|generer|telecharger|mon|ma|mes|votre|'
            . 'equipe|informations|releve|fichier|feuille|tableur)\b/u',
            $q
        ) === 1 ? 'fr' : 'en';
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function foldedLower(string $value): string
    {
        $lower = $this->lower($value);
        if (function_exists('iconv')) {
            $folded = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
            if (is_string($folded) && $folded !== '') {
                return $folded;
            }
        }

        return $lower;
    }
}
