<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Eval p12-B french t1: a French export was titled "Team members roles" because the
 * document title came from search_query — an English-normalized rewrite for semantic
 * search. QU now also emits `subject_label`: a short human title of the request's
 * SUBJECT in the QUESTION's language, for user-visible titles only. search_query (what
 * feeds semantic search) is unchanged.
 */
class QueryUnderstandingSubjectLabelTest extends TestCase
{
    /** @var list<array<int, array<string, string>>> */
    private array $prompts = [];

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function analyze(array $llmJson): QueryUnderstandingResult
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function ($messages) use ($llmJson) {
            $this->prompts[] = $messages;

            return $llmJson + [
                'entity_types' => [],
                'person_names' => [],
                'property_concepts' => [],
                'search_query' => 'team members roles',
                'possessive_words' => [],
                'intent' => 'list',
                'question_type' => 'data_query',
                'clarified_question' => '',
                'search_tags' => [],
                'language' => 'fr',
            ];
        });
        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldReceive('startTimer')->andReturn(null)->byDefault();
        $logger->shouldReceive('stopTimer')->andReturn(0.0)->byDefault();
        $logger->shouldReceive('info')->byDefault();
        $logger->shouldReceive('debug')->byDefault();
        $logger->shouldReceive('error')->byDefault();

        return (new QueryUnderstandingService($llm, $logger, []))
            ->analyze('Peux-tu me donner un Excel des membres de l\'équipe avec leurs rôles ?', []);
    }

    /** @test */
    public function the_prompt_asks_for_a_subject_label_in_the_questions_language(): void
    {
        $this->analyze([]);

        $system = $this->prompts[0][0]['content'];
        $this->assertStringContainsString('"subject_label"', $system);
    }

    /** @test */
    public function the_label_is_parsed_trimmed_and_whitespace_collapsed_and_never_replaces_search_query(): void
    {
        $result = $this->analyze(['subject_label' => "  Rôles des membres\n de l'équipe  "]);

        $this->assertSame("Rôles des membres de l'équipe", $result->subjectLabel);
        $this->assertSame('team members roles', $result->searchQuery, 'search_query (semantic search) is untouched');
    }

    /** @test */
    public function a_missing_or_malformed_label_is_empty(): void
    {
        $this->assertSame('', $this->analyze([])->subjectLabel);
        $this->assertSame('', $this->analyze(['subject_label' => null])->subjectLabel);
        $this->assertSame('', $this->analyze(['subject_label' => ['x']])->subjectLabel);
        $this->assertSame('', $this->analyze(['subject_label' => '   '])->subjectLabel);
    }

    /** @test */
    public function an_overlong_label_is_bounded(): void
    {
        $label = $this->analyze(['subject_label' => str_repeat('membres ', 40)])->subjectLabel;

        $this->assertLessThanOrEqual(80, mb_strlen($label));
        $this->assertNotSame('', $label);
    }

    /** @test */
    public function it_is_traced_survives_the_follow_up_copy_and_defaults_to_empty(): void
    {
        $result = $this->analyze(['subject_label' => 'Rôles des membres']);

        $this->assertSame('Rôles des membres', $result->toArray()['subject_label']);
        $this->assertSame('Rôles des membres', $result->asFollowUpOnPreviousResult()->subjectLabel);
        $this->assertSame('', QueryUnderstandingResult::empty()->subjectLabel);
        $this->assertSame('', QueryUnderstandingResult::failed()->subjectLabel);
    }
}
