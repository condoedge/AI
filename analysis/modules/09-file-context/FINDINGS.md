# Module 09: FILE_CONTEXT - Findings

> **Status:** COMPLETED

## Architecture Overview

The file context module provides a unified file search system supporting two file types:
1. **Physical files** - Documentation files from filesystem (always public)
2. **Database files** - User-uploaded files (subject to access control)

### Component Responsibilities

| Component | Role |
|-----------|------|
| `FileContextProvider` | Main orchestrator - searches, filters, transforms results |
| `FileAccessResolver` | Access control - determines user permissions |
| `PhysicalFileIndexer` | File discovery - indexes filesystem documentation |

## File Access Control Mechanism

### Two-Tier Access Model

1. **Physical Files** (prefix: `physical:`)
   - Always accessible (bypass security checks)
   - Intended for documentation files
   - Identified by string ID starting with `physical:`

2. **Database Files** (integer IDs)
   - Subject to security enforcement
   - Requires authenticated user
   - Access resolved through configurable mechanisms

### Access Resolution Priority

```
1. Config closure resolver (ai.file_context.access_resolver)
2. FileModel::accessibleBy() scope
3. Fallback: user_id OR team_id filtering
```

### Security Enforcement

- Controlled via `config('ai.file_context.security_enabled')` (default: true)
- When disabled, all files are accessible
- When enabled, requires user authentication for database files

## Supported File Types

From `PhysicalFileIndexer`:
```php
config('ai.file_context.supported_extensions', ['md', 'mdx', 'txt', 'rst'])
```

Default extensions: **md, mdx, txt, rst** (documentation formats)

## Issues Found

| ID | Severity | Description | Evidence | Recommendation |
|----|----------|-------------|----------|----------------|
| FC-001 | HIGH | Physical files always bypass security | `FileAccessResolver::isPhysicalFile()` returns true for any string starting with `physical:`, and `canAccessFile()` returns true for all physical files | Ensure `physical_paths` config only includes intended documentation directories; consider restricting physical file prefix creation |
| FC-002 | MEDIUM | Potential path traversal in physical file IDs | `PhysicalFileIndexer::generateFileId()` creates IDs from paths but `getPhysicalFilePath()` simply strips prefix without validation | Add path canonicalization and validation that extracted path is within allowed base_path |
| FC-003 | LOW | Security can be globally disabled | `security_enabled` config defaults to true but can be set to false, bypassing all access control | Document security implications; consider environment-based restrictions |
| FC-004 | LOW | Fallback filter returns empty if disabled | When both `use_user_filter` and `use_team_filter` are false, returns empty array | This is secure-by-default behavior; document this explicitly |
| FC-005 | INFO | Non-strict comparison in array filtering | `in_array($id, $accessibleDbIds, false)` uses loose comparison | Use strict comparison (`true`) for type safety |

## Security Analysis

### Path Traversal Assessment

**PhysicalFileIndexer.php:**
- `discoverFiles()` uses Symfony Finder with configured patterns
- Patterns come from config, not user input (safe)
- Base path is configurable, could be manipulated if config is compromised

**FileAccessResolver.php:**
- `getPhysicalFilePath()` extracts path by stripping prefix only
- Does NOT validate the extracted path is within allowed directories
- Potential risk if physical file IDs are constructed from user input elsewhere

**Mitigation Status:**
- Physical file IDs are generated internally by `PhysicalFileIndexer`
- No direct user input to physical file ID construction observed
- Risk is LOW but should be hardened

### Unauthorized Access Prevention

**Strengths:**
1. User validation before file search (`validateUserForSecurity()`)
2. Access filtering applied to all search results
3. Physical/database file separation clear
4. Fallback returns empty array when no filters enabled (fail-secure)

**Weaknesses:**
1. Physical files are globally accessible (by design, but may leak sensitive docs)
2. No per-file permission checking (relies on query-based filtering)
3. `accessibleBy` scope failures silently fall back to user_id filter

## Configuration Dependencies

```php
// Security toggle
'ai.file_context.security_enabled' => true

// Custom access resolver (highest priority)
'ai.file_context.access_resolver' => Closure|null

// Fallback filters
'ai.file_context.fallback_filters.use_user_filter' => true
'ai.file_context.fallback_filters.use_team_filter' => true

// Physical file discovery
'ai.file_context.physical_paths' => []
'ai.file_context.base_path' => base_path()
'ai.file_context.supported_extensions' => ['md', 'mdx', 'txt', 'rst']

// Search parameters
'ai.file_context.min_relevance_score' => 0.7
'ai.file_context.max_references' => 5
'ai.file_context.snippet_length' => 200
```

## Positive Findings

1. **Clear separation of concerns** - Provider, resolver, and indexer have distinct responsibilities
2. **Secure defaults** - Security enabled by default, fallback returns empty if misconfigured
3. **Configurable access** - Supports custom closures for complex permission logic
4. **Type safety** - Uses strict typing (`declare(strict_types=1)`)
5. **Comprehensive documentation** - PHPDoc blocks explain behavior clearly
6. **Physical file extension filtering** - Only allows configured documentation formats
