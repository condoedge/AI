<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Query;

use Condoedge\Ai\Exceptions\QueryGenerationException;
use Condoedge\Ai\Services\Security\CypherLexer;
use Condoedge\Ai\Services\Security\Token;

/**
 * A domain calculation (ai.calculations) run as the host wrote it.
 *
 * The host's query is verified against its screens, and it can be long. Having the model retype it
 * to fill in a name lost variables on the way ("Variable `b` not defined", measured 2026-10-30), and
 * the repair retyped it again. So the model names the calculation and gives the values, and code
 * fills them in:
 *
 *   'cypher' => '... WHERE toLower(un.name) CONTAINS toLower("<UNION NAME?>") AND left(b.at, 4) = "<YEAR>" ...',
 *   'variants' => ['per_year' => ['when' => 'asked per year', 'cypher' => '...']],
 *
 * A placeholder is <UPPERCASE WORDS>. Inside quotes it takes any text, escaped here; bare, only a
 * number. Ending in ? it may be left out: its condition goes, when that can be proven safe. Several
 * values for one placeholder repeat the parenthesized OR group holding it.
 *
 * Anything else is refused with a reason the model can act on, in the generator's redraft loop. A
 * filled query then takes the same path as a written one: scope, schema check, execution.
 */
final class CalculationQuery
{
    private const PLACEHOLDER = '/(["\']?)<([A-Z][A-Z0-9 \-]*\??)>\1/';

    private const CLAUSE_HEADS = ['MATCH', 'OPTIONAL', 'WITH', 'RETURN', 'UNWIND', 'CALL', 'ORDER', 'SKIP', 'LIMIT', 'UNION', 'CREATE', 'MERGE', 'SET', 'DELETE', 'REMOVE', 'FOREACH'];

    private const MAX_VALUE_LENGTH = 200;

    private function __construct(private readonly string $key, private readonly string $cypher)
    {
    }

    /**
     * The calculation or variant a reply names ("key" or "key.variant"). The allowed keys are the
     * ones this turn's prompt offered: null means any the host configured.
     *
     * @param list<string>|null $allowed
     */
    public static function named(string $name, ?array $allowed = null): self
    {
        [$key, $variant] = array_pad(explode('.', trim($name), 2), 2, null);
        $calculation = (array) config('ai.calculations', []);
        $entry = $calculation[$key] ?? null;

        if (!is_array($entry) || ($allowed !== null && !in_array($key, $allowed, true))) {
            throw new QueryGenerationException("\"{$name}\" is not one of the calculations of this prompt. Use one of them by its key, or write the query.");
        }

        $cypher = $variant === null ? ($entry['cypher'] ?? null) : ($entry['variants'][$variant]['cypher'] ?? null);
        if (!is_string($cypher) || trim($cypher) === '') {
            throw new QueryGenerationException("The calculation \"{$name}\" has no query to run. Use another one by its key, or write the query.");
        }

        return new self(trim($name), $cypher);
    }

    /** @return list<string> the placeholders of the query, as written (a trailing ? marks an optional one) */
    public function placeholders(): array
    {
        preg_match_all(self::PLACEHOLDER, $this->cypher, $found);

        return array_values(array_unique($found[2]));
    }

    /**
     * The query with these values in place of its placeholders.
     *
     * @param array<string, mixed> $values placeholder => text, number, or a list of them
     * @throws QueryGenerationException with what the model should do instead
     */
    public function fill(array $values): string
    {
        $values = $this->normalized($values);
        $cypher = $this->cypher;

        foreach ($this->placeholders() as $placeholder) {
            $name = rtrim($placeholder, '?');
            $given = $values[$name] ?? [];

            if ($given === []) {
                if (!str_ends_with($placeholder, '?')) {
                    throw new QueryGenerationException("The calculation \"{$this->key}\" needs a value for <{$name}>. Give it in \"values\", use another one, or write the query.");
                }
                $cypher = $this->withoutCondition($cypher, $placeholder);
                continue;
            }

            $cypher = count($given) === 1
                ? $this->withValue($cypher, $placeholder, $given[0])
                : $this->withValues($cypher, $placeholder, $given);
        }

        return $cypher;
    }

