<?php

namespace Condoedge\Ai\Services\Extractors;

use Condoedge\Ai\Contracts\FileExtractorInterface;
use Condoedge\Ai\Contracts\PdfParserInterface;
use Smalot\PdfParser\Parser;

/**
 * PDF file extractor
 *
 * Extracts text content from PDF files.
 */
class PdfExtractor implements FileExtractorInterface
{
    /**
     * @param PdfParserInterface|null $parser PDF text parser
     */
    public function __construct(
        private ?PdfParserInterface $parser = null,
        private ?Parser $metadataParser = null
    ) {
        $this->parser = $parser ?? new OcrPdfParser();
        $this->metadataParser = $metadataParser ?? new Parser();
    }

    /**
     * {@inheritdoc}
     */
    public function extract(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new \RuntimeException("File not readable: {$filePath}");
        }

        try {
            $text = $this->parser->extractText($filePath);

            // Clean up extracted text
            $text = $this->cleanExtractedText($text);

            return trim($text);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to extract text from PDF: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), $this->getSupportedExtensions());
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedExtensions(): array
    {
        return ['pdf'];
    }

    /**
     * {@inheritdoc}
     */
    public function extractMetadata(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        try {
            $pdf = $this->metadataParser->parseFile($filePath);
            $details = $pdf->getDetails();
            $pages = $pdf->getPages();

            $metadata = [
                'file_size' => filesize($filePath),
                'page_count' => count($pages),
                'title' => $details['Title'] ?? null,
                'author' => $details['Author'] ?? null,
                'subject' => $details['Subject'] ?? null,
                'keywords' => $details['Keywords'] ?? null,
                'creator' => $details['Creator'] ?? null,
                'producer' => $details['Producer'] ?? null,
                'creation_date' => $details['CreationDate'] ?? null,
                'modification_date' => $details['ModDate'] ?? null,
            ];

            // Count approximate words
            $text = $this->parser->extractText($filePath);
            $metadata['word_count'] = str_word_count($text);
            $metadata['character_count'] = strlen($text);

            // Remove null values
            return array_filter($metadata, fn($value) => $value !== null);
        } catch (\Exception $e) {
            return [
                'error' => "Failed to extract metadata: {$e->getMessage()}",
            ];
        }
    }

    /**
     * Clean extracted PDF text
     *
     * @param string $text
     * @return string
     */
    private function cleanExtractedText(string $text): string
    {
        // Ensure valid UTF-8 — PDF parsers can produce invalid byte sequences
        // from non-Unicode font encodings (WinAnsiEncoding, custom ToUnicode maps)
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        // Normalize line endings
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Remove null bytes and control characters except newlines and tabs
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // Remove excessive whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // Remove excessive blank lines (more than 2 consecutive)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Fix common PDF extraction issues
        // Remove soft hyphens
        $text = str_replace("\u{00AD}", '', $text);

        // Fix words split across lines (word- \nword -> word-word)
        $text = preg_replace('/(\w)-\s*\n\s*(\w)/', '$1-$2', $text);

        return $text;
    }
}
