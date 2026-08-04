<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;

/**
 * ResponsePageContextSection — WHERE the user is asking from.
 *
 * On every ordinary turn (owner directive 2026-07-23: "we need to have context
 * about the current route in the LLM, so we know in which moment the user
 * asked"), tell the responder which screen/record the chat was opened from.
 * This is SITUATIONAL context, not the question's subject: it disambiguates
 * referents ("this unit" while on a unit page), lets the answer be situated
 * ("you are already on the Units page — use the New Unit button here"), and
 * steers which next-step offers are most relevant.
 *
 * Token-lean: emits nothing when no page context was provided, and stays out
 * of the way on page-narration turns (PageDescriptionSection already carries
 * the route there). Internal ids are never rendered in prose (privacy policy:
 * ids live only inside action/entity links).
 */
class ResponsePageContextSection extends BaseResponseSection
{
    protected string $name = 'page_context';
    protected int $priority = 24;

    public function shouldInclude(PipelineContext $context, array $options = []): bool
    {
        if (trim($context->pageDescription) !== '') {
            return false; // page-narration turn: PageDescriptionSection owns the route
        }

        $route = $context->pageContext['route'] ?? null;
        $entityType = $context->pageContext['entity_type'] ?? null;
        $label = $context->pageContext['label'] ?? null;

        return (is_string($route) && $route !== '')
            || (is_string($entityType) && $entityType !== '')
            || (is_string($label) && $label !== '');
    }

    public function format(PipelineContext $context, array $options = []): string
    {
        $route = $context->pageContext['route'] ?? null;
        $entityType = $context->pageContext['entity_type'] ?? null;
        $label = $context->pageContext['label'] ?? null;

        $facts = [];
        if (is_string($label) && trim($label) !== '') {
            $facts[] = 'record on screen: ' . trim($label);
        }
        if (is_string($entityType) && trim($entityType) !== '') {
            $facts[] = 'screen type: ' . trim($entityType);
        }
        if (is_string($route) && trim($route) !== '') {
            $facts[] = 'route: ' . trim($route);
        }

        if ($facts === []) {
            return '';
        }

        return $this->header('CURRENT PAGE CONTEXT')
            . 'The user asked this while viewing — ' . implode('; ', $facts) . ".\n"
            . 'Use it as situational context only: when the question has an ambiguous referent, prefer '
            . "this screen's record/entity as the referent; when the answer involves navigation or offers "
            . 'a next step, prefer what is relevant from THIS screen (including "you are already there" '
            . 'when the user asks how to reach the page they are on). Never assume the question is about '
            . "this screen when it clearly is not.\n\n";
    }
}
