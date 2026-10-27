<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Discovery;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\Services\Discovery\AiConfigEnhancer;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * The ai:discover / ai:enhance prompts take their domain examples from
 * ai.domain.discovery; without an app brief they describe the project.
 */
class AiConfigEnhancerDomainSlotsTest extends TestCase
{
    /** @var list<array> */
    private array $sent = [];

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function enhancer(): AiConfigEnhancer
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(function (array $messages) {
            $this->sent[] = $messages;

            return [];
        });

        return new AiConfigEnhancer($llm);
    }

    private function prompts(): string
    {
        $config = ['graph' => ['label' => 'Campaign'], 'metadata' => ['aliases' => ['campaign']]];
        $enhancer = $this->enhancer();
        $enhancer->enhance('App\\Models\\Campaign', $config, [], []);
        $enhancer->generateTestQuestions('App\\Models\\Campaign', $config, 1);
        $enhancer->improveFromFeedback('App\\Models\\Campaign', $config, [['question' => 'q', 'output' => 'o']], 1, 3);

        return json_encode($this->sent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @test */
    public function the_host_examples_land_verbatim(): void
    {
        config(['ai.domain.discovery' => [
            'app_brief' => 'a fundraising platform assistant',
            'persona_examples' => 'organiser vs participant vs buyer',
            'possessive_path_example' => '"my campaign" = User→Team→Campaign',
            'possessive_alias_examples' => '"my campaign", "mes campagnes"',
            'question_examples' => '["How many campaigns are active?"]',
            'relationship_examples' => '{"TEAM": "The organisation running this campaign"}',
            'junction_example' => 'CampaignProduct links Campaign↔Product',
            'possessive_chain_example' => 'User→Team→Campaign (for campaigns)',
        ]]);

        $prompts = $this->prompts();

        foreach ([
            'test questions for a fundraising platform assistant. Return ONLY',
            '(organiser vs participant vs buyer)',
            'possessive queries (\"my campaign\" = User→Team→Campaign)',
            'where applicable (\"my campaign\", \"mes campagnes\")',
            'E.g., [\"How many campaigns are active?\"]',
            'E.g., {\"TEAM\": \"The organisation running this campaign\"}',
            '(e.g., CampaignProduct links Campaign↔Product)',
            'the full chain: User→Team→Campaign (for campaigns), etc.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $prompts);
        }
        foreach (['condo', 'renter', 'ContactUnit', 'units'] as $packageText) {
            $this->assertStringNotContainsString($packageText, $prompts);
        }
    }

    /** @test */
    public function without_an_app_brief_the_project_description_stands_in(): void
    {
        config([
            'ai.domain.discovery.app_brief' => '',
            'ai.project.description' => 'A fundraising platform for schools.',
        ]);

        $this->assertStringContainsString(
            'test questions for an AI assistant for this application: A fundraising platform for schools. Return ONLY',
            $this->prompts()
        );
    }
}
