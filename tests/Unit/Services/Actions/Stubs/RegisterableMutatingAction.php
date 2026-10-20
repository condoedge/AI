<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions\Stubs;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;

/**
 * Fixture host action modelling the Sisc "create event" pattern from the design doc
 * (94 §"Proof it is project-agnostic"): a state-mutating action a HOST registers purely via
 * config('ai.actions') class-strings — no service-provider code, no core edit. It is the
 * reference writer the package previously lacked (only a non-mutating test SpyAction shipped).
 *
 * Contract:
 *   - mutates() === true        => the dispatcher defers behind an approval gate, never runs
 *                                  handle() at render time.
 *   - authorize() is a real gate => denies unless the validated input carries allowed=true, so
 *                                  the booted round-trip can prove a denied gate blocks handle().
 *   - handle() returns DATA + INTENT (AiActionResult::element), never a Kompo element.
 *
 * It records, on a STATIC log keyed by a per-instance run id, every handle() invocation so a
 * booted test can assert handle() ran exactly once and only AFTER approval. The instance is
 * recreated by the registry on boot (new $class()), so the log is static + run-id-scoped rather
 * than per-instance.
 */
final class RegisterableMutatingAction implements AiActionInterface
{
    /** @var array<string, int> run id => times handle() executed */
    public static array $handledRuns = [];

    /** @var array<string, array<string, mixed>> run id => last input handle() received */
    public static array $lastInput = [];

    public function key(): string
    {
        return 'create_record';
    }

    public function label(): string
    {
        return 'Create record';
    }

    public function aliases(): array
    {
        return ['create record', 'add record'];
    }

    public function description(): string
    {
        return 'Create a new record (reference mutating action).';
    }

    public function inputSchema(): array
    {
        return [
            'name'    => ['type' => 'string', 'required' => true, 'label' => 'Name'],
            'allowed' => ['type' => 'bool',   'default' => false,  'label' => 'Authorized'],
            'run'     => ['type' => 'string', 'default' => 'default'],
        ];
    }

    /** State-mutating => approval gate + fresh server-side re-authorize, for free. */
    public function mutates(): bool
    {
        return true;
    }

    /**
     * Real server-side gate: allowed only when the validated input says so. The booted test
     * flips this to prove a denied gate blocks handle() at the approval step.
     */
    public function authorize(mixed $user, array $input): bool
    {
        return !empty($input['allowed']);
    }

    public function handle(array $input, ActionContext $context): AiActionResult
    {
        $run = (string) ($input['run'] ?? 'default');
        self::$handledRuns[$run] = (self::$handledRuns[$run] ?? 0) + 1;
        self::$lastInput[$run] = $input;

        $name = (string) ($input['name'] ?? 'record');

        // DATA + INTENT only — the presenter turns this into a link/redirect.
        return AiActionResult::element(
            ['route' => 'records.show', 'name' => $name],
            "Created record \"{$name}\"."
        );
    }

    /** Test helper: reset the static run log between cases. */
    public static function reset(): void
    {
        self::$handledRuns = [];
        self::$lastInput = [];
    }
}
