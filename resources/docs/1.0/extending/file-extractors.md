# Custom File Extractors

Create custom extractors for processing file content.

---

## Overview

File extractors convert document content to text for embedding and search. Built-in extractors:
- **PDF**: Adobe PDF documents
- **DOCX**: Microsoft Word documents
- **TXT/MD**: Plain text and Markdown

You can create custom extractors for:
- Spreadsheets (XLSX, CSV)
- Presentations (PPTX)
- Custom formats
- API-based extraction

---

## Extractor Interface

All extractors implement `FileExtractorInterface`:

```php
<?php

namespace Condoedge\Ai\Contracts;

interface FileExtractorInterface
{
    /**
     * Extract text content from a file.
     *
     * @param string $path File path
     * @return string Extracted text content
     */
    public function extract(string $path): string;

    /**
     * Get supported file extensions.
     *
     * @return array List of extensions (e.g., ['pdf', 'PDF'])
     */
    public function getSupportedExtensions(): array;

    /**
     * Check if extractor can handle a file.
     *
     * @param string $path File path
     * @return bool
     */
    public function supports(string $path): bool;

    /**
     * Get extractor name.
     *
     * @return string
     */
    public function getName(): string;
}
```

---

## Creating a Custom Extractor

### Step 1: Create Extractor Class

```php
<?php

namespace App\Services\Ai\Extractors;

use Condoedge\Ai\Contracts\FileExtractorInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ExcelExtractor implements FileExtractorInterface
{
    public function extract(string $path): string
    {
        $spreadsheet = IOFactory::load($path);
        $text = '';

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $text .= "## Sheet: " . $sheet->getTitle() . "\n\n";

            foreach ($sheet->getRowIterator() as $row) {
                $rowData = [];
                foreach ($row->getCellIterator() as $cell) {
                    $rowData[] = $cell->getValue();
                }
                $text .= implode(' | ', $rowData) . "\n";
            }

            $text .= "\n";
        }

        return $text;
    }

    public function getSupportedExtensions(): array
    {
        return ['xlsx', 'xls', 'XLSX', 'XLS'];
    }

    public function supports(string $path): bool
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        return in_array($extension, $this->getSupportedExtensions());
    }

    public function getName(): string
    {
        return 'excel';
    }
}
```

### Step 2: Register Extractor

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Condoedge\Ai\Services\FileProcessingService;
use App\Services\Ai\Extractors\ExcelExtractor;

class AiExtensionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->resolving(FileProcessingService::class, function ($service) {
            $service->registerExtractor(new ExcelExtractor());
        });
    }
}
```

### Step 3: Update Configuration

```php
// config/ai.php
'file_processing' => [
    'supported_types' => ['pdf', 'docx', 'txt', 'md', 'xlsx', 'xls'],
],
```

---

## Example: CSV Extractor

```php
<?php

namespace App\Services\Ai\Extractors;

use Condoedge\Ai\Contracts\FileExtractorInterface;

class CsvExtractor implements FileExtractorInterface
{
    public function extract(string $path): string
    {
        $text = '';
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new \Exception("Cannot open file: {$path}");
        }

        // Read header
        $header = fgetcsv($handle);
        if ($header) {
            $text .= "Columns: " . implode(', ', $header) . "\n\n";
        }

        // Read data rows
        $rowCount = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $rowCount++;

            // Create readable format
            if ($header) {
                $pairs = [];
                foreach ($header as $i => $col) {
                    $pairs[] = "{$col}: " . ($row[$i] ?? '');
                }
                $text .= implode(', ', $pairs) . "\n";
            } else {
                $text .= implode(', ', $row) . "\n";
            }

            // Limit for very large files
            if ($rowCount >= 1000) {
                $text .= "\n[...truncated after 1000 rows...]\n";
                break;
            }
        }

        fclose($handle);
        return $text;
    }

    public function getSupportedExtensions(): array
    {
        return ['csv', 'CSV'];
    }

    public function supports(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv';
    }

    public function getName(): string
    {
        return 'csv';
    }
}
```

---

## Example: PowerPoint Extractor

```php
<?php

namespace App\Services\Ai\Extractors;

use Condoedge\Ai\Contracts\FileExtractorInterface;
use PhpOffice\PhpPresentation\IOFactory;

class PowerPointExtractor implements FileExtractorInterface
{
    public function extract(string $path): string
    {
        $presentation = IOFactory::load($path);
        $text = '';
        $slideNumber = 0;

        foreach ($presentation->getAllSlides() as $slide) {
            $slideNumber++;
            $text .= "## Slide {$slideNumber}\n\n";

            foreach ($slide->getShapeCollection() as $shape) {
                if ($shape instanceof \PhpOffice\PhpPresentation\Shape\RichText) {
                    foreach ($shape->getParagraphs() as $paragraph) {
                        $text .= $paragraph->getPlainText() . "\n";
                    }
                }
            }

            $text .= "\n";
        }

        return $text;
    }

    public function getSupportedExtensions(): array
    {
        return ['pptx', 'ppt', 'PPTX', 'PPT'];
    }

