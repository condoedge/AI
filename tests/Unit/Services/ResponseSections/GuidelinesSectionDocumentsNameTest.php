<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\ResponseSections\GuidelinesSection;
use Condoedge\Ai\Tests\TestCase;

/**
 * What the corpus is called when an answer is not in it (ai.domain.response.documents_name):
 * "the available documents" by default; a help center names itself.
 */
class GuidelinesSectionDocumentsNameTest extends TestCase
{
    private function format(): string
    {
        $context = new PipelineContext();
        $context->qu = new QueryUnderstandingResult(questionType: 'knowledge_query');

        return (new GuidelinesSection())->format($context, ['style' => 'friendly']);
    }

    /** @test */
    public function the_default_keeps_the_available_documents(): void
    {
        $this->assertStringContainsString('If the available documents do not contain the answer, say it could not be found in the available documents', $this->format());
    }

    /** @test */
    public function a_host_names_its_corpus(): void
    {
        config(['ai.domain.response.documents_name' => "Coolecto's help articles"]);

        $output = $this->format();

        $this->assertStringContainsString("If Coolecto's help articles do not contain the answer, say it could not be found in Coolecto's help articles", $output);
        $this->assertStringNotContainsString('available documents', $output);
    }
}
