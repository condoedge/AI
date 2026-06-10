<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

/**
 * One native tool/function call extracted from a provider response.
 *
 * $args is the decoded argument object ([] when absent or undecodable). A provider that
 * cannot decode the raw arguments sets $argsError instead of throwing, so a malformed call
 * becomes a corrective tool_result for the model rather than a failed turn — it must never
 * reach the dispatcher.
 */
final class LlmToolCall
{
    /**
     * @param array<string, mixed> $args
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $args = [],
        public readonly ?string $argsError = null,
    ) {
    }
}
