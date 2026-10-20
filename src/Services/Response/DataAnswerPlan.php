<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Actions\Attach\RecordResolver;
use Condoedge\Ai\Services\Discovery\EntityLabelIndex;
use Condoedge\Ai\Services\Query\FanOutCollapse;
use Condoedge\Ai\Services\ResponseSections\SelfReferenceNoticeSection;
use Condoedge\Ai\Support\AliasNormalizer;

/**
 * DataAnswerPlan — deterministic data answers (Phase 8 batch A).
 *
 * Measured live: gpt-4o-mini mis-added rows even with a total column present
 * ($10,493.17 vs the real $11,493.17), said "10 shown" while listing 5, merged
 * three different units named "310", and only ever saw a 10-row pretty-JSON
 * sample, so a complete list was impossible. The plan moves everything that is
 * arithmetic or enumeration OUT of the LLM:
 *
 *   PROMPT (promptBlock): the stats line, FACTS (the values of a single-row /
 *   aggregate result), the query's own total columns or SYSTEM-COMPUTED totals
 *   of decimal columns, a COMPACT PII-masked pipe-table summary of the first
 *   ≤10 rows, homonym notes, and — in table mode — the instruction to write only
 *   the narrative with a {{table}} placeholder.
 *
 *   ANSWER (finalize): code replaces {{table}} with the TableRenderer table of
 *   ALL rows up to the display cap plus the honest "Showing X of Y" line (at the
 *   end of the narrative when the placeholder is missing). CODE OWNS EVERYTHING
 *   FROM THE TABLE ON: the answer ends at the code-rendered block, and whatever
 *   the model wrote after the placeholder is dropped (eval p16-B: 74 of 87 table
 *   answers ended with a generic closer there) — see finalize().
 *
 *   ONE row (eval p16-B digest t1): a RECORD — its fields are rendered by code at
 *   {{table}} as a "Field: value" block, so no fact depends on the model copying
 *   it (the union was dropped from "name, date, union" in several runs). Only a
 *   row small enough for one sentence is stated by the model from FACTS: at most
 *   two columns, or up to three MEASURES (an aggregate row — "You owe $11,493.17
 *   across 4 open bills" never grows a table) — see statedInProse().
 *
 *   Eval p16-A (G1-G3): the block states how the rows GROUP by another node's column
 *   (groupingsOf: 37 board members over 9 unions were answered as one board); a result
 *   that IS one set of homonyms gets ONE lead instruction and no sum across them
 *   (homonymLead / homonymsAreTheWholeResult); a column empty on every row is left out
 *   of the table, the record block and the summary (omittedColumns), named only by the
 *   NOT RECORDED / NO VALUE note.
 *
 *   Eval p17 (the padding the tail-drop removed moved in FRONT of the block): the model
 *   writes a bounded LEAD — one or two sentences for a table, one for a record, which leaves
 *   the field values to the block — plus only what a note of the block asks for
 *   (answerFormatLines / requiringNotes); finalize() removes a list or table of the lead
 *   that restates the rendered rows (NarrativeRowEcho over rowEchoKeys). A truncated list
 *   whose true total is known must lead with it, and the count line names the record kind
 *   when the total counts one the host gave a plural (recordKindOf / recordKind).
 *
 *   Eval p18 (the lead read its own instruction back: "There are 37 records covering 12
 *   different Union Names", "Unit 310 has three different records", "523 records in total",
 *   and "…the president is not specified in the available data" above the board's table): the
 *   count, the grouping, the homonym statement and "not recorded" are FACTS code already
 *   knows, so CODE WRITES THEM — one short line right above the block ({@see facts()}), from
 *   lang templates (ai.response.facts_*) and the HOST's nouns for the entities involved
 *   (entity config metadata.display_name / display_name_plural, {@see noun()}). The model's
 *   lead is then one sentence that answers the question and restates none of it. A noun the
 *   host did not declare, or cannot say in the answer's language, leaves that part to the
 *   model exactly as before. The block's headers are said in the answer's language through
 *   the host's translator ({@see headerLocale()}, {@see TableRenderer::headers()}).
 *
 * Everything is structural (row counts, value types, value distributions, the
 * cypher's projections and aggregate grammar) — no column-name word lists, no phrase
 * detection.
 * One plan per turn, memoized on the context.
 */
final class DataAnswerPlan
{
    public const PLACEHOLDER = '{{table}}';

    /** Section option carrying the turn's table-mode decision (set by ResponseGenerator). */
    public const OPTION = 'deterministic_table';

    public const MODE_TABLE = 'table';
    public const MODE_SINGLE = 'single';
    public const MODE_NONE = 'none';

    /** Styles whose contract is a one-liner — no rendered table there. */
    private const TERSE_STYLES = ['minimal', 'concise'];

    /** Aggregate row stated in prose: one row of at most this many MEASURE columns. */
    private const SINGLE_VALUE_MAX_COLUMNS = 3;

    /**
     * One row of at most this many columns is stated in prose whatever it holds: a lead
     * sentence carries a subject and one attribute. From the third field on the model
     * dropped one (eval p13/p16-B "name, date, union" → union lost), so code lists them.
     */
    private const RECORD_PROSE_MAX_COLUMNS = 2;

    private const PLACEHOLDER_PATTERN = '/\{\{\s*table\s*\}\}/i';

    /** A citation marker — "[3]", never a markdown link text "[3](…)" (as AnswerValidator::MARKER). */
    private const CITATION_PATTERN = '/\[\d{1,3}\](?!\()/';

    private string $mode = self::MODE_NONE;

    /** The table is ONE record, rendered as a "Field: value" block instead of a one-row table. */
    private bool $record = false;

    /** Whether the last finalize() dropped model text written after the table placeholder. */
    private bool $tailDropped = false;

    /** How many list / table blocks echoing the rendered rows the last finalize() dropped from the lead. */
    private int $rowEchoDropped = 0;

    /** @var list<string> the parts of the facts line the last finalize() printed ({@see facts()}) */
    private array $factsStated = [];

    /**
     * What the last finalize() removed from the lead beside the facts line ({@see boundedLead()}),
     * in order: the text after its first sentence ('one_sentence'), a lead that only restated
     * ('restatement'). The text is PII-masked.
     *
     * @var list<array{rule: string, text: string}>
     */
    private array $leadTrimmed = [];

    /** The user's question as asked — what a restating lead repeats ({@see boundedLead()}). */
    private string $question = '';

    /**
     * The identity values the prompt gave the model about the user — own name, current team
     * name ({@see knownIdentity()}): known words, never information of a lead's own.
     *
     * @var list<string>
     */
    private array $identity = [];

    /**
     * On a turn Query Understanding judged a FOLLOW-UP: the question it follows
     * ({@see previousQuestionOf()}) — a follow-up's subject lives in the conversation, so its
     * words are known words to a restating lead ({@see boundedLead()}). '' otherwise.
     */
    private string $previousQuestion = '';

    /**
     * The notes the last promptBlock() named in the lead instruction ({@see requiringNotes()}):
     * each may ask the lead for a sentence of its own. Null = no prompt was built yet.
     *
     * @var list<string>|null
     */
    private ?array $promptNotes = null;

    /** The rows are identified by at most this many of their leading label columns ({@see rowEchoKeys()}). */
    private const ROW_ECHO_KEY_COLUMNS = 2;

    /**
     * The entity label the stated total COUNTS, when that is known ({@see recordKindOf()}) —
     * what the lead and the code-rendered count line name the records by ({@see recordKind()}).
     */
    private ?string $recordKindLabel = null;

    /** The answer's language (QU), for host text resolved at prompt time. */
    private ?string $language = null;

    /**
     * The rows are a JOIN the collapse could not fold: the companion counted fewer distinct
     * anchor records than rows (execution metadata anchor_total < the row total). The total
     * is then a row count — never demanded in the lead, never given a record kind.
     */
    private bool $fanOut = false;

    /** @var array<int, array<string, mixed>> the rows the user table shows (prepared, real values) */
    private array $tableRows = [];

    /**
     * The NAME columns shown as one (eval p19-A board: "First Name | Last Name" for one person) —
     * {@see TableRenderer::nameMerges()}: an entity's declared `metadata.name_properties`, all
     * projected from one node variable. {@see $tableRows} hold the merged column; every
     * analysis, header and note reads the rows the user sees.
     *
     * @var list<array{key: string, columns: list<string>, variable: string, label: string, properties: list<string>}>
     */
    private array $nameMerges = [];

    /**
     * The table rows BEFORE the name merge — what the LLM-facing summary is masked from: PII is
     * decided per source column, so a masked part stays its token inside the merged cell.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $unmergedRows = [];

    /** @var array<string, mixed> scalar values stated as facts (single / aggregate row) */
    private array $facts = [];

    /** Column of the single row whose nested list-of-maps IS the table, if any. */
    private ?string $nestedColumn = null;

    private int $total = 0;

    /** False when execution hit the row cap without a companion true total. */
    private bool $totalKnown = true;

    /** @var array<int, string> result columns whose source property is declared money (P-3) */
    private array $moneyColumns = [];

    /** Table rows map 1:1 onto the result rows (row N of the summary IS result row N). */
    private bool $rowAligned = false;

    /** @var array<string, string> cypher variable => node label (F8 header disambiguation) */
    private array $variableLabels = [];

    /**
     * Result column => the node variable it is projected from ({@see TableRenderer::columnVariables()})
     * — which ENTITY a group / homonym column belongs to ({@see columnEntity()}). Empty for
     * fallback rows (they are not this cypher's).
     *
     * @var array<string, string>
     */
    private array $columnOwners = [];

    /**
     * Result column => [variable, property] of the cypher's plain property projections, for
     * an answer in the schema's language ({@see TableRenderer::headerProjections()}): a
     * column aliased after another word than the property it holds (`u.email AS
     * contact_info`, eval p17-B) is headed / labelled by the property everywhere the rows
     * are named — table, record block, summary, FACTS, totals, notes. Empty for fallback
     * rows (they are not this cypher's).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private array $projections = [];

    /** Whether the last rendered user table marked a cell as the user's own value (F1). */
    private bool $selfMarked = false;

    /**
     * Homonym groups by record identity (RecordResolver::homonyms); null = identity unknown,
     * the value-only TableRenderer::homonyms() fallback runs (eval p10-A).
     *
     * @var list<array{column: string, value: string, count: int, rows: int, by: string, values: list<string>}>|null
     */
    private ?array $identityHomonyms = null;

    /** @var list<string> the cypher's aggregate aliases — never a homonym `by` column */
    private array $aggregateAliases = [];

    /** What the rows answer (QU search query, else clarified question, else the question) — E3. */
    private string $subject = '';

    /**
     * Set when the rows are NOT the answer to the question but the host-declared FALLBACK of
     * an empty result (execution metadata `empty_fallback`, stamped by AiManager: the turn's
     * own query matched nothing, the attention source's primary query confirmed it, and its
     * labeled fallback supplied these rows) — eval p16-A "When is our next meeting?".
     *
     * @var array{label: string, fallback_label: string}|null
     */
    private ?array $fallback = null;

    /** @var list<string> table columns empty (null / '' / []) on every retrieved row — E3, G3 */
    private array $emptyColumns = [];

    /**
     * How the rows GROUP (G1, eval p16-A board): the text columns of ANOTHER node than the
     * listed record whose few distinct values partition the rows — see {@see groupingsOf()}.
     *
     * @var list<array{column: string, groups: list<array{0: string, 1: int}>, blank: int}>
     */
    private array $groupings = [];

    /**
     * The table columns the QUESTION asked for ({@see askedColumnsOf()}), in table order — the
     * only ones whose partial emptiness code comments on ({@see partlyRecordedFact()}).
     *
     * @var list<string>
     */
    private array $askedColumns = [];

    /** Asked-for columns whose partial emptiness is stated at most (the first ones, in table order). */
    private const PARTLY_RECORDED_MAX = 2;

    /** Longest subject quoted in the prompt (a query-like phrase, never a document). */
    private const SUBJECT_MAX_CHARS = 200;

    /** A grouping holds at least this many rows per group on average (fewer = one per row, no grouping). */
    private const GROUP_MIN_AVERAGE_ROWS = 2;

    /** Groupings stated at most (the coarsest first), and groups named per grouping. */
    private const GROUPINGS_MAX = 2;
    private const GROUPS_LISTED_MAX = 10;

    private TableRenderer $renderer;

    private function __construct(private readonly PiiMasker $masker)
    {
        $this->renderer = new TableRenderer();
    }

    /** Whether the user-facing table is rendered by code for these generation options. */
    public static function tableModeFor(array $options): bool
    {
        if (array_key_exists(self::OPTION, $options)) {
            return (bool) $options[self::OPTION];
        }

        if (!(bool) self::config('ai.response_generation.deterministic_tables', true)) {
            return false;
        }

        $format = $options['format'] ?? self::config('ai.response_generation.default_format', 'text');
        if ($format === 'json') {
            return false;
        }

        $style = $options['style'] ?? self::config('ai.response_generation.default_style', 'friendly');

        return !in_array($style, self::TERSE_STYLES, true);
    }

    /** The turn's plan, memoized on the context. */
    public static function for(PipelineContext $context): self
    {
        if ($context->dataAnswerPlan instanceof self) {
            return $context->dataAnswerPlan;
        }

        $plan = new self(PiiMasker::for($context));
        $plan->build($context);

        return $context->dataAnswerPlan = $plan;
    }

