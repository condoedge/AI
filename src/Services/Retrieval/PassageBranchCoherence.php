<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

/**
 * PassageBranchCoherence — one branch of a document per answer, when its branches
 * are confusable.
 *
 * A structured document can give a section the SAME TITLE in two of its parts: a
 * law titles a division "rights and obligations of members" in both its
 * first and its second chapter. The leaf heading is what a plain question
 * embeds closest to, so a question about one part retrieves passages of both, and
 * the answer then serves a rule of the other part as if it applied (eval p17-B
 * rights t0 — the full heading path on the passage header did not prevent it).
 *
 * Rule, on the candidate passages of ONE file:
 *  - two candidates whose leaf headings carry the same title but sit at different
 *    places of the document are a COLLISION; the level where their heading paths
 *    part is the branching level (the top-most one when several collide);
 *  - every candidate belongs to the branch its path names at that level;
 *  - each search-angle VOTE GROUP casts one vote, split between the branches the
 *    way its own passages are: every angle is a ballot — its passages of the file,
 *    best first, each weighing 1 / log2(1 + position), the standard rank discount
 *    — and a group of several angles (one probe written in two languages) is the
 *    mean of their ballots. A branch's vote weight is the sum over the groups;
 *  - of two branches that hold a same-titled section, the lighter one is dropped —
 *    all of its candidates, not only the same-titled section — provided the
 *    heavier one clearly carries the vote between them ($minShare). On a split
 *    vote nothing is dropped: picking a side would be a coin flip.
 *
 * Why ballots and not the candidates' summed fusion scores: a branch with more
 * (or more spread-out) passages collects more candidates whatever the question,
 * so a question about the small branch — every angle LEADING with it — lost to
 * the large one (measured live). One vote per group, weighed by the angle's own
 * order, follows what the angles prefer, not how many passages a branch has.
 *
 * Nothing happens when no titles collide (a question may legitimately span two
 * parts of a document), when all candidates sit in one branch, or when the file
 * carries no heading paths. Structural only: heading paths, numbering grammar and
 * the angles' rankings — never a vocabulary, never a corpus rule.
 */
final class PassageBranchCoherence
{
    /** Share of the two branches' combined vote the heavier one needs to evict the other. */
    public const DEFAULT_MIN_SHARE = 0.55;

    /** An ordinal: arabic ("3", "2.3") or roman ("IV") numbering. */
    private const ORDINAL = '(?:\d+(?:\.\d+)*|[IVXLCDM]+)';

    /** Longest numbering label in front of a title ("§ 1.", "DIVISION II", "Section 2"), in words. */
    private const MAX_LABEL_WORDS = 3;

    /**
     * @param array<int, list<string>> $paths   Candidate chunk_index => its heading path, outermost level first.
     * @param list<array{group: string, picks: list<int>}> $ballots One per search angle: its vote
     *        group and the chunk indexes of its passages of this file, best first.
     * @return array{dropped: list<int>, kept: string, dropped_branches: list<string>, weights: array<string, float>}|null
     *         null when every candidate stays. weights: branch heading => its vote weight (in vote groups).
     */
    public static function resolve(array $paths, array $ballots, float $minShare = self::DEFAULT_MIN_SHARE): ?array
    {
        $paths = array_filter(array_map([self::class, 'levels'], $paths));
        if (count($paths) < 2 || $ballots === []) {
            return null;
        }

        // Same-titled sections at different places, and the level where they part.
        $byTitle = [];
        foreach ($paths as $path) {
            $title = self::title($path[count($path) - 1]);
            if ($title !== '') {
                $byTitle[$title][implode("\u{1F}", $path)] = $path;
            }
        }
        $level = null;
        $collisions = [];
        foreach ($byTitle as $places) {
            $places = array_values($places);
            for ($i = 0; $i < count($places); $i++) {
                for ($j = $i + 1; $j < count($places); $j++) {
                    $parting = self::partingLevel($places[$i], $places[$j]);
                    if ($parting !== null) {
                        $collisions[] = [$places[$i], $places[$j], $parting];
                        $level = $level === null ? $parting : min($level, $parting);
                    }
                }
            }
        }
        if ($level === null) {
            return null;
        }

        $branchOf = static fn (array $path): ?string => count($path) > $level
            ? implode("\u{1F}", array_slice($path, 0, $level + 1))
            : null;

        $labels = [];
        foreach ($paths as $path) {
            $branch = $branchOf($path);
            if ($branch !== null) {
                $labels[$branch] = $path[$level];
            }
        }

        $branchWeights = self::voteWeights($paths, $ballots, $branchOf) + array_fill_keys(array_keys($labels), 0.0);

        // Of two branches holding a same-titled section, the lighter one goes.
        $lost = [];
        $won = [];
        foreach ($collisions as [$a, $b, $parting]) {
            if ($parting !== $level) {
                continue; // they part deeper, inside one branch
            }
            $branchA = $branchOf($a);
            $branchB = $branchOf($b);
            $weightA = $branchWeights[$branchA];
            $weightB = $branchWeights[$branchB];
            $total = $weightA + $weightB;
            if ($total <= 0.0 || max($weightA, $weightB) / $total < $minShare) {
                continue; // split vote
            }
            [$winner, $loser] = $weightA >= $weightB ? [$branchA, $branchB] : [$branchB, $branchA];
            $lost[$loser] = true;
            $won[$winner] = $branchWeights[$winner];
        }
        if ($lost === []) {
            return null;
        }

        $dropped = [];
        foreach ($paths as $index => $path) {
            $branch = $branchOf($path);
            if ($branch !== null && isset($lost[$branch])) {
                $dropped[] = (int) $index;
            }
        }

        $won = array_diff_key($won, $lost);
        arsort($won);
        $kept = $won !== [] ? (string) array_key_first($won) : null;

        $traceWeights = [];
        foreach ($branchWeights as $branch => $weight) {
            $traceWeights[$labels[$branch]] = round($weight, 4);
        }

        return [
            'dropped' => $dropped,
            'kept' => $kept !== null ? $labels[$kept] : '',
            'dropped_branches' => array_values(array_map(static fn (string $branch): string => $labels[$branch], array_keys($lost))),
            'weights' => $traceWeights,
        ];
    }

