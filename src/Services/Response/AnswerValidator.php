<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Response;

use Condoedge\Ai\Services\ResponseSections\SelfReferenceNoticeSection;
use Condoedge\Ai\Support\AliasNormalizer;
use Condoedge\Ai\Support\ArticleLocator;
use Condoedge\Ai\Support\StatedNumber;

/**
 * AnswerValidator — ONE post-composition validation pass (Phase 8 batch B).
 *
 * Replaces the three stacked guard retries (citation enforcement, truncation
 * honesty, self-reference) and the "re-assert" step that patched what a later
 * guard's retry had undone. Every check is PURE and STRUCTURAL over the masked
 * answer and the turn's own evidence — no semantic word lists:
 *
 *   citations   every [k] names an existing passage; literal "[N]" placeholders
 *               ("[N]", "[N] 2", "([N] 2)") are flagged; a sentence citing
 *               "art. 12" must cite a passage that contains 12; a
 *               document-grounded turn needs at least one valid marker.
 *   numbers     every stated quantity (StatedNumber::quantities) must be one of
 *               the turn's figures (real rows, plan facts/totals, counts, numbers
 *               in the prompt the model saw) — integers ≤ 31 and years ignored.
 *   links       `entity://Label/<id>` ids must be ids the rows carry for THAT
 *               label (EntityIdIndex); `action://…?x_id=` ids must be row ids.
 *   truncation  (narrative-only path) the exact total + shown/remainder are stated.
 *   self-ref    every line with the user's own email (token) carries a marker.
 *
 * Language is NOT checked: no language signal exists for the answer text (QU
 * detects the QUESTION's language) and a word-list detector is ruled out.
 *
 * ResponseGenerator: draft → validate → ONE combined retry (retryInstruction)
 * → validate → patch() for what remains → validate (violations_final).
 */
final class AnswerValidator
{
    public const CITATION_PLACEHOLDER = 'citation_placeholder';
    public const CITATION_OUT_OF_RANGE = 'citation_out_of_range';
    public const CITATION_ARTICLE_MISMATCH = 'citation_article_mismatch';
    public const CITATION_MISSING = 'citation_missing';
    public const NUMBER_UNSUPPORTED = 'number_unsupported';
    public const LINK_INVALID = 'link_invalid';
    public const TRUNCATION_UNDISCLOSED = 'truncation_undisclosed';
    public const SELF_REFERENCE_UNMARKED = 'self_reference_unmarked';

    /** A literal "[N]" placeholder (the instruction's own grammar echoed back). */
    private const PLACEHOLDER = '/\[\s*N\s*\]/i';

    /** A real citation marker — "[3]" but not a markdown link text "[3](…)". */
    private const MARKER = '/\[(\d{1,3})\](?!\()/';

    /** "art. 12" / "article 12" / "articles 12" — a numeric structural citation. */
    private const ARTICLE = '/\bart(?:icle)?s?\.?\s*(\d{1,5})\b/iu';

    private const MARKDOWN_LINK = '/\[([^\]]*)\]\((entity|action):\/\/([^)\s]*)\)/i';

    /**
     * @param array<int, string>  $passages        citation ref => passage text (the prompt's numbering)
     * @param bool                $citationsRequired a document-grounded turn must cite at least once
     * @param bool                $citationChecks  ref-range + article checks (off for terse styles)
     * @param list<float>|null    $evidence        the turn's figures; null = number check off
     * @param list<float>         $grandTotals     complete totals an aggregate may be patched to
     * @param list<float>         $moneyRowFigures the money-column values of the rows (never ids/phones)
     * @param list<float>         $keyFigures      figures quoted to the model in the retry
     * @param EntityIdIndex|null  $entityIds       null = link check off
     * @param array{total: int, shown: int}|null $truncation
     * @param string|null         $selfNeedle      the user's own email as it appears in the masked answer
     * @param array<int, list<int>>|null $passageArticles ref => articles the passage DEFINES
     *        (ArticleLocator::forReference); null = parsed from the passage text
     */
    public function __construct(
        private readonly array $passages = [],
        private readonly bool $citationsRequired = false,
        private readonly bool $citationChecks = true,
        private readonly ?array $evidence = null,
        private readonly array $grandTotals = [],
        private readonly array $moneyRowFigures = [],
        private readonly array $keyFigures = [],
        private readonly ?EntityIdIndex $entityIds = null,
        private readonly ?array $truncation = null,
        private readonly ?string $selfNeedle = null,
        private readonly ?string $locale = null,
        ?array $passageArticles = null,
    ) {
        $this->passageArticles = $passageArticles
            ?? array_map(static fn ($text) => ArticleLocator::definedArticles((string) $text), $passages);
    }

    /** Relative distance within which a stated total is a formatting/rounding slip (I1). */
    private const TOTAL_SLIP = 0.005;

    /** @var array<int, list<int>> */
    private array $passageArticles;

    // =========================================================================
    // Validation
    // =========================================================================

    /**
     * @return list<array{type: string, detail: string, fixable_deterministically: bool}&array<string, mixed>>
     */
    public function validate(string $answer): array
    {
        return [
            ...$this->citationViolations($answer),
            ...$this->numberViolations($answer),
            ...$this->linkViolations($answer),
            ...$this->truncationViolations($answer),
            ...$this->selfReferenceViolations($answer),
        ];
    }

