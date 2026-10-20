<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\ResponseSections\PrivacyAndSecurityGuidelinesSection;
use PHPUnit\Framework\TestCase;

/**
 * Covers the rewritten privacy/security policy block.
 *
 * The section is pure text (no container needed): it builds a static system
 * policy from a fresh PipelineContext, so we can instantiate it directly.
 */
class PrivacyAndSecurityGuidelinesSectionTest extends TestCase
{
    private function format(): string
    {
        $section = new PrivacyAndSecurityGuidelinesSection();

        return $section->format(new PipelineContext(), []);
    }

    /** @test */
    public function it_does_not_contain_the_irreversible_urgence_mode(): void
    {
        $output = $this->format();

        // The conversation-bricking "URGENCE mode" must be gone entirely.
        $this->assertStringNotContainsStringIgnoringCase('URGENCE', $output);
    }

    /** @test */
    public function it_does_not_brick_or_refuse_all_further_questions(): void
    {
        $output = $this->format();
        $lower = strtolower($output);

        // No language that permanently shuts down / refuses every later request.
        $this->assertStringNotContainsString('refuse to answer any further questions', $lower);
        $this->assertStringNotContainsString('until the situation is resolved', $lower);

        // The only mention of "refuse all" must be the NEGATED, anti-bricking
        // instruction ("do not refuse all further questions"), never an order to do so.
        $this->assertStringNotContainsString('you must refuse all', $lower);
        if (str_contains($lower, 'refuse all')) {
            $this->assertStringContainsString('do not refuse all further questions', $lower);
        }
    }

    /** @test */
    public function it_instructs_to_decline_one_request_and_continue_normally(): void
    {
        $output = $this->format();
        $lower = strtolower($output);

        // Decline-and-continue policy must be present.
        $this->assertStringContainsString('decline that one request', $lower);
        $this->assertStringContainsString('continue', $lower);
        $this->assertStringContainsString('do not refuse all further questions', $lower);
    }

    /** @test */
    public function it_contains_the_anti_fabrication_grounding_line(): void
    {
        $output = $this->format();

        $this->assertStringContainsString(
            'Only state facts present in the provided data and files; never invent names, dates, amounts, identifiers, file contents, or rule outcomes — if the answer is not present, say you could not find it.',
            $output
        );
    }

    /** @test */
    public function it_keeps_the_no_schema_disclosure_rule(): void
    {
        $output = $this->format();
        $lower = strtolower($output);

        // Real rule must survive: never reveal schema / table / column / property / internal identifiers.
        $this->assertStringContainsString('table names', $lower);
        $this->assertStringContainsString('property names', $lower);
        $this->assertStringContainsString('internal identifier', $lower);
        $this->assertStringContainsString('database structure', $lower);
    }

    /** @test */
    public function it_keeps_the_pii_anonymization_rule(): void
    {
        $output = $this->format();
        $lower = strtolower($output);

        $this->assertStringContainsString('anonymize', $lower);
        $this->assertStringContainsString('pii', $lower);
    }

    /** @test */
    public function it_has_no_duplicated_also_never_lines(): void
    {
        $output = $this->format();

        // The old text repeated "Also never ..." several times; the rewrite is deduplicated.
        $this->assertLessThanOrEqual(
            1,
            substr_count(strtolower($output), 'also never'),
            'Privacy policy still contains duplicated "Also never" lines.'
        );
    }

    /** @test */
    public function it_has_no_heredoc_leading_whitespace_noise_tokens(): void
    {
        $output = $this->format();

        // The old block leaked literal "\n\n" tokens from the PHP string; ensure none remain.
        $this->assertStringNotContainsString('\\n\\n', $output);
    }
}
