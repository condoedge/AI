<?php

declare(strict_types=1);

namespace Condoedge\Ai\Exceptions;

/**
 * Response Generation Exception
 *
 * Thrown when final response generation fails. Extends RuntimeException so every
 * existing catch site (which caught the previous bare \RuntimeException wrap) is
 * unaffected; additionally carries the verbatim prompts that were sent when the
 * failure happened (eval-only, see {@see CarriesPrompts}).
 */
class ResponseGenerationException extends \RuntimeException implements CarriesPrompts
{
    /** @var array{system: string, user: string}|null */
    private ?array $prompts = null;

    /** @param array{system: string, user: string}|null $prompts */
    public function setPrompts(?array $prompts): self
    {
        $this->prompts = $prompts;

        return $this;
    }

    public function getPrompts(): ?array
    {
        return $this->prompts;
    }
}
