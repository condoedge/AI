<?php

namespace Condoedge\Ai\Services\Extractors;

use Condoedge\Ai\Contracts\PdfParserInterface;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;
use thiagoalessio\TesseractOCR\TesseractOCR;

class OcrPdfParser implements PdfParserInterface
{
    public function __construct(private ?Parser $textParser = null)
    {
        $this->textParser = $this->textParser ?? new Parser();
    }

    public function extractText(string $filename, ?int $pageLimit = null): string
    {
        if (!is_file($filename)) {
            throw new RuntimeException("PDF file not found: {$filename}");
        }

        $effectivePageLimit = $this->resolvePageLimit($pageLimit);

        $nativePages = $this->extractNativePages($filename, $effectivePageLimit);
        $chunks = [];

        if ($nativePages === []) {
            $images = $this->convertPdfToImages($filename, $effectivePageLimit);

            try {
                foreach ($images as $imagePath) {
                    $ocrText = $this->extractTextFromImage($imagePath);
                    $finalText = $this->cleanExtractedText($ocrText);

                    if ($finalText !== '') {
                        $chunks[] = $finalText;
                    }
                }
            } finally {
                foreach ($images as $imagePath) {
                    if (is_file($imagePath)) {
                        @unlink($imagePath);
                    }
                }
            }

            return implode(PHP_EOL . PHP_EOL, $chunks);
        }

        foreach ($nativePages as $index => $nativePageText) {
            if ($this->hasEnoughNativeTextSignals($nativePageText)) {
                $finalText = $this->cleanExtractedText($nativePageText);
            } else {
                $finalText = $this->cleanExtractedText($this->extractTextFromPageImage($filename, $index + 1));
            }

            if ($finalText !== '') {
                $chunks[] = $finalText;
            }
        }

        return implode(PHP_EOL . PHP_EOL, $chunks);
    }

    private function extractTextFromPageImage(string $filename, int $pageNumber): string
    {
        $imagePath = $this->convertPdfPageToImage($filename, $pageNumber);

        try {
            return $this->extractTextFromImage($imagePath);
        } finally {
            if (is_file($imagePath)) {
                @unlink($imagePath);
            }
        }
    }

    private function convertPdfPageToImage(string $filename, int $pageNumber): string
    {
        $ghostscriptExecutable = $this->resolveGhostscriptExecutable();
        if ($ghostscriptExecutable !== null) {
            return $this->convertSinglePageWithGhostscript($filename, $ghostscriptExecutable, $pageNumber);
        }

        if (extension_loaded('imagick')) {
            return $this->convertSinglePageWithImagick($filename, $pageNumber);
        }

        throw new RuntimeException(
            'No PDF rasterizer available. Install Ghostscript (gswin64c/gswin32c) and set GHOSTSCRIPT_PATH if needed.'
        );
    }

    private function convertSinglePageWithGhostscript(string $filename, string $ghostscriptExecutable, int $pageNumber): string
    {
        $tempBase = tempnam(sys_get_temp_dir(), 'ocr_pdf_page_');
        if ($tempBase === false) {
            throw new RuntimeException('Unable to allocate temporary file for OCR page image.');
        }

        $imagePath = $tempBase . '.png';
        @unlink($tempBase);

        $command = [
            escapeshellarg($ghostscriptExecutable),
            '-q',
            '-dSAFER',
            '-dBATCH',
            '-dNOPAUSE',
            '-sDEVICE=png16m',
            '-r300',
            '-dFirstPage=' . $pageNumber,
            '-dLastPage=' . $pageNumber,
            '-sOutputFile=' . escapeshellarg($imagePath),
            escapeshellarg($filename),
        ];

        $output = [];
        $exitCode = 0;
        exec(implode(' ', $command) . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0 || !is_file($imagePath)) {
            @unlink($imagePath);
            throw new RuntimeException('Ghostscript single-page conversion failed. ' . implode(PHP_EOL, $output));
        }

        return $imagePath;
    }

    private function convertSinglePageWithImagick(string $filename, int $pageNumber): string
    {
        $tempBase = tempnam(sys_get_temp_dir(), 'ocr_pdf_page_');
        if ($tempBase === false) {
            throw new RuntimeException('Unable to allocate temporary file for OCR page image.');
        }

        $imagePath = $tempBase . '.png';
        @unlink($tempBase);

        $imagickClass = 'Imagick';
        $page = new $imagickClass();

        try {
            $page->setResolution(300, 300);
            $page->readImage($filename . '[' . ($pageNumber - 1) . ']');
            $page->setImageFormat('png');
            $page->writeImage($imagePath);
        } catch (Throwable $e) {
            @unlink($imagePath);
            throw new RuntimeException('Imagick single-page conversion failed.', 0, $e);
        } finally {
            $page->clear();
            $page->destroy();
        }

        return $imagePath;
    }

