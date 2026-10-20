<?php

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Response\FileCitationHandler;
use Condoedge\Ai\Tests\TestCase;

/**
 * Task 1.3 — the persisted referenced_files list is COMPACT (only cited files,
 * in order of first citation) and each item carries its prompt citation number
 * in 'ref'. The citation link must resolve [N] by that 'ref', never by array
 * position.
 */
class FileCitationHandlerRefLookupTest extends TestCase
{
    private function files(): array
    {
        // As ResponseFileEnricher::buildReferencedFiles builds it for "…[3]… …[1]…".
        return [
            ['ref' => 3, 'id' => 30, 'name' => 'law.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
            ['ref' => 1, 'id' => 10, 'name' => 'rules.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
        ];
    }

    private function idsBySlot(string $content, array $files): array
    {
        $handler = new FileCitationHandler();
        $handler->createElements($content, ['files' => $files]);

        $bySlot = [];
        foreach ($handler->getCitationMetadata() as $meta) {
            $bySlot[$meta['slot']] = $meta['id'];
        }

        return $bySlot;
    }

    public function test_citation_resolves_by_ref_not_array_position(): void
    {
        $bySlot = $this->idsBySlot('Per the law [3] and the rules [1].', $this->files());

        $this->assertSame(10, $bySlot['file-citation-1'] ?? null, '[1] must link the file whose ref is 1');
        $this->assertSame(30, $bySlot['file-citation-3'] ?? null, '[3] must link the file whose ref is 3');
    }

    public function test_citation_number_without_matching_ref_gets_no_link(): void
    {
        $bySlot = $this->idsBySlot('See [2].', $this->files());

        $this->assertArrayNotHasKey('file-citation-2', $bySlot, '[2] has no file with ref 2 — no link');
    }

    /**
     * M-1: when ANY item carries a 'ref', lookup is by ref only — an item without
     * one is never matched by its position (it could shadow the real [1]).
     */
    public function test_mixed_list_matches_by_ref_only(): void
    {
        $bySlot = $this->idsBySlot('See [1] and [2].', [
            ['id' => 99, 'name' => 'no-ref.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
            ['ref' => 1, 'id' => 10, 'name' => 'rules.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
        ]);

        $this->assertSame(10, $bySlot['file-citation-1'] ?? null, '[1] is the item whose ref is 1, not position 1');
        $this->assertArrayNotHasKey('file-citation-2', $bySlot, 'no item has ref 2; the ref-less item is not matched by position');
    }

    /** M-1: a list where NO item has a 'ref' (hand-built context) is positional. */
    public function test_list_without_any_ref_is_positional(): void
    {
        $bySlot = $this->idsBySlot('See [2] then [1].', [
            ['id' => 42, 'name' => 'a.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
            ['id' => 43, 'name' => 'b.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
        ]);

        $this->assertSame(42, $bySlot['file-citation-1'] ?? null);
        $this->assertSame(43, $bySlot['file-citation-2'] ?? null);
    }

    public function test_lone_high_citation_links_its_file(): void
    {
        $bySlot = $this->idsBySlot('Only the law [3] applies.', [
            ['ref' => 3, 'id' => 30, 'name' => 'law.pdf', 'morphable_type' => 'file', 'mime_type' => 'application/pdf'],
        ]);

        $this->assertSame(30, $bySlot['file-citation-3'] ?? null);
    }
}
