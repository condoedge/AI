<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\DTOs\PageContext;
use Condoedge\Ai\DTOs\ResolvedAudience;
use Condoedge\Ai\Kompo\Support\WelcomeSuggestions;
use Condoedge\Ai\Services\Settings\UserChatSettings;
use Condoedge\Ai\Tests\Fixtures\FixedAudienceResolver;
use Condoedge\Ai\Tests\TestCase;

/**
 * The welcome suggestions are the asker's audience's own when it has some: a page's
 * examples first, then the audience's, then the chat's global list.
 */
class WelcomeSuggestionsAudienceTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        config([
            'ai.chat.example_questions' => ['Global question?'],
            'ai.chat.page_examples' => ['Campaign' => ['Page question?']],
            'ai.audiences' => [
                'resolver' => FixedAudienceResolver::class,
                'map' => [
                    'buyer' => ['documents' => ['buyer'], 'example_questions' => ['How do I get my receipt?']],
                    'supplier' => ['documents' => ['supplier']],
                ],
            ],
        ]);
    }

    private function suggestions(?string $audience, ?PageContext $page = null): array
    {
        FixedAudienceResolver::$next = $audience !== null ? new ResolvedAudience($audience) : null;

        return WelcomeSuggestions::resolve($page, new UserChatSettings());
    }

    /** @test */
    public function the_audience_list_replaces_the_global_one(): void
    {
        $this->assertSame(['How do I get my receipt?'], $this->suggestions('buyer'));
    }

    /** @test */
    public function an_audience_without_a_list_falls_back_to_the_global_one(): void
    {
        $this->assertSame(['Global question?'], $this->suggestions('supplier'));
        $this->assertSame(['Global question?'], $this->suggestions(null));
    }

    /** @test */
    public function a_page_without_an_entity_is_matched_by_its_route(): void
    {
        config(['ai.chat.page_examples' => ['campaign.page.products' => ['How do I add a product?']]]);

        $this->assertSame(['How do I add a product?'], $this->suggestions('buyer', PageContext::fromArray(['route' => 'campaign.page.products'])));
    }

    /** @test */
    public function a_page_list_still_comes_first(): void
    {
        $this->assertSame(['Page question?'], $this->suggestions('buyer', PageContext::fromArray(['entity_type' => 'Campaign'])));
    }
}
