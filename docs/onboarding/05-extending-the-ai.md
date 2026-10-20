# Extending the AI per software — the harness map

> Every extension point below is config/class-registration only — no package edits. This is
> the contract that makes the assistant adaptable per software (CondoEdge, SISC, ADF…).
> Verified against source 2026-06-11.

## 1. Actions — the main extension surface

An action is anything the assistant can DO or OFFER: navigate, open a form, mutate a record
(approval-gated), export a file, render UI.

### 1a. Config-array actions (fastest)

`config/ai.php` → `entity_actions` (per graph label) and `generic_actions`:

```php
'generic_actions' => [
    'payables' => [
        'action' => fn ($text = 'Payables') => _Link($text)->href('bills.table'),
        'aliases' => ['payables', 'bills to pay'],     // selection hints
        'label' => 'Payables',
        'authorize' => $boardOrStaff,                  // advertisement + render gate
    ],
],
```

- `authorize($user, $input): bool` is checked at ADVERTISEMENT time (the prompt only offers
  what this user passes — 90c W6) and again at RENDER time. Fail closed.
- Mutating config actions: build a `ModelMethodMutationAction` (see the `$mutation` helper at
  the top of CondoEdge's ai.php) — `mutates() === true` gets the signed-token approval flow
  for free (deferred, re-authorized server-side against the resolved row, single-use nonce).

### 1b. Class actions (full power) — the "plugin" pattern

Implement `Condoedge\Ai\Contracts\AiActionInterface` (or extend
`Services\Actions\BuiltIn\AbstractBuiltInAction`) and register the class-string in the flat
`config('ai.actions')` array — the registry instantiates it beside the built-ins:

```php
'actions' => [
    \App\Ai\Actions\ExportRollCallAction::class,
],
```

`handle(array $input, ActionContext $context): AiActionResult` receives a typed context:

| Field | What it carries |
|---|---|
| `user`, `team` | the acting principal (never a stale static auth()) |
| `entityType`, `entityId` | for `entity://Type/id/action` links |
| `input` | schema-filtered params from the link's query string |
| `resultSet` | the current/last tabular query result |
| `answer` | the FULL composed answer the link is embedded in (document-style actions) |

Return one of `AiActionResult::element() / table() / export() / pending() / error()` — a
single presenter renders all kinds, for markdown links and persisted message invocations
alike.

### 1c. The export family (built-in, reusable as a pattern)

- `export_excel` / `export_pdf` with `mode=table` → result-set dump (PII/id-column redaction
  via `ResultSetExporter::redact`, tunable `config('ai.export.*')`).
- `mode=document` → renders the LLM-COMPOSED answer: PDF via markdown→HTML→dompdf (styles:
  `config('ai.export.pdf_document_styles')`); Excel parses the answer's markdown tables —
  header row = custom column headers, heading = worksheet name, `=SUM(C2:C10)`-style
  allowlisted formulas stay LIVE (other formula-leading cells are injection-neutralized).
- A new file-producing action only needs to return `AiActionResult::export(bytes, mime, name)`.

## 2. Teaching the model the domain (prompt-side config)

| Config | Teaches | Section |
|---|---|---|
| `ai.project.business_rules` | free-text query rules ("unit 310 means name=310") | ProjectContextSection |
| `ai.calculations` | NAMED canonical metrics: label/description/formula/cypher/notes (e.g. "total owed to a team = SUM(Invoice.calc_due_amount > 0)") | CalculationsSection |
| `ai.guardrails.domain_scope` | one sentence: what IS this software's domain; the decline for unrelated questions names it | ResponseGuardrailsSection |
| `ai.identity.*` | name/persona/language/non-disclosure (+ capability grammar: when to offer which export mode) | IdentitySection |
| entity catalogs `aliases`/`description` | SELECTION — which entity answers which words (keep alias ownership unambiguous; re-run `ai:index-semantic` + `ai:index-context` after edits) | semantic index |
| `ai.user_entity_paths.{Label}` | possessive questions ("my X") with the exact traversal | generation prompt |
| `ai.chat.example_questions` / `page_examples.{Label}` | welcome + per-page suggestions | MessagesQuery |

## 3. Gotchas that bite every time

1. `mergeConfigFrom` is top-level-shallow: overriding a top-level array key replaces the
   package's WHOLE array (the W1 incident). Never copy a package list unless you re-diff it
   on every upgrade.
2. Alias/description/scope edits do nothing until the semantic indexes are rebuilt.
3. Data changes (casts, new synced columns, polymorphic edges) need re-ingest; edges need
   `ai:sync-relationships`.
4. Action `authorize` closures must fail closed (try/catch around role checks that can
   abort).
