<?php

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\Kompo\AiChatPanel;
use Condoedge\Ai\Kompo\AiChatModal;
use Condoedge\Ai\Kompo\AiChatDrawer;
use Condoedge\Ai\Kompo\AiChatScreen;
use Condoedge\Ai\Kompo\AiChatFloating;
use Condoedge\Ai\Kompo\ChatMessageForm;
use Condoedge\Ai\Kompo\ConversationListQuery;
use Condoedge\Ai\Kompo\Modals\EditMessageModal;
use Condoedge\Ai\Kompo\Modals\ChatSettingsModal;
use Condoedge\Ai\Kompo\Modals\ChatHelpModal;
use Condoedge\Ai\Services\Chat\AiChatServiceInterface;
use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Condoedge\Ai\Services\Settings\UserChatSettings;
use Condoedge\Ai\Services\UI\ChatThemeFactoryInterface;
use Condoedge\Ai\Services\UI\ChatThemeInterface;
use Condoedge\Ai\Services\UI\ConfigChatThemeFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * Bootability smoke tests for the chat surfaces and their child components.
 *
 * These verify that every surface (AbstractChatSurface via AiChatModal/Drawer/Screen,
 * plus AiChatFloating) and every chat sub-component can be instantiated and can build
 * their layout without error - the shared body (sidebar + MessagesQuery + chat JS) is
 * exercised through render(). They do NOT test full chat functionality.
 *
 * Pattern (mirrors the green Kompo siblings - MessagesQueryTest / ChatPresenterTest /
 * EditMessageModalTest): extend Orchestra Testbench directly and load ONLY the
 * KompoServiceProvider, so the el-helpers (_Panel/_Rows/...) and request/encryption
 * plumbing the surfaces need are present, while the full AiServiceProvider (which
 * requires an OpenAI API key to boot) is NOT loaded. The few container collaborators
 * the surfaces resolve (theme + settings) are bound to their lightweight concrete
 * implementations, and the AI availability probe is faked. No RefreshDatabase / factory:
 * an in-memory SQLite + the package migrations back the settings/conversation reads, and
 * the acting user is an anonymous in-memory Authenticatable.
 */
class ChatComponentsBootTest extends TestCase
{
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindChatCollaborators();

