<?php

declare(strict_types=1);

namespace Condoedge\Ai\Kompo\Support;

use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Condoedge\Ai\Services\UI\ChatThemeInterface;
use Condoedge\Utils\Facades\FileModel;
use Condoedge\Utils\Models\Files\FileTypeEnum;

/**
 * Renders the structured "rich data" that hangs off an assistant message: response_data tables /
 * metrics / lists, execution metrics, and referenced-file cards.
 *
 * These were previously render* helpers on MessagesQuery. They are pure view builders over
 * (theme + settings + data) and carry no Kompo round-trip state, so they live here as a plain
 * collaborator. (The per-message follow-up suggestion chips were removed 2026-10-01 — nothing
 * produced them; next steps are code-attached action chips, actions plan B3.)
 *
 * Behavior-preserving: every class string, slice limit (rows→10, list items→5) and markup is
 * copied verbatim from MessagesQuery.
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
            '<th class="px-4 py-2 text-left text-xs font-medium text-gray-700 uppercase tracking-wider bg-gray-50">' . e($h) . '</th>',
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
                _Html($data['label'] ?? __('ai.common.value'))->class('text-sm text-gray-700'),
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
                    _Html(e($item['description'] ?? ''))->class('text-sm text-gray-700'),
                ),
            )->class('items-start gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors'),
            array_slice($items, 0, 5)
        );

        return _Rows(...$listItems)->class('mt-4 space-y-2');
    }

    public function renderMetrics($message)
    {
        return _Flex(
            _Html('⚡ ' . $message->execution_time_ms . 'ms')->class('text-xs text-gray-700'),
            $message->confidence_score ? _Html('🎯 ' . round($message->confidence_score * 100) . '%')->class('text-xs text-gray-700') : null,
        )->class('mt-2 gap-3');
    }

    public function renderFileReferences(array $files)
    {
        if (!config('ai.chat.show_file_references', true)) {
            return null;
        }

        if (!config('ai.chat.show_internal_file_references', true)) {
            $files = array_filter($files, fn ($file) => !$this->isInternalFile($file));
        }
        if (empty($files)) {
            return null;
        }

        $cards = array_map(fn($file) => $this->fileReferenceCard($file), array_values($files));

        return _Rows(
            _Html(__('ai.chat.sources'))->class('text-xs font-medium text-gray-700 mb-2'),
            _Flex(...$cards)->class('flex-wrap gap-2'),
        )->class('mt-4 pt-3 border-t border-gray-100');
    }

    /**
     * One "referenced file" card. A PROJECT file has a string id ("physical:…") and is shown
     * by name; an UPLOADED file has an integer id and gets its preview card. The reference
     * was stored when the answer was written: the file may be gone when the conversation is
     * opened again, and a file name is tenant-supplied text — so the card never assumes the
     * id's type or the row's existence (live 2026-10-08: an integer id here was an Error 500
     * for the whole conversation), and the name is always escaped.
     */
    protected function fileReferenceCard(array $file)
    {
        $id = $file['id'] ?? null;
        $name = e((string) ($file['name'] ?? ''));
        $plain = fn () => _Html($name)->class('text-sm text-gray-700 font-medium');

        if ($this->isInternalFile($file)) {
            return _Html(__('ai.internal-file', ['name' => $name]))->class('text-sm text-gray-700 font-medium');
        }

        $model = is_int($id) || (is_string($id) && $id !== '') ? $this->findFile($id) : null;
        $type = $model?->mime_type ? FileTypeEnum::fromMimeType($model->mime_type) : $model?->file_type_enum;
        if ($model === null || $type === null) {
            return $plain();
        }

        return _Flex(
            _Sax($type->icon(), 16)->class($this->theme->primaryText()),
            _Html(e(implode(' · ', array_filter([(string) ($model->name ?? $file['name'] ?? ''), trim((string) ($file['scope_label'] ?? ''))]))))->class('text-sm text-gray-700 font-medium'),
        )->class('inline-flex items-center gap-2 px-3 py-2 rounded-lg ' . $this->theme->primaryLightBg() . ' ' . $this->theme->primaryLightBgHover() . ' cursor-pointer transition-all')
         ->balloon(__('ai.messages.click-to-view'), 'up')
         ->when($type->isPreviewable(), fn($link) => $type->getPreviewButton($link, $model)
         );
    }

    /** A file of the project itself (help articles, bundled texts): the reader cannot open it. */
    protected function isInternalFile(array $file): bool
    {
        return is_string($file['id'] ?? null) && str_starts_with($file['id'], 'physical:');
    }

    /** The uploaded file's row, or null when it no longer exists. */
    protected function findFile(int|string $id): mixed
    {
        try {
            // The host's own model, as for the access checks: its morph alias is what the file URLs resolve.
            $model = config('ai.file_context.file_model');

            return is_string($model) && class_exists($model) ? $model::find($id) : FileModel::find($id);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
