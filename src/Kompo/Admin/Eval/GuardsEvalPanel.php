<?php

namespace Condoedge\Ai\Kompo\Admin\Eval;

use Condoedge\Ai\Eval\EvalPanel;

/**
 * Every eval component checks the ability itself, on top of the route middleware: Kompo's AJAX
 * calls (a table page, a modal, a launch) re-boot the component without the page route.
 */
trait GuardsEvalPanel
{
    public function authorizeBoot()
    {
        return EvalPanel::allows() && parent::authorizeBoot();
    }

    public function authorize()
    {
        return EvalPanel::allows() && parent::authorize();
    }
}
