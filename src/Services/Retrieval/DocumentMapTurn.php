<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

use Condoedge\Ai\Contracts\ChunkStoreInterface;
use Condoedge\Ai\Contracts\PipelineObserverInterface;
use Condoedge\Ai\DTOs\FileChunk;
use Condoedge\Ai\Services\Context\FileContextProvider;
use Condoedge\Ai\Support\ArticleLocator;
use Condoedge\Ai\Support\DocumentMap;
use Condoedge\Ai\Support\DocumentScopeLabel;
use Condoedge\Ai\Support\FileLink;
use Condoedge\Ai\Support\GuardedPipelineObserver;
use Condoedge\Ai\Support\PipelineObserverScope;

/**
 * DocumentMapTurn — what a document turn does with the map of its documents.
 *
 * Today a document question costs two focused calls around the search: the search
 * probes ({@see DocumentProbeWriter}, written before anything was read) and the
 * passage selection ({@see PassageSelector}, which may add ONE passage out of a
 * pool of near misses). For documents that carry a MAP — one gist line per chunk,
 * written at index time ({@see DocumentMap}) — the caller replaces both by ONE call
 * made AFTER the plain search ({@see DocumentMapReader}), and a GATE:
 *
 *  - the READER reads the question against the map of every file the search
 *    selected a passage of, and names the chunks a complete answer must rely on,
 *    each with its role;
 *  - the GATE is OPEN when a pick of a governing role — the general rule, the
 *    classification, the approval rule — is not among the chunks of the search's
 *    passages: the reader saw further than the search. CLOSED otherwise: then the
 *    turn IS the plain-search turn, its passages returned untouched and no word of
 *    the prompt changed. A reading that agrees with the search must cost the
 *    answer nothing;
 *  - OPEN: the picks lead, ordered by role (the rule, then what it turns on, then
 *    who decides, then what follows), the remaining search passages follow, and
 *    the composer is told which rules govern
 *    ({@see \Condoedge\Ai\Services\ResponseSections\QuestionAnalysisSection}).
 *
 * The model reports; this code verifies and decides:
 *  - A PICK INSIDE A SELECTED PASSAGE moves that passage, whole and unchanged, to
 *    the pick's place. A pick the search did not find becomes a passage of that
 *    ONE chunk (never its ±window: the neighbours were not judged, and a window
 *    per addition cost 1.6–3.2k characters of prompt for one article — measured
 *    for the passage selection), joined in reading order to a pick passage that
 *    holds the chunk right before or after it.
 *  - THE BUDGET never cuts a pick: the list holds the pick passages, then the
 *    remaining search passages up to max_references + 1 in all.
 *  - WHAT AN OPEN GATE NEVER DROPS: a passage of an article the user NAMED BY
 *    NUMBER stays first, as on the plain path (the reader is never shown the
 *    parts judged out of scope and cannot outvote an explicit reference); and the
 *    passage the question's OWN WORDING ranks first keeps a place whatever the
 *    picks took. At most DocumentMapReader::MAX_PICKS picks are kept.
 *  - THE ANALYSIS lists the governing roles only. Conditions, exceptions and
 *    consequences are proposed from one-line gists; announced to the composer as
 *    rules to apply they produced misapplied rules (measured). Their passages are
 *    still placed — they are not announced.
 *  - DATA, NEVER INSTRUCTIONS: the analysis handed to the composer holds a role
 *    of the closed list, article NUMBERS and a passage number, all computed here.
 *    The reader's one-sentence "issue" and the headings of the document are not
 *    in it (the issue stays in the trace): the block is printed outside the fence
 *    the passages are shown in. What remains possible is what any retrieval
 *    allows: a document admitted for this user can get one of its chunks picked
 *    and shown — as a passage, inside the fence.
 *
 * WHICH FILES ARE READ. The files of the selected passages that carry a USABLE
 * map ({@see DocumentMap::isUsable()}: every content chunk attempted for its
 * current text, nine in ten with a gist) — and ONLY when every file the search
 * admitted for the turn carries one (the selected passages' files and those the
 * caller names in `admitted_files`). The reader reads maps only: beside an
 * unmapped file it would read the mapped one alone and steer the answer onto it.
 * One unmapped admitted file and the turn is not a map turn (`unmapped_file`).
 * A file a user UPLOADED counts as unmapped — whatever its chunks carry — unless
 * the host switched the map of uploads on ({@see self::mapIsRead()}).
 *
 * DOCUMENTS OF TWO SCOPES (a general document — a project file — beside a
 * record's own — an uploaded one; {@see DocumentScopeLabel}):
 *  - a COPY of a document already in the map (same texts, same order, another
 *    file id: the document filed on two records) is read once; a passage of
 *    the copy holds the chunks of the document it copies;
 *  - the reader is told whose document each block of the map is, in the words
 *    of the passage headers, and each document's map has its own budget;
 *  - among the governing picks the record's own document comes first (by
 *    role), then the general one (by role): both are kept, in a fixed order;
 *  - an open gate keeps one selected passage of each kind of document.
 *
 * WHETHER THE TURN IS ONE THE READER SHOULD READ AT ALL (a first-time question,
 * not a follow-up on covered ground) is decided by the caller, per turn
 * (AiManager::mapTurnPlanned()).
 *
 * NOT A MAP TURN — apply() returns null, {@see self::lastTrace()} says why, and the
 * caller keeps the path it has today:
 *  - `disabled` / `reader_disabled`: the switches (both on by default);
 *  - `no_files`: the search selected nothing;
 *  - `unmapped_file`: an admitted file carries no usable map;
 *  - `chunks_unavailable`: an admitted file's chunks could not be had;
 *  - `empty_map`: every chunk is out of the application's scope;
 *  - `no_report`: the reader call failed or gave no usable reply;
 *  - `error`: anything else. Nothing here can throw into the turn.
 *
 * WHERE THE CHUNKS COME FROM. The ranking the provider returns
 * ({@see FileContextProvider::rankedChunks()}) holds only the chunks the vector
 * search returned (its nearest 45 per collection at the default budget, preambles
 * left out), not the file. The whole file — every chunk with its metadata, map entry included — is
 * what the plain search already read once for its ±window snippets and left in
 * the TURN MEMO ({@see FileContextProvider::TURN_MEMO}); it is taken from there,
 * at no store call. Only when the memo does not hold it (no memo, or a context
 * window of 0) is the optional chunk store asked, once per file.
 *
 * Configuration (read live, each with its in-code default; the constructor array
 * overrides a key for a wired instance or a test):
 *   ai.file_context.document_map.enabled             true
 *   ai.file_context.document_map.reader.enabled      true
 *   ai.file_context.document_map.reader.max_chunks   150 (per document)
 *   ai.file_context.document_map.uploads             false
 *   ai.file_context.max_references                   5
 */
