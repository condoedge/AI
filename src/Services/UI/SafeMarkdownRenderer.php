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
        // Placeholders (review #4): every held-aside fragment (code, escapes, links,
        // tables) is keyed with a per-render NONCE between \x1A delimiters, and any
        // \x1A in the input is dropped first — user text can never forge a key
        // (a typed "___ESC0___" stays literal text).
        $nonce = bin2hex(random_bytes(4));
        $placeholder = static fn (string $kind, int $n): string => "\x1A{$nonce}{$kind}{$n}\x1A";
        $text = str_replace("\x1A", '', $text);

        // Store code blocks temporarily to prevent them from being processed
        $codeBlocks = [];
        $inlineCode = [];

        // Extract code blocks first (prevent processing inside them)
        $text = preg_replace_callback(
            '/```(\w*)\n([\s\S]*?)```/',
            function ($matches) use (&$codeBlocks, $placeholder) {
                $lang = $this->sanitizeLanguage($matches[1] ?? '');
                $code = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
                $key = $placeholder('B', count($codeBlocks));
                $codeBlocks[$key] = '<pre class="bg-gray-900 text-gray-100 p-4 rounded-xl overflow-x-auto text-sm my-3 font-mono"><code' . ($lang ? ' class="language-' . $lang . '"' : '') . '>' . $code . '</code></pre>';
                return $key;
            },
            $text
        );

        // Backslash escapes (P-5): \[ \] \( \) \* \_ \` \| \\ render as the LITERAL
        // character — never as link/citation/emphasis/code/table syntax. Extracted
        // before inline code so an escaped backtick cannot open a code span.
        $escapes = [];
        $text = preg_replace_callback(
            '/\\\\([\\\\\[\]()*_`|#])/',
            function ($matches) use (&$escapes, $placeholder) {
                $key = $placeholder('E', count($escapes));
                $escapes[$key] = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
                return $key;
            },
            $text
        );

        // Extract inline code
        $text = preg_replace_callback(
            '/`([^`]+)`/',
            function ($matches) use (&$inlineCode, $placeholder) {
                $code = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
                $key = $placeholder('I', count($inlineCode));
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

        // Safe links - only allow safe URL schemes. A generated anchor is HELD ASIDE
        // as a placeholder (review #4) so no later substitution (citations, code,
        // escapes) can ever land inside its href. A URL carrying a placeholder key,
        // "___", whitespace or a protocol-relative "//" is rendered as plain text.
        $links = [];
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^\)]+)\)/',
            function ($matches) use (&$links, $placeholder) {
                $label = $matches[1]; // Already escaped
                $url = $matches[2];   // Already escaped

                // Decode for validation, then re-encode safely
                $decodedUrl = html_entity_decode($url, ENT_QUOTES, 'UTF-8');

                // The scheme check also runs on the FULLY entity-decoded URL, so an
                // encoded scheme ("javascript&colon;…") can never pass as relative.
                $fullyDecoded = $decodedUrl;
                for ($i = 0; $i < 4; $i++) {
                    $fullyDecoded = html_entity_decode($fullyDecoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }

                if (!str_contains($decodedUrl, "\x1A") && !str_contains($decodedUrl, '___')
                    && preg_match('/\s/u', $fullyDecoded) !== 1
                    && $this->isUrlSafe($decodedUrl) && $this->isUrlSafe($fullyDecoded)) {
                    $safeUrl = htmlspecialchars($decodedUrl, ENT_QUOTES, 'UTF-8');
                    $primaryText = $this->theme['primaryText'] ?? 'text-indigo-600';
                    $key = $placeholder('L', count($links));
                    $links[$key] = '<a href="' . $safeUrl . '" class="' . $primaryText . ' hover:underline" target="_blank" rel="noopener noreferrer">' . $label . '</a>';

                    return $key;
                }
                return '[' . $label . '](' . $url . ')'; // Return as plain text
            },
            $text
        );

        // Citations [1], [2] etc
        $primaryText = $this->theme['primaryText'] ?? 'text-indigo-600';
        $text = preg_replace('/\[(\d+)\]/', '<sup class="' . $primaryText . ' font-medium">[$1]</sup>', $text);

        // Pipe tables (P-6): the text is already escaped and its inline markdown
        // (links with the scheme allow-list, emphasis, citations) already rendered;
        // well-formed tables become <table> blocks held aside so nl2br never runs
        // inside them.
        $tables = [];
        $text = $this->renderTables($text, $tables, $placeholder);

        // Restore code blocks and inline code — in the text AND inside every
        // held-aside table / link label (review #3: code in a table cell was never
        // restored). A link's href never contains a key (rejected above).
        $code = $codeBlocks + $inlineCode;
        $text = strtr($text, $code);
        $tables = array_map(static fn (string $html) => strtr($html, $code), $tables);
        $links = array_map(static fn (string $html) => strtr($html, $code), $links);

        // Convert newlines to <br>
        $text = nl2br($text);

        // Re-insert: tables (which may hold links) → links → literal escapes.
        $text = strtr($text, $tables);
        $text = strtr($text, $links);
        $text = strtr($text, $escapes);

        return $text;
    }

    /**
     * Replace well-formed pipe tables (header row, `| --- |` separator, body rows)
     * with placeholders; $tables receives placeholder => table HTML. Cells are
     * already-escaped, already-rendered inline HTML; `|` inside a cell arrives as a
     * backslash-escape placeholder, so splitting on `|` is exact.
     *
     * @param array<string, string> $tables
     */
    private function renderTables(string $text, array &$tables, callable $placeholder): string
    {
        $lines = explode("\n", $text);
        $out = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            $next = $lines[$i + 1] ?? null;

            if (!$this->isTableRow($line) || $next === null || !$this->isSeparatorRow($next)) {
                $out[] = $line;
                continue;
            }

            $headers = $this->tableCells($line);
            $body = [];
            $i += 2;
            while ($i < $count && $this->isTableRow($lines[$i])) {
                $body[] = $this->tableCells($lines[$i]);
                $i++;
            }
            $i--; // the for-loop increment

            $th = 'px-3 py-1.5 text-left font-semibold text-gray-700 border-b border-gray-200';
            $td = 'px-3 py-1.5 text-gray-700 border-b border-gray-100';
            $html = '<div class="overflow-x-auto my-2"><table class="min-w-full text-sm border-collapse"><thead><tr>';
            foreach ($headers as $cell) {
                $html .= '<th class="' . $th . '">' . $cell . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($body as $row) {
                $row = array_pad(array_slice($row, 0, count($headers)), count($headers), '');
                $html .= '<tr>';
                foreach ($row as $cell) {
                    $html .= '<td class="' . $td . '">' . $cell . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';

            $key = $placeholder('T', count($tables));
            $tables[$key] = $html;
            $out[] = $key;
        }

        return implode("\n", $out);
    }

    private function isTableRow(string $line): bool
    {
        $line = trim($line);

        return strlen($line) > 1 && $line[0] === '|' && substr_count($line, '|') >= 2;
    }

    private function isSeparatorRow(string $line): bool
    {
        return (bool) preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/', $line);
    }

    /** @return array<int, string> */
    private function tableCells(string $line): array
    {
        $line = trim($line);
        $line = (string) preg_replace('/^\||\|$/', '', $line);

        return array_map('trim', explode('|', $line));
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

        // Protocol-relative "//host" is an off-site link in disguise (review #4).
        if (str_starts_with($url, '//') || str_starts_with($url, '/\\')) {
            return false;
        }

        // Allow relative URLs (starting with / or alphanumeric)
        if (preg_match('/^[a-zA-Z0-9\/]/', $url) && !str_contains($url, ':')) {
            return true;
        }

        return false;
    }
}
