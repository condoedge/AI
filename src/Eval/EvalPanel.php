<?php

namespace Condoedge\Ai\Eval;

use Condoedge\Ai\Contracts\EvalHost;
use Illuminate\Support\Facades\Gate;

/**
 * The eval panel's settings, all optional, under config ai.eval:
 *  - enabled (true)                 the routes exist;
 *  - path ('ai/admin/eval')         the page's address, a run's is path/{id};
 *  - suites_path (resources/ai-eval) the suites, one folder per kind, fixture.php in each;
 *  - host (DefaultEvalHost)         what ids are, who the assistant refuses (Contracts\EvalHost);
 *  - cards ([])                     Kompo components the host adds to the page, under the launch cards;
 *  - connection / queue ('ai-eval') where runs and audits are queued, one job at a time;
 *  - user_model                     the people who ask (auth.providers.users.model);
 *  - middleware / layout            as ai.admin's.
 * Who opens it is the Gate ability manageAiEval, denied unless the host grants it.
 */
class EvalPanel
{
    public const ABILITY = 'manageAiEval';

    public static function allows($user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null && Gate::forUser($user)->allows(static::ABILITY);
    }

    public static function host(): EvalHost
    {
        return app(config('ai.eval.host') ?: DefaultEvalHost::class);
    }

    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    public static function userModel(): string
    {
        return config('ai.eval.user_model') ?: config('auth.providers.users.model', \App\Models\User::class);
    }

    public static function suitesPath(string $path = ''): string
    {
        $root = rtrim(config('ai.eval.suites_path') ?: resource_path('ai-eval'), '/\\');

        return $path === '' ? $root : $root.'/'.$path;
    }

    public static function connection(): ?string
    {
        return config('ai.eval.connection', 'ai-eval');
    }

    public static function queue(): string
    {
        return config('ai.eval.queue', 'ai-eval');
    }

    /** @return array<class-string> */
    public static function cards(): array
    {
        return (array) config('ai.eval.cards', []);
    }

    public static function script(): string
    {
        return file_get_contents(__DIR__.'/../../resources/js/ai-eval-refresh.js');
    }
}
