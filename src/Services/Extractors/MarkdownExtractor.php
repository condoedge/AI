<?php

namespace Condoedge\Ai\Services\Extractors;

use Condoedge\Ai\Contracts\FileExtractorInterface;

/**
 * Markdown file extractor
 *
 * Extracts content from Markdown files (.md, .markdown)
 * with optional preservation of structure
 */
class MarkdownExtractor implements FileExtractorInterface
{
    /**
     * @param bool $preserveStructure Whether to keep markdown formatting
     */
    public function __construct(
        private readonly bool $preserveStructure = true
    ) {}

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

        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new \RuntimeException("Failed to read file: {$filePath}");
        }

        // Normalize line endings
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        if ($this->preserveStructure) {
            // Keep markdown structure but clean up
            $content = $this->cleanMarkdown($content);
        } else {
            // Strip all markdown formatting
            $content = $this->stripMarkdown($content);
        }

        return trim($content);
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
        return ['md', 'markdown', 'mdown'];
    }

    /**
     * {@inheritdoc}
     */
    public function extractMetadata(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);

        // Extract headers
        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches)) {
                $level = strlen($matches[1]);
                $headers[] = [
                    'level' => $level,
                    'text' => trim($matches[2]),
                ];
            }
        }

        // Extract front matter (if exists)
        $frontMatter = $this->extractFrontMatter($content);

        return [
            'file_size' => filesize($filePath),
            'line_count' => count($lines),
            'character_count' => strlen($content),
            'word_count' => str_word_count($content),
            'header_count' => count($headers),
            'headers' => $headers,
            'front_matter' => $frontMatter,
            'has_code_blocks' => (bool) preg_match('/```/', $content),
            'has_links' => (bool) preg_match('/\[.+\]\(.+\)/', $content),
            'has_images' => (bool) preg_match('/!\[.+\]\(.+\)/', $content),
        ];
    }

    /**
     * Clean markdown content while preserving structure
     *
     * @param string $content
     * @return string
     */
    private function cleanMarkdown(string $content): string
    {
        // Remove HTML comments
        $content = preg_replace('/<!--[\s\S]*?-->/', '', $content);

        // Remove excessive blank lines (more than 2 consecutive)
        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        return $content;
    }

    /**
     * Strip all markdown formatting
     *
     * @param string $content
     * @return string
     */
    private function stripMarkdown(string $content): string
    {
        // Remove code blocks
        $content = preg_replace('/```[\s\S]*?```/', '', $content);
        $content = preg_replace('/`[^`]+`/', '', $content);

        // Remove images: ![alt](url)
        $content = preg_replace('/!\[([^\]]*)\]\([^\)]+\)/', '$1', $content);

        // Convert links to just text: [text](url) -> text
        $content = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $content);

        // Remove headers
        $content = preg_replace('/^#{1,6}\s+/m', '', $content);

        // Remove bold/italic
        $content = preg_replace('/(\*\*|__)(.*?)\1/', '$2', $content);
        $content = preg_replace('/(\*|_)(.*?)\1/', '$2', $content);

        // Remove horizontal rules
        $content = preg_replace('/^(-{3,}|\*{3,}|_{3,})$/m', '', $content);

        // Remove list markers
        $content = preg_replace('/^\s*[-*+]\s+/m', '', $content);
        $content = preg_replace('/^\s*\d+\.\s+/m', '', $content);

        // Remove blockquotes
        $content = preg_replace('/^>\s+/m', '', $content);

        // Remove excessive blank lines
        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        return $content;
    }

    /**
     * Extract YAML front matter from markdown
     *
     * @param string $content
     * @return array
     */
    private function extractFrontMatter(string $content): array
    {
        if (!preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $content, $matches)) {
            return [];
        }

        $yaml = $matches[1];
        $frontMatter = [];

        // Simple key-value parsing (basic YAML subset)
        $lines = explode("\n", $yaml);
        foreach ($lines as $line) {
            if (preg_match('/^(\w+):\s*(.+)$/', $line, $match)) {
                $key = $match[1];
                $value = trim($match[2], '"\'');
                $frontMatter[$key] = $value;
            }
        }

        return $frontMatter;
    }
}
