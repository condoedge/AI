<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Services\Discovery\EntityLabelIndex;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;

/**
 * PolymorphicTargetResolver
 *
 * Maps a row's morph-type value (e.g. 'service' from priceable_type) to the
 * entity catalog's graph label (e.g. 'Service'):
 *
 *   morph value -> Relation::getMorphedModel() (or raw-FQCN passthrough)
 *               -> config('entities')[$class]['graph']['label']
 *
 * Unresolvable values (unmapped alias, class without a catalog entry, null,
 * non-string) return null — callers skip the row; this resolver never throws.
 * Raw morph values are used only as array-lookup keys and never reach Cypher.
 *
 * Results (including negative ones) are memoized per instance, so a million
 * rows sharing one alias cost one lookup and one log line per process.
 */
final class PolymorphicTargetResolver
{
    /** @var array<string, ?string> morph value => catalog label (null = unresolvable) */
    private array $cache = [];

    /** @var array<string, true> warn-once guard per distinct unresolvable value */
    private array $logged = [];

    public function resolveLabel(mixed $morphValue): ?string
    {
        if (!is_string($morphValue) || $morphValue === '') {
            return null;
        }

        if (array_key_exists($morphValue, $this->cache)) {
            return $this->cache[$morphValue];
        }

        // 1. Alias -> class via the host's morph map; raw FQCN values (models
        //    stored without a map entry) pass through the class_exists guard.
        $class = Relation::getMorphedModel($morphValue)
            ?? (class_exists($morphValue) ? $morphValue : null);

        // 2. Class -> catalog label; FQCN key first, basename second (mirrors
        //    HasNodeableConfig::readFromEntitiesConfig) — via the shared index.
        $label = $class !== null ? EntityLabelIndex::shared()->labelForClass($class) : null;

        if ($label === null && !isset($this->logged[$morphValue])) {
            Log::info('Polymorphic morph value has no catalog label; edges for it will be skipped', [
                'morph_value' => $morphValue,
                'resolved_class' => $class,
            ]);
            $this->logged[$morphValue] = true;
        }

        return $this->cache[$morphValue] = $label;
    }
}