final class DocumentMapTurn
{
    /** A pick of one of these roles, absent from the search's passages, opens the gate. */
    public const GOVERNING_ROLES = ['general_rule', 'classification', 'procedure_or_approval'];

    /**
     * The analysis block announces at most this many rules of ONE role per kind of
     * document (a record's own, a general one): the first the reader named. The
     * passages of the others still reach the composer.
     *
     * Measured (2026-10-08):
     *  - the law corpus: every stored block (15 of 15) announces one rule per role
     *    — nothing changes there;
     *  - a scanned document without headings: 7 of 14 blocks announced two to four
     *    "general rules". With the cap, the one question it fired on (five picks of
     *    one role from three articles) was graded 0.55 / 0.55; the same question
     *    with every pick announced, 0.20 / 0.05 (one blind grader, two runs each):
     *    a block of four "general rules" made the answer a merged list;
     *  - two, not one: the reader does not list the deciding rule first (the
     *    article that states how charges are shared came second in 2 of 2 runs).
     * Small numbers: a cap to revisit when more documents of this kind exist.
     */
    public const RULES_PER_ROLE = 2;

    /** The map shown to the reader holds at most this many chunks (reader.max_chunks). */
    public const DEFAULT_MAX_CHUNKS = 150;

    /** `selected_by` of a passage the reader added (no search angle admitted it). */
    public const SELECTED_BY = 'map_reader';

    /**
     * Where FileContextProvider keeps a file's chunks in the turn memo:
     * `file_chunks:<collection or ''>:<file id>` (its expandWithContextWindow()).
     * Pinned by DocumentMapTurnTest against the real provider.
     */
    private const MEMO_FILE_CHUNKS = 'file_chunks:';

    /** Separates the parts of an internal key (a file, a chunk, a heading level). */
    private const SEP = "\u{1F}";

    /** @var array<string, mixed> */
    private array $lastTrace = [];

    /**
     * @param array{enabled?: mixed, uploads?: mixed, reader?: array{enabled?: mixed, max_chunks?: mixed}, max_references?: mixed, context_window?: mixed} $config
     *        Overrides of the configuration keys above (relative to
     *        ai.file_context.document_map; max_references / context_window are
     *        ai.file_context's). Absent keys are read live from the configuration.
     * @param ChunkStoreInterface|null $chunkStore Asked for a selected file's chunks only
     *        when the turn memo does not hold them; without it such a turn is not a map turn.
     */
    public function __construct(
        private readonly DocumentMapReader $reader,
        private readonly FileContextProvider $provider,
        private readonly array $config = [],
        private readonly ?ChunkStoreInterface $chunkStore = null
    ) {
    }

    /**
     * Read the map for this turn's passages and decide what the answer is built on.
     *
     * @param string $question   The user's question, verbatim.
     * @param string $standalone Its conversation-resolved form ('' when none).
     * @param mixed  $user       The user the passages were retrieved for.
     * @param array<int, mixed> $files The passages the plain search selected, in prompt
     *        order — reference arrays still carrying `snippet_chunks` (chunk_index =>
     *        text) and `heading_path`.
     * @param array{turn_memo?: \ArrayObject, angle_options?: array|\Closure, question_passages?: array<int|string, int>, observer?: PipelineObserverInterface} $options
     *        turn_memo: the fan-out's FileContextProvider::TURN_MEMO object;
     *        angle_options: the search options of the QUESTION angle (or the closure
     *        that gives them for an angle text); question_passages: file id => the
     *        chunk the question's own wording ranks first there (kept by an open
     *        gate); observer: the turn's observer.
     * @return array{files: list<array>|array<int, mixed>, analysis: array{rules: list<array{role: string, articles: list<int>, passage: int}>, has_classification: bool}|null, trace: array<string, mixed>}|null
     *         null: not a map turn ({@see self::lastTrace()} holds `fallback`).
     *         Gate closed: `files` is $files itself, `analysis` null. Gate open:
     *         `files` is the final list (still with the structure keys —
     *         {@see self::withoutStructure()}), `analysis` what the composer is told;
     *         `passage` is a 1-based position in that list.
     */
    public function apply(string $question, string $standalone, mixed $user, array $files, array $options = []): ?array
    {
        $this->lastTrace = [];

        try {
            $result = $this->turn($question, $standalone, $user, $files, $options);
        } catch (\Throwable $e) {
            // Whatever went wrong, the passages the search selected stand.
            try {
                $this->observer($options)->stageError('file_context', $e);
            } catch (\Throwable) {
                // Observation only.
            }

            return $this->notAMapTurn('error');
        }

        if ($result !== null) {
            $this->lastTrace = $result['trace'];
        }

        return $result;
    }

    /**
     * The trace of the last apply(): `reader` + `gate` (+ `passages` when open) of a
     * map turn, or `fallback` => the reason it was not one. Plain arrays, for the
     * file_context stage data.
     *
     * @return array<string, mixed>
     */
    public function lastTrace(): array
    {
        return $this->lastTrace;
    }

