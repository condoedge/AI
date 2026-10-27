<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Actions\Attach;

use Condoedge\Ai\Contracts\AiActionInterface;
use Condoedge\Ai\DTOs\Digest\DigestSourceResult;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Services\Actions\ActionAdvertisement;
use Condoedge\Ai\Services\Actions\ActionInvocation;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\BuiltIn\AskFollowUpAction;
use Condoedge\Ai\Services\Actions\BuiltIn\ExportExcelAction;
use Condoedge\Ai\Services\Actions\BuiltIn\ExportPdfAction;
use Condoedge\Ai\Services\Actions\BuiltIn\IdentityLinkAction;
use Condoedge\Ai\Services\Actions\BuiltIn\RecordedResultExport;
use Condoedge\Ai\Services\Actions\BuiltIn\StartTaskAction;
use Condoedge\Ai\Services\Chat\Exporter\RecordedQueryRerun;
use Condoedge\Ai\Services\Discovery\EntityLabelIndex;
use Condoedge\Ai\Services\Query\UserOwnershipPaths;
use Condoedge\Ai\Services\Response\FollowUpWriter;
use Condoedge\Ai\Services\Response\MoneyColumns;
use Condoedge\Ai\Services\ResponseSections\SelfReferenceNoticeSection;
use Condoedge\Ai\Support\GuardedPipelineObserver;
use Illuminate\Support\Facades\Log;

/**
 * ActionAttacher — CODE decides which links/actions an answer carries (actions plan
 * B1–B4; owner rule "code attaches links/actions; the AI only narrates").
 *
 * Every decision reads STRUCTURED signals only — rows, entity labels, ids, QU fields
 * (intent, isPossessive, entityTypes, exportFormat), counts, truncation, declared money
 * columns, permissions — never the question's words:
 *
 *   B1 record chips   one chip per result record (its primary "open" action), labelled with
 *                     the record's name + homonym disambiguator ("Account 310 — North
 *                     Branch"); at most `max_record_chips` — spread over the records'
 *                     groups when they have several (one staff member per branch, each
 *                     labelled with its branch) — then "+N more" → the list page of the
 *                     result's SUBJECT ({@see listTarget()}), or no such chip when the
 *                     subject has none. Never for a single-row count/aggregate answer.
 *   B2 identity       my_profile when QU judged the question about the user's own (user)
 *                     record or the result IS the user's own record ({@see isOwnRecord()});
 *                     my_team when the question is about the team (QU entity types) — and,
 *                     on the answer that is the current team's own record, the team's
 *                     related doors the host lists (`identity.team.related`,
 *                     {@see teamDoors()}).
 *   B3 next steps     by answer SHAPE (≤ `max_next_steps`): empty → list + create (an
 *                     own-data ask — a query anchored on the user, or QU personal possession — gets no
 *                     create form but ONE door: the list page, else the report); one record →
 *                     its other read actions;
 *                     truncated list → the subject's list page + Excel: of the stored rows
 *                     when every row was retrieved, else a RE-RUN of the recorded query
 *                     when that would hold every row (else none);
 *                     money total → the entity's report (else its list page).
 *   B4 digest         each non-empty section's configured link + record chips per row +
 *                     the export chip when the digest ask was also an export ask.
 *   B6 follow-ups     question chips ({@see AskFollowUpAction}: the click sends the question
 *                     as the user's next message) — never named to the narrator:
 *                       - clarify: one per candidate reading of the clarifying question
 *                         ({@see forClarification()}, no LLM);
 *                       - digest: one "go through this section" per section that shows only
 *                         a part of its records, after the other chips (no LLM);
 *                       - a document-grounded answer: the questions the focused
 *                         {@see FollowUpWriter} call wrote (role `follow_up_writer`) and
 *                         code verified, computed AFTER composition
 *                         ({@see withAnswerFollowUps()}).
 *
 * Only actions the user passes are attached (ActionAdvertisement — the same rule every
 * advertising surface uses); render re-authorizes each chip fresh.
 *
 * Config: `ai.attached_actions` (enabled, max_record_chips, max_next_steps, identity.*,
 * max_follow_ups, follow_ups.{enabled, clarify, knowledge, digest, model_declines,
 * min_relevance}); the writer's model: `ai.llm_roles.follow_up_writer`;
 * placement attributes on actions: entity action `primary`, generic action `entity` + `kind`
 * (list | create | report) — see {@see AiActionRegistry::attributes()}.
 */
class ActionAttacher
{
    private const COUNT_INTENTS = ['count', 'aggregate'];

    /** The follow-up sources, as their `ai.attached_actions.follow_ups.<source>` switch. */
    private const FOLLOW_UPS_CLARIFY = 'clarify';
    private const FOLLOW_UPS_KNOWLEDGE = 'knowledge';
    private const FOLLOW_UPS_DIGEST = 'digest';

    /** Response metadata flags of an answer that is an error, a block or an abstention. */
    private const UNANSWERED_FLAGS = ['error', 'empty_result', 'fallback'];

    /**
     * Per-CALL memo of each label's primary action, keyed by user AND label: this attacher
     * lives in the AiManager singleton (queue workers, Octane, the eval command), so a
     * decision for one user must never be reused for another. Reset on every forTurn() /
     * forDigest().
     *
     * @var array<string, AiActionInterface|null>
     */
    private array $primaryCache = [];

    /**
     * @param FollowUpWriter|null $followUpWriter The focused "what would the user ask next?"
     *        call of a document-grounded answer. Null (the default): such answers get no
     *        follow-up chip — the clarify and digest chips need no model.
     */
    public function __construct(
        private ?AiActionRegistry $registry = null,
        private readonly ?FollowUpWriter $followUpWriter = null,
        private readonly ?NavigationPages $navigationPages = null
    ) {
    }

    /**
     * The chips for a normal (data / knowledge / empty) turn.
     *
     * @param array<string, mixed> $options pipeline options (user)
     */
    public function forTurn(PipelineContext $ctx, array $options = []): AttachedActions
    {
        if (!$this->enabled()) {
            return AttachedActions::none();
        }

        $this->primaryCache = [];
        $user = $this->user($options);
        $rows = array_values(array_filter((array) $ctx->executionResult->data, 'is_array'));
        $cypher = $ctx->queryResult->cypher;
        $current = true;

        // A follow-up about the record just shown ("open it") that ran no query: the
        // previous turn's recorded rows are the records it refers to.
        if ($rows === [] && $ctx->qu->followUpReference) {
            $rows = array_values(array_filter((array) ($ctx->conversationContext['last_result_sample'] ?? []), 'is_array'));
            $cypher = $ctx->conversationContext['last_cypher_query'] ?? null;
            $current = false;
        }

        $chips = [];
        $refs = [];
        $records = [];

        $countOnly = in_array($ctx->qu->intent, self::COUNT_INTENTS, true) && count($rows) === 1;
        if (!$countOnly && $rows !== []) {
            [$recordChips, $refs, $records] = $this->recordChips($rows, is_string($cypher) ? $cypher : null, $ctx, $user, $current);
            array_push($chips, ...$recordChips);
        }

        array_push($chips, ...$this->identityChips($ctx, $rows, $user, is_string($cypher) ? $cypher : null, $current));

        if ($current) {
            array_push($chips, ...$this->nextSteps($ctx, $rows, is_string($cypher) ? $cypher : null, $records, $user));
        }

        return new AttachedActions($this->dedupe($chips), $current ? $refs : []);
    }

