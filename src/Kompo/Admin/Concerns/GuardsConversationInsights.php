<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Admin\Concerns;

use Condoedge\Ai\Services\Admin\ConversationInsights;
use Illuminate\Support\Facades\Gate;

/**
 * Every admin insights component re-checks the Gate ability itself, on top of the
 * route middleware: Kompo AJAX calls (filtering, paginating, opening the transcript)
 * do not go through the page route, they re-boot the component — and authorizeBoot()
 * runs on every boot (display AND action), authorize() on every browse / self call.
 * A failure throws an AuthorizationException → HTTP 403.
 */
trait GuardsConversationInsights
{
    public function authorizeBoot()
    {
        // The gate first; the base (condoedge/utils plugins) hooks only for allowed users.
        return static::canViewConversationInsights() && parent::authorizeBoot();
    }

    public function authorize()
    {
        return static::canViewConversationInsights() && parent::authorize();
    }

    public static function canViewConversationInsights(): bool
    {
        $user = auth()->user();

        return $user !== null && Gate::forUser($user)->allows(ConversationInsights::ABILITY);
    }
}
