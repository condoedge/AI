<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\ResponseSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Response\PiiMasker;
use Condoedge\Ai\Support\UntrustedLabel;

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
     * Default cap for previous-turn excerpts (ai.response_generation.previous_snippet_chars),
     * = file_context.snippet_length, i.e. the whole chunk. A 400 then 1500 cap kept
     * only the START of each chunk — the least relevant articles — and dropped the
     * cited ones a deepening follow-up needed (Tasks 1.27 b / 1.28, live rights t1).
     *
     * Phase 7.5-lite: records that carry the cited chunk's own 'passage' (one
     * article) render it instead; this cap now applies only to legacy records.
     */
    private const DEFAULT_PREVIOUS_SNIPPET_CHARS = 6000;

    /**
     * Only include when there's conversation context with useful data
     */
    public function shouldInclude(PipelineContext $context, array $options = []): bool
    {
        $conversationContext = $context->conversationContext;

        return !empty($conversationContext['last_referenced_files'])
            || !empty($conversationContext['last_result_sample'])
            || !empty($conversationContext['focused_entity']);
    }

    /**
     * Format the conversation context for the response prompt
     */
    public function format(PipelineContext $context, array $options = []): string
    {
        $conversationContext = $context->conversationContext;

        if (empty($conversationContext)) {
            return '';
        }

        $output = $this->header('Conversation Context');

        if (!empty($conversationContext['last_referenced_files'])) {
            $output .= "**Files Referenced in Previous Response:**\n";
            // No literal "[N]" grammar (Phase 8-B): the model echoes it back verbatim.
            $output .= "These carry NO citation number: the bracketed passage numbers of this turn belong only to the RELEVANT FILE CONTENT list. "
                . "Never cite these with a bracketed number; refer to them by file name (the File: line of each block).\n\n";

            foreach ($conversationContext['last_referenced_files'] as $file) {
                $output .= $this->formatPreviousFile((array) $file);
            }

            $output .= "If the user refers to 'the file', 'that file' or similar, they most likely mean one of the previously cited files above.\n\n";
        }

        if (!empty($conversationContext['last_result_sample'])) {
            $output .= "**Previous Query Results Sample:**\n";
            // Identifying fields are masked with the turn's shared tokens (Phase 8 batch A).
            $sample = PiiMasker::for($context)->maskRows((array) $conversationContext['last_result_sample']);
            $output .= "```json\n" . json_encode($sample, JSON_PRETTY_PRINT) . "\n```\n\n";
        }

        if (!empty($conversationContext['focused_entity'])) {
            $output .= "**Current Focus:** {$conversationContext['focused_entity']}\n";

            if (!empty($conversationContext['focused_entity_filter'])) {
                $output .= "**Active Filter:** `{$conversationContext['focused_entity_filter']}`\n";
            }
        }

        return $output;
    }

    /**
     * One previous-turn file, rendered WITHOUT its old [N] number.
     *
     * The previous turn's citation numbers restart at [1] every turn, so
     * re-rendering them next to the current turn's [1], [2]… gives the model two
     * different "[1]" sources (live eval: it cited the wrong source number). The
     * snippet is capped and spotlighted like current retrieved content — it came
     * from an untrusted document and must stay data, never instructions.
     */
    private function formatPreviousFile(array $file): string
    {
        $filename = UntrustedLabel::fileName((string) ($file['name'] ?? $file['filename'] ?? ''));
        $filename = $filename !== '' ? $filename : 'Unknown file';
        $heading = UntrustedLabel::heading((string) ($file['heading'] ?? ''));
        $fileId = $file['id'] ?? $file['file_id'] ?? '';
        $fileId = is_int($fileId) ? (string) $fileId : UntrustedLabel::of(is_scalar($fileId) ? (string) $fileId : '');
        $snippet = trim((string) ($file['snippet'] ?? ''));
        // Phase 7.5-lite: the CITED chunk itself (one article with structure-aware
        // chunking) instead of the head of the ±window snippet — bounded by the
        // chunker's max size, so it is shown whole.
        $passage = trim((string) ($file['passage'] ?? ''));
        $article = $file['article_no'] ?? $file['article_range'] ?? '';
        $article = UntrustedLabel::of(is_scalar($article) ? (string) $article : '', 40);
        $downloadUrl = $file['download_url'] ?? null;
        $canDownload = $file['can_download'] ?? false;

        // The line code writes: nothing of the document on it.
        $output = '- previously cited' . ($fileId !== '' ? " (ID: {$fileId})" : '') . "\n";

        if ($canDownload && $downloadUrl) {
            $output .= "  Download: {$downloadUrl}\n";
        }

        // The file's name and the place of the excerpt are document content (an
        // uploaded file is named and titled by its uploader): inside the fence, with
        // the excerpt — and alone in it when the record holds no excerpt.
        $place = implode(' — ', array_filter([$heading !== '' ? "§ {$heading}" : '', $article !== '' ? "art. {$article}" : '']));
        $body = "File: {$filename}" . ($place !== '' ? "\n{$place}" : '');

        if ($passage !== '') {
            $body .= "\n\n" . $passage;
        } elseif ($snippet !== '') {
            $cap = $this->previousSnippetChars();
            if (mb_strlen($snippet) > $cap) {
                $snippet = rtrim(mb_substr($snippet, 0, $cap)) . '…';
            }
            $body .= "\n\n" . $snippet;
        }
        $output .= $this->spotlight($body, 'retrieved_document', ['source' => 'previous_turn']) . "\n";

        return $output . "\n";
    }

    private function previousSnippetChars(): int
    {
        $configured = config('ai.response_generation.previous_snippet_chars');

        return is_numeric($configured) && (int) $configured > 0
            ? (int) $configured
            : self::DEFAULT_PREVIOUS_SNIPPET_CHARS;
    }
}
