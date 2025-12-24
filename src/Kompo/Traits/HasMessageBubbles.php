<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Traits;

use Condoedge\Ai\Services\Chat\AiChatMessage;
use Condoedge\Ai\Services\Chat\AiChatResponseData;

/**
 * Trait for rendering chat message bubbles.
 * Must be used on a Kompo component that has chat configuration.
 */
trait HasMessageBubbles
{
    /**
     * Render a message bubble based on role.
     */
    protected function renderMessageBubble(AiChatMessage $message, array $config)
    {
        if ($message->isUser()) {
            return $this->renderUserBubble($message, $config);
        }

        return $this->renderAssistantBubble($message, $config);
    }

    /**
     * Render user message bubble.
     */
    protected function renderUserBubble(AiChatMessage $message, array $config)
    {
        $content = [
            _Html(e($message->content))->class('whitespace-pre-wrap'),
        ];

        if ($config['show_timestamp'] ?? false) {
            $content[] = _Html($message->getFormattedTime())
                ->class('text-xs opacity-70 mt-1');
        }

        return _Rows(
            _FlexEnd(
                ($config['show_avatar'] ?? true)
                    ? _Html($this->getUserAvatar())->class('ml-3 order-2 flex-shrink-0')
                    : null,
                _Rows(...$content)
                    ->class('px-4 py-3 rounded-2xl rounded-tr-md max-w-xs bg-level1 text-white shadow-md')
            )->class('items-end gap-0')
        )->class('mb-4');
    }

    /**
     * Render assistant message bubble.
     */
    protected function renderAssistantBubble(AiChatMessage $message, array $config)
    {
        $content = [];

        // Main message content
        if ($config['enable_markdown'] ?? true) {
            $content[] = _Html($this->renderMarkdown($message->content))
                ->class('prose prose-sm max-w-none prose-indigo');
        } else {
            $content[] = _Html(nl2br(e($message->content)))
                ->class('whitespace-pre-wrap');
        }

        // Response data (tables, metrics, lists)
        if ($message->responseData && $message->responseData->data) {
            $dataContent = $this->renderResponseData($message->responseData);
            if ($dataContent) {
                $content[] = $dataContent;
            }
        }

        // Actions
        if ($message->responseData?->hasActions()) {
            $content[] = $this->renderBubbleActions($message->responseData->actions);
        }

        // Suggestions
        if (($config['show_suggestions'] ?? true) && $message->responseData?->hasSuggestions()) {
            $content[] = $this->renderBubbleSuggestions(
                $message->responseData->suggestions,
                $config['panel_id'] ?? 'ai-chat-messages',
                $config['max_suggestions'] ?? 3
            );
        }

        // Footer (timestamp, metrics)
        $footer = $this->renderBubbleFooter($message, $config);
        if ($footer) {
            $content[] = $footer;
        }

        // Action buttons (copy, feedback)
        $messageActions = $this->renderBubbleMessageActions($message, $config);
        if ($messageActions) {
            $content[] = $messageActions;
        }

        return _Rows(
            _Flex(
                ($config['show_avatar'] ?? true)
                    ? _Html($this->getAssistantAvatar())->class('mr-3 flex-shrink-0')
                    : null,
                _Rows(...$content)
                    ->class('group px-4 py-3 max-w-[85%] rounded-2xl rounded-tl-md max-w-2xl bg-white border border-level1 shadow-sm flex-1')
            )->class('items-start gap-0')
        )->class('mb-4');
    }

    /**
     * Render action buttons in bubble.
     */
    protected function renderBubbleActions(array $actions)
    {
        $buttons = array_map(function ($action) {
            $action = (object) $action;
            return _Link($action->label ?? $action->text ?? 'Action')
                ->href($action->url ?? '#')
                ->icon($action->icon ?? 'arrow-top-right-on-square')
                ->class('inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition-all duration-200')
                ->target('_blank');
        }, $actions);

        return _Flex(...$buttons)
            ->class('flex-wrap gap-2 mt-4 pt-3 border-t border-gray-100');
    }