        $this->user = $this->createTestUser();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Load only Kompo so the surfaces can build their layout (el-helpers, request
     * plumbing) without dragging in the full AI provider that needs an API key to boot.
     */
    protected function getPackageProviders($app): array
    {
        return [
            \Kompo\KompoServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Kompo builds signed interaction targets when rendering links/buttons, so an
        // app encryption key must be present.
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        // In-memory SQLite so settings (AiUserSetting) and conversation lookups resolve.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    /**
     * Bind the lightweight collaborators the surfaces pull from the container. These are
     * the same concrete implementations AiServiceProvider registers, but instantiated
     * directly so we avoid booting the full provider (and its API-key requirement).
     */
    protected function bindChatCollaborators(): void
    {
        $this->app->singleton(ChatThemeFactoryInterface::class, fn () => new ConfigChatThemeFactory());
        $this->app->singleton(
            ChatThemeInterface::class,
            fn ($app) => $app->make(ChatThemeFactoryInterface::class)->create(),
        );
        $this->app->singleton(ChatSettingsInterface::class, fn () => new UserChatSettings());

        // created() probes AI availability; fake it so the surface boots "online".
        $service = Mockery::mock(AiChatServiceInterface::class);
        $service->shouldReceive('isAvailable')->andReturn(true);
        $this->app->instance(AiChatServiceInterface::class, $service);
    }

    protected function createTestUser(): Authenticatable
    {
        return new class implements Authenticatable {
            public $id = 1;
            public $name = 'Test User';
            public $email = 'test@example.com';

            public function getAuthIdentifierName() { return 'id'; }
            public function getAuthIdentifier() { return $this->id; }
            public function getAuthPassword() { return 'password'; }
            public function getAuthPasswordName() { return 'password'; }
            public function getRememberToken() { return null; }
            public function setRememberToken($value) {}
            public function getRememberTokenName() { return 'remember_token'; }
        };
    }

    /** @test */
    public function ai_chat_panel_can_be_instantiated(): void
    {
        $component = new AiChatPanel();

        $this->assertInstanceOf(AiChatPanel::class, $component);
    }

    /** @test */
    public function ai_chat_panel_can_be_instantiated_with_props(): void
    {
        $component = new AiChatPanel(null, [
            'welcome_title' => 'Test Assistant',
            'example_questions' => ['Question 1', 'Question 2'],
        ]);

        $this->assertInstanceOf(AiChatPanel::class, $component);
    }

    /** @test */
    public function ai_chat_modal_can_be_instantiated(): void
    {
        $component = new AiChatModal();

        $this->assertInstanceOf(AiChatModal::class, $component);
    }

    /** @test */
    public function ai_chat_modal_can_be_instantiated_with_props(): void
    {
        $component = new AiChatModal(null, [
            'welcome_title' => 'Test Modal',
        ]);

        $this->assertInstanceOf(AiChatModal::class, $component);
    }

    /** @test */
    public function below_md_every_surface_is_one_column_with_its_conversation_list_behind_a_button(): void
    {
        foreach ([new AiChatModal(), new AiChatDrawer(), new AiChatScreen()] as $surface) {
            $body = json_encode($surface->render());

            $this->assertStringContainsString('ai-chat-surface', $body);
            $this->assertStringContainsString('ai-chat-sidebar', $body, 'The list stays for larger screens.');
            $this->assertStringContainsString('ai-chat-history-open', $body, 'The button that opens it.');
            $this->assertStringContainsString('@media (max-width: 767.98px)', $body, 'Printed inline: no asset build in the host.');
        }
    }

    /** @test */
    public function the_compact_layout_has_no_list_to_open_and_is_full_screen_below_md_too(): void
    {
        config(['ai.chat.layout' => 'compact']);

        $body = json_encode((new AiChatModal())->render());

        $this->assertStringContainsString('ai-chat-surface', $body);
        $this->assertStringNotContainsString('ai-chat-sidebar w-80', $body);
        $this->assertStringNotContainsString('classList.toggle', $body);
    }

    /** @test */
    public function opening_a_conversation_closes_the_list_and_still_selects_it(): void
    {
        $conversation = \Condoedge\Ai\Models\AiConversation::create(['user_id' => $this->user->id, 'title' => 'One']);

        $item = json_decode(json_encode((new ConversationListQuery())->render($conversation)), true);
        $clicks = collect($item['interactions'])->where('interactionType', 'click')->pluck('action.actionType')->all();

        $this->assertSame(['runJs', 'axiosRequest'], $clicks);
    }

    /** @test */
    public function ai_chat_modal_builds_its_shared_body(): void
    {
        // The modal surface should build the shared body (sidebar + messages) without error.
        $component = new AiChatModal();

        $this->assertNotNull($component->render());
    }

    /** @test */
    public function ai_chat_drawer_can_be_instantiated(): void
    {
        $component = new AiChatDrawer();

        $this->assertInstanceOf(AiChatDrawer::class, $component);
    }

    /** @test */
    public function ai_chat_drawer_can_be_instantiated_with_props(): void
    {
        $component = new AiChatDrawer(null, [
            'welcome_title' => 'Test Drawer',
        ]);

        $this->assertInstanceOf(AiChatDrawer::class, $component);
    }

    /** @test */
    public function ai_chat_drawer_builds_its_shared_body(): void
    {
        $component = new AiChatDrawer();

        $this->assertNotNull($component->render());
    }

    /** @test */
    public function ai_chat_screen_can_be_instantiated(): void
    {
        $component = new AiChatScreen();

        $this->assertInstanceOf(AiChatScreen::class, $component);
    }

    /** @test */
    public function ai_chat_screen_can_be_instantiated_with_props(): void
    {
        $component = new AiChatScreen(null, [
            'welcome_title' => 'Test Screen',
        ]);

        $this->assertInstanceOf(AiChatScreen::class, $component);
    }

    /** @test */
    public function ai_chat_screen_builds_its_shared_body(): void
    {
        $component = new AiChatScreen();

        $this->assertNotNull($component->render());
    }

    /** @test */
    public function ai_chat_panel_is_a_modal_surface_alias(): void
    {
        // AiChatPanel must keep working as the modal surface so existing call sites
        // (and the ::ID constant) stay backward-compatible.
        $component = new AiChatPanel();

        $this->assertInstanceOf(AiChatModal::class, $component);
        $this->assertSame('chat-panel', AiChatPanel::ID);
    }

    /** @test */
    public function ai_chat_floating_can_be_instantiated(): void
    {
        $component = new AiChatFloating();

        $this->assertInstanceOf(AiChatFloating::class, $component);
    }

    /** @test */
    public function ai_chat_floating_can_be_instantiated_with_props(): void
    {
        $component = new AiChatFloating(null, [
            'position' => 'bottom-left',
            'size' => 'md',
            'theme' => 'solid',
            'label' => 'Ask AI',
            'pulse' => true,
        ]);

        $this->assertInstanceOf(AiChatFloating::class, $component);
    }

    /** @test */
    public function ai_chat_floating_builds_its_button(): void
    {
        // The floating button surface builds its own chrome (glow + open button) without error.
        $component = new AiChatFloating();

        $this->assertNotNull($component->render());
    }

    /** @test */
    public function ai_chat_floating_opens_the_modal_surface_by_default(): void
    {
        // With no surface configured it defaults to the modal surface (the AiChatPanel alias).
        $component = new AiChatFloating();

        $this->assertInstanceOf(AiChatModal::class, $component->openChat());
    }

    /** @test */
    public function chat_message_form_can_be_instantiated(): void
    {
        $component = new ChatMessageForm();

        $this->assertInstanceOf(ChatMessageForm::class, $component);
    }

    /** @test */
    public function chat_message_form_can_be_instantiated_with_props(): void
    {
        $component = new ChatMessageForm(null, [
            'conversation_id' => 1,
            'panel_id' => 'test-panel',
            'response_style' => 'professional',
        ]);

        $this->assertInstanceOf(ChatMessageForm::class, $component);
    }

    /** @test */
    public function conversation_list_query_can_be_instantiated(): void
    {
        $component = new ConversationListQuery();

        $this->assertInstanceOf(ConversationListQuery::class, $component);
    }

    /** @test */
    public function conversation_list_query_can_be_instantiated_with_props(): void
    {
        $component = new ConversationListQuery(null, [
            'selected_id' => 1,
            'search' => 'test',
            'filter' => 'pinned',
        ]);

        $this->assertInstanceOf(ConversationListQuery::class, $component);
    }

    /** @test */
    public function edit_message_modal_can_be_instantiated(): void
    {
        $component = new EditMessageModal();

        $this->assertInstanceOf(EditMessageModal::class, $component);
    }

    /** @test */
    public function edit_message_modal_can_be_instantiated_with_props(): void
    {
        $component = new EditMessageModal(null, [
            'message_id' => 1,
            'conversation_id' => 1,
        ]);

        $this->assertInstanceOf(EditMessageModal::class, $component);
    }

    /** @test */
    public function chat_settings_modal_can_be_instantiated(): void
    {
        $component = new ChatSettingsModal();

        $this->assertInstanceOf(ChatSettingsModal::class, $component);
    }

    /** @test */
    public function chat_settings_modal_can_be_instantiated_with_props(): void
    {
        $component = new ChatSettingsModal(null, [
            'config' => [
                'show_avatars' => true,
                'show_timestamps' => false,
                'response_style' => 'friendly',
            ],
        ]);

        $this->assertInstanceOf(ChatSettingsModal::class, $component);
    }

    /** @test */
    public function chat_help_modal_can_be_instantiated(): void
    {
        $component = new ChatHelpModal();

        $this->assertInstanceOf(ChatHelpModal::class, $component);
    }
}
