<?php

declare(strict_types=1);

namespace Condoedge\Ai\Contracts;

/**
 * What the host's own pages show the asker about their own records, as current facts the answer
 * may state (ai.audiences.facts_resolver). The host computes them from that user's records, so they
 * reach someone who may not query the entity: its rows would include other people's.
 */
interface AskerFactsInterface
{
    /** @return list<string> One fact per line, in plain words. Empty when there are none. */
    public function factsFor(mixed $user): array;
}