    /**
     * The user's IDENTITY values the composition prompt states (CURRENT USER CONTEXT: their own
     * name, their current team's name — ResponseCurrentUserContextSection::identityValues()).
     *
     * Eval p20-A roster: "Here are the members of your team, Novo Gestion, along with their
     * roles:" survived the restatement rule because "Gestion" is in neither the question nor the
     * facts line. The model was TOLD that name; repeating it adds nothing. {@see boundedLead()}
     * counts these words as known, beside the question's and the line's — a value of the rows
     * ("…the Lead union manager of Novo Gestion.") still makes a lead an answer.
     *
     * @param array<int, mixed> $values blank and non-string entries are ignored
     */
    public function knownIdentity(array $values): self
    {
        $this->identity = array_values(array_filter(
            array_map(static fn ($value) => is_string($value) ? trim($value) : '', $values),
            static fn (string $value): bool => $value !== ''
        ));

        return $this;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function hasTable(): bool
    {
        return $this->mode === self::MODE_TABLE && $this->tableRows !== [];
    }

    /** Whether the code-rendered block is ONE record (a "Field: value" block, not a table). */
    public function isRecord(): bool
    {
        return $this->record && $this->hasTable();
    }

    /** Whether the last finalize() dropped text the model wrote after the table placeholder. */
    public function tailDropped(): bool
    {
        return $this->tailDropped;
    }

    /**
     * How many model-written list / table blocks the last finalize() dropped from the lead
     * because they restated the rows the system renders ({@see NarrativeRowEcho}) — trace
     * evidence (`row_echo_dropped`).
     */
    public function rowEchoDropped(): int
    {
        return $this->rowEchoDropped;
    }

    /**
     * The parts of the facts line the last finalize() printed above the block, in print order:
     * 'homonyms' | 'count' (with 'groups' when the sentence also says how the records are
     * grouped), then 'not_recorded' — trace evidence (`facts_line`), never their content.
     *
     * @return list<string>
     */
    public function factsStated(): array
    {
        return $this->factsStated;
    }

    /**
     * What the last finalize() cut from the model's lead beside the facts line — trace evidence
     * (`lead_trimmed`): `rule` is 'one_sentence' (text after the first sentence) or
     * 'restatement' (a lead that only repeated the question / the line), `text` the PII-masked
     * text removed.
     *
     * @return list<array{rule: string, text: string}>
     */
    public function leadTrimmed(): array
    {
        return $this->leadTrimmed;
    }

    /** Rows the user-facing table shows (display cap applied). */
    public function shown(): int
    {
        $cap = $this->displayCap();

        return $cap > 0 ? min(count($this->tableRows), $cap) : count($this->tableRows);
    }

    public function total(): int
    {
        return $this->total;
    }

    public function truncated(): bool
    {
        return $this->hasTable() && ($this->total > $this->shown() || !$this->totalKnown);
    }

    /**
     * The LLM-facing data block (goes inside the spotlighted results wrapper).
     *
     * $numbered (actions plan B5): the summary gets a leading `#` column — the row
     * number the narrator writes in {{record:N}} — when its rows ARE the result rows.
     */
    public function promptBlock(bool $tableMode, int $summaryRows = 10, bool $numbered = false): string
    {
        $lines = [];

        // E3 (eval p11-A board): name what the rows ARE, so the model never disowns its
        // own rows ("I couldn't find the board" above the board table).
        if ($this->mode !== self::MODE_NONE && $this->fallback !== null) {
            // FALLBACK ROWS (eval p16-A meetings): the same framing the digest gives a
            // fallback section — its rows are recent history, never the asked-for items —
            // and the lead must say first that nothing matched what was asked. The two
            // labels are the host's own (ai.attention_sources label / fallback_label).
            $quote = fn (string $text): string => '"' . str_replace('"', "'", $this->masker->maskFreeText($text)) . '"';
            $lines[] = 'FALLBACK ROWS (established by the system): the query for '
                . ($this->subject !== '' ? $quote($this->subject) : 'this question') . ' matched NOTHING — '
                . $quote($this->fallback['label']) . ': none. The rows below are FALLBACK context, '
                . $quote($this->fallback['fallback_label']) . ': recent history, NOT the asked-for items.';
            $lines[] = 'Your FIRST sentence must say plainly that there is none of what was asked — never that no such '
                . 'records exist at all — and then introduce these rows as what they are (' . $quote($this->fallback['fallback_label'])
                . ', in your own words in the answer\'s language). Never present a fallback row as an asked-for item '
                . '(never as upcoming, next, scheduled, pending or current), and never say this information could not be found.';
            $lines[] = '';
        } elseif ($this->mode !== self::MODE_NONE && $this->subject !== '') {
            $lines[] = 'These rows answer: "' . str_replace('"', "'", $this->masker->maskFreeText($this->subject)) . '" — '
                . 'they are the data found for it. Present them as that answer; never say this information '
                . 'could not be found. Describe only what the columns actually hold: if the question asks '
                . 'for something no column contains (e.g. a requested attribute), say plainly that it is '
                . 'not in these results — never claim the table shows it.';
            $lines[] = '';
        }

        if ($this->facts !== []) {
            $lines[] = 'FACTS (exact values from the data — copy them verbatim):';
            foreach ($this->masker->maskRows([$this->facts])[0] as $column => $value) {
                $money = $this->isMoney((string) $column) && !is_bool($value) && $this->renderer->isNumericValue($value);
                $lines[] = '- ' . $this->columnLabel((string) $column) . ': '
                    . ($money ? $this->renderer->formatMoney($value, 'en') : $this->renderer->cell($value, true, 'en'));
            }
            $lines[] = '';
        }

        // What CODE prints above the block (the facts line): the notes below then only inform,
        // and the lead instruction asks for none of it.
        $printed = $tableMode ? $this->facts(null) : ['stated' => [], 'line' => ''];
        $stated = $printed['stated'];
        $homonymLead = false;
        if ($this->mode === self::MODE_TABLE) {
            $lines = array_merge($lines, $this->totalsLines());

            $summaryRows = max(1, $summaryRows);
            // (A homonym statement code prints owns the lead as the note's did.)
            $homonymLead = $this->homonymLead($summaryRows, $tableMode) || in_array('homonyms', $stated, true);
            $sample = array_slice($this->tableRows, 0, $summaryRows);
            $what = $this->nestedColumn !== null
                ? $this->renderer->translateHeader($this->renderer->label($this->nestedColumn), $this->headerLocale()) . ' — '
                : '';
            $count = count($sample) < count($this->tableRows) || !$this->totalKnown || $this->total > count($this->tableRows)
                ? 'the first ' . count($sample) . ' of ' . $this->totalPhrase() . ' rows'
                : 'all ' . count($sample) . ' rows';
            $lines[] = "DATA SUMMARY ({$what}{$count}):";
            // Masked FIRST, then the derived email domains are added from the real
            // values — a derived column name can never collide with a configured
            // sensitive field (live: Team.sensible_columns lists `domain`).
            $summary = $this->withEmailDomains($this->maskedSample(count($sample)), $sample);
            if ($numbered && $this->rowAligned && $this->nestedColumn === null) {
                $summary = array_map(
                    static fn (array $row, int $i) => ['#' => $i + 1] + $row,
                    array_values($summary),
                    array_keys(array_values($summary))
                );
            }
            $lines[] = $this->renderer->render($summary, [
                'max_rows' => 0,
                'redact' => false,
                'escape_markdown' => false,
                'humanize_values' => true,
                'locale' => 'en',
                // The columns are NAMED as the user's table heads them (the answer's language).
                'header_locale' => $this->headerLocale(),
                'variable_labels' => $this->variableLabels,
                'projections' => $this->tableProjections(),
                'labels' => $this->derivedDomainLabels($summary, $sample),
                'money_columns' => $this->moneyColumns,
                // G3: the summary mirrors the user table — a column empty on every row is in
                // neither; the NOT RECORDED line below is where the model learns about it.
                'omit_columns' => $this->omittedColumns(),
            ])['markdown'];
            $lines[] = '';

            // (Never beside a homonym lead: that note already says "one per <by>".)
            foreach ($homonymLead ? [] : $this->groupingLines($tableMode, $stated) as $line) {
                $lines[] = $line;
            }

            foreach ($this->emptyColumnLines($tableMode, $stated) as $line) {
                $lines[] = $line;
            }

            foreach ($this->homonymLines($summaryRows, $tableMode, $stated) as $line) {
                $lines[] = $line;
            }
        }

        if ($this->masker->map() !== []) {
            $lines[] = 'Values like [email#1] are placeholders for personal data: write them exactly as-is '
                . 'when you mention them — the system substitutes the real value for the user. '
                . 'A placeholder hides the value, so you CANNOT judge it (e.g. its domain or format): '
                . 'these rows were already selected by the query for the question — never say a row or '
                . 'value does not match because it is masked.';
            $lines[] = '';
        }

        // (The line is quoted last: every value the summary masked holds its token by now.)
        // p20-A unit-310: beside a code-written homonym statement, the lead's PLURAL subject.
        $subject = in_array('homonyms', $stated, true) ? (string) $this->homonymSubject(null) : '';
        $lines = array_merge($lines, $this->answerFormatLines(
            $tableMode,
            $homonymLead,
            $stated,
            $printed['line'] !== '' ? $this->masker->maskFreeText($printed['line']) : '',
            $subject !== '' ? str_replace('"', "'", $this->masker->maskFreeText($subject)) : ''
        ));

        return rtrim(implode("\n", $lines));
    }

    /**
     * The shipped answer: the model's unmasked lead, then the code-rendered block (the
     * table and its honest count line, or the record's fields) — and NOTHING after it.
     *
     * CODE OWNS EVERYTHING FROM THE TABLE ON (D5, eval p16-B resilience t0 / french t0).
     * The model's job ends at {{table}}: what it writes after the placeholder is dropped.
     * By POSITION, never by wording — 74 of 87 table answers across the eval runs carried
     * a generic closer there ("just let me know!", "n'hésitez pas…") against the guideline
     * of the same prompt, in different words every run. Nothing code-rendered lives after
     * the placeholder in the narrative (the count line is part of the block; the
     * self-reference patch is inserted in-line; the truncation disclosure is appended on
     * the narrative-only path alone), so the cut loses no code output. Two cases keep the
     * trailing text, moved IN FRONT of the block so the answer still ends at it:
     *   - nothing precedes the placeholder (the model put the table first): the trailing
     *     text is its whole answer;
     *   - the trailing text cites a passage ("[2]"): it is document-grounded content of a
     *     data + document turn, never a closer.
     * Without any placeholder the whole narrative is the lead and the block closes it.
     *
     * THE LEAD NEVER REPEATS THE BLOCK (eval p17: once the tail was dropped the padding moved
     * in FRONT of the placeholder — a 10-bullet list echoing the first ten table rows on three
     * roster turns, a numbered list of the three unit-310 rows, beside "Do NOT list the rows"
     * in the same prompt). A list or table the model wrote in the lead whose entries restate
     * the rows — they contain the rows' own label values ({@see rowEchoKeys()}) — is removed
     * ({@see NarrativeRowEcho}: structure only; a list that does not name the rows, or that
     * cites a passage, stays; a closer SENTENCE cannot be told from an answer and is left to
     * the instruction). The count is {@see rowEchoDropped()}.
     *
     * THE FACTS LINE (eval p18): between the lead and the block, the one line of facts code
     * states itself — the record count and its grouping, or the homonym statement, and the
     * fields that are not recorded ({@see facts()}). Empty when the host declared no noun for
     * them: the answer is then the lead and the block, as before.
     *
     * BESIDE THAT LINE THE LEAD IS BOUNDED, AND MAY BE EMPTY (eval p19: with the facts printed
     * by code the lead of a list question had nothing left to say — "several members with
     * various roles" above "523 members.", a second filler sentence on two of six turns). The
     * model may write the placeholder alone; a lead of several sentences keeps its first, and
     * a lead that only restates the question / the line is dropped ({@see boundedLead()} —
     * structure only; the cuts are {@see leadTrimmed()}). A lead without a letter or digit is
     * no lead. The answer is then the line and the block.
     *
     * A callback-free concatenation on purpose: the table holds "$456.12", which a
     * preg_replace replacement string reads as back-reference $45 (eval p12-A shipped "6.12").
     *
     * $user (F1): the current user — a table cell holding their own identity value
     * (SelfReferenceNoticeSection::selfValuesIn: email / full name) carries the
     * localized "that's you" marker. The table is rendered with REAL values after
     * every check ran on the masked narrative, so the marker must be put there by
     * code; null = no marking.
     */
    public function finalize(string $narrative, bool $tableMode, ?string $locale = null, mixed $user = null): string
    {
        $this->selfMarked = false;
        $this->tailDropped = false;
        $this->rowEchoDropped = 0;
        $this->factsStated = [];
        $this->leadTrimmed = [];
        if (!$tableMode || !$this->hasTable()) {
            return $this->tidy((string) preg_replace(self::PLACEHOLDER_PATTERN, '', $narrative));
        }

        $parts = preg_split(self::PLACEHOLDER_PATTERN, $narrative, 2) ?: [$narrative];
        $strip = fn (string $text): string => $this->tidy((string) preg_replace(self::PLACEHOLDER_PATTERN, '', $text));
        $lead = $strip($parts[0]);
        $tail = $strip($parts[1] ?? '');

        if ($tail !== '') {
            if ($lead === '' || preg_match(self::CITATION_PATTERN, $tail) === 1) {
                $lead = $lead === '' ? $tail : $lead . "\n\n" . $tail;
            } else {
                $this->tailDropped = true;
            }
        }

        if ($lead !== '') {
            try {
                [$lead, $this->rowEchoDropped] = NarrativeRowEcho::strip($lead, $this->rowEchoKeys($locale));
                $lead = $this->tidy($lead);
            } catch (\Throwable $e) {
                $this->rowEchoDropped = 0; // never breaks a turn — the lead ships as written
            }
        }

        $block = $this->tableMarkdown($locale, $user);
        $facts = $this->facts($locale);
        $this->factsStated = $facts['stated'];

        if (preg_match('/[\p{L}\p{N}]/u', $lead) !== 1) {
            $lead = ''; // stray punctuation / invisible whitespace is no lead
        } elseif ($facts['line'] !== '') {
            $lead = $this->boundedLead($lead, $facts, self::shownHeaders($block));
        }

        return $this->tidy(implode("\n\n", array_filter(
            [$lead, $facts['line'], $block],
            static fn (string $part): bool => $part !== ''
        )));
    }

    /**
     * The lead beside a code-written facts line (eval p19), bounded by STRUCTURE — never by
     * wording ({@see NarrativeRowEcho::firstSentence()} / {@see NarrativeRowEcho::restates()}):
     *
     *   1. ONE sentence: what follows the first sentence is cut — unless a note of the prompt
     *      asked the lead for a sentence of its own ({@see requiringNotes()}), the boundary is
     *      not a safe one, or the extra text cites a passage (a data + document turn);
     *   2. a lead whose every content word the QUESTION, the LINE or the user's own IDENTITY
     *      values (their name, their team's — {@see knownIdentity()}) already say is a
     *      restatement and is dropped: the line and the block are the answer. Known too: the
     *      HEADERS of the block shown right below, and — on a follow-up turn — the QUESTION IT
     *      FOLLOWS (eval p20-B resilience t1: "Here are the last login times for the members of
     *      your team, Novo Gestion." after "…add their last login to it?" — "the", "of", "team"
     *      are the roster question's words; the lead knew 5 of its 13 words, with them 8).
     *      Replayed on the 28 stored leads of eval p19–p21: that lead is the one whose fate
     *      changes. A lead holding a word of its own still stays — p21-B's "Here are the last
     *      login details for your team members:" ("details" is said nowhere, and no structure
     *      tells such a word from information: when unsure, the lead stays).
     *
     * Each cut is recorded ({@see leadTrimmed()}, PII-masked). Never breaks a turn: on any
     * failure the lead ships as written.
     *
     * @param array{stated: list<string>, line: string} $facts
     * @param list<string> $headers the headers of the block shown below the line
     */
    private function boundedLead(string $lead, array $facts, array $headers = []): string
    {
        try {
            $notes = $this->promptNotes
                ?? $this->requiringNotes($this->homonymLead(10, true) || in_array('homonyms', $facts['stated'], true), $facts['stated']);
            if ($notes === []) {
                [$first, $cut] = NarrativeRowEcho::firstSentence($lead);
                if ($cut !== '') {
                    $this->leadTrimmed[] = ['rule' => 'one_sentence', 'text' => $this->masker->maskFreeText($cut)];
                    $lead = $first;
                }
            }

            if (NarrativeRowEcho::restates($lead, [$this->question, $facts['line'], ...$this->identity, ...$headers, $this->previousQuestion])) {
                $this->leadTrimmed[] = ['rule' => 'restatement', 'text' => $this->masker->maskFreeText($lead)];
                $lead = '';
            } elseif ($notes === [] && in_array('not_recorded', $facts['stated'], true) && !NarrativeRowEcho::namesAny($lead, $this->shownValues())) {
                // 3. What is NOT recorded is the line's to say (iteration 5, board: "…is not
                //    recorded in the available data." above 37 board members): beside it, a lead
                //    naming no value of the rows says nothing of the data and is dropped — unless a
                //    note of the prompt asked the lead for a sentence of its own.
                $this->leadTrimmed[] = ['rule' => 'not_recorded', 'text' => $this->masker->maskFreeText($lead)];
                $lead = '';
            }
        } catch (\Throwable $e) {
            // the lead ships as written
        }

        return $lead;
    }

    /**
     * The headers of the block as it is SHOWN — read off the rendered markdown, so every rule
     * that leaves a column out is already applied: the cells of a table's header row, or the
     * field labels of a one-record block ("- **Field:** value").
     *
     * @return list<string>
     */
    private static function shownHeaders(string $block): array
    {
        $lines = preg_split('/\R/u', trim($block)) ?: [];
        if (str_starts_with(ltrim($lines[0] ?? ''), '|')) {
            return array_values(array_filter(array_map('trim', explode('|', $lines[0])), static fn (string $cell): bool => $cell !== ''));
        }

        return preg_match_all('/^\s*[-*]\s+\*\*([^*]+?):?\*\*/mu', $block, $labels) > 0 ? array_map('trim', $labels[1]) : [];
    }

    /**
     * The question a follow-up turn follows: the last user question of the conversation
     * (`recent_exchanges`) that is not this turn's own — the regenerate path records the user
     * message before the pipeline runs, so the trailing one may be the question being answered.
     *
     * @param array<string, mixed> $conversation
     */
    private static function previousQuestionOf(array $conversation, string $current): string
    {
        $questions = [];
        foreach ((array) ($conversation['recent_exchanges'] ?? []) as $exchange) {
            $question = is_array($exchange) ? ($exchange['question'] ?? null) : null;
            if (is_string($question) && trim($question) !== '') {
                $questions[] = trim((string) preg_replace('/\s+/u', ' ', $question));
            }
        }
        if ($questions !== [] && end($questions) === $current) {
            array_pop($questions);
        }

        return $questions !== [] ? (string) end($questions) : '';
    }

    /**
     * The values the user-facing table / record block shows, as written in the rows (scalars of
     * the columns not left out) — what a lead can name of the data.
     *
     * @return list<string>
     */
    private function shownValues(): array
    {
        $columns = array_diff($this->renderer->columns($this->tableRows), $this->omittedColumns());
        $values = [];
        foreach ($this->tableRows as $row) {
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                if (is_string($value) || is_int($value) || is_float($value)) {
                    $values[(string) $value] = true;
                }
            }
        }

        return array_map('strval', array_keys($values));
    }

