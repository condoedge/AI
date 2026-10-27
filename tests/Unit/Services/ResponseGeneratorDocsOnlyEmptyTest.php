<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Services\ResponseGenerator;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * In docs-only mode nothing is looked up, so an empty turn is "no document answers this",
 * never "no records were found" with a list of record kinds checked.
 */
class ResponseGeneratorDocsOnlyEmptyTest extends TestCase
{
    private ?string $prompt = null;

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function emptyPrompt(array $options): string
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('complete')->andReturnUsing(function (string $prompt) {
            $this->prompt = $prompt;

            return 'Aucun article ne répond à cette question.';
        });
        $llm->shouldReceive('chat')->andReturnUsing(function (array $messages) {
            $this->prompt = (string) end($messages)['content'];

            return 'Aucun article ne répond à cette question.';
        });

        (new ResponseGenerator($llm))->generateEmptyResponse('Combien de billets ai-je vendus?', null, $options + ['checked_subject' => 'tickets sold']);

        return (string) $this->prompt;
    }

    /** @test */
    public function the_docs_only_abstention_is_about_the_documents(): void
    {
        $prompt = $this->emptyPrompt(['docs_only' => true]);

        $this->assertStringContainsString('None of the documents answers this question', $prompt);
        $this->assertStringNotContainsString('No matching records were found', $prompt);
        $this->assertStringNotContainsString('WHAT WAS CHECKED', $prompt);
    }

    /** @test */
    public function without_it_the_abstention_is_unchanged(): void
    {
        $prompt = $this->emptyPrompt([]);

        $this->assertStringContainsString('No matching records were found', $prompt);
        $this->assertStringContainsString('WHAT WAS CHECKED: tickets sold', $prompt);
    }
}
