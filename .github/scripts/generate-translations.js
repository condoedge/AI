#!/usr/bin/env node

/**
 * AI translation finalizer.
 *
 * Development flow:
 * 1. Developers add temporary translation keys with the `translate.` prefix.
 * 2. This script reads the full changed source files.
 * 3. Claude chooses final project-style keys, reusing existing keys when possible.
 * 4. The script replaces the temporary keys in source files and updates en/fr JSON.
 */

const fs = require('fs').promises;
const fsSync = require('fs');
const path = require('path');
const { execFileSync, spawnSync } = require('child_process');

const ROOT = process.cwd();
const CLAUDE_API_KEY = process.env.ANTHROPIC_API_KEY;
const CLAUDE_API_URL = 'https://api.anthropic.com/v1/messages';
const MODEL = process.env.ANTHROPIC_MODEL || 'claude-sonnet-4-5-20250929';
const DEFAULT_PROVIDER = process.env.TRANSLATION_PROVIDER || 'api';
const DEFAULT_CLAUDE_CLI_BIN = process.env.TRANSLATION_CLAUDE_CLI_BIN || 'claude';

const EN_JSON_PATH = path.join(ROOT, 'resources/lang/en.json');
const FR_JSON_PATH = path.join(ROOT, 'resources/lang/fr.json');

const SOURCE_EXTENSIONS = [
    '.php',
    '.blade.php',
    '.vue',
    '.js',
    '.jsx',
    '.ts',
    '.tsx',
];

const DEFAULT_MAX_FILE_CHARS = 120000;
const DEFAULT_MAX_TOTAL_FILE_CHARS = 350000;
const MAX_RELATED_TRANSLATIONS = 90;


async function main() {
    const args = parseArgs(process.argv.slice(2));

    console.log('Finding changed source files...');
    const changedFiles = await getChangedFiles(args);

    if (changedFiles.length === 0) {
        console.log('No changed source files found.');
        return;
    }

    console.log(`Changed source files considered: ${changedFiles.length}`);

    const contexts = await loadChangedFileContexts(changedFiles, args);
    const occurrences = groupOccurrences(contexts);

    if (occurrences.length === 0) {
        console.log('No translate.* temporary keys found in changed files.');
        return;
    }

    console.log(`Temporary translation keys found: ${occurrences.length}`);

    const enTranslations = await loadJSON(EN_JSON_PATH);
    const frTranslations = await loadJSON(FR_JSON_PATH);
    const relatedTranslations = collectRelatedTranslations(enTranslations, frTranslations, occurrences, contexts);
    const prompt = buildPrompt({ occurrences, contexts, relatedTranslations });

    console.log(`Related translation examples included: ${relatedTranslations.length}`);
    console.log(`Prompt size: ${prompt.length} chars`);

    if (args.dryRun) {
        console.log('Dry run enabled. Skipping Claude call and file writes.');
        return;
    }

    if (args.provider === 'api' && !CLAUDE_API_KEY) {
        throw new Error('ANTHROPIC_API_KEY environment variable is not set.');
    }

    let responseText;
    if (args.provider === 'claude-cli') {
        console.log(`Calling local Claude Code CLI (${args.claudeCliBin})...`);
        responseText = await callClaudeCLI(prompt, args);
    } else {
        console.log(`Calling Claude model ${MODEL}...`);
        responseText = await callClaudeAPI(prompt);
    }
    const claudePayload = parseClaudeJSON(responseText);
    const suggestions = validateSuggestions(claudePayload, occurrences, enTranslations, frTranslations);

    if (suggestions.length === 0) {
        console.log('No usable translation suggestions returned.');
        return;
    }

    console.log(`Applying ${suggestions.length} translation suggestions...`);
    const result = await applySuggestions({
        suggestions,
        contexts,
        enTranslations,
        frTranslations,
        cleanupOldKeys: args.cleanupOldKeys,
    });

    console.log('Done.');
    console.log(`Source replacements: ${result.sourceReplacements}`);
    console.log(`English translations added: ${result.enAdded}`);
    console.log(`French translations added: ${result.frAdded}`);
    console.log(`Existing locale values reused: ${result.reusedExisting}`);
    console.log(`Old translate.* JSON entries removed: ${result.oldKeysRemoved}`);
    console.log(`Keys kept unchanged: ${result.kept}`);
}