    /** Whether the truncation-disclosure check is active this turn (narrative-only path). */
    public function checksTruncation(): bool
    {
        return $this->truncation !== null;
    }

    /** Whether the text mentions the user's own email (token) at all. */
    public function mentionsSelf(string $text): bool
    {
        return $this->selfNeedle !== null && mb_stripos($text, $this->selfNeedle) !== false;
    }

    /** @return list<string> distinct violation types, in order */
    public static function types(array $violations): array
    {
        return array_values(array_unique(array_map(static fn (array $v) => $v['type'], $violations)));
    }

    /**
     * Candidate ranking for draft vs retry: fewer violations needing the LLM, then
     * fewer violations overall. Lower is better.
     *
     * @return array{0: int, 1: int}
     */
    public static function severity(array $violations): array
    {
        $unfixable = count(array_filter($violations, static fn (array $v) => !$v['fixable_deterministically']));

        return [$unfixable, count($violations)];
    }

    // -------------------------------------------------------------------------

    private function citationViolations(string $answer): array
    {
        $violations = [];
        if (preg_match(self::PLACEHOLDER, $answer) === 1) {
            $violations[] = $this->violation(
                self::CITATION_PLACEHOLDER,
                'literal "[N]" placeholder instead of a passage number',
                true
            );
        }

        if (!$this->citationChecks || $this->passages === []) {
            return $violations;
        }

        // Checked on the placeholder-normalized text: "([N] 2)" becomes "[2]" in patch().
        $normalized = $this->normalizePlaceholders($answer);

        $outOfRange = array_values(array_unique(array_filter(
            $this->markers($normalized),
            fn (int $ref) => !isset($this->passages[$ref])
        )));
        if ($outOfRange !== []) {
            $violations[] = $this->violation(
                self::CITATION_OUT_OF_RANGE,
                'cited ' . $this->refList($outOfRange) . ' but only ' . $this->refList(array_keys($this->passages)) . ' exist',
                true,
                ['refs' => $outOfRange]
            );
        }

        foreach ($this->articleMismatches($normalized) as $mismatch) {
            $violations[] = $this->violation(
                self::CITATION_ARTICLE_MISMATCH,
                "art. {$mismatch['article']} is cited as " . $this->refList($mismatch['cited'])
                    . ($mismatch['found_in'] !== []
                        ? ' but appears only in ' . $this->refList($mismatch['found_in'])
                        : ' but appears in no passage'),
                // Movable only to the ONE passage that DEFINES the article (M1).
                count($mismatch['defined_in']) === 1 && count($mismatch['cited']) === 1 && !$mismatch['inherited'],
                $mismatch
            );
        }

        if ($this->citationsRequired && $this->validMarkers($normalized) === []) {
            $violations[] = $this->violation(
                self::CITATION_MISSING,
                'document-grounded answer without any valid citation marker',
                false
            );
        }

        return $violations;
    }

    private function numberViolations(string $answer): array
    {
        if ($this->evidence === null) {
            return [];
        }

        $violations = [];
        $seen = [];
        foreach (StatedNumber::quantities($this->numberText($answer)) as $quantity) {
            if ($this->ignorable($quantity) || StatedNumber::supported($quantity['values'], $quantity['decimals'], $this->evidence)) {
                continue;
            }
            if (isset($seen[$quantity['raw']])) {
                continue;
            }
            $seen[$quantity['raw']] = true;

            $replacement = $this->totalReplacement($quantity);
            $violations[] = $this->violation(
                self::NUMBER_UNSUPPORTED,
                $quantity['raw'],
                $replacement !== null,
                ['raw' => $quantity['raw'], 'replacement' => $replacement]
            );
        }

        return $violations;
    }

    private function linkViolations(string $answer): array
    {
        if ($this->entityIds === null) {
            return [];
        }

        $violations = [];
        foreach ($this->invalidLinks($answer) as $link) {
            $violations[] = $this->violation(self::LINK_INVALID, $link['reason'], true, $link);
        }

        return $violations;
    }

    private function truncationViolations(string $answer): array
    {
        if ($this->truncation === null
            || StatedNumber::disclosesTruncation($answer, $this->truncation['total'], $this->truncation['shown'])) {
            return [];
        }

        return [$this->violation(
            self::TRUNCATION_UNDISCLOSED,
            "total {$this->truncation['total']} with shown {$this->truncation['shown']} not stated",
            true
        )];
    }

    private function selfReferenceViolations(string $answer): array
    {
        if ($this->selfNeedle === null || !$this->hasUnmarkedEmailLine($answer)) {
            return [];
        }

        return [$this->violation(self::SELF_REFERENCE_UNMARKED, "{$this->selfNeedle} presented without a that's-you marker", true)];
    }

    // =========================================================================
    // Deterministic patches
    // =========================================================================

