<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\SemanticPromptBuilder;
use Condoedge\Ai\Tests\TestCase;

/**
 * E7: the internal query "role note" appended after the identity in the SYSTEM message
 * must be config-driven (ai.prompt.query_role_note) so it can be localized/overridden,
 * rather than a hardcoded English string baked into SemanticPromptBuilder.
 */
class SemanticPromptBuilderRoleNoteTest extends TestCase
{
    private function builder(): SemanticPromptBuilder
    {
        return new SemanticPromptBuilder();
    }

    /** @test */
    public function the_query_role_note_is_taken_from_config(): void
    {
        config(['ai.prompt.query_role_note' => 'TRADUISEZ EN SILENCE LES QUESTIONS DE L\'UTILISATEUR.']);

        ['system' => $system] = $this->builder()->buildPrompts('combien de factures ?', new PipelineContext(), false);

        $this->assertStringContainsString('TRADUISEZ EN SILENCE', $system);
    }

    /** @test */
    public function an_empty_role_note_emits_no_hardcoded_english(): void
    {
        config(['ai.prompt.query_role_note' => '']);

        ['system' => $system] = $this->builder()->buildPrompts('how many invoices?', new PipelineContext(), false);

        // With the note blanked, none of the previously hardcoded English leaks into the system message.
        $this->assertStringNotContainsString('you translate the user', $system);
    }

    /** @test */
    public function the_single_string_build_prompt_entrypoint_is_gone(): void
    {
        // Only the role-split buildPrompts() should remain (E7 / L7-13).
        $this->assertFalse(
            method_exists(SemanticPromptBuilder::class, 'buildPrompt'),
            'buildPrompt() single-string entrypoint must be removed in favor of buildPrompts().'
        );
    }
}
