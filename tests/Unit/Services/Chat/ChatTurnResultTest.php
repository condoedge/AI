<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Chat;

use Condoedge\Ai\Services\Chat\Results\ChatTurnResult;
use PHPUnit\Framework\TestCase;

class ChatTurnResultTest extends TestCase
{
    /** @test */
    public function a_successful_turn_is_not_failed(): void
    {
        $result = new ChatTurnResult(
            answer: 'You have 3 parking spaces.',
            data: [['id' => 1]],
            sources: [],
        );

        $this->assertFalse($result->failed());
        $this->assertNull($result->error);
        $this->assertSame('You have 3 parking spaces.', $result->answer);
        $this->assertFalse($result->degraded);
    }

    /** @test */
    public function failure_factory_marks_the_turn_failed_and_carries_the_error(): void
    {
        $result = ChatTurnResult::failure('ai.chat.error-connection');

        $this->assertTrue($result->failed());
        $this->assertSame('ai.chat.error-connection', $result->error);
        // answer mirrors the error so a naive renderer still shows something useful
        $this->assertSame('ai.chat.error-connection', $result->answer);
    }

    /** @test */
    public function a_degraded_turn_is_a_success_answered_from_fallback(): void
    {
        $result = new ChatTurnResult(answer: 'I could not find a governing document.', degraded: true);

        $this->assertFalse($result->failed());
        $this->assertTrue($result->degraded);
    }
}
