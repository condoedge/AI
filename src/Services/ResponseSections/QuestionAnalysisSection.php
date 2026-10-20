<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Support\DocumentScopeLabel;

/**
 * QuestionAnalysisSection — tells the composer which rules GOVERN the question.
 *
 * Rendered only on a document turn whose map reading named a governing rule the
 * plain search had not found ({@see \Condoedge\Ai\Services\Retrieval\DocumentMapTurn},
 * gate open): `fileContext['analysis']` then holds, per governing rule, its role,
 * the article numbers of the chunk that states it and the number of the passage
 * that holds it. Every other turn renders nothing — zero tokens.
 *
 * Why a block beside the passage order: the rule a concrete question falls under
 * often shares no word with it, so nothing in the passage itself tells the
 * composer that THIS one is the general rule and that one says who decides. The
 * block says it, and asks for the answer to be built on those rules in that
 * order.
 *
 * Why ONLY the governing rules (general rule, classification, approval): the
 * reader's conditions, exceptions and consequences are proposed from one-line
 * gists, and listed here as rules to apply they produced misapplied rules
 * (measured). Their passages still reach the composer — they are just not
 * announced as governing.
 *
 * DATA / INSTRUCTION SEPARATION. This block sits OUTSIDE the untrusted-data
 * fence of the passages and is worded as an instruction, so nothing a model
 * wrote and nothing a document says may be printed in it. It is rendered by code
 * from three closed things only:
 *  - the role wording below (a constant, chosen by an enum value);
 *  - article NUMBERS (digits and dots, checked here whatever the caller sent);
 *  - a passage number that exists this turn.
 * The reader's one-sentence "issue" is NOT printed (free model text written from
 * document-derived gists; it stays in the trace), nor is a heading of the
 * document: a chunk without an article number is announced by its role and its
 * passage number alone.
 *
 * Priority: 44 — right before the passages (FileContextSection, 45), after the
 * question (30). There is no switch of its own: the block is the reason the
 * reader runs at all (the reader as retrieval only measured WORSE than the
 * probe + selection path), so `ai.file_context.document_map.reader.enabled`
 * turns both off together.
 */
class QuestionAnalysisSection extends BaseResponseSection
{
    protected string $name = 'question_analysis';
    protected int $priority = 44;

    /** What each governing role is called in the block (role => wording), in answer order. */
    public const ROLE_TEXT = [
        'general_rule' => 'the general rule',
        'classification' => 'what the thing concerned is / which category it falls in',
        'procedure_or_approval' => 'who approves or decides, and how',
    ];

    /** Styles that ask for just the answer; a "cite its passage" instruction contradicts them. */
    private const TERSE_STYLES = ['minimal', 'concise'];

    /** An article number: digits, optionally dotted ("1064.1"). Nothing else is ever printed as a locator. */
    private const ARTICLE_NUMBER = '/^\d{1,6}(\.\d{1,3}){0,2}$/';

    /** Up to this many article numbers are listed; a longer run is printed first–last. */
    private const LISTED_ARTICLES = 4;

    public function shouldInclude(PipelineContext $context, array $options = []): bool
    {
        return $this->rules($context) !== [];
    }

