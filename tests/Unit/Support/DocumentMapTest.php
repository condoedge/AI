<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Support;

use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Support\DocumentMap;
use PHPUnit\Framework\TestCase;

/**
 * DocumentMap: the pure helpers the index-time builder and the question-time
 * reader share — what a FRESH map entry is, when a document counts as mapped,
 * and which top-level part of its document a chunk belongs to.
 */
class DocumentMapTest extends TestCase
{
    private function chunk(string $content, array $metadata = [], int $index = 0): FileChunk
    {
        return new FileChunk('f1', 'law.md', $content, [], $index, 3, 0, mb_strlen($content), $metadata);
    }

    private function entry(string $content, array $over = []): array
    {
        return $over + [
            'v' => DocumentMap::VERSION,
            'hash' => DocumentMap::hash($content),
            'gist' => 'What the passage establishes.',
            'kind' => 'general_rule',
            'part' => null,
            'built_at' => 1700000000,
        ];
    }

    /** @test */
    public function the_hash_is_the_first_16_hex_characters_of_the_sha1_of_the_content(): void
    {
        $this->assertSame(substr(sha1('Some passage.'), 0, 16), DocumentMap::hash('Some passage.'));
        $this->assertSame(16, strlen(DocumentMap::hash('')));
        $this->assertNotSame(DocumentMap::hash('Some passage.'), DocumentMap::hash('Some passage. '));
    }

    /** @test */
    public function a_fresh_entry_is_returned(): void
    {
        $entry = $this->entry('Some passage.');

        $this->assertSame($entry, DocumentMap::entryOf($this->chunk('Some passage.', ['map' => $entry])));
    }

    /** @test */
    public function an_entry_whose_hash_is_not_the_one_of_the_current_content_is_stale_and_counts_as_absent(): void
    {
        $entry = $this->entry('The passage as it was when the map was built.');

        $this->assertNull(DocumentMap::entryOf($this->chunk('The passage as it reads now.', ['map' => $entry])));
    }

    /** @test */
    public function an_entry_of_another_version_counts_as_absent(): void
    {
        $content = 'Some passage.';

        $this->assertNull(DocumentMap::entryOf($this->chunk($content, ['map' => $this->entry($content, ['v' => DocumentMap::VERSION + 1])])));
        $this->assertNull(DocumentMap::entryOf($this->chunk($content, ['map' => array_diff_key($this->entry($content), ['v' => 1])])));
    }

    /** @test */
    public function an_entry_without_a_gist_or_that_is_not_an_array_counts_as_absent(): void
    {
        $content = 'Some passage.';

        $this->assertNull(DocumentMap::entryOf($this->chunk($content)));
        $this->assertNull(DocumentMap::entryOf($this->chunk($content, ['map' => 'gist'])));
        $this->assertNull(DocumentMap::entryOf($this->chunk($content, ['map' => $this->entry($content, ['gist' => '  '])])));
        $this->assertNull(DocumentMap::entryOf($this->chunk($content, ['map' => $this->entry($content, ['gist' => ['a']])])));
    }

    /** @test */
    public function the_entry_is_read_from_an_array_chunk_a_raw_payload_or_a_search_result_too(): void
    {
        $content = 'Some passage.';
        $entry = $this->entry($content);

        $this->assertSame($entry, DocumentMap::entryOf(['content' => $content, 'metadata' => ['map' => $entry]]));
        $this->assertSame($entry, DocumentMap::entryOf(['content' => $content, 'map' => $entry]), 'raw payload: top-level map key');
        $this->assertSame($entry, DocumentMap::entryOf(['chunk' => $this->chunk($content, ['map' => $entry]), 'score' => 0.8]));
        $this->assertNull(DocumentMap::entryOf(['content' => 'Other text.', 'map' => $entry]));
        $this->assertNull(DocumentMap::entryOf(['map' => $entry]), 'no content to check the hash against');
    }

    /** @test */
    public function a_map_is_usable_when_every_content_chunk_was_attempted_for_its_current_content(): void
    {
        $a = $this->chunk('First passage.', ['map' => $this->entry('First passage.')], 1);
        $b = $this->chunk('Second passage.', ['map' => $this->entry('Second passage.')], 2);
        $unmapped = $this->chunk('Third passage.', [], 3);
        $stale = $this->chunk('Fourth passage, edited.', ['map' => $this->entry('Fourth passage.')], 4);

        $this->assertTrue(DocumentMap::isUsable([$a, $b]));
        $this->assertFalse(DocumentMap::isUsable([$a, $b, $unmapped]), 'a chunk nobody looked at yet');
        $this->assertFalse(DocumentMap::isUsable([$a, $stale]), 'a record of another text is no attempt');
        $this->assertSame(['content' => 3, 'mapped' => 2, 'failed' => 0, 'pending' => 1], DocumentMap::state([$a, $b, $unmapped]));
    }

