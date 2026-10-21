<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Fixtures;

use Carbon\Carbon;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A small, fully known corpus for the conversation-insights admin page tests.
 *
 * "Now" is frozen at 2026-10-21 12:00. In the default 30-day range:
 *   A  Alice (team via her current team 10 "Les Jardins")  q1 a1(negative, 1000 ms, 100 tok / 2 calls, data)
 *                                                           q2 a2(positive, 3000 ms, 300 tok / 3 calls, knowledge_query)
 *   B  Bob (conversation team 20 "Tour B")                  q3 a3(metadata.error, no trace, execution_time_ms 2000)
 *                                                           q4 a4(branch error, 500 ms, 50 tok / 1 call, 1 stage error)
 *   F  Carol (team via her current team 10)                 q8(<script>) a8(<img onerror>, 4000 ms, 400 tok / 4 calls, digest, 2 files)
 * Outside it / excluded:
 *   C  Carol, 60 days ago                                   q5 a5(positive, 700 ms)
 *   D  Alice, an EVAL conversation (metadata.eval = true)   q6 a6(negative)
 *   E  Bob, SOFT-DELETED                                    q7 a7(negative)
 */
trait AdminInsightsSeed
{
    /** @var array<string, AiConversation> */
    protected array $conversations = [];

    /** @var array<string, AiMessage> */
    protected array $messages = [];

    protected function createHostTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->unsignedBigInteger('current_team_id')->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    protected function seedInsights(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00'));

        \DB::table('teams')->insert([
            ['id' => 10, 'name' => 'Les Jardins'],
            ['id' => 20, 'name' => 'Tour B'],
        ]);
        \DB::table('users')->insert([
            ['id' => 1, 'name' => 'Alice Martin', 'email' => 'alice@example.com', 'current_team_id' => 10],
            ['id' => 2, 'name' => 'Bob Tremblay', 'email' => 'bob@example.com', 'current_team_id' => 20],
            ['id' => 3, 'name' => 'Carol Roy', 'email' => 'carol@example.com', 'current_team_id' => 10],
        ]);

        $now = Carbon::now();

        // A — Alice, no team on the conversation (falls back to her current team).
        $this->conversations['A'] = $this->conversation(1, null, 'Units question', $now->copy()->subDays(2));
        $this->messages['q1'] = $this->message('A', 'user', 'How many units?', $now->copy()->subDays(2));
        $this->messages['a1'] = $this->message('A', 'assistant', 'There are 12 units.', $now->copy()->subDays(2)->addMinute(), [
            'feedback' => 'negative',
            'trace' => $this->trace(1000, 100, 2, 'data'),
        ]);
        $this->messages['q2'] = $this->message('A', 'user', 'And owners?', $now->copy()->subDays(2)->addMinutes(2));
        $this->messages['a2'] = $this->message('A', 'assistant', 'Owners are listed in the registry.', $now->copy()->subDays(2)->addMinutes(3), [
            'feedback' => 'positive',
            'trace' => $this->trace(3000, 300, 3, 'knowledge_query'),
        ]);

        // B — Bob, team on the conversation.
        $this->conversations['B'] = $this->conversation(2, 20, 'Bills', $now->copy()->subDays(5));
        $this->messages['q3'] = $this->message('B', 'user', 'Show my bills', $now->copy()->subDays(5));
        $this->messages['a3'] = $this->message('B', 'assistant', 'I encountered an error.', $now->copy()->subDays(5)->addMinute(), [
            'error' => true,
        ], 2000);
        $this->messages['q4'] = $this->message('B', 'user', 'retry please', $now->copy()->subDays(5)->addMinutes(2));
        $this->messages['a4'] = $this->message('B', 'assistant', 'Something went wrong.', $now->copy()->subDays(5)->addMinutes(3), [
            'trace' => $this->trace(500, 50, 1, 'error', [
                ['stage' => 'execution', 'class' => 'RuntimeException', 'message' => 'boom'],
            ]),
        ]);

        // C — old (outside 30 days, inside 90).
        $this->conversations['C'] = $this->conversation(3, null, 'Old one', $now->copy()->subDays(60));
        $this->messages['q5'] = $this->message('C', 'user', 'Old question', $now->copy()->subDays(60));
        $this->messages['a5'] = $this->message('C', 'assistant', 'Old answer', $now->copy()->subDays(60)->addMinute(), [
            'feedback' => 'positive',
            'trace' => $this->trace(700, 70, 1, 'data'),
        ]);

        // D — eval run: never counted.
        $this->conversations['D'] = $this->conversation(1, null, 'Eval', $now->copy()->subDays(1), ['eval' => true]);
        $this->messages['q6'] = $this->message('D', 'user', 'Eval question', $now->copy()->subDays(1));
        $this->messages['a6'] = $this->message('D', 'assistant', 'Eval answer', $now->copy()->subDays(1)->addMinute(), [
            'feedback' => 'negative',
            'trace' => $this->trace(9000, 900, 9, 'data'),
        ]);

        // E — soft-deleted by its owner: never counted.
        $this->conversations['E'] = $this->conversation(2, 20, 'Deleted', $now->copy()->subDays(1));
        $this->messages['q7'] = $this->message('E', 'user', 'Deleted question', $now->copy()->subDays(1));
        $this->messages['a7'] = $this->message('E', 'assistant', 'Deleted answer', $now->copy()->subDays(1)->addMinute(), [
            'feedback' => 'negative',
            'trace' => $this->trace(8000, 800, 8, 'data'),
        ]);
        $this->conversations['E']->delete();

        // F — hostile content, no feedback.
        $this->conversations['F'] = $this->conversation(3, null, 'Digest <b>bold</b>', $now->copy()->subDay());
        $this->messages['q8'] = $this->message('F', 'user', '<script>alert(1)</script> hello', $now->copy()->subDay());
        $this->messages['a8'] = $this->message('F', 'assistant', '<img src=x onerror=alert(2)> digest', $now->copy()->subDay()->addMinute(), [
            'trace' => $this->trace(4000, 400, 4, 'digest'),
            'referenced_files' => [
                ['id' => 7, 'name' => 'minutes-2026.pdf', 'passage' => 'secret passage text'],
                ['id' => 'physical:docs/rules.md', 'name' => 'rules.md'],
            ],
        ]);
    }