    /**
     * Apply every deterministic fix the violations allow. Returns the patched
     * answer and the violation types actually patched. Never invents content:
     * placeholders are normalized/stripped, out-of-range markers and invalid
     * links removed (link text kept), a mis-attributed article marker moved to
     * the one passage containing the article, an aggregate figure absent from the
     * data replaced by the plan's single known grand total, the that's-you marker
     * inserted, the localized truncation disclosure appended.
     *
     * @return array{0: string, 1: list<string>}
     */
    public function patch(string $answer, array $violations): array
    {
        $types = self::types($violations);
        $patched = [];

        if (in_array(self::CITATION_PLACEHOLDER, $types, true)) {
            $answer = $this->normalizePlaceholders($answer);
            $patched[] = self::CITATION_PLACEHOLDER;
        }

        if (in_array(self::CITATION_ARTICLE_MISMATCH, $types, true)) {
            [$answer, $moved] = $this->remapArticleMarkers($answer);
            if ($moved) {
                $patched[] = self::CITATION_ARTICLE_MISMATCH;
            }
        }

        if (in_array(self::CITATION_OUT_OF_RANGE, $types, true)) {
            $answer = (string) preg_replace_callback(
                '/\s?' . substr(self::MARKER, 1, -1) . '/',
                fn (array $m) => isset($this->passages[(int) $m[1]]) ? $m[0] : '',
                $answer
            );
            $patched[] = self::CITATION_OUT_OF_RANGE;
        }

        if (in_array(self::LINK_INVALID, $types, true)) {
            foreach ($this->invalidLinks($answer) as $link) {
                $answer = str_replace($link['markdown'], $link['text'], $answer);
            }
            $patched[] = self::LINK_INVALID;
        }

        foreach ($violations as $violation) {
            if ($violation['type'] === self::NUMBER_UNSUPPORTED && $violation['replacement'] !== null) {
                // Callback: a money replacement ("$1,211.48") must never be read as a
                // back-reference by preg_replace.
                $replacement = (string) $violation['replacement'];
                $answer = (string) preg_replace_callback(
                    '/(?<![\p{L}\p{N}_])' . preg_quote($violation['raw'], '/') . '(?![\p{L}\p{N}_])/u',
                    static fn (): string => $replacement,
                    $answer
                );
                $patched[] = self::NUMBER_UNSUPPORTED;
            }
        }

        if (in_array(self::SELF_REFERENCE_UNMARKED, $types, true) && $this->selfNeedle !== null) {
            $answer = $this->insertSelfReferenceMarker($answer);
            $patched[] = self::SELF_REFERENCE_UNMARKED;
        }

        if (in_array(self::TRUNCATION_UNDISCLOSED, $types, true) && $this->truncation !== null) {
            $answer = rtrim($answer) . "\n\n" . trans('ai.response.truncation_disclosure', $this->truncation, $this->locale);
            $patched[] = self::TRUNCATION_UNDISCLOSED;
        }

        return [$answer, array_values(array_unique($patched))];
    }

    // =========================================================================
    // The uncited closing paragraph of a document-grounded answer
    // =========================================================================

    /** Whether this turn's answer must cite its passages (a document-grounded turn). */
    public function requiresCitations(): bool
    {
        return $this->citationsRequired && $this->passages !== [];
    }

    /** Blocks that must carry a citation before the last paragraph for it to be a closing one. */
    private const CLOSING_MIN_CITED_BLOCKS = 2;

    /**
     * A word that can carry content of its own: at least this many letters (the function words
     * of the languages answered in are shorter). A length, never a vocabulary.
     */
    private const CONTENT_WORD_LETTERS = 6;

    /** A figure of the passages worth keeping a paragraph for holds at least this many digits (an article, an amount, a delay). */
    private const CLOSING_FIGURE_DIGITS = 2;

    /**
     * Share of a closing paragraph's content words that must be GROUNDED — found in the passages,
     * in the answer's own earlier cited blocks or in the question — for it to stay
     * ({@see closingReadsAsContent()}). Below it the paragraph is grounded in nothing and goes.
     *
     * MEASURED (docs/eval p20, every stored report): 147 distinct cited law answers, 68 of them
     * ending with an eligible uncited paragraph (67 distinct), each read and labelled. The share
     * of the 10 CONCLUSIONS ("In summary, whether you can cancel the contract depends on…", "In
     * summary, yes, you would be responsible…") runs from 4/11 = 0.36 to 0.75; the 24 platitudes
     * from 0.00 to 0.50; the 23 invitations from 0.00 to 0.71. The classes OVERLAP — a platitude
     * is written in the answer's own vocabulary too — so the threshold is set for the one error
     * that costs an answer its verdict (eval p20-A tear-down): just under the LOWEST conclusion.
     * At 0.35, replayed through the whole rule: all 10 conclusions and the 5 advice paragraphs
     * stay; 15 of 24 platitudes, 14 of 23 invitations and 2 of 5 pointers go — but with a margin
     * of ONE word of an eleven-word paragraph over the lowest conclusion. The two errors do not
     * weigh the same: a closer that survives is cosmetic, a conclusion that goes costs the
     * answer its verdict. So the threshold sits a clear step lower, at 0.30: paragraphs at
     * 1/3 (some platitudes and invitations) now stay, everything from 0.00 to 0.29 still goes.
     * The prompt also asks for the direct answer FIRST
     * ({@see \Condoedge\Ai\Services\ResponseSections\GuidelinesSection}), where position
     * protects it.
     */
    public const CLOSING_GROUNDED_SHARE = 0.30;

