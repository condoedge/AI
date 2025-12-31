<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\UI;

/**
 * SafeMarkdownRenderer
 *
 * A minimal, secure markdown renderer for chat messages.
 * Supports: code blocks, inline code, bold, italic, lists, headers, links.
 * All outputs are properly escaped to prevent XSS.
 */
class SafeMarkdownRenderer
{
    private array $theme;

    public function __construct(array $theme = [])
    {
        $this->theme = $theme;
    }

    /**
     * Render markdown to safe HTML
     */
    public function render(string $text): string
    {
        // Store code blocks temporarily to prevent them from being processed
        $codeBlocks = [];
        $inlineCode = [];

        // Extract code blocks first (prevent processing inside them)
        $text = preg_replace_callback(
            '/```(\w*)\n([\s\S]*?)```/',
            function ($matches) use (&$codeBlocks) {
                $lang = $this->sanitizeLanguage($matches[1] ?? '');
                $code = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
                $key = '___CODEBLOCK_' . count($codeBlocks) . '___';
                $codeBlocks[$key] = '<pre class="bg-gray-900 text-gray-100 p-4 rounded-xl overflow-x-auto text-sm my-3 font-mono"><code' . ($lang ? ' class="language-' . $lang . '"' : '') . '>' . $code . '</code></pre>';
                return $key;
            },
            $text
        );

        // Extract inline code
        $text = preg_replace_callback(
            '/`([^`]+)`/',
            function ($matches) use (&$inlineCode) {
                $code = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
                $key = '___INLINECODE_' . count($inlineCode) . '___';
                $badgeClass = $this->theme['activeBadge'] ?? 'bg-indigo-100 text-indigo-700';
                $inlineCode[$key] = '<code class="' . $badgeClass . ' px-1.5 py-0.5 rounded text-sm font-mono">' . $code . '</code>';
                return $key;
            },
            $text
        );

        // Now escape the remaining text
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Bold
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong class="font-semibold">$1</strong>', $text);

        // Italic
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);

        // Lists (unordered)
        $text = preg_replace('/^- (.+)$/m', '<li class="ml-4 list-disc text-gray-700">$1</li>', $text);

        // Lists (ordered)
        $text = preg_replace('/^\d+\. (.+)$/m', '<li class="ml-4 list-decimal text-gray-700">$1</li>', $text);

        // Headers
        $text = preg_replace('/^### (.+)$/m', '<h4 class="text-lg font-semibold text-gray-800 mt-4 mb-2">$1</h4>', $text);
        $text = preg_replace('/^## (.+)$/m', '<h3 class="text-xl font-semibold text-gray-800 mt-4 mb-2">$1</h3>', $text);

        // Safe links - only allow safe URL schemes
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^\)]+)\)/',
            function ($matches) {
                $label = $matches[1]; // Already escaped
                $url = $matches[2];   // Already escaped

                // Decode for validation, then re-encode safely
                $decodedUrl = html_entity_decode($url, ENT_QUOTES, 'UTF-8');

                if ($this->isUrlSafe($decodedUrl)) {
                    $safeUrl = htmlspecialchars($decodedUrl, ENT_QUOTES, 'UTF-8');
                    $primaryText = $this->theme['primaryText'] ?? 'text-indigo-600';
                    return '<a href="' . $safeUrl . '" class="' . $primaryText . ' hover:underline" target="_blank" rel="noopener noreferrer">' . $label . '</a>';
                }
                return '[' . $label . '](' . $url . ')'; // Return as plain text
            },
            $text
        );

        // Citations [1], [2] etc
        $primaryText = $this->theme['primaryText'] ?? 'text-indigo-600';
        $text = preg_replace('/\[(\d+)\]/', '<sup class="' . $primaryText . ' font-medium">[$1]</sup>', $text);

        // Restore code blocks and inline code
        foreach ($codeBlocks as $key => $html) {
            $text = str_replace(htmlspecialchars($key, ENT_QUOTES, 'UTF-8'), $html, $text);
        }
        foreach ($inlineCode as $key => $html) {
            $text = str_replace(htmlspecialchars($key, ENT_QUOTES, 'UTF-8'), $html, $text);
        }

        // Convert newlines to <br>
        $text = nl2br($text);

        return $text;
    }

    /**
     * Sanitize language identifier for code blocks
     */
    private function sanitizeLanguage(string $lang): string
    {
        // Only allow alphanumeric and common language identifiers
        if (preg_match('/^[a-zA-Z0-9_+-]+$/', $lang)) {
            return htmlspecialchars($lang, ENT_QUOTES, 'UTF-8');
        }
        return '';
    }

    /**
     * Check if URL is safe (no javascript:, data:, etc.)
     */
    private function isUrlSafe(string $url): bool
    {
        $url = trim(strtolower($url));

        // Allow only safe schemes
        $safeSchemes = ['http://', 'https://', 'mailto:', 'tel:', '#'];
        foreach ($safeSchemes as $scheme) {
            if (str_starts_with($url, $scheme)) {
                return true;
            }
        }

        // Allow relative URLs (starting with / or alphanumeric)
        if (preg_match('/^[a-zA-Z0-9\/]/', $url) && !str_contains($url, ':')) {
            return true;
        }

        return false;
    }
}
