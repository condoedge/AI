<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\BuiltIn;

use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\ActionKind;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Chat\Exporter\RecordedQueryRerun;
use Condoedge\Ai\Services\Chat\Exporter\ResultSetExporter;
use Condoedge\Ai\Services\Chat\Support\FriendlyError;
use Condoedge\Ai\Services\Response\CodedColumns;
use Condoedge\Ai\Services\Response\TableRenderer;

/**
 * The RE-RUN mode of the built-in export actions (`export_excel` / `export_pdf`
 * `mode=rerun`) — the export chip of a CAPPED list.
 *
 * Why (eval p18-A roster, "25 of 523" and no export chip): `mode=table` writes the
 * message's stored rows — the executor's first 100 — so the chip was withheld on a capped
 * list, while the very next turn ("give me that as an Excel") exported all 523 by
 * re-running the recorded query. This gives the chip the same ability.
 *
 * What it does when the chip is CLICKED:
 *  1. takes the query recorded with the message from the action context — put there by
 *     the click-time entry point, from the stored message. No recorded query = an error;
 *     the context's stored rows are NEVER used here (no silent partial file);
 *  2. re-runs it through {@see RecordedQueryRerun} AS THE ACTING USER — whoever clicks
 *     now, with today's permissions: the scoped executor applies their tenant scope,
 *     read-only, one rate-limit charge. A refusal, a failure or an empty result is a
 *     friendly error, never a file and never the internal reason;
 *  3. writes the rows the way the document export does: export redaction, legend codes as
 *     labels in the answer's language ({@see CodedColumns::labelled()}), a split name in
 *     one column as on screen ({@see self::withNamesMerged()}), all-empty columns
 *     dropped, each column headed by the property it holds
 *     ({@see TableRenderer::headerProjections()}).
 *
 * The result is the exporter's Export result with two extra payload keys — `rows` (rows
 * written) and `complete` (false when the document limit cut the list) — so the caller
 * can say so beside the download.
 */
final class RecordedResultExport
{
    /** The `mode` input that asks for the re-run. */
    public const MODE = 'rerun';

    public function __construct(
        private ?RecordedQueryRerun $rerun = null,
        private ?ResultSetExporter $exporter = null,
    ) {
    }

    /** @param array<string, mixed> $input an export action's (schema-filtered or raw) params */
    public static function requested(array $input): bool
    {
        return strtolower(trim((string) ($input['mode'] ?? ''))) === self::MODE;
    }

    /**
     * @param string $format 'excel' | 'pdf'
     * @param array<string, mixed> $input title, language
     */
    public function handle(string $format, array $input, ActionContext $context): AiActionResult
    {
        $pdf = $format === 'pdf';
        $noData = $pdf ? 'No data available to export to PDF.' : 'No data available to export to Excel.';

        $recorded = $context->recordedQuery;
        if ($recorded === null || !is_object($context->user)) {
            return AiActionResult::error($noData);
        }

        try {
            $result = $this->rerun()->run($recorded['cypher'], $recorded['parameters'], $context->user, [
                'format' => $pdf ? 'pdf' : 'excel',
                'conversation_id' => $recorded['conversation_id'],
            ]);
        } catch (\Throwable $e) {
            $this->log($e);

            return AiActionResult::error($this->friendly($e));
        }

        if ($result['rows'] === []) {
            return AiActionResult::error($noData);
        }

        $language = $this->language($input['language'] ?? null);
        [$rows, $headers] = $this->document($result['rows'], $recorded['cypher'], $language);
        if ($rows === []) {
            return AiActionResult::error($noData);
        }

        $title = trim((string) ($input['title'] ?? '')) !== '' ? (string) $input['title'] : 'export';
        $file = $pdf
            ? $this->exporter()->documentRowsToPdf($rows, $title, $headers)
            : $this->exporter()->documentRowsToExcel($rows, $title, $headers);

        if ($file->kind !== ActionKind::Export || !is_array($file->payload)) {
            return $file;
        }

        return new AiActionResult(
            ActionKind::Export,
            $file->payload + ['rows' => count($rows), 'complete' => $result['complete']],
            $file->summaryForLlm
        );
    }

