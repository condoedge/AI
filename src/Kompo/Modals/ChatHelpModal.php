<?php
// src/Kompo/Modals/ChatHelpModal.php

namespace Condoedge\Ai\Kompo\Modals;

use Condoedge\Utils\Kompo\Common\Modal;

/**
 * Chat Help Modal - Display help and tips for using the AI chat.
 */
class ChatHelpModal extends Modal
{
    protected $_Title = 'Help & Tips';
    public $class = 'overflow-hidden max-w-2xl rounded-2xl';

    public function body()
    {
        return _Rows(
            $this->tabBar(),
            _Panel($this->gettingStartedTab())->id('help-tab-content')
                ->class('flex-1 overflow-y-auto max-h-[60vh] mini-scroll'),
            $this->modalActions(),
        )->class('h-full flex flex-col');
    }

    protected function tabBar()
    {
        return _Flex(
            _Link('Getting Started')->class($this->tabClass(true))
                ->selfGet('showTab', ['tab' => 'getting-started'])->inPanel('help-tab-content'),
            _Link('Tips & Tricks')->class($this->tabClass(false))
                ->selfGet('showTab', ['tab' => 'tips'])->inPanel('help-tab-content'),
            _Link('Keyboard Shortcuts')->class($this->tabClass(false))
                ->selfGet('showTab', ['tab' => 'shortcuts'])->inPanel('help-tab-content'),
            _Link('FAQ')->class($this->tabClass(false))
                ->selfGet('showTab', ['tab' => 'faq'])->inPanel('help-tab-content'),
        )->class('px-6 py-3 border-b border-gray-200 gap-2 overflow-x-auto');
    }

    protected function tabClass($active)
    {
        $base = 'px-4 py-2 text-sm font-medium rounded-lg transition-all whitespace-nowrap';
        return $active
            ? "$base bg-indigo-100 text-indigo-700"
            : "$base text-gray-500 hover:bg-gray-100";
    }

    public function showTab($tab)
    {
        return match($tab) {
            'tips' => $this->tipsTab(),
            'shortcuts' => $this->shortcutsTab(),
            'faq' => $this->faqTab(),
            default => $this->gettingStartedTab(),
        };
    }

    protected function gettingStartedTab()
    {
        return _Rows(
            $this->helpSection(
                'Welcome to AI Assistant',
                'Your intelligent companion for data exploration and analysis.',
                $this->iconHtml('sparkles')
            ),
            _Rows(
                $this->featureCard('chat-bubble-left-right', 'Natural Conversations', 'Ask questions in plain English. No need for complex queries.'),
                $this->featureCard('document-magnifying-glass', 'Smart Search', 'Search across your data, documents, and history.'),
                $this->featureCard('light-bulb', 'Suggestions', 'Get follow-up questions to dive deeper into topics.'),
                $this->featureCard('clipboard-document', 'Easy Export', 'Export conversations to Markdown for documentation.'),
            )->class('grid grid-cols-2 gap-4 mb-6'),
            $this->helpSection(
                'Getting Started',
                'Try asking questions like:',
                $this->iconHtml('question-mark-circle')
            ),
            _Rows(
                $this->exampleQuery('How many customers do we have?'),
                $this->exampleQuery('Show me last month\'s sales'),
                $this->exampleQuery('What are the top products?'),
            )->class('space-y-2'),
        )->class('p-6');
    }

    protected function tipsTab()
    {
        return _Rows(
            $this->tipCard('Be Specific', 'The more specific your question, the better the answer. "Show sales for Q4 2024" is better than "show sales".', 'target'),
            $this->tipCard('Use Context', 'Reference previous answers. Say "break that down by region" to get more detail.', 'arrows-pointing-out'),
            $this->tipCard('Try Different Styles', 'Use the style selector for different response formats - concise for quick answers, detailed for explanations.', 'adjustments-horizontal'),
            $this->tipCard('Pin Important Chats', 'Pin conversations you want to keep handy. They\'ll appear at the top of your list.', 'star'),
            $this->tipCard('Use Feedback', 'Rate responses with thumbs up/down to help improve future answers.', 'hand-thumb-up'),
            $this->tipCard('Edit & Retry', 'Made a typo? Edit your message and the AI will regenerate its response.', 'pencil-square'),
        )->class('p-6 space-y-4');
    }

