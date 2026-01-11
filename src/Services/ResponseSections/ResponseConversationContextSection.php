<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

/**
 * ResponseConversationContextSection
 *
 * Adds conversation context to the response generator prompt, including
 * file references from previous responses so the AI can answer follow-up
 * questions about files.
 *
 * Priority 45 places this after FileContextSection (40) but before ResultsData (50).
 */
class ResponseConversationContextSection extends BaseResponseSection
{
    protected string $name = 'conversation_context';
    protected int $priority = 45;

    /**
     * Only include when there's conversation context with useful data
     */
    public function shouldInclude(array $context, array $options = []): bool
    {
        $conversationContext = $context['conversation_context'] ?? [];

        return !empty($conversationContext['last_referenced_files'])
            || !empty($conversationContext['last_result_sample'])
            || !empty($conversationContext['focused_entity']);
    }

    /**
     * Format the conversation context for the response prompt
     */
    public function format(array $context, array $options = []): string
    {
        $conversationContext = $context['conversation_context'] ?? [];

        if (empty($conversationContext)) {
            return '';
        }

        $output = $this->header('Conversation Context');

        // Referenced files from previous response - include full content for follow-ups
        if (!empty($conversationContext['last_referenced_files'])) {
            $output .= "**Files Referenced in Previous Response:**\n\n";

            foreach ($conversationContext['last_referenced_files'] as $index => $file) {
                $refNum = $file['ref'] ?? ($index + 1);
                $filename = $file['name'] ?? $file['filename'] ?? 'Unknown file';
                $fileId = $file['id'] ?? $file['file_id'] ?? '';
                $snippet = $file['snippet'] ?? '';
                $downloadUrl = $file['download_url'] ?? null;
                $canDownload = $file['can_download'] ?? false;

                $output .= "**[{$refNum}] {$filename}** (ID: {$fileId})\n";

                if ($canDownload && $downloadUrl) {
                    $output .= "Download: {$downloadUrl}\n";
                }

                // Include the full snippet/chunk content so AI can reference it
                if (!empty($snippet)) {
                    $output .= "Content:\n```\n{$snippet}\n```\n\n";
                }
            }

            $output .= "**Instructions:** If user asks about 'the file', 'that file', 'raw content', ";
            $output .= "or similar references, use the file content above to answer.\n\n";
        }

        // Previous result sample for context
        if (!empty($conversationContext['last_result_sample'])) {
            $output .= "**Previous Query Results Sample:**\n";
            $output .= "```json\n" . json_encode($conversationContext['last_result_sample'], JSON_PRETTY_PRINT) . "\n```\n\n";
        }

        // Focused entity
        if (!empty($conversationContext['focused_entity'])) {
            $output .= "**Current Focus:** {$conversationContext['focused_entity']}\n";

            if (!empty($conversationContext['focused_entity_filter'])) {
                $output .= "**Active Filter:** `{$conversationContext['focused_entity_filter']}`\n";
            }
        }

        return $output;
    }
}