    protected function conversation(int $userId, ?int $teamId, string $title, Carbon $at, ?array $metadata = null): AiConversation
    {
        $conversation = AiConversation::create([
            'user_id' => $userId,
            'team_id' => $teamId,
            'title' => $title,
            'metadata' => $metadata,
        ]);
        $conversation->forceFill(['created_at' => $at, 'updated_at' => $at, 'last_message_at' => $at])->save();

        return $conversation;
    }

    protected function message(string $conv, string $role, string $content, Carbon $at, ?array $metadata = null, ?int $executionMs = null): AiMessage
    {
        $message = new AiMessage();
        $message->forceFill([
            'conversation_id' => $this->conversations[$conv]->id,
            'role' => $role,
            'content' => $content,
            'metadata' => $metadata,
            'execution_time_ms' => $executionMs,
            'created_at' => $at,
            'updated_at' => $at,
        ])->save();

        $this->conversations[$conv]->forceFill(['last_message_at' => $at])->save();

        return $message;
    }

    protected function trace(float $totalMs, int $tokens, int $calls, string $branch, array $errors = []): array
    {
        return [
            'version' => 1,
            'total_ms' => $totalMs,
            'stages' => [
                ['stage' => 'security_gate', 'ms' => 0.1, 'data' => ['passed' => true]],
                ['stage' => 'understanding', 'ms' => 10.0, 'data' => ['qu' => ['question_type' => 'data_query']]],
                ['stage' => 'composition', 'ms' => 20.0, 'data' => ['branch' => $branch, 'status' => null]],
                ['stage' => 'pipeline', 'ms' => $totalMs - 5],
            ],
            'errors' => $errors,
            'usage' => [
                'total' => ['input' => $tokens - 10, 'output' => 10, 'total' => $tokens, 'calls' => $calls],
                'by_stage' => ['understanding' => ['input' => $tokens - 10, 'output' => 10, 'total' => $tokens, 'calls' => $calls]],
                'models' => ['gpt-4o-mini'],
            ],
        ];
    }
}
