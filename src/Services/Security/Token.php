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
        /** One of CypherLexer::T_WORD | T_LITERAL | T_PUNCT. */
        public readonly string $type,
        /** Raw value (lower-cased comparisons are the caller's job); '' for literals. */
        public readonly string $value,
        /** Byte offset of the token's first character in the source string. */
        public readonly int $offset,
        public readonly int $parenDepth,
        public readonly int $bracketDepth,
        public readonly int $braceDepth
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