main().catch((error) => {
    console.error(`Fatal error: ${error.stack || error.message}`);
    process.exit(1);
});

function parseArgs(argv) {
    const args = {
        changedFiles: [],
        changedFilesFile: null,
        base: null,
        head: null,
        dryRun: false,
        cleanupOldKeys: process.env.TRANSLATION_CLEANUP_OLD_KEYS !== 'false',
        maxFileChars: parseInt(process.env.TRANSLATION_MAX_FILE_CHARS || `${DEFAULT_MAX_FILE_CHARS}`, 10),
        maxTotalFileChars: parseInt(process.env.TRANSLATION_MAX_TOTAL_FILE_CHARS || `${DEFAULT_MAX_TOTAL_FILE_CHARS}`, 10),
        provider: DEFAULT_PROVIDER,
        claudeCliBin: DEFAULT_CLAUDE_CLI_BIN,
    };

    for (let i = 0; i < argv.length; i++) {
        const arg = argv[i];

        if (arg === '--changed-files-file') {
            args.changedFilesFile = argv[++i];
        } else if (arg === '--changed-files') {
            args.changedFiles.push(...splitFileList(argv[++i] || ''));
        } else if (arg === '--base') {
            args.base = argv[++i];
        } else if (arg === '--head') {
            args.head = argv[++i];
        } else if (arg === '--dry-run') {
            args.dryRun = true;
        } else if (arg === '--no-cleanup-old-keys') {
            args.cleanupOldKeys = false;
        } else if (arg === '--max-file-chars') {
            args.maxFileChars = parseInt(argv[++i], 10);
        } else if (arg === '--max-total-file-chars') {
            args.maxTotalFileChars = parseInt(argv[++i], 10);
        } else if (arg === '--provider') {
            args.provider = (argv[++i] || '').toLowerCase();
        } else if (arg.startsWith('--provider=')) {
            args.provider = arg.slice('--provider='.length).toLowerCase();
        } else if (arg === '--claude-cli-bin') {
            args.claudeCliBin = argv[++i];
        } else if (arg === '--help') {
            printHelp();
            process.exit(0);
        } else {
            throw new Error(`Unknown argument: ${arg}`);
        }
    }

    if (!['api', 'claude-cli'].includes(args.provider)) {
        throw new Error(`Unknown --provider value: ${args.provider}. Use "api" or "claude-cli".`);
    }

    return args;
}

function printHelp() {
    console.log(`Usage: node .github/scripts/generate-translations.js [options]

Options:
  --changed-files-file <path>  Newline-delimited changed file list
  --changed-files <list>       Comma/newline-delimited changed file list
  --base <sha/ref>             Base ref for git diff fallback
  --head <sha/ref>             Head ref for git diff fallback
  --dry-run                    Analyze context without calling Claude or writing files
  --no-cleanup-old-keys        Keep old translate.* entries in lang JSON files
  --max-file-chars <number>    Maximum characters per changed file
  --max-total-file-chars <n>   Maximum total characters sent from changed files
  --provider <api|claude-cli>  LLM backend (default: api). claude-cli pipes the
                               prompt through the local 'claude' CLI in headless
                               mode (uses your Claude Code subscription, no API key).
  --claude-cli-bin <path>      Override the claude binary (default: 'claude').
`);
}

function splitFileList(value) {
    return value
        .split(/[\r\n,]+/)
        .map((file) => file.trim())
        .filter(Boolean);
}

async function readOptionalFile(filePath) {
    try {
        return await fs.readFile(filePath, 'utf8');
    } catch {
        return '';
    }
}

