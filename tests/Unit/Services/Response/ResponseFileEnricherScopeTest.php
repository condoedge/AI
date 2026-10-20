<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Response\ResponseFileEnricher;
use Condoedge\Ai\Tests\TestCase;

/**
 * The citation of an uploaded file says whose document it is — the words for the
 * user-facing card, the keys for the next turn's scope — and the citation of a
 * project file says it is a general document.
 */
class ResponseFileEnricherScopeTest extends TestCase
{
    private const FILES = ['relevant_files' => [
        ['file_id' => 20, 'filename' => 'declaration.pdf', 'snippet' => 'Article 5', 'relevance' => 0.8, 'chunk_index' => 4, 'source' => 'database',
            'owner' => 'unit:873', 'ancestors' => ['union:66'],
            'scope_label' => ['kind' => 'unit', 'name' => 'A-101', 'root_kind' => 'condo association', 'root_name' => 'Viva Tour 1']],
        ['file_id' => 'physical:laws/code.md', 'filename' => 'code.md', 'snippet' => 'Art. 1063', 'relevance' => 0.7, 'chunk_index' => 3, 'source' => 'physical'],
        ['file_id' => 21, 'filename' => 'old.pdf', 'snippet' => 'x', 'relevance' => 0.6, 'chunk_index' => 0, 'source' => 'database'],
    ]];

    /** @test */
    public function the_citation_of_an_uploaded_file_carries_its_owner_in_words_and_in_keys(): void
    {
        $cited = (new ResponseFileEnricher())->buildReferencedFiles('The rule [1] and the law [2] and [3].', self::FILES);

        $this->assertSame('unit A-101 — condo association Viva Tour 1', $cited[0]['scope_label']);
        $this->assertSame('unit:873', $cited[0]['owner']);
        $this->assertSame(['union:66'], $cited[0]['ancestors']);
        // A legacy reference says nothing about its owner.
        $this->assertSame([], array_intersect(['scope_label', 'owner', 'ancestors'], array_keys($cited[2])));
    }

    /** @test */
    public function the_citation_of_a_project_file_says_it_is_a_general_document_in_the_users_language(): void
    {
        $enricher = new ResponseFileEnricher();

        $this->assertSame('General document', $enricher->buildReferencedFiles('[2]', self::FILES, ['locale' => 'en'])[0]['scope_label']);
        $this->assertSame('Document général', $enricher->buildReferencedFiles('[2]', self::FILES, ['locale' => 'fr'])[0]['scope_label']);
    }
}
