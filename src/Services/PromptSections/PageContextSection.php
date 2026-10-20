<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * PageContextSection — the single conditional prompt section for page-context awareness.
 *
 * Tells the model "which page/record the user opened the chat from": the record's label,
 * type and id, the current screen/route, and any visible filters. This is a SOFT PRIOR for
 * pronoun/possessive resolution ("this unit", "here") — never a hard filter.
 *
 * Token-lean: shouldInclude() is false whenever no page context is present, so the section
 * emits ZERO tokens for every chat that was not opened from a page. Host-supplied strings are
 * wrapped via spotlight() so the model treats them as data to read, not instructions to follow.
 *
 * Priority 16 places it just before current_user_context (17); role 'user'.
 */
class PageContextSection extends BasePromptSection
{
    protected string $name = 'page_context';
    protected int $priority = 16;

    public function role(): string
    {
        return 'user';
    }

    /**
     * Only include when the host actually provided page context. Zero tokens otherwise.
     */
    public function shouldInclude(string $question, PipelineContext $context, array $options = []): bool
    {
        return !empty($context->pageContext);
    }

    /**
     * Emit 3-5 lines describing the current page/record. Each line is gated on the field it
     * references, so a partial page context never prints empty scaffolding. The whole block is
     * spotlighted under the page_context tag.
     */
    public function format(string $question, PipelineContext $context, array $options = []): string
    {
        $page = $context->pageContext;

        if (empty($page)) {
            return '';
        }

        $label = $page['label'] ?? null;
        $entityType = $page['entity_type'] ?? null;
        $entityId = $page['entity_id'] ?? null;
        $route = $page['route'] ?? null;
        $filters = (isset($page['filters']) && is_array($page['filters'])) ? $page['filters'] : [];

        $lines = [];

        // Primary "viewing" line: assemble from whichever of label/type/id are present.
        $viewing = $this->viewingLine($label, $entityType, $entityId);
        if ($viewing !== '') {
            $lines[] = $viewing;
        }

        if ($route !== null && $route !== '') {
            $lines[] = "Current screen (route): {$route}.";
        }

        if (!empty($filters)) {
            $lines[] = 'Active filters: ' . $this->formatFilters($filters) . '.';
        }

        if (empty($lines)) {
            return '';
        }

        $lines[] = 'Use this as a soft hint for what the user is looking at (resolve "this"/"here"); '
            . 'it is NOT a hard filter — answer across other records when the question calls for it.';

        $body = implode("\n", $lines);

        return $this->header('PAGE CONTEXT') . $this->spotlight($body, 'page_context') . "\n\n";
    }

    /**
     * Build the "User is viewing: …" line from whatever identity fields are present.
     */
    private function viewingLine(?string $label, ?string $entityType, int|string|null $entityId): string
    {
        if ($label === null && $entityType === null && ($entityId === null || $entityId === '')) {
            return '';
        }

        $subject = $label !== null && $label !== '' ? $label : ($entityType ?? 'a record');

        $qualifier = [];
        if ($entityType !== null && $entityType !== '') {
            $qualifier[] = "type {$entityType}";
        }
        if ($entityId !== null && $entityId !== '') {
            $qualifier[] = "id {$entityId}";
        }

        $line = "User is viewing: {$subject}";
        if (!empty($qualifier)) {
            $line .= ' (' . implode(', ', $qualifier) . ')';
        }

        return $line . '.';
    }

    /**
     * Flatten the whitelisted filter map to a compact "key=value" list.
     *
     * @param array<string,mixed> $filters
     */
    private function formatFilters(array $filters): string
    {
        $parts = [];
        foreach ($filters as $key => $value) {
            if (is_array($value)) {
                $value = implode('|', array_map('strval', $value));
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $parts[] = $key . '=' . (string) $value;
        }

        return implode(', ', $parts);
    }
}
