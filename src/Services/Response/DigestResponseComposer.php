<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\Digest\DigestSourceResult;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Actions\Attach\AttachedActions;
use Condoedge\Ai\Support\Audiences;
use Condoedge\Ai\Support\PipelineObserverScope;
use Condoedge\Ai\Support\TokenUsage;

/**
 * Digest Response Composer
 *
 * Renders a completed digest run (list of DigestSourceResult) into the final chat
 * answer with exactly ONE LLM call — the digest turn's entire LLM budget beyond QU
 * (design §0.2).
 *
 * p12 H1 — CODE-RENDERED SECTIONS (the deterministic-table pattern of DataAnswerPlan):
 * the model writes only the FRAMING — one opening line, one line per section beside
 * its `{{section:<key>}}` placeholder (optionally `{{section:<key>|translated
 * heading}}`), the `{{nothing_pending}}` slot and ONE closing deepening offer (H5).
 * Code then inserts, per placeholder, the section's heading + its TableRenderer table
 * + its "showing N of M" line + its money total, so the model never re-types rows
 * (p12: 13–15 s of composition was gpt-4o-mini re-typing ~1.3k tokens of tables).
 * The honesty rubric is therefore structural:
 *
 *  - a section placeholder the model omitted is inserted in registry order after
 *    the last placed section (before the closing); unknown / duplicate placeholders
 *    are stripped; a table or heading the model wrote anyway is dropped;
 *  - honest-empty collapse (H4): plain-empty sources are ONE combined code line at
 *    the `{{nothing_pending}}` slot (or after the sections) — never a section;
 *  - fallback rows render under their fallback label — the label states the
 *    "none scheduled" fact, the rows are the content (no "none pending" line, H4);
 *  - failed sources are one honest "could not be checked" line (raw error text never
 *    reaches the LLM or the user); a failed fallback adds one small note line;
 *  - per-source truncation disclosure is code ("Showing 5 of 130.", question
 *    language) inside its own section — never left to LLM goodwill;
 *  - H2: declared money columns (MoneyColumns over the source cypher) carry the
 *    currency symbol; a COMPLETE money section gets a code total line (a truncated
 *    one never — the sum of the shown rows is not what is owed);
 *  - H3: coded columns show their config enum-legend labels; legend-less bare
 *    codes are omitted ({@see CodedColumns});
 *  - p11 G1: columns empty in every row of a section are not rendered;
 *  - H6: a whole-digest export chip (and the write-back subject) is titled by the
 *    digest itself, in the question's language;
 *  - amendment B1/B2 + actions plan B4 metadata as before (section/record/export
 *    chips are attached by code; the prompt teaches no link grammar).
 */
class DigestResponseComposer
{
    /** `{{section:<key>}}` or `{{section:<key>|<heading>}}`. */
    private const SECTION_PATTERN = '/\{\{\s*section\s*:\s*([A-Za-z0-9_.\-]+)\s*(?:\|\s*([^{}\n]*?)\s*)?\}\}/u';

    /** The combined empty / unavailable lines slot. */
    private const TAIL_PATTERN = '/\{\{\s*nothing_pending\s*\}\}/iu';

    /** Either slot, for the line-level split. */
    private const SLOT_PATTERN = '/\{\{\s*(?:section\s*:\s*[A-Za-z0-9_.\-]+\s*(?:\|[^{}\n]*)?|nothing_pending)\s*\}\}/iu';

    /** Any other `{{...}}` the model wrote. */
    private const ANY_PLACEHOLDER = '/\{\{[^{}\n]*\}\}/u';

    /** The composition in progress's PII masker (see compose()). */
    private ?PiiMasker $masker = null;

    private TableRenderer $renderer;

    public function __construct(private readonly LlmProviderInterface $llm)
    {
        $this->renderer = new TableRenderer();
    }

