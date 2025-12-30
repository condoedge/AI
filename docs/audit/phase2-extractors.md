# Phase 2 Audit - Extractors Review

**Task:** 23
**Date:** 2025-12-30
**Auditor:** Claude Code

## Overview

The extractor subsystem handles text extraction from various file formats. All extractors implement `FileExtractorInterface` and are registered in `FileExtractorRegistry`.

## Files Reviewed

| File | Location |
|------|----------|
| DocxExtractor.php | `src/Services/Extractors/` |
| MarkdownExtractor.php | `src/Services/Extractors/` |
| PdfExtractor.php | `src/Services/Extractors/` |
| TextExtractor.php | `src/Services/Extractors/` |

## Interface Contract

**File:** `src/Contracts/FileExtractorInterface.php`

```php
interface FileExtractorInterface
{
    public function extract(string $filePath): string;
    public function supports(string $extension): bool;
    public function getSupportedExtensions(): array;
    public function extractMetadata(string $filePath): array;
}
```

All four extractors correctly implement this interface.

---

## Extractor Analysis

### 1. DocxExtractor

| Property | Value |
|----------|-------|
| **File Types** | `.docx` |
| **Library** | `phpoffice/phpword` (^1.4) |
| **Output Format** | Plain text (normalized, cleaned) |
| **Implements Interface** | Yes |
| **Registered** | Yes (AiServiceProvider) |

**Extraction Method:**
- Uses `IOFactory::load()` to parse DOCX
- Iterates through sections and elements recursively
- Handles `Text`, `TextRun`, and `AbstractContainer` elements
- Falls back to `getText()` method if available

**Error Handling:**
- Throws `RuntimeException` if file not found
- Throws `RuntimeException` if file not readable
- Wraps extraction exceptions with context message

**Metadata Extracted:**
- `file_size`, `title`, `subject`, `creator`, `keywords`
- `description`, `last_modified_by`, `created`, `modified`
- `word_count`, `character_count`, `section_count`

**Notes:**
- Well-implemented with recursive element traversal
- Good text cleanup (null bytes, control chars, excessive whitespace)
- Does NOT support `.doc` (legacy Word format) - only `.docx`

---

### 2. MarkdownExtractor

| Property | Value |
|----------|-------|
| **File Types** | `.md`, `.markdown`, `.mdown` |
| **Library** | Native PHP (no external dependency) |
| **Output Format** | Markdown (preserved) or plain text (stripped) |
| **Implements Interface** | Yes |
| **Registered** | Yes (AiServiceProvider) |

**Extraction Method:**
- Simple `file_get_contents()` for reading
- Optional structure preservation via constructor parameter
- Two modes: `cleanMarkdown()` or `stripMarkdown()`

**Constructor Parameter:**
```php
public function __construct(
    private readonly bool $preserveStructure = true
)
```

**Error Handling:**
- Throws `RuntimeException` if file not found
- Throws `RuntimeException` if file not readable
- Throws `RuntimeException` if `file_get_contents()` returns false

**Metadata Extracted:**
- `file_size`, `line_count`, `character_count`, `word_count`
- `header_count`, `headers` (array with level and text)
- `front_matter` (YAML front matter parsing)
- `has_code_blocks`, `has_links`, `has_images`

**Notes:**
- Constructor-based configuration for structure preservation
- Basic YAML front matter parsing (key-value only)
- Good markdown stripping implementation
- No external dependencies required

---

### 3. PdfExtractor

| Property | Value |
|----------|-------|
| **File Types** | `.pdf` |
| **Library** | `smalot/pdfparser` (^2.12) |
| **Output Format** | Plain text (normalized, cleaned) |
| **Implements Interface** | Yes |
| **Registered** | Yes (AiServiceProvider) |

**Extraction Method:**
- Uses `Smalot\PdfParser\Parser::parseFile()`
- Calls `getText()` on parsed PDF object
- Applies cleanup for common PDF extraction issues

**Constructor (supports DI):**
```php
public function __construct(
    private ?Parser $parser = null
) {
    $this->parser = $parser ?? new Parser();
}
```

**Error Handling:**
- Throws `RuntimeException` if file not found
- Throws `RuntimeException` if file not readable
- Wraps extraction exceptions with context message

**Metadata Extracted:**
- `file_size`, `page_count`
- `title`, `author`, `subject`, `keywords`
- `creator`, `producer`, `creation_date`, `modification_date`
- `word_count`, `character_count`

