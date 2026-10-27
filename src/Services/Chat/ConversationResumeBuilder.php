<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat;

use Condoedge\Ai\Models\AiConversation;

/**
 * Builds the plain-text "mini resume" of a conversation that the limit banner copies to the
 * clipboard, so the user can paste it as the FIRST message of a fresh conversation and carry
 * their working context across.
 *
 * Deliberately deterministic and cheap: NO LLM call, no kompo/kit dependency, no semantic
 * word lists — this is a structural serialization of state the pipeline's LLM already
 * extracted turn-by-turn into the context snapshot (last_subject, last_result_count,
 * last_result_sample, last_answer_summary) plus the user's own recent questions verbatim.
 *
 * Privacy/size contract: for the last shown result only the row COUNT and the COLUMN NAMES
 * are serialized — never row values. The new conversation re-queries live data anyway, and
 * keeping values out keeps the resume mini and paste-safe.
 *
 * Each section renders only when its source is non-empty; an empty conversation yields just
 * the header line. Labels are localized via the ai.chat.resume-* JSON keys (en + fr).
 */
class ConversationResumeBuilder
{
    /** How many of the user's most recent questions the resume carries. */
    public const RECENT_QUESTIONS = 5;

    public function build(AiConversation $conversation): string
    {
        // The 'array' cast round-trips ANY valid JSON — including scalars — so a corrupted
        // row must degrade to "no snapshot", not a TypeError (the banner builds the resume
        // at render time, so throwing here would take down the whole messages panel).
        $snapshot = is_array($conversation->context_snapshot) ? $conversation->context_snapshot : [];

        $lines = [
            __('ai.chat.resume-header', [
                // created_at is the only time source — keeps the output stable for a given
                // conversation (asserted by tests).
                'date' => $conversation->created_at?->format('Y-m-d') ?? '',
            ]),
        ];

        $subject = $this->subject($snapshot);
        if ($subject !== null) {
            $lines[] = __('ai.chat.resume-subject', ['subject' => $subject]);
        }

        $resultLine = $this->lastResultLine($snapshot, $subject);
        if ($resultLine !== null) {
            $lines[] = $resultLine;
        }

        $answer = $snapshot['last_answer_summary'] ?? null;
        if (is_string($answer) && trim($answer) !== '') {
            $lines[] = __('ai.chat.resume-last-answer', ['answer' => trim($answer)]);
        }

        $questions = $this->recentUserQuestions($conversation);
        if (!empty($questions)) {
            $lines[] = __('ai.chat.resume-recent-questions');
            foreach ($questions as $question) {
                $lines[] = '- ' . $question;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The conversation's working subject: the snapshot's last_subject (set by row-producing
     * turns), falling back to the focused entity type.
     */
    protected function subject(array $snapshot): ?string
    {
        foreach (['last_subject', 'focused_entity'] as $key) {
            $value = $snapshot[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * "N rows about {subject}; columns: a, b, c" — count + column names only, NEVER values.
     * Null when the conversation has no recorded result (count 0 / no sample columns).
     */
    protected function lastResultLine(array $snapshot, ?string $subject): ?string
    {
        $count = $snapshot['last_result_count'] ?? 0;
        $sample = $snapshot['last_result_sample'] ?? [];

        if (!is_int($count) || $count <= 0 || !is_array($sample) || empty($sample)) {
            return null;
        }

        $firstRow = reset($sample);
        if (!is_array($firstRow) || empty($firstRow)) {
            return null;
        }

        $columns = implode(', ', array_map('strval', array_keys($firstRow)));

        // trans_choice, not __(): single-row results are common ("account 101's balance") and
        // the keys carry {1}/[2,*] pipe variants so EN/FR both stay grammatical.
        if ($subject !== null) {
            return trans_choice('ai.chat.resume-last-result', $count, [
                'subject' => $subject,
                'columns' => $columns,
            ]);
        }

        return trans_choice('ai.chat.resume-last-result-generic', $count, [
            'columns' => $columns,
        ]);
    }

    /**
     * The last RECENT_QUESTIONS user messages, verbatim, oldest first (the order the user
     * asked them). Ties on created_at (same-second inserts) break by id to stay deterministic.
     *
     * @return array<int,string>
     */
    protected function recentUserQuestions(AiConversation $conversation): array
    {
        return $conversation->messages()
            ->where('role', 'user')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit(self::RECENT_QUESTIONS)
            ->get()
            ->reverse()
            ->values()
            ->pluck('content')
            ->filter(fn ($content) => is_string($content) && trim($content) !== '')
            ->map(fn ($content) => trim($content))
            ->all();
    }
}
