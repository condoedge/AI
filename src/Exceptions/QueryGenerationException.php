<?php

declare(strict_types=1);

namespace Condoedge\Ai\Exceptions;

/**
 * Query Generation Exception
 *
 * Thrown when query generation fails after retries or encounters
 * unrecoverable errors during the generation process. Carries the last
 * attempt's verbatim prompts when capture was requested (eval-only,
 * see {@see CarriesPrompts}) so a failed generation is still inspectable.
 */
class QueryGenerationException extends \RuntimeException implements CarriesPrompts
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
