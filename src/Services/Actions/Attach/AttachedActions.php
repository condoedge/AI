<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\Attach;

use Condoedge\Ai\Services\Actions\ActionInvocation;
use Condoedge\Ai\Services\Response\NarrativeRowEcho;

/**
 * AttachedActions — the chips CODE attaches to one answer (actions plan B1–B4).
 *
 * The owner rule: "code attaches links/actions; the AI only narrates". Chips are
 * computed from structured signals (rows, labels, QU, counts, permissions), persisted
 * as message `metadata.invocations` and re-dispatched at every render with a FRESH
 * authorize() (MessageBubbleRenderer::renderPersistedInvocations).
 *
 * Inline references: the narrative may write `{{record:N}}` (N = the row number in the
 * data summary); {@see substitute()} replaces it with a markdown link to that row's
 * primary action, or strips it when N names no openable record.
 *
 * Follow-up question chips (ROLE_FOLLOW_UP — {@see \Condoedge\Ai\Services\Actions\BuiltIn\AskFollowUpAction})
 * are chips like the others for persistence and rendering, with one difference: they are
 * suggestions for the user's NEXT message, not pages or files of this answer, so they are
 * never named to the narrator ({@see labels()}). Every branch writes its chips to the
 * response metadata through {@see applyTo()}.
 */
final class AttachedActions
{
    public const ROLE_RECORD = 'record';
    public const ROLE_MORE = 'more';
    public const ROLE_IDENTITY = 'identity';
    public const ROLE_NEXT = 'next_step';
    public const ROLE_SECTION = 'section';
    public const ROLE_FOLLOW_UP = 'follow_up';

    /** THE narrator instruction (B5) — the only thing any prompt says about links. */
    public const NARRATOR_LINE = 'Relevant links and actions are attached automatically below your answer; do not write URLs or action links.';

    /**
     * When code attached nothing: saying "attached below" anyway made the model promise
     * buttons that never render ("Next steps are attached below" on a legal answer).
     */
    public const NARRATOR_NONE_LINE = 'Do not write URLs or action links, and never mention links, buttons or attachments (neither that some are attached nor that none are).';

    public const RECORD_LINE = 'To refer to a specific record inline, write {{record:N}} where N is its row number in the data summary.';

    /**
     * The narrator's instruction for this answer: the one line, the attached chip labels
     * (so the prose may point at them by name), and the {{record:N}} rule when rows can
     * be referenced.
     */
    public function narratorInstruction(): string
    {
        if ($this->labels() === [] && !$this->hasRecordRefs()) {
            return self::NARRATOR_NONE_LINE;
        }

        $text = self::NARRATOR_LINE;
        if ($this->labels() !== []) {
            // Framed as what the user already sees: live, the bare list was echoed as prose.
            // And the labels ride INSIDE the sentence, quoted, the prohibition last — never
            // "<a label of the prompt>: <the buttons>.", which reads like a sentence of the
            // answer: eval p21-B rights t2 shipped "Attached below this answer: Receivables
            // report." copied from the former wording ("Attached below this answer (the user
            // already sees these buttons; never list or repeat them): …"). The words
            // "attached below this answer" stay: a host persona may refer to that list by name.
            $text .= ' The buttons attached below this answer are '
                . implode('; ', array_map(static fn (string $label): string => '"' . str_replace('"', "'", $label) . '"', $this->labels()))
                . ' — the user already sees them: never list or repeat them.';
        }
        if ($this->hasRecordRefs()) {
            $text .= ' ' . self::RECORD_LINE;
        }

        return $text;
    }

    /**
     * @param list<array{role: string, invocation: ActionInvocation}> $chips
     * @param array<int, ActionInvocation> $records 1-based row number => the row's primary action
     */
    public function __construct(
        private readonly array $chips = [],
        private readonly array $records = [],
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->chips === [];
    }

    /** Whether the narrative may reference rows with {{record:N}}. */
    public function hasRecordRefs(): bool
    {
        return $this->records !== [];
    }