    /**
     * The rows and headers of the file — the document export's own steps
     * (ExportResponseComposer::generateExportDocumentResponse), through its public helpers.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function document(array $rows, string $cypher, ?string $language): array
    {
        $rows = array_values(array_filter(
            $this->exporter()->redact($rows),
            static fn ($row): bool => is_array($row) && $row !== []
        ));
        if ($rows === []) {
            return [[], []];
        }

        try {
            $rows = CodedColumns::labelled($rows, $cypher, $language);
        } catch (\Throwable $e) {
            // display nicety only: the raw values stand
        }
        // A name split over several properties is ONE column, as on screen (after redaction:
        // a name part that was left out is never joined back in).
        $rows = self::withNamesMerged($rows, $cypher);

        $renderer = new TableRenderer($this->exporter());
        $columns = $renderer->columns($rows);
        $empty = $renderer->emptyColumns($rows);
        // A column empty on every row says nothing; all of them empty = nothing to drop down to.
        if ($empty !== [] && count($empty) < count($columns)) {
            $drop = array_fill_keys($empty, true);
            $rows = array_map(static fn (array $row): array => array_diff_key($row, $drop), $rows);
            $columns = $renderer->columns($rows);
        }

        $headers = [];
        try {
            $projections = TableRenderer::headerProjections($cypher, $language);
            // …said in the answer's language, as the chat table's and the document export's
            // headers are (the host's own lines; a header it ships none for stays as it is).
            $headers = array_combine($columns, $renderer->headers($columns, [], TableRenderer::variableLabels($cypher), $projections, $this->headerLocale($language)));
        } catch (\Throwable $e) {
            // the humanized column keys head the file
        }

        return [$rows, $headers];
    }

    /**
     * $rows with the columns of an entity's declared name merged into ONE column — the chat
     * table's own rule over the query the rows came from ({@see TableRenderer::nameMerges()}:
     * `metadata.name_properties`, all projected as plain properties of one node variable),
     * so the file shows the columns the screen showed (eval p19-A board: "Name" on screen,
     * "First Name | Last Name" in the chip's file). The merged column's key heads it "Name"
     * through the ordinary header rules. Apply it to rows that are already REDACTED.
     *
     * Display only — nothing is executed. No query, nothing to merge or any failure: the
     * rows as they are.
     *
     * @param array<int, mixed> $rows
     * @return array<int, mixed>
     */
    public static function withNamesMerged(array $rows, ?string $cypher): array
    {
        if ($rows === [] || $cypher === null || trim($cypher) === '') {
            return $rows;
        }

        try {
            return (new TableRenderer())->mergeColumns($rows, TableRenderer::nameMerges($cypher, $rows));
        } catch (\Throwable $e) {
            return $rows; // display nicety only: the query's own columns stand
        }
    }

    /** The answer's language as the chip carries it; anything that is not a language code = the app locale. */
    private function language(mixed $language): ?string
    {
        return is_string($language) && preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})?$/', trim($language)) === 1
            ? trim($language)
            : null;
    }

    /** The language the file's headers are said in: the chip's, else the app locale. */
    private function headerLocale(?string $language): ?string
    {
        if ($language !== null) {
            return $language;
        }

        try {
            $locale = function_exists('app') ? (string) app()->getLocale() : '';
        } catch (\Throwable $e) {
            $locale = '';
        }

        return $locale !== '' ? $locale : null;
    }

    /**
     * The chat's own friendly failure text ({@see FriendlyError}: connection / timeout /
     * generic, by exception type) — never the exception's message, which may name the
     * security layer's reason or the rate limit.
     */
    private function friendly(\Throwable $e): string
    {
        try {
            $key = FriendlyError::key($e);
            $text = function_exists('__') ? __($key) : $key;
            if (is_string($text) && $text !== '' && $text !== $key) {
                return $text;
            }
        } catch (\Throwable) {
            // no translator (unit context)
        }

        return 'The export could not be prepared. Please try again in a moment.';
    }

    private function log(\Throwable $e): void
    {
        try {
            \Illuminate\Support\Facades\Log::warning('Export re-run of a recorded result failed: ' . $e->getMessage());
        } catch (\Throwable) {
            // logging is best effort
        }
    }

    private function rerun(): RecordedQueryRerun
    {
        return $this->rerun ??= new RecordedQueryRerun();
    }

    private function exporter(): ResultSetExporter
    {
        return $this->exporter ??= new ResultSetExporter();
    }
}
