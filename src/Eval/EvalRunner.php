<?php

namespace Condoedge\Ai\Eval;

use Condoedge\Ai\Models\AiEvalResult;
use Condoedge\Ai\Models\AiEvalRun;
use Illuminate\Database\Eloquent\Model as User;
use Condoedge\Ai\DTOs\PageContext;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Models\AiQueryLog;
use Condoedge\Ai\Models\AiTask;
use Condoedge\Ai\Models\AiTaskEvent;
use Condoedge\Ai\Services\Admin\ConversationInsights;
use Condoedge\Ai\Services\Chat\Inputs\AskQuestionInput;
use Condoedge\Ai\Services\Chat\UseCases\AskQuestionUseCase;

/**
 * Asks one suite conversation through the same use case as the chat window, as the case's user,
 * and stores each turn's answer, grade and trace. The user's team and union are only switched in
 * memory, and the conversation is deleted afterwards so test turns never reach the admin panel's
 * statistics or the user's own conversation list.
 *
 * A turn may give the page it is asked from ('page_context', as the chat window sends it). Test
 * turns never teach the assistant: no question-to-query example is stored from them. A turn the
 * model provider refused, or whose query or search index timed out, is labelled (TurnLabel) and
 * left ungraded: it says nothing about the answer. A failed turn keeps its cause (AnswerGrader::cause).
 */
class EvalRunner
{
    public function __construct(
        protected AnswerGrader $grader,
        protected AskQuestionUseCase $askQuestion,
    ) {
    }

    /** @return array{passed: int, failed: int, ungraded: int} */
    public function runCase(AiEvalRun $run, array $case, int $repetition): array
    {
        $counts = ['passed' => 0, 'failed' => 0, 'ungraded' => 0];

        $previousUser = auth()->user();
        $previousLocale = app()->getLocale();
        $user = $this->actingUser($case);
        $conversation = null;

        try {
            auth()->setUser($user);
            // The language the suites are written in (config ai.eval.locale): the answer's follows the question's.
            app()->setLocale(config('ai.eval.locale', app()->getLocale()));

            $conversation = AiConversation::create([
                'user_id' => $user->id,
                'team_id' => function_exists('currentTeamId') ? currentTeamId() : null,
                'status' => 'active',
                'title' => 'eval #'.$run->id.' '.$case['id'],
                'metadata' => ['ai_eval_run_id' => $run->id],
            ]);

            foreach (array_values($case['turns']) as $index => $turn) {
                $result = $this->runTurn($run, $case, $repetition, $index + 1, $turn, $user, $conversation);

                $counts[$result->passed === null ? 'ungraded' : ($result->passed ? 'passed' : 'failed')]++;
            }
        } finally {
            if ($conversation) {
                $this->forget($conversation);
            }

            app()->setLocale($previousLocale);
            $previousUser ? auth()->setUser($previousUser) : auth()->forgetUser();
        }

        return $counts;
    }

    protected function runTurn(AiEvalRun $run, array $case, int $repetition, int $turnNumber, array $turn, User $user, AiConversation $conversation): AiEvalResult
    {
        $result = new AiEvalResult();
        $result->ai_eval_run_id = $run->id;
        $result->case_id = $case['id'];
        $result->repetition = $repetition;
        $result->turn = $turnNumber;
        $result->role = $case['role'] ?? null;
        $result->user_id = $user->id;
        $result->question = $turn['ask'];

        $started = microtime(true);

        // The turn's own message is the one written after this id: an earlier turn's never stands in for it.
        $askedAfter = (int) AiMessage::where('conversation_id', $conversation->id)->where('role', 'user')->max('id');

        try {
            $answer = $this->askQuestion->execute(new AskQuestionInput(
                question: $turn['ask'],
                conversation: $conversation,
                user: $user,
                pageContext: !empty($turn['page_context']) ? PageContext::fromArray($turn['page_context']) : null,
                pipelineOptions: array_filter(['store_examples' => false, 'engine' => $run->engine], fn ($value) => $value !== null),
            ));

            $metadata = $answer->assistantMessage?->metadata;
            $metadata = is_array($metadata) ? $metadata : [];
            $trace = $this->turnFacts($answer, $conversation, $askedAfter, $turn['page_context'] ?? null) + $this->traceSummary($metadata);
            $grade = $this->grader->grade($answer->answer, $turn['grade'] ?? [], $trace['route'], $trace['documents'] ?? [], $trace + $this->evidence($answer, $user));

            $result->answer = $answer->answer;
            $result->passed = $grade['passed'];
            $result->checks = $grade['checks'];
            $result->trace = $trace;
            $result->error = $answer->error;
            [$result->label, $why] = TurnLabel::of($answer->error, $metadata['trace']['errors'] ?? []);
        } catch (\Throwable $e) {
            $result->passed = !empty($turn['grade']) ? false : null;
            $result->error = get_class($e).': '.mb_substr($e->getMessage(), 0, 500);
            [$result->label, $why] = TurnLabel::of($result->error);
        }

        $trace = $result->trace ?? [];

        if ($result->label) {
            $result->passed = null;
            $trace['label_reason'] = $why;
        } else {
            $trace['cause'] = $this->grader->cause($result->passed, $result->checks ?? [], (string) $result->answer, $trace['route'] ?? null, $trace, $result->error);
        }

        // What a good answer is, in words, for the reader and the auditor; and a failure already known.
        $result->trace = array_filter($trace + [
            'standard' => $turn['standard'] ?? null,
            'known_open' => $case['known_open'] ?? null,
        ], fn ($value) => $value !== null);

        $result->latency_ms = (int) round((microtime(true) - $started) * 1000);
        $result->save();

        return $result;
    }