    protected function shortcutsTab()
    {
        return _Rows(
            _Html('Keyboard Shortcuts')->class('font-semibold text-gray-800 mb-4'),
            _Rows(
                $this->shortcutRow('Enter', 'Send message'),
                $this->shortcutRow('Shift + Enter', 'New line'),
                $this->shortcutRow('Esc', 'Close modal'),
                $this->shortcutRow('Ctrl + N', 'New conversation'),
                $this->shortcutRow('Ctrl + K', 'Search conversations'),
                $this->shortcutRow('Ctrl + C', 'Copy last response'),
            )->class('space-y-2'),
        )->class('p-6');
    }

    protected function faqTab()
    {
        return _Rows(
            $this->faqItem('How is my data protected?', 'Your conversations are stored securely and only accessible to you. We don\'t share your data with third parties.'),
            $this->faqItem('Can I delete my chat history?', 'Yes! You can delete individual conversations or archive them for later reference.'),
            $this->faqItem('What if the AI gives wrong information?', 'Use the feedback buttons to report incorrect answers. You can also regenerate responses or rephrase your question.'),
            $this->faqItem('Are there usage limits?', 'Usage depends on your plan. Check your account settings for current limits.'),
            $this->faqItem('Can I export my conversations?', 'Yes! Use the export button to download conversations as Markdown files.'),
        )->class('p-6 space-y-4');
    }

    protected function helpSection($title, $description, $icon)
    {
        return _Flex(
            _Html($icon)->class('w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-100 to-purple-100 flex items-center justify-center text-indigo-600 mr-4 flex-shrink-0'),
            _Rows(
                _Html($title)->class('font-semibold text-gray-800'),
                _Html($description)->class('text-sm text-gray-500'),
            ),
        )->class('items-start mb-6');
    }

    protected function featureCard($icon, $title, $description)
    {
        return _Rows(
            _Html($this->iconHtml($icon))->class('w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center mb-2'),
            _Html($title)->class('font-medium text-gray-800 text-sm'),
            _Html($description)->class('text-xs text-gray-500'),
        )->class('p-4 bg-gray-50 rounded-xl hover:bg-gray-100 transition-all');
    }

    protected function exampleQuery($query)
    {
        return _Html('"' . $query . '"')
            ->class('p-3 bg-indigo-50 text-indigo-700 rounded-lg text-sm font-medium');
    }

    protected function tipCard($title, $description, $icon)
    {
        return _Flex(
            _Html($this->iconHtml($icon))->class('w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center mr-4 flex-shrink-0'),
            _Rows(
                _Html($title)->class('font-medium text-gray-800'),
                _Html($description)->class('text-sm text-gray-500'),
            ),
        )->class('items-start p-4 bg-gray-50 rounded-xl');
    }

    protected function shortcutRow($key, $action)
    {
        return _FlexBetween(
            _Html('<kbd class="px-2 py-1 bg-gray-100 border border-gray-200 rounded text-sm font-mono">' . $key . '</kbd>'),
            _Html($action)->class('text-sm text-gray-600'),
        )->class('py-2 border-b border-gray-100');
    }

    protected function faqItem($question, $answer)
    {
        return _Rows(
            _Html($question)->class('font-medium text-gray-800 mb-1'),
            _Html($answer)->class('text-sm text-gray-500'),
        )->class('p-4 bg-gray-50 rounded-xl');
    }

    protected function modalActions()
    {
        return _FlexEnd(
            _Link('Got it!')
                ->class('px-6 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl hover:from-indigo-700 hover:to-purple-700 shadow-lg transition-all')
                ->closeModal(),
        )->class('px-6 py-4 border-t border-gray-200 bg-gray-50');
    }

    protected function iconHtml($name): string
    {
        $icons = [
            'sparkles' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" /></svg>',
            'question-mark-circle' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>',
            'chat-bubble-left-right' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12.5c0 2.5-2.5 4.5-6 4.5s-6-2-6-4.5S10.5 8 14 8s6 2 6 4.5z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 12.5C4 10 6.5 8 10 8" /></svg>',
            'document-magnifying-glass' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 21h7a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v11m0 5l4.879-4.879m0 0a3 3 0 104.243-4.242 3 3 0 00-4.243 4.242z" /></svg>',
            'light-bulb' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" /></svg>',
            'clipboard-document' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>',
            'target' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4-1.79-4-4-4zm0-4c-4.42 0-8 3.58-8 8s3.58 8 8 8 8-3.58 8-8-3.58-8-8-8z" /></svg>',
            'arrows-pointing-out' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" /></svg>',
            'adjustments-horizontal' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75" /></svg>',
            'star' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" /></svg>',
            'hand-thumb-up' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5" /></svg>',
            'pencil-square' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>',
        ];

        return $icons[$name] ?? '';
    }
}
