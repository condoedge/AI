<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Actions\Attach\AttachedActions;

/**
 * AttachedActionsSection (actions plan B5) — replaces the former link-grammar teaching
 * (ResponseEntityActionsSection: action catalog, aliases, per-row ids, entity:// syntax).
 *
 * Owner rule: "code attaches links/actions; the AI only narrates". The model gets ONE
 * line, the labels of the chips code attached (so the prose can point at them by name)
 * and, when the rows are records, the {{record:N}} rule — which code substitutes with the
 * row's link after generation. It never sees an action key, a URL or an id.
 *
 * Priority 55: right after the data (50) and the self-reference note (52).
 */
class AttachedActionsSection extends BaseResponseSection
{
    protected string $name = 'attached_actions';
    protected int $priority = 55;

    public function format(PipelineContext $context, array $options = []): string
    {
        $attached = $context->attachedActions ?? AttachedActions::none();

        return $this->header('Links and actions') . $attached->narratorInstruction() . "\n\n";
    }
}