    private function resolvePageLimit(?int $pageLimit): ?int
    {
        if ($pageLimit !== null && $pageLimit > 0) {
            return $pageLimit;
        }

        $envLimit = (int) (getenv('OCR_PAGE_LIMIT') ?: 0);

        return $envLimit > 0 ? $envLimit : null;
    }

    private function extractNativePages(string $filename, ?int $pageLimit = null): array
    {
        try {
            $pdf = $this->textParser->parseFile($filename);
            $pages = $pdf->getPages();

            if (!is_array($pages) || $pages === []) {
                return [];
            }

            $limit = $pageLimit && $pageLimit > 0 ? min($pageLimit, count($pages)) : count($pages);
            $result = [];

            for ($i = 0; $i < $limit; $i++) {
                $result[] = (string) $pages[$i]->getText();
            }

            return $result;
        } catch (Throwable) {
            return [];
        }
    }

    private function hasEnoughNativeTextSignals(string $text): bool
    {
        $minWords = (int) (getenv('OCR_DIRECT_TEXT_MIN_WORDS') ?: 45);
        $minLetters = (int) (getenv('OCR_DIRECT_TEXT_MIN_LETTERS') ?: 180);

        return $this->countWords($text) >= $minWords && $this->countLetters($text) >= $minLetters;
    }

    private function countWords(string $text): int
    {
        preg_match_all('/[\p{L}\p{N}]{2,}/u', $text, $matches);
        return count($matches[0] ?? []);
    }

    private function countLetters(string $text): int
    {
        preg_match_all('/\p{L}/u', $text, $matches);
        return count($matches[0] ?? []);
    }

    private function extractTextFromImage(string $imagePath): string
    {
        $tesseractExecutable = $this->resolveTesseractExecutable();
        $lang = $this->resolveOcrLanguage($tesseractExecutable);

        $tsvOcr = (new TesseractOCR($imagePath))->lang($lang);
        $plainOcr = (new TesseractOCR($imagePath))->lang($lang);

        if ($tesseractExecutable !== null) {
            $tsvOcr->executable($tesseractExecutable);
            $plainOcr->executable($tesseractExecutable);
        }

        try {
            $tsv = $tsvOcr->tsv()->run();

            $textFromTsv = $this->extractHighConfidenceTextFromTsv($tsv);
            if ($textFromTsv !== '') {
                return $textFromTsv;
            }
        } catch (Throwable) {
            // Some Tesseract installations do not provide the `tsv` config; fall back to plain OCR.
        }

        return $plainOcr->run();
    }

    private function resolveOcrLanguage(?string $tesseractExecutable): string
    {
        $requested = trim((string) (getenv('OCR_LANG') ?: 'fra'));
        if ($requested === '') {
            $requested = 'fra';
        }

        if ($tesseractExecutable === null) {
            return $requested;
        }

        $available = $this->getAvailableTesseractLanguages($tesseractExecutable);
        if ($available === []) {
            return $requested;
        }

        if (in_array($requested, $available, true)) {
            return $requested;
        }

        if (in_array('eng', $available, true)) {
            return 'eng';
        }

        return $available[0];
    }

    private function getAvailableTesseractLanguages(string $tesseractExecutable): array
    {
        $output = [];
        $exitCode = 0;
        @exec(escapeshellarg($tesseractExecutable) . ' --list-langs 2>&1', $output, $exitCode);

        if ($exitCode !== 0 || $output === []) {
            return [];
        }

        $languages = [];
        foreach ($output as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'List of available languages')) {
                continue;
            }

