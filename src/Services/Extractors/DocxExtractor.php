<?php

namespace Condoedge\Ai\Services\Extractors;

use Condoedge\Ai\Contracts\FileExtractorInterface;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;

/**
 * DOCX file extractor
 *
 * Extracts text content from Microsoft Word (.docx) files using phpoffice/phpword
 */
class DocxExtractor implements FileExtractorInterface
{
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
            $phpWord = IOFactory::load($filePath);
            $text = $this->extractTextFromDocument($phpWord);

            // Clean up extracted text
            $text = $this->cleanExtractedText($text);

            return trim($text);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to extract text from DOCX: {$e->getMessage()}",
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
        return ['docx'];
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
            $phpWord = IOFactory::load($filePath);
            $properties = $phpWord->getDocInfo();
            $text = $this->extractTextFromDocument($phpWord);

            $metadata = [
                'file_size' => filesize($filePath),
                'title' => $properties->getTitle(),
                'subject' => $properties->getSubject(),
                'creator' => $properties->getCreator(),
                'keywords' => $properties->getKeywords(),
                'description' => $properties->getDescription(),
                'last_modified_by' => $properties->getLastModifiedBy(),
                'created' => $properties->getCreated(),
                'modified' => $properties->getModified(),
                'word_count' => str_word_count($text),
                'character_count' => strlen($text),
                'section_count' => count($phpWord->getSections()),
            ];

            // Remove null/empty values
            return array_filter($metadata, fn($value) => $value !== null && $value !== '');
        } catch (\Exception $e) {
            return [
                'error' => "Failed to extract metadata: {$e->getMessage()}",
            ];
        }
    }

    /**
     * Extract all text from a PhpWord document
     *
     * @param \PhpOffice\PhpWord\PhpWord $phpWord
     * @return string
     */
    private function extractTextFromDocument($phpWord): string
    {
        $text = [];

        foreach ($phpWord->getSections() as $section) {
            $sectionText = $this->extractTextFromContainer($section);
            if (!empty($sectionText)) {
                $text[] = $sectionText;
            }
        }

        return implode("\n\n", $text);
    }

    /**
     * Extract text from a container element
     *
     * @param AbstractContainer $container
     * @return string
     */
    private function extractTextFromContainer(AbstractContainer $container): string
    {
        $text = [];

        foreach ($container->getElements() as $element) {
            $elementText = $this->extractTextFromElement($element);
            if (!empty($elementText)) {
                $text[] = $elementText;
            }
        }

        return implode("\n", $text);
    }

    /**
     * Extract text from a single element
     *
     * @param mixed $element
     * @return string
     */
    private function extractTextFromElement($element): string
    {
        if ($element instanceof Text) {
            return $element->getText();
        }

        if ($element instanceof TextRun) {
            $text = [];
            foreach ($element->getElements() as $textElement) {
                if ($textElement instanceof Text) {
                    $text[] = $textElement->getText();
                }
            }
            return implode('', $text);
        }

        if ($element instanceof AbstractContainer) {
            return $this->extractTextFromContainer($element);
        }

        // For other element types, try to get text if method exists
        if (method_exists($element, 'getText')) {
            return $element->getText();
        }

        return '';
    }

    /**
     * Clean extracted DOCX text
     *
     * @param string $text
     * @return string
     */
    private function cleanExtractedText(string $text): string
    {
        // Normalize line endings
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Remove null bytes and control characters except newlines and tabs
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // Remove excessive whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // Remove excessive blank lines (more than 2 consecutive)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return $text;
    }
}