    /**
     * The passages without the keys only the retrieval layer reads
     * (`heading_path`, `snippet_chunks`) — what the caller does before they reach
     * the prompt, as AiManager::withSelectedPassages() does for its own.
     *
     * @param array<int, mixed> $files
     * @return list<mixed>
     */
    public static function withoutStructure(array $files): array
    {
        foreach ($files as &$file) {
            if (is_array($file)) {
                unset($file['heading_path'], $file['snippet_chunks']);
            }
        }

        return array_values($files);
    }

    private function turn(string $question, string $standalone, mixed $user, array $files, array $options): ?array
    {
        if (!$this->setting('enabled', true)) {
            return $this->notAMapTurn('disabled');
        }
        if (!$this->setting('reader.enabled', true)) {
            return $this->notAMapTurn('reader_disabled');
        }

        $passages = array_filter($files, 'is_array');
        if ($passages === []) {
            return $this->notAMapTurn('no_files');
        }

        // One memo for everything this turn asks the provider: the caller's (the
        // plain search left the files' chunks and the question's ranking in it), or
        // one of our own so that a file is still read at most once.
        $memo = ($options['turn_memo'] ?? null) instanceof \ArrayObject ? $options['turn_memo'] : new \ArrayObject();
        $angleOptions = $options['angle_options'] ?? [];
        if ($angleOptions instanceof \Closure) {
            $angleOptions = $angleOptions($question);
        }
        $searchOptions = (is_array($angleOptions) ? $angleOptions : []) + [FileContextProvider::TURN_MEMO => $memo];

        // How the question ranks the chunks, WITHOUT the relevance floor — asked for
        // only when it is needed (a map over its budget, the similarity of an added
        // passage, a file the memo does not hold) and memoized for the turn.
        $ranking = null;
        $ranked = function () use (&$ranking, $question, $user, $searchOptions): array {
            return $ranking ??= $this->provider->rankedChunks($question, $user, $searchOptions);
        };

        // ---- The map: every chunk of every selected file that carries a usable map,
        // in reading order. A file without one is simply not read: its passages stay
        // what the plain search made them, and it does not turn the reader off for
        // the mapped files admitted beside it.
        $documents = [];
        $map = [];
        $unmapped = [];
        $unavailable = [];
        // A COPY of a document already in the map (the same texts, in the same
        // order, under another file id — the same document filed on two records)
        // is not read twice: file key => the file key its chunks stand for.
        $copyOf = [];
        $texts = [];
        foreach ($passages as $file) {
            $fileId = $file['file_id'] ?? null;
            $fileKey = is_int($fileId) || is_string($fileId) ? (string) $fileId : '';
            if ($fileKey === '') {
                $unavailable[''] = null;
                continue;
            }
            if (isset($documents[$fileKey]) || isset($copyOf[$fileKey]) || array_key_exists($fileKey, $unmapped) || array_key_exists($fileKey, $unavailable)) {
                continue;
            }

            [$chunks, $collection] = $this->fileChunks($fileId, $memo, $ranked);
            if ($chunks === []) {
                $unavailable[$fileKey] = $fileId;
                continue;
            }
            if (!DocumentMap::isUsable($chunks) || !$this->mapIsRead($file, $collection)) {
                $unmapped[$fileKey] = $fileId;
                continue;
            }

            $fingerprint = $this->textFingerprint($chunks);
            if (isset($texts[$fingerprint])) {
                $copyOf[$fileKey] = $texts[$fingerprint];
                continue;
            }
            $texts[$fingerprint] = $fileKey;

            $documents[$fileKey] = [
                'file_id' => $fileId,
                // The file's NAME only — not the document's own title its passages carry
                // (`document_title`): measured live 2026-10-07, the title on this header
                // moved the reader's picks on one benchmark question in 3 runs of 6 (the
                // approval rule, art. 297, was no longer named), against 12 of 12 stable
                // runs without it. The reader picks passages; the composer names sources.
                'name' => basename((string) ($file['filename'] ?? $chunks[0]->fileName)),
                'collection' => $collection,
                // WHOSE document it is, in the words of the passage headers.
                'scope' => DocumentScopeLabel::of($file),
            ];
            foreach ($chunks as $chunk) {
                $entry = DocumentMap::isPreamble($chunk) ? null : DocumentMap::entryOf($chunk);
                // A chunk whose gist failed is absent from the map. A part judged not
                // to govern the application's users is not shown: the reader cannot
                // take the neighbouring regime's rule for the user's.
                if ($entry === null || ($entry['scope'] ?? null) === 'out') {
                    continue;
                }
                $path = DocumentMap::headingPathOf($chunk);
                $map[$fileKey . self::SEP . $chunk->chunkIndex] = [
                    'file' => $fileKey,
                    'chunk' => $chunk,
                    'path' => $path,
                    'gist' => (string) $entry['gist'],
                    'section' => $fileKey . self::SEP . implode(self::SEP, $path),
                ];
            }
        }
        // Files the search ADMITTED for the turn whose passages the reference
        // budget left out: they count too (the caller names them).
        foreach ((array) ($options['admitted_files'] ?? []) as $fileId) {
            $fileKey = is_int($fileId) || is_string($fileId) ? (string) $fileId : '';
            if ($fileKey === '' || isset($documents[$fileKey]) || isset($copyOf[$fileKey]) || array_key_exists($fileKey, $unmapped) || array_key_exists($fileKey, $unavailable)) {
                continue;
            }
            [$chunks, $collection] = $this->fileChunks($fileId, $memo, $ranked);
            if ($chunks === []) {
                $unavailable[$fileKey] = $fileId;
            } elseif (!DocumentMap::isUsable($chunks) || !$this->mapIsRead(null, $collection)) {
                $unmapped[$fileKey] = $fileId;
            }
        }

        // THE SAFETY RULE: the reader runs only when EVERY admitted file carries a
        // usable map. It reads maps only — beside an unmapped file it read the
        // mapped one alone, opened its gate on a rule of that document and the
        // answer was built on it (live: the general law served as the record's
        // own by-law, which sat unmapped beside it). One unmapped file, or
        // one whose chunks cannot be had, and the turn is not a map turn.
        if ($unmapped !== []) {
            return $this->notAMapTurn('unmapped_file');
        }
        if ($unavailable !== [] || $documents === []) {
            return $this->notAMapTurn('chunks_unavailable');
        }
        if ($map === []) {
            return $this->notAMapTurn('empty_map');
        }

        // What each selected passage holds — a passage of a copy holds the chunks
        // of the document it is a copy of.
        $held = [];
        foreach ($passages as $position => $file) {
            $held[$position] = $this->heldBy($file, $copyOf);
        }

        // ---- The map budget: a HARD cap on the lines the reader is shown, PER
        // DOCUMENT. One budget for the whole map made a long document pay with
        // another's lines: a record's own document without headings is ONE section
        // (measured: 104 lines), and beside it the general document was cut to what
        // was left — or the other way round, whichever the passages held first.
        // Each document is cut on its own, and the map never exceeds the ids the
        // reader has ({@see DocumentMapReader::MAX_CHUNKS}).
        //
        // Measured 2026-10-08, 12 questions × 2 runs on a by-law (104 lines)
        // beside the law (90 in scope): one budget of 150 (the law cut to ~45
        // lines), one of 250 and one per document named the same deciding chunks
        // (34, 34 and 33 of 62) at 11.3k / 13.5k / 13.5k reader tokens and the same
        // 2.1–2.6 s — the cut neither helped nor hurt there, so the budget is the
        // one that never lets a document's length decide what is read of another.
        $budget = min(DocumentMapReader::MAX_CHUNKS, max(1, (int) $this->setting('reader.max_chunks', self::DEFAULT_MAX_CHUNKS)));
        $cut = false;
        $budgeted = [];
        foreach (array_keys($documents) as $fileKey) {
            $fileKey = (string) $fileKey; // a numeric file id is an integer array key
            $own = array_filter($map, static fn (array $line): bool => $line['file'] === $fileKey);
            if (count($own) > $budget) {
                $cut = true;
                $own = $this->withinBudget($own, $held, $ranked(), $budget);
            }
            $budgeted += $own;
        }
        $map = $budgeted;
        if (count($map) > DocumentMapReader::MAX_CHUNKS) {
            $cut = true;
            $map = $this->withinBudget($map, $held, $ranked(), DocumentMapReader::MAX_CHUNKS);
        }
        if ($map === []) {
            return $this->notAMapTurn('empty_map');
        }

        // ---- The reading.
        $shown = [];
        foreach ($map as $line) {
            $shown[$line['file']] ??= ['file_id' => $documents[$line['file']]['file_id'], 'name' => $documents[$line['file']]['name'], 'scope' => $documents[$line['file']]['scope'], 'chunks' => []];
            $shown[$line['file']]['chunks'][] = ['chunk_index' => $line['chunk']->chunkIndex, 'path' => $line['path'], 'gist' => $line['gist']];
        }
        $report = $this->reader->read($question, $standalone, array_values($shown), $this->givenObserver($options));
        if ($report === null) {
            return $this->notAMapTurn('no_report');
        }

        $picks = [];
        foreach ($report['picks'] as $pick) {
            $key = (string) $pick['file_id'] . self::SEP . (int) $pick['chunk_index'];
            if (isset($map[$key]) && !isset($picks[$key])) {
                $picks[$key] = ['key' => $key, 'file' => (string) $pick['file_id'], 'chunk_index' => (int) $pick['chunk_index'], 'role' => (string) $pick['role']];
            }
        }

        $trace = ['reader' => [
            'map_chunks' => $report['map']['chunks'],
            'map_chars' => $report['map']['chars'],
            'cut' => $cut,
            // The reader's one sentence: free model text written from document-derived
            // lines. Observation only — it is never handed to the composer.
            'issue' => $report['issue'],
            'answerable' => $report['answerable'],
            'picks' => array_values(array_map(fn (array $pick): array => [
                'file' => $documents[$pick['file']]['file_id'],
                'chunk_index' => $pick['chunk_index'],
                'role' => $pick['role'],
            ], $picks)),
        ] + ($unmapped + $unavailable !== [] ? ['unmapped_files' => array_values(array_filter($unmapped + $unavailable, static fn (mixed $id): bool => $id !== null))] : [])
        + (is_array($report['usage'] ?? null)
            ? ['tokens' => array_intersect_key($report['usage'], ['input' => 1, 'output' => 1, 'total' => 1])]
            : [])
        + ['ms' => $report['ms']]];

        // ---- The gate: a governing rule the search's passages do not hold.
        $found = $held === [] ? [] : array_replace(...array_values($held));
        $opening = array_filter(
            $picks,
            static fn (array $pick): bool => in_array($pick['role'], self::GOVERNING_ROLES, true) && !isset($found[$pick['key']])
        );
        $closed = static fn (array $gate = []): array => [
            'files' => $files,
            'analysis' => null,
            'trace' => $trace + ['gate' => ['state' => 'closed', 'opened_by' => []] + $gate],
        ];
        if ($opening === []) {
            return $closed();
        }

        // ---- Open: the picks first — the GOVERNING rules (the ones the analysis block
        // lists) before the reader's other picks, then by role, then by the order
        // reported. Live (2026-10-08, tear-down): a condition pick placed before the
        // approval rule was taken for the approval, and the majority was lost.
        //
        // Among the governing rules, A RECORD'S OWN DOCUMENT BEFORE A GENERAL ONE:
        // the rules the reader named in the document of the record the question is
        // about are stated first (by role), then those of the general document (by
        // role) — two sources, both KEPT, each in one block, in a fixed order.
        // Measured live on "what does the by-law say about …": by role alone
        // the general law's article led as THE general rule and the record's own
        // clause followed as a classification. Structural (whose document it is);
        // which of the two prevails is for the documents to say.
        $order = array_flip(DocumentMapReader::ROLES);
        $general = static fn (array $pick): int => DocumentScopeLabel::isGeneral($documents[$pick['file']]['scope'] ?? null) ? 1 : 0;
        $rank = static fn (array $pick): array => in_array($pick['role'], self::GOVERNING_ROLES, true)
            ? [0, $general($pick), $order[$pick['role']] ?? 99]
            : [1, 0, $order[$pick['role']] ?? 99];
        $byRole = array_values($picks);
        usort($byRole, static fn (array $a, array $b): int => $rank($a) <=> $rank($b));

        $groups = [];
        $placed = [];
        $consumed = [];
        $unbuilt = [];

        // …but an article the USER NAMED BY NUMBER leads whatever the reader names
        // (as it leads the plain list): the user asked for that article, and the
        // reader — which is never shown the parts judged out of scope — cannot
        // outvote an explicit reference. It is outside every cut below.
        foreach ($passages as $position => $file) {
            if (empty($file['article_match'])) {
                continue;
            }
            $consumed[$position] = true;
            $groups[] = ['file' => $file, 'from' => 'search', 'roles' => [], 'keys' => $held[$position]];
            foreach ($held[$position] as $heldKey => $_) {
                $placed[$heldKey] ??= count($groups) - 1;
            }
        }

        foreach ($byRole as $pick) {
            $key = $pick['key'];
            if (isset($placed[$key])) {
                $groups[$placed[$key]]['roles'][$key] = $pick['role'];
                if ($groups[$placed[$key]]['from'] === 'search') {
                    $groups[$placed[$key]]['from'] = 'both';
                }
                continue;
            }

            // Inside a selected passage: that passage, whole, takes the pick's place.
            foreach ($held as $position => $keys) {
                if (isset($consumed[$position]) || !isset($keys[$key])) {
                    continue;
                }
                $consumed[$position] = true;
                $groups[] = ['file' => $passages[$position], 'from' => 'both', 'roles' => [$key => $pick['role']], 'keys' => $keys];
                foreach ($keys as $heldKey => $_) {
                    $placed[$heldKey] ??= count($groups) - 1;
                }
                continue 2;
            }

            // The reader's own: the ONE chunk it named.
            $score = $ranked()[$documents[$pick['file']]['file_id']]['chunks'][$pick['chunk_index']]['score'] ?? null;
            $passage = $this->chunkPassage($map[$key]['chunk'], $documents[$pick['file']], is_numeric($score) ? (float) $score : null, $question, $user, $memo);
            if ($passage === null) {
                $unbuilt[] = $pick;
                continue;
            }
            $text = $passage['snippet_chunks'][$pick['chunk_index']];

            // Joined to the pick passage it continues (the chunk right before or
            // after one of its chunks), else a passage of its own.
            $into = null;
            foreach ($groups as $number => $group) {
                $before = $pick['file'] . self::SEP . ($pick['chunk_index'] - 1);
                $after = $pick['file'] . self::SEP . ($pick['chunk_index'] + 1);
                if ((isset($group['keys'][$before]) || isset($group['keys'][$after])) && is_array($group['file']['snippet_chunks'] ?? null)) {
                    $into = $number;
                    break;
                }
            }
            if ($into !== null) {
                $joined = $groups[$into]['file']['snippet_chunks'] + [$pick['chunk_index'] => $text];
                ksort($joined);
                $groups[$into]['file']['snippet_chunks'] = $joined;
                $groups[$into]['file']['snippet'] = implode(FileContextProvider::SNIPPET_SEPARATOR, $joined);
                $groups[$into]['keys'][$key] = true;
                $groups[$into]['roles'][$key] = $pick['role'];
                $placed[$key] = $into;
                continue;
            }

            // No search angle stands behind it: consumers that must not build on
            // drift (follow-up chips) read these two facts.
            $groups[] = [
                'file' => ['angle_groups' => [], 'question_supported' => false] + $passage,
                'from' => 'reader',
                'roles' => [$key => $pick['role']],
                'keys' => [$key => true],
            ];
            $placed[$key] = count($groups) - 1;
        }

        // A governing rule that could not be built opens nothing: with none left,
        // the turn is the plain-search turn after all.
        $opened = array_filter($opening, static fn (array $pick): bool => isset($placed[$pick['key']]));
        $label = static fn (array $pick): string => $pick['chunk_index'] . ':' . $pick['role'];
        $gate = $unbuilt !== [] ? ['unbuilt' => array_values(array_map($label, $unbuilt))] : [];
        if ($opened === []) {
            return $closed($gate);
        }

        // Then the remaining search passages, in their order, within the budget —
        // except the passage the QUESTION'S OWN WORDING ranks first, which keeps a
        // place whatever the picks took: the reader reasons from one-line gists, and
        // what the user literally asked about must not leave the prompt for it.
        $max = max(count($groups), max(1, (int) $this->setting('max_references', 5, 'ai.file_context.max_references')) + 1);
        $questions = $this->questionPassage($passages, $options['question_passages'] ?? null);
        // … and ONE PASSAGE OF EACH KIND OF DOCUMENT the search selected (a general
        // document, a record's own — {@see ReferenceSlots}): the picks of a reading
        // that named one document only must not take the other out of the answer's
        // sight. Its first selected passage stays, over the cap if need be.
        $kinds = [];
        foreach ($groups as $group) {
            $kinds[ReferenceSlots::kindOf($group['file']) ?? ''] = true;
        }
        foreach ($passages as $position => $file) {
            if (isset($consumed[$position])) {
                continue;
            }
            $kind = ReferenceSlots::kindOf($file);
            if (count($groups) >= $max && $position !== $questions && ($kind === null || isset($kinds[$kind]))) {
                continue;
            }
            if ($held[$position] !== [] && array_diff_key($held[$position], $placed) === []) {
                continue; // everything it holds is already in a pick passage
            }
            $kinds[$kind ?? ''] = true;
            $groups[] = ['file' => $file, 'from' => 'search', 'roles' => [], 'keys' => $held[$position]];
            foreach ($held[$position] as $heldKey => $_) {
                $placed[$heldKey] ??= count($groups) - 1;
            }
        }

        // ---- What the composer is told: the governing rules, by role then passage.
        // DATA / INSTRUCTION SEPARATION: a role of the closed list, the chunk's
        // article NUMBERS and a passage number — all computed here. Neither the
        // reader's sentence nor a heading of the document goes with them (the block
        // stands outside the fence the passages are shown in).
        $rows = [];
        foreach ($groups as $number => $group) {
            foreach ($group['roles'] as $key => $role) {
                if (in_array($role, self::GOVERNING_ROLES, true)) {
                    // …the record's own document first, as its passages stand.
                    $ofGeneral = DocumentScopeLabel::isGeneral($documents[$map[$key]['file']]['scope'] ?? null) ? 1 : 0;
                    $rows[] = [$order[$role], $number + 1, $role, $this->articlesOf($map[$key]['chunk']), $ofGeneral];
                }
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$a[4], $a[0], $a[1]] <=> [$b[4], $b[0], $b[1]]);

        // AT MOST RULES_PER_ROLE OF ONE ROLE, per kind of document: the first the
        // reader named. The passages of the others stay where they are — they are
        // only not announced as governing.
        $announced = [];
        $perRole = [];
        foreach ($rows as $row) {
            $slot = $row[4] . '|' . $row[2];
            $perRole[$slot] = ($perRole[$slot] ?? 0) + 1;
            if ($perRole[$slot] <= self::RULES_PER_ROLE) {
                $announced[] = $row;
            }
        }
        if (count($announced) < count($rows)) {
            $gate['unannounced'] = count($rows) - count($announced);
        }
        $rows = $announced;

        return [
            'files' => array_column($groups, 'file'),
            'analysis' => [
                'rules' => array_map(static fn (array $row): array => ['role' => $row[2], 'articles' => $row[3], 'passage' => $row[1]], $rows),
                'has_classification' => in_array('classification', array_column($rows, 2), true),
            ],
            'trace' => $trace + [
                'gate' => ['state' => 'open', 'opened_by' => array_values(array_map($label, $opened))] + $gate,
                'passages' => array_map(static fn (array $group): array => [
                    'file' => $group['file']['file_id'] ?? null,
                    'chunks' => is_array($group['file']['snippet_chunks'] ?? null)
                        ? array_map('intval', array_keys($group['file']['snippet_chunks']))
                        : (isset($group['file']['chunk_index']) ? [(int) $group['file']['chunk_index']] : []),
                    'from' => $group['from'],
                    'roles' => array_values($group['roles']),
                ], $groups),
            ],
        ];
    }