    /**
     * The user-facing block: the table + count line, or ONE record's "Field: value" lines
     * (real values; the user's own values marked — see finalize()).
     *
     * G3 (eval p16-A: "Team Address", "Bm Role", "Oldest Due" headed blank columns): a
     * column empty on EVERY retrieved row is left out, as the export already does
     * (ExportResponseComposer::withoutEmptyColumns) — see {@see omittedColumns()}.
     */
    public function tableMarkdown(?string $locale = null, mixed $user = null): string
    {
        $rendered = $this->renderer->render($this->tableRows, [
            'max_rows' => $this->displayCap(),
            'total' => $this->total,
            'redact' => false,
            'humanize_values' => true,
            'locale' => $locale,
            // p18-B french t0: the headers are said in the answer's language (host lines).
            'header_locale' => $this->headerLocale($locale),
            'variable_labels' => $this->variableLabels,
            'projections' => $this->tableProjections(),
            'marked_values' => $this->selfMarks($user, $locale),
            'money_columns' => $this->moneyColumns,
            'layout' => $this->record ? 'record' : 'table',
            'omit_columns' => $this->omittedColumns(),
        ]);
        $this->selfMarked = ($rendered['marked'] ?? 0) > 0;

        $block = $rendered['markdown'];
        if ($rendered['total'] > $rendered['shown']) {
            // P3 (eval p17-A roster "Showing 25 of 523 rows"): the line names the record kind
            // when the stated total counts one the host named — else today's neutral wording.
            $counts = ['shown' => $rendered['shown'], 'total' => $rendered['total']];
            $kind = $this->totalKnown ? $this->recordKind($locale) : null;
            $block .= "\n\n" . ($kind !== null
                ? $this->trans('ai.response.table_showing_of_kind', $counts + ['kind' => $kind], $locale, 'ai.response.table_showing_of')
                : $this->trans('ai.response.table_showing_of', $counts, $locale));
        } elseif (!$this->totalKnown) {
            $block .= "\n\n" . $this->trans('ai.response.table_showing_first', ['shown' => $rendered['shown']], $locale);
        }

        return $block;
    }

    /** Whether the last rendered user table marked one of the user's own values (F1). */
    public function selfMarked(): bool
    {
        return $this->selfMarked;
    }

    /**
     * The headers of the columns the code-rendered table / record block leaves out because
     * they hold no value on any retrieved row (G3) — trace evidence, and what the prompt's
     * NOT RECORDED / NO VALUE lines name.
     *
     * @return list<string>
     */
    public function hiddenColumns(): array
    {
        $headers = $this->headersByColumn();

        return array_map(static fn (string $column) => $headers[$column] ?? $column, $this->omittedColumns());
    }

    /**
     * The column KEYS left out of every render of the rows (user table, record block, LLM
     * summary): empty on every retrieved row ({@see TableRenderer::emptyColumns()} — null /
     * blank / []; 0, false and "0" are values). Never all of them: a result whose every
     * column is empty keeps its columns (nothing to drop down to).
     *
     * @return list<string>
     */
    private function omittedColumns(): array
    {
        return count($this->emptyColumns) < count($this->renderer->columns($this->tableRows))
            ? $this->emptyColumns
            : [];
    }

    /**
     * Table column => its header, told apart over the FULL column set (the names the prompt
     * notes use, whatever the table then leaves out) — in the language the table is headed in
     * ({@see headerLocale()}).
     *
     * @return array<string, string>
     */
    private function headersByColumn(?string $locale = null): array
    {
        $columns = $this->renderer->columns($this->tableRows);

        return $columns === []
            ? []
            : array_combine($columns, $this->renderer->headers(
                $columns,
                [],
                $this->variableLabels,
                $this->tableProjections(),
                $this->headerLocale($locale)
            ));
    }

