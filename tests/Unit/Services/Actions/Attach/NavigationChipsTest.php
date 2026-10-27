<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Actions\Attach;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\Attach\ActionAttacher;
use Condoedge\Ai\Services\Actions\Attach\AttachedActions;
use Condoedge\Ai\Services\Actions\Attach\NavigationPages;
use Condoedge\Ai\Support\RecordingPipelineObserver;
use Condoedge\Ai\Tests\Fixtures\Tasks\BookRoomTask;
use Condoedge\Ai\Tests\Fixtures\Tasks\EditBookingTask;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use Mockery;

/**
 * A document-grounded answer carries up to two page buttons the model picked from the
 * pages setup offers this user; each renders through the built-in `link` action by route
 * name. Without pages configured nothing is called and nothing changes.
 */
class NavigationChipsTest extends TestCase
{
    use AttachFixtures;

    private const ANSWER = 'Les ventes se voient dans « Transactions » [1].';

    public function setUp(): void
    {
        parent::setUp();
        $this->configureCatalog();
        config(['logging.default' => 'null']);
        Route::get('/my-campaign/transactions', fn () => 'ok')->name('campaign.transactions');
        Route::get('/my-campaign/products', fn () => 'ok')->name('campaign.page.products');
        Route::getRoutes()->refreshNameLookups();
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function configurePages(): void
    {
        config(['ai.navigation' => ['max' => 2, 'pages' => [
            'transactions' => ['route' => 'campaign.transactions', 'label' => 'Transactions', 'description' => 'The orders of the campaign.', 'authorize' => fn () => true],
            'products' => ['route' => 'campaign.page.products', 'label' => 'Produits', 'description' => 'The campaign products.', 'authorize' => fn () => true],
        ]]]);
    }

    private function llm(array $pages, int $times = 1): LlmProviderInterface
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->times($times)->andReturn(['pages' => $pages]);

        return $llm;
    }

    private function knowledgeTurn(): PipelineContext
    {
        $ctx = $this->turn([], null, ['questionType' => 'knowledge_query']);
        $ctx->question = 'Où voir mes ventes?';
        $ctx->fileContext = ['relevant_files' => [['file_id' => 'physical:aide/ventes.md', 'chunk_index' => 0, 'snippet' => 'Transactions']], 'file_count' => 1];
        $ctx->attachedActions = AttachedActions::none();
        $ctx->observer = new RecordingPipelineObserver();

        return $ctx;
    }

    private function response(array $responseMetadata = ['style' => 'friendly']): array
    {
        return [
            'question' => 'Où voir mes ventes?',
            'answer' => self::ANSWER,
            'metadata' => ['query' => ['source' => 'file_context'], 'response' => $responseMetadata],
        ];
    }

    /** @test */
    public function the_picked_pages_become_link_buttons_by_route(): void
    {
        $this->configurePages();
        $ctx = $this->knowledgeTurn();

        $response = (new ActionAttacher(new AiActionRegistry(), null, new NavigationPages($this->llm(['transactions']))))
            ->withNavigation($this->response(), $ctx, ['user' => $this->resident()]);
        $invocations = $response['metadata']['response']['invocations'];

        $this->assertSame(['link|||Transactions'], $this->chipLines($invocations));
        $this->assertSame(['route' => 'campaign.transactions', 'style' => 'button'], $invocations[0]['params']);
        $this->assertSame(['next_step'], array_column($response['metadata']['response']['attached_actions'], 'role'));
        $this->assertSame(self::ANSWER, $response['answer']);
        $this->assertContains(NavigationPages::STAGE, array_column($ctx->observer->events(), 'stage'));
    }

    /** @test */
    public function without_pages_configured_nothing_is_called(): void
    {
        $response = (new ActionAttacher(new AiActionRegistry(), null, new NavigationPages($this->llm([], 0))))
            ->withNavigation($this->response(), $this->knowledgeTurn(), ['user' => $this->resident()]);

        $this->assertSame($this->response(), $response);
    }

    /** @test */
    public function an_answer_that_answered_nothing_gets_no_button(): void
    {
        $this->configurePages();

        $response = (new ActionAttacher(new AiActionRegistry(), null, new NavigationPages($this->llm([], 0))))
            ->withNavigation($this->response(['status' => 'error']), $this->knowledgeTurn(), ['user' => $this->resident()]);

        $this->assertArrayNotHasKey('invocations', $response['metadata']['response']);
    }

    private function configureTaskPage(bool $tasksOn = true): void
    {
        $this->configurePages();
        config(['ai.navigation.pages.products.task' => 'book_room']);
        config(['ai.tasks' => ['enabled' => $tasksOn, 'definitions' => [BookRoomTask::class]]]);
    }

    /** @test */
    public function a_picked_page_that_names_a_startable_task_gets_the_start_chip_before_its_button(): void
    {
        $this->configureTaskPage();

        $response = (new ActionAttacher(new AiActionRegistry(), null, new NavigationPages($this->llm(['products']))))
            ->withNavigation($this->response(), $this->knowledgeTurn(), ['user' => $this->resident()]);
        $invocations = $response['metadata']['response']['invocations'];

        $this->assertSame(['start_task|||book.start', 'link|||Produits'], $this->chipLines($invocations));
        $this->assertSame(['key' => 'book_room'], $invocations[0]['params']);
    }