    /**
     * Every chunk of a file, in reading order, with the collection it lives in.
     *
     * From the turn memo when the plain search left the file there (it reads each
     * selected file once for its ±window snippets): no store call. Else from the
     * chunk store, when one was given — and then left in the memo, so the passages
     * built later this turn read it from there.
     *
     * @return array{0: list<FileChunk>, 1: ?string} [] when the chunks cannot be had.
     */
    private function fileChunks(int|string $fileId, \ArrayObject $memo, \Closure $ranked): array
    {
        $suffix = ':' . $fileId;
        foreach ($memo as $key => $value) {
            if (!is_string($key) || !is_array($value) || !str_starts_with($key, self::MEMO_FILE_CHUNKS) || !str_ends_with($key, $suffix)) {
                continue;
            }
            // `<collection>:<file id>`: what is left before the id is the collection.
            $collection = substr($key, strlen(self::MEMO_FILE_CHUNKS), -strlen($suffix));
            if (str_contains($collection, ':')) {
                continue; // the key of another file whose id merely ends alike
            }
            $chunks = $this->chunksOf($value, $fileId);
            if ($chunks !== []) {
                return [$chunks, $collection !== '' ? $collection : null];
            }
        }

        if ($this->chunkStore === null) {
            return [[], null];
        }

        // The collection the search found the file in (null = the store's own).
        $collection = $ranked()[$fileId]['result']['collection'] ?? null;
        $collection = is_string($collection) && $collection !== '' && $collection !== '__primary__' ? $collection : null;
        try {
            $fetched = $this->chunkStore->getFileChunks($fileId, $collection);
        } catch (\Throwable) {
            return [[], null];
        }
        $chunks = $this->chunksOf(is_array($fetched) ? $fetched : [], $fileId);
        if ($chunks !== []) {
            $memo[self::MEMO_FILE_CHUNKS . ($collection ?? '') . ':' . $fileId] = $fetched;
        }

        return [$chunks, $collection];
    }

