<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\PromptSections\QueryRulesSection;
use Condoedge\Ai\Tests\TestCase;

/**
 * The query rules' examples come from ai.domain.query_rules, verbatim.
 */
class QueryRulesSectionDomainSlotsTest extends TestCase
{
    /** @test */
    public function the_host_examples_replace_the_package_examples_verbatim(): void
    {
        config(['ai.domain.query_rules' => [
            'join_example' => 'JOIN Campaign for Order',
            'record_examples' => 'campaign, order, participant',
            'record_id_example' => 'c.id AS campaign_id',
            'row_grain_examples' => 'participants, orders, donations',
        ]]);

        $rules = (new QueryRulesSection())->format('q', new PipelineContext(), ['allowWrite' => false]);

        $this->assertStringContainsString('human-readable names (e.g., JOIN Campaign for Order)', $rules);
        $this->assertStringContainsString('one row per campaign, order, participant…, including', $rules);
        $this->assertStringContainsString("ALSO return each record's id (e.g. c.id AS campaign_id): the system", $rules);
        $this->assertStringContainsString('(the participants, orders, donations… the question enumerates)', $rules);
        foreach (['BoardMember', 'unit_id', 'units, invoices'] as $packageText) {
            $this->assertStringNotContainsString($packageText, $rules);
        }
    }
}
