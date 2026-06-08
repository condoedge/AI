<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat\Exporter;

/**
 * Xlsx conversation exporter.
 *
 * Exports a whole conversation (one row per message) to .xlsx via {@see ResultSetExporter},
 * which is GUARDED: when PhpSpreadsheet is absent it degrades. This service surfaces that
 * degradation by throwing a clear, actionable exception (the export controller maps the
 * message to the user) rather than silently producing nothing.
 *
 * NOTE FOR THE MAIN SESSION: register this in ExporterConversationFactory (config-driven) —
 * this slice intentionally does NOT edit the factory's type map. Add:
 *     'xlsx' => ExportConversationXlsxService::class,
 */
class ExportConversationXlsxService implements ExportConversationServiceInterface
{
    public function __construct(
        private ?ResultSetExporter $exporter = null,
    ) {
        $this->exporter = $exporter ?? new ResultSetExporter();
    }

    public function export($conversation)
    {
        $rows = [];
        foreach ($conversation->messages as $msg) {
            $rows[] = [
                'role' => $msg->role === 'user' ? 'You' : 'AI Assistant',
                'time' => optional($msg->created_at)->format('Y-m-d g:i A'),
                'content' => (string) $msg->content,
            ];
        }

        if (empty($rows)) {
            $rows[] = ['role' => '', 'time' => '', 'content' => ''];
        }

        $title = $conversation->title ?? 'Conversation';
        $result = $this->exporter->toExcel($rows, $title);

        if ($result->isError || !is_array($result->renderable)) {
            throw new \RuntimeException($result->summaryForLlm ?: 'Excel export is unavailable.');
        }

        return $result->renderable['content'];
    }

    public function getMimeType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function getFileExtension(): string
    {
        return 'xlsx';
    }
}