async function getChangedFiles(args) {
    const explicitFiles = [];

    if (args.changedFilesFile) {
        explicitFiles.push(...splitFileList(await readOptionalFile(args.changedFilesFile)));
    }

    explicitFiles.push(...args.changedFiles);

    if (process.env.TRANSLATION_CHANGED_FILES) {
        explicitFiles.push(...splitFileList(process.env.TRANSLATION_CHANGED_FILES));
    }

    if (explicitFiles.length > 0) {
        return normalizeChangedFiles(explicitFiles);
    }

    const eventFiles = getChangedFilesFromGitHubEvent();
    if (eventFiles.length > 0) {
        return normalizeChangedFiles(eventFiles);
    }

    const diffFiles = getChangedFilesFromGit(args);
    return normalizeChangedFiles(diffFiles);
}

function getChangedFilesFromGitHubEvent() {
    const eventPath = process.env.GITHUB_EVENT_PATH;
    const sha = process.env.GITHUB_SHA;

    if (!eventPath || !fsSync.existsSync(eventPath)) {
        return [];
    }

    try {
        const event = JSON.parse(fsSync.readFileSync(eventPath, 'utf8'));

        if (event.pull_request?.base?.sha && event.pull_request?.head?.sha) {
            return gitDiffNameOnly(event.pull_request.base.sha, event.pull_request.head.sha);
        }

        if (event.before && sha && !isZeroSha(event.before)) {
            return gitDiffNameOnly(event.before, sha);
        }
    } catch (error) {
        console.warn(`Warning: could not read GitHub event file: ${error.message}`);
    }

    return [];
}

function getChangedFilesFromGit(args) {
    if (args.base && args.head) {
        return gitDiffNameOnly(args.base, args.head);
    }

    const files = new Set();

    for (const commandArgs of [
        ['diff', '--name-only', '--cached'],
        ['diff', '--name-only', 'HEAD'],
    ]) {
        for (const file of gitOutputLines(commandArgs)) {
            files.add(file);
        }
    }

    if (files.size > 0) {
        return [...files];
    }

    return gitOutputLines(['diff', '--name-only', 'HEAD~1', 'HEAD']);
}

function gitDiffNameOnly(base, head) {
    return gitOutputLines(['diff', '--name-only', base, head]);
}

function gitOutputLines(args) {
    try {
        return execFileSync('git', args, { encoding: 'utf8' })
            .split(/\r?\n/)
            .map((line) => line.trim())
            .filter(Boolean);
    } catch {
        return [];
    }
}

function isZeroSha(value) {
    return /^0+$/.test(value || '');
}

function normalizeChangedFiles(files) {
    const seen = new Set();
    const normalized = [];

    for (const file of files) {
        const relative = toRelativePath(file);

        if (!relative || seen.has(relative) || !isSupportedSourceFile(relative)) {
            continue;
        }

        seen.add(relative);
        normalized.push(relative);
    }

    return normalized.sort();
}

function toRelativePath(file) {
    const normalized = file.replace(/\\/g, '/');
    const absolute = path.isAbsolute(normalized)
        ? path.resolve(normalized)
        : path.resolve(ROOT, normalized);
    const relative = path.relative(ROOT, absolute);

    if (relative.startsWith('..') || path.isAbsolute(relative)) {
        return null;
    }

    return relative.replace(/\\/g, '/');
}

function isSupportedSourceFile(file) {
    const normalized = file.replace(/\\/g, '/');

    if (
        normalized.startsWith('node_modules/') ||
        normalized.startsWith('vendor/') ||
        normalized.startsWith('.github/') ||
        normalized.startsWith('storage/') ||
        normalized.startsWith('bootstrap/cache/') ||
        normalized.startsWith('public/js/') ||
        normalized.startsWith('public/build/') ||
        normalized.startsWith('resources/lang/') ||
        normalized.includes('/node_modules/') ||
        normalized.includes('/vendor/')
    ) {
        return false;
    }

    return SOURCE_EXTENSIONS.some((extension) => normalized.endsWith(extension));
}

async function loadChangedFileContexts(files, args) {
    const contexts = [];
    let totalChars = 0;

    for (const file of files) {
        const absolute = path.join(ROOT, file);

        if (!fsSync.existsSync(absolute)) {
            console.warn(`Warning: changed file does not exist anymore, skipping ${file}`);
            continue;
        }

        const content = await fs.readFile(absolute, 'utf8');

        if (content.length > args.maxFileChars) {
            console.warn(`Warning: ${file} has ${content.length} chars, above --max-file-chars. Skipping it.`);
            continue;
        }

        if (totalChars + content.length > args.maxTotalFileChars) {
            console.warn(`Warning: changed file context budget reached. Skipping ${file}.`);
            continue;
        }

        const occurrences = findTranslateOccurrences(content, file);

        contexts.push({
            file,
            content,
            occurrences,
        });

        totalChars += content.length;
    }

    return contexts;
}

