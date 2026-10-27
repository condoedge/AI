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
 * referents ("this order" while on an order page), lets the answer be situated
 * ("you are already on the Orders page — use the New Order button here"), and
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

        $route = $this->screen($context->pageContext)[1];
        $entityType = $context->pageContext['entity_type'] ?? null;
        $label = $context->pageContext['label'] ?? null;

        return (is_string($route) && $route !== '')
            || (is_string($entityType) && $entityType !== '')
            || (is_string($label) && $label !== '');
    }

    public function format(PipelineContext $context, array $options = []): string
    {
        [$kind, $route] = $this->screen($context->pageContext);
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
            $facts[] = $kind . ': ' . trim($route);
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
            . "this screen when it clearly is not.\n"
            . ($kind === 'page' ? "Call this page by that name only, never by a route or any technical identifier.\n" : '')
            . "\n";
    }

    /**
     * How the screen is named to the model: a page the chat captured itself carries `page_name` (its
     * navigation label, '' when none) and its route is never shown; a host's own context shows its route.
     *
     * @return array{0: string, 1: ?string}
     */
    private function screen(array $page): array
    {
        if (array_key_exists('page_name', $page) && is_string($page['page_name'])) {
            return ['page', $page['page_name']];
        }

        return ['route', is_string($page['route'] ?? null) ? $page['route'] : null];
    }
}
