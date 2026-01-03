# Module 09: FILE_CONTEXT - Documentation Updates

> **Status:** COMPLETED

## Recommended Documentation Additions

### 1. Security Configuration Guide

Add to main documentation:

```markdown
## File Context Security

### Access Control Model

The file context system uses a two-tier access model:

1. **Physical Files** - Documentation files from filesystem
   - Always publicly accessible
   - Intended for help docs, guides, FAQs
   - Configure paths in `ai.file_context.physical_paths`

2. **Database Files** - User-uploaded content
   - Subject to access control
   - Requires authenticated user
   - Permissions resolved per-user

### Enabling/Disabling Security

```php
// config/ai.php
'file_context' => [
    'security_enabled' => true, // Set false to disable access control
]
```

**WARNING:** Disabling security allows all users to access all database files.

### Custom Access Resolver

For complex permission logic, provide a closure:

```php
'file_context' => [
    'access_resolver' => function ($user) {
        // Return array of accessible file IDs
        return File::where('organization_id', $user->organization_id)
            ->pluck('id')
            ->toArray();
    },
]
```

### Fallback Access Logic

When no custom resolver or `accessibleBy` scope is available:

```php
'fallback_filters' => [
    'use_user_filter' => true,  // Files where user_id matches
    'use_team_filter' => true,  // Files where team_id matches
]
```

Files are accessible if user_id OR team_id matches.

**SECURITY NOTE:** If both filters are disabled, no database files will be accessible (fail-secure).
```

### 2. Physical File Configuration Guide

```markdown
## Configuring Physical Documentation Files

Physical files are indexed from the filesystem and made available to all users.

### Configuration

```php
// config/ai.php
'file_context' => [
    'base_path' => base_path(),
    'physical_paths' => [
        'docs/**/*.md',           // Recursive: all .md in docs/
        'help/*.txt',             // Non-recursive: .txt in help/
        'guides/**/*.mdx',        // Recursive: all .mdx in guides/
    ],
    'supported_extensions' => ['md', 'mdx', 'txt', 'rst'],
]
```

### Glob Pattern Syntax

| Pattern | Description |
|---------|-------------|
| `docs/*.md` | .md files in docs/ only |
| `docs/**/*.md` | .md files in docs/ and all subdirectories |
| `*.txt` | .txt files in base path |

### Security Considerations

- Physical files bypass ALL access control
- Only include documentation intended for public access
- Review `physical_paths` config before deployment
- Do not include user-uploaded directories
```

### 3. API Reference Updates

```markdown
## FileContextProvider

### searchRelevantFiles(string $question, mixed $user, array $options = []): array

Searches for files relevant to a question with access control.

**Parameters:**
- `$question` - Search query
- `$user` - Authenticated user (required when security enabled)
- `$options` - Optional overrides:
  - `limit` - Max results (default: 5)
  - `min_score` - Minimum relevance (default: 0.7)

**Returns:** Array of file references with keys:
- `file_id` - Unique identifier
- `filename` - Display name
- `snippet` - Content excerpt
- `relevance` - Score 0-1
- `chunk_index` - Position in file
- `source` - 'physical' or 'database'

**Throws:** `RuntimeException` when security enabled and user is null

### getFileContext(string $question, mixed $user): array

Convenience method returning structured context.

**Returns:**
- `relevant_files` - Array from searchRelevantFiles
- `file_count` - Number of files found
- `has_physical` - Boolean, physical files present
- `has_database` - Boolean, database files present
```

### 4. Troubleshooting Guide Addition

```markdown
## File Context Troubleshooting

### No Files Returned

1. **Check security configuration:**
   ```php
   config('ai.file_context.security_enabled') // true = requires auth
   ```

2. **Verify user is authenticated:**
   ```php
   $user = auth()->user(); // Must not be null
   ```

3. **Check access resolver:**
   - Custom closure returning empty array?
   - `accessibleBy` scope missing on File model?
   - Fallback filters both disabled?

4. **For physical files:**
   - Verify `physical_paths` patterns are correct
   - Check `supported_extensions` includes your file type
   - Ensure `base_path` is correct

### Physical Files Not Found

1. Check pattern syntax (use `/` not `\`)
2. Verify directory exists at `base_path`/pattern
3. Confirm file extensions are in `supported_extensions`

### Access Denied Errors

- User required when `security_enabled` is true
- Provide authenticated user to search methods
```

## Files That Need Updates

| File | Update Type | Description |
|------|-------------|-------------|
| `docs/configuration.md` | New Section | Add file context security configuration guide |
| `docs/api-reference.md` | Addition | Add FileContextProvider method documentation |
| `docs/troubleshooting.md` | Addition | Add file context troubleshooting section |
| `README.md` | Mention | Brief mention of file context feature with link to docs |