    /** @test */
    public function a_chunk_whose_gist_failed_was_attempted_and_does_not_keep_its_file_unmapped(): void
    {
        $chunks = [];
        for ($n = 1; $n <= 19; $n++) {
            $chunks[] = $this->chunk("Passage {$n}.", ['map' => $this->entry("Passage {$n}.")], $n);
        }
        $rejected = $this->chunk('Boilerplate.', ['map' => DocumentMap::failure('Boilerplate.', DocumentMap::FAILED_REJECTED)], 20);

        $this->assertNull(DocumentMap::entryOf($rejected), 'no gist: not a line of the map');
        $this->assertSame(['failed' => 'rejected', 'attempts' => 1], DocumentMap::failureOf($rejected));
        $this->assertNull(DocumentMap::failureOf($chunks[0]), 'an entry is not a failure');
        $this->assertSame(['content' => 20, 'mapped' => 19, 'failed' => 1, 'pending' => 0], DocumentMap::state([...$chunks, $rejected]));
        $this->assertTrue(DocumentMap::isUsable([...$chunks, $rejected]), '19 of 20 mapped, all attempted');

        // The failure of an OLD text is no attempt on the new one.
        $edited = $this->chunk('Boilerplate, edited.', ['map' => DocumentMap::failure('Boilerplate.', DocumentMap::FAILED_REJECTED)], 20);
        $this->assertNull(DocumentMap::failureOf($edited));
        $this->assertFalse(DocumentMap::isUsable([...$chunks, $edited]));
    }

    /** @test */
    public function a_map_that_lost_more_than_one_chunk_in_ten_is_not_usable(): void
    {
        $this->assertSame(0.9, DocumentMap::MIN_MAPPED_SHARE);

        $chunks = [];
        for ($n = 1; $n <= 10; $n++) {
            $failed = $n <= 2;
            $chunks[] = $this->chunk("Passage {$n}.", ['map' => $failed
                ? DocumentMap::failure("Passage {$n}.", DocumentMap::FAILED_ERROR, 3)
                : $this->entry("Passage {$n}.")], $n);
        }

        $this->assertSame(['content' => 10, 'mapped' => 8, 'failed' => 2, 'pending' => 0], DocumentMap::state($chunks));
        $this->assertFalse(DocumentMap::isUsable($chunks), '8 of 10');
        $this->assertFalse(DocumentMap::isUsable(array_slice($chunks, 1)), '8 of 9');

        $chunks[0] = $this->chunk('Passage 1.', ['map' => $this->entry('Passage 1.')], 1);
        $this->assertTrue(DocumentMap::isUsable($chunks), '9 of 10');
    }

    /** @test */
    public function preamble_chunks_are_never_mapped_and_do_not_count(): void
    {
        $preamble = $this->chunk('# Title', ['is_preamble' => true], 0);
        $a = $this->chunk('First passage.', ['is_preamble' => false, 'map' => $this->entry('First passage.')], 1);

        $this->assertTrue(DocumentMap::isUsable([$preamble, $a]));
        $this->assertFalse(DocumentMap::isUsable([$preamble]), 'at least one content chunk');
        $this->assertFalse(DocumentMap::isUsable([]));
    }

    /** @test */
    public function the_scope_of_a_chunk_is_read_from_its_fresh_record_of_either_kind(): void
    {
        $content = 'Some passage.';

        $this->assertSame('out', DocumentMap::scopeOf($this->chunk($content, ['map' => $this->entry($content, ['scope' => 'out'])])));
        $this->assertSame('in', DocumentMap::scopeOf($this->chunk($content, ['map' => $this->entry($content, ['scope' => 'in'])])));
        $this->assertSame('out', DocumentMap::scopeOf($this->chunk($content, ['map' => DocumentMap::failure($content, 'rejected') + ['scope' => 'out']])), 'a chunk without a gist is hidden with its part too');
        $this->assertNull(DocumentMap::scopeOf($this->chunk($content, ['map' => $this->entry($content, ['scope' => 'unclear'])])));
        $this->assertNull(DocumentMap::scopeOf($this->chunk($content, ['map' => $this->entry($content)])));
        $this->assertNull(DocumentMap::scopeOf($this->chunk('Edited.', ['map' => $this->entry($content, ['scope' => 'out'])])), 'a stale record hides nothing');
        $this->assertNull(DocumentMap::scopeOf($this->chunk($content)));
    }