    /**
     * Persisted form (message metadata `invocations`), in display order.
     *
     * @return list<array<string, mixed>>
     */
    public function invocations(): array
    {
        return array_map(static fn (array $chip) => $chip['invocation']->toArray(), $this->chips);
    }

    /**
     * Chip labels, in display order (what the narrative may refer to by name).
     *
     * Follow-up question chips are left out: the composing model must not point at them
     * (or repeat them) — they are the user's possible next messages, not this answer's
     * buttons.
     *
     * @return list<string>
     */
    public function labels(): array
    {
        $named = array_filter($this->chips, static fn (array $chip): bool => $chip['role'] !== self::ROLE_FOLLOW_UP);

        return array_values(array_filter(array_map(
            static fn (array $chip) => $chip['invocation']->text,
            $named
        ), static fn (string $text) => $text !== ''));
    }

    /** The words in front of the colon of a label echo: a label of the prompt, not a sentence (the one shipped held four). */
    private const ECHO_LEAD_WORDS = 6;

    /** Between the labels of an echo only a joining word may stand ("and", "et", "ou"): this many letters at most. */
    private const ECHO_JOINER_LETTERS = 3;

    /**
     * The model's narrative WITHOUT its label echoes: a sentence (or a whole line) that is
     * nothing but this turn's button labels introduced by a colon — "Attached below this
     * answer: Receivables report." (eval p21-B rights t2: the narrator instruction's own
     * label, copied into the answer). The buttons are right below the answer; the
     * instruction says "never list or repeat them".
     *
     * By STRUCTURE only — the chips of this turn and the shape of the sentence, never its
     * wording (so it holds in any language):
     *  - a colon stands before the first chip label of the sentence, and at most
     *    ECHO_LEAD_WORDS words stand before that colon;
     *  - after the colon there are only chip labels (whole words, any case), punctuation
     *    and at most one joining word of ECHO_JOINER_LETTERS letters per label;
     *  - at least one of those labels is a BUTTON — a page, a report, an action. A line
     *    that holds record chips only is a record's own value ("Meeting name: <the
     *    record>") and stays; follow-up question chips are never labels here.
     * Table rows are not read, and the echo is never dropped when it is all there is.
     *
     * Measured on the stored answers of eval p16–p21 (both parts; 116 answers with chips,
     * 775 sentences): outside the digest 68 sentences hold a chip label verbatim. 63 are
     * prose with the label inside the sentence — "You can check the receivables report for
     * more details.", "1. **Open Team Settings**: Click on …" (the label BEFORE the colon) —
     * and none of them has this shape; 4 are a record's value line (record chip only); 1 is
     * the echo. Any bound of four words or more removes it; prose is protected by the
     * shape, not by the bound.
     *
     * @param string $narrative the MODEL's text, before any code-rendered block is added
     * @param bool $mayLeaveNothing a data lead may be dropped whole: the code-written lines
     *        and the table are the answer
     * @return array{0: string, 1: list<string>} [the narrative, the echoes dropped]
     */
    public function withoutLabelEcho(string $narrative, bool $mayLeaveNothing = false): array
    {
        $labels = [];
        $anyButton = false;
        foreach ($this->chips as $chip) {
            $label = self::plainLabel($chip['invocation']->text);
            if ($chip['role'] === self::ROLE_FOLLOW_UP || $label === '') {
                continue;
            }
            $button = $chip['role'] !== self::ROLE_RECORD;
            $labels[$label] = ($labels[$label] ?? false) || $button;
            $anyButton = $anyButton || $button;
        }
        if (!$anyButton || trim($narrative) === '' || preg_match('/[:\x{FF1A}]/u', $narrative) !== 1) {
            return [$narrative, []];
        }
        // The longest label first: "Team settings" before "Team".
        uksort($labels, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        /** @var list<string|null> $lines */
        $lines = preg_split('/\R/u', $narrative) ?: [];
        // The echo over several lines first: a colon line and the label-only list under it.
        [$lines, $dropped] = self::withoutLabelLists($lines, $labels);
        foreach ($lines as $index => $line) {
            if ($line === null || trim($line) === '' || str_starts_with(ltrim($line), '|')) {
                continue;
            }
            if (self::isLabelEcho($line, $labels)) {
                $dropped[] = trim($line);
                $lines[$index] = null;
                continue;
            }
            foreach (NarrativeRowEcho::sentences($line) as $sentence) {
                if (self::isLabelEcho($sentence, $labels) && ($at = strpos($line, $sentence)) !== false) {
                    $dropped[] = $sentence;
                    $line = rtrim(substr($line, 0, $at)) . ' ' . ltrim(substr($line, $at + strlen($sentence)));
                    $lines[$index] = rtrim($line);
                }
            }
        }
        if ($dropped === []) {
            return [$narrative, []];
        }

        $kept = implode("\n", array_filter($lines, static fn (?string $line): bool => $line !== null));
        $kept = trim((string) preg_replace('/\n[ \t]*(?:\n[ \t]*)+\n/u', "\n\n", $kept));

        return $kept === '' && !$mayLeaveNothing ? [$narrative, []] : [$kept, $dropped];
    }

    /** A list marker in front of an item: a bullet or a number. */
    private const LIST_MARKER = '/^\s*(?:[-+•*]|\d{1,3}[.)])\s+(?=\S)/u';

    /**
     * THE ECHO OVER SEVERAL LINES (eval p22-1001-B app-navigation t0):
     *
     *   Here are the next steps you can take:
     *
     *   - Team settings
     *
     * Read one line at a time neither line is an echo: the first holds the colon and no
     * label, the second the label and no colon. Together they are the button list again.
     *
     * By STRUCTURE only, like the one-line echo:
     *  - a sentence that ENDS a line with a colon — the last sentence of that line; what
     *    stands before it on the line stays;
     *  - under it (blank lines aside) a LIST: consecutive list items, or, without list
     *    markers, the lines of the next paragraph;
     *  - EVERY item is only chip labels of this turn (whole words, any case), punctuation
     *    and at most one joining word of ECHO_JOINER_LETTERS letters per label, and at
     *    least one item names a BUTTON (a list of record chips is a list of the rows).
     * The sentence and its list go together. Everything else stays whole: a list with one
     * item that says something ("Go to Team settings and click Add member", the steps of a
     * how-to), an item continued on the next line, a table. So does a heading that states
     * something itself — it holds a digit (a figure, a citation) or more than one sentence.
     *
     * Measured on the stored answers of eval p16–p22 (both parts; 216 answers, 156 with
     * chips): 41 colon lines are followed by a list — the steps of a how-to, the rights and
     * duties of a law answer, a record's fields. ONE of those lists holds an item that is a
     * chip label, p22-B's; it is the only one dropped.
     *
     * @param list<string> $lines the lines of the narrative
     * @param array<string, bool> $labels plain label => it is a button (not a record)
     * @return array{0: list<string|null>, 1: list<string>} [the lines (null = dropped), what was dropped]
     */
    private static function withoutLabelLists(array $lines, array $labels): array
    {
        $dropped = [];
        $count = count($lines);

        for ($at = 0; $at < $count; $at++) {
            $line = $lines[$at];
            if ($line === null || trim($line) === '' || str_starts_with(ltrim($line), '|')) {
                continue;
            }
            $heading = self::listHeading($line);
            if ($heading === null) {
                continue;
            }

            $items = self::listUnder($lines, $at);
            $button = false;
            foreach ($items as $item) {
                $names = self::namesOnlyLabels((string) $lines[$item], $labels);
                if ($names === null) {
                    continue 2;
                }
                $button = $button || $names;
            }
            if (!$button) {
                continue;
            }

            $before = rtrim(substr($line, 0, (int) strrpos($line, $heading)));
            $dropped[] = $heading;
            $lines[$at] = $before === '' ? null : $before;
            foreach ($items as $item) {
                $dropped[] = trim((string) $lines[$item]);
                $lines[$item] = null;
            }
            $at = end($items);
        }

        return [$lines, $dropped];
    }

    /**
     * The sentence that introduces a list on this line: its last sentence, when it ends
     * with a colon and states nothing itself (no digit, one sentence). Null otherwise.
     */
    private static function listHeading(string $line): ?string
    {
        $sentences = NarrativeRowEcho::sentences($line);
        $last = $sentences === [] ? '' : $sentences[count($sentences) - 1];
        $plain = self::plainLabel((string) preg_replace(self::LIST_MARKER, '', $last));

        if (preg_match('/[:\x{FF1A}]$/u', $plain) !== 1
            || preg_match('/\p{N}/u', $plain) === 1
            || preg_match('/[.!?\x{3002}]\s+\S/u', $plain) === 1
            || strrpos($line, $last) === false) {
            return null;
        }

        return $last;
    }

    /**
     * The lines of the list under line $at: consecutive list items (blank lines between
     * them allowed), or the lines of the next paragraph when it has no list markers. Empty
     * when there is none, or when the list holds a line that is not an item (an item
     * continued on the next line, a table row).
     *
     * @param list<string|null> $lines
     * @return list<int>
     */
    private static function listUnder(array $lines, int $at): array
    {
        $count = count($lines);
        $blank = static fn (int $i): bool => $lines[$i] === null || trim($lines[$i]) === '';
        $next = static function (int $i) use ($count, $blank): int {
            while ($i < $count && $blank($i)) {
                $i++;
            }

            return $i;
        };

        $i = $next($at + 1);
        if ($i >= $count) {
            return [];
        }

        $items = [];
        $marked = preg_match(self::LIST_MARKER, (string) $lines[$i]) === 1;
        while ($i < $count) {
            if ($blank($i)) {
                // A marked list may be loose; a paragraph ends at its first blank line.
                $after = $next($i);
                if (!$marked || $after >= $count || preg_match(self::LIST_MARKER, (string) $lines[$after]) !== 1) {
                    break;
                }
                $i = $after;
                continue;
            }
            $line = (string) $lines[$i];
            if (str_starts_with(ltrim($line), '|') || (preg_match(self::LIST_MARKER, $line) === 1) !== $marked) {
                return [];
            }
            $items[] = $i++;
        }

        return $items;
    }

    /**
     * Whether a list item is only chip labels: true when it names a button, false when it
     * names record chips only, null when it says anything else (or names no chip at all).
     *
     * @param array<string, bool> $labels plain label => it is a button (not a record)
     */
    private static function namesOnlyLabels(string $item, array $labels): ?bool
    {
        $text = self::plainLabel((string) preg_replace(self::LIST_MARKER, '', $item));

        $named = 0;
        $button = false;
        foreach ($labels as $label => $isButton) {
            $text = (string) preg_replace(self::labelPattern($label), ' ', $text, -1, $found);
            $named += $found;
            $button = $button || ($found > 0 && $isButton);
        }
        if ($named === 0) {
            return null;
        }

        $joiners = self::wordsOf($text);
        foreach ($joiners as $word) {
            if (preg_match('/^\p{L}{1,' . self::ECHO_JOINER_LETTERS . '}$/u', $word) !== 1) {
                return null;
            }
        }

        return count($joiners) <= $named ? $button : null;
    }

    /**
     * Whether one sentence (or line) is a label echo — see {@see withoutLabelEcho()}.
     *
     * @param array<string, bool> $labels plain label => it is a button (not a record)
     */
    private static function isLabelEcho(string $text, array $labels): bool
    {
        $text = self::plainLabel((string) preg_replace('/^\s{0,3}(?:[-+•]|\d{1,3}[.)])\s+/u', '', $text));

        // The first chip label of the sentence, and the colon in front of it.
        $first = null;
        foreach (array_keys($labels) as $label) {
            if (preg_match(self::labelPattern($label), $text, $m, PREG_OFFSET_CAPTURE) === 1 && ($first === null || $m[0][1] < $first)) {
                $first = $m[0][1];
            }
        }
        if ($first === null || preg_match('/^(.*)[:\x{FF1A}]/us', substr($text, 0, $first), $lead) !== 1) {
            return false;
        }
        if (count(self::wordsOf($lead[1])) > self::ECHO_LEAD_WORDS) {
            return false;
        }

        $tail = substr($text, strlen($lead[0]));
        $named = 0;
        $button = false;
        foreach ($labels as $label => $isButton) {
            $tail = (string) preg_replace(self::labelPattern($label), ' ', $tail, -1, $count);
            $named += $count;
            $button = $button || ($count > 0 && $isButton);
        }
        if (!$button) {
            return false;
        }

        $joiners = self::wordsOf($tail);
        foreach ($joiners as $word) {
            if (preg_match('/^\p{L}{1,' . self::ECHO_JOINER_LETTERS . '}$/u', $word) !== 1) {
                return false;
            }
        }

        return count($joiners) <= $named;
    }

    /** A label as a whole word of a text, in any case. */
    private static function labelPattern(string $label): string
    {
        return '/(?<![\p{L}\p{N}])' . preg_quote($label, '/') . '(?![\p{L}\p{N}])/iu';
    }

    /** A text without its markdown emphasis marks, single-spaced. */
    private static function plainLabel(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace(['*', '_', '`'], '', $text)));
    }

    /** @return list<string> the words (runs of letters and digits) of a text */
    private static function wordsOf(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * A copy with more chips after the existing ones (a chip already attached — same
     * invocation fingerprint — is not added twice). The inline record references stay.
     *
     * @param list<array{role: string, invocation: ActionInvocation}> $chips
     */
    public function with(array $chips): self
    {
        $seen = [];
        foreach ($this->chips as $chip) {
            $seen[$chip['invocation']->fingerprint()] = true;
        }

        $all = $this->chips;
        foreach ($chips as $chip) {
            $fingerprint = $chip['invocation']->fingerprint();
            if (!isset($seen[$fingerprint])) {
                $seen[$fingerprint] = true;
                $all[] = $chip;
            }
        }

        return new self($all, $this->records);
    }

    /**
     * Write the chips into a response's metadata: `invocations` (what AskQuestionUseCase
     * stores on the assistant message and MessageBubbleRenderer re-dispatches with a FRESH
     * authorize at every render) and `attached_actions` (the observation summary). THE one
     * place every branch — data, knowledge, digest, clarification — assembles them.
     * Nothing attached: the metadata is returned untouched.
     *
     * @param array<string, mixed> $metadata the response metadata (`metadata.response`)
     * @return array<string, mixed>
     */
    public function applyTo(array $metadata): array
    {
        if ($this->isEmpty()) {
            return $metadata;
        }

        $metadata['invocations'] = $this->invocations();
        $metadata['attached_actions'] = $this->summary();

        return $metadata;
    }

    /**
     * Observation summary: role + key + label per chip (never ids).
     *
     * @return list<array{role: string, key: string, text: string}>
     */
    public function summary(): array
    {
        return array_map(static fn (array $chip) => [
            'role' => $chip['role'],
            'key' => $chip['invocation']->key,
            'text' => $chip['invocation']->text,
        ], $this->chips);
    }

    /**
     * The markdown link for row N's primary action, or null when N is no openable record.
     */
    public function recordLink(int $n): ?string
    {
        $invocation = $this->records[$n] ?? null;
        if ($invocation === null) {
            return null;
        }

        $text = strtr($invocation->text, ['[' => '(', ']' => ')']);

        return '[' . $text . '](entity://' . $invocation->entityType . '/' . $invocation->entityId . '/' . $invocation->key . ')';
    }

    /**
     * Replace every {{record:N}} with its record link; an unknown N is stripped.
     */
    public function substitute(string $answer): string
    {
        return (string) preg_replace_callback(
            self::STRIP_PATTERN,
            function (array $m): string {
                $link = $this->recordLink((int) $m[2]);

                return $link !== null ? $m[1] . $link : '';
            },
            $answer
        );
    }

    /** Strip every {{record:N}} placeholder (paths without attached records). */
    public static function strip(string $answer): string
    {
        return (string) preg_replace(self::STRIP_PATTERN, '', $answer);
    }

    /** A placeholder with the one space before it (consumed when the placeholder is stripped). */
    private const STRIP_PATTERN = '/([ \t]?)\{\{\s*record\s*:\s*(\d+)\s*\}\}/i';
}
