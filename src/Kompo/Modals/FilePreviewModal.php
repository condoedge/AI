<?php
// src/Kompo/Modals/FilePreviewModal.php

namespace Condoedge\Ai\Kompo\Modals;

use Condoedge\Utils\Kompo\Common\Modal;

/**
 * File Preview Modal - Displays file content referenced in AI responses.
 */
class FilePreviewModal extends Modal
{
    protected $_Title = 'File Preview';
    public $class = 'overflow-hidden max-w-4xl rounded-2xl';
    public $style = 'max-height: 85vh;';

    protected $fileId;
    protected $file;

    public function created()
    {
        $this->fileId = $this->prop('file_id');

        // In a real implementation, you would fetch the file details here
        // For now, we'll show a placeholder or use the file_data prop
        $this->file = $this->prop('file_data') ?? [
            'name' => 'Document',
            'type' => 'file',
            'content' => null,
            'url' => null,
        ];

        $this->_Title = $this->file['name'] ?? 'File Preview';
    }

    public function body()
    {
        $content = $this->renderFileContent();

        return _Rows(
            $this->fileHeader(),
            _Rows($content)->class('flex-1 overflow-y-auto p-6 bg-gray-50 mini-scroll'),
            $this->fileActions(),
        )->class('h-full flex flex-col');
    }

    protected function fileHeader()
    {
        $icon = $this->getFileIcon();

        return _FlexBetween(
            _Flex(
                _Html($icon)->class('w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-100 to-purple-100 flex items-center justify-center text-indigo-600 mr-4'),
                _Rows(
                    _Html($this->file['name'] ?? 'Document')->class('font-semibold text-gray-800 text-lg'),
                    _Flex(
                        _Html($this->getFileTypeLabel())->class('text-sm text-gray-500'),
                        isset($this->file['size']) ? _Html('• ' . $this->formatSize($this->file['size']))->class('text-sm text-gray-400 ml-2') : null,
                    )->class('items-center'),
                ),
            )->class('items-center'),
            _Link()->icon('x-mark')
                ->class('p-2 rounded-xl text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-all')
                ->closeModal(),
        )->class('px-6 py-4 border-b border-gray-200 bg-white');
    }

    protected function renderFileContent()
    {
        $type = $this->file['type'] ?? 'file';
        $content = $this->file['content'] ?? null;
        $url = $this->file['url'] ?? null;

        // Image preview
        if (in_array($type, ['image', 'png', 'jpg', 'jpeg', 'gif', 'webp'])) {
            if ($url) {
                return _Html('<img src="' . e($url) . '" alt="' . e($this->file['name']) . '" class="max-w-full h-auto rounded-lg shadow-sm">');
            }
            return $this->noPreviewAvailable();
        }

        // PDF preview
        if ($type === 'pdf') {
            if ($url) {
                return _Html('<iframe src="' . e($url) . '" class="w-full h-[60vh] rounded-lg border border-gray-200"></iframe>');
            }
            return $this->noPreviewAvailable('PDF preview not available');
        }

        // Text/code preview
        if (in_array($type, ['text', 'txt', 'md', 'json', 'xml', 'csv']) || $content) {
            return _Html('<pre class="bg-gray-900 text-gray-100 p-4 rounded-xl overflow-x-auto text-sm font-mono whitespace-pre-wrap">' . e($content ?? 'No content available') . '</pre>');
        }

        // Default - no preview
        return $this->noPreviewAvailable();
    }

    protected function noPreviewAvailable($message = 'Preview not available for this file type')
    {
        return _Rows(
            _Html($this->getFileIcon())->class('w-20 h-20 text-gray-300 mb-4'),
            _Html($message)->class('text-gray-500 text-center'),
            isset($this->file['url'])
                ? _Link('Download File')->icon('arrow-down-tray')
                    ->class('mt-4 px-4 py-2 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 transition-all')
                    ->href($this->file['url'])
                    ->attr(['download' => $this->file['name'] ?? 'file'])
                : null,
        )->class('flex flex-col items-center justify-center py-12');
    }

    protected function fileActions()
    {
        $actions = [];

        if (isset($this->file['url'])) {
            $actions[] = _Link('Download')->icon('arrow-down-tray')
                ->class('px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl hover:from-indigo-700 hover:to-purple-700 shadow-lg transition-all')
                ->href($this->file['url'])
                ->attr(['download' => $this->file['name'] ?? 'file']);

            $actions[] = _Link('Open in New Tab')->icon('arrow-top-right-on-square')
                ->class('px-4 py-2 border border-gray-200 text-gray-700 rounded-xl hover:bg-gray-50 transition-all')
                ->href($this->file['url'])
                ->attr(['target' => '_blank']);
        }

        $actions[] = _Link('Close')->class('px-4 py-2 text-gray-500 hover:text-gray-700 transition-all')->closeModal();

        return _FlexEnd(...$actions)->class('px-6 py-4 border-t border-gray-200 bg-white gap-3');
    }

    protected function getFileIcon(): string
    {
        $type = $this->file['type'] ?? 'file';

        $icons = [
            'pdf' => '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>',
            'image' => '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>',
            'spreadsheet' => '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>',
        ];

        return $icons[$type] ?? '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>';
    }

    protected function getFileTypeLabel(): string
    {
        $types = [
            'pdf' => 'PDF Document',
            'image' => 'Image',
            'png' => 'PNG Image',
            'jpg' => 'JPEG Image',
            'spreadsheet' => 'Spreadsheet',
            'xlsx' => 'Excel Spreadsheet',
            'csv' => 'CSV File',
            'text' => 'Text File',
            'md' => 'Markdown',
        ];

        return $types[$this->file['type'] ?? 'file'] ?? 'Document';
    }

    protected function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
