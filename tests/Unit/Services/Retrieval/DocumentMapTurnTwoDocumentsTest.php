<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\Contracts\FileAccessResolverInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Services\Context\FilenameExtractor;
use Condoedge\Ai\Services\FileSearchService;
use Condoedge\Ai\Services\Retrieval\DocumentMapReader;
use Condoedge\Ai\Services\Retrieval\DocumentMapTurn;
use Condoedge\Ai\Support\DocumentMap;
use Condoedge\Ai\Tests\Fixtures\CallbackUsageLlm;
use Condoedge\Ai\Tests\Fixtures\InMemoryMapChunkStore;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * The map turn over documents of TWO SCOPES: a general document (a project
 * file: the law) and a record's own document (an uploaded file: one building's
 * declaration), the second filed several times.
 *
 *  - a COPY of a document already in the map is not read twice: its chunks stand
 *    for the first's;
 *  - the reader is told WHOSE document each block of the map is, in the words the
 *    passage headers use;
 *  - picks of one role put the record's own document before the general one, and
 *    keep both;
 *  - each document's map is budgeted separately.
 *
 * Fixture: the law (`physical:law.md`, chunks 1–6 under headings) and the
 * declaration (file 20, chunks 1–6, no heading), copied as file 21.
 */
class DocumentMapTurnTwoDocumentsTest extends TestCase
{
    private const LAW = 'physical:law.md';
    private const DECLARATION = 20;
    private const COPY = 21;

    private const OWN_LABEL = ['kind' => 'unit', 'name' => 'A-101', 'root_kind' => 'building', 'root_name' => 'Tower'];

    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai.file_context.min_relevance_score', 0.4);
        $app['config']->set('ai.file_context.max_references', 5);
        $app['config']->set('ai.file_context.snippet_length', 6000);
        $app['config']->set('ai.file_context.snippet_chunks', 3);
        $app['config']->set('ai.file_context.context_window', 2);
        $app['config']->set('ai.file_context.security_enabled', false);
        // The host switched the map of uploaded files ON (it is off by default).
        $app['config']->set('ai.file_context.document_map.uploads', true);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function text(int|string $fileId, int $index): string
    {
        return $fileId === self::LAW ? sprintf('**10%02d.** Rule %d of the law.', $index, $index) : "ARTICLE {$index} Clause {$index} of the declaration.";
    }

    /** @return list<FileChunk> */
    private function chunks(int|string $fileId, int $count = 6): array
    {
        $law = $fileId === self::LAW;
        $chunks = [];
        for ($index = 1; $index <= $count; $index++) {
            $content = $this->text($fileId, $index);
            $chunks[] = new FileChunk($fileId, $law ? 'law.md' : 'declaration.pdf', $content, [], $index, $count, 0, mb_strlen($content), [
                'heading_path' => $law ? ['LAW', 'PART', $index <= 3 ? 'S1' : 'S2'] : [],
                'is_preamble' => false,
                'article_no' => $law ? sprintf('10%02d', $index) : (string) $index,
            ] + ($law ? [] : ['link' => ['owner' => $fileId === self::COPY ? 'unit:874' : 'unit:873', 'ancestors' => ['building:71'], 'dedupe' => 'd' . $index]]) + [
                'map' => [
                    'v' => DocumentMap::VERSION,
                    'hash' => DocumentMap::hash($content),
                    'gist' => ($law ? 'Law' : 'Decl') . " gist {$index}.",
                ],
            ]);
        }

        return $chunks;
    }

    /** @param list<int> $indexes */
    private function passage(int|string $fileId, array $indexes): array
    {
        $held = [];
        foreach ($indexes as $index) {
            $held[$index] = $this->text($fileId, $index);
        }
        $law = $fileId === self::LAW;

        return [
            'file_id' => $fileId,
            'filename' => $law ? 'law.md' : 'declaration.pdf',
            'snippet' => implode(FileContextProvider::SNIPPET_SEPARATOR, $held),
            'relevance' => 0.6,
            'chunk_index' => $indexes[0],
            'source' => $law ? 'physical' : 'database',
            'match_type' => 'content',
            'heading_path' => $law ? ['LAW', 'PART', 'S1'] : [],
            'snippet_chunks' => $held,
            'angle_groups' => ['question'],
            'question_supported' => true,
        ] + ($law ? [] : [
            'owner' => $fileId === self::COPY ? 'unit:874' : 'unit:873',
            'ancestors' => ['building:71'],
            'scope_label' => $fileId === self::COPY ? ['kind' => 'unit', 'name' => 'A-100'] + self::OWN_LABEL : self::OWN_LABEL,
        ]);
    }