    /** @return array<string, list<string>> */
    private function normalized(array $values): array
    {
        $known = array_map(static fn (string $placeholder): string => rtrim($placeholder, '?'), $this->placeholders());
        $normalized = [];

        foreach ($values as $name => $value) {
            $name = strtoupper(trim(trim((string) $name), '<>? '));
            $list = array_values(array_filter(
                array_map(static fn (mixed $item): string => is_scalar($item) ? self::clean((string) $item) : '', is_array($value) ? $value : [$value]),
                static fn (string $item): bool => $item !== ''
            ));
            if ($list === []) {
                continue;
            }
            if (!in_array($name, $known, true)) {
                throw new QueryGenerationException("The calculation \"{$this->key}\" has no <{$name}>: its placeholders are <" . implode('>, <', $known) . '>. Use another one, or write the query.');
            }
            $normalized[$name] = $list;
        }

        return $normalized;
    }

    private static function clean(string $value): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)), 0, self::MAX_VALUE_LENGTH);
    }

    private function withValue(string $cypher, string $placeholder, string $value): string
    {
        return (string) preg_replace_callback(
            '/(["\']?)<' . preg_quote($placeholder, '/') . '>\1/',
            fn (array $found): string => $this->literal($found[1], $placeholder, $value),
            $cypher
        );
    }

    /** One value per copy of the OR group holding the placeholder: every copy reads the same way. */
    private function withValues(string $cypher, string $placeholder, array $values): string
    {
        $pattern = '/(["\']?)<' . preg_quote($placeholder, '/') . '>\1/';
        [$open, $close] = $this->enclosingGroup($cypher, $placeholder);

        $group = substr($cypher, $open + 1, $close - $open - 1);
        if (preg_match_all($pattern, $cypher) !== preg_match_all($pattern, $group)) {
            throw new QueryGenerationException("The calculation \"{$this->key}\" takes one value for <" . rtrim($placeholder, '?') . '>.');
        }

        $copies = array_map(
            fn (string $value): string => (string) preg_replace_callback(
                $pattern,
                fn (array $found): string => $this->literal($found[1], $placeholder, $value),
                $group
            ),
            $values
        );

        return substr($cypher, 0, $open + 1) . implode(' OR ', array_map('trim', $copies)) . substr($cypher, $close);
    }

    private function literal(string $quote, string $placeholder, string $value): string
    {
        if ($quote === '') {
            if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) !== 1) {
                throw new QueryGenerationException('<' . rtrim($placeholder, '?') . "> of the calculation \"{$this->key}\" takes a number, not \"{$value}\". Use another one, or write the query.");
            }

            return $value;
        }

        return $quote . str_replace(['\\', $quote], ['\\\\', '\\' . $quote], $value) . $quote;
    }

    /** @return array{0: int, 1: int} the offsets of the parentheses around the placeholder */
    private function enclosingGroup(string $cypher, string $placeholder): array
    {
        $tokens = (new CypherLexer())->tokenize($cypher)['tokens'];
        $offset = strpos($cypher, '<' . $placeholder . '>');

        $index = null;
        foreach ($tokens as $i => $token) {
            if ($token->offset > $offset) {
                break;
            }
            $index = $i;
        }

        $open = null;
        $level = 0;
        for ($i = (int) $index; $i >= 0; $i--) {
            $value = $tokens[$i]->value;
            if ($tokens[$i]->type === CypherLexer::T_PUNCT && $value === ')') {
                $level++;
            } elseif ($tokens[$i]->type === CypherLexer::T_PUNCT && $value === '(') {
                if ($level === 0) {
                    $open = $i;
                    // toLower("<X>") is a call, not the group: its parenthesis follows a word.
                    if ($i === 0 || $tokens[$i - 1]->type !== CypherLexer::T_WORD || in_array(strtoupper($tokens[$i - 1]->value), ['AND', 'OR', 'NOT', 'WHERE'], true)) {
                        break;
                    }
                    $open = null;
                } else {
                    $level--;
                }
            }
        }

        if ($open !== null) {
            $level = 0;
            for ($i = $open + 1, $count = count($tokens); $i < $count; $i++) {
                $value = $tokens[$i]->value;
                if ($tokens[$i]->type === CypherLexer::T_PUNCT && $value === '(') {
                    $level++;
                } elseif ($tokens[$i]->type === CypherLexer::T_PUNCT && $value === ')') {
                    if ($level === 0) {
                        return [$tokens[$open]->offset, $tokens[$i]->offset];
                    }
                    $level--;
                }
            }
        }

        throw new QueryGenerationException("The calculation \"{$this->key}\" takes one value for <" . rtrim($placeholder, '?') . '>.');
    }

    /**
     * The query without the conditions holding this placeholder. Only a condition AND-ed at the top
     * of a WHERE can go: anywhere else, leaving it out could change what the query means.
     */
    private function withoutCondition(string $cypher, string $placeholder): string
    {
        $tokens = (new CypherLexer())->tokenize($cypher)['tokens'];
        $needle = '<' . $placeholder . '>';
        $edits = [];

        foreach ($tokens as $i => $token) {
            if (!$this->isTopLevelWord($token, 'WHERE')) {
                continue;
            }

            $end = $this->whereEnd($tokens, $i, strlen($cypher));
            if (!str_contains(substr($cypher, $token->offset, $end - $token->offset), $needle)) {
                continue;
            }

            $edit = $this->whereWithout($cypher, $tokens, $i, $end, $needle);
            if ($edit === null) {
                throw new QueryGenerationException("The calculation \"{$this->key}\" needs a value for <" . rtrim($placeholder, '?') . '>. Give it in "values", or write the query.');
            }
            $edits[] = [$token->offset, $end, $edit];
        }

        foreach (array_reverse($edits) as [$start, $end, $text]) {
            $cypher = substr($cypher, 0, $start) . $text . substr($cypher, $end);
        }

        if (str_contains($cypher, $needle)) {
            throw new QueryGenerationException("The calculation \"{$this->key}\" needs a value for <" . rtrim($placeholder, '?') . '>. Give it in "values", or write the query.');
        }

        return $cypher;
    }

    /** @param list<Token> $tokens */
    private function whereWithout(string $cypher, array $tokens, int $where, int $end, string $needle): ?string
    {
        $conditions = [];
        $start = $tokens[$where + 1]->offset ?? $end;

        for ($i = $where + 1, $count = count($tokens); $i < $count && $tokens[$i]->offset < $end; $i++) {
            if ($this->isTopLevelWord($tokens[$i], 'OR') || $this->isTopLevelWord($tokens[$i], 'XOR') || $this->isTopLevelWord($tokens[$i], 'CASE')) {
                return null;
            }
            if ($this->isTopLevelWord($tokens[$i], 'AND')) {
                $conditions[] = trim(substr($cypher, $start, $tokens[$i]->offset - $start));
                $start = $tokens[$i + 1]->offset ?? $end;
            }
        }
        $conditions[] = trim(substr($cypher, $start, $end - $start));

        $kept = array_values(array_filter($conditions, static fn (string $condition): bool => !str_contains($condition, $needle)));

        return $kept === [] ? '' : 'WHERE ' . implode(' AND ', $kept) . ' ';
    }

    /** @param list<Token> $tokens */
    private function whereEnd(array $tokens, int $where, int $length): int
    {
        for ($i = $where + 1, $count = count($tokens); $i < $count; $i++) {
            foreach (self::CLAUSE_HEADS as $head) {
                if ($this->isTopLevelWord($tokens[$i], $head)
                    && !($head === 'WITH' && in_array(strtoupper($tokens[$i - 1]->value), ['STARTS', 'ENDS'], true))) {
                    return $tokens[$i]->offset;
                }
            }
        }

        return $length;
    }

    private function isTopLevelWord(Token $token, string $word): bool
    {
        return $token->type === CypherLexer::T_WORD && $token->isWord($word) && !$token->keywordAsIdentifier
            && $token->parenDepth === 0 && $token->bracketDepth === 0 && $token->braceDepth === 0;
    }
}