    /**
     * Each vote group's one vote, split between the branches: per ballot, the
     * share of its rank-discounted passages that lie in each branch (a passage
     * of no branch keeps its place in the order and votes for none); per group,
     * the mean of its ballots.
     *
     * @param array<int, list<string>> $paths
     * @param list<array{group: string, picks: list<int>}> $ballots
     * @return array<string, float> branch => vote weight
     */
    private static function voteWeights(array $paths, array $ballots, \Closure $branchOf): array
    {
        $groupVotes = [];
        $groupBallots = [];
        foreach ($ballots as $ballot) {
            $shares = [];
            $position = 0;
            foreach ((array) ($ballot['picks'] ?? []) as $index) {
                $position++;
                $branch = isset($paths[$index]) ? $branchOf($paths[$index]) : null;
                if ($branch !== null) {
                    $shares[$branch] = ($shares[$branch] ?? 0.0) + 1.0 / log($position + 1, 2);
                }
            }
            $total = array_sum($shares);
            if ($total <= 0.0) {
                continue; // none of its passages sits in a branch: no vote
            }
            $group = (string) ($ballot['group'] ?? '');
            $groupBallots[$group] = ($groupBallots[$group] ?? 0) + 1;
            foreach ($shares as $branch => $share) {
                $groupVotes[$group][$branch] = ($groupVotes[$group][$branch] ?? 0.0) + $share / $total;
            }
        }

        $weights = [];
        foreach ($groupVotes as $group => $votes) {
            foreach ($votes as $branch => $vote) {
                $weights[$branch] = ($weights[$branch] ?? 0.0) + $vote / $groupBallots[$group];
            }
        }

        return $weights;
    }

    /**
     * A heading's TITLE: the heading without its numbering, case- and
     * spacing-insensitive. '' when the heading is numbering only ("SECTION 1"
     * repeats in every part of a document by construction — it titles nothing).
     *
     * Numbering grammar only: a short label holding an ordinal in front of a
     * separator ("DIVISION II — …", "§ 1. — …", "Section 2: …"), or a leading
     * enumerator ("2.3 …", "IV. …", "a) …").
     */
    public static function title(string $heading): string
    {
        $heading = trim((string) preg_replace('/\s+/u', ' ', $heading));

        $parts = preg_split('/\s*[—–:]\s*|\s+-\s+/u', $heading, 2);
        if (is_array($parts) && count($parts) === 2 && self::isNumberingLabel($parts[0])) {
            $heading = $parts[1];
        } elseif (preg_match('/^[^\p{L}\p{N}]*(?:\d+(?:\.\d+)*[.)]?|(?:[IVXLCDM]+|\p{L})[.)])\s+(.+)$/u', $heading, $m) === 1) {
            $heading = $m[1];
        } elseif (self::isNumberingLabel($heading)) {
            return '';
        }

        $heading = (string) preg_replace('/^[\s\p{P}]+|[\s\p{P}]+$/u', '', mb_strtolower($heading));

        return preg_match('/^\d+(?:\.\d+)*$/', $heading) === 1 ? '' : $heading;
    }

    /** "DIVISION II", "§ 1.", "Section 2", "3": at most a few words, the last one an ordinal. */
    private static function isNumberingLabel(string $text): bool
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === [] || count($words) > self::MAX_LABEL_WORDS) {
            return false;
        }

        return preg_match('/^' . self::ORDINAL . '[.)]?$/u', $words[count($words) - 1]) === 1;
    }

    /**
     * The first level at which two heading paths differ; null when one path
     * continues the other (a section and its own sub-section are one branch).
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function partingLevel(array $a, array $b): ?int
    {
        $shared = min(count($a), count($b));
        for ($level = 0; $level < $shared; $level++) {
            if ($a[$level] !== $b[$level]) {
                return $level;
            }
        }

        return null;
    }

    /**
     * @return list<string> The path's non-empty levels, whitespace-normalised.
     */
    private static function levels(mixed $path): array
    {
        $levels = [];
        foreach (is_array($path) ? $path : [] as $level) {
            $level = is_scalar($level) ? trim((string) preg_replace('/\s+/u', ' ', (string) $level)) : '';
            if ($level !== '') {
                $levels[] = $level;
            }
        }

        return $levels;
    }
}
