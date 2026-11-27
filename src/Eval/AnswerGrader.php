<?php

namespace Condoedge\Ai\Eval;

use Condoedge\Ai\Services\ResponseSections\SelfReferenceNoticeSection;
use Condoedge\Ai\Support\StatedNumber;
use Illuminate\Support\Str;

/**
 * Grades one answer against a suite turn's 'grade' without any model: 'all' (every expected
 * value appears), 'any' (at least one does), 'forbid' (none does), 'refusal' (the assistant
 * declined). Expected values are closures returning numbers or strings, read now so they are the
 * data of the day. A turn without a grade is left ungraded (passed = null), and so is one whose
 * expected values are all zero or missing: a 0 is in too many answers to prove anything.
 *
 * What the turn did, read from its trace rather than its wording:
 *  - 'reads' (int[])                     the answer rests on one of these documents (File ids);
 *  - 'previous_result_available' (bool)  the turn received the rows of the turn before it: a
 *                                        follow-up ("put that in Excel") that lost them fails here;
 *  - 'produces_file' ('excel'|'pdf')     the answer is an export of that format, with its rows;
 *  - 'columns_include' (string[])        the file's headers, else the rows' columns, name each of
 *                                        these (case, accents and word breaks ignored);
 *  - 'min_rows' (int)                    the query returned at least this many rows;
 *  - 'branch_in' (string[])              the way the assistant answered is one of these;
 *  - 'audience_is' (?string)             the audience the application gave the asker;
 *  - 'cites_document' (bool)             the answer cites files (or, false, cites none);
 *  - 'discloses_total' (bool)            when fewer rows were shown than exist, the answer states
 *                                        the exact total and the shown count or the remainder;
 *  - 'disambiguation_column_present' (bool) the rows (else the file's) have two columns or more
 *                                        and no two of them are identical: same-name records
 *                                        (three units "310") can be told apart;
 *  - 'no_self_contact' (bool)            an answer giving the asker's own email says it is theirs;
 *  - 'promised_columns_nonempty' (bool)  the file has rows and no column empty in all of them.
 * The last five come from the former ai-eval command, with its meaning: a check with nothing to
 * look at (nothing withheld, no email, no rows) passes.
 *
 * A failed turn also gets its cause (cause()): what to look at first, not a second verdict.
 */
class AnswerGrader
{
    // The pipeline's own refusal branch, or a refusal worded by the answer model.
    protected const REFUSAL_ROUTES = ['access_denied', 'blocked_by_scoping'];

    public const KEYS = [
        'all', 'any', 'forbid', 'refusal', 'reads', 'previous_result_available', 'produces_file', 'columns_include', 'min_rows', 'branch_in', 'audience_is',
        'cites_document', 'discloses_total', 'disambiguation_column_present', 'no_self_contact', 'promised_columns_nonempty',
    ];

    // A cell the file shows as empty.
    protected const EMPTY_CELLS = ['', 'none', 'null'];

    /** The causes of a failed turn, the most telling first. */
    public const CAUSES = ['error', 'leaked', 'not_refused', 'refused', 'lost_context', 'no_file', 'not_found', 'wrong'];

    protected const REFUSAL_PATTERNS = [
        '/ne me permet pas/u',
        '/je ne (peux|suis pas en mesure de) (pas )?(vous )?(fournir|donner|partager|communiquer|divulguer|afficher|répondre)/u',
        '/(n\'est|ne sont) pas (accessible|disponible|affichée?)s? pour vous/u',
        '/not (allowed|permitted|able) to (share|provide|access|show)/u',
        '/(cannot|can\'t|can not) (share|provide|disclose|show|access)/u',
    ];

    /** A typo in a suite would leave its turn silently ungraded: it stops the launch instead. */
    public static function assertKnown(array $grade, string $where): void
    {
        if ($unknown = array_diff(array_keys($grade), static::KEYS)) {
            throw new \InvalidArgumentException('Unknown grade key '.implode(', ', $unknown).' in '.$where.'. Known: '.implode(', ', static::KEYS).'.');
        }
    }