    /**
     * @param DigestSourceResult[] $sources        Registry-ordered run results.
     * @param string[]             $skippedSources Enabled keys dropped by max_sources (B2).
     * @param string|null          $exportFormat   The turn's QU export ask, when combined (B1).
     * @param array<string, mixed> $options
     * @param AttachedActions|null $attached The chips code attaches (B4).
     * @return array<string, mixed> The standard response array.
     */
    public function compose(
        string $question,
        PipelineContext $ctx,
        array $sources,
        array $skippedSources = [],
        ?string $exportFormat = null,
        array $options = [],
        ?AttachedActions $attached = null
    ): array {
        // Phase 8 batch A: one fresh per-turn PII token map — identifying fields in
        // digest rows reach the LLM as tokens and are substituted back afterwards.
        $ctx->piiMasker = null;
        $this->masker = PiiMasker::for($ctx);
        $locale = $ctx->qu->language !== '' ? $ctx->qu->language : null;

        $sections = $this->sections($sources);

        $role = Audiences::promptBlock($this->authenticatedUser());
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($ctx, $exportFormat, $attached) . ($role !== '' ? "\n\n" . $role : '')],
            ['role' => 'user', 'content' => $this->masker->maskText($this->userPrompt($question, $sources, $sections, $exportFormat))],
        ];

        // The ONE composition call (LLM budget: QU + this = 2 calls per digest turn).
        $observer = PipelineObserverScope::for($ctx);
        $observer->promptBuilt('composition', $messages);
        try {
            // B5 enforced in code: every link the model wrote becomes text (section and row
            // links are chips attached by code) — on the masked text, before unmasking.
            $filter = new NarrativeLinkFilter(rows: $this->flattenRows($sources), tokens: array_keys($this->masker->map()));
            $narrative = trim($this->masker->unmask($filter->apply((string) $this->llm->chat($messages, ['temperature' => 0.4]))));
        } finally {
            TokenUsage::report($observer, 'composition', $this->llm);
        }

        [$answer, $appended] = $this->assemble($narrative, $sources, $sections, $locale);

        $truncated = [];
        foreach ($sections as $key => $section) {
            if ($section['truncated']) {
                $truncated[] = $key;
            }
        }

        $responseMetadata = [
            'digest' => $this->digestMetadata($sources, $skippedSources, $exportFormat),
            'digest_composition' => ['mode' => 'framing', 'sections_appended' => $appended],
        ];
        if ($truncated !== []) {
            // The disclosure is always code-rendered inside its own section now.
            $responseMetadata['digest_truncation_appended'] = $truncated;
            $responseMetadata['digest_truncation_placement'] = array_fill_keys($truncated, 'inline');
        }

        $title = $this->line('ai.digest.title', [], $locale, 'Items needing your attention');
        if ($attached !== null && !$attached->isEmpty()) {
            // The one assembly of a response's chips (AttachedActions::applyTo) — section,
            // record and export chips, then the "go through <section>" follow-up chips.
            $responseMetadata = $attached->applyTo($responseMetadata);
            $responseMetadata['invocations'] = $this->retitledExports($responseMetadata['invocations'], $title);
        }

        $data = $this->flattenRows($sources);

        return [
            'question' => $question,
            'answer' => $answer,
            'cypher' => null,
            'data' => $data,
            'stats' => [
                'result_count' => count($data),
                'digest_sources_run' => count($sources),
            ],
            'referenced_files' => [],
            'metadata' => [
                'query' => ['source' => 'attention_digest', 'intent' => 'digest_request'],
                'execution' => ['digest_sources_run' => count($sources)],
                'response' => $responseMetadata,
                'scope_clarified' => false,
                'security_filter_applied' => false,
                // Same write-back contract as buildFinalResponse: the subject titles a
                // follow-up export of these rows — for a digest, the digest itself (H6).
                'subject' => $title,
            ],
        ];
    }

    // =========================================================================
    // Sections (code-rendered)
    // =========================================================================

    /**
     * The sources that render as their own section (rows, or a configured empty
     * note), keyed by source key, in registry order, with everything the prompt
     * facts and the code render need.
     *
     * @param DigestSourceResult[] $sources
     * @return array<string, array{source: DigestSourceResult, heading: string, rows: array<int, mixed>, money: array<int, string>, truncated: bool, total: ?float, total_column: ?string}>
     */
    private function sections(array $sources): array
    {
        $sections = [];
        foreach ($sources as $source) {
            if (!$this->rendersAsSection($source) || isset($sections[$source->key])) {
                continue;
            }

            // H3 codes → legend labels (bare codes omitted), then p11 G1 empty columns.
            $rows = $this->withoutEmptyColumns(CodedColumns::apply($source->rows, $source->cypher));
            $money = MoneyColumns::fromCypher($source->cypher);
            $truncated = $source->trueTotal !== null && $source->trueTotal > $source->rowCount;

            // H2: a money total only for a COMPLETE section (the shown rows are all of it).
            $total = null;
            $totalColumn = null;
            if (!$truncated && !$source->capHit && $money !== []) {
                $prepared = $this->renderer->prepare($rows);
                foreach ($this->renderer->summableColumns($prepared, $money) as $column) {
                    if ($this->masker === null || $this->masker->maskedKind($column) === null) {
                        $total = $this->renderer->sum($prepared, $column);
                        $totalColumn = $column;
                        break;
                    }
                }
            }

            $sections[$source->key] = [
                'source' => $source,
                'heading' => $source->usedFallback && $source->fallbackLabel !== null ? $source->fallbackLabel : $source->label,
                'rows' => $rows,
                'money' => $money,
                'truncated' => $truncated,
                'total' => $total,
                'total_column' => $totalColumn,
            ];
        }

        return $sections;
    }

    /** Rows to show, or a host-configured empty note; never a plain empty or a failure. */
    private function rendersAsSection(DigestSourceResult $source): bool
    {
        if ($source->error !== null) {
            return false;
        }

        return $source->rows !== [] || ($source->rowCount === 0 && $source->emptyText !== null);
    }

    /**
     * One section as the user sees it: heading, the model's framing line, the table
     * (or the configured empty note), the truncation line and the money total.
     *
     * @param array<string, mixed> $section
     */
    private function sectionBlock(array $section, ?string $heading, string $framing, ?string $locale): string
    {
        /** @var DigestSourceResult $source */
        $source = $section['source'];
        $heading = $heading !== null && $heading !== '' ? $heading : $section['heading'];

        $parts = ['### ' . $heading . ($framing !== '' ? "\n" . $framing : '')];

        if ($source->rows === []) {
            $parts[] = (string) $source->emptyText;
            if ($source->fallbackError !== null) {
                $parts[] = $this->line('ai.digest.fallback_unavailable', ['label' => $source->label], $locale, 'Recent history for :label could not be loaded.');
            }

            return implode("\n\n", $parts);
        }

        $parts[] = $this->renderer->render($section['rows'], [
            'max_rows' => 0,
            'humanize_values' => true,
            'locale' => $locale,
            'money_columns' => $section['money'],
            'variable_labels' => TableRenderer::variableLabels($source->cypher),
            // No 'projections' on purpose: a source's query is the HOST's own
            // (ai.attention_sources) and its aliases are the column names the host chose
            // (`r.name AS change`). The property-header rule (TableRenderer::label())
            // corrects a GENERATED query's aliases only.
        ])['markdown'];

        if ($section['truncated']) {
            $parts[] = $this->line('ai.digest.truncation_inline', ['shown' => $source->rowCount, 'total' => (int) $source->trueTotal], $locale, 'Showing :shown of :total.');
        } elseif ($source->capHit) {
            $parts[] = $this->line('ai.digest.showing_first', ['shown' => $source->rowCount], $locale, 'Showing the first :shown — more may exist.');
        }

        if ($section['total'] !== null) {
            $parts[] = $this->line('ai.digest.total_line', [
                'label' => $this->renderer->label((string) $section['total_column']),
                'amount' => $this->renderer->formatMoney($section['total'], $locale),
            ], $locale, 'Total :label: :amount');
        }

        return implode("\n\n", array_filter($parts, static fn (string $p): bool => trim($p) !== ''));
    }

    /**
     * The combined lines for the sources that are NOT sections (H4): ONE "nothing
     * pending in: …" line for every plain-empty source, a note per failed fallback,
     * one honest line per failed source. '' when there is none.
     *
     * @param DigestSourceResult[] $sources
     */
    private function tailBlock(array $sources, ?string $locale): string
    {
        $empty = [];
        $notes = [];
        $failed = [];
        foreach ($sources as $source) {
            if ($this->rendersAsSection($source)) {
                continue;
            }
            if ($source->error !== null) {
                $failed[] = $this->line('ai.digest.source_failed', ['label' => $source->label], $locale, ':label could not be checked right now.');
                continue;
            }
            $empty[] = $source->label;
            if ($source->fallbackError !== null) {
                $notes[] = $this->line('ai.digest.fallback_unavailable', ['label' => $source->label], $locale, 'Recent history for :label could not be loaded.');
            }
        }

        $lines = [];
        if ($empty !== []) {
            $lines[] = $this->line('ai.digest.nothing_pending', ['sources' => implode(', ', $empty)], $locale, 'Nothing pending in: :sources.');
        }

        return implode("\n\n", [...$lines, ...$notes, ...$failed]);
    }

    // =========================================================================
    // Assembly: the model's framing + the code-rendered blocks
    // =========================================================================

    /**
     * @param DigestSourceResult[] $sources
     * @param array<string, array<string, mixed>> $sections
     * @return array{0: string, 1: list<string>} [answer, keys of the sections code inserted unplaced]
     */
    private function assemble(string $narrative, array $sources, array $sections, ?string $locale): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $narrative));
        // Tables are code-rendered: a table the model wrote anyway is dropped.
        $lines = array_values(array_filter($lines, static fn (string $l): bool => !str_starts_with(ltrim($l), '|')));

        $segments = [];   // list<array{type: string, text: string}>
        $buffer = [];
        $placed = [];
        $tailPlaced = false;
        $tail = $this->tailBlock($sources, $locale);

        $flush = function () use (&$buffer, &$segments): void {
            $text = trim((string) preg_replace(self::ANY_PLACEHOLDER, '', implode("\n", $buffer)));
            if ($text !== '') {
                $segments[] = ['type' => 'text', 'text' => $this->sentenceCase($text)];
            }
            $buffer = [];
        };

        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            // A heading the model wrote right above a placeholder doubles the code heading.
            if ($this->isHeading($line) && preg_match(self::SLOT_PATTERN, ltrim($this->nextNonBlank($lines, $i + 1)), $m, PREG_OFFSET_CAPTURE) === 1 && $m[0][1] === 0) {
                continue;
            }

            if (preg_match_all(self::SLOT_PATTERN, $line, $slots, PREG_OFFSET_CAPTURE) === 0) {
                $buffer[] = $line;
                continue;
            }

            $lead = trim(substr($line, 0, $slots[0][0][1]));
            foreach ($slots[0] as $n => [$slot, $offset]) {
                $end = $offset + strlen($slot);
                $next = $slots[0][$n + 1][1] ?? strlen($line);
                $after = trim(substr($line, $end, $next - $end));

                if (preg_match(self::TAIL_PATTERN, $slot) === 1) {
                    if ($n === 0 && $lead !== '') {
                        $buffer[] = $lead;
                    }
                    if (!$tailPlaced && $tail !== '') {
                        $flush();
                        $segments[] = ['type' => 'tail', 'text' => $tail];
                        $tailPlaced = true;
                    }
                    if ($after !== '') {
                        $buffer[] = $after;
                    }
                    continue;
                }

                preg_match(self::SECTION_PATTERN, $slot, $parts);
                $key = $parts[1] ?? '';
                if (!isset($sections[$key]) || isset($placed[$key])) {
                    // Unknown / duplicate / not a section: the placeholder goes, the prose stays.
                    $text = trim(($n === 0 ? $lead . ' ' : '') . $after);
                    if ($text !== '') {
                        $buffer[] = $text;
                    }
                    continue;
                }

                $framing = trim(($n === 0 ? $lead . ' ' : '') . $after);
                if ($framing === '' && $n === count($slots[0]) - 1) {
                    // Framing written on the following line(s) instead of beside the placeholder.
                    $more = [];
                    while ($i + 1 < $count && trim($lines[$i + 1]) !== '' && !$this->isHeading($lines[$i + 1])
                        && preg_match(self::SLOT_PATTERN, $lines[$i + 1]) === 0) {
                        $more[] = trim($lines[++$i]);
                    }
                    $framing = implode(' ', $more);
                }
                $framing = trim((string) preg_replace(self::ANY_PLACEHOLDER, '', $framing));
                // "{{section:x}} — 130 tasks are open": the separator the model put after the
                // placeholder is not part of the sentence (eval p14-B digest t2: every line began "— ").
                $framing = $this->sentenceCase((string) preg_replace('/^[\p{Pd}:;,.\s]+/u', '', $framing));

                $flush();
                $segments[] = ['type' => 'section', 'text' => $this->sectionBlock($sections[$key], $this->cleanHeading($parts[2] ?? ''), $framing, $locale)];
                $placed[$key] = true;
            }
        }
        $flush();

        // Sections the model did not place: registry order, after the last placed section
        // (else before the tail, else before the closing paragraph).
        $missing = [];
        foreach ($sections as $key => $section) {
            if (!isset($placed[$key])) {
                $missing[] = $key;
            }
        }
        $insert = array_map(fn (string $key): array => ['type' => 'section', 'text' => $this->sectionBlock($sections[$key], null, '', $locale)], $missing);
        if (!$tailPlaced && $tail !== '') {
            $insert[] = ['type' => 'tail', 'text' => $tail];
        }
        if ($insert !== []) {
            $segments = $this->insertBlocks($segments, $insert);
        }

        $answer = implode("\n\n", array_map(static fn (array $s): string => $s['text'], $segments));
        $answer = (string) preg_replace('/[ \t]+\n/', "\n", $answer);
        $answer = (string) preg_replace("/\n{3,}/", "\n\n", $answer);

        return [trim($answer), $missing];
    }

    /**
     * @param list<array{type: string, text: string}> $segments
     * @param list<array{type: string, text: string}> $insert
     * @return list<array{type: string, text: string}>
     */
    private function insertBlocks(array $segments, array $insert): array
    {
        $at = null;
        foreach ($segments as $i => $segment) {
            if ($segment['type'] === 'section') {
                $at = $i + 1;
            }
        }
        if ($at === null) {
            foreach ($segments as $i => $segment) {
                if ($segment['type'] === 'tail') {
                    $at = $i;
                    break;
                }
            }
        }
        if ($at !== null) {
            // The inserted tail (if any) never lands before a model-placed section.
            array_splice($segments, $at, 0, $insert);

            return $segments;
        }

        // No block at all: before the closing paragraph when the narrative has several.
        $text = implode("\n\n", array_map(static fn (array $s): string => $s['text'], $segments));
        $paragraphs = array_values(array_filter(preg_split('/\n\s*\n/', $text) ?: [], static fn (string $p): bool => trim($p) !== ''));
        if (count($paragraphs) >= 2) {
            $closing = array_pop($paragraphs);

            return [
                ['type' => 'text', 'text' => implode("\n\n", $paragraphs)],
                ...$insert,
                ['type' => 'text', 'text' => $closing],
            ];
        }

        return [...$segments, ...$insert];
    }

    /**
     * The model's framing text with a capital at the start of each of its lines (eval
     * p22-1001-B digest t0: on a byte-identical prompt every framing sentence came back in
     * lower case — "{{section:x}} — there are 130 tasks not yet closed." reads as the rest of
     * a sentence to the model; code places it as a line of its own).
     *
     * Only MODEL text passes here (the prose between the blocks, the line beside a
     * placeholder) — never a heading, a table or a line code wrote. By shape, any script:
     *  - the line starts with a plain lower-case WORD: lower-case letters (apostrophe or
     *    hyphen inside: "l'entretien"), then a space, the end, or one punctuation mark before
     *    either. A digit, a markdown marker, a quote, a capital, a word with a capital
     *    inside ("iCal"), a dotted token ("e.g.", an address), an identifier ("team_id") or
     *    an e-mail address starts no such word: the line is left as written;
     *  - and the line starts a sentence: it is the first of its paragraph, or the line above
     *    ends one (terminal punctuation or a colon). A line that continues the one above is
     *    left alone.
     * A sentence that starts INSIDE a line is not touched: no shape tells "…paid. there are"
     * from "…approx. three" without a list of abbreviations.
     */
    private function sentenceCase(string $text): string
    {
        $lines = explode("\n", $text);
        $above = '';
        foreach ($lines as $i => $line) {
            $startsSentence = $above === ''
                || preg_match('/[.!?:\x{3002}\x{FF1A}]["\'\x{201D}\x{2019}\x{00BB})\]*_]*$/u', $above) === 1;
            if ($startsSentence
                && preg_match('/^(\s*)(\p{Ll})[\p{Ll}\p{M}\'\x{2019}\-]*(?=[,;:!?.]?(?:\s|$))/u', $line, $m) === 1) {
                $capital = mb_strtoupper($m[2]);
                // One letter for one letter ("ß" has no single capital: left as written).
                if (mb_strlen($capital) === 1) {
                    $lines[$i] = $m[1] . $capital . substr($line, strlen($m[1]) + strlen($m[2]));
                }
            }
            $above = rtrim($line);
        }

        return implode("\n", $lines);
    }

    /** @param list<string> $lines */
    private function nextNonBlank(array $lines, int $from): string
    {
        for ($i = $from, $n = count($lines); $i < $n; $i++) {
            if (trim($lines[$i]) !== '') {
                return $lines[$i];
            }
        }

        return '';
    }

    /** A model-supplied (translated) heading: one plain line, markdown markers removed. */
    private function cleanHeading(string $heading): ?string
    {
        $heading = trim((string) preg_replace('/[#*_`|\[\]]+/u', '', $heading));
        $heading = trim((string) preg_replace('/\s+/u', ' ', $heading));

        return $heading !== '' && mb_strlen($heading) <= 120 ? $heading : null;
    }

    /** A markdown #-heading or a bold-only line ("**Heading**", optional colon). */
    private function isHeading(string $line): bool
    {
        return preg_match('/^\s{0,3}#{1,6}\s+\S/u', $line) === 1
            || preg_match('/^\s*\*\*[^*].*\*\*\s*:?\s*$/u', $line) === 1;
    }

    // =========================================================================
    // Prompt (framing only)
    // =========================================================================

    private function authenticatedUser(): mixed
    {
        try {
            return function_exists('auth') && auth()->check() ? auth()->user() : null;
        } catch (\Throwable) {
            return null; // no auth context (console/queue)
        }
    }

    private function systemPrompt(PipelineContext $ctx, ?string $exportFormat, ?AttachedActions $attached): string
    {
        $language = $ctx->qu->language !== ''
            ? "Answer ENTIRELY in the question's language: '{$ctx->qu->language}'."
            : 'Answer ENTIRELY in the language of the question.';

        // B4: links and the export file are BUTTONS code attaches under the answer —
        // the model never writes a link and never offers what a button already does.
        // labels() leaves the follow-up question chips out ("Go through <section>" for a
        // section that shows only a part of its records): they are the user's possible
        // next messages, not buttons of this answer, so the model is neither told to point
        // at them nor told to avoid them — and the ONE closing offer below stays. The
        // sentence invites in the model's own voice (and may be answered with a plain
        // "yes"); a chip is the one-click way to take up such an offer, for every partial
        // section and not only the one the sentence picked.
        $labels = $attached !== null ? $attached->labels() : [];
        $narrator = AttachedActions::NARRATOR_LINE
            . ($labels !== [] ? ' Attached: ' . implode('; ', $labels) . '.' : '')
            . ($exportFormat === null ? ' Your closing offer is a deeper look you can give in this chat — never one of those buttons.' : '');
        // One closer only (suite standard): with a file attached, the file sentence IS it.
        $export = $exportFormat !== null
            ? "\n- The user ALSO asked for a " . strtoupper($exportFormat) . ' file of this; it is attached below.'
            : '';
        $closing = $exportFormat !== null
            ? 'Exactly ONE closing sentence: that the ' . strtoupper($exportFormat) . ' file with everything shown is '
                . 'attached below. It REPLACES the deepening offer — no other offer, no second closer.'
            : 'Exactly ONE closing sentence: a single deepening offer, asked as a question in an insider voice '
                . '(e.g. "Want me to go through the <most pressing section> one by one?") — one offer, never a menu of options.';

        return <<<PROMPT
You write the FRAMING of a workspace ATTENTION DIGEST built from pre-executed data. The system renders every section itself — its heading, its table of rows, its "showing N of M" line and its money total — and the combined "nothing pending" / "could not be checked" lines. You never write tables, rows, row lists or section headings, and you never add information of your own.

Write, in this order:
1. One short opening line.
2. For each SECTION, in the order given: its placeholder exactly as given (e.g. {{section:open_tasks}}) and, on the SAME line, ONE short factual sentence framing that section from its facts (its true total, or an item the facts single out — never call an item the oldest, newest or most urgent unless its facts say so). One line per section — the system renders the section right there.
3. {{nothing_pending}} on its own line — the system writes the combined lines for the sources listed as not rendered; write nothing else about them.
4. {$closing}

Rules:
- {$language} If a section heading is not in that language, give its translation after a pipe: {{section:<key>|<translated heading>}}.
- NEVER invent rows, totals, dates, names or urgency not present in the facts, and add no judgment or consequence the facts do not state (no "significant backlog", no "delaying progress"). Never sum rows yourself: state only totals the facts give.
- A section's count is the total its facts give — the only count you state for it. When its rows are a sample, the table shows only a part of that total: the system prints the "showing N of M" line itself — never write how many rows are shown and never present the sample as all of them.
- FALLBACK sections: their rows are recent history, NOT pending items, and their heading already says nothing is scheduled. Frame them as the most recent ones; never call them upcoming and never write "none pending" for them.
- Values like [email#1] are placeholders for personal data: write them exactly as-is; the system substitutes the real value.
- Direct and concise, insider voice, no padding.
- {$narrator}{$export}
PROMPT;
    }

    /**
     * @param DigestSourceResult[] $sources
     * @param array<string, array<string, mixed>> $sections
     */
    private function userPrompt(string $question, array $sources, array $sections, ?string $exportFormat): string
    {
        $blocks = ["QUESTION: {$question}"];

        foreach ($sections as $key => $section) {
            $blocks[] = $this->sectionFacts($key, $section);
        }

        $notRendered = [];
        foreach ($sources as $source) {
            if ($this->rendersAsSection($source)) {
                continue;
            }
            // PRIMARY failure (§3 step 4): one honest line; raw error text stays in the
            // logs/trace, never in the prompt.
            $notRendered[] = '- ' . $source->label . ($source->error !== null
                ? ': could not be checked right now.'
                : ': nothing pending.' . ($source->fallbackError !== null ? ' (recent-history context could not be loaded)' : ''));
        }
        if ($notRendered !== []) {
            $blocks[] = "NOT RENDERED AS SECTIONS — the system writes these at {{nothing_pending}}:\n" . implode("\n", $notRendered);
        }

        if ($exportFormat !== null) {
            $blocks[] = 'NOTE: the user also asked for a ' . strtoupper($exportFormat)
                . ' file of this — it is attached below the answer.';
        }

        return implode("\n\n", $blocks);
    }

    /** @param array<string, mixed> $section */
    private function sectionFacts(string $key, array $section): string
    {
        /** @var DigestSourceResult $source */
        $source = $section['source'];
        $lines = ["SECTION {{section:{$key}}} — heading: {$section['heading']}"];
        if ($source->description !== '') {
            $lines[] = "Meaning: {$source->description}";
        }

        if ($source->rows === []) {
            $lines[] = 'Facts: nothing pending — the system shows this configured note under the heading: "' . $source->emptyText . '"';

            return implode("\n", $lines);
        }

        // A PARTIAL section (more exist than the table shows) hands the model its TRUE
        // TOTAL only — never the number of rows on screen. Eval p17-B digest t0: given
        // "5 shown of 189 in total — speak of the 189" the model wrote "5 contracts have
        // expired out of 189 total" in five sections of one answer (and the
        // right sentence on the byte-identical prompt of the run before). The
        // "Showing 5 of 189." line is code's ({@see self::sectionBlock()}), so the
        // model has no use for the shown count; the rows it sees are named a sample.
        $partial = !$source->usedFallback && ($section['truncated'] || $source->capHit);

        if ($source->usedFallback) {
            $lines[] = "Facts: PRIMARY EMPTY — nothing pending/scheduled. The {$source->rowCount} rows are FALLBACK context: "
                . 'recent history, NOT the asked-for items — never call them upcoming.';
        } elseif ($section['truncated']) {
            $lines[] = "Facts: {$source->trueTotal} in total.";
        } elseif ($source->capHit) {
            $lines[] = 'Facts: the total is unknown — more exist than the table shows. State no count.';
        } else {
            $lines[] = "Facts: all {$source->rowCount} shown.";
        }

        if ($section['total'] !== null) {
            $lines[] = 'Total ' . $this->renderer->label((string) $section['total_column']) . " over all {$source->rowCount}: "
                . $this->renderer->formatMoney($section['total'], 'en') . ' (system-computed).';
        } elseif ($section['money'] !== [] && $partial) {
            $lines[] = 'Amounts: no total of all of them is known — never sum the sample rows.';
        }

        $lines[] = ($partial
            ? 'Sample rows (context only — a part of them; the system renders this table and prints how many it shows; never reproduce or count it):'
            : 'Rows (context only — the system renders this table; never reproduce it):')
            . "\n" . $this->rowsTable($section);

        return implode("\n", $lines);
    }

    /**
     * The section's rows as a COMPACT masked pipe table (LLM-facing, via THE
     * TableRenderer): internal ids redacted, identifying fields masked, codes as
     * labels, money with its currency. No links: record chips are attached by code (B4).
     *
     * @param array<string, mixed> $section
     */
    private function rowsTable(array $section): string
    {
        return $this->renderer->render($section['rows'], [
            'max_rows' => 0,
            'masker' => $this->masker,
            'humanize_values' => true,
            'locale' => 'en',
            'money_columns' => $section['money'],
        ])['markdown'];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * H6: an export chip of the whole digest is titled by the digest — never by QU's
     * distilled subject of the question ("tasks needing attention").
     *
     * @param list<array<string, mixed>> $invocations
     * @return list<array<string, mixed>>
     */
    private function retitledExports(array $invocations, string $title): array
    {
        return array_map(static function (array $invocation) use ($title): array {
            if (is_array($invocation['params'] ?? null) && array_key_exists('title', $invocation['params'])) {
                $invocation['params']['title'] = $title;
            }

            return $invocation;
        }, $invocations);
    }

    /**
     * p11 G1 — drop every column empty in ALL rows of ONE section (null, a blank
     * string, or []); 0 / false / "0" are values. Purely value-based, no column
     * names — the same rule as the export document (ExportResponseComposer::
     * withoutEmptyColumns). A row missing the key counts as empty for it; when every
     * column is empty the rows are returned untouched.
     *
     * @param array<int, mixed> $rows
     * @return array<int, mixed>
     */
    private function withoutEmptyColumns(array $rows): array
    {
        $hasValue = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $key => $value) {
                $hasValue[$key] = ($hasValue[$key] ?? false)
                    || !($value === null || (is_string($value) && trim($value) === '') || $value === []);
            }
        }

        $empty = array_keys(array_filter($hasValue, static fn (bool $v): bool => !$v));
        if ($empty === [] || count($empty) === count($hasValue)) {
            return $rows;
        }

        $drop = array_fill_keys($empty, true);

        return array_map(static fn ($row) => is_array($row) ? array_diff_key($row, $drop) : $row, $rows);
    }

    /**
     * A canned line in the QUESTION's language (the 2026-07-23 live-failure class:
     * never the app locale), with an English fallback when no translator answers.
     *
     * @param array<string, scalar> $replace
     */
    private function line(string $key, array $replace, ?string $locale, string $fallback): string
    {
        try {
            $line = trans($key, $replace, $locale);
            if (is_string($line) && $line !== $key) {
                return $line;
            }
        } catch (\Throwable $e) {
            // no translator — fall through
        }

        $pairs = [];
        foreach ($replace as $name => $value) {
            $pairs[':' . $name] = (string) $value;
        }

        return strtr($fallback, $pairs);
    }

    /**
     * @param DigestSourceResult[] $sources
     * @param string[]             $skippedSources
     * @return array<string, mixed>
     */
    private function digestMetadata(array $sources, array $skippedSources, ?string $exportFormat): array
    {
        $perSource = [];
        $empty = [];
        $failed = [];

        foreach ($sources as $source) {
            $entry = [
                'key' => $source->key,
                'row_count' => $source->rowCount,
                'true_total' => $source->trueTotal,
                'used_fallback' => $source->usedFallback,
                'error' => $source->error,
            ];
            if ($source->fallbackError !== null) {
                $entry['fallback_error'] = $source->fallbackError;
            }
            $perSource[] = $entry;

            if ($source->error !== null) {
                $failed[] = $source->key;
            } elseif ($source->countsAsEmpty()) {
                $empty[] = $source->key;
            }
        }

        $digest = [
            'sources' => $perSource,
            'empty_sources' => $empty,
            'failed_sources' => $failed,
            'skipped_sources' => array_values($skippedSources),
        ];

        if ($exportFormat !== null) {
            $digest['export_followup_offered'] = $exportFormat;
        }

        return $digest;
    }

    /**
     * Flat rows for the conversation write-back. recordResponse persists a
     * digest turn's rows IN FULL (metadata.response.digest marks them as an
     * already display-capped set) — that is what makes the B1 follow-up
     * "export that" able to carry everything shown. Each row is stamped with
     * its source key so the export keeps the attribution.
     *
     * @param DigestSourceResult[] $sources
     * @return array<int, array<string, mixed>>
     */
    private function flattenRows(array $sources): array
    {
        $data = [];
        foreach ($sources as $source) {
            foreach ($source->rows as $row) {
                if (is_array($row)) {
                    $data[] = $row + ['attention_source' => $source->key];
                }
            }
        }

        return $data;
    }
}