    /**
     * @param list<array> $files
     * @param list<array{0: string, 1: int, 2: string}> $needs [['Law'|'Decl', chunk, role], …]
     * @param array<int|string, int> $sizes file id => number of chunks
     * @return array{0: ?array, 1: CallbackUsageLlm}
     */
    private function mapTurn(array $files, array $needs, array $config = [], array $sizes = [], ?DocumentMapTurn &$turn = null): array
    {
        $store = new InMemoryMapChunkStore();
        $memo = new \ArrayObject();
        foreach ([self::LAW, self::DECLARATION, self::COPY] as $fileId) {
            $chunks = $this->chunks($fileId, $sizes[$fileId] ?? 6);
            $store->put($fileId, $chunks, null);
            $memo['file_chunks::' . $fileId] = $chunks;
        }

        $search = Mockery::mock(FileSearchService::class);
        $search->shouldReceive('searchByContent')->andReturn([]);
        $access = Mockery::mock(FileAccessResolverInterface::class);
        $access->shouldReceive('shouldEnforceSecurity')->andReturn(false);
        $access->shouldReceive('isPhysicalFile')->andReturnUsing(static fn ($id): bool => $id === self::LAW);
        $access->shouldReceive('filterAccessibleFileIds')->andReturnUsing(static fn (array $ids): array => array_values($ids));
        $access->shouldReceive('getAccessibleFileIds')->andReturn([self::LAW, self::DECLARATION, self::COPY]);
        $filenames = Mockery::mock(FilenameExtractor::class);
        $filenames->shouldReceive('extract')->andReturn([]);
        $provider = new FileContextProvider($search, $access, $filenames, null, $store);

        $llm = new CallbackUsageLlm(static function (array $messages) use ($needs): array {
            preg_match_all('/^\[([A-Z]{2})\] (Law|Decl) gist (\d+)\.$/m', $messages[1]['content'], $m, PREG_SET_ORDER);
            $ids = [];
            foreach ($m as $line) {
                $ids[$line[2] . $line[3]] = $line[1];
            }
            $reported = [];
            foreach ($needs as [$document, $chunk, $role]) {
                if (isset($ids[$document . $chunk])) {
                    $reported[] = ['id' => $ids[$document . $chunk], 'role' => $role];
                }
            }

            return ['issue' => 'What governs it.', 'answerable' => 'yes', 'needs' => $reported];
        });

        $turn = new DocumentMapTurn(new DocumentMapReader($llm, ['application' => '']), $provider, $config);

        return [$turn->apply('Who pays?', '', null, $files, ['turn_memo' => $memo]), $llm];
    }

    /** @test */
    public function a_copy_of_a_document_already_in_the_map_is_read_once_and_its_passages_count_as_the_documents_own(): void
    {
        $files = [$this->passage(self::DECLARATION, [1, 2]), $this->passage(self::COPY, [4]), $this->passage(self::LAW, [1])];

        // The reader names clause 4 — the chunk the COPY's passage holds: found by the search.
        [$result, $llm] = $this->mapTurn($files, [['Decl', 4, 'general_rule']]);

        $map = $llm->calls[0]['messages'][1]['content'];
        $this->assertSame(1, substr_count($map, 'Decl gist 4.'), 'the copy is not a second document of the map');
        $this->assertSame(1, substr_count($map, '=== DOCUMENT: declaration.pdf'));
        $this->assertSame(12, $result['trace']['reader']['map_chunks'], '6 of the law + 6 of the declaration');
        $this->assertSame('closed', $result['trace']['gate']['state'], 'the search already holds that clause, in the copy');
        $this->assertSame($files, $result['files']);
    }

