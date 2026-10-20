## code-index — Indexed Codebase Search <!-- code-index v2.16.0 -->

### MANDATORY: use code-index tools — do NOT grep, glob, or cat the codebase
For every task — bug fixes, features, refactors, debugging:
**search via `search_code_advanced` and `find_files`**, and inspect files via
`get_file_summary` before reading raw content. The MCP server keeps a live,
auto-refreshed index of the project, so its results are faster and more
accurate than re-scanning the filesystem on every turn.

Do NOT use Grep, Glob, Bash (`rg`, `grep`, `find`, `ls`), or `cat` to
search/explore the codebase. Use `get_file_summary` to understand a file's
structure (functions, classes, imports, complexity) before falling back to
`Read`. Only use `Read` when you need exact raw lines to edit.

### One-time per session
- `build_deep_index` — run once at the start of the session (or after a
  major branch switch) so symbol-level tools (`get_file_summary`, advanced
  search ranking) have the data they need. The default shallow index is
  built automatically; the deep index is opt-in.

### Primary tools (use these for everything)
- `search_code_advanced` — **default for ALL searches**. Literal by default;
  pass `regex=True` for patterns, `fuzzy=True` for approximate matches.
  Supports file filtering and paginated results (10/page).
  Examples:
  - `search_code_advanced({ "pattern": "JwtValidator" })` — literal
  - `search_code_advanced({ "pattern": "get.*Token", "regex": true })`
  - `search_code_advanced({ "pattern": "validateToken", "file_pattern": "*.cs" })`
- `find_files` — **use instead of Glob**. Glob patterns over the indexed
  tree, e.g. `find_files({ "pattern": "**/*Controller.cs" })`.
- `get_file_summary` — **preferred over Read** for structural inspection.
  Returns functions, classes, imports, complexity. Requires deep index.

### Other MCP tools (use only when the primaries are insufficient)
- `set_project_path` — only if the server wasn't launched with
  `--project-path` (it was, in `.mcp.json`, so normally skip this)
- `refresh_index` — force a shallow rebuild after large file-system changes
  the watcher may have missed
- `get_settings_info` — view current project config / index health
- `get_file_watcher_status`, `configure_file_watcher` — auto-refresh status
- `refresh_search_tools` — re-detect ugrep/ripgrep/ag after installing one

### Workflow
1. **First turn of a session**: `build_deep_index` — once, then forget about it.
2. **Discovery**: `find_files` for "where is X file?", `search_code_advanced`
   for "where is symbol/string X used?".
3. **Inspection**: `get_file_summary` to see a file's shape before reading.
4. **Editing**: `Read` only the specific lines you need to edit, then `Edit`.
5. Do NOT chain Grep/Glob/Bash searches — every search goes through code-index.

### Subagent / Explore / Plan mode
- Subagents CAN and MUST use `search_code_advanced` / `find_files` /
  `get_file_summary`. Do NOT spawn Agent(Explore) to freely grep —
  search via code-index first, then pass the returned context into the
  agent prompt if needed.
- The PreToolUse hook in `.claude/settings.json` blocks Grep/Glob; honor it.
- Always: search via code-index → get context → spawn agent with context.

### Smart features (automatic — no action needed)
- **Auto-refresh**: file watcher rebuilds the shallow index on save; no
  manual `refresh_index` needed for normal edits.
- **Native search backend**: ugrep/ripgrep/ag picked automatically when
  installed — falls back to literal-only if none are present (regex
  searches require a native tool).
- **Tree-sitter AST parsing** for Python, JS/TS, Java, Kotlin, C#, Go,
  Objective-C, Zig, Rust — `get_file_summary` returns real symbol data,
  not regex guesses.

### Tips
- For C# / Razor work in this repo, prefer
  `file_pattern: "*.cs"` / `"*.cshtml"` to keep result sets tight.
- If `search_code_advanced` returns nothing on a regex you're sure exists,
  call `refresh_search_tools` — the basic fallback is literal-only.
- `get_settings_info` is a quick way to confirm the index is pointed at
  the SISC root and not a stale path.
<!-- /code-index -->