<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions;

use Condoedge\Ai\Contracts\AdvertisableActionInterface;
use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;

/**
 * ModelMethodMutationAction
 *
 * The ONE reusable, approval-gated mutation pattern. It lets a host expose an EXISTING domain
 * write — a model state-transition the app's own UI already calls (e.g. Reservation::approve(),
 * Task::close()) — to the assistant WITHOUT reinventing the write or bypassing app rules. It
 * wraps the existing method; it never issues a raw query.
 *
 * It is deliberately model-agnostic: the package carries no host-model imports. A host wires:
 *   - finder($id): resolve the target from the entity id (defaults to {ModelClass}::find($id)
 *     when a $model class-string is given instead),
 *   - mutator($model, array $input): call the EXISTING domain method (the only place a write
 *     happens — and only after approval),
 *   - an EXPLICIT authorization (mandatory, see below),
 *   - optionally a precondition: the state the transition applies to (e.g. 'isPending').
 *
 * AUTHORIZATION IS MANDATORY AND EXPLICIT (security A1/A2). Any combination of:
 *   - permission($user): bool  — the row-independent CAPABILITY the app's own UI checks for this
 *     transition (e.g. "reservation:manage"). It is also what decides whether the action is
 *     ADVERTISED to a user at all ({@see isAdvertisableTo()}); without it the action is never
 *     offered at type level.
 *   - ability: a Gate/Policy ability re-checked via $user->can($ability, $model).
 *   - authorizer($user, $model, array $input): bool — a host row-level callback.
 * Every declared gate must pass (AND). A generic CRUD policy ability ('update', 'delete', ...) is
 * NOT an authorization for a state transition on its own: CRUD policies routinely let the record's
 * owner through (a customer "updating" their own reservation is not a manager approving it). A
 * mutation declaring nothing, or only a generic CRUD ability, FAILS CLOSED — it never authorizes
 * and is never advertised, and {@see authorizationError()} says why (logged once). It deliberately
 * does not throw: the action is built while the host's config file loads, and a throw there would
 * take the whole application down, not just this one action.
 *
 * A null principal never authorizes a mutation, and a gate/precondition that throws fails closed.
 *
 * mutates() is hard true, so the dispatcher defers this behind a signed PendingActionToken and a
 * fresh authorize() pass — the action never has to opt into the gate, it cannot opt out. The
 * precondition is part of authorize(), so it is checked at proposal AND at approval (the state may
 * change in between), and once more inside handle() right before the write.
 *
 * Entity id flow: an entity:// link carries the id as a PATH segment, not a query param. The
 * dispatcher seeds it into the schema-filtered input under {@see ID_PARAM} so it survives the
 * approval round-trip (the PendingActionToken stores input, not the path), which is how
 * authorize($user, $input) — called with no context — can still re-resolve the exact target.
 */
class ModelMethodMutationAction implements AiActionInterface, AdvertisableActionInterface
{
    /** The input key the entity id is seeded under so it rides the approval token. */
    public const ID_PARAM = 'entity_id';

    /**
     * Generic CRUD policy abilities. None of them is, on its own, an authorization for a domain
     * state transition (they commonly let the record owner through) — see the class doc.
     */
    public const GENERIC_ABILITIES = [
        'update', 'edit', 'save', 'create', 'store', 'delete', 'destroy',
        'view', 'viewAny', 'restore', 'forceDelete',
    ];

    /** Action keys whose misconfiguration was already logged in this process (log once). */
    private static array $loggedMisconfigurations = [];

    /** @var callable(mixed):mixed */
    private $finder;

    /** @var callable(mixed, array<string,mixed>):mixed */
    private $mutator;

    /** @var (callable(mixed, mixed, array<string,mixed>):bool)|null */
    private $authorizer;

    /** @var (callable(mixed):bool)|null */
    private $permission;

    /** @var (callable(mixed, array<string,mixed>):bool)|string|null */
    private $precondition;

    private ?string $authorizationError;

    /** The model class when the finder was given as a class-string (used for the locked re-fetch). */
    private ?string $modelClass;

    /** @var (callable(mixed):mixed)|null  Optional fn($id) re-fetching the row under a write lock. */
    private $lockingFinder;

