<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

use Condoedge\Ai\Support\ArticleLocator;

/**
 * DeepeningContract — what a follow-up knowledge turn must ADD.
 *
 * "Tell me more about X" retrieves the passage with the new rule next to the
 * passages the previous answer was built on, and the composer, shown both alike,
 * re-served the previous answer (eval p17-B rights t1: the passage holding the
 * late-payment penalty ranked 1–2 on every angle and was never mentioned).
 *
 * The contract is computed by code from the conversation memory — the passages
 * the previous answer cited (`last_referenced_files`: file, chunk, the articles it
 * named) — against this turn's passages:
 *  - COVERED: the articles the previous answer cited;
 *  - NEW: per passage of this turn, the articles it defines that are not covered
 *    (a passage without article numbers is new when the previous answer did not
 *    cite that very passage).
 *
 * Article numbers come from the passages' own structure
 * ({@see ArticleLocator}: line-start clause numbers, else the chunk metadata) and
 * are compared within one document. No vocabulary anywhere.
 */
final class DeepeningContract
{
    /**
     * The contract between the previous turn's citations and this turn's
     * passages, or null when there is none to state: no previous citation,
     * nothing new, or a turn that neither returns to covered ground nor was
     * judged a follow-up by Query Understanding (a stale memory of an old
     * citation must not shape an unrelated answer).
     *
     * @param array<int, mixed> $previousReferences The conversation's `last_referenced_files`.
     * @param array<int, mixed> $currentFiles       This turn's passages, in prompt order ([1], [2], …).
     * @param bool              $followUp           Query Understanding's follow_up_reference.
     * @return array{covered: list<int>, covered_passages: list<int>, new: array<int, list<int>>}|null
     *         covered: article numbers already cited; covered_passages: this turn's
     *         passage numbers that hold nothing new; new: passage number => its new
     *         article numbers ([] for a new passage without article numbers).
     */
    public static function between(array $previousReferences, array $currentFiles, bool $followUp = false): ?array
    {
        $compared = self::compare($previousReferences, $currentFiles);
        if ($compared === null || $compared['new'] === [] || !($followUp || $compared['returns_to_covered_ground'])) {
            return null;
        }
        unset($compared['returns_to_covered_ground']);

        return $compared;
    }

    /**
     * The comparison itself, without the judgment of whether a contract is worth stating
     * ({@see self::between()}): what the given references cover, against these passages.
     * For a consumer that needs the split whatever the turn — the follow-up question chips
     * ({@see \Condoedge\Ai\Services\Response\FollowUpWriter}) hand in the conversation's
     * citations AND the ones of the answer just written.
     *
     * Null only when no reference names a file: nothing can be compared.
     *
     * @param array<int, mixed> $references   Cited passages (file, chunk, `cited_articles`).
     * @param array<int, mixed> $currentFiles This turn's passages, in prompt order ([1], [2], …).
     * @return array{covered: list<int>, covered_passages: list<int>, new: array<int, list<int>>, returns_to_covered_ground: bool}|null
     *         as {@see self::between()}, plus whether one of the passages holds an article
     *         (or is a passage) the references already cited.
     */
    public static function compare(array $references, array $currentFiles): ?array
    {
        $coveredByFile = [];
        $citedChunks = [];
        foreach ($references as $record) {
            $record = (array) $record;
            $fileId = self::fileId($record);
            if ($fileId === '') {
                continue;
            }
            $coveredByFile[$fileId] = [...($coveredByFile[$fileId] ?? []), ...self::citedArticles($record)];
            if (is_numeric($record['chunk_index'] ?? null)) {
                $citedChunks[$fileId][(int) $record['chunk_index']] = true;
            }
        }
        if ($coveredByFile === []) {
            return null;
        }

        $new = [];
        $coveredPassages = [];
        $returnsToCoveredGround = false;
        foreach (array_values($currentFiles) as $position => $file) {
            if (!is_array($file) || !empty($file['listing_only'])) {
                continue;
            }
            $number = $position + 1;
            $fileId = self::fileId($file);
            $covered = $coveredByFile[$fileId] ?? [];
            $articles = ArticleLocator::forReference($file);
            $wasCited = is_numeric($file['chunk_index'] ?? null) && isset($citedChunks[$fileId][(int) $file['chunk_index']]);

            if ($articles === []) {
                // No article numbers: the passage itself is the unit.
                if ($wasCited) {
                    $coveredPassages[] = $number;
                    $returnsToCoveredGround = true;
                } else {
                    $new[$number] = [];
                }
                continue;
            }

            $fresh = array_values(array_diff($articles, $covered));
            if (count($fresh) < count($articles) || $wasCited) {
                $returnsToCoveredGround = true;
            }
            if ($fresh === []) {
                $coveredPassages[] = $number;
            } else {
                $new[$number] = $fresh;
            }
        }

        $covered = array_values(array_unique(array_merge(...array_values($coveredByFile))));
        sort($covered);

        return [
            'covered' => $covered,
            'covered_passages' => $coveredPassages,
            'new' => $new,
            'returns_to_covered_ground' => $returnsToCoveredGround,
        ];
    }

