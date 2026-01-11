<?php

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\Kompo\AiChatPanel;
use Condoedge\Ai\Kompo\AiChatModal;
use Condoedge\Ai\Kompo\AiChatFloating;
use Condoedge\Ai\Kompo\ChatMessageForm;
use Condoedge\Ai\Kompo\ConversationListQuery;
use Condoedge\Ai\Kompo\Modals\EditMessageModal;
use Condoedge\Ai\Kompo\Modals\ChatSettingsModal;
use Condoedge\Ai\Kompo\Modals\ChatHelpModal;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Bootability tests for chat components.
 *
 * These tests verify that components can be instantiated and booted
 * without errors. They don't test full functionality.
 */
class ChatComponentsBootTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test user and authenticate
        $this->actingAs($this->createTestUser());
    }

    protected function createTestUser()
    {
        $userClass = config('auth.providers.users.model', 'App\\Models\\User');

        if (!class_exists($userClass)) {
            // Create a minimal user for testing
            return new class {
                public $id = 1;
                public $name = 'Test User';
                public $email = 'test@example.com';
                public function getKey() { return $this->id; }
                public function getAuthIdentifier() { return $this->id; }
            };
        }

        return $userClass::factory()->create();
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
