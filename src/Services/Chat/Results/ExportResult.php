<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat\Results;

/**
 * Immutable result of exporting a conversation.
 *
 * Carries the rendered document plus the metadata the HTTP/presentation layer needs to
 * return a proper download response (correct Content-Type and download filename), so the
 * controller no longer hardcodes "text/markdown" for every exporter
 * (ConversationController.php previously did this regardless of the chosen format).
 *
 * Modeled on Sisc's readonly Result DTOs (e.g. App\Services\Inscriptions\Results\ApprovalResult)
 * and the package's own ChatTurnResult.
 */
final class ExportResult
{
    /**
     * @param string $content  The fully-rendered export document body.
     * @param string $mime      The MIME type for the Content-Type header (from the exporter's getMimeType()).
     * @param string $filename The suggested download filename including extension (from the exporter's getFileExtension()).
     */
    public function __construct(
        public readonly string $content,
        public readonly string $mime,
        public readonly string $filename,
    ) {
    }
}