    /**
     * Render suggestion chips in bubble.
     */
    protected function renderBubbleSuggestions(array $suggestions, string $panelId, int $maxSuggestions)
    {
        $chips = array_map(function ($suggestion) use ($panelId) {
            return _Link($suggestion)
                ->class('inline-flex items-center px-3 py-1.5 text-sm rounded-full bg-gray-100 text-gray-700 hover:bg-indigo-100 hover:text-indigo-700 transition-all duration-200 cursor-pointer border border-transparent hover:border-indigo-200')
                ->selfPost('askQuestion', ['question' => $suggestion])
                ->inPanel($panelId);
        }, array_slice($suggestions, 0, $maxSuggestions));

        return _Rows(
            _Html('Related questions:')->class('text-xs font-medium text-gray-400 mb-2'),
            _Flex(...$chips)->class('flex-wrap gap-2')
        )->class('mt-4 pt-3 border-t border-gray-100');
    }

    /**
     * Render bubble footer with timestamp and metrics.
     */
    protected function renderBubbleFooter(AiChatMessage $message, array $config)
    {
        $items = [];

        if ($config['show_timestamp'] ?? false) {
            $items[] = _Html($message->getFormattedTime())
                ->class('text-xs text-gray-400');
        }

        if (($config['show_metrics'] ?? false) && $message->responseData?->executionTimeMs) {
            $items[] = _Flex(
                _Html('⚡')->class('text-xs'),
                _Html("{$message->responseData->executionTimeMs}ms")->class('text-xs text-gray-400')
            )->class('gap-1 items-center');
        }

        if (empty($items)) {
            return null;
        }

        return _Flex(...$items)
            ->class('gap-4 mt-3 pt-2 border-t border-gray-50');
    }

    /**
     * Render message action buttons (copy, feedback).
     */
    protected function renderBubbleMessageActions(AiChatMessage $message, array $config)
    {
        $actions = [];

        if ($config['enable_copy'] ?? true) {
            $actions[] = _Link()
                ->icon('clipboard-document')
                ->class('p-1.5 rounded-lg hover:bg-gray-100 transition-all duration-200 text-gray-400 hover:text-gray-600')
                ->balloon('Copy to clipboard', 'up')
                ->onClick->run("navigator.clipboard.writeText(" . json_encode($message->content) . ")");
        }

        if ($config['enable_feedback'] ?? false) {
            $actions[] = _Link()
                ->icon('hand-thumb-up')
                ->class('p-1.5 rounded-lg hover:bg-green-50 transition-all duration-200 text-gray-400 hover:text-green-600')
                ->balloon('Helpful', 'up')
                ->selfPost('submitFeedback', ['message_id' => $message->id, 'feedback' => 'positive']);

            $actions[] = _Link()
                ->icon('hand-thumb-down')
                ->class('p-1.5 rounded-lg hover:bg-red-50 transition-all duration-200 text-gray-400 hover:text-red-600')
                ->balloon('Not helpful', 'up')
                ->selfPost('submitFeedback', ['message_id' => $message->id, 'feedback' => 'negative']);
        }

        if (empty($actions)) {
            return null;
        }

        return _Flex(...$actions)
            ->class('gap-0.5 mt-2 opacity-0 group-hover:opacity-100 transition-all duration-200');
    }

    /**
     * Render markdown content.
     */
    protected function renderMarkdown(string $content): string
    {
        $content = e($content);

        // Code blocks
        $content = preg_replace('/```(\w+)?\n(.*?)\n```/s', '<pre class="bg-gray-900 text-gray-100 p-3 rounded-lg overflow-x-auto text-sm my-2"><code>$2</code></pre>', $content);

        // Inline code
        $content = preg_replace('/`([^`]+)`/', '<code class="bg-gray-100 text-indigo-600 px-1.5 py-0.5 rounded text-sm font-mono">$1</code>', $content);

        // Bold and italic
        $content = preg_replace('/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $content);
        $content = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $content);
        $content = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $content);

        // Headers
        $content = preg_replace('/^### (.+)$/m', '<h3 class="text-base font-semibold text-gray-800 mt-3 mb-1">$1</h3>', $content);
        $content = preg_replace('/^## (.+)$/m', '<h2 class="text-lg font-semibold text-gray-800 mt-4 mb-2">$1</h2>', $content);
        $content = preg_replace('/^# (.+)$/m', '<h1 class="text-xl font-bold text-gray-900 mt-4 mb-2">$1</h1>', $content);

        // Lists
        $content = preg_replace('/^- (.+)$/m', '<li class="ml-4 list-disc">$1</li>', $content);
        $content = preg_replace('/^(\d+)\. (.+)$/m', '<li class="ml-4 list-decimal">$2</li>', $content);

        // Line breaks
        $content = nl2br($content);

