<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * Grouped vector search + paged scroll capability of a vector store.
 *
 * Separate from VectorStoreInterface for the reason UpdatesPayloadInterface is
 * (a method added there breaks every host implementation and mock):
 * `instanceof GroupedSearchInterface` IS the capability check. A store without
 * it is searched plainly and its results are collapsed by the caller.
 * Implemented by QdrantStore.
 */
interface GroupedSearchInterface
{
    /**
     * The nearest points, at most $groupSize per distinct value of the payload
     * key $groupBy, for at most $limit distinct values — the grouping happens
     * inside the search, BEFORE the limit. A point without the key belongs to no
     * group and is not returned.
     *
     * @param array<int, float> $vector
     * @param array<string, mixed> $filter
     * @return list<array{key: string|int, hits: list<array<string, mixed>>}> best group first;
     *         each hit as VectorStoreInterface::search() returns a point
     */
    public function searchGroups(
        string $collection,
        array $vector,
        string $groupBy,
        int $limit = 10,
        array $filter = [],
        float $scoreThreshold = 0.0,
        int $groupSize = 1
    ): array;

    /**
     * One page of a scroll.
     *
     * @param array<string, mixed> $filter
     * @param bool|array<string, mixed> $withPayload true / false, or a payload
     *        selector (`['include' => [...]]`, `['exclude' => [...]]`)
     * @param mixed $offset The `next` of the previous page (null: the first page)
     * @return array{points: list<array<string, mixed>>, next: mixed} `next` is null on the last page
     */
    public function scrollPage(
        string $collection,
        array $filter = [],
        int $limit = 100,
        bool|array $withPayload = true,
        mixed $offset = null
    ): array;
}