    /**
     * Whether ONE passage is ground the previous answer covered: the very chunk
     * it cited, or a chunk that defines an article it cited (same document).
     *
     * For the caller that must tell a question ABOUT what was just answered from
     * a new question whose search merely brushes it: asked of the passage the
     * question's own wording ranks first (AiManager). The passage's articles are
     * those of its OWN chunk (`passage`, the chunk's text) when the reference
     * carries it — not of the window stitched around it, which reaches into the
     * neighbouring articles.
     *
     * @param array<int, mixed> $previousReferences The conversation's `last_referenced_files`.
     * @param array<string, mixed> $passage One of this turn's passages.
     */
    public static function coveredGround(array $previousReferences, array $passage): bool
    {
        $fileId = self::fileId($passage);
        if ($fileId === '') {
            return false;
        }

        $covered = [];
        $citedChunks = [];
        foreach ($previousReferences as $record) {
            $record = (array) $record;
            if (self::fileId($record) !== $fileId) {
                continue;
            }
            $covered = [...$covered, ...self::citedArticles($record)];
            if (is_numeric($record['chunk_index'] ?? null)) {
                $citedChunks[(int) $record['chunk_index']] = true;
            }
        }

        if (is_numeric($passage['chunk_index'] ?? null) && isset($citedChunks[(int) $passage['chunk_index']])) {
            return true;
        }

        $own = is_string($passage['passage'] ?? null) && trim($passage['passage']) !== ''
            ? ['snippet' => $passage['passage']] + $passage
            : $passage;

        return array_intersect(ArticleLocator::forReference($own), $covered) !== [];
    }

    /**
     * The chunks the previous turn cited, per file: file id => chunk indexes.
     *
     * @param array<string, mixed> $conversationContext
     * @return array<int|string, list<int>>
     */
    public static function citedChunks(array $conversationContext): array
    {
        $cited = [];
        foreach ((array) ($conversationContext['last_referenced_files'] ?? []) as $record) {
            $record = (array) $record;
            $fileId = self::fileId($record);
            if ($fileId === '' || !is_numeric($record['chunk_index'] ?? null)) {
                continue;
            }
            $index = (int) $record['chunk_index'];
            if (!in_array($index, $cited[$fileId] ?? [], true)) {
                $cited[$fileId][] = $index;
            }
        }

        return $cited;
    }

    /**
     * "art. 4, 11–14, 19": ascending, three or more consecutive numbers
     * written as a run. '' for no article.
     *
     * @param array<int, int> $articles
     */
    public static function formatArticles(array $articles): string
    {
        $articles = array_values(array_unique(array_map('intval', $articles)));
        sort($articles);
        if ($articles === []) {
            return '';
        }

        $parts = [];
        $run = [];
        $flush = static function () use (&$parts, &$run): void {
            if (count($run) >= 3) {
                $parts[] = $run[0] . '–' . $run[count($run) - 1];
            } else {
                array_push($parts, ...array_map('strval', $run));
            }
            $run = [];
        };
        foreach ($articles as $article) {
            if ($run !== [] && $article !== $run[count($run) - 1] + 1) {
                $flush();
            }
            $run[] = $article;
        }
        $flush();

        return 'art. ' . implode(', ', $parts);
    }

    /**
     * The articles a previous-turn record covers: the ones the answer named among
     * those its passage defines (`cited_articles`, stored with the record). When
     * the answer cited the passage without naming an article (and for records
     * stored before that list existed), every article the cited passage defines:
     * the user was shown an answer drawn from that passage, and live an answer
     * citing one passage five times had used each of its articles — counting only
     * the cited chunk's own article marked the others NEW and they were re-served.
     *
     * @return list<int>
     */
    private static function citedArticles(array $record): array
    {
        $stored = array_values(array_filter((array) ($record['cited_articles'] ?? []), 'is_numeric'));

        return $stored !== [] ? array_map('intval', $stored) : ArticleLocator::forReference($record);
    }

    /** The file a previous-turn record ('id') or a current reference ('file_id') belongs to. */
    private static function fileId(array $reference): string
    {
        $id = $reference['file_id'] ?? $reference['id'] ?? '';

        return is_scalar($id) ? (string) $id : '';
    }
}