    /**
     * @param array{previous_rows?: int, export?: ?array, columns?: array, row_count?: ?int, cited?: int, shown?: int, total?: int, sample?: array, export_table?: array, email?: ?string} $turn what the turn did
     */
    public function grade(string $answer, array $grade, ?string $route = null, array $documents = [], array $turn = []): array
    {
        if (!array_intersect(array_keys($grade), static::KEYS)) {
            return ['passed' => null, 'checks' => []];
        }

        $checks = [];

        foreach (['all', 'any', 'forbid'] as $mode) {
            if (!array_key_exists($mode, $grade)) {
                continue;
            }

            $expected = array_values(array_filter($this->values($grade[$mode]), fn ($value) => !is_numeric($value) || (float) $value != 0));

            if (!$expected && $mode != 'forbid') {
                continue;
            }

            $found = array_values(array_filter($expected, fn ($value) => $this->contains($answer, $value)));

            $checks[] = [
                'mode' => $mode,
                'expected' => $expected,
                'found' => $found,
                'passed' => match ($mode) {
                    'all' => count($found) == count($expected) && count($expected) > 0,
                    'any' => count($found) > 0,
                    'forbid' => count($found) == 0,
                },
            ];
        }

        // The answer rests on a document of the application (its id in Files); an id of 0 is "not known here".
        $reads = array_values(array_filter(array_map('intval', (array) ($grade['reads'] ?? []))));

        if ($reads) {
            $read = array_values(array_intersect($reads, array_map('intval', $documents)));
            $checks[] = ['mode' => 'reads', 'expected' => $reads, 'found' => $read, 'passed' => count($read) > 0];
        }

        $checks = array_merge($checks, $this->turnChecks($grade, $route, $turn), $this->answerChecks($answer, $grade, $turn));

        if (!empty($grade['refusal'])) {
            $checks[] = ['mode' => 'refusal', 'route' => $route, 'passed' => $this->isRefusal($answer, $route)];
        }

        return [
            'passed' => $checks ? collect($checks)->every(fn ($check) => $check['passed']) : null,
            'checks' => $checks,
        ];
    }

    protected function turnChecks(array $grade, ?string $route, array $turn): array
    {
        $checks = [];

        if (array_key_exists('previous_result_available', $grade)) {
            $rows = (int) ($turn['previous_rows'] ?? 0);
            $checks[] = ['mode' => 'previous_result_available', 'expected' => [(bool) $grade['previous_result_available']], 'found' => [$rows], 'passed' => ($rows > 0) === (bool) $grade['previous_result_available']];
        }

        if (array_key_exists('produces_file', $grade)) {
            $export = $turn['export'] ?? null;
            $checks[] = [
                'mode' => 'produces_file',
                'expected' => [$grade['produces_file']],
                'found' => $export ? [$export['format'].' · '.$export['rows']] : [],
                'passed' => $export && $export['format'] === $grade['produces_file'] && $export['rows'] > 0,
            ];
        }

        if (array_key_exists('columns_include', $grade)) {
            $columns = (array) ($turn['columns'] ?? []);
            $expected = array_values((array) $grade['columns_include']);
            $checks[] = [
                'mode' => 'columns_include',
                'expected' => $expected,
                'found' => $columns,
                'passed' => collect($expected)->every(fn ($name) => $this->hasColumn($columns, (string) $name)),
            ];
        }

        if (array_key_exists('min_rows', $grade)) {
            $rows = (int) ($turn['row_count'] ?? 0);
            $checks[] = ['mode' => 'min_rows', 'expected' => [(int) $grade['min_rows']], 'found' => [$rows], 'passed' => $rows >= (int) $grade['min_rows']];
        }

        if (array_key_exists('audience_is', $grade)) {
            $audience = $turn['audience'] ?? null;
            $checks[] = ['mode' => 'audience_is', 'expected' => [$grade['audience_is'] ?? '—'], 'found' => [$audience ?? '—'], 'passed' => $audience === $grade['audience_is']];
        }

        if (array_key_exists('branch_in', $grade)) {
            $checks[] = ['mode' => 'branch_in', 'expected' => array_values((array) $grade['branch_in']), 'found' => array_filter([$route]), 'passed' => in_array($route, (array) $grade['branch_in'], true)];
        }

        return $checks;
    }