    /**
     * @param string $key        Stable machine key used in the link / token.
     * @param string $label      Human label advertised to the LLM and the approval card.
     * @param callable|string $finder  fn($id): ?object resolving the target, OR a model
     *                                  class-string resolved via {ModelClass}::find($id).
     * @param callable $mutator   fn($model, array $input): mixed — the EXISTING domain method.
     * @param callable|null $authorizer  fn($user, $model, array $input): bool — row-level gate.
     * @param string[] $aliases   Natural-language phrasings the user might say.
     * @param string $description Short description advertised to the LLM.
     * @param array<string, array<string, mixed>> $inputSchema  Extra typed params the mutator reads.
     * @param string|null $entityType  Entity label this action is bound to (for token round-trip).
     * @param string|null $summary  Optional ID-free summary line for the LLM (defaults from label).
     * @param callable|null $permission  fn($user): bool — the row-independent capability the app's
     *                                   UI requires for this transition; also gates advertisement.
     * @param string|null $ability  Gate/Policy ability checked via $user->can($ability, $model).
     *                              A generic CRUD ability is accepted only BESIDE an explicit
     *                              permission/authorizer (defense in depth), never alone.
     * @param callable|string|null $precondition  The state the transition applies to: a method
     *                              name on the model (e.g. 'isPending') or fn($model, array $input):
     *                              bool. Checked at proposal, at approval and right before the write.
     * @param callable|null $lockingFinder  fn($id): ?object re-fetching the row under a write lock
     *                              for a CALLABLE finder that does not return an Eloquent model (a
     *                              class-string finder / Eloquent result is locked automatically).
     * @param bool $rowScoped  true (default): a ROW-LEVEL gate is mandatory — an `authorizer:` or a
     *                              transition-specific `ability:` — because a `permission:` is usually
     *                              scoped to the user's CURRENT team, not to the row's team. false is
     *                              the explicit opt-out for genuinely global (tenant-less) models.
     */
    public function __construct(
        private string $key,
        private string $label,
        callable|string $finder,
        callable $mutator,
        ?callable $authorizer = null,
        private array $aliases = [],
        private string $description = '',
        private array $inputSchema = [],
        private ?string $entityType = null,
        private ?string $summary = null,
        ?callable $permission = null,
        private ?string $ability = null,
        callable|string|null $precondition = null,
        ?callable $lockingFinder = null,
        private bool $rowScoped = true,
    ) {
        $this->modelClass = is_string($finder) ? $finder : null;
        $this->lockingFinder = $lockingFinder;
        $this->finder = is_string($finder)
            ? static fn ($id) => $id === null ? null : $finder::find($id)
            : $finder;
        $this->mutator = $mutator;
        $this->authorizer = $authorizer;
        $this->permission = $permission;
        $this->ability = ($ability !== null && trim($ability) !== '') ? $ability : null;
        // A string precondition is a model METHOD name, never a global function: is_callable('trim')
        // must not turn 'trim' into a function call on the model's behalf.
        $this->precondition = $precondition;
        $this->authorizationError = $this->validateAuthorization();
    }

    /**
     * Why this mutation fails closed, or null when it declares an explicit authorization.
     */
    public function authorizationError(): ?string
    {
        return $this->authorizationError;
    }

    private function validateAuthorization(): ?string
    {
        $explicitCallback = $this->permission !== null || $this->authorizer !== null;

        if (!$explicitCallback && $this->ability === null) {
            return "AI mutation '{$this->key}' declares no authorization: pass `permission:` (the capability "
                . 'the app UI requires for this transition), a transition-specific `ability:`, or an `authorizer:`. '
                . 'It fails closed (never authorized, never advertised) until it does.';
        }

        if (!$explicitCallback && in_array($this->ability, self::GENERIC_ABILITIES, true)) {
            return "AI mutation '{$this->key}' is gated only on the generic policy ability '{$this->ability}', which is "
                . 'not an authorization for a state transition (CRUD policies commonly let the record owner pass). '
                . 'Declare `permission:` (the capability the app UI requires) or a transition-specific `ability:`. '
                . 'It fails closed (never authorized, never advertised) until it does.';
        }

        // I2: a row-independent permission (typically current-team scoped) does not prove the
        // user may act on THIS row — a manager of team A could otherwise mutate team B's row by id.
        $rowGate = $this->authorizer !== null
            || ($this->ability !== null && !in_array($this->ability, self::GENERIC_ABILITIES, true));
        if ($this->rowScoped && !$rowGate) {
            return "AI mutation '{$this->key}' declares no row-level gate: a `permission:` is not scoped to the row "
                . '(it is usually checked on the user\'s CURRENT team), and a generic CRUD ability does not count. '
                . 'Declare an `authorizer:` (e.g. the row belongs to the user\'s current team) or a transition-specific '
                . '`ability:`, or pass `rowScoped: false` for a genuinely global model. '
                . 'It fails closed (never authorized, never advertised) until it does.';
        }

        return null;
    }

