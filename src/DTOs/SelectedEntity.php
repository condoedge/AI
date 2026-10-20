<?php

declare(strict_types=1);

namespace Condoedge\Ai\DTOs;

class SelectedEntity
{
    /** A direct vector hit (entity / alias / example / property / relationship endpoint). */
    public const SOURCE_VECTOR = 'vector';
    /** A direct keyword match (index unavailable). */
    public const SOURCE_KEYWORD = 'keyword';
    /** The entity of a matched scope. */
    public const SOURCE_SCOPE = 'scope';
    /** Pulled in through a configured relationship of another selected entity. */
    public const SOURCE_EXPANSION = 'expansion';
    /** Pulled in through a user_entity_path (possessive question). */
    public const SOURCE_USER_PATH = 'user_path';
    /** Seeded by the retriever (Query Understanding, anchor, page, focus). */
    public const SOURCE_SEED = 'seed';
    /** Kept by the pruner (identity / tenant / closure / ownership tier). */
    public const SOURCE_PRUNER = 'pruner';

    public function __construct(
        public readonly float $score,
        public readonly string $matchedText,
        public readonly ?array $config = null,
        public readonly string $source = self::SOURCE_VECTOR,
    ) {}
}