    /**
     * @param array<int, mixed> $candidates
     * @return list<FileChunk> The chunks of that file among them, in reading order.
     */
    private function chunksOf(array $candidates, int|string $fileId): array
    {
        $chunks = array_values(array_filter(
            $candidates,
            static fn (mixed $chunk): bool => $chunk instanceof FileChunk && (string) $chunk->fileId === (string) $fileId
        ));
        usort($chunks, static fn (FileChunk $a, FileChunk $b): int => $a->chunkIndex <=> $b->chunkIndex);

        return $chunks;
    }

    /**
     * The chunks a selected passage holds, as map keys: the chunks its snippet is
     * made of when the provider reported them, else the ±context_window around its
     * centre (what its snippet was stitched from).
     *
     * @return array<string, true>
     */
    private function heldBy(array $file, array $copyOf = []): array
    {
        $fileKey = is_scalar($file['file_id'] ?? null) ? (string) $file['file_id'] : '';
        $fileKey = $copyOf[$fileKey] ?? $fileKey;
        $chunks = $file['snippet_chunks'] ?? null;
        if (is_array($chunks) && $chunks !== []) {
            $indexes = array_keys($chunks);
        } elseif (is_numeric($file['chunk_index'] ?? null)) {
            $window = max(0, (int) $this->setting('context_window', 2, 'ai.file_context.context_window'));
            $indexes = range(max(0, (int) $file['chunk_index'] - $window), (int) $file['chunk_index'] + $window);
        } else {
            $indexes = [];
        }

        $held = [];
        foreach ($indexes as $index) {
            $held[$fileKey . self::SEP . (int) $index] = true;
        }

        return $held;
    }

