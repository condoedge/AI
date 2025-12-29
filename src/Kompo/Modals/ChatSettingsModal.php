<?php
// src/Kompo/Modals/ChatSettingsModal.php

namespace Condoedge\Ai\Kompo\Modals;

use Condoedge\Utils\Kompo\Common\Modal;

/**
 * Chat Settings Modal - Configure chat preferences.
 */
class ChatSettingsModal extends Modal
{
    protected $_Title = 'Chat Settings';
    public $class = 'overflow-hidden max-w-lg rounded-2xl';

    protected array $config = [];

    public function created()
    {
        $this->config = $this->prop('config') ?? [];
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
        return _Rows(
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
                ->value($this->config['response_style'] ?? 'friendly')
                ->class('w-full border border-gray-200 rounded-xl p-3 focus:border-indigo-300 focus:ring-2 focus:ring-indigo-100 transition-all'),

        )->class('p-6 overflow-y-auto max-h-[60vh] mini-scroll');
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
        $isEnabled = $this->config[$key] ?? true;

        return _FlexBetween(
            _Rows(
                _Html($label)->class('font-medium text-gray-700'),
                _Html($description)->class('text-xs text-gray-400'),
            ),
            _Toggle()->name($key)->value($isEnabled)
                ->class('flex-shrink-0'),
        )->class('p-3 rounded-xl hover:bg-gray-50 transition-all');
    }

    protected function modalActions()
    {
        return _FlexBetween(
            _Link('Reset to Defaults')
                ->class('text-sm text-gray-500 hover:text-indigo-600 transition-all')
                ->selfPost('resetSettings'),
            _Flex(
                _Link('Cancel')
                    ->class('px-4 py-2 text-gray-500 hover:text-gray-700 transition-all')
                    ->closeModal(),
                _Button('Save Settings')->icon('check')
                    ->class('px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl hover:from-indigo-700 hover:to-purple-700 shadow-lg transition-all')
                    ->selfPost('saveSettings')
                    ->closeModal(),
            )->class('gap-3'),
        )->class('px-6 py-4 border-t border-gray-200 bg-gray-50');
    }

    public function saveSettings()
    {
        $settings = [
            'show_avatars' => (bool) request('show_avatars'),
            'show_timestamps' => (bool) request('show_timestamps'),
            'show_metrics' => (bool) request('show_metrics'),
            'show_suggestions' => (bool) request('show_suggestions'),
            'enable_copy' => (bool) request('enable_copy'),
            'enable_feedback' => (bool) request('enable_feedback'),
            'enable_regenerate' => (bool) request('enable_regenerate'),
            'enable_edit' => (bool) request('enable_edit'),
            'response_style' => request('response_style') ?? 'friendly',
        ];

        // Store in user preferences or session
        session(['ai_chat_settings' => $settings]);

        // Could also store in user preferences table if available
        // auth()->user()?->update(['ai_chat_settings' => $settings]);
    }

    public function resetSettings()
    {
        session()->forget('ai_chat_settings');

        // Return updated form with defaults
        $this->config = config('ai.chat', []);
        return $this->settingsForm();
    }
}