    /**
     * The chips for an attention digest (B4).
     *
     * @param DigestSourceResult[] $sources
     * @param array<string, mixed> $options
     */
    public function forDigest(PipelineContext $ctx, array $sources, array $options = []): AttachedActions
    {
        if (!$this->enabled()) {
            return AttachedActions::none();
        }

        $this->primaryCache = [];
        $user = $this->user($options);
        $sections = [];
        $perSource = [];
        $max = $this->maxRecordChips();

        foreach ($sources as $source) {
            if (!$source instanceof DigestSourceResult || $source->error !== null || $source->rows === []) {
                continue;
            }

            if ($source->actionKey !== null && ($action = $this->allowedGeneric($source->actionKey, $user)) !== null) {
                $sections[] = $this->chip(AttachedActions::ROLE_SECTION, new ActionInvocation(
                    $action->key(), null, null, [], $source->usedFallback && $source->fallbackLabel !== null ? $source->fallbackLabel : $source->label
                ));
            }

            if ($source->entity === null) {
                continue;
            }
            // The source DECLARES its entity: stamp it so the row's id field is attributed.
            $rows = array_map(static fn (array $row) => ['_label' => $source->entity] + $row, array_values(array_filter($source->rows, 'is_array')));
            [$sourceChips] = $this->recordChips($rows, null, $ctx, $user, false, $max, false);
            if ($sourceChips !== []) {
                $perSource[] = $sourceChips;
            }
        }

        // Round-robin across sections (first row of each, then the second…): the cap is
        // never spent on the first section alone.
        $records = [];
        for ($i = 0; count($records) < $max && $perSource !== []; $i++) {
            $took = false;
            foreach ($perSource as $sourceChips) {
                if (isset($sourceChips[$i]) && count($records) < $max) {
                    $records[] = $sourceChips[$i];
                    $took = true;
                }
            }
            if (!$took) {
                break;
            }
        }

        $chips = [...$sections, ...$records];
        if ($ctx->qu->exportFormat !== null && ($export = $this->exportChip($ctx->qu->exportFormat, $ctx, $user)) !== null) {
            $chips[] = $export;
        }
        array_push($chips, ...$this->sectionFollowUps($ctx, $sources, $user));

        return new AttachedActions($this->dedupe($chips));
    }

    // =========================================================================
    // B6 — follow-up question chips
    // =========================================================================

    /**
     * The chips of a clarifying question: one per candidate reading. The label is the
     * reading with its first letter upper-cased; the click sends the reading ITSELF — the
     * conversation already holds the clarifying question, and Query Understanding resolves
     * the reply against it.
     *
     * Every reading gets its chip: the clarifying question names them all, so the
     * suggestions cap (`max_follow_ups`) does not apply — the readings are already bounded
     * where they are produced. No LLM call. Decoration only: never throws — on any failure
     * the clarifying question simply carries no chip.
     *
     * @param array<string, mixed> $options pipeline options (user)
     */
    public function forClarification(PipelineContext $ctx, array $options = []): AttachedActions
    {
        try {
            if (!$this->followUpsEnabled(self::FOLLOW_UPS_CLARIFY)
                || $this->allowedGeneric(AskFollowUpAction::KEY, $this->user($options)) === null) {
                return AttachedActions::none();
            }

            $chips = [];
            foreach ($ctx->qu->candidateReadings as $reading) {
                if (is_string($reading)) {
                    $chips[] = $this->followUpChip($reading, self::capitalized(AskFollowUpAction::sanitize($reading)));
                }
            }

            return new AttachedActions($this->dedupe(array_values(array_filter($chips))));
        } catch (\Throwable $e) {
            try {
                Log::warning('Clarification chips failed — continuing without them: ' . $e->getMessage());
            } catch (\Throwable) {
                // Logging is best effort.
            }

            return AttachedActions::none();
        }
    }

    /**
     * Page buttons of a document-grounded answer (ai.navigation.pages, {@see NavigationPages}):
     * the pages setup offers this user, of which the model picks at most `max`, each one a
     * `link` chip by route name, with `?section=` and "Page › Section" when one was picked.
     * Gates: documents retrieved, a real answer, no export, the turn's own chips. A how-to QU also read as "open
     * my team" gets them too: the page the answer is about brings its tasks. Never throws.
     *
     * @param array<string, mixed> $response
     * @param array<string, mixed> $options pipeline options (user)
     * @return array<string, mixed>
     */
    public function withNavigation(array $response, PipelineContext $ctx, array $options = []): array
    {
        try {
            $metadata = is_array($response['metadata']['response'] ?? null) ? $response['metadata']['response'] : null;
            $answer = is_string($response['answer'] ?? null) ? $response['answer'] : '';
            $attached = $ctx->attachedActions ?? AttachedActions::none();
            if ($this->navigationPages === null || $metadata === null || trim($answer) === '' || $this->unanswered($metadata)
                || $ctx->qu->exportFormat !== null
                || empty($ctx->fileContext['relevant_files']) || !empty($ctx->fileContext['enumeration'])
                || ($metadata['invocations'] ?? []) !== $attached->invocations()) {
                return $response;
            }
            $user = $this->user($options);
            $pages = NavigationPages::offeredTo($user);
            if ($pages === [] || $this->allowedGeneric('link', $user) === null) {
                return $response;
            }

            $observer = GuardedPipelineObserver::wrap($ctx->observer);
            $observer->stageStarted(NavigationPages::STAGE);
            $start = hrtime(true);
            $picked = $this->navigationPages->pick((string) ($response['question'] ?? $ctx->question), $answer, $pages, $ctx->qu->language, $observer);
            $observer->stageFinished(NavigationPages::STAGE, ['offered' => array_keys($pages), 'picked' => array_keys($picked)], round((hrtime(true) - $start) / 1e6, 3));

            $chips = [];
            $opened = $this->identityRoutes($attached);
            foreach ($picked as $key => $section) {
                // A page naming tasks the user may start gets their start chips first.
                foreach ($pages[$key]['task'] ?? [] as $taskKey) {
                    if (($task = StartTaskAction::invocation($taskKey, $user)) !== null) {
                        $chips[] = $this->chip(AttachedActions::ROLE_NEXT, $task);
                    }
                }
                if ($section === null && in_array($pages[$key]['route'], $opened, true)) {
                    continue; // the identity link already opens this page
                }
                $chips[] = $this->chip(AttachedActions::ROLE_NEXT, new ActionInvocation('link', null, null, $section === null
                    ? ['route' => $pages[$key]['route'], 'style' => 'button']
                    : ['route' => $pages[$key]['route'], 'params' => ['section' => $section], 'style' => 'button'],
                    $section === null ? $pages[$key]['label'] : $pages[$key]['label'] . ' › ' . $pages[$key]['sections'][$section]['label']));
            }
            if ($chips === []) {
                return $response;
            }

            $ctx->attachedActions = $attached->with($this->dedupe($chips));
            $response['metadata']['response'] = $ctx->attachedActions->applyTo($metadata);

            return $response;
        } catch (\Throwable $e) {
            try {
                Log::warning('Page buttons failed — continuing without them: ' . $e->getMessage());
            } catch (\Throwable) {
                // Logging is best effort.
            }

            return $response;
        }
    }

    /** @return list<string> the routes the turn's built-in identity links (my_team / my_profile) open */
    private function identityRoutes(AttachedActions $attached): array
    {
        $keys = array_intersect(array_column($attached->invocations(), 'key'), [IdentityLinkAction::PROFILE, IdentityLinkAction::TEAM]);

        return array_values(array_filter(array_map(static fn (string $key) => (new IdentityLinkAction($key))->route(), $keys)));
    }