    public function supports(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, ['pptx', 'ppt']);
    }

    public function getName(): string
    {
        return 'powerpoint';
    }
}
```

---

## Example: API-Based Extractor

Using external OCR service for images:

```php
<?php

namespace App\Services\Ai\Extractors;

use Condoedge\Ai\Contracts\FileExtractorInterface;
use Illuminate\Support\Facades\Http;

class OcrExtractor implements FileExtractorInterface
{
    protected string $apiKey;
    protected string $endpoint;

    public function __construct()
    {
        $this->apiKey = config('services.ocr.api_key');
        $this->endpoint = config('services.ocr.endpoint');
    }

    public function extract(string $path): string
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
        ])->attach(
            'file',
            file_get_contents($path),
            basename($path)
        )->post($this->endpoint);

        if (!$response->successful()) {
            throw new \Exception("OCR API error: " . $response->body());
        }

        return $response->json('text', '');
    }

    public function getSupportedExtensions(): array
    {
        return ['png', 'jpg', 'jpeg', 'tiff', 'bmp', 'gif'];
    }

    public function supports(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, ['png', 'jpg', 'jpeg', 'tiff', 'bmp', 'gif']);
    }

    public function getName(): string
    {
        return 'ocr';
    }
}
```

---

## Chunking Strategy

Extractors can implement custom chunking:

```php
<?php

namespace App\Services\Ai\Extractors;

use Condoedge\Ai\Contracts\FileExtractorInterface;
use Condoedge\Ai\Contracts\ChunkableExtractorInterface;

class SmartPdfExtractor implements FileExtractorInterface, ChunkableExtractorInterface
{
    public function extract(string $path): string
    {
        // Full extraction
        return $this->extractPages($path);
    }

    /**
     * Extract and return chunks directly.
     */
    public function extractChunks(string $path, array $options = []): array
    {
        $chunkSize = $options['chunk_size'] ?? 1000;
        $overlap = $options['overlap'] ?? 200;

        $chunks = [];
        $pages = $this->extractPageByPage($path);

        foreach ($pages as $pageNum => $pageText) {
            // Chunk each page preserving page boundaries
            $pageChunks = $this->chunkText($pageText, $chunkSize, $overlap);

            foreach ($pageChunks as $i => $chunk) {
                $chunks[] = [
                    'text' => $chunk,
                    'metadata' => [
                        'page' => $pageNum + 1,
                        'chunk_index' => $i,
                    ],
                ];
            }
        }

        return $chunks;
    }

    protected function chunkText(string $text, int $size, int $overlap): array
    {
        // Implement chunking logic
        $chunks = [];
        $position = 0;

        while ($position < strlen($text)) {
            $chunk = substr($text, $position, $size);

            // Find sentence boundary
            $lastPeriod = strrpos($chunk, '.');
            if ($lastPeriod !== false && $lastPeriod > $size * 0.5) {
                $chunk = substr($chunk, 0, $lastPeriod + 1);
            }

            $chunks[] = $chunk;
            $position += strlen($chunk) - $overlap;
        }

        return $chunks;
    }
}
```

---

## Error Handling

Implement robust error handling:

```php
public function extract(string $path): string
{
    // Check file exists
    if (!file_exists($path)) {
        throw new \InvalidArgumentException("File not found: {$path}");
    }

    // Check file readable
    if (!is_readable($path)) {
        throw new \RuntimeException("File not readable: {$path}");
    }

    // Check file size
    $maxSize = config('ai.file_processing.max_file_size', 50 * 1024 * 1024);
    if (filesize($path) > $maxSize) {
        throw new \RuntimeException("File too large: {$path}");
    }

    try {
        return $this->doExtract($path);
    } catch (\Exception $e) {
        if (config('ai.file_processing.fail_silently')) {
            Log::warning("File extraction failed: {$path}", [
                'error' => $e->getMessage(),
            ]);
            return '';
        }
        throw $e;
    }
}
```

---

## Testing Extractors

```php
use App\Services\Ai\Extractors\ExcelExtractor;

public function test_excel_extractor()
{
    $extractor = new ExcelExtractor();

    // Test support
    $this->assertTrue($extractor->supports('/path/to/file.xlsx'));
    $this->assertFalse($extractor->supports('/path/to/file.pdf'));

    // Test extraction
    $text = $extractor->extract(storage_path('test/sample.xlsx'));
    $this->assertNotEmpty($text);
    $this->assertStringContainsString('Sheet:', $text);
}
```

---

## Configuration

```php
// config/ai.php
'file_processing' => [
    'supported_types' => ['pdf', 'docx', 'txt', 'md', 'xlsx', 'csv', 'pptx'],

    // Chunk settings
    'chunk_size' => 1000,
    'chunk_overlap' => 200,
    'preserve_sentences' => true,

    // Limits
    'max_file_size' => 50 * 1024 * 1024, // 50MB

    // Error handling
    'fail_silently' => true,
    'log_errors' => true,
],
```

---

## Related Documentation

- [File Search](/docs/{{version}}/usage/file-search) - Searching file content
- [Data Ingestion](/docs/{{version}}/usage/data-ingestion) - Ingestion process
- [Overview](/docs/{{version}}/usage/extending) - Extension overview
