<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * PageDescriptionSection — grounding for the read-only "Explain this page" narration.
 *
 * When a turn is answered as page narration (AiManager detected a page-explanation
 * intent AND the host configured a description for the current page), this section
 * feeds the responder the host-supplied page description plus the current route so the
 * answer describes the page the chat was opened from instead of attempting a data query.
 *
 * Token-lean and default-off: shouldInclude() is false whenever $pageDescription is
 * empty, so every ordinary turn emits ZERO tokens and the response prompt is unchanged.
 * The host description is untrusted host data, so it is spotlighted (read as data, not
 * followed as instructions).
 */
class PageDescriptionSection extends BaseResponseSection
{
    protected string $name = 'page_description';
    protected int $priority = 22;

    public function shouldInclude(PipelineContext $context, array $options = []): bool
    {
        return trim($context->pageDescription) !== '';
    }

    public function format(PipelineContext $context, array $options = []): string
    {
        $description = trim($context->pageDescription);

        if ($description === '') {
            return '';
        }

        $route = $context->pageContext['route'] ?? null;
        $label = $context->pageContext['label'] ?? null;

        $lines = [];
        if (is_string($route) && $route !== '') {
            $lines[] = "Current screen (route): {$route}.";
        }
        if (is_string($label) && $label !== '') {
            $lines[] = "Record on screen: {$label}.";
        }
        $lines[] = $description;

        $body = implode("\n", $lines);

        return $this->header('PAGE DESCRIPTION')
            . "The user is asking what this page is and what they can do here. Answer ONLY from "
            . "the page description below — describe the page and its actions in the user's language; "
            . "do NOT run or claim any data lookup.\n\n"
            . $this->spotlight($body, 'page_description') . "\n\n";
    }
}