    /**
     * Whether a document's map is READ at question time: always for the host's own
     * documents (a project file — `source: physical`, or a file of the physical
     * collection); for a file a user UPLOADED only when the host switched that on
     * (ai.file_context.document_map.uploads, default off).
     *
     * The switch is the same one that decides whether an upload is mapped at all
     * (FileProcessor, `ai:documents:map`) — and it must hold HERE too: a map built
     * while it was on, or by another process, stays in the store, and "off" has to
     * mean the turn of a host that never mapped an upload (the file is then an
     * unmapped one: {@see self::turn()}, `unmapped_file`).
     *
     * @param array<string, mixed>|null $file The passage the file was met through, when there is one.
     */
    private function mapIsRead(?array $file, ?string $collection): bool
    {
        if (($file['source'] ?? null) === 'physical') {
            return true;
        }

        try {
            $physical = config('ai.file_context.physical_collection', 'documentation_chunks');
        } catch (\Throwable) {
            $physical = 'documentation_chunks';
        }
        if ($collection !== null && $collection === $physical) {
            return true;
        }

        return (bool) $this->setting('uploads', false);
    }

    /**
     * What tells a document from another by its TEXT alone: the hashes of its
     * content chunks, in reading order. Two files with the same fingerprint are
     * copies of one document (chunk n of one is chunk n of the other).
     *
     * @param list<FileChunk> $chunks
     */
    private function textFingerprint(array $chunks): string
    {
        $hashes = [];
        foreach ($chunks as $chunk) {
            if (!DocumentMap::isPreamble($chunk)) {
                $hashes[] = $chunk->chunkIndex . ':' . DocumentMap::hash($chunk->content);
            }
        }

        return sha1(implode('|', $hashes));
    }

