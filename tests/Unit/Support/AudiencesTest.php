<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Support;

use Condoedge\Ai\DTOs\ResolvedAudience;
use Condoedge\Ai\Support\Audiences;
use Condoedge\Ai\Tests\Fixtures\FixedAudienceResolver;
use Condoedge\Ai\Tests\TestCase;

/**
 * Who is asking, as the host sees it (ai.audiences): code decides which documents they
 * may read and how the answer frames their role — never the model.
 */
class AudiencesTest extends TestCase
{
    private function configure(?ResolvedAudience $resolved): void
    {
        FixedAudienceResolver::$next = $resolved;
        config(['ai.audiences' => [
            'resolver' => FixedAudienceResolver::class,
            'map' => [
                'staff' => ['documents' => ['staff', 'buyer'], 'insider_voice' => true, 'prompt' => 'Works for the organisation; role {role}.', 'example_questions' => ['How do I add a product?']],
                'buyer' => ['documents' => ['buyer'], 'insider_voice' => false, 'prompt' => 'Buys from the organisation.'],
            ],
        ]]);
    }

    /** @test */
    public function without_configuration_nothing_is_filtered_or_said(): void
    {
        $this->assertNull(Audiences::of((object) ['id' => 1]));
        $this->assertNull(Audiences::documents((object) ['id' => 1]));
        $this->assertSame('', Audiences::prompt((object) ['id' => 1]));
        $this->assertTrue(Audiences::insiderVoice((object) ['id' => 1]));
        $this->assertNull(Audiences::exampleQuestions((object) ['id' => 1]));
    }

    /** @test */
    public function a_resolved_audience_reads_its_documents_prompt_and_voice(): void
    {
        $this->configure(new ResolvedAudience('staff', 'owner'));

        $this->assertSame('staff', Audiences::of((object) ['id' => 1])->key);
        $this->assertSame(['staff', 'buyer'], Audiences::documents((object) ['id' => 1]));
        $this->assertSame('Works for the organisation; role owner.', Audiences::prompt((object) ['id' => 1]));
        $this->assertTrue(Audiences::insiderVoice((object) ['id' => 1]));
        $this->assertSame(['How do I add a product?'], Audiences::exampleQuestions((object) ['id' => 1]));

        $this->configure(new ResolvedAudience('buyer'));
        $this->assertFalse(Audiences::insiderVoice((object) ['id' => 1]));
        $this->assertNull(Audiences::exampleQuestions((object) ['id' => 1]), 'no list of its own: the chat default');
    }

    /** @test */
    public function an_unresolved_or_unknown_audience_reads_only_the_documents_written_for_everyone(): void
    {
        $this->configure(null);
        $this->assertSame([], Audiences::documents(null));
        $this->assertSame('', Audiences::prompt(null));

        $this->configure(new ResolvedAudience('nobody'));
        $this->assertSame([], Audiences::documents((object) ['id' => 1]));
    }

    /** @test */
    public function data_entities_are_the_audiences_list_or_null_for_no_limit(): void
    {
        $this->assertNull(Audiences::dataEntities((object) ['id' => 1]), 'unconfigured: no limit');

        $this->configure(new ResolvedAudience('buyer'));
        config()->set('ai.audiences.map.buyer.data_entities', ['Meeting', 'Motion', 7]);
        $this->assertSame(['Meeting', 'Motion'], Audiences::dataEntities((object) ['id' => 1]));

        config()->set('ai.audiences.map.buyer.data_entities', []);
        $this->assertSame([], Audiences::dataEntities((object) ['id' => 1]), 'an empty list reads nothing');

        config()->set('ai.audiences.map.buyer.data_entities', null);
        $this->assertNull(Audiences::dataEntities((object) ['id' => 1]));
    }

    /** @test */
    public function a_resolver_that_fails_reads_as_unresolved(): void
    {
        config(['ai.audiences' => ['resolver' => 'App\\Missing\\Resolver', 'map' => ['staff' => ['documents' => ['staff']]]]]);

        $this->assertSame([], Audiences::documents((object) ['id' => 1]));
    }
}