        return $content;
    }

    /**
     * Get user avatar HTML.
     */
    protected function getUserAvatar(): string
    {
        $initial = strtoupper(substr(auth()->user()?->name ?? 'U', 0, 1));
        return <<<HTML
            <span class="w-9 h-9 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white flex items-center justify-center text-sm font-semibold shadow-sm">
                {$initial}
            </span>
        HTML;
    }

    /**
     * Get assistant avatar HTML.
     */
    protected function getAssistantAvatar(): string
    {
        return <<<'HTML'
            <span class="w-9 h-9 rounded-full lg:max-w-md xl:max-w-lg bg-gradient-to-r from-indigo-500 to-purple-600 text-white flex items-center justify-center shadow-sm">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23-.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" />
                </svg>
            </span>
        HTML;
    }

    /**
     * Render response data based on type (table, metric, list, etc.)
     */
    protected function renderResponseData(AiChatResponseData $responseData)
    {
        return match ($responseData->type) {
            AiChatResponseData::TYPE_TABLE => $this->renderTableData($responseData->data),
            AiChatResponseData::TYPE_METRIC => $this->renderMetricData($responseData->data),
            AiChatResponseData::TYPE_LIST => $this->renderListData($responseData->data),
            AiChatResponseData::TYPE_ERROR => $this->renderErrorData($responseData->errorMessage),
            default => null,
        };
    }

    /**
     * Render table data.
     */
    protected function renderTableData(array $data)
    {
        $headers = $data['headers'] ?? [];
        $rows = $data['rows'] ?? [];

        if (empty($rows)) {
            return null;
        }

        // Build header row
        $headerHtml = '<tr>';
        foreach ($headers as $header) {
            $headerHtml .= '<th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">' . e($header) . '</th>';
        }
        $headerHtml .= '</tr>';

        // Build body rows
        $bodyHtml = '';
        foreach ($rows as $row) {
            $bodyHtml .= '<tr>';
            foreach ($row as $cell) {
                $bodyHtml .= '<td class="px-3 py-2 text-sm text-gray-700 border-t border-gray-100">' . e($cell) . '</td>';
            }
            $bodyHtml .= '</tr>';
        }

        $tableHtml = <<<HTML
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">{$headerHtml}</thead>
                <tbody class="bg-white divide-y divide-gray-200">{$bodyHtml}</tbody>
            </table>
        HTML;

        return _Html($tableHtml)->class('mt-3 overflow-x-auto rounded-lg border border-gray-200');
    }

    /**
     * Render metric/card data.
     */
    protected function renderMetricData(array $data)
    {
        $label = $data['label'] ?? '';
        $value = $data['value'] ?? '';
        $icon = $data['icon'] ?? null;
        $trend = $data['trend'] ?? null;

        $trendClass = str_starts_with($trend ?? '', '+') ? 'text-green-600' : 'text-red-600';

        return _Rows(
            _Flex(
                $icon ? _Html($icon)->class('text-2xl mr-2') : null,
                _Html($label)->class('text-sm font-medium text-gray-500')
            )->class('items-center'),
            _Html($value)->class('text-3xl font-bold text-gray-900 mt-1'),
            $trend ? _Html($trend)->class("text-sm font-medium {$trendClass} mt-1") : null
        )->class('mt-3 p-4 bg-gradient-to-br from-indigo-50 to-purple-50 rounded-xl border border-indigo-100');
    }

    /**
     * Render list data.
     */
    protected function renderListData(array $data)
    {
        $items = $data['items'] ?? [];

        if (empty($items)) {
            return null;
        }

        $listItems = array_map(function ($item) {
            $item = (object) $item;
            return _Flex(
                _Rows(
                    _Html($item->title ?? $item->name ?? '')->class('font-medium text-gray-800'),
                    isset($item->description) ? _Html($item->description)->class('text-sm text-gray-500') : null
                )
            )->class('py-2 border-b border-gray-100 last:border-0');
        }, $items);

        return _Rows(...$listItems)->class('mt-3');
    }

    /**
     * Render error data.
     */
    protected function renderErrorData(?string $message)
    {
        if (!$message) {
            return null;
        }

        return _Flex(
            _Html('⚠️')->class('text-lg mr-2'),
            _Html($message)->class('text-sm text-red-700')
        )->class('mt-3 p-3 bg-red-50 border border-red-200 rounded-lg items-center');
    }
}
