<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

/**
 * A single lexer token with its byte offset and the nesting depths in effect at
 * its position. See {@see CypherLexer} for how depths are assigned.
 */
final class Token
{
    public function __construct(
        /** One of CypherLexer::T_WORD | T_LITERAL | T_PUNCT | T_OPERATOR. */
        public readonly string $type,
        /** Raw value (lower-cased comparisons are the caller's job); '' for literals. */
        public readonly string $value,
        /** Byte offset of the token's first character in the source string. */
        public readonly int $offset,
        public readonly int $parenDepth,
        public readonly int $bracketDepth,
        public readonly int $braceDepth,
        /**
         * True for a WORD that is DEFINITELY an identifier — a label / property /
         * label-expression name (`b:Yield`, `b:!Match`, `n.limit`), a backtick word,
         * a SKIP/LIMIT/OFFSET used as a variable, an `order` with no following BY.
         * Set by CypherLexer::markKeywordIdentifiers(); clause scans must skip it.
         */
        public readonly bool $keywordAsIdentifier = false,
        /**
         * True for a WORD in OPERAND position (after `=`, `,`, `(`, AND, WHERE …):
         * an expression, not a clause head. Only for fail-safe consumers — see
         * CypherLexer::markKeywordIdentifiers().
         */
        public readonly bool $operandPosition = false
    ) {
    }

    /** Case-insensitive keyword match for a T_WORD token. */
    public function isWord(string $keyword): bool
    {
        return $this->type === CypherLexer::T_WORD
            && strcasecmp($this->value, $keyword) === 0;
    }

    public function isPunct(string $char): bool
    {
        return $this->type === CypherLexer::T_PUNCT && $this->value === $char;
    }

    /** True when all structural nesting depths are zero (top level). */
    public function isTopLevel(): bool
    {
        return $this->parenDepth === 0 && $this->bracketDepth === 0 && $this->braceDepth === 0;
    }
}