    public function format(PipelineContext $context, array $options = []): string
    {
        $rules = $this->rules($context);
        if ($rules === []) {
            return '';
        }

        $analysis = $context->fileContext['analysis'];

        // DOCUMENTS OF DIFFERENT SCOPES (a record's own document beside a general
        // one): every rule line says WHICH document its rule comes from — the words
        // of the passage headers, rendered by code from the passage the rule points
        // at ({@see DocumentScopeLabel}), never from the analysis. Measured: without
        // it the general law was presented as the record's own document. With one
        // scope the block is, byte for byte, the one measured on a single corpus.
        $files = array_values($context->fileContext['relevant_files']);
        $severalScopes = count(DocumentScopeLabel::among($files)) > 1;
        $ruleScopes = [];

        $output = $this->header('QUESTION ANALYSIS');
        $output .= "The rules that govern the question, in order:\n";
        foreach ($rules as $number => $rule) {
            $scope = $severalScopes && is_array($files[$rule['passage'] - 1] ?? null)
                ? DocumentScopeLabel::of($files[$rule['passage'] - 1])
                : null;
            if ($scope !== null) {
                $ruleScopes[$scope['key']] = true;
            }
            $output .= ($number + 1) . '. ' . self::ROLE_TEXT[$rule['role']]
                . ($rule['articles'] !== [] ? ' — art. ' . $this->articleList($rule['articles']) : '')
                . ", in passage [{$rule['passage']}]"
                . ($scope !== null ? ' — ' . $scope['text'] : '') . "\n";
        }

        // The measured instruction, verbatim. Only its citation clause yields to a
        // terse style, whose own contract forbids inline markers (FileContextSection).
        $output .="\nBuild the answer on these rules, in this order: state each one with its own conditions, "
            . 'as its passage words them' . ($this->isTerseStyle($options) ? '.' : ', and cite its passage.');
        if ($ruleScopes !== []) {
            // Structural: whose document a rule is. Which of two documents prevails
            // is for the documents to say, never for this block.
            $output .= ' These rules come from documents of DIFFERENT SCOPES: state each rule as a rule of the document '
                . 'named on its line, never as a rule of another document.';
            if (count($ruleScopes) > 1) {
                $output .= ' When two of these documents each give a rule on the same matter, say what each one provides.';
            }
        }
        if (($analysis['has_classification'] ?? false) === true) {
            $output .= ' When the answer turns on which category the thing concerned falls in, '
                . 'say what decides the category instead of giving a flat yes or no.';
        }

        return $output . "\n";
    }

    /**
     * The rules to print, in the order given: those of a governing role that point
     * at a passage this turn really has, each with the article numbers that pass
     * {@see self::ARTICLE_NUMBER}. [] when the turn has no (content) passage or no
     * analysis.
     *
     * @return list<array{role: string, articles: list<string>, passage: int}>
     */
    private function rules(PipelineContext $context): array
    {
        $analysis = $context->fileContext['analysis'] ?? null;
        $files = $context->fileContext['relevant_files'] ?? [];
        if (!is_array($analysis) || !is_array($analysis['rules'] ?? null)
            || !is_array($files) || $files === []
            || !empty($context->fileContext['enumeration'])) {
            return [];
        }

        $rules = [];
        foreach ($analysis['rules'] as $rule) {
            $role = is_array($rule) ? ($rule['role'] ?? null) : null;
            $passage = is_array($rule) ? ($rule['passage'] ?? null) : null;
            if (!is_string($role) || !isset(self::ROLE_TEXT[$role])
                || !is_int($passage) || $passage < 1 || $passage > count($files)) {
                continue;
            }
            $rules[] = [
                'role' => $role,
                'articles' => $this->articleNumbers($rule['articles'] ?? null),
                'passage' => $passage,
            ];
        }

        return $rules;
    }

    /**
     * The article numbers among what the caller sent: strings (or integers) that
     * ARE a number, each once, in the order given. Anything else is dropped — a
     * locator is never free text.
     *
     * @return list<string>
     */
    private function articleNumbers(mixed $articles): array
    {
        $numbers = [];
        foreach (is_array($articles) ? $articles : [] as $article) {
            $article = is_int($article) ? (string) $article : $article;
            if (is_string($article) && preg_match(self::ARTICLE_NUMBER, $article) === 1 && !in_array($article, $numbers, true)) {
                $numbers[] = $article;
            }
        }

        return $numbers;
    }

    /**
     * "1063" · "1064.1, 1065" · a run of five or more as "1096–1100" (the wording of
     * the passage headers, FileContextSection).
     *
     * @param list<string> $articles
     */
    private function articleList(array $articles): string
    {
        return count($articles) <= self::LISTED_ARTICLES
            ? implode(', ', $articles)
            : $articles[0] . '–' . $articles[count($articles) - 1];
    }

    /** Whether the requested style wants just the answer (no inline citations). */
    private function isTerseStyle(array $options): bool
    {
        $style = $options['style'] ?? config('ai.response_generation.default_style', 'friendly');

        return in_array($style, self::TERSE_STYLES, true);
    }
}