    /**
     * A map over its budget, cut to whole SECTIONS (a chunk's full heading path):
     * the sections of the selected passages (the gate compares the reading with
     * them), then the sections of the chunks the question ranks best, in rank
     * order, each taken whole while it still fits. A section that does not fit is
     * passed over; a smaller one ranked after it may still enter. Sections stay
     * whole because a gist is read against its neighbours.
     *
     * The budget is a HARD cap: when the passages' own sections exceed it, sections
     * are given up and the map is the neighbourhood of the hits
     * ({@see self::neighbourhood()}).
     *
     * @param array<string, array<string, mixed>> $map
     * @param array<int|string, array<string, true>> $held
     * @param array<int|string, array<string, mixed>> $ranked FileContextProvider::rankedChunks()
     * @return array<string, array<string, mixed>> The kept lines, in reading order.
     */
    private function withinBudget(array $map, array $held, array $ranked, int $budget): array
    {
        $size = array_count_values(array_column($map, 'section'));

        $keep = [];
        $count = 0;
        foreach ($held as $keys) {
            foreach ($keys as $key => $_) {
                $section = $map[$key]['section'] ?? null;
                if ($section !== null && !isset($keep[$section])) {
                    $keep[$section] = true;
                    $count += $size[$section];
                }
            }
        }

        $scores = [];
        foreach ($ranked as $fileId => $entry) {
            foreach ((array) ($entry['chunks'] ?? []) as $index => $chunk) {
                $scores[$fileId . self::SEP . (int) $index] = (float) ($chunk['score'] ?? 0.0);
            }
        }
        arsort($scores);

        // THE HARD CAP. The passages' own sections do not fit (a file without
        // headings is ONE section: hundreds of gists): the map is then the
        // NEIGHBOURHOOD of the plain search's hits — the chunks the passages hold,
        // then the chunks the question ranks best, then the chunks one step from
        // each of those, two steps, … until the budget is full. A rule far from
        // every hit is then out of the reader's sight (as it is out of the
        // search's): the cap bounds the cost of a turn, it does not find more.
        if ($count > $budget) {
            return $this->neighbourhood($map, $held, array_keys($scores), $budget);
        }

        foreach (array_keys($scores) as $key) {
            $section = $map[$key]['section'] ?? null;
            if ($section === null || isset($keep[$section]) || $count + $size[$section] > $budget) {
                continue;
            }
            $keep[$section] = true;
            $count += $size[$section];
        }

        return array_filter($map, static fn (array $line): bool => isset($keep[$line['section']]));
    }

    /**
     * At most $budget lines of the map: the seeds (the chunks of the selected
     * passages, in their order, then the ranked chunks, best first) and the chunks
     * nearest to them in their file, a step further at each round.
     *
     * @param array<string, array<string, mixed>> $map
     * @param array<int|string, array<string, true>> $held
     * @param list<string> $rankedKeys Map keys of the ranked chunks, best first.
     * @return array<string, array<string, mixed>> The kept lines, in reading order.
     */
    private function neighbourhood(array $map, array $held, array $rankedKeys, int $budget): array
    {
        $seeds = [];
        foreach ($held as $keys) {
            foreach ($keys as $key => $_) {
                $seeds[(string) $key] = true;
            }
        }
        foreach ($rankedKeys as $key) {
            $seeds[(string) $key] = true;
        }

        // Each file's chunk indexes present in the map, to bound the walk.
        $span = [];
        foreach ($map as $line) {
            $index = $line['chunk']->chunkIndex;
            $span[$line['file']] = [min($span[$line['file']][0] ?? $index, $index), max($span[$line['file']][1] ?? $index, $index)];
        }

        $keep = [];
        $widest = 0;
        foreach ($span as [$low, $high]) {
            $widest = max($widest, $high - $low);
        }
        for ($step = 0; $step <= $widest && count($keep) < $budget; $step++) {
            foreach (array_keys($seeds) as $seed) {
                $cut = strrpos($seed, self::SEP);
                if ($cut === false) {
                    continue;
                }
                $file = substr($seed, 0, $cut);
                $index = (int) substr($seed, $cut + strlen(self::SEP));
                foreach ($step === 0 ? [$index] : [$index - $step, $index + $step] as $near) {
                    $key = $file . self::SEP . $near;
                    if (isset($map[$key]) && !isset($keep[$key])) {
                        $keep[$key] = true;
                        if (count($keep) >= $budget) {
                            break 3;
                        }
                    }
                }
            }
        }

        return array_intersect_key($map, $keep);
    }

