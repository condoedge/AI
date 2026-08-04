<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

/**
 * One step of an AI-composed guided tutorial.
 *
 * The shape mirrors a single entry in the `steps` array that
 * Condoedge\Utils\Tutorials\TutorialProcessor::process() returns for static
 * tutorials, so the existing front-end window.TutorialEngine consumes an
 * AI-generated step unchanged:
 *
 *   - element : a CSS selector the engine highlights (null = a floating step,
 *               no anchor). The composer whitelists this against a host-provided
 *               selector map so the AI can never point a step at arbitrary DOM.
 *   - intro   : the step's HTML body (already rendered from sanitized markdown).
 *   - showIf  : an optional engine-side condition key (passed through verbatim;
 *               the host's TutorialConditionEvaluator decides visibility).
 */
final class TutorialStep
{
    public function __construct(
        public readonly string $intro,
        public readonly ?string $element = null,
        public readonly ?string $showIf = null,
    ) {
    }

    /**
     * The serialized shape the front-end engine consumes. Null `element`/`showIf`
     * are OMITTED rather than emitted as null so the JSON stays minimal and an
     * absent anchor reads as a floating step (the engine distinguishes absent from
     * present-but-null).
     *
     * @return array{element?:string,intro:string,showIf?:string}
     */
    public function toArray(): array
    {
        $out = [];

        if ($this->element !== null) {
            $out['element'] = $this->element;
        }

        $out['intro'] = $this->intro;

        if ($this->showIf !== null) {
            $out['showIf'] = $this->showIf;
        }

        return $out;
    }
}
