<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * SystemPromptSection
 *
 * Historically this set a hardcoded generic "data analyst" persona. The assistant's
 * identity now lives in IdentitySection (config('ai.identity'), system role), so by
 * default this section emits NOTHING — it only contributes if the host app explicitly
 * sets a custom system prompt via setPrompt(). This keeps a single source of truth for
 * the persona and avoids the old hardcoded, non-project-branded string.
 *
 * Delivered via the system role so any custom prompt frames the turn.
 * Priority: 10 (after IdentitySection at 5).
 */
class SystemPromptSection extends BaseResponseSection
{
    protected string $name = 'system';
    protected int $priority = 10;

    private ?string $customPrompt = null;

    public function role(): string
    {
        return 'system';
    }

    /**
     * Set a custom system prompt
     */
    public function setPrompt(string $prompt): self
    {
        $this->customPrompt = $prompt;
        return $this;
    }

    public function format(PipelineContext $context, array $options = []): string
    {
        if ($this->customPrompt !== null) {
            return $this->customPrompt . "\n\n";
        }

        // Default persona is provided by IdentitySection (config('ai.identity')).
        return '';
    }
}
