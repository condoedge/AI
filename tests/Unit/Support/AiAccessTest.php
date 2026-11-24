<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Support;

use Condoedge\Ai\Support\AiAccess;
use Condoedge\Ai\Tests\TestCase;

class AiAccessTest extends TestCase
{
    /** @test */
    public function without_a_rule_the_assistant_is_open_to_everyone(): void
    {
        config()->set('ai.access.authorize', null);

        $this->assertTrue(AiAccess::allows(null));
        $this->assertTrue(AiAccess::allows((object) ['id' => 1]));
    }

    /** @test */
    public function the_hosts_rule_decides_for_each_asker(): void
    {
        config()->set('ai.access.authorize', fn (mixed $user): bool => ($user->team ?? null) === 'accepted');

        $this->assertTrue(AiAccess::allows((object) ['team' => 'accepted']));
        $this->assertFalse(AiAccess::allows((object) ['team' => 'not yet']));
        $this->assertFalse(AiAccess::allows(null));
    }

    /** @test */
    public function a_rule_that_fails_or_answers_anything_but_true_closes_the_assistant(): void
    {
        config()->set('ai.access.authorize', fn (mixed $user) => throw new \RuntimeException('down'));
        $this->assertFalse(AiAccess::allows((object) ['id' => 1]));

        config()->set('ai.access.authorize', fn (mixed $user) => 1);
        $this->assertFalse(AiAccess::allows((object) ['id' => 1]));
    }

    /** @test */
    public function a_record_of_a_closed_account_is_not_read(): void
    {
        $this->assertTrue(AiAccess::allowsRecord((object) ['team_id' => 1]), 'no rule: every record');

        config()->set('ai.access.authorize_record', fn (mixed $file): bool => $file->team_id === 1);

        $this->assertTrue(AiAccess::allowsRecord((object) ['team_id' => 1]));
        $this->assertFalse(AiAccess::allowsRecord((object) ['team_id' => 2]));
    }
}
