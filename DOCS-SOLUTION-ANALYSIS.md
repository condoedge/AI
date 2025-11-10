# Documentation Solution Analysis

## Goal
Create an easy-to-use, auto-updating documentation system for the AI Text-to-Query system that:
- Lives in a separate `docs/` folder
- Provides beautiful, readable documentation for developers
- Integrates with Laravel via `web.php` (only when enabled in config)
- Requires minimal maintenance
- Can be auto-generated/updated by an agent

---

## Options Analysis

### 1. **Larecipe** ⭐ RECOMMENDED
**Package:** `binarytorch/larecipe`

**Pros:**
- ✅ Pure PHP/Laravel package (no Node.js required)
- ✅ Beautiful, modern UI (similar to Docusaurus)
- ✅ Markdown-based documentation
- ✅ Built-in search functionality
- ✅ Versioning support
- ✅ Easy Laravel integration
- ✅ Can be toggled via config
- ✅ Supports code highlighting
- ✅ Mobile responsive
- ✅ Customizable themes

**Cons:**
- ⚠️ Package not very actively maintained (last update ~2021)
- ⚠️ May need forking for long-term support

**Installation:**
```bash
composer require binarytorch/larecipe
php artisan larecipe:install
```

**Integration:**
```php
// Routes automatically registered, can be disabled
'docs.enabled' => env('AI_DOCS_ENABLED', true),
```

**File Structure:**
```
resources/docs/
├── 1.0/               # Version 1.0
│   ├── index.md
│   ├── overview.md
│   ├── installation.md
│   ├── usage.md
│   └── api.md
└── 2.0/               # Version 2.0
    └── ...
```

**Agent Integration:**
- Agent can write markdown files to `resources/docs/1.0/`
- Auto-generates from current codebase state
- Updates on demand

---

### 2. **Custom Laravel Markdown Solution** ⭐ ALTERNATIVE
**Packages:** `league/commonmark` + custom controller

**Pros:**
- ✅ Full control over implementation
- ✅ No external dependencies
- ✅ Can integrate with existing Kompo components
- ✅ Uses familiar Laravel patterns
- ✅ Easy to maintain and customize
- ✅ No version lock-in

**Cons:**
- ⚠️ Need to build UI from scratch
- ⚠️ No built-in search (need to add)
- ⚠️ More development time

**Implementation:**
```php
// Controller
class DocsController extends Controller
{
    public function show($page)
    {
        $markdown = file_get_contents(resource_path("docs/{$page}.md"));
        $html = (new CommonMarkConverter())->convert($markdown);
        return view('docs.show', ['content' => $html]);
    }
}

// Route
Route::get('/docs/{page}', [DocsController::class, 'show']);
```

**File Structure:**
```
resources/docs/
├── installation.md
├── configuration.md
├── usage/
│   ├── simple-approach.md
│   └── advanced-approach.md
└── api/
    ├── data-ingestion.md
    └── context-retrieval.md
```

---

### 3. **Docusaurus**
**Type:** Node.js static site generator

**Pros:**
- ✅ Industry standard (used by Meta, Microsoft, etc.)
- ✅ Excellent search and navigation
- ✅ Versioning built-in
- ✅ MDX support (React in Markdown)
- ✅ Active development

**Cons:**
- ❌ Requires Node.js runtime
- ❌ Separate build process
- ❌ More complex integration with Laravel
- ❌ Need to serve static files or proxy

**Not Recommended Because:**
- Adds Node.js dependency
- Complicates Laravel deployment
- Overkill for internal documentation

---

### 4. **VitePress**
**Type:** Vue-based static site generator

**Pros:**
- ✅ Very fast (Vite-powered)
- ✅ Simple markdown files
- ✅ Beautiful default theme
- ✅ Vue 3 components support

**Cons:**
- ❌ Requires Node.js/Vite
- ❌ Separate build process
- ❌ Same integration issues as Docusaurus

**Not Recommended Because:**
- Same reasons as Docusaurus
- Adds unnecessary complexity

---

### 5. **Daux.io**
**Type:** PHP markdown documentation generator

**Pros:**
- ✅ Pure PHP (no Node.js)
- ✅ Simple folder-based structure
- ✅ Auto-generates table of contents
- ✅ Live mode or static generation

**Cons:**
- ⚠️ Less actively maintained
- ⚠️ Basic UI (not as polished)
- ⚠️ Limited customization

**Could Work But:**
- Less polished than Larecipe
- Similar maintenance concerns

---

### 6. **BookStack**
**Type:** Full documentation platform

**Pros:**
- ✅ Laravel-based
- ✅ Rich editor
- ✅ User management
- ✅ Very actively maintained

**Cons:**
- ❌ Separate application (not a package)
- ❌ Database-driven (not file-based)
- ❌ Overkill for code documentation
- ❌ Requires separate installation

