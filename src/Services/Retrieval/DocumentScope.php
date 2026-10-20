<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

/**
 * The record a question is about, for the search of UPLOADED documents: a focus
 * key and the keys of the records that own it ({@see \Condoedge\Ai\Support\FileLink::key()})
 * — or UNKNOWN. Project files are never narrowed by it.
 */
final class DocumentScope
{
    public const PAGE = 'page';
    public const CONVERSATION = 'conversation';
    public const CITED_FILES = 'cited_files';
    public const CURRENT = 'current';
    public const UNKNOWN = 'unknown';

    /**
     * @param list<string> $ancestors
     */
    public function __construct(
        public readonly ?string $focus = null,
        public readonly array $ancestors = [],
        public readonly string $source = self::UNKNOWN,
    ) {
    }

    public static function unknown(): self
    {
        return new self();
    }

    public function isKnown(): bool
    {
        return $this->focus !== null && $this->focus !== '';
    }

    /**
     * The search filter of this scope; null when it is unknown (no narrowing).
     *
     * @return array{focus: string, ancestors: list<string>}|null
     */
    public function filter(): ?array
    {
        return $this->isKnown() ? ['focus' => (string) $this->focus, 'ancestors' => $this->ancestors] : null;
    }

    /** @return array<string, mixed> for the turn trace */
    public function toArray(): array
    {
        return $this->isKnown()
            ? ['state' => 'known', 'focus' => $this->focus, 'ancestors' => $this->ancestors, 'source' => $this->source]
            : ['state' => 'unknown'];
    }
}
