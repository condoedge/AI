<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions;

/**
 * The action channel {@see ActionToolGate} chooses for one response turn — how (and whether)
 * registered actions are offered to the model.
 *
 *  - Native   : real provider tool definitions; the markdown grammar section is suppressed
 *               so the model is never taught both transports at once.
 *  - Markdown : today's action:// / entity:// link grammar — also the implicit channel for
 *               every legacy caller whose options carry no channel at all.
 *  - None     : no action channel (terse styles — just the answer, no levers).
 */
enum ActionToolChannel
{
    /** Section-options key the chosen channel rides under to the response sections. */
    public const OPTION = 'action_channel';

    case Native;
    case Markdown;
    case None;
}
