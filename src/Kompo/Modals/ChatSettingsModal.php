<?php
// src/Kompo/Modals/ChatSettingsModal.php

namespace Condoedge\Ai\Kompo\Modals;

use Condoedge\Ai\Kompo\Traits\HasChatTheme;
use Condoedge\Ai\Models\AiUserSetting;
use Condoedge\Ai\Services\UI\ChatThemeFactoryInterface;
use Condoedge\Utils\Kompo\Common\Modal;

/**
 * Chat Settings Modal - Configure chat preferences.
 */
class ChatSettingsModal extends Modal
{
    use HasChatTheme;

    protected $_Title = 'Chat Settings';
    public $class = 'overflow-hidden max-w-lg rounded-2xl';

    public $model = AiUserSetting::class;

    protected $noHeaderButtons = true;

    public function created() 
    {
        if (!$this->model) {
            $this->model(auth()->check() ? AiUserSetting::forUser(auth()->id()) : new AiUserSetting());
        }
    }

    public function body()
    {
        return _Rows(
            $this->settingsForm(),
            $this->modalActions(),
        )->class('h-full flex flex-col');
    }

    protected function settingsForm()
    {
        $factory = app(ChatThemeFactoryInterface::class);
        $themeAllowUserOverrides = property_exists($factory, 'allowUserOverrides') ? $factory->allowUserOverrides : false;

        return _Rows(
            // Theme section
            !$themeAllowUserOverrides ? null : _Rows(
                $this->sectionHeader('Theme', 'Choose your color theme'),
                $this->themeSelector(),
            ),
            
            // Appearance section
            $this->sectionHeader('Appearance', 'How the chat looks'),
            _Rows(
                $this->toggleSetting('show_avatars', 'Show Avatars', 'Display user and AI avatars'),
                $this->toggleSetting('show_timestamps', 'Show Timestamps', 'Display message times'),
                $this->toggleSetting('show_metrics', 'Show Metrics', 'Display response time and confidence'),
            )->class('space-y-3 mb-6'),

            // Features section
            $this->sectionHeader('Features', 'Chat capabilities'),
            _Rows(
                $this->toggleSetting('show_suggestions', 'Follow-up Suggestions', 'Show suggested questions'),
                $this->toggleSetting('enable_copy', 'Copy Button', 'Allow copying AI responses'),
                $this->toggleSetting('enable_feedback', 'Feedback Buttons', 'Rate AI responses'),
                $this->toggleSetting('enable_regenerate', 'Regenerate', 'Allow regenerating responses'),
                $this->toggleSetting('enable_edit', 'Edit Messages', 'Allow editing your messages'),
            )->class('space-y-3 mb-6'),

            // Response style section
            $this->sectionHeader('Response Style', 'How the AI responds'),
            _Select()->name('response_style')
                ->options([
                    'friendly' => 'Friendly - Casual and approachable',
                    'professional' => 'Professional - Formal and business-like',
                    'concise' => 'Concise - Brief and to the point',
                    'detailed' => 'Detailed - Thorough explanations',
                ])
                ->default('friendly')
                ->class('w-full border border-gray-200 rounded-xl p-3 transition-all'),

        )->class('p-6 overflow-y-auto max-h-[60vh] mini-scroll');
    }

    protected function themeSelector()
    {
        $factory = app(ChatThemeFactoryInterface::class);
        $availableThemes = $factory->available();
        $currentTheme = $this->getCurrentTheme();

        $themeOptions = [];

        foreach ($availableThemes as $theme) {
            $themeOptions[$theme] = __('translate.ai.themes.' . $theme);
        }

        return _Select()->name('ui_theme')
            ->options($themeOptions)
            ->value($currentTheme)
            ->class('w-full border border-gray-200 rounded-xl p-3 transition-all mb-6');
    }

    protected function getCurrentTheme(): string
    {
        if (auth()->check()) {
            $settings = AiUserSetting::forUser(auth()->id());
            if ($settings->getThemeName()) {
                return $settings->getThemeName();
            }
        }
        return config('ai.ui.theme', 'indigo');
    }

    protected function sectionHeader($title, $subtitle)
    {
        return _Rows(
            _Html($title)->class('font-semibold text-gray-800'),
            _Html($subtitle)->class('text-xs text-gray-400'),
        )->class('mb-3');
    }

    protected function toggleSetting($key, $label, $description)
    {
        return _FlexBetween(
            _Rows(
                _Html($label)->class('font-medium text-gray-700'),
                _Html($description)->class('text-xs text-gray-400'),
            ),
            _Toggle()->name($key)->checkColor($this->mainHexColor(), '#ECEFF3')
                ->class('flex-shrink-0'),
        )->class('p-3 rounded-xl hover:bg-gray-50 transition-all');
    }

    protected function modalActions()
    {
        return _FlexBetween(
            _Link('Reset to Defaults')
                ->class('text-sm text-gray-500 transition-all !bg-none ' . $this->link_hover)
                ->selfPost('resetSettings'),
            _Flex(
                _Link('Cancel')
                    ->class('px-4 py-2 text-gray-500 hover:text-gray-700 transition-all')
                    ->closeModal(),
                _SubmitButton('Save Settings')->icon('check')
                    ->class('px-4 py-2 text-white rounded-xl shadow-lg transition-all' . $this->theme_gradient)
                    ->closeModal(),
            )->class('gap-3'),
        )->class('px-6 py-4 border-t border-gray-200 bg-gray-50');
    }


    public function resetSettings()
    {
        session()->forget('ai_chat_settings');

        if (auth()->check()) {
            $userSettings = AiUserSetting::forUser(auth()->id());
            $userSettings->setTheme(null);
        }

        return $this->settingsForm();
    }
}
