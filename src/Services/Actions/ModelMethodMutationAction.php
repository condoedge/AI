<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions;

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
 * It is deliberately model-agnostic: the package carries no host-model imports. A host wires
 * three callables:
 *   - finder($id): resolve the target from the entity id (defaults to {ModelClass}::find($id)
 *     when a $model class-string is given instead),
 *   - mutator($model, array $input): call the EXISTING domain method (the only place a write
 *     happens — and only after approval),
 *   - authorizer($user, $model, array $input): the SERVER-SIDE policy re-check, re-run at render
 *     AND again right before the mutation executes.
 *
 * mutates() is hard true, so the dispatcher defers this behind a signed PendingActionToken and a
 * fresh authorize() pass — the action never has to opt into the gate, it cannot opt out.
 *
 * Entity id flow: an entity:// link carries the id as a PATH segment, not a query param. The
 * dispatcher seeds it into the schema-filtered input under {@see ID_PARAM} so it survives the
 * approval round-trip (the PendingActionToken stores input, not the path), which is how
 * authorize($user, $input) — called with no context — can still re-resolve the exact target.
 */
class ModelMethodMutationAction implements AiActionInterface
{
    /** The input key the entity id is seeded under so it rides the approval token. */
    public const ID_PARAM = 'entity_id';

    /** @var callable(mixed):mixed */
    private $finder;

    /** @var callable(mixed, array<string,mixed>):mixed */
    private $mutator;

    /** @var callable(mixed, mixed, array<string,mixed>):bool */
    private $authorizer;

    /**
     * @param string $key        Stable machine key used in the link / token.
     * @param string $label      Human label advertised to the LLM and the approval card.
     * @param callable|string $finder  fn($id): ?object resolving the target, OR a model
     *                                  class-string resolved via {ModelClass}::find($id).
     * @param callable $mutator   fn($model, array $input): mixed — the EXISTING domain method.
     * @param callable $authorizer  fn($user, $model, array $input): bool — server-side gate.
     * @param string[] $aliases   Natural-language phrasings the user might say.
     * @param string $description Short description advertised to the LLM.
     * @param array<string, array<string, mixed>> $inputSchema  Extra typed params the mutator reads.
     * @param string|null $entityType  Entity label this action is bound to (for token round-trip).
     * @param string|null $summary  Optional ID-free summary line for the LLM (defaults from label).
     */
    public function __construct(
        private string $key,
        private string $label,
        callable|string $finder,
        callable $mutator,
        callable $authorizer,
        private array $aliases = [],
        private string $description = '',
        private array $inputSchema = [],
        private ?string $entityType = null,
        private ?string $summary = null,
    ) {
        $this->finder = is_string($finder)
            ? static fn ($id) => $id === null ? null : $finder::find($id)
            : $finder;
        $this->mutator = $mutator;
        $this->authorizer = $authorizer;
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
        $model = $this->resolve($input[self::ID_PARAM] ?? null);
        if ($model === null) {
            return false;
        }

        return (bool) ($this->authorizer)($user, $model, $input);
    }

    /**
     * Execute the wrapped domain method. For mutating actions this only runs AFTER approval + a
     * fresh authorize() pass. Resolves the target from the context entity id (entity:// path) or
     * the seeded input id, then delegates to the EXISTING method — the sole write site. A missing
     * target degrades to a graceful error (no exception, no write).
     */
    public function handle(array $input, ActionContext $context): AiActionResult
    {
        $id = $context->entityId ?? ($input[self::ID_PARAM] ?? null);

        $model = $this->resolve($id);
        if ($model === null) {
            return AiActionResult::error('The item to act on could not be found.');
        }

        try {
            ($this->mutator)($model, $input);
        } catch (\Throwable $e) {
            return AiActionResult::error('The action could not be completed: ' . $e->getMessage());
        }

        return AiActionResult::element(
            ['action' => 'mutation', 'key' => $this->key],
            $this->summary ?? ($this->label . ' completed.'),
        );
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
