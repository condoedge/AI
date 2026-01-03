<?php
// src/Kompo/Modals/ChatSettingsModal.php

namespace Condoedge\Ai\Kompo\Modals;

use Condoedge\Ai\Kompo\AiChatPanel;
use Condoedge\Ai\Kompo\Traits\HasChatSettings;
use Condoedge\Ai\Kompo\Traits\HasChatTheme;
use Condoedge\Ai\Kompo\Traits\HasMethodsAsProperties;
use Condoedge\Ai\Models\AiUserSetting;
use Condoedge\Ai\Services\UI\ChatThemeFactoryInterface;
use Condoedge\Utils\Kompo\Common\Modal;

/**
 * Chat Settings Modal - Configure chat preferences.
 */
class ChatSettingsModal extends Modal
{
    use HasChatSettings, HasChatTheme, HasMethodsAsProperties;

    protected $_Title = 'ai.settings.title';
    public $class = 'overflow-hidden max-w-lg rounded-2xl';

    public $model = AiUserSetting::class;

    protected $noHeaderButtons = true;

    public function created() 
    {
        if (!$this->model->id) {
            $this->model(auth()->check() ? AiUserSetting::forUser(auth()->id()) : new AiUserSetting());
        }
    }

    public function beforeSave()
    {
        $this->model->user_id = auth()->id() ?? null;
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
                $this->sectionHeader(__('ai.settings.theme'), __('ai.settings.theme-description')),
                $this->themeSelector(),
            ),
            
            // Appearance section
            $this->sectionHeader(__('ai.settings.appearance'), __('ai.settings.appearance-description')),
            _Rows(
                $this->toggleSetting('show_avatars', __('ai.settings.show-avatars'), __('ai.settings.show-avatars-desc')),
                $this->toggleSetting('show_timestamps', __('ai.settings.show-timestamps'), __('ai.settings.show-timestamps-desc')),
                $this->toggleSetting('show_metrics', __('ai.settings.show-metrics'), __('ai.settings.show-metrics-desc')),
            )->class('space-y-3 mb-6'),

            // Features section
            $this->sectionHeader(__('ai.settings.features'), __('ai.settings.features-description')),
            _Rows(
                $this->toggleSetting('show_suggestions', __('ai.settings.follow-up-suggestions'), __('ai.settings.follow-up-suggestions-desc')),
                $this->toggleSetting('enable_copy', __('ai.settings.copy-button'), __('ai.settings.copy-button-desc')),
                $this->toggleSetting('enable_feedback', __('ai.settings.feedback-buttons'), __('ai.settings.feedback-buttons-desc')),
                $this->toggleSetting('enable_regenerate', __('ai.settings.regenerate'), __('ai.settings.regenerate-desc')),
                $this->toggleSetting('enable_edit', __('ai.settings.edit-messages'), __('ai.settings.edit-messages-desc')),
            )->class('space-y-3 mb-6'),

            // Response style section
            $this->sectionHeader(__('ai.settings.response-style'), __('ai.settings.response-style-description')),
            _Select()->name('response_style')
                ->options([
                    'friendly' => __('ai.settings.style-friendly'),
                    'professional' => __('ai.settings.style-professional'),
                    'concise' => __('ai.settings.style-concise'),
                    'detailed' => __('ai.settings.style-detailed'),
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
            $themeOptions[$theme] = __('ai.themes.' . $theme);
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
            _Link(__('ai.settings.reset-defaults'))
                ->class('text-sm text-gray-500 transition-all !bg-none ' . $this->link_hover)
                ->selfPost('resetSettings'),
            _Flex(
                _Link(__('ai.common.cancel'))
                    ->class('px-4 py-2 text-gray-500 hover:text-gray-700 transition-all')
                    ->closeModal(),
                _SubmitButton(__('ai.settings.save-settings'))->icon('check')
                    ->class('px-4 py-2 text-white rounded-xl shadow-lg transition-all' . $this->theme_gradient)
                    ->closeModal()
                    ->refresh(AiChatPanel::ID),
            )->class('gap-3'),
        )->class('px-6 py-4 border-t border-gray-200 bg-gray-50');
    }



    public function afterSave()
    {
        // Sync saved settings to session for immediate effect
        if ($this->model && $this->model->exists) {
            session()->put('ai_chat_settings', [
                'show_avatars' => $this->model->show_avatars,
                'show_timestamps' => $this->model->show_timestamps,
                'show_metrics' => $this->model->show_metrics,
                'show_suggestions' => $this->model->show_suggestions,
                'enable_copy' => $this->model->enable_copy,
                'enable_feedback' => $this->model->enable_feedback,
                'enable_regenerate' => $this->model->enable_regenerate,
                'enable_edit' => $this->model->enable_edit,
                'response_style' => $this->model->response_style,
                'ui_theme' => $this->model->ui_theme,
            ]);
        }
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