    /** @test */
    public function a_divided_document_owes_a_scope_verdict_until_every_record_was_judged_against_the_current_description(): void
    {
        $application = 'An app for the members of a housing cooperative.';
        $ref = DocumentMap::scopeRef($application);
        $chunk = fn (int $n, string $part, array $map): FileChunk => $this->chunk("Passage {$n}.", [
            'heading_path' => ['Code', $part, 'Section'],
            'map' => $map + $this->entry("Passage {$n}."),
        ], $n);

        $this->assertSame($ref, DocumentMap::scopeRef("  An app for the members\n of a housing cooperative. "), 'whitespace is not a change');
        $this->assertNotSame($ref, DocumentMap::scopeRef('Another application.'));

        $judged = [$chunk(1, 'Part A', ['scope' => 'in', 'scope_ref' => $ref]), $chunk(2, 'Part B', ['scope' => 'out', 'scope_ref' => $ref])];
        $this->assertSame(['Code › Part A', 'Code › Part B'], DocumentMap::parts($judged));
        $this->assertFalse(DocumentMap::scopePending($judged, $application));

        // A first build whose scope call failed; a record written after the verdict.
        $this->assertTrue(DocumentMap::scopePending([$chunk(1, 'Part A', []), $chunk(2, 'Part B', [])], $application));
        $this->assertTrue(DocumentMap::scopePending([...$judged, $chunk(3, 'Part B', [])], $application));
        // The description changed since.
        $this->assertTrue(DocumentMap::scopePending($judged, 'Another application.'));
        // A verdict that hides nothing (the model could not tell) is still a verdict.
        $this->assertFalse(DocumentMap::scopePending([$chunk(1, 'Part A', ['scope_ref' => $ref]), $chunk(2, 'Part B', ['scope_ref' => $ref])], $application));

        // A document that is one part owes nothing; nor does any document while the
        // host describes no application — unless a scope of an earlier description is left.
        $this->assertFalse(DocumentMap::scopePending([$chunk(1, 'Part A', []), $chunk(2, 'Part A', [])], $application));
        $this->assertFalse(DocumentMap::scopePending([$chunk(1, 'Part A', []), $chunk(2, 'Part B', [])], ''));
        $this->assertTrue(DocumentMap::scopePending($judged, ''));
    }

    /** @test */
    public function the_document_depth_is_its_deepest_heading_path_among_content_chunks(): void
    {
        $chunks = [
            $this->chunk('# Title', ['is_preamble' => true, 'heading_path' => ['T', 'A', 'B', 'C', 'D']], 0),
            $this->chunk('One.', ['heading_path' => ['T', 'A']], 1),
            $this->chunk('Two.', ['heading_path' => ['T', 'A', 'B']], 2),
            ['content' => 'Three.', 'metadata' => ['heading_path' => ['T']]],
        ];

        $this->assertSame(3, DocumentMap::documentDepth($chunks));
        $this->assertSame(0, DocumentMap::documentDepth([$this->chunk('No structure.')]));
        $this->assertSame(0, DocumentMap::documentDepth([]));
    }

    /** @test */
    public function a_document_of_three_or_more_levels_is_divided_into_parts_named_by_its_first_two_levels(): void
    {
        $this->assertSame('Code › Chapter II', DocumentMap::partOf(['Code', 'Chapter II', 'Division 1'], 3));
        $this->assertSame('Code › Chapter II', DocumentMap::partOf(['Code', 'Chapter II', 'Division 1', '§ 2'], 4));
        $this->assertSame('Code › Chapter I', DocumentMap::partOf(['Code', 'Chapter I'], 3), 'a shallower chunk of a deep document');
        $this->assertSame('Code', DocumentMap::partOf(['Code'], 3), 'text directly under the title is a part of its own');
        $this->assertNull(DocumentMap::partOf([], 3), 'a chunk under no heading belongs to no part');
    }

    /** @test */
    public function a_document_of_fewer_than_three_levels_is_one_part(): void
    {
        $this->assertNull(DocumentMap::partOf(['Guide', 'Adding a contact'], 2));
        $this->assertNull(DocumentMap::partOf(['Guide'], 1));
        $this->assertNull(DocumentMap::partOf([], 0));
    }
}