    protected function actingUser(array $case): User
    {
        $user = EvalPanel::userModel()::findOrFail($case['user']);

        // A case may ask from another context (config ai.eval.user_overrides: case key => user
        // attribute, e.g. a team): in memory only, the real user keeps their own.
        foreach ((array) config('ai.eval.user_overrides', []) as $key => $attribute) {
            if (isset($case[$key])) {
                $user->{$attribute} = $case[$key];
            }
        }

        return $user;
    }

    protected function traceSummary(array $metadata): array
    {
        $stages = collect($metadata['trace']['stages'] ?? [])->filter(fn ($stage) => is_array($stage))->keyBy('stage');
        $understanding = $stages->get('understanding')['data'] ?? [];
        $generation = $stages->get('query_generation')['data'] ?? [];
        $execution = $stages->get('execution')['data'] ?? [];
        $files = $stages->get('file_context')['data']['files'] ?? [];

        return array_merge(ConversationInsights::traceSummary($metadata, null), [
            'question_type' => $understanding['qu']['question_type'] ?? null,
            // How the question was read: a follow-up that is not read as one cannot use the turn before.
            'understood' => array_filter(array_intersect_key((array) ($understanding['qu'] ?? []), array_flip(['intent', 'entity_types', 'search_query', 'follow_up_reference', 'export_format'])), fn ($value) => $value !== null && $value !== [] && $value !== ''),
            'audience' => is_array($metadata['audience'] ?? null) ? ($metadata['audience']['key'] ?? null) : null,
            'matched_calculations' => $understanding['matched_calculations'] ?? [],
            'calculation' => is_string($generation['calculation'] ?? null) ? $generation['calculation'] : null,
            'cypher' => $generation['cypher'] ?? null,
            'row_count' => $execution['row_count'] ?? null,
            'documents' => collect($files)->pluck('id')->unique()->values()->all(),
            'skill' => array_intersect_key($stages->get('skill')['data'] ?? [], ['selected' => 1, 'references' => 1]),
            'stages' => $stages->keys()->values()->all(),
            // Where the time went: a stage that runs more than once counts for all its runs.
            'stage_ms' => collect($metadata['trace']['stages'] ?? [])->filter(fn ($stage) => is_array($stage) && ($stage['ms'] ?? 0) >= 1)
                ->groupBy('stage')->map(fn ($runs) => (int) round($runs->sum('ms')))->all(),
        ]);
    }

    /**
     * What the turn received and produced beyond its wording: the rows of the turn before it (a
     * follow-up that lost them cannot answer), the page it was asked from, the file it made and
     * the columns the person saw. The query is the one that ran, its values unmasked.
     */
    protected function turnFacts($answer, AiConversation $conversation, int $askedAfter, ?array $pageContext): array
    {
        $asked = AiMessage::where('conversation_id', $conversation->id)->where('role', 'user')->where('id', '>', $askedAfter)->orderByDesc('id')->first();
        $received = is_array($asked?->context_used) ? $asked->context_used : [];
        $response = is_array($answer->metadata['response'] ?? null) ? $answer->metadata['response'] : [];
        $rows = array_values($answer->data);
        $export = ($response['export_intent'] ?? false) === true ? [
            'format' => (string) ($response['export_format'] ?? ''),
            'rows' => count((array) ($response['export_document_rows'] ?? [])),
        ] : null;

        return array_filter([
            'previous_rows' => count((array) ($received['last_result_sample'] ?? [])),
            'page' => $pageContext ? array_filter(array_intersect_key($pageContext, array_flip(['route', 'entity_type', 'entity_id', 'label']))) : null,
            'export' => $export,
            'columns' => $export
                ? array_values(array_map('strval', (array) ($response['export_document_headers'] ?? [])))
                : array_keys(is_array($rows[0] ?? null) ? $rows[0] : []),
            'cypher' => $answer->cypher,
            // Files cited; rows shown and the full count when more exist (the answer must say so).
            'cited' => count($answer->sources),
            'shown' => is_numeric($response['result_shown'] ?? null) ? (int) $response['result_shown'] : null,
            'total' => is_numeric($response['result_total'] ?? null) ? (int) $response['result_total'] : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * What some checks read but the result does not keep: the rows themselves, the file's cells,
     * the asker's email.
     */
    protected function evidence($answer, User $user): array
    {
        $response = is_array($answer->metadata['response'] ?? null) ? $answer->metadata['response'] : [];
        $headers = (array) ($response['export_document_headers'] ?? []);

        return [
            'sample' => array_values($answer->data),
            'export_table' => [
                'headers' => array_values(array_map('strval', $headers)),
                'rows' => array_map(fn ($row) => array_map(fn ($key) => is_array($row) ? ($row[$key] ?? null) : null, array_keys($headers)), array_values((array) ($response['export_document_rows'] ?? []))),
            ],
            'email' => $user->email,
        ];
    }

    protected function forget(AiConversation $conversation): void
    {
        $taskIds = AiTask::where('conversation_id', $conversation->id)->pluck('id');

        AiTaskEvent::whereIn('task_id', $taskIds)->delete();
        AiTask::whereIn('id', $taskIds)->delete();
        AiQueryLog::where('conversation_id', $conversation->id)->delete();
        $conversation->forceDelete();
    }
}
