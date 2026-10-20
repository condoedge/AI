<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\AssistantIdentity;

/**
 * IdentitySection
 *
 * Renders the model-agnostic assistant identity (config('ai.identity')) at the very
 * top of the SYSTEM message: the project-branded persona, the language policy, and
 * the non-disclosure rule that prevents the assistant from revealing or speculating
 * about which LLM model/vendor powers it.
 *
 * Delivered via the system role (see role()), so it frames the whole turn as
 * non-negotiable instructions rather than user-supplied text.
 *
 * Priority: 5 (first — before the security policy and everything else).
 */
class IdentitySection extends BaseResponseSection
{
    protected string $name = 'identity';
    protected int $priority = 5;

    public function role(): string
    {
        return 'system';
    }

    public function format(PipelineContext $context, array $options = []): string
    {
        $output = AssistantIdentity::render();

        return $output === '' ? '' : $output . "\n\n";
    }
}
