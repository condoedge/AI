<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions\Attach;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Actions\ActionContext;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\Attach\ActionAttacher;
use Condoedge\Ai\Services\Actions\Attach\AttachedActions;
use Condoedge\Ai\Services\Actions\Attach\NavigationPages;
use Condoedge\Ai\Services\Actions\BuiltIn\LinkAction;
use Condoedge\Ai\Support\RecordingPipelineObserver;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use Mockery;

/**
 * A page may declare sections (ai.navigation.pages[*].sections): the model may pick one
 * section of a chosen page, code keeps it only when that offered page lists it, and the
 * button carries it as `?section=<id>`. Pages without sections keep today's call unchanged.
 */
class NavigationSectionsTest extends TestCase
{
    use AttachFixtures;

    private const QUESTION = 'Où changer le nom de ma campagne?';
    private const ANSWER = 'Le nom se change dans « Paramètres » [1].';

    /** @var list<array> */
    private array $sent = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->configureCatalog();
        config(['logging.default' => 'null']);
        Route::get('/my-campaign/settings', fn () => 'ok')->name('campaign.page.settings');
        Route::get('/my-campaign/transactions', fn () => 'ok')->name('campaign.transactions');
        Route::get('/my-campaign/products', fn () => 'ok')->name('campaign.page.products');
        Route::getRoutes()->refreshNameLookups();
        app('translator')->addLines([
            'menu.settings' => 'Paramètres',
            'campaign.campaign-name' => 'Nom de la campagne',
            'campaign.tax-receipts' => 'Reçus fiscaux',
            'campaign.product-list' => 'Liste des produits',
        ], 'fr');
        app()->setLocale('fr');
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function configurePages(bool $productsOffered = true): void
    {
        config(['ai.navigation' => ['max' => 2, 'pages' => [
            'settings' => ['route' => 'campaign.page.settings', 'label' => 'menu.settings', 'description' => 'Change the campaign settings.',
                'authorize' => fn () => true, 'sections' => [
                    'sec-campaign-campaign-name' => ['label' => 'campaign.campaign-name', 'conditional' => 0],
                    'sec-campaign-tax-receipts' => ['label' => 'campaign.tax-receipts', 'conditional' => 1],
                ]],
            'transactions' => ['route' => 'campaign.transactions', 'label' => 'Transactions', 'description' => 'The orders of the campaign.',
                'authorize' => fn () => true],
            'products' => ['route' => 'campaign.page.products', 'label' => 'Produits', 'description' => 'The campaign products.',
                'authorize' => fn () => $productsOffered, 'sections' => [
                    'sec-campaign-product-list' => ['label' => 'campaign.product-list', 'conditional' => 0],
                ]],
        ]]]);
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

    private function knowledgeTurn(): PipelineContext
    {
        $ctx = $this->turn([], null, ['questionType' => 'knowledge_query']);
        $ctx->question = self::QUESTION;
        $ctx->fileContext = ['relevant_files' => [['file_id' => 'physical:aide/nom.md', 'chunk_index' => 0, 'snippet' => 'Paramètres']], 'file_count' => 1];
        $ctx->attachedActions = AttachedActions::none();
        $ctx->observer = new RecordingPipelineObserver();

        return $ctx;
    }

    private function attach(array $reply): array
    {
        $response = ['question' => self::QUESTION, 'answer' => self::ANSWER,
            'metadata' => ['query' => ['source' => 'file_context'], 'response' => ['style' => 'friendly']]];

        return (new ActionAttacher(new AiActionRegistry(), null, new NavigationPages($this->llm($reply))))
            ->withNavigation($response, $this->knowledgeTurn(), ['user' => $this->resident()])['metadata']['response'];
    }

    /** @test Snapshot of the call before sections existed: CondoEdge declares none. */
    public function pages_without_sections_keep_the_call_byte_identical(): void
    {
        config(['ai.navigation' => ['max' => 2, 'pages' => [
            'transactions' => ['route' => 'campaign.transactions', 'label' => 'Transactions', 'description' => 'The orders of the campaign.', 'authorize' => fn () => true],
            'products' => ['route' => 'campaign.page.products', 'label' => 'Produits', 'description' => 'The campaign products.', 'authorize' => fn () => true],
        ]]]);
        $pages = NavigationPages::offeredTo($this->resident());

        $picked = (new NavigationPages($this->llm(['pages' => ['transactions']])))->pick('Où voir mes ventes?', 'Dans « Transactions ».', $pages);

        $this->assertSame(['transactions' => null], $picked);
        $this->assertSame([
            ['role' => 'system', 'content' => 'You choose which pages of the application to offer as buttons under an answer. '
                . 'Choose only keys from the PAGES list. Choose a page only when the answer sends the user there or tells them to do something '
                . 'that is done there; choose none when no page fits. At most 2, the most useful first. Return JSON: {"pages": [keys]}.'],
            ['role' => 'user', 'content' => "QUESTION: Où voir mes ventes?\n\nANSWER:\nDans « Transactions ».\n\nPAGES:\n"
                . "- transactions: The orders of the campaign.\n- products: The campaign products."],
        ], $this->sent[0]['messages']);
        $this->assertSame([
            'temperature' => 0.0,
            'max_tokens' => 60,
            'json_schema' => ['name' => 'navigation_pages', 'schema' => [
                'type' => 'object',
                'properties' => ['pages' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['transactions', 'products']]]],
                'required' => ['pages'],
                'additionalProperties' => false,
            ]],
        ], $this->sent[0]['options']);
    }

    /** @test */
    public function a_page_lists_its_sections_under_it_and_conditional_ones_are_marked(): void
    {
        $this->configurePages();

        (new NavigationPages($this->llm(['pages' => []])))->pick(self::QUESTION, self::ANSWER, NavigationPages::offeredTo($this->resident()));

        $this->assertStringContainsString("PAGES:\n- settings: Change the campaign settings.\n"
            . "  - sec-campaign-campaign-name: Nom de la campagne\n"
            . "  - sec-campaign-tax-receipts: Reçus fiscaux (not always present)\n"
            . "- transactions: The orders of the campaign.\n"
            . "- products: The campaign products.\n"
            . "  - sec-campaign-product-list: Liste des produits", $this->sent[0]['messages'][1]['content']);
        $this->assertStringContainsString('"section"', $this->sent[0]['messages'][0]['content']);
    }

    /** @test */
    public function the_section_enum_of_a_page_is_its_own_ids_and_null(): void
    {
        $this->configurePages();

        (new NavigationPages($this->llm(['pages' => []])))->pick(self::QUESTION, self::ANSWER, NavigationPages::offeredTo($this->resident()));

        $branches = $this->sent[0]['options']['json_schema']['schema']['properties']['pages']['items']['anyOf'];
        $byPages = [];
        foreach ($branches as $branch) {
            $this->assertSame(['page', 'section'], $branch['required']);
            $this->assertFalse($branch['additionalProperties']);
            $byPages[implode(',', $branch['properties']['page']['enum'])] = $branch['properties']['section'];
        }
        $this->assertSame([
            'transactions' => ['type' => 'null'],
            'settings' => ['type' => ['string', 'null'], 'enum' => ['sec-campaign-campaign-name', 'sec-campaign-tax-receipts', null]],
            'products' => ['type' => ['string', 'null'], 'enum' => ['sec-campaign-product-list', null]],
        ], $byPages);
    }

    /** @test */
    public function an_unknown_section_or_one_of_another_page_is_dropped(): void
    {
        $this->configurePages();

        $picked = (new NavigationPages($this->llm(['pages' => [
            ['page' => 'settings', 'section' => 'sec-campaign-product-list'],
            ['page' => 'products', 'section' => 'sec-invented'],
        ]])))->pick(self::QUESTION, self::ANSWER, NavigationPages::offeredTo($this->resident()));

        $this->assertSame(['settings' => null, 'products' => null], $picked);
    }

    /** @test */
    public function a_section_of_a_page_not_offered_is_never_shown_nor_kept(): void
    {
        $this->configurePages(productsOffered: false);

        $picked = (new NavigationPages($this->llm(['pages' => [['page' => 'products', 'section' => 'sec-campaign-product-list']]])))
            ->pick(self::QUESTION, self::ANSWER, NavigationPages::offeredTo($this->resident()));

        $this->assertSame([], $picked);
        $this->assertStringNotContainsString('sec-campaign-product-list', $this->sent[0]['messages'][1]['content']);
    }

    /** @test */
    public function the_button_carries_the_section_and_reads_page_then_section(): void
    {
        $this->configurePages();

        $metadata = $this->attach(['pages' => [['page' => 'settings', 'section' => 'sec-campaign-campaign-name']]]);

        $this->assertSame(['link|||Paramètres › Nom de la campagne'], $this->chipLines($metadata['invocations']));
        $this->assertSame(['route' => 'campaign.page.settings', 'params' => ['section' => 'sec-campaign-campaign-name'], 'style' => 'button'],
            $metadata['invocations'][0]['params']);
    }

    /** @test */
    public function without_a_section_the_button_is_the_page_alone(): void
    {
        $this->configurePages();

        $metadata = $this->attach(['pages' => [['page' => 'settings', 'section' => null]]]);

        $this->assertSame(['link|||Paramètres'], $this->chipLines($metadata['invocations']));
        $this->assertSame(['route' => 'campaign.page.settings', 'style' => 'button'], $metadata['invocations'][0]['params']);
    }

    /** @test */
    public function the_link_action_turns_the_section_into_a_query_string(): void
    {
        $result = (new LinkAction())->handle(
            ['route' => 'campaign.page.settings', 'params' => ['section' => 'sec-campaign-campaign-name'], 'style' => 'button'],
            new ActionContext(text: 'Paramètres › Nom de la campagne')
        );

        $this->assertStringEndsWith('/my-campaign/settings?section=sec-campaign-campaign-name', $result->payload['href'] ?? '');
    }
}