            if (preg_match('/^[a-z0-9_+\-]+$/i', $line)) {
                $languages[] = strtolower($line);
            }
        }

        return array_values(array_unique($languages));
    }

    private function resolveTesseractExecutable(): ?string
    {
        $configured = getenv('TESSERACT_PATH') ?: null;
        if ($configured && is_file($configured)) {
            return $configured;
        }

        $candidates = [
            'C:/Program Files/Tesseract-OCR/tesseract.exe',
            'C:/Program Files (x86)/Tesseract-OCR/tesseract.exe',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $whereResult = [];
        $whereExit = 0;
        @exec(PHP_OS_FAMILY === 'Windows' ? 'where tesseract.exe 2>NUL' : 'command -v tesseract 2>/dev/null', $whereResult, $whereExit);
        if ($whereExit === 0 && isset($whereResult[0]) && is_file(trim($whereResult[0]))) {
            return trim($whereResult[0]);
        }

        return null;
    }

    private function extractHighConfidenceTextFromTsv(string $tsv): string
    {
        $rows = preg_split('/\r\n|\r|\n/', trim($tsv)) ?: [];
        if (count($rows) <= 1) {
            return '';
        }

        $header = str_getcsv((string) array_shift($rows), "\t");
        if (!is_array($header) || $header === []) {
            return '';
        }

        $columnMap = array_flip($header);
        foreach (['page_num', 'block_num', 'par_num', 'line_num', 'conf', 'text'] as $required) {
            if (!array_key_exists($required, $columnMap)) {
                return '';
            }
        }

        $minWordConf = (float) (getenv('OCR_MIN_WORD_CONF') ?: 58);
        $minLineConf = (float) (getenv('OCR_MIN_LINE_CONF') ?: 70);
        $lines = [];

        foreach ($rows as $row) {
            $columns = str_getcsv($row, "\t");
            if (!is_array($columns) || $columns === []) {
                continue;
            }

            $text = trim((string) ($columns[$columnMap['text']] ?? ''));
            $conf = (float) ($columns[$columnMap['conf']] ?? -1);

            if ($text === '' || $conf < $minWordConf) {
                continue;
            }

            $key = implode(':', [
                $columns[$columnMap['page_num']] ?? '0',
                $columns[$columnMap['block_num']] ?? '0',
                $columns[$columnMap['par_num']] ?? '0',
                $columns[$columnMap['line_num']] ?? '0',
            ]);

            if (!isset($lines[$key])) {
                $lines[$key] = ['words' => [], 'conf' => []];
            }

            $lines[$key]['words'][] = $text;
            $lines[$key]['conf'][] = $conf;
        }

        $result = [];
        foreach ($lines as $line) {
            if (($line['words'] ?? []) === []) {
                continue;
            }

            $avgConf = array_sum($line['conf']) / max(1, count($line['conf']));
            if ($avgConf < $minLineConf) {
                continue;
            }

            $lineText = trim(implode(' ', $line['words']));
            if ($lineText !== '') {
                $result[] = $lineText;
            }
        }

        return implode(PHP_EOL, $result);
    }

    private function cleanExtractedText(string $text): string
    {
        // Ensure valid UTF-8 — PDF parsers and OCR can produce invalid byte sequences
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $replacements = [
            "\u{2018}" => "'",
            "\u{2019}" => "'",
            "\u{201C}" => '"',
            "\u{201D}" => '"',
            "\u{2013}" => '-',
            "\u{2014}" => '-',
            "\u{00A0}" => ' ',
            "\u{FB00}" => 'ff',
            "\u{FB01}" => 'fi',
            "\u{FB02}" => 'fl',
            "\u{FB03}" => 'ffi',
            "\u{FB04}" => 'ffl',
        ];
        $text = strtr($text, $replacements);

        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

        $lines = preg_split('/\n/u', $text) ?: [];
        $kept = [];

        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
            if ($line === '') {
                continue;
            }

            if (!$this->isLikelyUsefulLine($line)) {
                continue;
            }

            $kept[] = $line;
        }

        $cleaned = implode(PHP_EOL, $kept);
        $cleaned = preg_replace('/\n{3,}/u', "\n\n", $cleaned) ?? $cleaned;

        return trim($cleaned);
    }

    private function isLikelyUsefulLine(string $line): bool
    {
        $length = mb_strlen($line, 'UTF-8');
        if ($length <= 1) {
            return false;
        }

        $letters = preg_match_all('/\p{L}/u', $line, $unused1);
        $digits = preg_match_all('/\p{N}/u', $line, $unused2);
        $symbols = preg_match_all("~[^\\p{L}\\p{N}\\s.,;:!?\"'()\\[\\]{}\\\\/-]~u", $line, $unused3);

        $alnum = max(1, $letters + $digits);
        $symbolRatio = $symbols / max(1, $length);

        if ($alnum < 2 && $length > 4) {
            return false;
        }

        if ($symbolRatio > 0.40) {
            return false;
        }

        if (preg_match('/([ilI1|])\1{5,}/u', $line)) {
            return false;
        }

        return true;
    }

    private function convertPdfToImages(string $filename, ?int $pageLimit = null): array
    {
        $ghostscriptExecutable = $this->resolveGhostscriptExecutable();
        if ($ghostscriptExecutable !== null) {
            return $this->convertWithGhostscriptCli($filename, $ghostscriptExecutable, $pageLimit);
        }

        if (extension_loaded('imagick')) {
            return $this->convertWithImagick($filename, $pageLimit);
        }

        throw new RuntimeException(
            'No PDF rasterizer available. Install Ghostscript (gswin64c/gswin32c) and set GHOSTSCRIPT_PATH if needed.'
        );
    }

    private function resolveGhostscriptExecutable(): ?string
    {
        $candidates = [];

        $configuredPath = getenv('GHOSTSCRIPT_PATH') ?: getenv('GS_PROG') ?: null;
        if ($configuredPath) {
            $normalized = str_replace('\\', '/', $configuredPath);
            $candidates[] = $normalized;
            $candidates[] = rtrim($normalized, '/') . '/gswin64c.exe';
            $candidates[] = rtrim($normalized, '/') . '/gswin32c.exe';
            $candidates[] = rtrim($normalized, '/') . '/bin/gswin64c.exe';
            $candidates[] = rtrim($normalized, '/') . '/bin/gswin32c.exe';
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $commonBases = [
                'C:/Program Files/gs',
                'C:/Program Files (x86)/gs',
            ];

            foreach ($commonBases as $base) {
                foreach (glob($base . '/gs*/bin/gswin64c.exe') ?: [] as $match) {
                    $candidates[] = $match;
                }
                foreach (glob($base . '/gs*/bin/gswin32c.exe') ?: [] as $match) {
                    $candidates[] = $match;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate && is_file($candidate)) {
                return $candidate;
            }
        }

        $whereResult = [];
        $whereExit = 0;
        @exec(PHP_OS_FAMILY === 'Windows' ? 'where gswin64c.exe 2>NUL' : 'command -v gs 2>/dev/null', $whereResult, $whereExit);
        if ($whereExit === 0 && isset($whereResult[0]) && is_file(trim($whereResult[0]))) {
            return trim($whereResult[0]);
        }

        return null;
    }

    private function convertWithGhostscriptCli(string $filename, string $ghostscriptExecutable, ?int $pageLimit = null): array
    {
        $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $prefix = 'ocr_pdf_' . uniqid('', true);
        $outputPattern = $tempDir . DIRECTORY_SEPARATOR . $prefix . '_%04d.png';

        $command = [
            escapeshellarg($ghostscriptExecutable),
            '-q',
            '-dSAFER',
            '-dBATCH',
            '-dNOPAUSE',
            '-sDEVICE=png16m',
            '-r300',
        ];

        if ($pageLimit && $pageLimit > 0) {
            $command[] = '-dFirstPage=1';
            $command[] = '-dLastPage=' . (int) $pageLimit;
        }

        $command[] = '-sOutputFile=' . escapeshellarg($outputPattern);
        $command[] = escapeshellarg($filename);
        $commandString = implode(' ', $command) . ' 2>&1';

        $output = [];
        $exitCode = 0;
        exec($commandString, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                'Ghostscript PDF conversion failed. ' . implode(PHP_EOL, $output)
            );
        }

        $paths = glob($tempDir . DIRECTORY_SEPARATOR . $prefix . '_*.png') ?: [];
        sort($paths);

        if ($paths === []) {
            throw new RuntimeException('Ghostscript conversion produced no images.');
        }

        return $paths;
    }

    private function convertWithImagick(string $filename, ?int $pageLimit = null): array
    {
        $paths = [];
        $imagickClass = 'Imagick';
        $pdf = new $imagickClass();

        try {
            $pdf->setResolution(300, 300);
            $pdf->readImage($filename);

            $pageCount = $pdf->getNumberImages();
            $maxPages = $pageLimit ? min($pageLimit, $pageCount) : $pageCount;

            $index = 0;
            foreach ($pdf as $page) {
                if ($index >= $maxPages) {
                    break;
                }

                $tempBase = tempnam(sys_get_temp_dir(), 'ocr_pdf_');
                if ($tempBase === false) {
                    throw new RuntimeException('Unable to allocate temporary file for OCR image.');
                }

                $imagePath = $tempBase . '.png';
                @unlink($tempBase);

                $page->setImageFormat('png');
                $page->writeImage($imagePath);
                $paths[] = $imagePath;
                $index++;
            }

            return $paths;
        } catch (Throwable $e) {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            throw new RuntimeException('Failed to convert PDF to images. Ensure Ghostscript is installed and available.', 0, $e);
        } finally {
            $pdf->clear();
            $pdf->destroy();
        }
    }
}