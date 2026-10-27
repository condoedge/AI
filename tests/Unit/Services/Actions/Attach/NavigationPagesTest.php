<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions\Attach;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\ResolvedAudience;
use Condoedge\Ai\Services\Actions\Attach\NavigationPages;
use Condoedge\Ai\Tests\Fixtures\FixedAudienceResolver;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use Mockery;

/**
 * Buttons come from setup (ai.navigation.pages), never from documents: code keeps the
 * pages this user may open now — their audience, a required authorize, a route that
 * exists and needs no parameter — and the model picks at most `max` of them by key.
 */
class NavigationPagesTest extends TestCase
{
    /** @var list<array> */
    private array $sent = [];

    public function setUp(): void
    {
        parent::setUp();
        Route::get('/my-campaign/products', fn () => 'ok')->name('campaign.page.products');
        Route::get('/my-campaign/transactions', fn () => 'ok')->name('campaign.transactions');
        Route::get('/switch-campaign/{id}', fn () => 'ok')->name('switch.campaign');
        Route::get('/user/tax-receipts', fn () => 'ok')->name('user.tax-receipts');
        Route::getRoutes()->refreshNameLookups();

        FixedAudienceResolver::$next = new ResolvedAudience('organisateur', 'owner');
        config([
            'ai.audiences' => ['resolver' => FixedAudienceResolver::class, 'map' => ['organisateur' => ['documents' => ['organisateur']]]],
            'ai.navigation' => ['max' => 2, 'pages' => [
                'products' => ['route' => 'campaign.page.products', 'label' => 'Produits', 'description' => 'Add or edit the campaign products.',
                    'audiences' => ['organisateur'], 'authorize' => fn ($user) => true],
                'transactions' => ['route' => 'campaign.transactions', 'label' => 'Transactions', 'description' => 'The orders of the campaign.',
                    'audiences' => ['organisateur'], 'authorize' => fn ($user) => ($user->role ?? null) === 'owner'],
                'no_gate' => ['route' => 'campaign.page.products', 'label' => 'X', 'description' => 'No authorize: never offered.'],
                'switch' => ['route' => 'switch.campaign', 'label' => 'Switch', 'description' => 'Needs an id.', 'authorize' => fn () => true],
                'missing' => ['route' => 'does.not.exist', 'label' => 'Gone', 'description' => 'No such route.', 'authorize' => fn () => true],
                'receipts' => ['route' => 'user.tax-receipts', 'label' => 'Reçus', 'description' => 'Buyer receipts.',
                    'audiences' => ['acheteur'], 'authorize' => fn () => true],
            ]],
        ]);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function llm(array $reply): LlmProviderInterface
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->andReturnUsing(function (array $messages, array $options) use ($reply) {
            $this->sent[] = ['messages' => $messages, 'options' => $options];

            return $reply;
        });

        return $llm;
    }

    /** @test */
    public function only_the_pages_this_user_may_open_now_are_offered(): void
    {
        $this->assertSame(['products', 'transactions'], array_keys(NavigationPages::offeredTo((object) ['id' => 1, 'role' => 'owner'])));
        $this->assertSame(['products'], array_keys(NavigationPages::offeredTo((object) ['id' => 1, 'role' => 'editor'])));
    }

    /** @test */
    public function the_model_picks_from_the_closed_list_and_code_keeps_valid_unique_keys_up_to_max(): void
    {
        $pages = NavigationPages::offeredTo((object) ['id' => 1, 'role' => 'owner']);
        $picked = (new NavigationPages($this->llm(['pages' => ['transactions', 'invented', 'transactions', 'products', 'products']])))
            ->pick('Où voir mes ventes?', 'Les ventes se voient dans « Transactions ».', $pages);

        $this->assertSame(['transactions' => null, 'products' => null], $picked);
        $schema = $this->sent[0]['options']['json_schema']['schema'];
        $this->assertSame(['products', 'transactions'], $schema['properties']['pages']['items']['enum']);
        $this->assertStringContainsString('transactions: The orders of the campaign.', $this->sent[0]['messages'][1]['content']);
    }

    /** @test A cached config cannot hold closures: authorize may be a [class, method] pair. */
    public function authorize_may_be_a_class_method_pair(): void
    {
        config(['ai.navigation.pages' => [
            'products' => ['route' => 'campaign.page.products', 'label' => 'Produits', 'description' => 'Products.',
                'authorize' => [NavigationGateFixture::class, 'owner']],
        ]]);

        $this->assertSame(['products'], array_keys(NavigationPages::offeredTo((object) ['id' => 1, 'role' => 'owner'])));
        $this->assertSame([], NavigationPages::offeredTo((object) ['id' => 1, 'role' => 'editor']));
    }

    /** @test A label may be the host's translation key: the button shows the user's own UI label. */
    public function labels_go_through_the_translator(): void
    {
        app('translator')->addLines(['menu.products' => 'Products'], 'en');
        app()->setLocale('en');
        config(['ai.navigation.pages.products.label' => 'menu.products']);

        $this->assertSame('Products', NavigationPages::offeredTo((object) ['id' => 1, 'role' => 'owner'])['products']['label']);
    }

    /** @test */
    public function no_page_to_offer_means_no_call(): void
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldNotReceive('chatJson');

        $this->assertSame([], (new NavigationPages($llm))->pick('Q', 'A', []));
    }
}

class NavigationGateFixture
{
    public function owner(object $user): bool
    {
        return ($user->role ?? null) === 'owner';
    }
}
