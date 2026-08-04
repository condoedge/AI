<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryExecutionResult;
use Condoedge\Ai\DTOs\QueryGenerationResult;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\ResponseGenerator;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Self-identity-as-contact guard (structural): when the authenticated user's own
 * email appears in the answer on a line carrying NO "that's-you" marker (marker
 * wordings single-sourced from SelfReferenceNoticeSection::markerPhrases()), the
 * turn gets ONE regeneration with a hard instruction; if the violation persists,
 * a localized "(that's you — your own contact details)" marker is inserted
 * deterministically after the first unmarked occurrence. Flag:
 * metadata self_reference_guard = clean|recovered|patched. Never fires when the
 * email is absent from the answer or no authenticated identity is available.
 */
class ResponseGeneratorSelfReferenceGuardTest extends TestCase
{
    private const EMAIL = 'j.rousseau@novogestion.ca';

    private LlmProviderInterface $llmMock;
    private ResponseGenerator $generator;

    public function setUp(): void
    {
        parent::setUp();

        config(['ai.response_generation.self_reference_guard' => true]);

        $this->llmMock = Mockery::mock(LlmProviderInterface::class);
        $this->generator = new ResponseGenerator($this->llmMock, [
            'default_format' => 'text',
            'default_style' => 'friendly',
            'default_max_length' => 200,
        ]);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function dataContext(): PipelineContext
    {
        $context = new PipelineContext();
        $context->question = 'Can I get information about my team?';
        $context->qu = new QueryUnderstandingResult(questionType: 'data_query');
        $context->queryResult = new QueryGenerationResult(cypher: 'MATCH (t:Team) RETURN t');
        $context->executionResult = new QueryExecutionResult(
            success: true,
            data: [['team' => 'Novo Gestion', 'email' => self::EMAIL]],
        );

        return $context;
    }

    private function guardOptions(): array
    {
        return ['user' => (object) ['id' => 13, 'email' => self::EMAIL]];
    }

    /** @test */
    public function an_answer_marking_the_email_on_the_same_line_is_clean(): void
    {
        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn("Your team's contact on file is " . self::EMAIL . " — that's you.");

        $result = $this->generator->generate($this->dataContext(), $this->guardOptions());

        $this->assertSame('clean', $result['metadata']['self_reference_guard'] ?? null);
    }

    /** @test */
    public function an_unmarked_own_email_triggers_one_retry_which_can_recover(): void
    {
        $calls = [];
        $this->llmMock->shouldReceive('complete')
            ->twice()
            ->andReturnUsing(function ($user) use (&$calls) {
                $calls[] = $user;
                return count($calls) === 1
                    ? 'Your team can be reached at ' . self::EMAIL . '.'
                    : 'The listed email ' . self::EMAIL . " (that's you) is your own.";
            });

        $result = $this->generator->generate($this->dataContext(), $this->guardOptions());

        $this->assertCount(2, $calls, 'Exactly one enforcement retry');
        $this->assertStringContainsString('SELF-REFERENCE', $calls[1]);
        $this->assertStringContainsString(self::EMAIL, $calls[1]);
        $this->assertStringContainsString('Your team can be reached at', $calls[1]);
        $this->assertSame('recovered', $result['metadata']['self_reference_guard'] ?? null);
        $this->assertStringContainsString("that's you", $result['answer']);
    }

    /** @test */
    public function a_persisting_violation_is_patched_deterministically_after_the_first_unmarked_occurrence(): void
    {
        $this->llmMock->shouldReceive('complete')
            ->twice()
            ->andReturn(
                'Reach your team at ' . self::EMAIL . ' any time.',
                'Contact ' . self::EMAIL . ' for questions.'
            );

        $result = $this->generator->generate($this->dataContext(), $this->guardOptions());

        $this->assertSame('patched', $result['metadata']['self_reference_guard'] ?? null);
        $this->assertStringContainsString(
            self::EMAIL . " (that's you — your own contact details)",
            $result['answer']
        );
    }

    /** @test */
    public function a_marker_on_a_different_line_does_not_excuse_an_unmarked_occurrence(): void
    {
        // The observed live failure: "(that's you)" correct in the table AND the
        // same email presented as a contact route two paragraphs later.
        $draft = "| Julie Rousseau (that's you) | lead |\n\n"
            . 'Your team can be reached at ' . self::EMAIL . '.';

        $this->llmMock->shouldReceive('complete')
            ->twice()
            ->andReturn($draft, $draft);

        $result = $this->generator->generate($this->dataContext(), $this->guardOptions());

        $this->assertSame('patched', $result['metadata']['self_reference_guard'] ?? null);
        // The patch lands on the unmarked line, not the already-marked table row.
        $this->assertStringContainsString(
            'reached at ' . self::EMAIL . " (that's you — your own contact details)",
            $result['answer']
        );
    }

    /** @test */
    public function detection_is_case_insensitive_on_the_email(): void
    {
        $this->llmMock->shouldReceive('complete')
            ->twice()
            ->andReturn(
                'Contact J.Rousseau@Novogestion.CA for help.',
                'Contact J.Rousseau@Novogestion.CA for help.'
            );

        $result = $this->generator->generate($this->dataContext(), $this->guardOptions());

        $this->assertSame('patched', $result['metadata']['self_reference_guard'] ?? null);
    }

    /** @test */
    public function it_never_fires_when_the_email_is_absent_from_the_answer(): void
    {
        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn('Your team is Novo Gestion with 100 members.');

        $result = $this->generator->generate($this->dataContext(), $this->guardOptions());

        $this->assertArrayNotHasKey('self_reference_guard', $result['metadata']);
    }

    /** @test */
    public function it_never_fires_without_an_authenticated_identity(): void
    {
        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn('Your team can be reached at ' . self::EMAIL . '.');

        $result = $this->generator->generate($this->dataContext(), []); // no user option, no auth

        $this->assertArrayNotHasKey('self_reference_guard', $result['metadata']);
    }

    /** @test */
    public function the_config_kill_switch_disables_the_guard(): void
    {
        config(['ai.response_generation.self_reference_guard' => false]);

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn('Your team can be reached at ' . self::EMAIL . '.');

        $result = $this->generator->generate($this->dataContext(), $this->guardOptions());

        $this->assertArrayNotHasKey('self_reference_guard', $result['metadata']);
    }

    /** @test */
    public function a_typographic_apostrophe_marker_is_compliant(): void
    {
        // LLMs commonly emit U+2019 ("that’s you") — typographically identical
        // to the straight-apostrophe marker wording. It must count as marked:
        // no retry burned, no duplicate marker inserted.
        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn('The contact on file is ' . self::EMAIL . " — that\u{2019}s you.");

        $result = $this->generator->generate($this->dataContext(), $this->guardOptions());

        $this->assertSame('clean', $result['metadata']['self_reference_guard'] ?? null);
    }

    /** @test */
    public function the_french_typographic_apostrophe_marker_is_compliant(): void
    {
        // "c’est vous" (U+2019) is the default French typography — compliant.
        $context = $this->dataContext();
        $context->qu = new QueryUnderstandingResult(questionType: 'data_query', language: 'fr');

        $this->llmMock->shouldReceive('complete')
            ->once()
            ->andReturn('Le courriel ' . self::EMAIL . " — c\u{2019}est vous.");

        $result = $this->generator->generate($context, $this->guardOptions());

        $this->assertSame('clean', $result['metadata']['self_reference_guard'] ?? null);
    }

    /** @test */
    public function the_french_patch_wording_follows_the_question_language(): void
    {
        $context = $this->dataContext();
        $context->qu = new QueryUnderstandingResult(questionType: 'data_query', language: 'fr');

        $this->llmMock->shouldReceive('complete')
            ->twice()
            ->andReturn(
                'Vous pouvez joindre votre équipe à ' . self::EMAIL . '.',
                'Contactez ' . self::EMAIL . '.'
            );

        $result = $this->generator->generate($context, $this->guardOptions());

        $this->assertSame('patched', $result['metadata']['self_reference_guard'] ?? null);
        $this->assertStringContainsString("c'est vous — vos propres coordonnées", $result['answer']);
    }
}
