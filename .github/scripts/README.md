# Auto-Translation GitHub Action

This workflow finalizes temporary translation keys created with the `translate.` prefix.

## Flow

1. A developer adds temporary keys in source code, for example `__('translate.add-camp-types')`.
2. The GitHub Action collects the full source files changed in the current commit range.
3. `generate-translations.js` sends those full files, the `translate.*` occurrences, and related existing translation examples to Claude.
4. Claude returns final project-style keys and English/French values.
5. The script replaces source usages of `translate.*` and updates:
   - `resources/lang/en.json`
   - `resources/lang/fr.json`

## Required Secret

Add `ANTHROPIC_API_KEY` in repository settings under Secrets and variables -> Actions.

You can override the model with `ANTHROPIC_MODEL`.

## Manual Usage

Analyze local changed files without calling Claude:

```bash
node .github/scripts/generate-translations.js --dry-run
```

Run against an explicit file list:

```bash
node .github/scripts/generate-translations.js --changed-files-file changed-files.txt
```

Run against a git range:

```bash
node .github/scripts/generate-translations.js --base origin/dev --head HEAD
```

## Context Sent To Claude

The script sends:

- full contents of the changed source files, within configured size limits
- each `translate.*` key with file, line, and source line
- related existing `en.json` and `fr.json` pairs selected by namespace, file path, and similar words

This lets Claude infer the feature namespace, reuse existing keys, preserve placeholders like `:count`, and produce translations consistent with the application vocabulary.

## Limits

Defaults:

- `TRANSLATION_MAX_FILE_CHARS=120000`
- `TRANSLATION_MAX_TOTAL_FILE_CHARS=350000`

Raise these only when the changed files are genuinely useful translation context.

## Review Checklist

Always review the generated PR:

- final keys no longer start with `translate.`
- source replacements point to the right namespace
- English/French values are accurate in UI context
- placeholders are preserved in both languages
- existing translations were reused when appropriate
