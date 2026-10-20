<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\DTOs;

use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use PHPUnit\Framework\TestCase;

/**
 * The document-search probes are written AFTER Query Understanding (by
 * DocumentProbeWriter, only when document search runs). They ride the two probe
 * slots the file-search fan-out already reads, on a COPY of the readonly result.
 */
class QueryUnderstandingResultDocumentProbesTest extends TestCase
{
    /** @test */
    public function the_first_probe_of_each_language_fills_the_per_language_slot_and_the_rest_the_list_slot(): void
    {
        $result = (new QueryUnderstandingResult())->withDocumentProbes([
            'fr' => ['sonde fr 1', 'sonde fr 2'],
            'en' => ['probe en 1', 'probe en 2'],
        ]);

        $this->assertSame(['fr' => 'sonde fr 1', 'en' => 'probe en 1'], $result->corpusTranslations);
        $this->assertSame(['sonde fr 2', 'probe en 2'], $result->registerExpansions);
    }

    /** @test */
    public function every_other_field_is_carried_over_unchanged(): void
    {
        $original = new QueryUnderstandingResult(
            entityTypes: ['Unit'],
            personNames: ['Ana'],
            propertyConcepts: ['balance'],
            searchQuery: 'unit balance',
            isPossessive: true,
            intent: 'lookup',
            questionType: 'knowledge_query',
            clarifiedQuestion: 'clarified',
            searchTags: ['a', 'b'],
            exportFormat: 'pdf',
            language: 'fr',
            followUpReference: true,
            digestRequest: true,
            needsClarification: true,
            candidateReadings: ['x', 'y'],
            clarifyingQuestion: 'which?',
            failed: true,
            navigationTarget: 'own_team',
            subjectLabel: 'Solde',
        );

        $copy = $original->withDocumentProbes(['en' => ['probe']]);

        $this->assertNotSame($original, $copy);
        $this->assertSame([], $original->corpusTranslations, 'the original is untouched');

        $expected = get_object_vars($original);
        $expected['corpusTranslations'] = ['en' => 'probe'];
        $expected['registerExpansions'] = [];
        $this->assertSame($expected, get_object_vars($copy));
    }

    /** @test */
    public function blank_and_non_string_probes_are_ignored(): void
    {
        $result = (new QueryUnderstandingResult())->withDocumentProbes([
            'fr' => ['  ', 'sonde'],
            'en' => 'not-a-list',
            'es' => [42, ['nested']],
        ]);

        $this->assertSame(['fr' => 'sonde'], $result->corpusTranslations);
        $this->assertSame([], $result->registerExpansions);
    }
}
