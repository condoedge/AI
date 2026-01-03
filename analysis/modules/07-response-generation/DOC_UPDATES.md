# Module 07: Response Generation - Documentation Updates

> **Status:** COMPLETE

## Required Changes

| Doc Path | Change Type | Description |
|----------|-------------|-------------|
| `src/Services/ResponseSections/PrivacyAndSecurityGuidelinesSection.php` | Fix docstring | Line 12 says "Priority: 15" but actual is 1000. Update to match actual value or change code. |
| `src/Services/ResponseSections/BaseResponseSection.php` | Enhancement | Consider adding abstract `format()` method declaration for clarity |
| `src/Services/ResponseGenerator.php` | i18n | Lines 339, 340, 350, 392-401 contain hardcoded English text that should use translation helpers |
| N/A | New doc needed | Document the ResponseFileEnricher usage pattern and citation flow |

## Code Suggestions

### 1. PrivacyAndSecurityGuidelinesSection Priority Clarification

**Option A: Fix docstring to match code**
```php
/**
 * PrivacyAndSecurityGuidelinesSection
 *
 * Security and data privacy guidelines
 * Priority: 1000 (Appearing at the end to ensure compliance emphasis)
 */
```

**Option B: Change priority to match docstring**
```php
protected int $priority = 15;
```

### 2. ResponseGenerator i18n

Replace hardcoded strings:
```php
// Before:
$prompt .= "Please explain in a friendly way why there might be no results. ";

// After:
$prompt .= __('ai.response.explain_no_results');
```

### 3. BaseResponseSection Enhancement

```php
abstract class BaseResponseSection implements ResponseSectionInterface
{
    // ... existing code ...

    /**
     * Format the section content
     *
     * @param array $context Context with question, data, etc.
     * @param array $options Options for formatting
     * @return string Formatted section content
     */
    abstract public function format(array $context, array $options = []): string;
}
```

## Translation Keys Needed

| Key | Current Hardcoded Text |
|-----|----------------------|
| `ai.response.explain_no_results` | "Please explain in a friendly way why there might be no results." |
| `ai.response.suggest_alternatives` | "Suggest what the user could try instead or how to rephrase their question." |
| `ai.response.no_results_fallback` | "No results were found for your question: \"{question}\". You might want to try rephrasing or checking if the data you're looking for exists." |
| `ai.response.error_encountered` | "I encountered an issue while trying to answer your question: \"{question}\"." |
| `ai.response.error_timeout` | "The query took too long to execute. Try asking a more specific question or limiting the scope." |
| `ai.response.error_syntax` | "There was an issue with the generated query. Please try rephrasing your question." |
| `ai.response.error_generic` | "Please try rephrasing your question or contact support if the issue persists." |