    /** What the person received: the files cited, the rows withheld, the rows and the file themselves. */
    protected function answerChecks(string $answer, array $grade, array $turn): array
    {
        $checks = [];

        if (array_key_exists('cites_document', $grade)) {
            $cited = (int) ($turn['cited'] ?? 0);
            $checks[] = ['mode' => 'cites_document', 'expected' => [(bool) $grade['cites_document']], 'found' => [$cited], 'passed' => ($cited > 0) === (bool) $grade['cites_document']];
        }

        if (array_key_exists('discloses_total', $grade)) {
            $shown = (int) ($turn['shown'] ?? count($turn['sample'] ?? []));
            $total = max((int) ($turn['total'] ?? 0), (int) ($turn['row_count'] ?? 0));
            $checks[] = [
                'mode' => 'discloses_total',
                'expected' => [$shown.' / '.$total],
                'passed' => !$grade['discloses_total'] || $total <= $shown || StatedNumber::disclosesTruncation($answer, $total, $shown),
            ];
        }

        if (array_key_exists('disambiguation_column_present', $grade)) {
            $checks[] = ['mode' => 'disambiguation_column_present'] + ($grade['disambiguation_column_present'] ? $this->distinctRows($turn) : ['passed' => true]);
        }

        if (array_key_exists('no_self_contact', $grade)) {
            $email = (string) ($turn['email'] ?? '');
            $given = $grade['no_self_contact'] && $email !== '' && mb_stripos($answer, $email) !== false;
            $text = SelfReferenceNoticeSection::normalizeMarkerText($answer);
            $checks[] = [
                'mode' => 'no_self_contact',
                'found' => $given ? [$email] : [],
                'passed' => !$given || collect(SelfReferenceNoticeSection::markerPhrases())->contains(fn ($marker) => $marker !== '' && str_contains($text, $marker)),
            ];
        }

        if (array_key_exists('promised_columns_nonempty', $grade)) {
            ['headers' => $headers, 'rows' => $rows] = ($turn['export_table'] ?? []) + ['headers' => [], 'rows' => []];
            $empty = collect(array_values($headers))->filter(fn ($header, $index) => collect($rows)->every(fn ($cells) => in_array(mb_strtolower(trim((string) ($cells[$index] ?? ''))), static::EMPTY_CELLS, true)))->values()->all();
            $checks[] = [
                'mode' => 'promised_columns_nonempty',
                'found' => $empty,
                'passed' => !$grade['promised_columns_nonempty'] || ($headers && $rows && !$empty),
            ];
        }

        return $checks;
    }

    /** The rows shown (else the file's) have two columns or more and no two identical rows. */
    protected function distinctRows(array $turn): array
    {
        $rows = array_values(array_filter((array) ($turn['sample'] ?? []), 'is_array'));

        if (!$rows && !empty($turn['export_table']['headers'])) {
            $headers = array_values($turn['export_table']['headers']);
            $rows = array_map(fn ($cells) => array_combine($headers, array_map(fn ($index) => $cells[$index] ?? null, array_keys($headers))), (array) $turn['export_table']['rows']);
        }

        // No rows: an honest "none" or a question back, judged by the standard, not here.
        if (!$rows) {
            return ['found' => [0], 'passed' => true];
        }

        $columns = collect($rows)->flatMap(fn ($row) => array_keys($row))->unique()->values()->all();
        $same = collect($rows)->map(fn ($row) => serialize(array_map(fn ($column) => $row[$column] ?? null, $columns)))
            ->countBy()->filter(fn ($count) => $count > 1)->count();

        return ['expected' => ['2+'], 'found' => $columns, 'identical' => $same, 'passed' => count($columns) >= 2 && !$same];
    }