    /** @test */
    public function with_the_uploads_switch_off_an_uploaded_document_counts_as_unmapped_whatever_its_chunks_carry(): void
    {
        config()->set('ai.file_context.document_map.uploads', false);
        $turn = null;

        // A map was built while the switch was on (or by another process): OFF means
        // the turn is exactly the turn of a host that never mapped an upload.
        [$result, $llm] = $this->mapTurn([$this->passage(self::DECLARATION, [1, 2]), $this->passage(self::LAW, [1])], [['Decl', 5, 'general_rule']], [], [], $turn);

        $this->assertNull($result);
        $this->assertSame(['fallback' => 'unmapped_file'], $turn->lastTrace());
        $this->assertSame([], $llm->calls, 'the reader is not paid for');

        // The host's own documents are read as before.
        [$result] = $this->mapTurn([$this->passage(self::LAW, [1])], [['Law', 5, 'general_rule']]);
        $this->assertSame('open', $result['trace']['gate']['state']);
    }

    /** @test */
    public function the_uploads_switch_is_off_unless_the_host_turns_it_on(): void
    {
        config()->set('ai.file_context.document_map', []);
        $turn = null;

        [$result] = $this->mapTurn([$this->passage(self::DECLARATION, [1, 2])], [], [], [], $turn);

        $this->assertNull($result);
        $this->assertSame(['fallback' => 'unmapped_file'], $turn->lastTrace());
    }

    /** @test */
    public function a_file_that_only_shares_some_text_with_another_is_a_document_of_its_own(): void
    {
        // The second file holds one clause more: not a copy.
        $files = [$this->passage(self::DECLARATION, [1, 2]), $this->passage(self::COPY, [4])];

        [$result, $llm] = $this->mapTurn($files, [], [], [self::COPY => 7]);

        $this->assertSame(2, substr_count($llm->calls[0]['messages'][1]['content'], '=== DOCUMENT: declaration.pdf'));
        $this->assertSame(13, $result['trace']['reader']['map_chunks']);
    }

    /** @test */
    public function the_reader_is_told_whose_document_each_block_of_the_map_is_in_the_words_of_the_passage_headers(): void
    {
        $files = [$this->passage(self::DECLARATION, [1, 2]), $this->passage(self::LAW, [1])];

        [, $llm] = $this->mapTurn($files, []);

        $map = $llm->calls[0]['messages'][1]['content'];
        $this->assertStringContainsString('=== DOCUMENT: declaration.pdf — document of unit "A-101" (building "Tower") ===', $map);
        $this->assertStringContainsString('=== DOCUMENT: law.md — GENERAL document, applies to everyone ===', $map);
        $this->assertStringContainsString('DIFFERENT SCOPES', $llm->calls[0]['messages'][0]['content']);
    }

    /** @test */
    public function documents_of_one_scope_are_read_with_the_prompt_measured_for_them(): void
    {
        [, $llm] = $this->mapTurn([$this->passage(self::LAW, [1])], []);

        $this->assertStringContainsString("=== DOCUMENT: law.md ===\n", $llm->calls[0]['messages'][1]['content']);
        $this->assertStringNotContainsString('DIFFERENT SCOPES', $llm->calls[0]['messages'][0]['content']);
        $this->assertStringNotContainsString('GENERAL document', $llm->calls[0]['messages'][1]['content']);
    }

    /** @test */
    public function picks_of_one_role_put_the_records_own_document_before_the_general_one_and_keep_both(): void
    {
        $files = [$this->passage(self::LAW, [1]), $this->passage(self::DECLARATION, [1])];

        // The reader names the law's rule first, then the declaration's, same role.
        [$result] = $this->mapTurn($files, [['Law', 5, 'general_rule'], ['Decl', 5, 'general_rule'], ['Law', 3, 'procedure_or_approval']]);

        $this->assertSame('open', $result['trace']['gate']['state']);
        $leading = array_map(static fn (array $file): string => $file['file_id'] . '#' . $file['chunk_index'], array_slice($result['files'], 0, 3));
        $this->assertSame([self::DECLARATION . '#5', self::LAW . '#5', self::LAW . '#3'], $leading);
        $this->assertSame(
            [['general_rule', 1], ['general_rule', 2], ['procedure_or_approval', 3]],
            array_map(static fn (array $rule): array => [$rule['role'], $rule['passage']], $result['analysis']['rules']),
            'the general document is kept — after the record\'s own'
        );
    }