**Notes:**
- Handles soft hyphens removal
- Fixes words split across lines (hyphenated line breaks)
- Parser can be injected for testing
- smalot/pdfparser may struggle with scanned/image-based PDFs

---

### 4. TextExtractor

| Property | Value |
|----------|-------|
| **File Types** | `.txt`, `.text`, `.log` |
| **Library** | Native PHP (no external dependency) |
| **Output Format** | Plain text (normalized) |
| **Implements Interface** | Yes |
| **Registered** | Yes (AiServiceProvider) |

**Extraction Method:**
- Simple `file_get_contents()` for reading
- Normalizes line endings
- Removes null bytes and control characters

**Error Handling:**
- Throws `RuntimeException` if file not found
- Throws `RuntimeException` if file not readable
- Throws `RuntimeException` if `file_get_contents()` returns false

**Metadata Extracted:**
- `file_size`, `line_count`, `character_count`, `word_count`
- `encoding` (via `mb_detect_encoding()`)

**Notes:**
- Simplest extractor implementation
- Handles `.log` files which is useful for system logs
- No external dependencies required
- Encoding detection is best-effort (limited charset list)

---

## Registration in Service Provider

**File:** `src/AiServiceProvider.php` (lines 287-299)

```php
$this->app->singleton(FileExtractorRegistry::class, function ($app) {
    $registry = new FileExtractorRegistry();

    // Register default extractors
    $registry->registerMany([
        new TextExtractor(),
        new MarkdownExtractor(),
        new PdfExtractor(),
        new DocxExtractor(),
    ]);

    return $registry;
});
```

All four extractors are properly registered at service provider boot time.

---

## Usage Analysis

### Primary Consumer

**File:** `src/Services/FileProcessor.php`

The `FileProcessor` uses `FileExtractorRegistry` to:
1. Check if a file type is supported (`supportsFileType()`)
2. Extract text from files (`extractorRegistry->extract()`)
3. Report supported file types (`getSupportedFileTypes()`)

### Test Coverage

**File:** `tests/Unit/Services/FileExtractorRegistryTest.php`

Tests cover:
- Single and multiple extractor registration
- Extension support checking (case-insensitive)
- Text extraction via registry
- Metadata extraction via registry
- Statistics reporting
- Error handling for unsupported types

**Note:** Tests only use `TextExtractor` and `MarkdownExtractor`. No tests for `PdfExtractor` or `DocxExtractor` directly (likely due to external library dependencies).

---

## Dependencies

| Extractor | Composer Package | Version |
|-----------|-----------------|---------|
| DocxExtractor | phpoffice/phpword | ^1.4 |
| PdfExtractor | smalot/pdfparser | ^2.12 |
| MarkdownExtractor | (none) | - |
| TextExtractor | (none) | - |

Both external dependencies are present in `composer.json`.

---

## Summary

### All Extensions Supported

| Extension | Extractor |
|-----------|-----------|
| `.docx` | DocxExtractor |
| `.md` | MarkdownExtractor |
| `.markdown` | MarkdownExtractor |
| `.mdown` | MarkdownExtractor |
| `.pdf` | PdfExtractor |
| `.txt` | TextExtractor |
| `.text` | TextExtractor |
| `.log` | TextExtractor |

### Findings

| Finding | Severity | Description |
|---------|----------|-------------|
| No `.doc` support | Low | DocxExtractor only handles `.docx`, not legacy `.doc` format |
| Limited PDF handling | Low | smalot/pdfparser may not handle scanned/image PDFs |
| No CSV/Excel support | Info | No extractor for `.csv`, `.xlsx`, `.xls` files |
| No HTML extractor | Info | No dedicated HTML text extractor |

### Recommendations

1. **Consider adding `.doc` support** - Legacy Word files may still be encountered
2. **Consider OCR fallback for PDFs** - For scanned documents (tesseract/OCR integration)
3. **Add CSV extractor** - Common format for data files
4. **Add Excel extractor** - PhpSpreadsheet could be used (already have phpoffice namespace)

### Overall Assessment

The extractor subsystem is well-designed and follows consistent patterns:
- All extractors implement the interface correctly
- All have proper error handling
- All are registered in the service provider
- No orphaned or unused extractors
- Good separation of concerns

**Status:** PASS