    /** Log a misconfiguration once per action key (container-tolerant). */
    private function reportMisconfiguration(): void
    {
        if ($this->authorizationError === null || isset(self::$loggedMisconfigurations[$this->key])) {
            return;
        }
        self::$loggedMisconfigurations[$this->key] = true;

        try {
            if (function_exists('app') && app()->bound('log')) {
                app('log')->error($this->authorizationError);
            }
        } catch (\Throwable $e) {
            // No logger — failing closed is already the safe behavior.
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function aliases(): array
    {
        return $this->aliases;
    }

    public function description(): string
    {
        return $this->description !== '' ? $this->description : $this->label;
    }

    /**
     * The configured params PLUS the entity-id param the dispatcher seeds from the link path, so
     * the id survives filtering and rides the approval token (it is never advertised as a
     * user-supplied param — EntityActionAwarenessSection only flags "[accepts params]" when the
     * host declares its own). Declaring it here is what keeps authorize($user, $input) able to
     * re-resolve the exact target after the round-trip.
     */
    public function inputSchema(): array
    {
        return [self::ID_PARAM => ['type' => 'string', 'label' => 'Target id']] + $this->inputSchema;
    }

    /** The host-declared params only (the seeded id is an internal carrier, not a user param). */
    public function declaredParams(): array
    {
        return $this->inputSchema;
    }

    /** A write is always approval-gated — it cannot opt out. */
    public function mutates(): bool
    {
        return true;
    }

    public function entityType(): ?string
    {
        return $this->entityType;
    }

    /**
     * Server-side gate. Resolves the target from the id seeded in $input and runs the configured
     * authorizer against the actual model — so the check is over the SAME row the mutation will
     * touch, not a guessed one. Fails closed when the target cannot be resolved (a denied/forged
     * id never reaches the authorizer against a null model).
     */
    public function authorize(mixed $user, array $input): bool
    {
        if ($this->authorizationError !== null || $user === null) {
            $this->reportMisconfiguration();
            return false;
        }

        $model = $this->resolve($input[self::ID_PARAM] ?? null);
        if ($model === null) {
            return false;
        }

        return $this->authorizeModel($user, $model, $input);
    }

    /**
     * The full gate over an already-resolved row: declared authorization (permission AND ability
     * AND authorizer — every declared one must pass) plus the precondition. Fails closed on a
     * misconfigured action, a null principal/row, or any gate that throws.
     *
     * @param array<string, mixed> $input
     */
    public function authorizeModel(mixed $user, mixed $model, array $input = []): bool
    {
        if ($this->authorizationError !== null) {
            $this->reportMisconfiguration();
            return false;
        }

        if ($user === null || $model === null) {
            return false;
        }

        try {
            if ($this->permission !== null && !(bool) ($this->permission)($user)) {
                return false;
            }

            if ($this->ability !== null) {
                if (!is_object($user) || !method_exists($user, 'can') || !(bool) $user->can($this->ability, $model)) {
                    return false;
                }
            }

            if ($this->authorizer !== null && !(bool) ($this->authorizer)($user, $model, $input)) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        return $this->preconditionHolds($model, $input);
    }

    /**
     * Row-independent capability: may this action be OFFERED to the principal at all? Only a
     * declared `permission` can answer that without a row — an action without one is never
     * advertised at type level (it is still dispatchable through the full row gate).
     */
    public function isAdvertisableTo(mixed $user): bool
    {
        if ($this->authorizationError !== null) {
            $this->reportMisconfiguration();
            return false;
        }

        if ($user === null || $this->permission === null) {
            return false;
        }

        try {
            return (bool) ($this->permission)($user);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether the row is in a state the transition applies to. No precondition => holds.
     * A string is a METHOD NAME on the model (never a global function); a missing method or a
     * throwing check fails closed.
     *
     * @param array<string, mixed> $input
     */
    public function preconditionHolds(mixed $model, array $input = []): bool
    {
        if ($this->precondition === null) {
            return true;
        }

        try {
            if (is_string($this->precondition)) {
                $method = $this->precondition;

                return is_object($model) && method_exists($model, $method) && (bool) $model->{$method}();
            }

            return (bool) ($this->precondition)($model, $input);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Execute the wrapped domain method. For mutating actions this only runs AFTER approval + a
     * fresh authorize() pass, and delegates to the EXISTING method — the sole write site.
     *
     * M1: it acts ONLY on the AUTHORIZED id — the input id the approval token carries, the one
     * authorize() resolved. A context naming a different row is refused (never "context wins").
     *
     * I1: check-then-write is atomic. Inside ONE transaction the row is re-fetched under a write
     * lock (lockForUpdate), the precondition is evaluated on that locked row, and the mutator runs —
     * so of two concurrent cards on the same pending row the second blocks on the lock, then sees
     * the first's state change and reports not-applied (no last-write-wins). A throwing mutator
     * rolls the transaction back. Non-Eloquent targets (no connection) run the same sequence
     * without a transaction, through the optional lockingFinder or a fresh finder call.
     *
     * A missing target degrades to a graceful error (no exception, no write).
     */
    public function handle(array $input, ActionContext $context): AiActionResult
    {
        $id = $input[self::ID_PARAM] ?? null;
        if ($id === null || $id === '') {
            return AiActionResult::error('The item to act on could not be found.');
        }

        $contextId = $context->entityId;
        if ($contextId !== null && $contextId !== '' && (string) $contextId !== (string) $id) {
            return AiActionResult::error('This action request does not match the item it was approved for.');
        }

        $probe = $this->resolve($id);
        if ($probe === null) {
            return AiActionResult::error('The item to act on could not be found.');
        }

        $apply = function () use ($id, $probe, $input): AiActionResult {
            $model = $this->lockForWrite($id, $probe);
            if ($model === null) {
                return AiActionResult::error('The item to act on could not be found.');
            }

            // Last check, on the LOCKED row: the state may have changed since approval was granted.
            if (!$this->preconditionHolds($model, $input)) {
                return AiActionResult::error('This action was not applied: it no longer applies to the item in its current state.');
            }

            ($this->mutator)($model, $input); // a throw propagates → the transaction rolls back

            return AiActionResult::element(
                ['action' => 'mutation', 'key' => $this->key],
                $this->summary ?? ($this->label . ' completed.'),
            );
        };

        try {
            $connection = $this->connectionFor($probe);

            return $connection !== null ? $connection->transaction($apply) : $apply();
        } catch (\Throwable $e) {
            return AiActionResult::error('The action could not be completed: ' . $e->getMessage());
        }
    }

    /**
     * Re-fetch the target under a write lock (must be called inside the transaction):
     *   1. the host's lockingFinder, when declared;
     *   2. a class-string finder → {Model}::query()->lockForUpdate()->find($id);
     *   3. an Eloquent model from a callable finder → re-fetched locked through its own class;
     *   4. anything else (no database) → a fresh finder call.
     */
    private function lockForWrite(mixed $id, mixed $probe): mixed
    {
        if ($this->lockingFinder !== null) {
            return ($this->lockingFinder)($id);
        }

        if ($this->modelClass !== null && is_subclass_of($this->modelClass, \Illuminate\Database\Eloquent\Model::class)) {
            return $this->modelClass::query()->lockForUpdate()->find($id);
        }

        if ($probe instanceof \Illuminate\Database\Eloquent\Model) {
            return $probe->newQuery()->lockForUpdate()->find($probe->getKey());
        }

        return $this->resolve($id);
    }

    /** The database connection the target lives on, or null for a non-Eloquent target. */
    private function connectionFor(mixed $probe): ?\Illuminate\Database\ConnectionInterface
    {
        if ($probe instanceof \Illuminate\Database\Eloquent\Model) {
            return $probe->getConnection();
        }

        return null;
    }

    /**
     * Resolve the target model from an id via the configured finder, tolerating any finder error
     * (e.g. a malformed id) by failing closed to null rather than throwing.
     */
    private function resolve(mixed $id): mixed
    {
        if ($id === null || $id === '') {
            return null;
        }

        try {
            return ($this->finder)($id);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