    /** @test */
    public function no_start_chip_when_tasks_are_off_or_the_task_is_not_startable_or_its_page_is_not_picked(): void
    {
        $this->configureTaskPage(false);
        $off = (new ActionAttacher(new AiActionRegistry(), null, new NavigationPages($this->llm(['products']))))
            ->withNavigation($this->response(), $this->knowledgeTurn(), ['user' => $this->resident()]);
        $this->assertSame(['link|||Produits'], $this->chipLines($off['metadata']['response']['invocations']));

        $this->configureTaskPage();
        $this->app->instance(BookRoomTask::class, new class extends BookRoomTask {
            public function startableBy(mixed $user): bool
            {
                return false;
            }
        });
        $refused = (new ActionAttacher(new AiActionRegistry(), null, new NavigationPages($this->llm(['products']))))
            ->withNavigation($this->response(), $this->knowledgeTurn(), ['user' => $this->resident()]);
        $this->assertSame(['link|||Produits'], $this->chipLines($refused['metadata']['response']['invocations']));

        $this->app->forgetInstance(BookRoomTask::class);
        $other = (new ActionAttacher(new AiActionRegistry(), null, new NavigationPages($this->llm(['transactions']))))
            ->withNavigation($this->response(), $this->knowledgeTurn(), ['user' => $this->resident()]);
        $this->assertSame(['link|||Transactions'], $this->chipLines($other['metadata']['response']['invocations']));
    }

    private function configureTwoTaskPage(): void
    {
        $this->configurePages();
        config(['ai.navigation.pages.products.task' => ['edit_booking', 'no_such_task', 'book_room']]);
        config(['ai.tasks' => ['enabled' => true, 'definitions' => [BookRoomTask::class, EditBookingTask::class]]]);
    }

    /** @test */
    public function a_page_with_two_tasks_gets_both_chips_in_order(): void
    {
        $this->configureTwoTaskPage();

        $response = (new ActionAttacher(new AiActionRegistry(), null, new NavigationPages($this->llm(['products']))))
            ->withNavigation($this->response(), $this->knowledgeTurn(), ['user' => $this->resident()]);
        $invocations = $response['metadata']['response']['invocations'];

        $this->assertSame(['start_task|||Change a booking', 'start_task|||book.start', 'link|||Produits'], $this->chipLines($invocations));
        $this->assertSame([['key' => 'edit_booking'], ['key' => 'book_room']], array_column(array_slice($invocations, 0, 2), 'params'));
    }

    /** @test */
    public function a_string_task_still_works(): void
    {
        $this->configureTaskPage();

        $this->assertSame(['book_room'], NavigationPages::offeredTo($this->resident())['products']['task']);
        $response = (new ActionAttacher(new AiActionRegistry(), null, new NavigationPages($this->llm(['products']))))
            ->withNavigation($this->response(), $this->knowledgeTurn(), ['user' => $this->resident()]);
        $this->assertSame(['start_task|||book.start', 'link|||Produits'], $this->chipLines($response['metadata']['response']['invocations']));
    }

    /** @test */
    public function an_unstartable_task_of_the_list_is_skipped(): void
    {
        $this->configureTwoTaskPage();
        $this->app->instance(BookRoomTask::class, new class extends BookRoomTask {
            public function startableBy(mixed $user): bool
            {
                return false;
            }
        });

        $response = (new ActionAttacher(new AiActionRegistry(), null, new NavigationPages($this->llm(['products']))))
            ->withNavigation($this->response(), $this->knowledgeTurn(), ['user' => $this->resident()]);

        $this->assertSame(['start_task|||Change a booking', 'link|||Produits'], $this->chipLines($response['metadata']['response']['invocations']));
    }

    /** A how-to answered from the articles that QU also read as "open my team" (live: "Can you help me creating a campaign in my team?"). */
    private function teamHowToTurn(): array
    {
        config(['ai.attached_actions.identity.team' => ['route' => 'campaign.page.products']]);
        $ctx = $this->knowledgeTurn();
        $ctx->qu = new \Condoedge\Ai\DTOs\QueryUnderstandingResult(questionType: 'knowledge_query', navigationTarget: 'own_team', language: 'en');
        $ctx->attachedActions = (new ActionAttacher(new AiActionRegistry()))->forTurn($ctx, ['user' => $this->resident()]);

        return [$ctx, $this->response($ctx->attachedActions->applyTo(['style' => 'friendly']))];
    }

    /** @test The team link carries no task of its own: what the answer is about decides the task (live: a campaign how-to offered "invite a member"). */
    public function the_team_link_alone_carries_no_start_chip(): void
    {
        $this->configureTaskPage();
        [$ctx] = $this->teamHowToTurn();

        $this->assertSame(['my_team|||My team'], $this->chipLines($ctx->attachedActions->invocations()));
    }

    /** @test */
    public function a_how_to_read_as_open_my_team_still_gets_the_page_it_is_about_with_its_tasks(): void
    {
        $this->configureTaskPage();
        [$ctx, $response] = $this->teamHowToTurn();
        Route::get('/my-campaign/dashboard', fn () => 'ok')->name('campaign.dashboard');
        Route::getRoutes()->refreshNameLookups();
        config(['ai.navigation.pages.transactions.task' => 'book_room']);

        $response = (new ActionAttacher(new AiActionRegistry(), null, new NavigationPages($this->llm(['transactions']))))
            ->withNavigation($response, $ctx, ['user' => $this->resident()]);

        $this->assertSame(['my_team|||My team', 'start_task|||book.start', 'link|||Transactions'], $this->chipLines($response['metadata']['response']['invocations']));
    }

    /** @test The page the team link already opens is not offered twice: only its task chips are added. */
    public function a_picked_page_the_team_link_opens_adds_only_its_tasks(): void
    {
        $this->configureTaskPage();
        [$ctx, $response] = $this->teamHowToTurn();

        $response = (new ActionAttacher(new AiActionRegistry(), null, new NavigationPages($this->llm(['products']))))
            ->withNavigation($response, $ctx, ['user' => $this->resident()]);

        $this->assertSame(['my_team|||My team', 'start_task|||book.start'], $this->chipLines($response['metadata']['response']['invocations']));
    }
}
