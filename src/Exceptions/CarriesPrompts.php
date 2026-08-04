<?php

declare(strict_types=1);

namespace Condoedge\Ai\Exceptions;

/**
 * A throwable that carries the VERBATIM LLM prompts that were in flight when the
 * failure happened.
 *
 * Prompt capture (the eval harness's capture_prompts option) must not go dark
 * exactly when a stage fails — that is when an out-of-process grader most needs
 * to see what was asked of the LLM. Generators that build system/user prompts
 * attach them to their failure exceptions via this contract so the eval driver
 * (and AiManager's error-metadata path) can surface them in the trace.
 *
 * Prompts are only attached when capture was explicitly requested; production
 * error paths carry null and pay nothing.
 */
interface CarriesPrompts
{
    /**
     * The last verbatim prompt pair sent (or about to be sent) to the LLM,
     * as ['system' => string, 'user' => string], or null when capture was off.
     *
     * @return array{system: string, user: string}|null
     */
    public function getPrompts(): ?array;
}