    /**
     * Why a turn failed, read from its checks and trace: it broke (error), stated a forbidden value
     * (leaked), answered where it had to refuse (not_refused), refused where it had to answer
     * (refused), lost the turn before (lost_context), made no file or the wrong one (no_file), found
     * nothing (not_found), or stated something else than expected (wrong). Null when it did not fail.
     */
    public function cause(?bool $passed, array $checks, string $answer, ?string $route, array $turn, ?string $error = null): ?string
    {
        if ($error || $route == 'error') {
            return 'error';
        }

        if ($passed !== false) {
            return null;
        }

        $failed = collect($checks)->reject(fn ($check) => $check['passed'])->pluck('mode');
        $expectsRefusal = collect($checks)->contains('mode', 'refusal');

        return match (true) {
            $failed->contains('forbid') => 'leaked',
            $failed->contains('refusal') => 'not_refused',
            !$expectsRefusal && $this->isRefusal($answer, $route) => 'refused',
            $failed->contains('previous_result_available') => 'lost_context',
            $failed->contains('produces_file') => 'no_file',
            !($turn['row_count'] ?? 0) && empty($turn['documents']) => 'not_found',
            default => 'wrong',
        };
    }

    protected function hasColumn(array $columns, string $name): bool
    {
        $squash = fn (string $text) => preg_replace('/[^a-z0-9]/', '', $this->normalize(preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $text)));
        $needle = $squash($name);

        return $needle !== '' && collect($columns)->contains(fn ($column) => str_contains($squash((string) $column), $needle));
    }

    public function isRefusal(string $answer, ?string $route = null): bool
    {
        if (in_array($route, static::REFUSAL_ROUTES, true)) {
            return true;
        }

        $text = mb_strtolower($answer);

        return collect(static::REFUSAL_PATTERNS)->contains(fn ($pattern) => preg_match($pattern, $text));
    }

    public function contains(string $answer, mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return collect($this->numbersIn($answer))->contains(fn ($number) => $this->sameAmount($number, (float) $value));
        }

        return Str::contains($this->normalize($answer), $this->normalize((string) $value));
    }

    /**
     * The numbers written in a text, in French or English: "45 747,65 $", "$45,747.65", "1,64 %".
     *
     * @return array<float>
     */
    public function numbersIn(string $text): array
    {
        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}"], ' ', $text);
        $text = str_replace(['−', '–'], '-', $text);

        preg_match_all('/-?\d{1,3}(?:[ ,.]\d{3})+(?:[.,]\d{1,2})?(?!\d)|-?\d+(?:[.,]\d{1,2})?(?!\d)/u', $text, $matches);

        return array_map(fn ($token) => $this->parseNumber($token), $matches[0]);
    }

    protected function parseNumber(string $token): float
    {
        $token = str_replace(' ', '', $token);
        $last = max(strrpos($token, ','), strrpos($token, '.'));

        if ($last === false) {
            return (float) $token;
        }

        $decimals = strlen($token) - $last - 1;
        $integer = preg_replace('/[^\d-]/', '', substr($token, 0, $last));
        $fraction = substr($token, $last + 1);

        return $decimals == 3 ? (float) ($integer.$fraction) : (float) ($integer.'.'.$fraction);
    }

    // To the cent, or to the dollar when the answer rounds a larger amount.
    protected function sameAmount(float $written, float $expected): bool
    {
        if (abs($written - $expected) < 0.005) {
            return true;
        }

        return abs($expected) >= 100 && floor($written) == $written && abs($written - round($expected)) < 0.5;
    }

    protected function values(mixed $value): array
    {
        $value = $value instanceof \Closure ? $value() : $value;

        return collect(is_array($value) ? $value : [$value])
            ->flatMap(fn ($item) => is_array($item) ? $this->values($item) : [$item])
            ->filter(fn ($item) => $item !== null && $item !== '')
            ->values()->all();
    }

    protected function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(Str::ascii($text))));
    }
}