function findTranslateOccurrences(content, file) {
    const regex = /(['"`])(translate\.[A-Za-z0-9_.-]+)\1/g;
    const occurrences = [];
    const lineStarts = getLineStarts(content);
    let match;

    while ((match = regex.exec(content)) !== null) {
        const line = lineNumberAt(lineStarts, match.index);
        occurrences.push({
            key: match[2],
            file,
            line,
            sourceLine: getLine(content, line).trim(),
        });
    }

    return occurrences;
}

function getLineStarts(content) {
    const starts = [0];

    for (let i = 0; i < content.length; i++) {
        if (content[i] === '\n') {
            starts.push(i + 1);
        }
    }

    return starts;
}

function lineNumberAt(lineStarts, index) {
    let low = 0;
    let high = lineStarts.length - 1;

    while (low <= high) {
        const mid = Math.floor((low + high) / 2);

        if (lineStarts[mid] <= index) {
            low = mid + 1;
        } else {
            high = mid - 1;
        }
    }

    return high + 1;
}

function getLine(content, lineNumber) {
    return content.split(/\r?\n/)[lineNumber - 1] || '';
}

function groupOccurrences(contexts) {
    const grouped = new Map();

    for (const context of contexts) {
        for (const occurrence of context.occurrences) {
            if (!grouped.has(occurrence.key)) {
                grouped.set(occurrence.key, {
                    oldKey: occurrence.key,
                    locations: [],
                });
            }

            grouped.get(occurrence.key).locations.push({
                file: occurrence.file,
                line: occurrence.line,
                sourceLine: occurrence.sourceLine,
            });
        }
    }

    return [...grouped.values()].sort((a, b) => a.oldKey.localeCompare(b.oldKey));
}

async function loadJSON(filePath) {
    try {
        const content = await fs.readFile(filePath, 'utf8');
        return JSON.parse(content);
    } catch (error) {
        console.warn(`Warning: could not read ${filePath}, starting with empty object: ${error.message}`);
        return {};
    }
}

async function saveJSON(filePath, data) {
    const sortedData = Object.keys(data)
        .sort((a, b) => a.localeCompare(b))
        .reduce((acc, key) => {
            acc[key] = data[key];
            return acc;
        }, {});

    await fs.writeFile(filePath, `${JSON.stringify(sortedData, null, '\t')}\n`, 'utf8');
}

function collectRelatedTranslations(enTranslations, frTranslations, occurrences, contexts) {
    const namespaceCandidates = collectNamespaceCandidates(enTranslations, contexts);
    const allTokens = collectSearchTokens(occurrences, contexts);
    const directSuffixes = new Set(occurrences.map(({ oldKey }) => oldKey.replace(/^translate\./, '')));
    const rows = [];

    for (const [key, en] of Object.entries(enTranslations)) {
        if (key.startsWith('translate.')) {
            continue;
        }

        const fr = frTranslations[key];
        const score = scoreTranslationExample(key, en, fr, namespaceCandidates, allTokens, directSuffixes);

        if (score <= 0) {
            continue;
        }

        rows.push({
            key,
            en,
            fr,
            score,
        });
    }

    return rows
        .sort((a, b) => b.score - a.score || a.key.localeCompare(b.key))
        .slice(0, MAX_RELATED_TRANSLATIONS)
        .map(({ key, en, fr }) => ({ key, en, fr }));
}

function collectNamespaceCandidates(enTranslations, contexts) {
    const existingNamespaces = new Set(
        Object.keys(enTranslations)
            .filter((key) => key.includes('.'))
            .map((key) => key.split('.')[0])
    );
    const candidates = new Map();

    for (const context of contexts) {
        const parts = context.file.split('/');

        for (const part of parts) {
            const base = part.replace(/\.[^.]+$/, '');

            for (const candidate of candidateNamesFromSegment(base)) {
                if (existingNamespaces.has(candidate)) {
                    candidates.set(candidate, (candidates.get(candidate) || 0) + 1);
                }
            }
        }
    }

    return new Set(
        [...candidates.entries()]
            .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
            .slice(0, 12)
            .map(([candidate]) => candidate)
    );
}

function candidateNamesFromSegment(segment) {
    const kebab = toKebab(segment);
    const candidates = new Set([kebab]);

    if (kebab.endsWith('ies')) {
        candidates.add(`${kebab.slice(0, -3)}y`);
    }

    if (kebab.endsWith('s')) {
        candidates.add(kebab.slice(0, -1));
    }

    return [...candidates].filter(Boolean);
}

function collectSearchTokens(occurrences, contexts) {
    const tokens = new Set();

    for (const { oldKey } of occurrences) {
        for (const token of tokenize(oldKey.replace(/^translate\./, ''))) {
            tokens.add(token);
        }
    }

    for (const context of contexts) {
        for (const token of tokenize(context.file)) {
            tokens.add(token);
        }
    }

    return tokens;
}

function scoreTranslationExample(key, en, fr, namespaceCandidates, tokens, directSuffixes) {
    const lowerKey = key.toLowerCase();
    const lowerEn = `${en || ''}`.toLowerCase();
    const lowerFr = `${fr || ''}`.toLowerCase();
    const namespace = key.split('.')[0];
    let score = 0;

    if (namespaceCandidates.has(namespace)) {
        score += 14;
    }

    for (const suffix of directSuffixes) {
        if (lowerKey.endsWith(`.${suffix.toLowerCase()}`) || lowerKey === suffix.toLowerCase()) {
            score += 35;
        }
    }

    for (const token of tokens) {
        if (token.length < 3) {
            continue;
        }

        if (lowerKey.includes(token)) {
            score += 4;
        }

        if (lowerEn.includes(token) || lowerFr.includes(token)) {
            score += 2;
        }
    }

    return score;
}

function tokenize(value) {
    return `${value}`
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .toLowerCase()
        .split(/[^a-z0-9]+/)
        .filter((token) => token.length >= 2 && !['app', 'kompo', 'php', 'vue', 'js', 'tsx', 'jsx'].includes(token));
}

function toKebab(value) {
    return `${value}`
        .replace(/([a-z])([A-Z])/g, '$1-$2')
        .replace(/_/g, '-')
        .toLowerCase()
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function buildPrompt({ occurrences, contexts, relatedTranslations }) {
    const promptPayload = {
        temporaryKeys: occurrences,
        relatedExistingTranslations: relatedTranslations,
        changedFiles: contexts.map(({ file, content }) => ({
            file,
            content,
        })),
    };

    return `You are maintaining translations for SISC, a Laravel/PHP and Kompo application.

Developers use temporary translation keys that start with "translate.". Your task is to finalize them.

Use the full changed files as primary context. Use relatedExistingTranslations to learn naming style, domain vocabulary, English/French tone, and to reuse existing keys when the meaning already exists.

Rules:
- Return JSON only. Do not include markdown or explanations outside JSON.
- Return exactly one item for every temporary key.
- Choose a final key that does not start with "translate.".
- Prefer an existing key from relatedExistingTranslations when it already means the same thing in context.
- New keys should be lowercase dot-separated namespaces with hyphenated slugs, for example "teams.invite-someone-to-committee".
- Choose the namespace from the real feature context, not from the temporary key alone.
- Preserve Laravel placeholders such as ":count", ":name", and ":team" exactly in both languages.
- Keep HTML tags only when the UI context clearly requires them.
- For English, use concise professional UI language.
- For French, follow the existing French wording and domain vocabulary in relatedExistingTranslations.
- If a temporary key is clearly not user-facing text, set "action" to "keep".

JSON schema:
{
  "items": [
    {
      "oldKey": "translate.example",
      "newKey": "feature.final-key",
      "action": "replace",
      "reuseExisting": false,
      "en": "English text, or null when reusing an existing key",
      "fr": "French text, or null when reusing an existing key"
    }
  ]
}

Context payload:
${JSON.stringify(promptPayload, null, 2)}
`;
}

async function callClaudeAPI(prompt) {
    const response = await fetch(CLAUDE_API_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'x-api-key': CLAUDE_API_KEY,
            'anthropic-version': '2023-06-01',
        },
        body: JSON.stringify({
            model: MODEL,
            max_tokens: 8192,
            messages: [
                {
                    role: 'user',
                    content: prompt,
                },
            ],
        }),
    });

    if (!response.ok) {
        const error = await response.text();
        throw new Error(`Claude API error: ${response.status} - ${error}`);
    }

    const data = await response.json();
    return data.content?.map((part) => part.text || '').join('\n') || '';
}

async function callClaudeCLI(prompt, args) {
    const cliArgs = [
        '-p',
        '--output-format', 'text',
        '--permission-mode', 'bypassPermissions',
    ];

    const result = spawnSync(args.claudeCliBin, cliArgs, {
        input: prompt,
        encoding: 'utf8',
        maxBuffer: 64 * 1024 * 1024,
    });

    if (result.error) {
        throw new Error(
            `Failed to spawn Claude CLI '${args.claudeCliBin}': ${result.error.message}. ` +
            `Is it installed and on PATH?`
        );
    }

    if (result.status !== 0) {
        const stderr = (result.stderr || '').toString().trim();
        throw new Error(`Claude CLI exited with code ${result.status}: ${stderr}`);
    }

    return (result.stdout || '').toString();
}

function parseClaudeJSON(responseText) {
    try {
        return JSON.parse(responseText);
    } catch {
        const start = responseText.indexOf('{');
        const end = responseText.lastIndexOf('}');

        if (start === -1 || end === -1 || end <= start) {
            throw new Error('Claude response did not contain a JSON object.');
        }

        return JSON.parse(responseText.slice(start, end + 1));
    }
}

function validateSuggestions(payload, occurrences, enTranslations, frTranslations) {
    if (!payload || !Array.isArray(payload.items)) {
        throw new Error('Claude JSON must contain an "items" array.');
    }

    const expectedKeys = new Set(occurrences.map(({ oldKey }) => oldKey));
    const existingKeys = new Set([...Object.keys(enTranslations), ...Object.keys(frTranslations)]);
    const valid = [];

    for (const item of payload.items) {
        const oldKey = `${item.oldKey || ''}`.trim();
        const newKey = `${item.newKey || ''}`.trim();
        const action = `${item.action || 'replace'}`.trim();

        if (!expectedKeys.has(oldKey)) {
            console.warn(`Warning: ignoring unexpected Claude item for ${oldKey || '(empty key)'}`);
            continue;
        }

        if (action === 'keep') {
            valid.push({
                oldKey,
                newKey: oldKey,
                action,
                reuseExisting: false,
                en: stringOrNull(item.en),
                fr: stringOrNull(item.fr),
            });
            continue;
        }

        if (!newKey) {
            console.warn(`Warning: missing newKey for ${oldKey}, skipping.`);
            continue;
        }

        if (newKey.startsWith('translate.')) {
            console.warn(`Warning: Claude kept translate prefix for ${oldKey}, skipping.`);
            continue;
        }

        if (!existingKeys.has(newKey) && !/^[a-z0-9][a-z0-9_.-]*[a-z0-9]$/.test(newKey)) {
            console.warn(`Warning: invalid new key "${newKey}" for ${oldKey}, skipping.`);
            continue;
        }

        const keyExists = existingKeys.has(newKey);
        const en = stringOrNull(item.en);
        const fr = stringOrNull(item.fr);

        if (!keyExists && (!en || !fr)) {
            console.warn(`Warning: new key ${newKey} for ${oldKey} is missing en/fr text, skipping.`);
            continue;
        }

        warnOnPlaceholderMismatch(newKey, en, fr);

        valid.push({
            oldKey,
            newKey,
            action,
            reuseExisting: Boolean(item.reuseExisting || keyExists),
            en,
            fr,
        });
    }

    for (const expectedKey of expectedKeys) {
        if (!valid.some(({ oldKey }) => oldKey === expectedKey)) {
            console.warn(`Warning: Claude did not return a usable item for ${expectedKey}`);
        }
    }

    return valid;
}

function stringOrNull(value) {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    return trimmed.length > 0 ? trimmed : null;
}

function warnOnPlaceholderMismatch(key, en, fr) {
    if (!en || !fr) {
        return;
    }

    const enPlaceholders = extractPlaceholders(en);
    const frPlaceholders = extractPlaceholders(fr);

    if (enPlaceholders.join('|') !== frPlaceholders.join('|')) {
        console.warn(`Warning: placeholder mismatch for ${key}: en=[${enPlaceholders}] fr=[${frPlaceholders}]`);
    }
}

function extractPlaceholders(value) {
    const matches = `${value}`.match(/:[A-Za-z_][A-Za-z0-9_]*/g) || [];
    return [...new Set(matches)].sort();
}

async function applySuggestions({ suggestions, contexts, enTranslations, frTranslations, cleanupOldKeys }) {
    const result = {
        sourceReplacements: 0,
        enAdded: 0,
        frAdded: 0,
        oldKeysRemoved: 0,
        reusedExisting: 0,
        kept: 0,
    };

    for (const suggestion of suggestions) {
        if (suggestion.action === 'keep' || suggestion.oldKey === suggestion.newKey) {
            result.kept++;
            continue;
        }

        for (const context of contexts) {
            const nextContent = replaceTranslationKey(context.content, suggestion.oldKey, suggestion.newKey);

            if (nextContent !== context.content) {
                result.sourceReplacements += countKeyOccurrences(context.content, suggestion.oldKey);
                context.content = nextContent;
            }
        }

        if (enTranslations[suggestion.newKey]) {
            result.reusedExisting++;
        } else if (suggestion.en) {
            enTranslations[suggestion.newKey] = suggestion.en;
            result.enAdded++;
        }

        if (frTranslations[suggestion.newKey]) {
            result.reusedExisting++;
        } else if (suggestion.fr) {
            frTranslations[suggestion.newKey] = suggestion.fr;
            result.frAdded++;
        }
    }

    for (const context of contexts) {
        await fs.writeFile(path.join(ROOT, context.file), context.content, 'utf8');
    }

    if (cleanupOldKeys) {
        for (const suggestion of suggestions) {
            if (suggestion.oldKey === suggestion.newKey || suggestion.action === 'keep') {
                continue;
            }

            if (!isKeyReferencedInRepo(suggestion.oldKey)) {
                if (Object.prototype.hasOwnProperty.call(enTranslations, suggestion.oldKey)) {
                    delete enTranslations[suggestion.oldKey];
                    result.oldKeysRemoved++;
                }

                if (Object.prototype.hasOwnProperty.call(frTranslations, suggestion.oldKey)) {
                    delete frTranslations[suggestion.oldKey];
                    result.oldKeysRemoved++;
                }
            }
        }
    }

    await saveJSON(EN_JSON_PATH, enTranslations);
    await saveJSON(FR_JSON_PATH, frTranslations);

    return result;
}

function replaceTranslationKey(content, oldKey, newKey) {
    const regex = new RegExp(`(['"\`])${escapeRegExp(oldKey)}\\1`, 'g');
    return content.replace(regex, `$1${newKey}$1`);
}

function countKeyOccurrences(content, key) {
    const regex = new RegExp(`(['"\`])${escapeRegExp(key)}\\1`, 'g');
    return (content.match(regex) || []).length;
}

function escapeRegExp(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function isKeyReferencedInRepo(key) {
    const result = spawnSync(
        'rg',
        [
            '--fixed-strings',
            '--quiet',
            '--glob',
            '!resources/lang/**',
            '--glob',
            '!vendor/**',
            '--glob',
            '!node_modules/**',
            '--glob',
            '!.github/**',
            '--glob',
            '!public/js/**',
            key,
            '.',
        ],
        {
            cwd: ROOT,
            stdio: 'ignore',
        }
    );

    if (result.error) {
        return true;
    }

    return result.status === 0;
}
