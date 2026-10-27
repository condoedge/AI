<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Admin;

use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Services\Admin\EvalCase;
use PHPUnit\Framework\TestCase;

/**
 * "Copy as eval case": one conversation in the eval suite shape
 * ({"conversations":[{"id","tags","turns":[{"ask","expect"}]}]}), its turns the user's
 * questions, each expect a skeleton of what the answer's trace knows. Anonymised: no user.
 */
class EvalCaseTest extends TestCase
{
    private function message(int $id, string $role, string $content, ?array $metadata = null): AiMessage
    {
        $message = new AiMessage();
        $message->forceFill(['id' => $id, 'role' => $role, 'content' => $content, 'metadata' => $metadata]);

        return $message;
    }

    private function answer(int $id, string $branch, array $fileIds, ?array $audience): AiMessage
    {
        return $this->message($id, 'assistant', 'answer ' . $id, array_filter([
            'audience' => $audience,
            'trace' => ['stages' => [
                ['stage' => 'security_gate', 'ms' => 0.1, 'data' => ['passed' => true]],
                ['stage' => 'file_context', 'ms' => 5, 'data' => ['file_count' => count($fileIds), 'files' => array_map(
                    fn ($id) => ['id' => $id, 'source' => 'physical', 'audience' => ['organisateur']],
                    $fileIds,
                )]],
                ['stage' => 'composition', 'ms' => 9, 'data' => ['branch' => $branch]],
            ]],
            'feedback' => 'negative',
            'feedback_comment' => 'Wrong page',
        ], fn ($v) => $v !== null));
    }

    /** @return array<string, mixed> */
    private function build(array $messages): array
    {
        $conversation = (object) [
            'uuid' => '0f8a1b2c-3d4e-5f60-7182-93a4b5c6d7e8',
            'title' => 'Billets',
            'user_name' => 'Alice Martin',
            'team_name' => 'Les Jardins',
        ];

        $json = EvalCase::fromTranscript($conversation, collect($messages));
        $case = json_decode($json, true);
        $this->assertIsArray($case, 'valid JSON');
        $this->assertStringNotContainsString('Alice', $json);
        $this->assertStringNotContainsString('Jardins', $json);
        $this->assertStringNotContainsString('Wrong page', $json, 'feedback is not part of the case');

        return $case;
    }

    /** @test */
    public function the_users_questions_become_the_turns_with_an_expect_skeleton_from_each_answer_trace(): void
    {
        $case = $this->build([
            $this->message(1, 'user', '  Comment je vends des billets?  '),
            $this->answer(2, 'knowledge_query', [
                'physical:resources/ai-help/aide/organisateur/billets-creer.md',
                'physical:resources/ai-help/aide/organisateur/billets-creer.md',
                'physical:resources/ai-help/aide/organisateur/salle-plan.md',
            ], ['key' => 'organisateur', 'role' => 'owner']),
            $this->message(3, 'user', 'Et pour un souper?'),
            $this->answer(4, 'conversational', [], ['key' => 'organisateur', 'role' => 'owner']),
        ]);

        $this->assertSame(['conversations'], array_keys($case));
        $this->assertCount(1, $case['conversations']);
        $conversation = $case['conversations'][0];
        $this->assertSame('promoted-0f8a1b2c', $conversation['id']);
        $this->assertSame(['promoted', 'aud:organisateur'], $conversation['tags']);
        $this->assertArrayNotHasKey('user', $conversation, 'whoever promotes picks a local test user');

        $this->assertSame([
            [
                'ask' => 'Comment je vends des billets?',
                'expect' => [
                    'branch_in' => ['knowledge_query'],
                    'retrieves_doc' => ['billets-creer', 'salle-plan'],
                    'audience_is' => 'organisateur',
                ],
            ],
            [
                'ask' => 'Et pour un souper?',
                'expect' => [
                    'branch_in' => ['conversational'],
                    'audience_is' => 'organisateur',
                ],
            ],
        ], $conversation['turns']);
    }

    /** @test */
    public function an_unresolved_audience_is_expected_as_null_and_an_unstamped_or_unanswered_turn_has_no_audience_gate(): void
    {
        $case = $this->build([
            $this->message(1, 'user', 'Hello'),
            $this->answer(2, 'conversational', [], ['key' => null, 'role' => null]),
            $this->message(3, 'user', 'Again'),
            $this->answer(4, 'conversational', [], null),
            $this->message(5, 'user', 'No answer yet'),
        ]);

        $conversation = $case['conversations'][0];
        $this->assertSame(['promoted'], $conversation['tags']);
        $this->assertArrayHasKey('audience_is', $conversation['turns'][0]['expect']);
        $this->assertNull($conversation['turns'][0]['expect']['audience_is']);
        $this->assertSame(['branch_in' => ['conversational']], $conversation['turns'][1]['expect']);
        $this->assertSame([], $conversation['turns'][2]['expect']);
    }
}
