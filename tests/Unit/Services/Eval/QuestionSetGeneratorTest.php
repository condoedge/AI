<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Eval;

use Condoedge\Ai\Services\Eval\QuestionSetGenerator;
use PHPUnit\Framework\TestCase;

class QuestionSetGeneratorTest extends TestCase
{
    /** @test */
    public function it_includes_page_examples_with_filterable_tags(): void
    {
        $questions = (new QuestionSetGenerator(config: [
            'entities' => [],
            'example_questions' => ['How many records do we have?'],
            'page_examples' => [
                'Unit' => [
                    'Show this unit payment history',
                    'Who owns this unit?',
                ],
            ],
            'business_rules' => [],
            'physical_paths' => [],
        ]))->generate(offline: true);

        $byQuestion = [];
        foreach ($questions as $row) {
            $byQuestion[$row['question']] = $row['tags'];
        }

        $this->assertArrayHasKey('How many records do we have?', $byQuestion);
        $this->assertArrayHasKey('Show this unit payment history', $byQuestion);
        $this->assertContains('page_example', $byQuestion['Show this unit payment history']);
        $this->assertContains('page_context', $byQuestion['Show this unit payment history']);
        $this->assertContains('page:Unit', $byQuestion['Show this unit payment history']);
    }

    /** @test */
    public function duplicate_global_and_page_examples_merge_tags(): void
    {
        $questions = (new QuestionSetGenerator(config: [
            'entities' => [],
            'example_questions' => ['Show recent activity'],
            'page_examples' => [
                'Team' => ['Show recent activity'],
            ],
            'business_rules' => [],
            'physical_paths' => [],
        ]))->generate(offline: true);

        $this->assertCount(1, $questions);
        $this->assertContains('example', $questions[0]['tags']);
        $this->assertContains('page_example', $questions[0]['tags']);
        $this->assertContains('page:Team', $questions[0]['tags']);
    }
}