    /**
     * The language this answer's host text is said in: the caller's answer locale, else the
     * question's (QU); null = the app locale ({@see HostText}, the package's language policy).
     */
    private function hostLocale(?string $locale = null): ?string
    {
        foreach ([$locale, $this->language] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * The language the block's HEADERS are said in ({@see TableRenderer::headers()}): the
     * answer's ({@see hostLocale()}), else the app locale — always a locale, so a host line for
     * a header applies on every path.
     */
    private function headerLocale(?string $locale = null): ?string
    {
        $resolved = $this->hostLocale($locale);
        if ($resolved !== null) {
            return $resolved;
        }

        try {
            $app = function_exists('app') ? (string) app()->getLocale() : '';
        } catch (\Throwable $e) {
            $app = '';
        }

        return $app !== '' ? $app : null;
    }

    /**
     * The projections that head the TABLE's columns: none when the table is a nested list —
     * its columns are a collected map's keys, not the RETURN's columns.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function tableProjections(): array
    {
        return $this->nestedColumn === null ? $this->projections : [];
    }

    /**
     * The label of one of the RESULT's own columns (a FACT, a record field): its property
     * when the query aliased it after another word ({@see $projections}), else its alias.
     */
    public function columnLabel(string $column): string
    {
        return $this->renderer->translateHeader($this->renderer->label($column, [], $this->projections), $this->headerLocale());
    }

    /** A table column's plain label, as the table heads it (collisions aside — see headersByColumn()). */
    private function tableLabel(string $column): string
    {
        return $this->renderer->translateHeader($this->renderer->label($column, [], $this->tableProjections()), $this->headerLocale());
    }

    /**
     * The user's own identity values found in the visible table rows (THE
     * self-reference detection — SelfReferenceNoticeSection::selfValuesIn), each
     * mapped to the localized ai.response.self_reference_marker.
     *
     * @return array<string, string> value => marker
     */
    private function selfMarks(mixed $user, ?string $locale): array
    {
        if ($user === null) {
            return [];
        }

        $cap = $this->displayCap();
        $visible = $cap > 0 ? array_slice($this->tableRows, 0, $cap) : $this->tableRows;
        $matches = SelfReferenceNoticeSection::selfValuesIn($visible, is_array($user) ? (object) $user : $user);
        if ($matches === []) {
            return [];
        }

        $marker = SelfReferenceNoticeSection::THATS_YOU_MARKERS[0];
        try {
            $line = function_exists('trans') ? trans('ai.response.self_reference_marker', [], $locale) : null;
            if (is_string($line) && trim($line) !== '' && $line !== 'ai.response.self_reference_marker') {
                $marker = trim($line);
            }
        } catch (\Throwable $e) {
            // no translator — the canonical English marker
        }

        $marks = [];
        foreach ($matches as $match) {
            $marks[$match['value']] = $marker;
        }

        return $marks;
    }

    // =========================================================================
    // Build
    // =========================================================================

    private function build(PipelineContext $context): void
    {
        $this->moneyColumns = MoneyColumns::fromCypher($context->queryResult->cypher);
        $this->variableLabels = TableRenderer::variableLabels($context->queryResult->cypher);
        $this->subject = $this->subjectOf($context);
        $this->question = trim((string) preg_replace('/\s+/u', ' ', (string) $context->question));
        $this->previousQuestion = isset($context->qu) && $context->qu->followUpReference
            ? self::previousQuestionOf(is_array($context->conversationContext ?? null) ? $context->conversationContext : [], $this->question)
            : '';
        $this->language = isset($context->qu) && $context->qu->language !== '' ? $context->qu->language : null;

        $fallback = $context->executionResult->metadata['empty_fallback'] ?? null;
        if (is_array($fallback) && is_string($fallback['label'] ?? null) && is_string($fallback['fallback_label'] ?? null)
            && trim($fallback['label']) !== '' && trim($fallback['fallback_label']) !== '') {
            $this->fallback = ['label' => trim($fallback['label']), 'fallback_label' => trim($fallback['fallback_label'])];
        }
        $this->projections = $this->fallback === null
            ? TableRenderer::headerProjections(
                $context->queryResult->cypher,
                isset($context->qu) && $context->qu->language !== '' ? $context->qu->language : null
            )
            : [];
        $this->columnOwners = $this->fallback === null ? TableRenderer::columnVariables($context->queryResult->cypher) : [];

        $data = $context->executionResult->data;
        if (!is_array($data) || $data === []) {
            return;
        }

        // Coded values the host declares a legend for read as their LABEL in the answer
        // ("manager" → "Union manager", a status code → its name), in the question's
        // language — eval p16: rosters printed raw role keys. Columns are never dropped here.
        try {
            $data = CodedColumns::labelled(
                $data,
                $context->queryResult->cypher,
                $context->qu->language !== '' ? $context->qu->language : null
            );
        } catch (\Throwable $e) {
            // display nicety only: the raw values stand
        }

        $prepared = $this->renderer->prepare($data);
        if ($prepared === []) {
            return; // nothing displayable (e.g. only internal id columns)
        }

        $this->aggregateAliases = $this->aggregateAliasesOf($context->queryResult->cypher);

        if (count($prepared) === 1 && count($data) === 1) {
            $row = $prepared[0];
            $nested = $this->largestNestedTable($row);

            if ($nested !== null) {
                $this->mode = self::MODE_TABLE;
                $this->nestedColumn = $nested;
                $this->tableRows = $this->renderer->prepare($row[$nested]);
                $this->total = count($this->tableRows);
                $this->facts = array_filter($row, static fn ($v, $k) => $k !== $nested && !is_array($v), ARRAY_FILTER_USE_BOTH);
                $this->emptyColumns = $this->renderer->emptyColumns($this->tableRows);

                return;
            }

            if ($this->statedInProse($row)) {
                $this->mode = self::MODE_SINGLE;
                // An empty list (a breakdown that collected nothing) is no fact to state.
                $this->facts = array_filter($row, static fn ($value) => $value !== []);
                $this->total = 1;

                return;
            }
        }

        $this->mode = self::MODE_TABLE;
        $this->unmergedRows = $prepared;
        $this->tableRows = $this->withNamesMerged($prepared, $context->queryResult->cypher);
        $this->rowAligned = count($prepared) === count($data);
        $this->emptyColumns = $this->renderer->emptyColumns($this->tableRows);
        $records = $this->resolveRecords($context, $data);

        $trueTotal = $context->executionResult->metadata['true_total']
            ?? $context->executionResult->stats['true_total']
            ?? null;
        $count = count($data);
        if (is_numeric($trueTotal) && (int) $trueTotal >= $count) {
            $this->total = (int) $trueTotal;
        } else {
            $this->total = $count;
            $cap = (int) self::config('ai.query_execution.default_limit', 100);
            $this->totalKnown = $cap <= 0 || $count < $cap;
        }

        // The WHOLE result is one row: a record, shown as its fields (never a one-row table).
        $this->record = $count === 1 && count($prepared) === 1 && $this->total === 1 && $this->totalKnown;

        $anchorTotal = $context->executionResult->metadata['anchor_total'] ?? null;
        $this->fanOut = is_numeric($anchorTotal) && (int) $anchorTotal < $this->total;
        $this->recordKindLabel = $this->totalKnown ? $this->recordKindOf($context, $records, $count) : null;
        if ($this->recordKindLabel !== null) {
            try {
                $this->recordKindLabel = $this->askedKind($context, $this->recordKindLabel);
            } catch (\Throwable $e) {
                // a wording nicety — the resolved kind stands
            }
        }

        try {
            $this->askedColumns = $this->askedColumnsOf($context);
        } catch (\Throwable $e) {
            $this->askedColumns = []; // a facts-line nicety — never breaks a turn
        }
    }

    /**
     * THE COLUMNS THE QUESTION ASKED FOR (eval p20-B resilience t1: "…could you add their last
     * login to it?" — the added column was blank on 23 of the 25 rows shown and nothing said so).
     * Established by structure, three ways; a column qualifies on any of them:
     *
     *   (a) a PROPERTY CONCEPT Query Understanding named is a spelling the column's alias or the
     *       property it projects carries ({@see AliasNormalizer::mentions()} — the comparison
     *       PreviousResultExport makes: "last login" ↔ `last_login`);
     *   (b) the turn's SUBJECT (QU's label / search subject) or the QUESTION itself names the
     *       column: a run of their adjacent words is a spelling of its alias or property;
     *   (c) the turn is a FOLLOW-UP on the recorded result (QU follow_up_reference) and the
     *       column is one it ADDED: neither its alias nor its property is a column — or a
     *       projected property — of the recorded result, while at least one other column of this
     *       result is. On the p18–p20 turns QU named NO concept for "add their last login" and
     *       the generator's alias changed between runs (`last_login`, `last_logged_at`): only the
     *       addition itself tells the asked-for column every time.
     *
     * Never an aggregate's result (its emptiness means "nothing to compute"), a merged name
     * column, a nested table's column or fallback rows. No word list: spellings only.
     *
     * @return list<string> table column keys
     */
    private function askedColumnsOf(PipelineContext $context): array
    {
        if ($this->fallback !== null || $this->nestedColumn !== null || $this->record || !isset($context->qu)) {
            return [];
        }

        $qu = $context->qu;
        $strings = static fn (array $values): array => array_values(array_filter(
            array_map(static fn ($value) => is_string($value) ? trim($value) : '', $values),
            static fn (string $value): bool => $value !== ''
        ));
        $concepts = $strings((array) $qu->propertyConcepts);
        $texts = $strings([$qu->displaySubject(), $qu->searchQuery, (string) $context->question]);
        $projected = TableRenderer::columnProjections($context->queryResult->cypher);
        $merged = array_column($this->nameMerges, 'key');

        $names = [];
        foreach ($this->renderer->columns($this->tableRows) as $column) {
            if (in_array($column, $this->aggregateAliases, true) || in_array($column, $merged, true)) {
                continue;
            }
            $names[$column] = array_values(array_unique(array_filter([$column, (string) ($projected[$column][1] ?? '')])));
        }

        $asked = [];
        foreach ($names as $column => $own) {
            foreach ($own as $name) {
                foreach ($concepts as $concept) {
                    if (AliasNormalizer::mentions($name, $concept)) {
                        $asked[$column] = true;
                        continue 3;
                    }
                }
                foreach ($texts as $text) {
                    if (self::spells($text, $name)) {
                        $asked[$column] = true;
                        continue 3;
                    }
                }
            }
        }

        $recorded = $qu->followUpReference ? $this->recordedNames((array) $context->conversationContext) : [];
        if ($recorded !== []) {
            $carried = [];
            foreach ($names as $column => $own) {
                $carried[$column] = array_intersect_key(array_flip(array_map([AliasNormalizer::class, 'key'], $own)), $recorded) !== [];
            }
            if (in_array(true, $carried, true)) {
                foreach ($carried as $column => $isCarried) {
                    if (!$isCarried) {
                        $asked[$column] = true;
                    }
                }
            }
        }

        return array_values(array_filter(array_keys($names), static fn (string $column): bool => isset($asked[$column])));
    }

    /**
     * Whether a run of adjacent words of $text is a spelling of $name ({@see AliasNormalizer::key()}:
     * "last login" for `last_login`) — whole words only, never a substring of one.
     */
    private static function spells(string $text, string $name): bool
    {
        $key = AliasNormalizer::key($name);
        if ($key === '') {
            return false;
        }

        $tokens = AliasNormalizer::tokens($text);
        $count = count($tokens);
        for ($start = 0; $start < $count; $start++) {
            $run = '';
            for ($i = $start; $i < $count && strlen($run) < strlen($key); $i++) {
                $run .= $tokens[$i];
            }
            if ($run === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the conversation's RECORDED result carries, as spelling keys: the columns of its
     * sample rows, the columns its query returns and the properties they project
     * (`u.last_logged_at AS last_login` → both). Empty when the conversation recorded neither.
     *
     * @param array<string, mixed> $conversation the pipeline-stamped conversation context
     * @return array<string, true> AliasNormalizer key => true
     */
    private function recordedNames(array $conversation): array
    {
        $names = [];
        foreach ((array) ($conversation['last_result_sample'] ?? []) as $row) {
            foreach (is_array($row) ? array_keys($row) : [] as $column) {
                $names[] = (string) $column;
            }
        }
        $cypher = $conversation['last_cypher_query'] ?? null;
        if (is_string($cypher) && trim($cypher) !== '') {
            foreach (FanOutCollapse::returnItems($cypher) as $item) {
                $names[] = (string) $item['column'];
            }
            foreach (TableRenderer::columnProjections($cypher) as $projection) {
                $names[] = (string) $projection[1];
            }
        }

        $keys = [];
        foreach ($names as $name) {
            $key = AliasNormalizer::key($name);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * $rows with a split name shown as ONE column (R2, eval p19-A board) — and the merge
     * remembered ({@see $nameMerges}): the merged column belongs to the variable its parts are
     * projected from ({@see $columnOwners}), so the facts line and the notes still know whose
     * name it is. Fallback rows are another query's: never merged. Never breaks a turn.
     *
     * @param array<int, array<string, mixed>> $rows the prepared rows
     * @return array<int, array<string, mixed>>
     */
    private function withNamesMerged(array $rows, ?string $cypher): array
    {
        if ($this->fallback !== null) {
            return $rows;
        }

        try {
            $merges = TableRenderer::nameMerges($cypher, $rows);
            $merged = $this->renderer->mergeColumns($rows, $merges);
        } catch (\Throwable $e) {
            return $rows; // a display nicety — the query's own columns stand
        }

        $this->nameMerges = $merges;
        foreach ($merges as $merge) {
            $this->columnOwners[$merge['key']] = $merge['variable'];
        }

        return $merged;
    }

    /** The column a result column is SHOWN in: the merged name column it is part of, else itself. */
    private function shownColumn(string $column): string
    {
        foreach ($this->nameMerges as $merge) {
            if (in_array($column, $merge['columns'], true)) {
                return $merge['key'];
            }
        }

        return $column;
    }

    /**
     * A value of $column as a prompt NOTE prints it: masked as its column is — a merged name
     * column as its first identifying part is (the whole name then reads as that token).
     */
    private function maskedNoteValue(string $column, string $value): string
    {
        foreach ($this->nameMerges as $merge) {
            if ($merge['key'] !== $column) {
                continue;
            }
            foreach ($merge['columns'] as $part) {
                if ($this->masker->maskedKind($part) !== null) {
                    $column = $part;
                    break 2;
                }
            }
        }

        return $this->masker->maskFreeText((string) $this->masker->maskField($column, $value));
    }

    /**
     * The first $count table rows as the LLM reads them: masked per SOURCE column, then merged
     * like the user's table.
     *
     * @return array<int, array<string, mixed>>
     */
    private function maskedSample(int $count): array
    {
        if ($this->nameMerges === []) {
            return $this->masker->maskRows(array_slice($this->tableRows, 0, $count));
        }

        return $this->renderer->mergeColumns($this->masker->maskRows(array_slice($this->unmergedRows, 0, $count)), $this->nameMerges);
    }

    /**
     * The entity label the stated total COUNTS (P3, eval p17-A roster: "Showing 25 of 523
     * rows", the lead giving no count at all) — only when that is established, never guessed:
     *
     *   - the companion count of a capped result counted DISTINCT records of its anchor
     *     (execution metadata anchor_label / anchor_total, stamped by AiManager) and that
     *     count IS the total: 523 User records behind 523 rows. A fan-out the collapse could
     *     not fold (anchor_total below the row total) is counted in rows — no kind;
     *   - else the rows carry their records (RecordResolver, the chips' source of truth):
     *     every row one record of the SAME label, all different — the row count is that
     *     kind's count (37 board contacts).
     *
     * Fallback rows are another query's (no kind). Null = unknown: the neutral wording stands.
     *
     * @param array<int, array<string, mixed>>|null $records row index => record ({@see resolveRecords()})
     */
    private function recordKindOf(PipelineContext $context, ?array $records, int $rows): ?string
    {
        if ($this->fallback !== null) {
            return null;
        }

        $metadata = $context->executionResult->metadata;
        $anchorTotal = $metadata['anchor_total'] ?? null;
        if (is_numeric($anchorTotal)) {
            $label = $metadata['anchor_label'] ?? null;

            return (int) $anchorTotal === $this->total && is_string($label) && trim($label) !== '' ? trim($label) : null;
        }

        if ($records === null || $this->total !== $rows || count($records) !== $rows) {
            return null;
        }
        $identities = [];
        foreach ($records as $record) {
            $identities[(string) ($record['label'] ?? '')][(string) ($record['id'] ?? '')] = true;
        }
        $label = (string) array_key_first($identities);

        return count($identities) === 1 && $label !== '' && count($identities[$label]) === $rows ? $label : null;
    }

    /** Cypher's aggregating functions (language grammar, not vocabulary) — as RecordResolver. */
    private const AGGREGATE_CALL = '/\b(?:count|sum|avg|min|max|collect|stdev|stdevp|percentilecont|percentiledisc)\s*\(/i';

    /**
     * THE KIND THE QUESTION COUNTS (eval p18-A board). "Who is on our board of directors?" is
     * answered by rows carrying CONTACT ids — 37 contacts — while the question (QU: BoardMember)
     * counts board seats. The rows are counted as the asked kind when that is PROVEN, never
     * guessed: the first kind QU names that is either the resolved one (it stays) or a
     * {@see toOneOwners() to-one owner} of it for which the host declares a plural.
     *
     * Why it is a proof: every row is one distinct record of $label ({@see recordKindOf()});
     * each row binds exactly one owner; an owner holds ONE record of $label (the host declares
     * the link as its foreign key); so two rows can never share an owner — the rows are as many
     * distinct owners as they are records of $label.
     */
    private function askedKind(PipelineContext $context, string $label): string
    {
        $asked = isset($context->qu) ? array_values(array_filter((array) $context->qu->entityTypes, 'is_string')) : [];
        if ($asked === [] || $this->fallback !== null) {
            return $label;
        }

        $owners = null;
        foreach ($asked as $type) {
            $type = EntityLabelIndex::shared()->labelForTerm($type) ?? $type;
            if ($type === $label) {
                return $label;
            }
            $owners ??= $this->toOneOwners((string) $context->queryResult->cypher, $label);
            if (!isset($owners[$type])) {
                continue;
            }
            $config = EntityLabelIndex::shared()->configForLabel($type) ?? [];
            $plural = $config['metadata']['display_name_plural'] ?? $config['display_name_plural'] ?? null;
            if ((is_string($plural) && trim($plural) !== '') || (is_array($plural) && $plural !== [])) {
                return $type;
            }
        }

        return $label;
    }

    /**
     * The labels of the nodes that own exactly ONE record of $label on every row — structurally:
     *
     *   - the query links the node variable `v` to the (only) variable of $label by one
     *     relationship written in a MATCH that is not OPTIONAL (a row without `v` would not be
     *     one of them), in either direction of writing;
     *   - `v`'s entity config declares that relationship type towards $label with a
     *     `foreign_key` — the host's statement that each of them has one such record;
     *   - the final RETURN is not grouped (no aggregate) and projects a property of `v`: the
     *     node is still bound, one per row, where the rows are made (a `WITH DISTINCT c` that
     *     drops it, or `count(v)`, would fold several of them into one row).
     *
     * Any unknown yields nothing.
     *
     * @return array<string, true> owner label => true
     */
    private function toOneOwners(string $cypher, string $label): array
    {
        $recordVariables = array_keys(array_filter($this->variableLabels, static fn (string $bound): bool => $bound === $label));
        if (count($recordVariables) !== 1) {
            return [];
        }
        $record = (string) $recordVariables[0];

        $items = FanOutCollapse::returnItems($cypher);
        if ($items === []) {
            return [];
        }
        foreach ($items as $item) {
            if (preg_match(self::AGGREGATE_CALL, (string) $item['expression']) === 1) {
                return [];
            }
        }
        $projected = [];
        foreach (TableRenderer::columnProjections($cypher) as $projection) {
            $projected[(string) $projection[0]] = true;
        }

        $text = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/", "''", $cypher);
        $node = '\(\s*([A-Za-z_]\w*)\b[^()]*\)';
        $next = '(?=\(\s*([A-Za-z_]\w*)\b)';
        // ONE relationship of ONE type: a variable-length (`*`) or alternative (`|`) link proves nothing.
        $type = '\[[^\]*|]*?:\s*`?([A-Za-z_]\w*)`?[^\]*|]*\]';
        $links = [];
        // (v)-[:T]->(record)
        if (preg_match_all('/' . $node . '\s*-\s*' . $type . '\s*->\s*' . $next . '/', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $hit) {
                $links[] = [$hit[1][0], $hit[2][0], $hit[3][0], (int) $hit[0][1]];
            }
        }
        // (record)<-[:T]-(v)
        if (preg_match_all('/' . $node . '\s*<-\s*' . $type . '\s*-\s*' . $next . '/', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $hit) {
                $links[] = [$hit[3][0], $hit[2][0], $hit[1][0], (int) $hit[0][1]];
            }
        }

        $owners = [];
        foreach ($links as [$from, $relationship, $to, $offset]) {
            $ownerLabel = $this->variableLabels[$from] ?? null;
            if ($to !== $record || $ownerLabel === null || $ownerLabel === $label || !isset($projected[$from])) {
                continue;
            }
            // The MATCH this pattern is written in must not be OPTIONAL.
            if (preg_match_all('/\b(OPTIONAL\s+)?MATCH\b/i', substr($text, 0, $offset), $clauses, PREG_SET_ORDER) < 1
                || (end($clauses)[1] ?? '') !== '') {
                continue;
            }
            $config = EntityLabelIndex::shared()->configForLabel($ownerLabel) ?? [];
            foreach ((array) ($config['graph']['relationships'] ?? []) as $declared) {
                if (is_array($declared)
                    && ($declared['type'] ?? null) === $relationship
                    && ($declared['target_label'] ?? $label) === $label
                    && ($declared['direction'] ?? 'outgoing') === 'outgoing'
                    && is_string($declared['foreign_key'] ?? null) && trim($declared['foreign_key']) !== '') {
                    $owners[$ownerLabel] = true;
                }
            }
        }

        return $owners;
    }

    /**
     * The PLURAL the host gives the counted record kind, IN THE ANSWER'S LANGUAGE
     * ({@see noun()}). Null when no kind is established, the host declares no plural, or it
     * cannot be said in the answer's language ("Affichage de 25 members sur 523" is worse than
     * the neutral line): nothing is inflected, translated or guessed here — the neutral
     * wording stands.
     */
    private function recordKind(?string $locale): ?string
    {
        return $this->recordKindLabel === null ? null : $this->noun($this->recordKindLabel, true, $locale);
    }

    /**
     * The word the HOST gives an entity — its plural (entity config
     * `metadata.display_name_plural`, else top-level `display_name_plural`) or its singular
     * (`metadata.display_name` / `display_name`) — as a sentence in the answer's language can
     * say it ({@see HostText::inLanguage()}: the host's translation of it, the per-language
     * text when the config declares one, or the declared text itself in the language host
     * config is written in). Null when the host declares none or it cannot be said in that
     * language: the caller keeps its neutral wording / the model's instruction. Never a label
     * turned into words, never a humanized column header.
     */
    private function noun(?string $label, bool $plural, ?string $locale): ?string
    {
        if ($label === null || trim($label) === '') {
            return null;
        }

        try {
            $config = EntityLabelIndex::shared()->configForLabel(trim($label)) ?? [];
            $key = $plural ? 'display_name_plural' : 'display_name';

            return HostText::inLanguage($config['metadata'][$key] ?? $config[$key] ?? null, $this->hostLocale($locale));
        } catch (\Throwable $e) {
            return null; // a wording nicety — never breaks a turn
        }
    }

    /**
     * The entity (node label) a table column belongs to: the label of the node variable the
     * query projects it from (`un.name AS union_name` → Union). Null for an expression, an
     * aggregate, a nested table's column or fallback rows — nothing is read from the column's
     * name.
     */
    private function columnEntity(string $column): ?string
    {
        if ($this->nestedColumn !== null) {
            return null;
        }
        $variable = $this->columnOwners[$column] ?? null;

        return $variable !== null ? ($this->variableLabels[$variable] ?? null) : null;
    }

    // =========================================================================
    // The facts line (code-written, above the block)
    // =========================================================================

    /**
     * THE FACTS LINE (eval p18): what code states itself right above the table / record block,
     * so the model is never asked to say it. One short line of up to two sentences:
     *
     *   - "3 different units named 310 — one per union."  ({@see homonymFact()}) — or —
     *     "37 board members across 12 unions." / "523 members."  ({@see countFact()});
     *   - "Not recorded: board role."  ({@see notRecordedFact()}).
     *
     * Wording is the package's lang templates (ai.response.facts_*); every noun is the HOST's
     * ({@see noun()}), every field name the block's own header. A part whose noun the host did
     * not declare — or cannot say in the answer's language — or whose template is missing is
     * simply absent: `stated` lists what IS printed, and {@see answerFormatLines()} keeps
     * asking the model for the rest, as before. Only beside a code-rendered block.
     *
     * @return array{stated: list<string>, line: string}
     */
    private function facts(?string $locale): array
    {
        $none = ['stated' => [], 'line' => ''];
        if (!$this->hasTable()) {
            return $none;
        }

        try {
            $sentences = [];
            $stated = [];

            $homonyms = $this->homonymFact($locale);
            if ($homonyms !== null) {
                $sentences[] = $homonyms;
                $stated[] = 'homonyms';
            } else {
                [$count, $grouped] = $this->countFact($locale);
                if ($count !== null) {
                    $sentences[] = $count;
                    $stated[] = 'count';
                    if ($grouped) {
                        $stated[] = 'groups';
                    }
                }
            }

            $missing = $this->notRecordedFact($locale);
            if ($missing !== null) {
                $sentences[] = $missing;
                $stated[] = 'not_recorded';
            }

            $partly = $this->partlyRecordedFact($locale);
            if ($partly !== null) {
                $sentences[] = $partly;
                $stated[] = 'partly_recorded';
            }

            return ['stated' => $stated, 'line' => implode(' ', $sentences)];
        } catch (\Throwable $e) {
            return $none; // never breaks a turn — the model's instructions cover it
        }
    }

    /**
     * "N <records>." or "N <records> across G <groups>." — when a count is due (a truncated
     * list whose true total is known, a list with grand totals) or the rows group, and the
     * stated total COUNTS a kind the host named ({@see recordKind()} — never a fan-out's row
     * count, never an unknown total). The grouping joins the sentence only when every row was
     * retrieved (else the groups are a lower bound) and the host named the entity the group
     * column belongs to ({@see columnEntity()}); only the coarsest grouping is stated.
     *
     * @return array{0: ?string, 1: bool} [the sentence, whether it states the grouping]
     */
    private function countFact(?string $locale): array
    {
        $rows = count($this->tableRows);
        if ($this->record || $rows < 2) {
            return [null, false];
        }
        $kind = $this->recordKind($locale);
        if ($kind === null) {
            return [null, false];
        }

        $grouping = $this->groupings[0] ?? null;
        $groupKind = $grouping !== null && $this->totalKnown && $this->total <= $rows
            ? $this->noun($this->columnEntity($grouping['column']), true, $locale)
            : null;
        if ($groupKind !== null) {
            $sentence = $this->factText('ai.response.facts_count_groups', [
                'count' => $this->total,
                'kind' => $kind,
                'groups' => count($grouping['groups']),
                'group_kind' => $groupKind,
            ], $locale);
            if ($sentence !== null) {
                return [$sentence, true];
            }
        }

        $counted = $this->labeledGrandTotals() !== [] || ($this->truncated() && !$this->fanOut);

        return [$counted ? $this->factText('ai.response.facts_count', ['count' => $this->total, 'kind' => $kind], $locale) : null, false];
    }

    /**
     * "N different <records> named X — one per <by>." — when the WHOLE result is one set of
     * homonyms by record identity (never the value-only guess), every one of them is on screen,
     * each differs by its own value of the `by` column (so "one per" is true), and the host
     * named both entities: the records' (the entity their name column belongs to, plural) and
     * the `by` column's (singular). The name is the rows' own value, escaped like a cell.
     */
    private function homonymFact(?string $locale): ?string
    {
        $parts = $this->homonymParts($locale);

        return $parts === null ? null : $this->factText('ai.response.facts_homonyms', $parts, $locale);
    }

    /**
     * The namesakes as a PLURAL SUBJECT — "the 3 units named 310" — from the very facts of the
     * homonym statement ({@see homonymParts()}) and a lang template of its own
     * (ai.response.facts_homonyms_subject), in the answer's language. Null wherever the
     * statement itself is not code's, or the template is missing in that language.
     *
     * Eval p20-A unit-310 t0 / t1: the lead read "Unit 310 currently owes $0.00 across all
     * unions." right above "3 different units named 310 — one per union." — one unit, where the
     * line says three. The prompt gives the model this subject ({@see answerFormatLines()}).
     */
    private function homonymSubject(?string $locale): ?string
    {
        $parts = $this->homonymParts($locale);

        return $parts === null ? null : $this->factText('ai.response.facts_homonyms_subject', $parts, $locale);
    }

    /**
     * The facts of the code-written homonym statement, or null when it is not code's to make
     * ({@see homonymFact()} for the conditions).
     *
     * @return array{count: int, kind: string, name: string, by: string}|null
     */
    private function homonymParts(?string $locale): ?array
    {
        $rows = count($this->tableRows);
        if ($this->record || $this->identityHomonyms === null || !$this->totalKnown || $this->total > $rows || $this->shown() < $rows) {
            return null;
        }

        foreach ($this->identityHomonyms as $group) {
            if (!$this->coversAllRows($group)) {
                continue;
            }
            if (count($group['values']) !== (int) $group['count']) {
                return null;
            }
            $kind = $this->noun($this->columnEntity((string) $group['column']), true, $locale);
            $by = $this->noun($this->columnEntity((string) $group['by']), false, $locale);
            if ($kind === null || $by === null) {
                return null;
            }

            return [
                'count' => (int) $group['count'],
                'kind' => $kind,
                'name' => $this->renderer->escapeInline((string) $group['value']),
                'by' => $by,
            ];
        }

        return null;
    }

    /**
     * "Not recorded: <fields>." — the STORED columns the block leaves out because no row holds
     * a value for them ({@see omittedColumns()}; an aggregate with nothing to compute is not a
     * field the data fails to record), named by the block's own headers. Only when every row
     * was retrieved: a column empty on the first 100 of 523 rows proves nothing about the rest.
     */
    private function notRecordedFact(?string $locale): ?string
    {
        if (!$this->totalKnown || $this->total > count($this->tableRows)) {
            return null;
        }
        $stored = array_values(array_diff($this->omittedColumns(), $this->aggregateAliases));
        if ($stored === []) {
            return null;
        }

        $headers = $this->headersByColumn($locale);
        $fields = array_map(fn (string $column): string => $this->renderer->escapeInline($headers[$column] ?? $column), $stored);

        return $this->factText('ai.response.facts_not_recorded', ['fields' => implode(', ', $fields)], $locale);
    }

    /**
     * "<Field> recorded for F of the S shown." / "<Field> recorded for F of N." — for a column
     * the QUESTION asked for ({@see askedColumnsOf()}) that holds a value on SOME rows only
     * (eval p20-B resilience t1: "add their last login" — blank on 23 of the 25 rows shown, and
     * not a word about it). Stated ONLY over rows the count is proven for:
     *
     *   - every row of the result is held (the total is known and all of it was retrieved):
     *     counted over all N of them, also the ones below the display cap;
     *   - else over the rows the table SHOWS — a partial retrieval says nothing about the rest
     *     (the column may be full or empty there), so nothing is claimed about it.
     *
     * Skipped for a column filled on every counted row, and for one empty on every retrieved
     * row — that is {@see notRecordedFact()} / the NOT RECORDED note. A column empty on the rows
     * shown but recorded further down a partial retrieval IS stated ("0 of the 25 shown"): the
     * user sees a blank column. The field is named by the block's own header; at most
     * PARTLY_RECORDED_MAX columns, in table order.
     */
    private function partlyRecordedFact(?string $locale): ?string
    {
        $rows = $this->tableRows;
        if ($this->record || $this->askedColumns === [] || count($rows) < 2) {
            return null;
        }

        $complete = $this->totalKnown && $this->total <= count($rows);
        $counted = $complete ? $rows : array_slice($rows, 0, $this->shown());
        $of = count($counted);
        if ($of < 2) {
            return null;
        }

        $headers = $this->headersByColumn($locale);
        $sentences = [];
        foreach ($this->askedColumns as $column) {
            if (in_array($column, $this->emptyColumns, true)) {
                continue;
            }
            $filled = 0;
            foreach ($counted as $row) {
                $value = $row[$column] ?? null;
                $filled += $value !== null && $value !== [] && !(is_string($value) && trim($value) === '') ? 1 : 0;
            }
            if ($filled === $of) {
                continue;
            }

            $sentence = $this->factText(
                $complete ? 'ai.response.facts_partly_recorded' : 'ai.response.facts_partly_recorded_shown',
                ['field' => $this->renderer->escapeInline($headers[$column] ?? $column), 'filled' => $filled, 'rows' => $of],
                $locale
            );
            if ($sentence !== null) {
                $sentences[] = $sentence;
            }
            if (count($sentences) >= self::PARTLY_RECORDED_MAX) {
                break;
            }
        }

        return $sentences === [] ? null : implode(' ', $sentences);
    }

    /**
     * A facts template in the answer's language (a regional code falls back to its primary
     * language) — null when the package / host ships no line for it there: that fact is then
     * not printed, never said in another language.
     *
     * @param array<string, scalar> $replace
     */
    private function factText(string $key, array $replace, ?string $locale): ?string
    {
        $locale = $this->hostLocale($locale);
        $candidates = [$locale];
        if ($locale !== null && preg_match('/^([A-Za-z]+)[-_]/', $locale, $m) === 1) {
            $candidates[] = strtolower($m[1]);
        }

        foreach ($candidates as $candidate) {
            try {
                $line = trans($key, $replace, $candidate);
            } catch (\Throwable $e) {
                continue;
            }
            if (is_string($line) && trim($line) !== '' && $line !== $key) {
                return trim($line);
            }
        }

        return null;
    }

    /**
     * The values that identify the rows of the code-rendered block, for the row-echo guard
     * ({@see finalize()}, {@see NarrativeRowEcho}) — real values, as the unmasked lead holds them.
     *
     *   - a TABLE: the distinct values of its first ROW_ECHO_KEY_COLUMNS displayed LABEL
     *     columns — text on every row that has it (never a number, a date / time, an
     *     aggregate's result or declared money: those are a row's figures, shared by many)
     *     and not a coarse grouping (a value repeated on GROUP_MIN_AVERAGE_ROWS rows or more
     *     on average is a parent the rows are spread over, or the one name every row shares —
     *     "310" in three unions: it identifies no row, and a list naming it is a per-group
     *     summary, not a row list);
     *   - ONE RECORD: its "rows" are its fields — every displayed scalar value, as the block
     *     and the prompt show it.
     *
     * No column qualifies → no keys → nothing is ever dropped.
     *
     * @return list<string>
     */
    private function rowEchoKeys(?string $locale): array
    {
        $rows = $this->tableRows;
        $columns = array_values(array_diff($this->renderer->columns($rows), $this->omittedColumns()));
        $keys = [];

        if ($this->record) {
            foreach ($columns as $column) {
                $value = $rows[0][$column] ?? null;
                if ($value === null || is_bool($value) || is_array($value)) {
                    continue;
                }
                $money = $this->isMoney($column) && $this->renderer->isNumericValue($value);
                foreach (array_unique([$locale, 'en']) as $form) {
                    $keys[] = $money ? $this->renderer->formatMoney($value, $form) : $this->renderer->cell($value, true, $form);
                }
                $keys[] = is_string($value) ? trim($value) : $this->renderer->cell($value);
            }

            return array_values(array_unique($keys));
        }

        $taken = 0;
        foreach ($columns as $column) {
            if (in_array($column, $this->aggregateAliases, true) || $this->isMoney($column)) {
                continue;
            }
            $values = [];
            $filled = 0;
            foreach ($rows as $row) {
                $value = $row[$column] ?? null;
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    continue;
                }
                if (!is_string($value) || TableRenderer::isTemporalValue($value)) {
                    continue 2;
                }
                $filled++;
                $values[mb_strtolower(trim($value))] = trim($value);
            }
            if ($values === [] || count($values) * self::GROUP_MIN_AVERAGE_ROWS <= $filled) {
                continue;
            }
            array_push($keys, ...array_values($values));
            if (++$taken >= self::ROW_ECHO_KEY_COLUMNS) {
                break;
            }
        }

        return $keys;
    }

    /**
     * Whether a one-row result is small enough for the MODEL to state (FACTS, prose, no
     * code-rendered block) — the only place a fact still depends on being copied:
     *
     *   - at most RECORD_PROSE_MAX_COLUMNS columns, whatever they hold ("Unit 310 owes
     *     $1,250.50"; "the last meeting was X on <date>"): no loss was ever observed;
     *   - at most SINGLE_VALUE_MAX_COLUMNS columns that are ALL measures — an aggregate
     *     row, whose numbers are the answer itself ("You owe $11,493.17 across 4 open
     *     bills to 4 suppliers"). A one-number answer never grows a table.
     *
     * Any other row DESCRIBES something (a name, a date, a status next to its other
     * fields) and is a record: code lists its fields ({@see $record}). A row holding a
     * list is never prose — except an EMPTY list, which is no value at all (the live
     * "nothing owed" shape: totals of 0 beside an empty breakdown).
     *
     * @param array<string, mixed> $row the prepared (displayable) row
     */
    private function statedInProse(array $row): bool
    {
        $lists = array_filter($row, static fn ($value) => is_array($value) && $value !== []);
        if ($lists !== [] || count($row) > self::SINGLE_VALUE_MAX_COLUMNS) {
            return false;
        }
        if (count($row) <= self::RECORD_PROSE_MAX_COLUMNS) {
            return true;
        }

        foreach ($row as $column => $value) {
            if (!$this->isMeasure((string) $column, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A MEASURE: the result of an aggregate (by the cypher's grammar — `min(b.due_at) AS
     * oldest_due` is one even though its value is a date), or a number / nothing by value.
     * Text, a date or a flag projected from a record describes that record instead.
     */
    private function isMeasure(string $column, mixed $value): bool
    {
        if (in_array($column, $this->aggregateAliases, true)) {
            return true;
        }

        return $value === null
            || $value === []
            || (is_string($value) && trim($value) === '')
            || (!is_bool($value) && $this->renderer->isNumericValue($value));
    }

    /**
     * The cypher's aggregate aliases (RecordResolver — language grammar, not vocabulary).
     *
     * @return list<string>
     */
    private function aggregateAliasesOf(?string $cypher): array
    {
        try {
            return (new RecordResolver([], $cypher))->aggregateAliases();
        } catch (\Throwable $e) {
            return []; // never breaks a turn — measures are then told by value alone
        }
    }

    /**
     * Homonym groups by record IDENTITY (eval p10-A): the raw rows still carry the record
     * ids (RecordIdProjection) that the table drops, and RecordResolver — the chips' single
     * source of truth — tells "unit 310 in three unions" (three ids) from four units of ONE
     * union (union id 8 on every row), which values alone cannot. Labels come from the
     * cypher's node patterns (every node with an id is a record here, openable or not), the
     * preference from QU. Unknown identity (some row without an attributable id, or rows not
     * 1:1 with the table) leaves $identityHomonyms null: the value-only fallback runs.
     *
     * The same resolution tells how the rows GROUP ({@see groupingsOf()}, G1) — which needs
     * every row's record too, so it stays silent on an unknown identity.
     *
     * @param array<int, mixed> $data the executed rows (ids included)
     * @return array<int, array<string, mixed>>|null row index => record (RecordResolver::resolve); null = unknown
     */
    private function resolveRecords(PipelineContext $context, array $data): ?array
    {
        $cypher = $context->queryResult->cypher;
        $labels = array_values(array_unique($this->variableLabels));
        $preferred = isset($context->qu) ? (array) $context->qu->entityTypes : [];

        try {
            $resolver = new RecordResolver($labels, $cypher, $preferred);
            $this->aggregateAliases = $resolver->aggregateAliases();
            if (!$this->rowAligned || $labels === []) {
                return null;
            }
            $groups = $resolver->homonyms(array_values($data));
            $records = $resolver->resolve(array_values($data));
        } catch (\Throwable $e) {
            return null; // never breaks a turn — the value-only fallback runs
        }

        if ($groups !== null) {
            // Only notes about columns the table shows (a redacted column is never named) — a
            // name part is shown inside its merged name column.
            $shown = array_flip($this->renderer->columns($this->tableRows));
            $groups = array_map(fn (array $group): array => [
                'column' => $this->shownColumn((string) $group['column']),
                'by' => $this->shownColumn((string) $group['by']),
            ] + $group, $groups);
            $this->identityHomonyms = array_values(array_filter(
                $groups,
                static fn (array $group) => $group['column'] !== $group['by'] && isset($shown[$group['column']], $shown[$group['by']])
            ));
        }

        try {
            $this->groupings = $this->groupingsOf($records, $cypher);
        } catch (\Throwable $e) {
            $this->groupings = []; // a prompt nicety — never breaks a turn
        }

        return $records;
    }

    /**
     * How the rows GROUP (G1, eval p16-A board: 37 board members over 9 unions answered as
     * "the members of your board" — the 10-row summary showed three unions and nothing said
     * the rows belong to several). A grouping column is, structurally:
     *
     *   - projected from ANOTHER node variable than the listed record's
     *     ({@see TableRenderer::columnVariables()} — `un.name` beside Contact rows): a
     *     parent / group. The record's OWN repeated attribute (a shared family name) and a
     *     computed expression (no single owner) never qualify — and nothing is read from the
     *     column's name;
     *   - TEXT on every row that has it — never a number, a date / time (a per-row fact),
     *     an aggregate's result, declared money or a masked identifying value;
     *   - COARSE: at least two distinct values, and GROUP_MIN_AVERAGE_ROWS rows per value on
     *     average (one value per row — "unit 310" in three unions — is the homonym note's
     *     case, not a grouping).
     *
     * The listed record is RecordResolver's (the chips' source of truth); its variable is
     * the only one bound to its label, else the one its name column is projected from. Any
     * unknown — a row without an attributable record, rows of different kinds, an ambiguous
     * variable — states nothing. Coarsest first, GROUPINGS_MAX at most; groups in row order.
     *
     * @param array<int, array<string, mixed>> $records row index => record (RecordResolver::resolve)
     * @return list<array{column: string, groups: list<array{0: string, 1: int}>, blank: int}>
     */
    private function groupingsOf(array $records, ?string $cypher): array
    {
        $rows = $this->tableRows;
        $n = count($rows);
        if ($n < 2 * self::GROUP_MIN_AVERAGE_ROWS || count($records) !== $n) {
            return [];
        }

        $labels = array_values(array_unique(array_map(static fn (array $r) => (string) ($r['label'] ?? ''), $records)));
        if (count($labels) !== 1 || $labels[0] === '') {
            return [];
        }

        $owners = TableRenderer::columnVariables($cypher);
        $recordVariable = $this->recordVariable($labels[0], $records, $owners);
        if ($recordVariable === null) {
            return [];
        }

        $found = [];
        foreach ($this->renderer->columns($rows) as $order => $column) {
            $owner = $owners[$column] ?? null;
            if ($owner === null || $owner === $recordVariable
                || in_array($column, $this->aggregateAliases, true)
                || $this->isMoney($column)
                || $this->masker->maskedKind($column) !== null) {
                continue;
            }

            $groups = [];
            $blank = 0;
            foreach ($rows as $row) {
                $value = $row[$column] ?? null;
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    $blank++;
                    continue;
                }
                if (!is_string($value) || TableRenderer::isTemporalValue($value)) {
                    continue 2;
                }
                $key = mb_strtolower(trim($value));
                $groups[$key] ??= [trim($value), 0];
                $groups[$key][1]++;
            }

            $distinct = count($groups);
            if ($distinct < 2 || $distinct * self::GROUP_MIN_AVERAGE_ROWS > $n - $blank) {
                continue;
            }
            $found[] = ['rank' => [$distinct, $order], 'column' => $column, 'groups' => array_values($groups), 'blank' => $blank];
        }

        usort($found, static fn (array $a, array $b) => $a['rank'] <=> $b['rank']);

        return array_map(
            static fn (array $g) => ['column' => $g['column'], 'groups' => $g['groups'], 'blank' => $g['blank']],
            array_slice($found, 0, self::GROUPINGS_MAX)
        );
    }

    /**
     * The node variable of the listed record: the only variable bound to its label in the
     * cypher, else (two variables of one label — a unit and its parent unit) the one every
     * record's name column is projected from. Null when it cannot be told.
     *
     * @param array<int, array<string, mixed>> $records
     * @param array<string, string> $owners result column => node variable
     */
    private function recordVariable(string $label, array $records, array $owners): ?string
    {
        $variables = array_keys(array_filter($this->variableLabels, static fn (string $bound) => $bound === $label));
        if (count($variables) === 1) {
            return (string) $variables[0];
        }

        $named = [];
        foreach ($records as $record) {
            $variable = $owners[(string) ($record['name_column'] ?? '')] ?? null;
            if ($variable === null || !in_array($variable, $variables, true)) {
                return null;
            }
            $named[$variable] = true;
        }

        return count($named) === 1 ? (string) array_key_first($named) : null;
    }

    /** The row's column holding the longest non-empty list of maps (a collected breakdown). */
    private function largestNestedTable(array $row): ?string
    {
        $best = null;
        $bestCount = 0;
        foreach ($row as $column => $value) {
            if (!is_array($value) || $value === [] || !array_is_list($value)) {
                continue;
            }
            $maps = array_filter($value, static fn ($item) => is_array($item) && $item !== [] && !array_is_list($item));
            if (count($maps) === count($value) && count($value) > $bestCount) {
                $best = (string) $column;
                $bestCount = count($value);
            }
        }

        return $best;
    }

    /**
     * What the rows answer (E3): QU's search query (the subject as a phrase), else its
     * clarified question, else the user's question — one line, capped. Structural: the
     * pipeline's own text for this turn, never a word list.
     */
    private function subjectOf(PipelineContext $context): string
    {
        $qu = isset($context->qu) ? $context->qu : null;
        foreach ([$qu?->searchQuery, $qu?->clarifiedQuestion, $context->question] as $candidate) {
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) $candidate));
            if ($text !== '') {
                return mb_strlen($text) > self::SUBJECT_MAX_CHARS
                    ? rtrim(mb_substr($text, 0, self::SUBJECT_MAX_CHARS - 1)) . '…'
                    : $text;
            }
        }

        return '';
    }

    /** Whether a result column is declared money (P-3 MoneyColumns). */
    private function isMoney(string $column): bool
    {
        return in_array($column, $this->moneyColumns, true);
    }

    /** A figure stated to the model: money (with the configured currency) for a money column. */
    private function figure(float $value, bool $money): string
    {
        return $money ? $this->renderer->formatMoney($value, 'en') : $this->number($value);
    }

    // =========================================================================
    // Prompt pieces
    // =========================================================================

    /**
     * The totals the plan states to the model — the SINGLE source for the prompt
     * lines (totalsLines) and the AnswerValidator's evidence/patch figures.
     *
     * @return array{query: array<string, float>, sums: array<string, float>, complete: bool}
     */
    private function totalsSpec(): array
    {
        $spec = ['query' => [], 'sums' => [], 'complete' => false];
        $rows = $this->tableRows;
        if ($this->mode !== self::MODE_TABLE || count($rows) < 2) {
            return $spec;
        }

        $spec['complete'] = $this->totalKnown && $this->total <= count($rows);

        // P-4: a constant decimal is the query's total only when it equals (complete)
        // or covers (truncated, money column) the sum of a varying column.
        foreach ($this->renderer->constantTotalColumns($rows, $spec['complete'], $this->moneyColumns) as $column) {
            $spec['query'][$column] = (float) $rows[0][$column];
        }

        $factHasDecimal = array_filter($this->facts, fn ($v) => $this->renderer->isDecimal($v)) !== [];
        if ($spec['query'] !== [] || $factHasDecimal) {
            return $spec;
        }

        // G2 (eval p16-A 310 t0 / ambiguous t1): the rows are DIFFERENT records sharing one
        // name. A sum across them is the merge the HOMONYMS note forbids and answers a
        // question nobody asked — it is neither stated nor offered to the validator. Each
        // record's own figure stays in the table.
        if ($this->homonymsAreTheWholeResult()) {
            return $spec;
        }

        // P-3: only DECLARED MONEY columns (entity config metadata.money_properties,
        // mapped through the cypher projections) are summed; otherwise the model gets
        // the row count only.
        foreach ($this->renderer->summableColumns($rows, $this->moneyColumns) as $column) {
            if ($this->masker->maskedKind($column) === null) {
                $spec['sums'][$column] = $this->renderer->sum($rows, $column);
            }
        }

        return $spec;
    }

    /** @return array<int, string> */
    private function totalsLines(): array
    {
        $spec = $this->totalsSpec();
        $lines = [];

        foreach ($spec['query'] as $column => $value) {
            $lines[] = 'QUERY TOTAL: the column "' . $this->tableLabel($column) . '" holds the same value '
                . $this->figure($value, $this->isMoney($column)) . ' on every row — it is the total computed by the query. '
                . 'Use it as the total; never add rows yourself.';
        }

        if ($spec['sums'] === []) {
            return $lines === [] ? [] : [...$lines, ''];
        }

        $n = count($this->tableRows);
        $complete = $spec['complete'];
        $lines[] = $complete
            ? "COMPUTED TOTALS (computed by the system over all {$n} rows — use these numbers, do not add rows yourself):"
            : "PARTIAL SUMS (computed by the system over the {$n} retrieved rows only — the full result has "
                . $this->totalPhrase() . ' rows, so these are NOT grand totals; say so if you mention them):';
        foreach ($spec['sums'] as $column => $sum) {
            $scope = $complete ? "over all {$n} rows" : "over the {$n} retrieved rows";
            // Only declared money columns are ever summed (P-3): the figure carries the currency.
            $lines[] = '- TOTAL of ' . $this->tableLabel($column) . " {$scope} = " . $this->figure($sum, true);
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * Every figure this plan stated to the model: FACTS values, query totals,
     * computed / partial sums, the row total and the shown count (AnswerValidator
     * evidence — a number the answer states must be one of the turn's figures).
     *
     * @return list<float>
     */
    public function statedFigures(): array
    {
        $spec = $this->totalsSpec();
        $figures = [...array_values($spec['query']), ...array_values($spec['sums']), (float) $this->total, (float) $this->shown()];
        foreach ($this->facts as $value) {
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
                $figures[] = (float) $value;
            }
        }

        return $figures;
    }

    /**
     * The row values of the DECLARED money columns (P-3 metadata.money_properties
     * via MoneyColumns) — never ids, phones or postal codes. The AnswerValidator
     * uses them to recognize a subset sum next to the grand total.
     *
     * @return list<float>
     */
    public function moneyRowFigures(): array
    {
        $figures = [];
        foreach ($this->tableRows as $row) {
            foreach ($this->moneyColumns as $column) {
                $value = $row[$column] ?? null;
                if ($value !== null && $this->renderer->isNumericValue($value)) {
                    $figures[] = (float) $value;
                }
            }
        }

        return $figures;
    }

    /**
     * The GRAND totals of a complete result (query-total columns, or computed sums
     * when the plan saw every row). A mis-summed aggregate in the answer is patched
     * to one of these — partial sums never qualify.
     *
     * @return list<float>
     */
    public function grandTotals(): array
    {
        $spec = $this->totalsSpec();
        if ($spec['query'] !== []) {
            return array_values($spec['query']);
        }

        return $spec['complete'] ? array_values($spec['sums']) : [];
    }

    /**
     * LLM-facing summary only: beside every masked email value, a derived
     * `<column>_domain` with the part after "@" — not identifying on its own, and it
     * is what domain questions turn on (live 2026-09-30: after "only the ones with a
     * gmail address" the model saw only [email#N] tokens and claimed none matched,
     * although the query had filtered correctly). The local part stays masked.
     *
     * @param array<int, array<string, mixed>> $maskedRows rows already masked
     * @param array<int, array<string, mixed>> $realRows   the same rows, real values
     * @return array<int, array<string, mixed>>
     */
    private function withEmailDomains(array $maskedRows, array $realRows): array
    {
        if (!$this->masker->enabled()) {
            return $maskedRows;
        }

        $out = [];
        foreach ($maskedRows as $i => $row) {
            $withDomains = [];
            foreach ($row as $column => $value) {
                $withDomains[$column] = $value;
                $real = $realRows[$i][$column] ?? null;
                if (is_string($real) && $real !== $value
                    && preg_match('/^[^\s@]+@([^\s@]+\.[A-Za-z]{2,})$/', trim($real), $m) === 1) {
                    $withDomains[$column . '_domain'] = strtolower($m[1]);
                }
            }
            $out[] = $withDomains;
        }

        return $out;
    }

    /**
     * Header overrides for the summary's derived `<column>_domain` columns
     * ({@see withEmailDomains()}) whose source column is headed by its property rather than
     * its alias: the derived column follows it ("Email" → "Email Domain", never "Contact
     * Info Domain" beside "Email"). Other derived columns keep their plain label.
     *
     * @param array<int, array<string, mixed>> $summary the summary rows (with derived columns)
     * @param array<int, array<string, mixed>> $sample  the rows they derive from
     * @return array<string, string> derived column => header
     */
    private function derivedDomainLabels(array $summary, array $sample): array
    {
        $headers = $this->headersByColumn();
        $labels = [];
        foreach (array_diff($this->renderer->columns($summary), $this->renderer->columns($sample)) as $derived) {
            $source = str_ends_with($derived, '_domain') ? substr($derived, 0, -strlen('_domain')) : '';
            if ($source !== '' && $this->renderer->label($source, [], $this->tableProjections()) !== $this->renderer->label($source)) {
                $labels[$derived] = ($headers[$source] ?? $this->tableLabel($source)) . ' Domain';
            }
        }

        return $labels;
    }

    /**
     * The turn's homonym groups: by record IDENTITY when the rows carry ids
     * ({@see resolveRecords()}), else the value-only analysis.
     *
     * @return array<int, array{column: string, value: string, count: int, rows?: int, by: string, values: array<int, string>}>
     */
    private function homonymGroups(): array
    {
        return $this->identityHomonyms
            ?? $this->renderer->homonyms($this->tableRows, 5, $this->aggregateAliases);
    }

    /** Whether a homonym group covers every row of the result. */
    private function coversAllRows(array $group): bool
    {
        return ($group['rows'] ?? $group['count']) === count($this->tableRows);
    }

    /**
     * Whether the result IS one set of homonyms — DIFFERENT records (by identity: distinct
     * ids, never the value-only guess) sharing a name on every row. Then no figure is summed
     * across the rows ({@see totalsSpec()}).
     */
    private function homonymsAreTheWholeResult(): bool
    {
        foreach ($this->identityHomonyms ?? [] as $group) {
            if ($this->coversAllRows($group)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a homonym note owns the answer's FIRST sentence: a group covers every row, the
     * model sees them all (the summary) and — in table mode — so does the user (the table).
     */
    private function homonymLead(int $summaryRows, bool $tableMode): bool
    {
        $rows = count($this->tableRows);
        if ($rows > $summaryRows || ($tableMode && $this->shown() < $rows)) {
            return false;
        }

        foreach ($this->homonymGroups() as $group) {
            if ($this->coversAllRows($group)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Homonym notes. When the homonym group IS the whole (fully visible) result —
     * "what does unit 310 owe?" matched in three unions — the narrative must lead
     * with it. In a longer list the homonyms are incidental: the table already lists
     * them separately, so the model is only told never to merge them (live 2026-09-30:
     * demanding per-record figures for homonyms outside the 10-row summary made the
     * model invent them).
     *
     * G2 (eval p16-A 310 t0 / ambiguous t1): the lead used to ALSO demand each record's
     * figures in the first sentence, beside "state the totals" and "do NOT list the rows" —
     * three directives no answer satisfies (a numbered list on one run, merged totals on the
     * other, identical prompts). The system's table already gives every record's own figure
     * beside what tells it apart, so in table mode the lead only says that there are N
     * different records of that name; the prose carries the figures only when no table does.
     *
     * Groups come from record IDENTITY when the rows carry ids ({@see resolveRecords()}):
     * a parent repeated beside each child is one record, never "N different records"
     * (eval p10-A); the value-only analysis is the fallback.
     *
     * @param list<string> $stated the parts of the facts line code prints ({@see facts()})
     * @return array<int, string>
     */
    private function homonymLines(int $summaryRows, bool $tableMode, array $stated = []): array
    {
        // Detection runs on the real rows; only the values PRINTED in a note are
        // masked (never a token minted for every table row — review minor).
        $lead = $this->homonymLead($summaryRows, $tableMode);
        // p18-A unit-310 ("Unit 310 has three different records"): the statement is CODE's
        // when the facts line prints it — the note then informs and forbids the merge only.
        $printed = in_array('homonyms', $stated, true);
        $lines = [];
        $mask = fn (string $column, string $value): string => $this->maskedNoteValue($column, $value);
        foreach ($this->homonymGroups() as $group) {
            $wholeResult = $lead && $this->coversAllRows($group);
            $group['value'] = $mask($group['column'], $group['value']);
            $more = count($group['values']) > 10 ? '; …' : '';
            $group['values'] = array_map(fn (string $v) => $mask($group['by'], $v), array_slice($group['values'], 0, 10));
            if ($more !== '') {
                $group['values'][] = '…';
            }
            $name = $this->tableLabel($group['column']);
            $by = $this->tableLabel($group['by']);
            // p17-A 310 t0: "report each one separately" stood right before "do not list them
            // again in prose" and the answer was a numbered list of the rows. Beside a
            // code-rendered table the table IS the separate report; only prose-only answers
            // are asked for one.
            $line = "HOMONYMS: {$group['count']} DIFFERENT records share the {$name} '{$group['value']}' — they differ by "
                . "{$by} (" . implode('; ', $group['values']) . '): '
                . ($tableMode ? '' : "report each one separately with its {$by}; ")
                . 'never merge them into one sentence, one record or one figure.';
            if ($printed && $this->coversAllRows($group)) {
                $line .= " The system states this itself right above the table, and the table shows each one's own "
                    . "figures beside its {$by}: do not restate it, do not list them again in prose and never add them "
                    . 'up into one figure.';
            } elseif ($wholeResult) {
                $line .= " Your FIRST sentence must say that there are {$group['count']} different {$name} "
                    . "'{$group['value']}' records (one per {$by})"
                    . ($tableMode
                        ? "; the table shows each one's own figures beside its {$by}, so do not list them again "
                            . 'in prose and never add them up into one figure.'
                        : " and give each one's figures with its {$by}.");
            } else {
                $line .= " The table lists them separately; whenever you mention {$name} '{$group['value']}', "
                    . "say which {$by} you mean.";
            }
            $lines[] = $line;
        }
        if ($lines !== []) {
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * Each grouping as the lead states it — "9 different Union Name" ("at least 9 …" when
     * only part of the result was retrieved).
     *
     * @return list<string>
     */
    private function groupingSpans(): array
    {
        $complete = $this->totalKnown && $this->total <= count($this->tableRows);
        $headers = $this->headersByColumn();

        return array_map(
            fn (array $grouping): string => ($complete ? '' : 'at least ') . count($grouping['groups']) . ' different '
                . ($headers[$grouping['column']] ?? $this->tableLabel($grouping['column'])),
            $this->groupings
        );
    }

    /**
     * G1: how the rows group ({@see groupingsOf()}), stated over ALL retrieved rows — the
     * model reads a 10-row summary and cannot see it — and that they are never one group.
     * The lead requirement itself is the ANSWER FORMAT's single first-sentence instruction
     * in table mode ({@see answerFormatLines()}); without a table it rides this line. Group
     * values are masked like every value printed in a note. When only part of the result
     * was retrieved the groups are a lower bound and carry no counts (a partial count would
     * read as a total).
     *
     * When the facts line prints the grouping ($stated has 'groups', eval p18-A board: "12
     * different Union Names" read back from this note), the coarsest grouping is worded with
     * the host's noun for its entity — the words the user reads right above the table.
     *
     * @param list<string> $stated the parts of the facts line code prints ({@see facts()})
     * @return array<int, string>
     */
    private function groupingLines(bool $tableMode, array $stated = []): array
    {
        if ($this->groupings === []) {
            return [];
        }

        $n = count($this->tableRows);
        $complete = $this->totalKnown && $this->total <= $n;
        $headers = $this->headersByColumn();
        $spans = $this->groupingSpans();
        $lines = [];
        foreach ($this->groupings as $i => $grouping) {
            $column = $grouping['column'];
            $header = $headers[$column] ?? $this->tableLabel($column);
            $distinct = count($grouping['groups']);
            $listed = [];
            foreach (array_slice($grouping['groups'], 0, self::GROUPS_LISTED_MAX) as [$value, $count]) {
                $listed[] = $this->maskedNoteValue($column, $value) . ($complete ? " ({$count})" : '');
            }
            if ($distinct > count($listed)) {
                $listed[] = '… and ' . ($distinct - count($listed)) . ' more';
            }
            if ($grouping['blank'] > 0 && $complete) {
                $listed[] = "{$grouping['blank']} with no {$header}";
            }

            $groupKind = $i === 0 && in_array('groups', $stated, true)
                ? $this->noun($this->columnEntity($column), true, null)
                : null;
            $span = $groupKind !== null ? "{$distinct} {$groupKind} (column {$header})" : $spans[$i];

            $lines[] = 'GROUPS: The ' . ($complete ? "{$n} rows" : "{$n} retrieved rows (of {$this->totalPhrase()})")
                . " span {$span}: " . implode('; ', $listed) . '. '
                . "They are NOT one group — never present them as one single group (the rows of one {$header})"
                . ($tableMode
                    ? '; the table shows which row belongs to which.'
                    : ". Your FIRST sentence must say that the rows cover {$spans[$i]}.");
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * E3 / G3: the columns empty on every retrieved row, named with their table headers.
     *
     *   - a STORED field (projected from a node): the data does not record it — said
     *     plainly, never "could not find" (E3). Since the table no longer shows the column
     *     (G3) this line is the only place it is named, and it closes the WHICH-ONE reading
     *     (eval p16-A board "…and who is the president?" over an all-empty role column): no
     *     row can be singled out by a column that holds nothing — by the column's own
     *     header, never by what the question called it;
     *   - an AGGREGATE's result (`min(i.due_at) AS oldest_due` with nothing due — the
     *     cypher's grammar): there was nothing to compute it from. That is an answer about
     *     the data, not a field the data fails to record.
     *
     * When the facts line prints the stored ones ($stated has 'not_recorded', eval p18-A
     * board: "The information about the board of directors and the president is not specified
     * in the available data" above 25 board members), the note only informs: that line IS the
     * answer to any part of the question about them.
     *
     * @param list<string> $stated the parts of the facts line code prints ({@see facts()})
     * @return array<int, string>
     */
    private function emptyColumnLines(bool $tableMode, array $stated = []): array
    {
        if ($this->emptyColumns === []) {
            return [];
        }

        $headers = $this->headersByColumn();
        $n = count($this->tableRows);
        $scope = match (true) {
            $this->record => 'this record',
            $this->total > $n || !$this->totalKnown => "all {$n} retrieved rows",
            default => "all {$n} rows",
        };
        $leftOut = static fn (bool $one): string => ($one ? 'it' : 'them') . ' out';
        $hidden = $tableMode && $this->omittedColumns() !== [];

        $stored = [];
        $computed = [];
        foreach ($this->emptyColumns as $column) {
            $name = $headers[$column] ?? $column;
            if (in_array($column, $this->aggregateAliases, true)) {
                $computed[] = $name;
            } else {
                $stored[] = $name;
            }
        }

        $lines = [];
        if ($stored !== [] && in_array('not_recorded', $stated, true)) {
            $one = count($stored) === 1;
            $lines[] = 'NOT RECORDED: ' . implode(', ', $stored) . ' ' . ($one ? 'is' : 'are')
                . " empty on {$scope} — the data does not record " . ($one ? 'it' : 'them') . ', so '
                . ($this->record ? 'the listed fields leave ' : 'the table leaves ') . $leftOut($one)
                . ' and the system says so itself right above ' . ($this->record ? 'the record' : 'the table')
                . ' — that line IS the answer to any part of the question about ' . ($one ? 'it' : 'them')
                . ($this->record ? '' : ' (including which record has a given ' . implode(' / ', $stored) . ')')
                . ': do not restate it in your own words, never guess it'
                . ($this->record ? '.' : ' and never name a record for it.');
        } elseif ($stored !== []) {
            $one = count($stored) === 1;
            $lines[] = 'NOT RECORDED: ' . implode(', ', $stored) . ' ' . ($one ? 'is' : 'are')
                . " empty on {$scope} — the data does not record " . ($one ? 'it' : 'them')
                . ($hidden ? ', so ' . ($this->record ? 'the listed fields leave ' : 'the table leaves ') . $leftOut($one) : '')
                . '. If the question asks about ' . ($one ? 'it' : 'them')
                . ($this->record ? '' : ', or asks which record has a given ' . implode(' / ', $stored))
                . ', say plainly in your answer that it is not recorded; never guess it'
                . ($this->record ? '.' : ' and never name a record for it.');
        }
        if ($computed !== []) {
            $one = count($computed) === 1;
            $lines[] = 'NO VALUE: ' . implode(', ', $computed) . ' ' . ($one ? 'is' : 'are')
                . " empty on {$scope} — " . ($one ? 'it is' : 'they are') . ' computed by the query and there was '
                . 'nothing to compute ' . ($one ? 'it' : 'them') . ' from (not a missing field)'
                . ($hidden ? ', so ' . ($this->record ? 'the listed fields leave ' : 'the table leaves ') . $leftOut($one) : '')
                . '. Mention ' . ($one ? 'it' : 'them') . ' only if the question asks, and then say there is none; never guess it.';
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * @param list<string> $stated the parts of the facts line code prints above the block
     *        ({@see facts()}): the model is asked for none of them and told not to restate them
     * @param string $printedLine that line's EXACT text (PII-masked), quoted to the model so its
     *        lead can neither restate nor contradict it (eval p19: "…the president is available
     *        in the records" right above "Not recorded: Board role."); '' = none printed
     * @param string $homonymSubject the namesakes as a plural subject, in the answer's language and
     *        PII-masked ("the 3 units named 310" — {@see homonymSubject()}); '' = no code-written
     *        homonym statement
     * @return array<int, string>
     */
    private function answerFormatLines(
        bool $tableMode,
        bool $homonymLead = false,
        array $stated = [],
        string $printedLine = '',
        string $homonymSubject = ''
    ): array {
        $this->promptNotes = null;
        if ($this->mode === self::MODE_SINGLE) {
            return [
                'ANSWER FORMAT: state the values from FACTS in prose, copied verbatim — never recompute them. No table.',
            ];
        }

        if ($this->mode !== self::MODE_TABLE) {
            return [];
        }

        $numbers = 'Every total, count or amount you state must be copied verbatim from FACTS / QUERY TOTAL / '
            . 'COMPUTED TOTALS / the DATA SUMMARY rows / the row count — never add, subtract or re-derive numbers '
            . 'yourself, and never state a figure for a row that is not in the DATA SUMMARY.';

        if (!$tableMode) {
            return ['ANSWER RULE: ' . $numbers];
        }

        // D5: the model's job ENDS at the placeholder. The former "then at most one short
        // closing next step" contradicted the guideline of the same prompt ("Do NOT end with
        // an offer or a question") and produced a generic closer on every run; next steps
        // are the buttons code attaches. finalize() enforces it: text after the placeholder
        // is dropped.
        // P1 (eval p17): "a direct answer first AND ANYTHING ELSE YOU HAVE TO SAY" moved that
        // padding in front of the placeholder (a 10-row bullet list, a closer). The model
        // writes a LEAD — bounded, like the record's — plus only what a note of this very
        // block asks of it; finalize() drops a list / table that echoes the rows.
        $notes = $this->requiringNotes($homonymLead, $stated);
        $this->promptNotes = $notes;
        // p19: what code prints is QUOTED, and beside it the lead may be empty — a lead with
        // nothing left to say filled ("several members with various roles") or contradicted.
        $quoted = $printedLine !== '' ? ' The system prints: "' . $printedLine . '"' : '';
        // (Only when nothing else is OWED by the lead: a first sentence a note demands — the
        // fallback statement, the groups, a homonym statement code could not word — or, below,
        // a total / count the model still states, make it mandatory. NOT RECORDED / NO VALUE
        // only speak "if the question asks": they leave it optional.)
        $owed = array_intersect($notes, $homonymLead ? ['FALLBACK ROWS', 'GROUPS', 'HOMONYMS'] : ['FALLBACK ROWS', 'GROUPS']);
        $emptyLead = $printedLine === '' || $owed !== [] ? [] : [
            '- That sentence is OPTIONAL — the lead may be EMPTY: if you have nothing to ADD that the question asks for beyond that line and the '
                . ($this->record ? 'record' : 'table') . ' (a question that asks for the list is answered by the table itself), '
                . 'write ONLY ' . self::PLACEHOLDER . ' — no sentence at all. Never put an introduction, a restatement of the '
                . 'question or a vague summary in its place.',
        ];
        $required = $notes === []
            ? ''
            : ', plus only the sentence the ' . implode(' / ', $notes) . ' note' . (count($notes) > 1 ? 's' : '')
                . ' above ask' . (count($notes) > 1 ? '' : 's') . ' for';
        $ends = ' — then the placeholder ' . self::PLACEHOLDER . ' on its own line as the LAST line of your answer: ';

        if ($this->record) {
            // D2: ONE record — code lists every field, the model writes the lead only.
            // (G3: a field the record does not hold is not listed — see NOT RECORDED.)
            // P2 (eval p17-A team-info): "one or two sentences … any value copied verbatim
            // from the row" let the lead restate all five fields the block lists right below.
            $fields = implode(', ', array_diff_key($this->headersByColumn(), array_flip($this->omittedColumns())));

            return [
                'ANSWER FORMAT — THE SYSTEM LISTS THE RECORD:',
                '- Write ONLY the lead — ONE sentence that answers the question or introduces the record' . $required . $ends
                    . 'the system prints the record there and discards any text after it. The sentence may state the one value '
                    . 'the question literally asks for, copied verbatim from the row above (never recomputed); leave every '
                    . 'other value to the system.',
                '- The system replaces ' . self::PLACEHOLDER . " with every field of this record and its exact value ({$fields}): "
                    . 'do NOT list the fields yourself and do NOT restate their values in your sentence.',
                ...(in_array('not_recorded', $stated, true)
                    ? ['- The system itself prints, on a line right above the record, which fields are not recorded.' . $quoted
                        . ' — do NOT restate it and never contradict it: what that line states is settled, also for any part of '
                        . 'the question it answers.']
                    : []),
                ...$emptyLead,
            ];
        }

        // p18 (A board / unit-310, B resilience t1): the count, the grouping, the homonym
        // statement and "not recorded" came back in THIS instruction's words. What the facts
        // line prints is no longer asked of the model: its lead is one sentence that answers.
        $codeCount = in_array('count', $stated, true);
        $codeGroups = in_array('groups', $stated, true);
        $kind = $this->recordKind(null);

        $shown = $this->shown();
        $lines = [
            'ANSWER FORMAT — THE SYSTEM RENDERS THE TABLE:',
            '- Write ONLY the lead — ' . ($stated !== [] ? 'ONE sentence that answers the question' : 'one or two sentences that answer the question')
                . $required . $ends . 'the system prints the rows there and discards any text after it.',
        ];
        if ($stated !== []) {
            $prints = [];
            if (in_array('homonyms', $stated, true)) {
                $prints[] = 'that these rows are different records sharing one name';
            }
            if ($codeCount) {
                $prints[] = 'how many ' . ($kind ?? 'of them') . ' there are' . ($codeGroups ? ' and how they are grouped' : '');
            }
            if (in_array('not_recorded', $stated, true)) {
                $prints[] = 'which fields are not recorded';
            }
            if (in_array('partly_recorded', $stated, true)) {
                $prints[] = 'for how many rows a field the question asks for is recorded';
            }
            $lines[] = '- The system itself prints, on a line right above the table, ' . implode(', and ', $prints) . '.' . $quoted
                . ' — do NOT restate any of it (no count, no grouping, no "not recorded" sentence of your own) and never '
                . 'contradict it: what that line states is settled, also for any part of the question it answers. Your '
                . 'sentence only adds what the question asks for beyond it.';
            // p20-A unit-310 t0 / t1 ("Unit 310 currently owes $0.00 across all unions." above
            // "3 different units named 310 — one per union."): the lead's SUBJECT is handed to
            // the model, plural, in the answer's language — a positive form to write, where
            // "never merge them" alone left the singular in place.
            if ($homonymSubject !== '' && in_array('homonyms', $stated, true)) {
                $lines[] = '- The rows are ' . count($this->tableRows) . ' DIFFERENT records, so the SUBJECT of your sentence is PLURAL — "'
                    . $homonymSubject . '": say what is true of all of them or of each of them, never of ONE record under the shared name.';
            }
        }

        // E1 (eval p11-A owner-arrears): the computed total sat in the prompt and was never
        // stated, nor the count — a multi-row result WITH grand totals leads with both.
        // G2: never beside a homonym lead — the HOMONYMS note owns the first sentence there
        // ("there are N different records of that name"), and one total over records that
        // only share a name is the merge that note forbids.
        // G1: the rows' grouping joins that SAME sentence — one first-sentence instruction,
        // whatever it has to carry (count + total, the groups, or both).
        // P3 (eval p17-A roster): a TRUNCATED list whose true total is known leads with that
        // total too — "if you give a count, the full count is 523" made it optional, and the
        // answer gave none. Never for a fan-out counted in rows (the DATA STATS line then
        // names the distinct record count), never for an unknown total.
        $grand = $homonymLead ? [] : $this->labeledGrandTotals();
        $spans = $homonymLead || $codeGroups ? [] : $this->groupingSpans();
        $fullCount = !$homonymLead && $this->truncated() && $this->totalKnown && !$this->fanOut;
        // (The count is asked of the model only when code does not print it.)
        $counted = ($grand !== [] || $fullCount) && !$codeCount;
        if (($grand !== [] || $spans !== [] || $counted) && count($this->tableRows) > 1) {
            $parts = [];
            if ($counted) {
                $parts[] = 'the number of ' . ($kind ?? 'records') . ' (' . $this->totalPhrase() . ($fullCount ? ' in all' : '') . ')';
            }
            if ($spans !== []) {
                $parts[] = 'that the rows cover ' . implode(' and ', $spans) . ' (see GROUPS above)';
            }
            if ($grand !== []) {
                $stated = [];
                foreach ($grand as $label => $figure) {
                    $stated[] = "{$label} = {$figure}";
                }
                $parts[] = 'the total' . (count($stated) > 1 ? 's' : '') . ' (' . implode('; ', $stated) . '), copied verbatim';
            }
            $last = array_pop($parts);
            $lines[] = '- Your FIRST sentence states ' . ($parts === [] ? '' : implode(', ', $parts) . ' and ') . $last
                . ', then answers the question.'
                // No host-named kind: the question's own noun, never the system's.
                . ($counted && $kind === null ? ' Name what is counted in the question\'s own words — never as "rows".' : '');
        } else {
            array_push($lines, ...$emptyLead);
        }

        // The shown count is the system's to state ("showing X of Y") — only a truncated table
        // has a shown count that differs from the result's own count.
        $lines[] = '- The system replaces ' . self::PLACEHOLDER . " with a table of the rows ({$shown} of "
            . $this->totalPhrase() . ') and adds any "showing X of Y" line itself. Do NOT list the rows '
            . '(no bullet or numbered list, no row-by-row sentences) and do NOT write your own table'
            . match (true) {
                !$this->truncated() => '.',
                $fullCount && $codeCount => '; the table is only part of the list, so never call it complete, and do NOT state how '
                    . 'many rows are shown or how many there are in all — the system prints both.',
                $fullCount => '; the table is only part of the list, so never call it complete, and do NOT state how many rows '
                    . 'are shown — the count you state is the full count, ' . $this->totalPhrase() . '.',
                default => '; the table is only part of the list, so never call it complete, and do NOT state how many rows '
                    . 'are shown — if you give a count, the full count is ' . $this->totalPhrase() . '.',
            };
        $lines[] = '- ' . $numbers;

        return $lines;
    }

    /**
     * The notes of THIS block that may ask the lead for a sentence of their own — named in
     * the lead instruction so "one or two sentences" never reads as forbidding them, and so
     * nothing else is invited. By the notes actually emitted, in the order they appear.
     *
     * A note whose statement the facts line prints ($stated) asks nothing of the lead.
     *
     * @param list<string> $stated the parts of the facts line code prints ({@see facts()})
     * @return list<string>
     */
    private function requiringNotes(bool $homonymLead, array $stated = []): array
    {
        $notes = [];
        if ($this->fallback !== null) {
            $notes[] = 'FALLBACK ROWS';
        }
        if (!$homonymLead && $this->groupings !== [] && !in_array('groups', $stated, true)) {
            $notes[] = 'GROUPS';
        }
        foreach ($this->emptyColumns as $column) {
            $aggregate = in_array($column, $this->aggregateAliases, true);
            if ($aggregate || !in_array('not_recorded', $stated, true)) {
                $notes[] = $aggregate ? 'NO VALUE' : 'NOT RECORDED';
            }
        }
        if (!$this->record && $this->homonymGroups() !== [] && !in_array('homonyms', $stated, true)) {
            $notes[] = 'HOMONYMS';
        }

        $order = ['FALLBACK ROWS', 'GROUPS', 'NOT RECORDED', 'NO VALUE', 'HOMONYMS'];

        return array_values(array_intersect($order, $notes));
    }

    /**
     * The grand totals with their column labels and stated figures (E1) — exactly what
     * totalsLines() told the model: query-total columns, else complete computed sums.
     *
     * @return array<string, string> label => figure
     */
    private function labeledGrandTotals(): array
    {
        $spec = $this->totalsSpec();
        $out = [];
        if ($spec['query'] !== []) {
            foreach ($spec['query'] as $column => $value) {
                $out[$this->tableLabel($column)] = $this->figure($value, $this->isMoney($column));
            }

            return $out;
        }
        if ($spec['complete']) {
            foreach ($spec['sums'] as $column => $sum) {
                $out[$this->tableLabel($column)] = $this->figure($sum, true);
            }
        }

        return $out;
    }

    /** The table's total as stated to the model ("at least N" when the row cap hid it). */
    public function totalPhrase(): string
    {
        return $this->totalKnown ? (string) $this->total : 'at least ' . $this->total;
    }

    private function displayCap(): int
    {
        $cap = self::config('ai.response_generation.table_max_rows', TableRenderer::DEFAULT_MAX_ROWS);

        return is_numeric($cap) ? max(0, (int) $cap) : TableRenderer::DEFAULT_MAX_ROWS;
    }

    private function number(float $value): string
    {
        return number_format($value, 2, '.', ',');
    }

    private function tidy(string $text): string
    {
        $text = (string) preg_replace('/[ \t]+\n/', "\n", $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /**
     * @param array<string, scalar> $replace
     * @param string|null $fallbackKey the line used when $key has no translation (a host lang override predating it)
     */
    private function trans(string $key, array $replace, ?string $locale, ?string $fallbackKey = null): string
    {
        foreach (array_filter([$key, $fallbackKey]) as $candidate) {
            try {
                $line = trans($candidate, $replace, $locale);
                if (is_string($line) && $line !== $candidate) {
                    return $line;
                }
            } catch (\Throwable $e) {
                // no translator — fall through
            }
        }

        return 'Showing ' . ($replace['shown'] ?? '') . (isset($replace['total']) ? ' of ' . $replace['total'] : '') . ' rows.';
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
