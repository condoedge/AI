<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\BuiltIn;

use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;

/**
 * my_profile / my_team — the identity links (actions plan B2; owner: "provide the link
 * of the user profile when asking related").
 *
 * Near-zero config: each points at a host route that exists in a standard Laravel/Jetstream
 * app — `profile.show` and `teams.show` (current team) — configurable under
 * `ai.attached_actions.identity.{profile|team}.route`. A host that already ships its own
 * generic action for the page (e.g. `team_settings`) names it under
 * `...identity.team.action` instead, and the attacher uses that action (no duplicate).
 *
 * Authorized only for an authenticated user when the route exists — a missing route never
 * renders a dead chip.
 */
final class IdentityLinkAction extends AbstractBuiltInAction
{
    public const PROFILE = 'my_profile';
    public const TEAM = 'my_team';

    private const DEFAULTS = [
        self::PROFILE => ['kind' => 'profile', 'route' => 'profile.show', 'label' => 'My profile'],
        self::TEAM => ['kind' => 'team', 'route' => 'teams.show', 'label' => 'My team'],
    ];

    public function __construct(private readonly string $key)
    {
    }

    public static function profile(): self
    {
        return new self(self::PROFILE);
    }

    public static function team(): self
    {
        return new self(self::TEAM);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->labelIn(null);
    }

    /**
     * The label in $locale (the QUESTION's language when the attacher knows it; null = app
     * locale) — a chip under an English answer must not read "Mon profil".
     */
    public function labelIn(?string $locale): string
    {
        $default = self::DEFAULTS[$this->key]['label'] ?? $this->key;
        $translationKey = 'ai.actions.' . $this->key;

        try {
            $translated = function_exists('__') ? __($translationKey, [], $locale) : $translationKey;
        } catch (\Throwable $e) {
            $translated = $translationKey;
        }

        return is_string($translated) && $translated !== $translationKey ? $translated : $default;
    }

    public function description(): string
    {
        return $this->key === self::PROFILE
            ? "The current user's own profile page."
            : "The current user's team page (members, invitations, roles).";
    }

    public function authorize(mixed $user, array $input): bool
    {
        return $user !== null && $this->target($user) !== null;
    }

    public function handle(array $input, ActionContext $context): AiActionResult
    {
        $target = $this->target($context->user);
        if ($target === null || !function_exists('_Link')) {
            return AiActionResult::error('This page is not available.');
        }

        [$route, $parameters] = $target;
        $text = $context->text !== null && $context->text !== '' ? $context->text : $this->label();

        return AiActionResult::element(_Link($text)->href($route, $parameters), $this->label());
    }

    /**
     * The route + parameters this link opens for $user, or null when the route does not
     * exist (or the team link has no current team to open).
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function target(mixed $user): ?array
    {
        $kind = self::DEFAULTS[$this->key]['kind'] ?? null;
        if ($kind === null) {
            return null;
        }

        $route = self::config("ai.attached_actions.identity.{$kind}.route", self::DEFAULTS[$this->key]['route']);
        if (!is_string($route) || $route === '' || !self::routeExists($route)) {
            return null;
        }

        if ($kind !== 'team') {
            return [$route, []];
        }

        $teamId = is_object($user) ? ($user->current_team_id ?? null) : null;
        if (!is_scalar($teamId) || (string) $teamId === '') {
            return null;
        }

        $parameter = self::config('ai.attached_actions.identity.team.parameter', 'team');

        return [$route, [is_string($parameter) && $parameter !== '' ? $parameter : 'team' => $teamId]];
    }

    private static function routeExists(string $route): bool
    {
        try {
            return function_exists('app') && app()->bound('router') && app('router')->has($route);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function config(string $key, mixed $default): mixed
    {
        try {
            return function_exists('config') ? config($key, $default) : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