**Not Recommended Because:**
- Too heavyweight for this use case
- Not file-based (can't auto-generate easily)

---

## Final Recommendation

### Primary: **Custom Kompo-Based Solution** ⭐⭐⭐

**Why:**
1. You already have Kompo components for `AiDocsIndex`, `AiDocsArchitecture`, etc.
2. Full control and consistency with existing UI
3. No external package dependencies
4. Can integrate perfectly with existing routing and configuration
5. Easy for agent to generate and update
6. Uses familiar Laravel/Kompo patterns

**Approach:**
- Extend existing `AiDocs*` Kompo components
- Add markdown rendering capability
- Create file-based documentation structure
- Agent generates markdown files
- Beautiful UI using Tailwind (already in use)
- Integrated search using simple text matching

**Architecture:**
```
resources/docs/
├── getting-started/
│   ├── installation.md
│   ├── configuration.md
│   └── quick-start.md
├── architecture/
│   ├── overview.md
│   ├── domain-layer.md
│   └── services.md
├── usage/
│   ├── simple-approach.md
│   ├── advanced-approach.md
│   └── laravel-integration.md
├── api/
│   ├── data-ingestion.md
│   ├── context-retrieval.md
│   ├── embeddings.md
│   └── llm.md
└── examples/
    ├── basic-usage.md
    ├── advanced-usage.md
    └── real-world.md

src/Kompo/Docs/
├── DocsLayout.php          # Main layout
├── DocsIndex.php           # Home page
├── DocsViewer.php          # Markdown viewer
├── DocsSidebar.php         # Navigation
└── DocsSearch.php          # Search component

src/Http/Controllers/
└── DocsController.php      # Routes handler
```

**Benefits:**
- ✅ No external dependencies
- ✅ Consistent with existing system
- ✅ Full customization control
- ✅ Easy for agent to maintain
- ✅ File-based (version control friendly)
- ✅ Can be toggled via config
- ✅ Beautiful UI with Kompo + Tailwind

---

### Secondary: **Larecipe** (If you prefer out-of-the-box)

If you want something ready-made:
- Install Larecipe
- Customize theme to match your design
- Agent writes to `resources/docs/`
- Works immediately

**Trade-off:**
- Less control
- Package maintenance concern
- But saves initial development time

---

## Recommended Agent Capabilities

The **docs agent** should be able to:

1. **Analyze Codebase**
   - Read all source files
   - Extract class documentation
   - Identify patterns and conventions
   - Understand service relationships

2. **Generate Documentation**
   - Create markdown files for each major component
   - Generate API reference from PHPDoc
   - Create usage examples from tests
   - Build architecture diagrams (Mermaid)

3. **Organize Content**
   - Categorize by topic (Getting Started, Architecture, API, Examples)
   - Create navigation structure
   - Generate table of contents
   - Add cross-references

4. **Keep Updated**
   - Detect code changes
   - Update relevant docs
   - Maintain consistency
   - Version documentation

5. **Generate Examples**
   - Extract working examples from tests
   - Create realistic scenarios
   - Show both simple and advanced usage
   - Include Laravel integration examples

---

## Implementation Plan

### Phase 1: Infrastructure (Custom Kompo Solution)
1. Create `resources/docs/` folder structure
2. Build Kompo documentation components
3. Add markdown rendering with syntax highlighting
4. Create navigation and search
5. Integrate with existing routes (config-based toggle)

### Phase 2: Content Generation (Docs Agent)
1. Create docs agent in orchestrator skill
2. Implement codebase analysis
3. Generate initial documentation set
4. Create API reference from code
5. Build usage examples

### Phase 3: Maintenance
1. Add command to regenerate docs
2. Create CI/CD hook for auto-updates
3. Add version detection
4. Implement change tracking

---

## Configuration Integration

```php
// config/ai.php
return [
    // ... existing config

    'docs' => [
        'enabled' => env('AI_DOCS_ENABLED', true),
        'route_prefix' => 'ai-docs',
        'path' => resource_path('docs'),
        'theme' => 'default',
        'search' => true,
        'versions' => ['1.0', '2.0'],
        'default_version' => '1.0',
    ],
];
```

```php
// routes/web.php
if (config('ai.docs.enabled')) {
    Route::prefix(config('ai.docs.route_prefix'))->group(function () {
        Route::get('/', [DocsController::class, 'index'])->name('ai.docs.index');
        Route::get('/{page}', [DocsController::class, 'show'])->name('ai.docs.show');
        Route::get('/search', [DocsController::class, 'search'])->name('ai.docs.search');
    });
}
```

---

## Decision Matrix

| Solution | Laravel Native | No Build Step | Easy Maintenance | Beautiful UI | Agent-Friendly | Score |
|----------|---------------|---------------|------------------|--------------|----------------|-------|
| **Custom Kompo** | ✅ Yes | ✅ Yes | ✅ Yes | ✅ Yes | ✅ Yes | 5/5 ⭐ |
| **Larecipe** | ✅ Yes | ✅ Yes | ⚠️ Medium | ✅ Yes | ✅ Yes | 4/5 |
| **Custom Markdown** | ✅ Yes | ✅ Yes | ✅ Yes | ⚠️ Basic | ✅ Yes | 4/5 |
| **Docusaurus** | ❌ No | ❌ No | ✅ Yes | ✅ Yes | ⚠️ Medium | 2/5 |
| **VitePress** | ❌ No | ❌ No | ✅ Yes | ✅ Yes | ⚠️ Medium | 2/5 |
| **Daux.io** | ✅ Yes | ✅ Yes | ⚠️ Medium | ⚠️ Basic | ✅ Yes | 3/5 |

---

## Final Decision: **Custom Kompo-Based Documentation System**

**Rationale:**
1. You already have the infrastructure (Kompo components, routing, controllers)
2. Maintains consistency with existing AiDocs components
3. No external dependencies or maintenance concerns
4. Full control over design and functionality
5. Perfect integration with Laravel and your existing architecture
6. Easy for agent to generate and maintain
7. File-based (version control friendly, no database needed)

**Next Steps:**
1. Extend existing Kompo docs components
2. Add markdown rendering capability
3. Create file-based documentation structure
4. Update orchestrator skill to include docs agent
5. Implement docs generation agent

Would you like me to proceed with this approach?