    /**
     * The follow-up chips of a document-grounded answer, computed AFTER composition and
     * merged into the turn's chips: the response comes back with them in
     * `metadata.response.invocations`, and with the passage (and article) each one came
     * from and its similarity to the user's question in `metadata.response.follow_ups`
     * (trace evidence).
     *
     * ONE small focused call ({@see FollowUpWriter}), made only when all of this holds —
     * structural signals, never the question's words:
     *  - a writer is wired, the `knowledge` source is switched on and the user may be
     *    offered the chip;
     *  - the turn is not an export, and its answer is not an error, a block or an
     *    abstention (response metadata);
     *  - the turn is not a navigation ask (QU's navigation_target — the signal that already
     *    skips the document probes): the door of an app how-to is its page chip, and the
     *    writer declined every excerpt of such a turn (eval p19-B: 1.1-1.3 s and ~700
     *    tokens each, for no chip);
     *  - documents were retrieved (not a mere file listing) AND the answer cites at least
     *    one of them: an answer that cites no passage is not document-grounded — a data
     *    answer with documents merely in sight, or "the documents do not say";
     *  - (in the writer) something NEW is left to ask about — what neither this answer nor
     *    an earlier answer of the conversation covered (the conversation memory's
     *    `last_referenced_files`, handed to the writer) — and it is on the question's matter. By
     *    default the writer's model judges that and declines the excerpts that are not;
     *    with `follow_ups.model_declines` off, code gates instead (sources the user's own
     *    wording retrieved, and `follow_ups.min_relevance`). When nothing passes, the
     *    answer carries NO follow-up chip;
     *  - the response's chips are the ones this attacher computed for the turn (a branch
     *    that assembled its own is left alone).
     *
     * Decoration only: never throws, and any failure returns the response unchanged.
     *
     * @param array<string, mixed> $response the final response of the turn (question, answer, metadata)
     * @param array<string, mixed> $options pipeline options (user)
     * @return array<string, mixed>
     */
    public function withAnswerFollowUps(array $response, PipelineContext $ctx, array $options = []): array
    {
        try {
            return $this->answerFollowUps($response, $ctx, $options);
        } catch (\Throwable $e) {
            try {
                Log::warning('Follow-up chips failed — continuing without them: ' . $e->getMessage());
            } catch (\Throwable) {
                // Logging is best effort.
            }

            return $response;
        }
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function answerFollowUps(array $response, PipelineContext $ctx, array $options): array
    {
        $max = $this->maxFollowUps();
        if ($this->followUpWriter === null || $max === 0 || !$this->followUpsEnabled(self::FOLLOW_UPS_KNOWLEDGE)) {
            return $response;
        }

        $metadata = is_array($response['metadata']['response'] ?? null) ? $response['metadata']['response'] : null;
        $answer = is_string($response['answer'] ?? null) ? $response['answer'] : '';
        if ($metadata === null || trim($answer) === '' || $ctx->qu->exportFormat !== null || $this->unanswered($metadata)) {
            return $response;
        }
        if (trim((string) $ctx->qu->navigationTarget) !== '') {
            return $response;
        }

        if (!empty($ctx->fileContext['enumeration'])) {
            return $response;
        }
        $passages = array_values(array_filter((array) ($ctx->fileContext['relevant_files'] ?? []), 'is_array'));
        if ($passages === [] || FollowUpWriter::citedPassages($answer, count($passages)) === []) {
            return $response;
        }

        $attached = $ctx->attachedActions ?? AttachedActions::none();
        if (($metadata['invocations'] ?? []) !== $attached->invocations()) {
            return $response;
        }
        if ($this->allowedGeneric(AskFollowUpAction::KEY, $this->user($options)) === null) {
            return $response;
        }

        $question = is_string($response['question'] ?? null) && trim($response['question']) !== ''
            ? $response['question']
            : $ctx->question;

        $observer = GuardedPipelineObserver::wrap($ctx->observer);
        $observer->stageStarted(FollowUpWriter::STAGE);
        $start = hrtime(true);
        // The resolved form of the question only when the turn IS a follow-up (QU): on a
        // first message it is a mere rewording of the question above.
        $resolved = $ctx->qu->followUpReference ? $ctx->qu->clarifiedQuestion : '';
        // The topical FLOOR: how close to the user's question a follow-up must be (0 = off).
        // Not configured: who gates follows who judges — a writer whose model declines
        // tangential excerpts itself needs none (measured: it then only cost good
        // questions, and an embedding call); a code-gated writer gets the measured default.
        $configured = $this->config('ai.attached_actions.follow_ups.min_relevance');
        $minRelevance = is_numeric($configured)
            ? max(0.0, (float) $configured)
            : ($this->followUpWriter->modelDeclines() ? 0.0 : FollowUpWriter::DEFAULT_MIN_RELEVANCE);
        // What earlier answers of this conversation cited: not new material for a chip.
        $covered = array_values(array_filter((array) ($ctx->conversationContext['last_referenced_files'] ?? []), 'is_array'));
        $written = $this->followUpWriter->write($question, $answer, $passages, $ctx->qu->language, $observer, $max, $resolved, $minRelevance, $covered);
        // Observation only: counts and passage numbers — the questions ride the response.
        $observer->stageFinished(FollowUpWriter::STAGE, [
            'proposed' => $written['proposed'],
            'kept' => count($written['follow_ups']),
            'dropped' => $written['dropped'],
            'passages' => array_column($written['follow_ups'], 'passage'),
        ] + ($minRelevance > 0.0 ? ['min_relevance' => $minRelevance] : [])
            + (isset($written['scored']) ? ['relevance' => $written['scored']] : [])
            // What the writer was shown: each excerpt's passage, article and tier (no text).
            + (isset($written['excerpts']) ? ['excerpts' => $written['excerpts']] : []), round((hrtime(true) - $start) / 1e6, 3));

        $chips = [];
        $evidence = [];
        foreach ($written['follow_ups'] as $followUp) {
            $chip = $this->followUpChip($followUp['question']);
            if ($chip !== null) {
                $chips[] = $chip;
                $evidence[] = ['passage' => $followUp['passage']]
                    + (isset($followUp['article']) ? ['article' => $followUp['article']] : [])
                    + (isset($followUp['similarity']) ? ['similarity' => $followUp['similarity']] : [])
                    + ['question' => $chip['invocation']->params['question']];
            }
        }
        if ($chips === []) {
            return $response;
        }

        $ctx->attachedActions = $attached->with($this->dedupe($chips));
        $response['metadata']['response'] = $ctx->attachedActions->applyTo($metadata) + ['follow_ups' => $evidence];

        return $response;
    }

    /**
     * The "go through this section" chips of a digest: one per rendered section that shows
     * only a part of its records (a true total above the rows shown, or a row cap that was
     * hit), in registry order, at most `max_follow_ups`. Label and question are the package
     * sentence in the QUESTION's language around the section's own label (host config; the
     * fallback label when the fallback rows are what is shown). No LLM call.
     *
     * @param DigestSourceResult[] $sources
     * @return list<array{role: string, invocation: ActionInvocation}>
     */
    private function sectionFollowUps(PipelineContext $ctx, array $sources, mixed $user): array
    {
        $max = $this->maxFollowUps();
        if ($max === 0 || !$this->followUpsEnabled(self::FOLLOW_UPS_DIGEST)
            || $this->allowedGeneric(AskFollowUpAction::KEY, $user) === null) {
            return [];
        }

        $chips = [];
        foreach ($sources as $source) {
            if (!$source instanceof DigestSourceResult || $source->error !== null || $source->rows === []) {
                continue;
            }
            $partial = $source->capHit || ($source->trueTotal !== null && $source->trueTotal > $source->rowCount);
            if (!$partial) {
                continue;
            }

            $section = $source->usedFallback && $source->fallbackLabel !== null ? $source->fallbackLabel : $source->label;
            $chips[] = $this->followUpChip($this->trans(
                'ai.actions.follow_up_section',
                ['section' => $section],
                'Go through ' . $section,
                $this->locale($ctx)
            ));
        }

        return array_slice($this->dedupe(array_values(array_filter($chips))), 0, $max);
    }

    /**
     * @return array{role: string, invocation: ActionInvocation}|null null when nothing is left of the question once sanitized
     */
    private function followUpChip(string $question, ?string $label = null): ?array
    {
        $invocation = AskFollowUpAction::invocation($question, $label);

        return $invocation !== null ? $this->chip(AttachedActions::ROLE_FOLLOW_UP, $invocation) : null;
    }

    /** The text with its first letter upper-cased as a letter of its script (never a byte). */
    private static function capitalized(string $text): string
    {
        $first = mb_substr($text, 0, 1, 'UTF-8');

        return mb_convert_case($first, MB_CASE_TITLE, 'UTF-8') . mb_substr($text, 1, null, 'UTF-8');
    }

    /**
     * Whether the response metadata marks an answer that answered nothing: an error, a
     * blocked or failed part (`status`), an empty result or the canned fallback.
     *
     * @param array<string, mixed> $metadata
     */
    private function unanswered(array $metadata): bool
    {
        if (is_string($metadata['status'] ?? null) && $metadata['status'] !== '') {
            return true;
        }
        foreach (self::UNANSWERED_FLAGS as $flag) {
            if (!empty($metadata[$flag])) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // B1 — record chips
    // =========================================================================

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{0: list<array{role: string, invocation: ActionInvocation}>, 1: array<int, ActionInvocation>, 2: array<int, array<string, mixed>>}
     */
    private function recordChips(
        array $rows,
        ?string $cypher,
        PipelineContext $ctx,
        mixed $user,
        bool $withRefs,
        ?int $max = null,
        bool $withMore = true
    ): array {
        $labels = array_values(array_filter(
            $this->registry()->entityLabels(),
            fn (string $label) => $this->primaryAction($label, $user) !== null
        ));
        if ($labels === []) {
            return [[], [], []];
        }

        $records = (new RecordResolver($labels, $cypher, $ctx->qu->entityTypes))->resolve($rows);
        if ($records === []) {
            return [[], [], []];
        }

        $invocations = [];
        $actions = [];
        foreach ($records as $index => $record) {
            $action = $this->primaryAction($record['label'], $user);
            if ($action !== null) {
                $actions[$index] = $action;
                $invocations[$index] = new ActionInvocation(
                    $action->key(), $record['label'], $record['id'], [], $this->recordText($record, $action)
                );
            }
        }

        // One chip per DISTINCT record, capped after the collapse: rows repeating a record
        // (several staff members of one branch, when only the branch is openable) must not
        // spend the cap on copies and end as one arbitrary chip (eval p9 F7). Inline refs
        // below still map every row to its record.
        $distinct = [];
        $firstRow = [];
        foreach ($invocations as $index => $invocation) {
            $distinct[$invocation->fingerprint()] ??= $invocation;
            $firstRow[$invocation->fingerprint()] ??= $index;
        }
        $firstRow = array_values($firstRow);
        $distinct = array_values($distinct);

        $max ??= $this->maxRecordChips();
        $chips = [];
        foreach ($this->spreadOverGroups($distinct, $firstRow, $records, $actions, $max) as $invocation) {
            $chips[] = $this->chip(AttachedActions::ROLE_RECORD, $invocation);
        }

        if ($withMore && count($distinct) > $max) {
            // Rows never retrieved count as more records only when one row IS one record:
            // a fan-out (20 staff-member rows over 8 branches) has a ROW total, not a record
            // total, so only the distinct records not shown are counted.
            $total = $this->resultTotal($ctx, count($rows));
            $fanOut = count($distinct) < count($invocations);
            $remaining = (count($distinct) - $max) + ($fanOut ? 0 : max(0, $total - count($rows)));
            // The list page of the result's SUBJECT ({@see listTarget()}); the records' label
            // is the one with the most records (not merely the first row's). No list page
            // for the subject → no "+N more" chip at all.
            $perLabel = array_count_values(array_map(static fn (ActionInvocation $i) => (string) $i->entityType, $distinct));
            arsort($perLabel);
            $list = $this->listTarget($ctx, (string) array_key_first($perLabel), $this->cypherLabels($cypher), false, $user);
            if ($list !== null && $remaining > 0) {
                $chips[] = $this->chip(AttachedActions::ROLE_MORE, new ActionInvocation(
                    $list->key(), null, null, [], $this->trans('ai.actions.more_records', ['count' => $remaining], '+' . $remaining . ' more', $this->locale($ctx))
                ));
            }
        }

        $refs = [];
        if ($withRefs) {
            foreach ($invocations as $index => $invocation) {
                $refs[$index + 1] = $invocation;
            }
        }

        return [$chips, $refs, $records];
    }

    /**
     * The record chips shown, at most $max.
     *
     * Everything fits → every record, in row order, labelled as is.
     *
     * More records than the cap AND the records belong to several GROUPS (the coarser
     * column of another node the rows name beside them — each staff member's branch,
     * {@see RecordResolver::grouped()}): the cap is taken in turn from each group, in the
     * rows' own group order — as forDigest() does across sections — and every chip names its
     * group ("Jane Doe — 12 Main Street"). Eval p16-A: rows ordered by group
     * spent all five chips on the first of eight groups.
     *
     * @param list<ActionInvocation> $distinct one invocation per distinct record, in row order
     * @param list<int> $firstRow the row index each distinct record first appears on
     * @param array<int, array<string, mixed>> $records row index => record
     * @param array<int, AiActionInterface> $actions row index => the record's primary action
     * @return list<ActionInvocation>
     */
    private function spreadOverGroups(array $distinct, array $firstRow, array $records, array $actions, int $max): array
    {
        if (count($distinct) <= $max) {
            return $distinct;
        }

        $groups = [];
        foreach ($distinct as $position => $invocation) {
            $groups[(string) ($records[$firstRow[$position]]['group'] ?? '')][] = $position;
        }
        if (count($groups) < 2) {
            return array_slice($distinct, 0, $max);
        }

        $picked = [];
        for ($turn = 0; count($picked) < $max; $turn++) {
            $took = false;
            foreach ($groups as $positions) {
                if (isset($positions[$turn]) && count($picked) < $max) {
                    $picked[] = $positions[$turn];
                    $took = true;
                }
            }
            if (!$took) {
                break;
            }
        }

        return array_map(function (int $position) use ($distinct, $firstRow, $records, $actions): ActionInvocation {
            $invocation = $distinct[$position];
            $row = $firstRow[$position];

            return new ActionInvocation(
                $invocation->key, $invocation->entityType, $invocation->entityId, $invocation->params,
                $this->recordText($records[$row], $actions[$row], true)
            );
        }, $picked);
    }

    /**
     * "310" → "Account 310"; "Northwind" stays as-is; + " — <disambiguator>" for homonyms; a
     * nameless record → its action label + " — <the row's other record>" ("Invoice PDF —
     * Account 301"), never N identical chips. $withGroup (chips spread over groups): a record
     * no disambiguator already places gets " — <its group>".
     *
     * @param array{label: string, name: ?string, disambiguator: ?string, group?: ?string, context?: array{label: string, name: string}|null} $record
     */
    private function recordText(array $record, AiActionInterface $action, bool $withGroup = false): string
    {
        $name = $record['name'];
        if ($name === null) {
            $text = $action->label();
            $context = $record['context'] ?? null;
            if (is_array($context)) {
                $text .= ' — ' . $this->named($context['label'], $context['name']);
            }
        } else {
            $text = $this->named($record['label'], $name);
        }

        if ($record['disambiguator'] !== null && $record['disambiguator'] !== $name) {
            $text .= ' — ' . $record['disambiguator'];
        } elseif ($withGroup && $name !== null && $record['disambiguator'] === null && ($record['group'] ?? null) !== null && $record['group'] !== $name) {
            // A NAMED record only: a nameless one is already placed by its context.
            $text .= ' — ' . $record['group'];
        }

        return $text;
    }

    /** A bare number/code gets its entity's display name ("Account 310"); a real name stays. */
    private function named(string $label, string $name): string
    {
        return preg_match('/\p{L}{2,}/u', $name) === 1 ? $name : $this->displayName($label) . ' ' . $name;
    }

    /**
     * The entity's main "open" action for this user: the read-only action flagged
     * `primary`, else the first read-only action in config order. Mutations never.
     */
    private function primaryAction(string $label, mixed $user): ?AiActionInterface
    {
        $memo = $this->userKey($user) . '|' . $label;
        if (array_key_exists($memo, $this->primaryCache)) {
            return $this->primaryCache[$memo];
        }

        $readable = $this->readableActions($label, $user);
        $primary = null;
        foreach ($readable as $action) {
            if (($this->registry()->attributes($action->key(), $label)['primary'] ?? false) === true) {
                $primary = $action;
                break;
            }
        }

        return $this->primaryCache[$memo] = $primary ?? ($readable[0] ?? null);
    }

    /** A stable identity for the principal (model key, id, else the object itself; 'guest'). */
    private function userKey(mixed $user): string
    {
        if (!is_object($user)) {
            return 'guest';
        }
        $key = method_exists($user, 'getKey') ? $user->getKey() : ($user->id ?? null);

        return is_scalar($key) && (string) $key !== '' ? 'u:' . $key : 'o:' . spl_object_id($user);
    }

    /**
     * @return list<AiActionInterface> the label's read-only actions the user may be offered
     */
    private function readableActions(string $label, mixed $user): array
    {
        return array_values(array_filter(
            $this->registry()->entityActions($label),
            static fn (AiActionInterface $action) => !$action->mutates() && ActionAdvertisement::allows($action, $user)
        ));
    }

    // =========================================================================
    // B2 — identity links
    // =========================================================================

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{role: string, invocation: ActionInvocation}>
     */
    private function identityChips(PipelineContext $ctx, array $rows, mixed $user, ?string $cypher = null, bool $current = true): array
    {
        if ($user === null) {
            return [];
        }

        $labels = (array) ($this->config('ai.response_generation.identity_labels') ?? []);
        $types = array_map([self::class, 'normalize'], array_filter($ctx->qu->entityTypes, 'is_string'));
        // What the turn is ABOUT: QU's entity types, or — a follow-up that names none — the
        // recorded result it follows ({@see subjectTypes()}). Membership only: the PRIMARY
        // subject below stays QU's own first entity type.
        $subject = array_map([self::class, 'normalize'], $this->subjectTypes($ctx));
        $mentions = fn (string $label) => in_array(self::normalize($label), $subject, true);

        $chips = [];
        // About the user themself — structured signals only, and when they cannot decide,
        // nothing is attached:
        //   - possessive + the user label, or QU's navigation_target = own_profile (the
        //     LLM judged an ask to open / link their own profile, in any language);
        //   - the user's own row (8-A self-reference detection) when it IS the result (the
        //     only row) — never a manager who merely appears in a list of customers or in
        //     their own team's roster, and never a record of something else that names the
        //     user in one of its fields ({@see isOwnRecord()}).
        //   - possessive counts only when the user label is QU's FIRST (primary) entity type:
        //     "my team's members" is possessive about the TEAM — the user's own row inside that
        //     roster is incidental (graded as noise in eval p11/p12 roster turns). QU's own
        //     types only: the label order of a recorded query is how it was written, never a
        //     judgment of the primary subject (a roster query often starts at User).
        $userLabel = (string) ($labels['user'] ?? 'User');
        $types = array_values($types);
        $userIsPrimary = ($types[0] ?? null) === self::normalize($userLabel);
        $aboutSelf = ($ctx->qu->isPersonalPossessive() && $userIsPrimary)
            || $ctx->qu->navigationTarget === 'own_profile';
        if ($aboutSelf || (count($rows) === 1 && $this->isOwnRecord($rows[0], $cypher, $ctx, $user, $userLabel, $current))) {
            $chips[] = $this->identityChip('profile', IdentityLinkAction::PROFILE, $user, $this->locale($ctx));
        }
        $teamLabel = (string) ($labels['team'] ?? 'Team');
        $aboutTeam = $mentions($teamLabel) || $ctx->qu->navigationTarget === 'own_team';
        if ($aboutTeam && $this->hasLiveCurrentTeam($user)) {
            $team = $this->identityChip('team', IdentityLinkAction::TEAM, $user, $this->locale($ctx));
            $chips[] = $team;
            // The doors of the user's own team, beside its link — only for who gets the link.
            if ($team !== null && $current && count($rows) === 1
                && !in_array($ctx->qu->intent, self::COUNT_INTENTS, true)
                && $this->isOwnTeamRecord($rows[0], $cypher, $user, $teamLabel)) {
                array_push($chips, ...$this->teamDoors($user, $this->locale($ctx)));
            }
        }

        return array_values(array_filter($chips));
    }

    /**
     * The RELATED DOORS of the user's own team (eval p17-p20 A team-info, "Can I get
     * information about my team?": the answer showed the team's record with its settings
     * link and nothing of what the team manages). A host lists them under
     * `ai.attached_actions.identity.team.related` — keys of its own generic actions, the
     * pages of the CURRENT team's portfolio (its projects, meetings, receivables…). They are
     * pages without a record id, so they are offered only where they mean what they show:
     * on the answer that IS the current team's own record ({@see isOwnTeamRecord()}).
     *
     * In the declared order, each only when this user may open it, at most
     * `max_next_steps`. Not configured (the default): none.
     *
     * @return list<array{role: string, invocation: ActionInvocation}>
     */
    private function teamDoors(mixed $user, ?string $locale): array
    {
        $doors = [];
        foreach ((array) $this->config('ai.attached_actions.identity.team.related', []) as $key) {
            $doors[] = is_string($key) && $key !== '' ? $this->allowedGeneric($key, $user) : null;
        }

        return $this->stepChips(array_values(array_filter($doors)), $locale);
    }

    /**
     * Whether the ONE row of a result is the record of the user's CURRENT team — structure
     * only:
     *  - the row holds a column of the team: the query projects it from a node of the team
     *    label (`t.name AS team_name`);
     *  - that team is the current one: the query binds the team node's id to the user's
     *    current team id (`(t:Team {id: 3})` / `t.id = 3` — the generator inlines it), or
     *    the row carries the team's own id column with that value.
     * A team reached only through the tenant scope (`t.id IN $ai_team_ids`) may be another
     * team of the portfolio: not proven, no doors.
     *
     * @param array<string, mixed> $row
     */
    private function isOwnTeamRecord(array $row, ?string $cypher, mixed $user, string $teamLabel): bool
    {
        $teamId = is_object($user) ? ($user->current_team_id ?? null) : null;
        if ($cypher === null || trim($cypher) === '' || !(is_int($teamId) || is_string($teamId)) || (string) $teamId === '') {
            return false;
        }

        $resolver = new RecordResolver([], $cypher);
        $ownsColumn = false;
        $carriesId = false;
        foreach ($row as $column => $value) {
            $owner = $resolver->columnOwner((string) $column);
            if ($owner === null || self::normalize($owner['label']) !== self::normalize($teamLabel)) {
                continue;
            }
            $ownsColumn = true;
            $carriesId = $carriesId || ($owner['property'] === 'id' && is_scalar($value) && (string) $value === (string) $teamId);
        }

        return $ownsColumn
            && ($carriesId || (new UserOwnershipPaths([], $teamLabel))->anchorIn($cypher, $teamId) !== null);
    }

    /**
     * Whether the ONE row of a result is the user's OWN record: one of the user's identity
     * values (8-A self-reference detection: e-mail / full name) sits in a column of a node
     * that IS the user — the user label, or a label the host's ownership paths bind to the
     * user (`ai.user_entity_paths`: `(c:Contact {user_id: $userId})` makes the user's
     * contact their linked identity record; {@see UserOwnershipPaths::anchors()}).
     *
     * A record of anything else that merely NAMES the user in one of its fields is not that
     * (eval p20: a meeting whose organizer is the user, a team whose contact e-mail is the
     * user's — each attached "My profile" to an answer about a meeting / a team). WHOSE
     * column a value sits in is read from structure only:
     *   - a column the single-record enrichment added (execution metadata `record_enriched`,
     *     this turn's rows only): a parent's name is the parent's, a detail field is the
     *     enriched record's;
     *   - else the query's own projection (`m.organizer AS organizer` → Meeting);
     *   - a column no node of the query owns decides nothing — no link.
     * Rows that came with NO query at all cannot be attributed: the value alone decides, as
     * before.
     *
     * @param array<string, mixed> $row
     */
    private function isOwnRecord(array $row, ?string $cypher, PipelineContext $ctx, mixed $user, string $userLabel, bool $current): bool
    {
        $own = [];
        foreach ($row as $column => $value) {
            if (SelfReferenceNoticeSection::selfValuesIn([[$column => $value]], $user) !== []) {
                $own[] = (string) $column;
            }
        }
        if ($own === []) {
            return false;
        }
        if ($cypher === null || trim($cypher) === '') {
            return true;
        }

        $identity = [self::normalize($userLabel) => true];
        foreach ((new UserOwnershipPaths(null, $userLabel))->anchors() as $anchor) {
            $identity[self::normalize($anchor['label'])] = true;
        }

        $enriched = $current ? ($ctx->executionResult->metadata['record_enriched'] ?? null) : null;
        $enriched = is_array($enriched) ? $enriched : [];
        $parents = (array) ($enriched['columns'] ?? []);
        $details = array_filter((array) ($enriched['details'] ?? []), 'is_string');
        $resolver = new RecordResolver([], $cypher);

        foreach ($own as $column) {
            $label = match (true) {
                array_key_exists($column, $parents) => $parents[$column],
                in_array($column, $details, true) => $enriched['entity'] ?? null,
                default => $resolver->columnOwner($column)['label'] ?? null,
            };
            if (is_string($label) && isset($identity[self::normalize($label)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The entity types this turn is ABOUT, for attachment (B2 team link, B3 empty offers).
     *
     * QU's own entity types whenever it named any — and always on a turn that is not a
     * follow-up: nothing changes for those.
     *
     * A QU follow-up ("add their last login to it") often names NONE: its subject lives in
     * the conversation, not in the question (eval p16-B resilience t1 lost the Team settings
     * chip its own roster carried one turn earlier). Then the RECORDED RESULT stands in —
     * structural signals only, and when they cannot decide, nothing does:
     *   - the conversation recorded a query (last_cypher_query; a digest turn records rows
     *     without one) — its node labels are read from that query itself, never from
     *     last_entity_types, which a query-less turn leaves behind from an older result;
     *   - the label the recorded rows ARE (last_anchor_label, written with the query) comes
     *     first;
     *   - the conversation's focus (focused_entity, moved by EVERY turn — a documents turn
     *     about another entity included) is still one of those labels: a focus that moved
     *     off the recorded result means the follow-up may not be about it.
     *
     * @return list<string>
     */
    private function subjectTypes(PipelineContext $ctx): array
    {
        $named = array_values(array_filter(
            $ctx->qu->entityTypes,
            static fn ($type): bool => is_string($type) && trim($type) !== ''
        ));
        if ($named !== [] || !$ctx->qu->followUpReference) {
            return $named;
        }

        $conversation = $ctx->conversationContext;
        $recorded = $conversation['last_cypher_query'] ?? null;
        $labels = $this->cypherLabels(is_string($recorded) ? $recorded : null);
        if ($labels === []) {
            return [];
        }

        $focus = $conversation['focused_entity'] ?? null;
        $normalized = array_map([self::class, 'normalize'], $labels);
        if (is_string($focus) && trim($focus) !== '' && !in_array(self::normalize($focus), $normalized, true)) {
            return [];
        }

        $anchor = $conversation['last_anchor_label'] ?? null;
        if (is_string($anchor) && in_array(self::normalize($anchor), $normalized, true)) {
            array_unshift($labels, $labels[array_search(self::normalize($anchor), $normalized, true)]);
        }

        return array_values(array_unique($labels));
    }

    /**
     * @return array{role: string, invocation: ActionInvocation}|null
     */
    private function identityChip(string $kind, string $builtIn, mixed $user, ?string $locale): ?array
    {
        // A host that names its own action for this page decides who may see it: a user it
        // DENIES gets no link at all — never the built-in (teams.show) as a back door.
        $key = $this->config("ai.attached_actions.identity.{$kind}.action");
        $action = is_string($key) && $key !== ''
            ? $this->allowedGeneric($key, $user)
            : $this->allowedGeneric($builtIn, $user);
        if ($action === null) {
            return null;
        }

        // p11 G5: a HOST action's label is host text — a literal ("Team settings", which
        // Laravel treats as a JSON translation key) or a group key ("menus.team-settings").
        // Either way it is resolved through the translator in the QUESTION's language, so a
        // French question gets the host's French label; no translation → the label as-is.
        $text = $action instanceof IdentityLinkAction
            ? $action->labelIn($locale)
            : $this->trans($action->label(), [], $action->label(), $locale);

        return $this->chip(AttachedActions::ROLE_IDENTITY, new ActionInvocation($action->key(), null, null, [], $text));
    }

    /**
     * Whether the user's current team still exists: no team link for a missing or
     * soft-deleted current team. A Jetstream-style currentTeam() relation answers (it
     * excludes trashed teams); without one, a set current_team_id is all we can check.
     */
    private function hasLiveCurrentTeam(mixed $user): bool
    {
        $teamId = is_object($user) ? ($user->current_team_id ?? null) : null;
        if (!is_scalar($teamId) || (string) $teamId === '') {
            return false;
        }
        if (!method_exists($user, 'currentTeam')) {
            return true;
        }

        try {
            $relation = $user->currentTeam();

            return is_object($relation) && method_exists($relation, 'exists') ? (bool) $relation->exists() : $relation !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The QUESTION's language (QU), for the package's own chip labels; null = app locale. */
    private function locale(PipelineContext $ctx): ?string
    {
        return $ctx->qu->language !== '' ? $ctx->qu->language : null;
    }

    // =========================================================================
    // B3 — next steps by answer shape
    // =========================================================================

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $records
     * @return list<array{role: string, invocation: ActionInvocation}>
     */
    private function nextSteps(PipelineContext $ctx, array $rows, ?string $cypher, array $records, mixed $user): array
    {
        $steps = [];
        $failed = !empty($ctx->executionResult->errors)
            || ($ctx->executionResult->metadata['safe_stop'] ?? false) === true;

        if ($rows === []) {
            // EMPTY: only a genuine "nothing matched" (no documents grounding the turn,
            // no failed/blocked data part) — the entity's list page + its create form.
            if ($failed || !empty($ctx->fileContext['relevant_files'])) {
                return [];
            }
            // OWN DATA — a question about records linked to the user ("my contribution"):
            // nothing linked is the answer, never an invitation to create one (eval p9 F4:
            // "New invoice" for a manager who owns no account). The subject entity is still the
            // first one with a page: suppressing its create form never falls through to a
            // secondary entity's list (Invoice → never "Accounts").
            //
            // Own data is first what the EXECUTED query says (eval p16-B rights t2): AiManager
            // stamps an empty result's scope from the query's user anchor (execution metadata
            // empty_scope = 'own'), whatever QU judged. QU's judgment counts only as PERSONAL
            // possession ("my…"): a COLLECTIVE possessive ("our next meeting", "do we have…")
            // speaks for the team — it is a team question, and gets the team question's doors
            // (list + create for who may create), as "any upcoming meetings?" does.
            //
            // ONE DOOR for an own-data empty: the subject's list page, else its REPORT. With
            // the create form suppressed, a subject that has no list page (Invoice: create +
            // report only) used to attach nothing at all — the manager who owns no account was
            // left without the receivables report, the page where the figure they asked
            // about lives. Still only the first subject with a page, still only a page the
            // user may open.
            $ownData = ($ctx->executionResult->metadata['empty_scope'] ?? null) === 'own'
                || $ctx->qu->isPersonalPossessive();
            // QU's entity types (a follow-up naming none: the recorded result's — one rule
            // with the team link, {@see subjectTypes()}).
            foreach ($this->subjectTypes($ctx) as $label) {
                $list = $this->genericFor($label, 'list', $user);
                $create = $this->genericFor($label, 'create', $user);
                $report = $ownData && $list === null ? $this->genericFor($label, 'report', $user) : null;
                if ($list !== null || $create !== null || $report !== null) {
                    $steps[] = $list ?? $report;
                    $steps[] = $ownData ? null : $create;
                    break;
                }
            }

            return $this->stepChips($steps, $this->locale($ctx));
        }

        // ONE RECORD: its other read actions (statement, …).
        if (count($rows) === 1 && count($records) === 1) {
            $record = reset($records);
            $primary = $this->primaryAction($record['label'], $user);
            foreach ($this->readableActions($record['label'], $user) as $action) {
                if ($primary === null || $action->key() !== $primary->key()) {
                    $steps[] = new ActionInvocation($action->key(), $record['label'], $record['id'], [], $action->label());
                }
            }
        }

        // FALLBACK ROWS of an empty result (execution metadata empty_fallback, stamped by
        // AiManager::resolveEmptyResult — "no upcoming meeting; the most recent ones are…"):
        // the source's own configured page, the link the digest gives that section. It is
        // the door the plain empty answer had (the entity's list page) — kept beside the
        // rows' record chips. Only a page this user may open.
        $fallbackPage = $ctx->executionResult->metadata['empty_fallback']['action_key'] ?? null;
        if (is_string($fallbackPage) && $fallbackPage !== '') {
            $steps[] = $this->allowedGeneric($fallbackPage, $user);
        }

        $labels = $this->cypherLabels($cypher);
        $recordLabel = $records !== [] ? reset($records)['label'] : null;

        // TRUNCATED: the user sees part of the list — its list page, and the Excel of
        // every retrieved row when the retrieval itself was complete.
        // Same counts DataAnswerPlan uses: the companion true total, the executor row cap
        // (a cap-hit retrieval without a true total may hide more rows) and the table's
        // display cap.
        $total = $this->resultTotal($ctx, count($rows));
        $hasTrueTotal = is_numeric($ctx->executionResult->metadata['true_total'] ?? $ctx->executionResult->stats['true_total'] ?? null);
        $executorCap = (int) $this->config('ai.query_execution.default_limit', 100);
        $retrievedAll = $total <= count($rows) && ($hasTrueTotal || $executorCap <= 0 || count($rows) < $executorCap);
        $displayCap = (int) $this->config('ai.response_generation.table_max_rows', 25);
        $truncated = count($rows) > 1 && (!$retrievedAll || ($displayCap > 0 && count($rows) > $displayCap));
        if ($truncated) {
            $steps[] = $this->listTarget($ctx, $recordLabel, $labels, true, $user);
            // The Excel chip in mode=table exports the message's STORED rows
            // (MessagesQuery::messageResultSet → response_data, the executor's retrieved
            // rows). A capped retrieval (100 of 523) would download a silently partial file
            // from them (eval p9 F10), so that mode is offered only when every row was
            // retrieved.
            //
            // A CAPPED list gets the chip in RE-RUN mode instead (eval p18-A roster: "25 of
            // 523" with no export door, while the next turn's "give me that as an Excel"
            // exported all 523 by re-running the recorded query): the click re-executes the
            // query recorded with the message, as the clicking user, without its display cap
            // (RecordedResultExport). Offered only when that re-run would hold EVERY row — the
            // true total is known, a document may hold that many rows, the query can be
            // widened and it binds nothing a re-run cannot replay (RecordedQueryRerun::
            // coversAll) — else no chip, as before: the list page stays the door to the full
            // population. Fallback rows of an empty result come from a host source query run
            // with that source's own bindings: never re-run from here.
            if ($retrievedAll) {
                $export = $this->exportChip('excel', $ctx, $user);
                $steps[] = $export['invocation'] ?? null;
            } elseif ($hasTrueTotal
                && !isset($ctx->executionResult->metadata['empty_fallback'])
                && RecordedQueryRerun::coversAll($cypher, $total, 'excel', (array) $ctx->queryResult->parameters)) {
                $export = $this->exportChip('excel', $ctx, $user, true);
                $steps[] = $export['invocation'] ?? null;
            }
        }

        // MONEY TOTAL: the entity's report, else its list page.
        $columns = array_keys($rows[0]);
        if (array_intersect(MoneyColumns::fromCypher($cypher), $columns) !== []) {
            foreach ($labels as $label) {
                $target = $this->genericFor($label, 'report', $user) ?? $this->genericFor($label, 'list', $user);
                if ($target !== null) {
                    $steps[] = $target;
                    break;
                }
            }
        }

        return $this->stepChips($steps, $this->locale($ctx));
    }

    /**
     * The list page for "the rest of this result" — the "+N more" chip and the truncated
     * list's door. A list page is offered for what the question is ABOUT, never for an
     * entity the rows merely link to (eval p16-A staff: 37 staff members, "+32 more" opened
     * the directory of EVERY contact because the openable record on each row is its contact).
     *
     * The subject is QU's entity types (a follow-up naming none: the recorded result's —
     * {@see subjectTypes()}), kept to the ones this result's query actually matches. In order:
     *   1. QU named nothing this result holds → structure decides, as before: the records'
     *      label, then ($anyLabel — the truncated list) the query's labels in order;
     *   2. a subject QU named BEFORE the records' own type (or instead of it) is a CHILD of
     *      the records' entity ({@see belongsTo()}: a staff member holds the key of its
     *      contact) → each row is one of THAT subject's records, the openable record only
     *      the parent it is named by: the subject's list page, or nothing;
     *   3. the records ARE a subject (customers for a question about customers and their invoices) →
     *      their list page; none configured → nothing;
     *   4. the records are not what the question is about (or there are none): "+N more"
     *      would not open more of THESE records → nothing; the truncated list ($anyLabel)
     *      still gets a door under its own name — the first subject's that has a list page.
     * Nothing → no chip: the table's "Showing 25 of N" line and the Excel chip cover the rest.
     *
     * @param list<string> $cypherLabels the query's node labels, in order of appearance
     */
    private function listTarget(PipelineContext $ctx, ?string $recordLabel, array $cypherLabels, bool $anyLabel, mixed $user): ?AiActionInterface
    {
        $inResult = array_map([self::class, 'normalize'], array_filter([$recordLabel, ...$cypherLabels], 'is_string'));
        $subjects = array_values(array_filter(
            $this->subjectTypes($ctx),
            static fn (string $type): bool => in_array(self::normalize($type), $inResult, true)
        ));

        if ($subjects === []) {
            $candidates = $anyLabel ? [$recordLabel, ...$cypherLabels] : [$recordLabel];
            foreach (array_values(array_unique(array_filter($candidates, 'is_string'))) as $label) {
                if (($list = $this->genericFor($label, 'list', $user)) !== null) {
                    return $list;
                }
            }

            return null;
        }

        if ($recordLabel !== null) {
            // QU order: its first type is the primary subject (as for the identity links).
            foreach ($subjects as $subject) {
                if (self::normalize($subject) === self::normalize($recordLabel)) {
                    return $this->genericFor($recordLabel, 'list', $user);
                }
                if ($this->belongsTo($subject, $recordLabel)) {
                    return $this->genericFor($subject, 'list', $user);
                }
            }
        }

        if (!$anyLabel) {
            return null;
        }
        foreach ($subjects as $subject) {
            if (($list = $this->genericFor($subject, 'list', $user)) !== null) {
                return $list;
            }
        }

        return null;
    }

    /**
     * Whether each $child record BELONGS TO one $parent record: the child's entity config
     * declares a relationship to the parent's label through a foreign key that is one of the
     * child's OWN properties (StaffMember.contact_id → Contact). A hasMany / morphMany
     * declared with the other table's key, a pivot and an inverse declaration are not that.
     * Structural (the host's declared relationships) — unknown entities are never children.
     */
    private function belongsTo(string $child, string $parent): bool
    {
        try {
            $index = EntityLabelIndex::shared();
            $config = $index->configForLabel($index->labelForTerm($child) ?? $child) ?? [];
        } catch (\Throwable $e) {
            return false;
        }

        $own = array_filter((array) ($config['graph']['properties'] ?? []), 'is_string');
        foreach ((array) ($config['graph']['relationships'] ?? []) as $relationship) {
            if (!is_array($relationship)
                || ($relationship['inverse'] ?? false) === true
                || isset($relationship['pivot'])
                || isset($relationship['morph'])) {
                continue;
            }
            $target = $relationship['target_label'] ?? null;
            $key = $relationship['foreign_key'] ?? null;
            if (is_string($target) && is_string($key) && self::normalize($target) === self::normalize($parent) && in_array($key, $own, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A host page attached as a next step is labelled like the identity chip (p11 G5): its
     * label is host text, resolved through the translator in the QUESTION's language — no
     * translation → the label as-is.
     *
     * @param list<AiActionInterface|ActionInvocation|null> $steps
     * @return list<array{role: string, invocation: ActionInvocation}>
     */
    private function stepChips(array $steps, ?string $locale = null): array
    {
        $chips = [];
        foreach ($steps as $step) {
            if ($step instanceof AiActionInterface) {
                $step = new ActionInvocation($step->key(), null, null, [], $this->trans($step->label(), [], $step->label(), $locale));
            }
            if ($step instanceof ActionInvocation) {
                $chips[] = $this->chip(AttachedActions::ROLE_NEXT, $step);
            }
        }

        return array_slice($this->dedupe($chips), 0, $this->maxNextSteps());
    }

    /**
     * The Excel / PDF export of the message's result set (the built-in export actions):
     * its stored rows (mode=table), or — $rerun — a re-run of the query recorded with the
     * message (mode=rerun, {@see RecordedResultExport}). The re-run chip also carries the
     * answer's language, for the file's labels and headers: the click happens later, when
     * the turn's understanding is gone.
     *
     * @return array{role: string, invocation: ActionInvocation}|null
     */
    private function exportChip(string $format, PipelineContext $ctx, mixed $user, bool $rerun = false): ?array
    {
        $key = $format === 'pdf' ? 'export_pdf' : 'export_excel';
        $action = $this->allowedGeneric($key, $user);
        if ($action === null) {
            return null;
        }

        // User-visible (file title): QU's localized subject_label, else search_query.
        $title = $ctx->qu->displaySubject() !== '' ? $ctx->qu->displaySubject() : 'export';

        $params = ['title' => $title, 'mode' => $rerun ? RecordedResultExport::MODE : 'table'];
        if ($rerun && $this->locale($ctx) !== null) {
            $params['language'] = $this->locale($ctx);
        }

        return $this->chip(AttachedActions::ROLE_NEXT, new ActionInvocation($key, null, null, $params, $this->exportLabel($action, $format, $ctx)));
    }

    /**
     * The export chip's label in the QUESTION's language, like the identity and next-step
     * chips (eval p19-B french t0: "Download Excel" under a French roster). The built-in
     * actions' own label is a fixed English string: theirs is the package lang line the
     * export answer already uses for its download link (ExportResponseComposer). A
     * host action registered under the same key keeps its label, resolved through the
     * translator as every host label is — no translation → the label as-is.
     */
    private function exportLabel(AiActionInterface $action, string $format, PipelineContext $ctx): string
    {
        $label = $action->label();
        $key = $action instanceof ExportExcelAction || $action instanceof ExportPdfAction
            ? ($format === 'pdf' ? 'ai.export.download_pdf' : 'ai.export.download_excel')
            : $label;

        return $this->trans($key, [], $label, $this->locale($ctx));
    }

    // =========================================================================
    // Lookups
    // =========================================================================

    /**
     * The generic action declared (`entity` + `kind`) as $label's list / create / report
     * page, when this user may be offered it.
     */
    private function genericFor(string $label, string $kind, mixed $user): ?AiActionInterface
    {
        $wanted = self::normalize($label);
        foreach (array_keys((array) $this->config('ai.generic_actions', [])) as $key) {
            $attributes = $this->registry()->attributes((string) $key);
            if (($attributes['kind'] ?? null) !== $kind) {
                continue;
            }
            $entities = array_map([self::class, 'normalize'], array_filter((array) ($attributes['entity'] ?? []), 'is_string'));
            if (!in_array($wanted, $entities, true)) {
                continue;
            }
            if (($action = $this->allowedGeneric((string) $key, $user)) !== null) {
                return $action;
            }
        }

        return null;
    }

    private function allowedGeneric(string $key, mixed $user): ?AiActionInterface
    {
        $action = $this->registry()->getGeneric($key);

        return $action !== null && !$action->mutates() && ActionAdvertisement::allows($action, $user) ? $action : null;
    }

    /**
     * Node labels in the cypher, in order of appearance.
     *
     * @return list<string>
     */
    private function cypherLabels(?string $cypher): array
    {
        if ($cypher === null || trim($cypher) === '') {
            return [];
        }
        $cypher = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/", "''", $cypher);
        preg_match_all('/\(\s*[A-Za-z_]?\w*\s*:\s*`?([A-Za-z_]\w*)`?/', $cypher, $m);

        return array_values(array_unique($m[1] ?? []));
    }

    /** The population's true total (companion count) when known, else the row count. */
    private function resultTotal(PipelineContext $ctx, int $rows): int
    {
        $trueTotal = $ctx->executionResult->metadata['true_total'] ?? $ctx->executionResult->stats['true_total'] ?? null;

        return is_numeric($trueTotal) && (int) $trueTotal >= $rows ? (int) $trueTotal : $rows;
    }

    private function displayName(string $label): string
    {
        try {
            $config = EntityLabelIndex::shared()->configForLabel($label) ?? [];
        } catch (\Throwable $e) {
            $config = [];
        }
        $display = $config['metadata']['display_name'] ?? $config['display_name'] ?? null;

        return mb_convert_case(is_string($display) && trim($display) !== '' ? trim($display) : $label, MB_CASE_TITLE);
    }

    /**
     * @return array{role: string, invocation: ActionInvocation}
     */
    private function chip(string $role, ActionInvocation $invocation): array
    {
        return ['role' => $role, 'invocation' => $invocation];
    }

    /**
     * First occurrence wins — by invocation fingerprint, and a parameterless generic action
     * once (a "+N more" list chip and a truncation list chip are the same door); a task's start
     * chip is its task, so two tasks are two chips. A
     * follow-up chip IS its question: two questions are two chips, and the same question
     * in another casing or punctuation is one.
     *
     * @param list<array{role: string, invocation: ActionInvocation}> $chips
     * @return list<array{role: string, invocation: ActionInvocation}>
     */
    private function dedupe(array $chips): array
    {
        $seen = [];
        $kept = [];
        foreach ($chips as $chip) {
            $invocation = $chip['invocation'];
            $key = match (true) {
                $chip['role'] === AttachedActions::ROLE_FOLLOW_UP => 'ask:' . self::comparable((string) ($invocation->params['question'] ?? $invocation->text)),
                $invocation->entityType === null && $invocation->key !== StartTaskAction::KEY => 'generic:' . $invocation->key,
                default => $invocation->fingerprint(),
            };
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $kept[] = $chip;
        }

        return $kept;
    }

    private function registry(): AiActionRegistry
    {
        if ($this->registry === null) {
            try {
                $this->registry = app(AiActionRegistry::class);
            } catch (\Throwable $e) {
                $this->registry = new AiActionRegistry();
            }
        }

        return $this->registry;
    }

    /** The principal: the threaded options['user'] first, the auth user next. */
    private function user(array $options): mixed
    {
        $user = $options['user'] ?? null;

        return is_object($user) ? $user : ActionAdvertisement::currentUser();
    }

    private function enabled(): bool
    {
        return (bool) $this->config('ai.attached_actions.enabled', true);
    }

    private function maxRecordChips(): int
    {
        return max(1, (int) $this->config('ai.attached_actions.max_record_chips', 5));
    }

    private function maxNextSteps(): int
    {
        return max(0, (int) $this->config('ai.attached_actions.max_next_steps', 3));
    }

    /** How many suggested follow-up questions one answer carries at most (0 = none). */
    private function maxFollowUps(): int
    {
        return max(0, (int) $this->config('ai.attached_actions.max_follow_ups', 3));
    }

    /** Attached actions on, follow-up chips on, and this source of them on. */
    private function followUpsEnabled(string $source): bool
    {
        return $this->enabled()
            && (bool) $this->config('ai.attached_actions.follow_ups.enabled', true)
            && (bool) $this->config('ai.attached_actions.follow_ups.' . $source, true);
    }

    /** A text reduced to its letters and digits, case-folded: the identity of a question. */
    private static function comparable(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text, 'UTF-8')));
    }

    /** @param array<string, scalar> $replace */
    private function trans(string $key, array $replace, string $fallback, ?string $locale = null): string
    {
        try {
            $line = function_exists('trans') ? trans($key, $replace, $locale) : $key;
        } catch (\Throwable $e) {
            $line = $key;
        }

        return is_string($line) && $line !== $key ? $line : $fallback;
    }

    private function config(string $key, mixed $default = null): mixed
    {
        try {
            return function_exists('config') ? config($key, $default) : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private static function normalize(string $name): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $name));
    }
}
