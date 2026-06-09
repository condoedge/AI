<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Support;

use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Condoedge\Ai\Services\UI\ChatThemeInterface;
use Condoedge\Utils\Facades\FileModel;

/**
 * Renders the structured "rich data" that hangs off an assistant message: response_data tables /
 * metrics / lists, follow-up suggestion chips, execution metrics, and referenced-file cards.
 *
 * These were previously eight render* helpers on MessagesQuery. They are pure view builders over
 * (theme + settings + data) and carry no Kompo round-trip state, so they live here as a plain
 * collaborator. Interactive chips still call selfPost('askSuggestion', ...) — that method name is
 * resolved by Kompo against the owning MessagesQuery component when the booted tree is traversed,
 * so moving the element construction here does not change the request target.
 *
 * Behavior-preserving: every class string, slice limit (rows→10, list items→5, suggestions via
 * settings()->maxSuggestions()) and markup is copied verbatim from MessagesQuery.
 */
class RichDataRenderer
{
    public function __construct(
        protected ChatThemeInterface $theme,
        protected ChatSettingsInterface $settings,
    ) {
    }

    /**
     * Render the structured response_data block for a message, dispatching on its 'type'.
     * Returns null for unknown / non-array data so callers can skip appending it.
     */
    public function renderRichData($responseData)
    {
        if (!is_array($responseData)) {
            return null;
        }

        $type = $responseData['type'] ?? 'text';

        return match ($type) {
            'table' => $this->renderTableData($responseData),
            'metric' => $this->renderMetricData($responseData),
            'list' => $this->renderListData($responseData),
            default => null,
        };
    }

    public function renderTableData($data)
    {
        $headers = $data['headers'] ?? [];
        $rows = $data['rows'] ?? [];

        if (empty($headers) || empty($rows)) {
            return null;
        }

        $headerHtml = '<tr>' . implode('', array_map(fn($h) =>
            '<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider bg-gray-50">' . e($h) . '</th>',
            $headers)) . '</tr>';

        $rowsHtml = implode('', array_map(fn($row) =>
            '<tr class="hover:bg-gray-50">' . implode('', array_map(fn($cell) =>
                '<td class="px-4 py-2 text-sm text-gray-700 border-t border-gray-100">' . e($cell) . '</td>',
                $row)) . '</tr>',
            array_slice($rows, 0, 10)));

        return _Html('<div class="mt-4 overflow-hidden rounded-lg border border-gray-200"><table class="min-w-full">' . $headerHtml . $rowsHtml . '</table></div>');
    }

    public function renderMetricData($data)
    {
        return _Flex(
            _Html($data['icon'] ?? '📊')->class('text-3xl'),
            _Rows(
                _Html($data['label'] ?? __('ai.common.value'))->class('text-sm text-gray-500'),
                _Flex(
                    _Html($data['value'] ?? '-')->class('text-2xl font-bold text-gray-800'),
                    isset($data['trend']) ? _Badge($data['trend'])
                        ->class(str_contains($data['trend'], '+') ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700') : null,
                )->class('items-center gap-2'),
            ),
        )->class('mt-4 p-4 ' . $this->theme->primaryLightBg() . ' rounded-xl items-center gap-4');
    }

    public function renderListData($data)
    {
        $items = $data['items'] ?? [];
        if (empty($items)) {
            return null;
        }

        $badgeColors = $this->theme->activeBadge();
        $listItems = array_map(fn($item) =>
            _Flex(
                _Html('<span class="w-6 h-6 rounded-full ' . $badgeColors . ' flex items-center justify-center text-xs font-medium flex-shrink-0">' . e($item['icon'] ?? '•') . '</span>'),
                _Rows(
                    _Html(e($item['title'] ?? ''))->class('font-medium text-gray-800'),
                    _Html(e($item['description'] ?? ''))->class('text-sm text-gray-500'),
                ),
            )->class('items-start gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors'),
            array_slice($items, 0, 5)
        );

        return _Rows(...$listItems)->class('mt-4 space-y-2');
    }

    /**
     * Follow-up suggestion chips. Each chip re-asks via selfPost('askSuggestion') on MessagesQuery.
     */
    public function renderSuggestions(array $suggestions)
    {
        $chips = array_map(fn($s) =>
            _Link($s)
                ->class('inline-flex items-center px-3 py-1.5 text-sm rounded-full bg-gray-100 text-gray-700 ' . $this->theme->primaryLightBgHover() . ' transition-all cursor-pointer')
                ->selfPost('askSuggestion', ['question' => $s])->refresh(),
            array_slice($suggestions, 0, $this->settings->maxSuggestions())
        );

        return _Rows(
            _Html(__('ai.messages.follow-up'))->class('text-xs font-medium text-gray-400 mb-2'),
            _Flex(...$chips)->class('flex-wrap gap-2'),
        )->class('mt-4 pt-3 border-t border-gray-100');
    }

    public function renderMetrics($message)
    {
        return _Flex(
            _Html('⚡ ' . $message->execution_time_ms . 'ms')->class('text-xs text-gray-400'),
            $message->confidence_score ? _Html('🎯 ' . round($message->confidence_score * 100) . '%')->class('text-xs text-gray-400') : null,
        )->class('mt-2 gap-3');
    }

    public function renderFileReferences(array $files)
    {
        if (empty($files)) {
            return null;
        }

        $cards = array_map(fn($file) => $this->fileReferenceCard($file), $files);

        return _Rows(
            _Html('📎 Referenced Files:')->class('text-xs font-medium text-gray-400 mb-2'),
            _Flex(...$cards)->class('flex-wrap gap-2'),
        )->class('mt-4 pt-3 border-t border-gray-100');
    }

    protected function fileReferenceCard(array $file)
    {
        $file = FileModel::find($file['id']);
        $type = $file->file_type_enum;
        $icon = $type->icon();
        $isPreviewable = $type->isPreviewable();

        return _Flex(
            _Sax($icon, 16)->class($this->theme->primaryText()),
            _Html($file['name'])->class('text-sm text-gray-700 font-medium'),
        )->class('inline-flex items-center gap-2 px-3 py-2 rounded-lg ' . $this->theme->primaryLightBg() . ' ' . $this->theme->primaryLightBgHover() . ' cursor-pointer transition-all')
         ->balloon(__('ai.messages.click-to-view'), 'up')
         ->when($isPreviewable, fn($link) => $type->getPreviewButton($link, $file)
         );
    }
}