    /**
     * A document-grounded answer WITHOUT its UNGROUNDED closing paragraph (eval p16–p20: "These
     * rights and duties are designed to ensure a smooth collaboration…", "Understanding
     * these obligations will help you…", "Next steps are attached below…" — in different words
     * every run, against the prompt's own ban). The grounding policy requires every claim of such
     * an answer to carry its passage marker, so the rule is one of STRUCTURE, never of wording:
     *
     *   the LAST block of the answer is dropped when it is a plain paragraph (not a list, a
     *   heading, a table, a quote; no list line inside) that carries no citation marker and no
     *   link, while at least CLOSING_MIN_CITED_BLOCKS earlier blocks cite a passage — AND it is
     *   grounded in nothing ({@see closingReadsAsContent()}).
     *
     * The first paragraph can never qualify. A paragraph GROUNDED in the turn stays: it holds a
     * figure or an article number of the passages, a proper noun of the passages, a content word
     * the QUESTION asks about that no passage holds (the direct answer in the user's words —
     * "…the previous owner's unpaid invoices…" — or the honest "nothing here covers X"), or at
     * least CLOSING_GROUNDED_SHARE of its content words are the passages', the cited blocks' or
     * the question's. An uncited SUMMARY of the cited blocks is redundant but safe — on a yes/no
     * question it is the verdict (eval p20-A tear-down: the drop removed "In summary, you will
     * likely need to confirm whether the clause applies to you…", the only sentence shaped as
     * an answer). A platitude brings words of its own.
     *
     * Replayed over the 67 stored paragraphs: 31 dropped, 36 kept (the former passage-only test
     * dropped 63, among them 8 of the 10 conclusions) — see CLOSING_GROUNDED_SHARE for the table.
     *
     * For the knowledge path only — the caller applies it when {@see requiresCitations()} and
     * no data rows were returned.
     *
     * @return array{0: string, 1: ?string} [the answer, the paragraph dropped or null]
     */
    public function withoutUncitedClosing(string $answer, string $question = ''): array
    {
        $text = trim($answer);
        if ($this->passages === [] || $text === '') {
            return [$answer, null];
        }

        $blocks = array_values(array_filter(
            preg_split('/\R[ \t]*\R/u', $text, -1, PREG_SPLIT_OFFSET_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [],
            static fn (array $block): bool => trim($block[0]) !== ''
        ));
        if (count($blocks) < self::CLOSING_MIN_CITED_BLOCKS + 1) {
            return [$answer, null];
        }

        [$last, $offset] = $blocks[count($blocks) - 1];
        if (!self::isPlainParagraph($last) || $this->markers($last) !== [] || str_contains($last, '](') || str_contains($last, '{{')) {
            return [$answer, null];
        }

        $cited = [];
        foreach (array_slice($blocks, 0, -1) as [$block]) {
            if ($this->validMarkers($block) !== []) {
                $cited[] = $block;
            }
        }
        if (count($cited) < self::CLOSING_MIN_CITED_BLOCKS || $this->closingReadsAsContent($last, $question, implode("\n", $cited))) {
            return [$answer, null];
        }

        return [rtrim(substr($text, 0, $offset)), trim($last)];
    }

    /** A block of prose: no list / heading / table / quote / code line anywhere in it. */
    private static function isPlainParagraph(string $block): bool
    {
        foreach (preg_split('/\R/u', $block) ?: [] as $line) {
            if (preg_match('/^\s{0,3}(?:#{1,6}\s|[-*+•]\s|\d{1,3}[.)]\s|>|\||```)/u', $line) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether an uncited closing paragraph is GROUNDED in the turn (structure only —
     * {@see withoutUncitedClosing()}):
     *
     *   1. a FIGURE of the passages: a token holding CLOSING_FIGURE_DIGITS digits that a passage
     *      holds too (an article number, an amount, a delay);
     *   2. a PROPER NOUN of the passages: a capitalized word that does not open a sentence and
     *      that a passage writes the same way;
     *   3. GROUNDED WORDS: at least CLOSING_GROUNDED_SHARE of its content words are found in the
     *      passages, in the answer's earlier CITED blocks ($body — what a summary restates) or in
     *      the question;
     *   4. a content word of the QUESTION that no passage holds.
     *
     * @param string $body the earlier blocks of the answer that cite a passage
     */
    private function closingReadsAsContent(string $paragraph, string $question, string $body = ''): bool
    {
        $passages = implode("\n", $this->passages);
        $inPassages = array_flip(self::foldedWords($passages));
        $inQuestion = array_flip(self::foldedWords($question));
        $inBody = array_flip(self::foldedWords($body));

        $content = 0;
        $shared = 0;
        foreach (self::foldedWords($paragraph) as $word) {
            if (preg_match_all('/\d/', $word) >= self::CLOSING_FIGURE_DIGITS) {
                if (isset($inPassages[$word])) {
                    return true;
                }
                continue;
            }
            if (preg_match('/\d/', $word) === 1 || mb_strlen((string) preg_replace('/[^\p{L}]+/u', '', $word)) < self::CONTENT_WORD_LETTERS) {
                continue;
            }
            if (isset($inQuestion[$word]) && !isset($inPassages[$word])) {
                return true;
            }
            $content++;
            $shared += isset($inPassages[$word]) || isset($inBody[$word]) || isset($inQuestion[$word]) ? 1 : 0;
        }
        if ($content > 0 && $shared / $content >= self::CLOSING_GROUNDED_SHARE) {
            return true;
        }

        foreach ($this->segments($paragraph) as [$sentence]) {
            $words = preg_split('/\s+/u', trim($sentence), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach (array_slice($words, 1) as $word) {
                $word = (string) preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $word);
                if (preg_match('/^\p{Lu}[\p{L}\p{N}\-]{2,}$/u', $word) === 1
                    && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/u', $passages) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The distinct folded words of a text ({@see AliasNormalizer}: case, accents, plural mark),
     * markdown emphasis removed, figures kept whole.
     *
     * @return list<string>
     */
    private static function foldedWords(string $text): array
    {
        return array_values(array_unique(AliasNormalizer::tokens(str_replace(['*', '`', '\\', '#'], '', $text))));
    }

    // =========================================================================
    // The ONE combined retry
    // =========================================================================

    /**
     * The regeneration instruction listing EVERY violation with concrete fixes
     * (the real passage numbers, the real figures, the real ids). The quoted draft
     * never carries "[N]" placeholders — they are normalized/stripped first, so the
     * model is never shown the broken grammar again.
     */
    public function retryInstruction(array $violations, string $draft): string
    {
        $types = self::types($violations);
        $refs = $this->citeChoices();
        $rules = [];

        if (in_array(self::CITATION_MISSING, $types, true)) {
            // Register-neutral wording: the corpus may be legal, medical, HR or technical.
            $rules[] = "CITATION ENFORCEMENT: your draft asserted document content without citations. Cite the "
                . "provided file content with its passage number in square brackets — {$refs} — for EVERY claim "
                . "drawn from the documents (rules, regulations, policies, or any other document contents). If the "
                . "documents provided do not actually contain the answer, state plainly that the documents provided "
                . "do not contain the answer — never answer such a question from general knowledge, and do NOT "
                . "restate the draft's uncited claims.";
        }
        if (array_intersect([self::CITATION_PLACEHOLDER, self::CITATION_OUT_OF_RANGE], $types) !== []) {
            $rules[] = "CITATION FORMAT: cite ONLY with the real passage numbers {$refs}. Never write the letter N "
                . 'or any number that is not one of those passages.';
        }
        foreach ($violations as $v) {
            if ($v['type'] === self::CITATION_ARTICLE_MISMATCH) {
                $rules[] = "CITATION ACCURACY: {$v['detail']}. Cite each article with the passage that actually "
                    . 'contains it, and state only what that passage says.';
            }
        }

        $numbers = array_values(array_filter($violations, static fn (array $v) => $v['type'] === self::NUMBER_UNSUPPORTED));
        if ($numbers !== []) {
            $raw = implode(', ', array_map(static fn (array $v) => $v['raw'], $numbers));
            $known = $this->keyFigures !== []
                ? ' Use only figures given to you — e.g. ' . implode(', ', array_map(fn (float $f) => $this->figure($f), array_slice($this->keyFigures, 0, 12))) . '.'
                : ' Use only figures that appear in the data or the documents.';
            $rules[] = "NUMBERS: {$raw} " . (count($numbers) === 1 ? 'does' : 'do') . ' not appear in the data or the '
                . "documents.{$known} Never add, subtract or re-derive figures yourself.";
        }

        $links = array_values(array_filter($violations, static fn (array $v) => $v['type'] === self::LINK_INVALID));
        if ($links !== []) {
            $lines = [];
            foreach ($links as $link) {
                $allowed = $link['label'] !== null && $this->entityIds !== null ? array_slice($this->entityIds->idsFor($link['label']), 0, 10) : [];
                $lines[] = $link['reason'] . ($link['label'] !== null
                    ? ($allowed !== [] ? " (valid {$link['label']} ids: " . implode(', ', $allowed) . ')' : " (no {$link['label']} ids exist in the result)")
                    : '');
            }
            $rules[] = 'LINKS: link ids must come from the rows of the SAME entity type — ' . implode('; ', $lines)
                . '. Use only those ids, or drop the link.';
        }

        if (in_array(self::TRUNCATION_UNDISCLOSED, $types, true) && $this->truncation !== null) {
            ['total' => $total, 'shown' => $shown] = $this->truncation;
            $remainder = $total - $shown;
            $rules[] = "RESULT COMPLETENESS: the data shown to you contains only the first {$shown} of {$total} matching "
                . "rows. State the numbers EXACTLY: the total is {$total} — write that exact number, never an "
                . "approximation like 'more than {$total}' or 'over {$total}' — followed by the noun for what the rows "
                . "in the data actually are (their entity), not the word 'results'. ALSO state either that {$shown} of "
                . "them are shown or that {$remainder} more exist. Never present the visible rows as the complete list.";
        }

        if (in_array(self::SELF_REFERENCE_UNMARKED, $types, true) && $this->selfNeedle !== null) {
            $marker = SelfReferenceNoticeSection::THATS_YOU_MARKERS[0];
            $rules[] = "SELF-REFERENCE: the email '{$this->selfNeedle}' IS the current user's own email address — it "
                . "belongs to the very person you are answering. Every time you mention '{$this->selfNeedle}', "
                . "acknowledge on the same line that it is the user's own (e.g. \"{$marker}\"), and NEVER present it "
                . "as contact information, as \"how to reach\" anyone, or as belonging to someone else.";
        }

        $numbered = [];
        foreach ($rules as $i => $rule) {
            $numbered[] = ($i + 1) . '. ' . $rule;
        }

        return "\n\nIMPORTANT — ANSWER VALIDATION (this is a one-time regeneration). Your previous draft broke "
            . "these rules; re-answer the question fixing ALL of them:\n"
            . implode("\n", $numbered) . "\n"
            . "Your previous draft:\n"
            . $this->normalizePlaceholders($draft);
    }

    // =========================================================================
    // Citation helpers
    // =========================================================================

    /** "[N] 2" / "([N] 2)" → "[2]" when passage 2 exists; every other "[N]" form removed. */
    public function normalizePlaceholders(string $text): string
    {
        $resolve = fn (array $m): string => isset($this->passages[(int) $m[1]]) ? '[' . (int) $m[1] . ']' : '';
        $text = (string) preg_replace_callback('/\(\s*\[\s*N\s*\]\s*(\d{1,3})\s*\)/i', $resolve, $text);
        $text = (string) preg_replace_callback('/\[\s*N\s*\]\s*(\d{1,3})(?!\d|[.,]\d)/i', $resolve, $text);
        $text = (string) preg_replace('/\s*\(\s*\[\s*N\s*\]\s*\)/i', '', $text);
        $text = (string) preg_replace('/\s*\[\s*N\s*\]/i', '', $text);

        return $text;
    }

    /**
     * Annotated citations → the standard marker. The model sometimes cites as
     * "[1, art. 11]" or "[2; arts. 12–14]" (eval p16-B: a fully cited answer was
     * judged marker-less, retried, and shipped with no source links). The annotation is
     * kept for the reader: "(art. 11) [1]"; a list of markers "[1, 2]" becomes "[1][2]".
     * Only brackets that START with an existing passage number are touched.
     */
    public function normalizeAnnotatedCitations(string $text): string
    {
        return (string) preg_replace_callback(
            '/\[\s*(\d{1,3})\s*[,;:]\s*([^\[\]\n]{1,80}?)\s*\](?!\()/u',
            function (array $m): string {
                if (!isset($this->passages[(int) $m[1]])) {
                    return $m[0];
                }
                $rest = trim($m[2]);
                if (preg_match('/^\d{1,3}(?:\s*[,;]\s*\d{1,3})*$/', $rest) === 1) {
                    $numbers = array_merge([(int) $m[1]], array_map('intval', preg_split('/\s*[,;]\s*/', $rest) ?: []));

                    return implode('', array_map(static fn (int $n): string => "[{$n}]", $numbers));
                }

                return '(' . $rest . ') [' . (int) $m[1] . ']';
            },
            $text
        );
    }

    /** @return list<int> */
    private function markers(string $text): array
    {
        preg_match_all(self::MARKER, $text, $m);

        return array_map('intval', $m[1] ?? []);
    }

    /** @return list<int> */
    private function validMarkers(string $text): array
    {
        return array_values(array_filter($this->markers($text), fn (int $ref) => isset($this->passages[$ref])));
    }

    /**
     * Sentences that cite a passage AND name an article number the cited
     * passage(s) do not contain.
     *
     * @return list<array{article: string, cited: list<int>, found_in: list<int>, offset: int, length: int}>
     */
    private function articleMismatches(string $text): array
    {
        $out = [];
        $segments = $this->segments($text);
        $ownMarkers = array_map(
            fn (array $s) => array_values(array_unique(array_filter($this->markers($s[0]), fn (int $ref) => isset($this->passages[$ref])))),
            $segments
        );
        foreach ($segments as $i => [$segment, $offset, $paragraph]) {
            if (!preg_match_all(self::ARTICLE, $segment, $articles)) {
                continue;
            }
            // An unmarked article sentence answers to the NEXT marked sentence of
            // its paragraph (the citation often closes the following sentence).
            $cited = $ownMarkers[$i];
            $inherited = false;
            for ($j = $i + 1; $cited === [] && isset($segments[$j]) && $segments[$j][2] === $paragraph; $j++) {
                [$cited, $inherited] = [$ownMarkers[$j], true];
            }
            if ($cited === []) {
                continue;
            }

            foreach (array_unique($articles[1]) as $article) {
                // Detection is loose: a cited passage that merely CONTAINS the number
                // (or covers it by its chunk metadata) is fine.
                $inCited = array_filter($cited, fn (int $ref) => $this->passageHasArticle($ref, $article)
                    || in_array((int) $article, $this->passageArticles[$ref] ?? [], true));
                if ($inCited !== []) {
                    continue;
                }
                // M3: a cited continuation chunk with NO known articles (no line-start
                // number, no metadata) may well hold the article's text — never flag.
                if (array_filter($cited, fn (int $ref) => ($this->passageArticles[$ref] ?? []) === []) !== []) {
                    continue;
                }
                $out[] = [
                    'article' => $article,
                    'cited' => $cited,
                    'found_in' => array_values(array_filter(
                        array_keys($this->passages),
                        fn (int $ref) => $this->passageHasArticle($ref, $article)
                    )),
                    // The only legitimate move targets: passages that DEFINE it (M1).
                    'defined_in' => array_values(array_filter(
                        array_keys($this->passages),
                        fn (int $ref) => in_array((int) $article, $this->passageArticles[$ref] ?? [], true)
                    )),
                    'offset' => $offset,
                    'length' => strlen($segment),
                    'inherited' => $inherited,
                ];
            }
        }

        return $out;
    }

    /**
     * Move a sentence's single marker to the ONE passage that DEFINES the
     * article it names (a cross-reference mention is never a target).
     * Ambiguous cases are left for the record.
     *
     * @return array{0: string, 1: bool}
     */
    private function remapArticleMarkers(string $text): array
    {
        $moved = false;
        foreach (array_reverse($this->articleMismatches($text)) as $mismatch) {
            if (count($mismatch['defined_in']) !== 1 || count($mismatch['cited']) !== 1 || $mismatch['inherited']) {
                continue;
            }
            $segment = substr($text, $mismatch['offset'], $mismatch['length']);
            $fixed = str_replace('[' . $mismatch['cited'][0] . ']', '[' . $mismatch['defined_in'][0] . ']', $segment);
            $text = substr_replace($text, $fixed, $mismatch['offset'], $mismatch['length']);
            $moved = true;
        }

        return [$text, $moved];
    }

    private function passageHasArticle(int $ref, string $article): bool
    {
        return preg_match('/(?<![\d.,])' . preg_quote($article, '/') . '(?!\d)/', $this->passages[$ref] ?? '') === 1;
    }

    /**
     * Sentence-ish segments with byte offsets and their paragraph index: blank
     * lines separate paragraphs; line breaks and sentence ends followed by an
     * uppercase/markup start separate segments ("art. 12" never splits — a digit
     * follows its dot).
     *
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private function segments(string $text): array
    {
        $out = [];
        $paragraphs = preg_split('/\R\s*\R/u', $text, -1, PREG_SPLIT_OFFSET_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [[$text, 0]];
        foreach ($paragraphs as $p => [$paragraph, $base]) {
            $parts = preg_split('/\R+|(?<=[.!?])\s+(?=[\p{Lu}*#\-])/u', $paragraph, -1, PREG_SPLIT_OFFSET_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [[$paragraph, 0]];
            foreach ($parts as [$segment, $offset]) {
                $out[] = [$segment, $base + $offset, $p];
            }
        }

        return $out;
    }

    private function refList(array $refs): string
    {
        sort($refs);

        return implode(', ', array_map(static fn ($r) => '[' . $r . ']', $refs));
    }

    /** "[1], [2] or [3]" — the real citation choices, named in every instruction. */
    private function citeChoices(): string
    {
        return self::choices(count($this->passages));
    }

    /** "[1]", "[1] or [2]", "[1], [2] or [3]" … (shared with the prompt sections). */
    public static function choices(int $count): string
    {
        if ($count <= 0) {
            return '[1]';
        }
        $refs = array_map(static fn (int $i) => '[' . $i . ']', range(1, $count));
        if (count($refs) === 1) {
            return $refs[0];
        }
        $last = array_pop($refs);

        return implode(', ', $refs) . ' or ' . $last;
    }

    // =========================================================================
    // Number helpers
    // =========================================================================

    /**
     * The answer text with everything that is not a stated quantity blanked:
     * citation markers, PII tokens, link targets, URLs, the table placeholder.
     */
    private function numberText(string $answer): string
    {
        $answer = (string) preg_replace('/\]\([^)]*\)/', ']', $answer);
        $answer = (string) preg_replace('/https?:\/\/\S+|(?:entity|action):\/\/\S+/i', ' ', $answer);
        $answer = (string) preg_replace('/\[[\w-]+#\d+\]/u', ' ', $answer);
        $answer = (string) preg_replace(self::MARKER, ' ', $answer);
        $answer = (string) preg_replace('/\[\s*N\s*\]\s*\d{0,3}/i', ' ', $answer);

        return (string) preg_replace('/\{\{\s*table\s*\}\}/i', ' ', $answer);
    }

    /** Day/date-sized integers and years are never checked (structural magnitude rule). */
    private function ignorable(array $quantity): bool
    {
        if ($quantity['decimals'] > 0) {
            return false;
        }
        $max = max(array_map('abs', $quantity['values']));
        if ($max <= 31) {
            return true;
        }

        return !$quantity['money'] && preg_match('/^\d{4}$/', $quantity['raw']) === 1 && $max >= 1900 && $max <= 2100;
    }

    /**
     * The known grand total that replaces an unsupported figure — ONLY when the
     * figure is a slip of that total: within formatting/rounding distance
     * (≤ 0.5%) or a two-digit transposition, money or decimal, and the plan knows
     * exactly one grand total. A legitimately derived figure (subset sum,
     * difference, annualized amount, another column's sum) is NEVER overwritten:
     * one whose gap to the total equals a money row is a subset sum and is
     * recorded; anything farther goes to the single retry and is recorded (I1).
     */
    private function totalReplacement(array $quantity): ?string
    {
        if (count($this->grandTotals) !== 1 || (!$quantity['money'] && $quantity['decimals'] === 0)) {
            return null;
        }

        $total = $this->grandTotals[0];
        if (abs($total) < 0.005) {
            return null;
        }

        $slip = false;
        foreach ($quantity['values'] as $value) {
            $gap = abs(abs($value) - abs($total));
            $isSubsetSum = array_filter($this->moneyRowFigures, static fn (float $row) => abs(abs($row) - $gap) < 0.005) !== [];
            if (!$isSubsetSum && ($gap / abs($total) <= self::TOTAL_SLIP || self::isTransposition($value, $total))) {
                $slip = true;
                break;
            }
        }
        if (!$slip) {
            return null;
        }
        // Keep the answer's own typography: decimal comma ⇒ space-grouped French form.
        $french = preg_match('/,\d{1,2}$/', $quantity['raw']) === 1;

        return $french
            ? number_format($total, 2, ',', "\u{00A0}")
            : number_format($total, 2, '.', ',');
    }

    /** Two ADJACENT digits swapped ("11,943.17" for 11,493.17), compared at cent precision. */
    private static function isTransposition(float $stated, float $total): bool
    {
        $a = str_replace('.', '', number_format(abs($stated), 2, '.', ''));
        $b = str_replace('.', '', number_format(abs($total), 2, '.', ''));
        if (strlen($a) !== strlen($b) || $a === $b) {
            return false;
        }

        $diff = [];
        for ($i = 0, $n = strlen($a); $i < $n; $i++) {
            if ($a[$i] !== $b[$i]) {
                $diff[] = $i;
            }
        }

        return count($diff) === 2 && $diff[1] === $diff[0] + 1
            && $a[$diff[0]] === $b[$diff[1]] && $a[$diff[1]] === $b[$diff[0]];
    }

    private function figure(float $value): string
    {
        return abs($value - round($value)) < 0.005
            ? number_format($value, 0, '.', ',')
            : number_format($value, 2, '.', ',');
    }

    // =========================================================================
    // Link helpers
    // =========================================================================

    /**
     * @return list<array{markdown: string, text: string, label: ?string, id: string, reason: string}>
     */
    private function invalidLinks(string $answer): array
    {
        if ($this->entityIds === null || !preg_match_all(self::MARKDOWN_LINK, $answer, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $invalid = [];
        foreach ($matches as [$markdown, $text, $scheme, $target]) {
            [$path, $query] = array_pad(explode('?', $target, 2), 2, '');

            if (strtolower($scheme) === 'entity') {
                $parts = explode('/', $path);
                $label = $parts[0] ?? '';
                $id = $parts[1] ?? '';
                if ($label !== '' && $id !== '' && $this->entityIds->has($label, $id)) {
                    continue;
                }
                $invalid[] = [
                    'markdown' => $markdown,
                    'text' => $text,
                    'label' => $label !== '' ? $label : null,
                    'id' => $id,
                    'reason' => preg_match('/^\d+$/', $id) === 1
                        ? "entity://{$label}/{$id} — {$id} is not a {$label} id in the result"
                        : "entity://{$label}/{$id} — '{$id}' is not a real id",
                ];
                continue;
            }

            parse_str($query, $params);
            // I3: a parameter is checked only when its `<label>_id` prefix names a
            // label the index actually holds (rows or the seeded identity ids); a
            // bare `id`, a create-form default or an unknown label is left alone.
            foreach ($params as $key => $value) {
                $prefix = EntityIdIndex::idColumnPrefix((string) $key);
                if (!is_string($value) || $prefix === null || !$this->entityIds->holdsLabel($prefix)
                    || $this->entityIds->has($prefix, $value)) {
                    continue;
                }
                $invalid[] = [
                    'markdown' => $markdown,
                    'text' => $text,
                    'label' => $prefix,
                    'id' => $value,
                    'reason' => "action://{$path} — {$key}={$value} is not a {$prefix} id in the result",
                ];
                break;
            }
        }

        return $invalid;
    }

    // =========================================================================
    // Self-reference helpers (ported byte-compatible from ResponseGenerator)
    // =========================================================================

    /**
     * Adjacency = SAME LINE: every line carrying the user's own email must also
     * carry a "that's-you" marker (SelfReferenceNoticeSection::markerPhrases —
     * the same source the prompt section instructs the model with). A marker
     * elsewhere does NOT excuse an unmarked contact-style line. Comparison runs on
     * the typographically normalized line, so "that’s you" (U+2019) counts.
     */
    private function hasUnmarkedEmailLine(string $answer): bool
    {
        foreach (preg_split('/\R/', $answer) ?: [] as $line) {
            if ($this->isUnmarkedEmailLine($line)) {
                return true;
            }
        }

        return false;
    }

    private function isUnmarkedEmailLine(string $line): bool
    {
        if ($this->selfNeedle === null || mb_stripos($line, $this->selfNeedle) === false) {
            return false;
        }

        $lineLower = SelfReferenceNoticeSection::normalizeMarkerText($line);
        foreach (SelfReferenceNoticeSection::markerPhrases($this->locale) as $marker) {
            if ($marker !== '' && mb_strpos($lineLower, $marker) !== false) {
                return false;
            }
        }

        return true;
    }

    /** Insert the localized "(that's you — …)" marker after the FIRST unmarked occurrence. */
    private function insertSelfReferenceMarker(string $answer): string
    {
        $patch = ' (' . trans('ai.response.self_reference_patch', [], $this->locale) . ')';

        $parts = preg_split('/(\R)/', $answer, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$answer];
        foreach ($parts as $i => $line) {
            if ($i % 2 === 1 || !$this->isUnmarkedEmailLine($line)) {
                continue; // newline separators / clean lines
            }

            $end = mb_stripos($line, (string) $this->selfNeedle) + mb_strlen((string) $this->selfNeedle);
            $parts[$i] = mb_substr($line, 0, $end) . $patch . mb_substr($line, $end);

            return implode('', $parts);
        }

        return $answer;
    }

    // =========================================================================

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function violation(string $type, string $detail, bool $fixable, array $extra = []): array
    {
        return ['type' => $type, 'detail' => $detail, 'fixable_deterministically' => $fixable] + $extra;
    }
}