    /**
     * The passage of ONE chunk the reader named: the reference a search emits for it
     * ({@see FileContextProvider::passageAt()} — the access gate again, the locator,
     * the host's enrichment), its snippet reduced to that chunk. `relevance` is the
     * similarity the question gave the chunk, 0.0 when its ranking never reached it.
     *
     * @param array{file_id: int|string, collection: ?string} $document
     * @return array<string, mixed>|null null when it cannot be built.
     */
    private function chunkPassage(FileChunk $chunk, array $document, ?float $score, string $question, mixed $user, \ArrayObject $memo): ?array
    {
        $index = $chunk->chunkIndex;
        // The record an UPLOADED file is filed on travels with its chunks
        // ({@see FileLink}): the passage built here says whose document it is like a
        // passage the search found (its header, the analysis line) — live, the
        // governing rule the reader added was the one passage without a scope.
        $link = FileLink::ofChunk($chunk);
        $result = [
            'file_id' => $document['file_id'],
            'chunks' => [$chunk],
            'chunk_scores' => $score !== null ? [$index => $score] : [],
        ] + ($document['collection'] !== null ? ['collection' => $document['collection']] : [])
            + ($link !== null ? ['owner' => $link[FileLink::OWNER], 'ancestors' => $link[FileLink::ANCESTORS]] : []);

        $passage = $this->provider->passageAt($result, $index, $user, [
            FileContextProvider::PASSAGE_CHUNKS => true,
            FileContextProvider::TURN_MEMO => $memo,
            'question' => $question,
        ]);
        $stitched = is_array($passage) && is_array($passage['snippet_chunks'] ?? null) ? $passage['snippet_chunks'] : null;
        if ($stitched === null || !isset($stitched[$index])) {
            return null;
        }

        unset($passage['chunk_scores']);
        $passage['snippet_chunks'] = [$index => $stitched[$index]];
        $passage['snippet'] = $stitched[$index];
        // No search admitted it: its cosine for the question is low by construction
        // (that is the gap the reader closes). FileContextSection prints no relevance
        // label for it — a "relevance: 28%" on the governing rule invited the composer
        // to discount it (the measured prompts carried no such label).
        $passage['selected_by'] = self::SELECTED_BY;

        return $passage;
    }

    /**
     * The articles a chunk defines, as NUMBERS ({@see ArticleLocator}: the clause
     * numbers its lines start with, else its chunk metadata) — what the passage
     * headers print as "art. 263". [] for a chunk that defines none: it is then
     * announced by its role and passage number alone, never by a heading.
     *
     * @return list<int>
     */
    private function articlesOf(FileChunk $chunk): array
    {
        return array_values(array_map('intval', ArticleLocator::forReference([
            'snippet' => $chunk->content,
            'article_no' => $chunk->metadata['article_no'] ?? null,
            'article_range' => $chunk->metadata['article_range'] ?? null,
        ])));
    }

    /**
     * The position of the passage the question's own wording ranks first: the
     * first passage (prompt order) centred on the chunk the caller reports for its
     * file (`question_passages`: file id => chunk index — AiManager's evidence
     * floor); without that knowledge, the first passage the question angle stands
     * behind (`question_supported`), else the first passage of the list.
     *
     * @param array<int, array> $passages
     */
    private function questionPassage(array $passages, mixed $reported): ?int
    {
        if (is_array($reported) && $reported !== []) {
            foreach ($passages as $position => $file) {
                $fileId = $file['file_id'] ?? null;
                if (is_scalar($fileId) && is_numeric($file['chunk_index'] ?? null)
                    && is_numeric($reported[$fileId] ?? null) && (int) $reported[$fileId] === (int) $file['chunk_index']) {
                    return $position;
                }
            }
        }

        foreach ($passages as $position => $file) {
            if (($file['question_supported'] ?? null) === true) {
                return $position;
            }
        }

        return array_key_first($passages);
    }

    /** Records why this is not a map turn; the caller keeps the path it has. */
    private function notAMapTurn(string $reason): ?array
    {
        $this->lastTrace = ['fallback' => $reason];

        return null;
    }

    /**
     * A setting: the constructor's override when it gives one, else the live
     * configuration, else the in-code default.
     */
    private function setting(string $key, mixed $default, ?string $configKey = null): mixed
    {
        $node = $this->config;
        foreach (explode('.', $key) as $part) {
            $node = is_array($node) && array_key_exists($part, $node) ? $node[$part] : null;
            if ($node === null) {
                break;
            }
        }
        if ($node !== null) {
            return $node;
        }

        try {
            return config($configKey ?? 'ai.file_context.document_map.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default; // no configuration to read
        }
    }

    private function givenObserver(array $options): ?PipelineObserverInterface
    {
        return ($options['observer'] ?? null) instanceof PipelineObserverInterface ? $options['observer'] : null;
    }

    private function observer(array $options): PipelineObserverInterface
    {
        $given = $this->givenObserver($options);

        return $given !== null ? GuardedPipelineObserver::wrap($given) : PipelineObserverScope::current();
    }
}