    /** @test */
    public function the_governing_rules_of_the_records_own_document_come_before_those_of_the_general_one_each_by_role(): void
    {
        $files = [$this->passage(self::LAW, [1]), $this->passage(self::DECLARATION, [1])];

        // Measured live on "what does the declaration say about …": the reader named
        // the law's article as THE general rule and the declaration's clause as the
        // classification — by role alone the general law led the answer about the
        // building's own document.
        [$result] = $this->mapTurn($files, [
            ['Law', 5, 'general_rule'],
            ['Decl', 5, 'classification'],
            ['Law', 3, 'procedure_or_approval'],
            ['Decl', 3, 'general_rule'],
            ['Decl', 6, 'consequence'],
        ]);

        $leading = array_map(static fn (array $file): string => $file['file_id'] . '#' . $file['chunk_index'], array_slice($result['files'], 0, 4));
        $this->assertSame([self::DECLARATION . '#3', self::DECLARATION . '#5', self::LAW . '#5', self::LAW . '#3'], $leading);
        $this->assertSame(
            [['general_rule', 1], ['classification', 2], ['general_rule', 3], ['procedure_or_approval', 4]],
            array_map(static fn (array $rule): array => [$rule['role'], $rule['passage']], $result['analysis']['rules']),
            'the own document by role, then the general document by role; the consequence is placed, never announced'
        );
    }

    /** @test */
    public function a_passage_the_reader_adds_from_an_uploaded_document_carries_the_record_it_is_filed_on(): void
    {
        // The search found the law only... and one clause of the declaration.
        $files = [$this->passage(self::LAW, [1]), $this->passage(self::DECLARATION, [1])];

        [$result] = $this->mapTurn($files, [['Decl', 5, 'general_rule'], ['Law', 5, 'general_rule']]);

        $added = $result['files'][0];
        $this->assertSame(DocumentMapTurn::SELECTED_BY, $added['selected_by']);
        $this->assertSame(5, $added['chunk_index']);
        // Whose document it is must reach the passage header and the analysis line:
        // without the owner the governing rule was the one passage with no scope.
        $this->assertSame('unit:873', $added['owner']);
        $this->assertSame(['building:71'], $added['ancestors']);
        $this->assertArrayNotHasKey('owner', $result['files'][1], 'a project file is the own document of no record');
    }

    /** @test */
    public function an_open_gate_keeps_one_passage_of_each_kind_of_document_whatever_the_picks_took(): void
    {
        // The search: three passages of the declaration, then the law's.
        $files = [$this->passage(self::DECLARATION, [1]), $this->passage(self::DECLARATION, [3]), $this->passage(self::DECLARATION, [5]), $this->passage(self::LAW, [1])];

        // The reader names six clauses of the declaration the search did not find:
        // with the passage of the question's own wording they fill the list.
        $needs = [];
        foreach ([7, 9, 11, 13, 15, 17] as $clause) {
            $needs[] = ['Decl', $clause, 'general_rule'];
        }
        [$result] = $this->mapTurn($files, $needs, [], [self::DECLARATION => 18, self::COPY => 18]);

        $this->assertSame('open', $result['trace']['gate']['state']);
        $sources = array_column($result['files'], 'source');
        $this->assertContains('physical', $sources, 'the general document keeps a passage beside the picks');
        $this->assertCount(1, array_keys($sources, 'physical', true));
        $this->assertCount(8, $result['files'], 'six picks, the passage of the question, and the one reserved');
    }

    /** @test */
    public function each_document_is_budgeted_separately_so_a_long_one_never_takes_the_other_out_of_the_map(): void
    {
        $files = [$this->passage(self::DECLARATION, [1]), $this->passage(self::LAW, [1])];

        // 8 lines per document: the declaration (12, no heading: one section) is cut
        // to the neighbourhood of its hit; the law (6) is whole.
        [$result, $llm] = $this->mapTurn($files, [], ['reader' => ['max_chunks' => 8]], [self::DECLARATION => 12, self::COPY => 12]);

        $map = $llm->calls[0]['messages'][1]['content'];
        $this->assertSame(8, substr_count($map, 'Decl gist'));
        $this->assertSame(6, substr_count($map, 'Law gist'), 'the general document is not what pays for a long own document');
        $this->assertStringContainsString('Decl gist 1.', $map);
        $this->assertStringNotContainsString('Decl gist 12.', $map);
        $this->assertTrue($result['trace']['reader']['cut']);
        $this->assertSame(14, $result['trace']['reader']['map_chunks']);
    }
}
