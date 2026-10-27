<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * The QU prompt's domain examples come from ai.domain.understanding: a host's
 * examples land verbatim where the package's stood, and the instructions
 * around them don't move.
 */
class QueryUnderstandingDomainSlotsTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function system(): string
    {
        $system = null;
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function (array $messages) use (&$system) {
            $system = $messages[0]['content'];

            return ['search_query' => 'x'];
        });
        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldReceive('startTimer')->andReturn(null)->byDefault();
        $logger->shouldReceive('stopTimer')->andReturn(0.0)->byDefault();
        foreach (['info', 'debug', 'warning', 'error'] as $level) {
            $logger->shouldReceive($level)->byDefault();
        }

        (new QueryUnderstandingService($llm, $logger, []))->analyze('Combien de billets ai-je vendus?', []);

        return str_replace("\r\n", "\n", (string) $system);
    }

    /** @test */
    public function the_host_examples_replace_the_package_examples_verbatim(): void
    {
        config(['ai.domain.understanding' => [
            'implicit_entity_example' => 'if asking about "pledge payments", include both the payment entity and the campaign entity',
            'property_concept_examples' => '"goal", "amount raised"',
            'possessive_examples' => '"my campaign", "my sales"',
            'clarified_example' => ['question' => 'How many tickets have I sold?', 'clarified' => 'How many ticket entities are related to me?'],
            'no_owner_example' => ['question' => 'Show me the tickets', 'clarified' => 'Which ticket entities exist?'],
            'knowledge_example' => ['question' => 'Can I refund a donation?', 'clarified' => 'What are the refund rules for donations?'],
        ]]);

        $system = $this->system();

        $this->assertStringContainsString('(e.g., if asking about "pledge payments", include both the payment entity and the campaign entity).', $system);
        $this->assertStringContainsString('property concept strings (e.g., "goal", "amount raised"). Keep each', $system);
        $this->assertStringContainsString('(as in "my campaign", "my sales", etc.)', $system);
        $this->assertStringContainsString('If the question is "How many tickets have I sold?" the clarified_question might be "How many ticket entities are related to me?" This is meant', $system);
        $this->assertStringContainsString('does not state: "Show me the tickets" names no owner (the one who receives the answer is not an owner), so it stays "Which ticket entities exist?" — the user', $system);
        $this->assertStringContainsString("\nBut also it could have a base significance. let's say the user asks \"Can I refund a donation?\" the clarified question might be \"What are the refund rules for donations?\"\n", $system);
        foreach (['rent payments', 'parking', 'my unit', 'tear down a wall'] as $packageText) {
            $this->assertStringNotContainsString($packageText, $system);
        }
    }

    /** @test */
    public function an_empty_knowledge_example_drops_its_line(): void
    {
        config(['ai.domain.understanding.knowledge_example' => ['question' => '', 'clarified' => '']]);

        $system = $this->system();

        $this->assertStringNotContainsString('base significance', $system);
        $this->assertStringContainsString("the user or the user's group appears in the clarified question ONLY when \"possessive_scope\" is not null.\n- \"search_tags\":", $system);
    }
}
