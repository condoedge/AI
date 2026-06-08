<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat\Exporter;

/**
 * PDF conversation exporter.
 *
 * Exports a whole conversation (one row per message) to PDF via {@see ResultSetExporter},
 * which is GUARDED: when Dompdf is absent it degrades. This service surfaces that degradation
 * by throwing a clear, actionable exception rather than producing nothing.
 *
 * NOTE FOR THE MAIN SESSION: register this in ExporterConversationFactory (config-driven) —
 * this slice intentionally does NOT edit the factory's type map. Add:
 *     'pdf' => ExportConversationPdfService::class,
 */
class ExportConversationPdfService implements ExportConversationServiceInterface
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
                'Role' => $msg->role === 'user' ? 'You' : 'AI Assistant',
                'Time' => optional($msg->created_at)->format('Y-m-d g:i A'),
                'Message' => (string) $msg->content,
            ];
        }

        if (empty($rows)) {
            $rows[] = ['Role' => '', 'Time' => '', 'Message' => ''];
        }

        $title = $conversation->title ?? 'Conversation';
        $result = $this->exporter->toPdf($rows, $title);

        if ($result->isError || !is_array($result->renderable)) {
            throw new \RuntimeException($result->summaryForLlm ?: 'PDF export is unavailable.');
        }

        return $result->renderable['content'];
    }

    public function getMimeType(): string
    {
        return 'application/pdf';
    }

    public function getFileExtension(): string
    {
        return 'pdf';
    }
}
