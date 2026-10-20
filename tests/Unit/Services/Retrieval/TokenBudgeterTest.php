<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Retrieval;

use Condoedge\Ai\Services\Retrieval\TokenBudgeter;
use Condoedge\Ai\Tests\TestCase;

class TokenBudgeterTest extends TestCase
{
    /** @test */
    public function estimate_tokens_is_zero_for_empty_string(): void
    {
        $this->assertSame(0, (new TokenBudgeter())->estimateTokens(''));
    }

    /** @test */
    public function estimate_tokens_uses_four_chars_per_token(): void
    {
        // 8 chars / 4 = 2 tokens (plain text, no JSON uplift)
        $this->assertSame(2, (new TokenBudgeter())->estimateTokens('abcdefgh'));
    }

    /** @test */
    public function estimate_tokens_adds_overhead_for_json_like_text(): void
    {
        $plain = (new TokenBudgeter())->estimateTokens('aaaaaaaa'); // 8 chars
        $json = (new TokenBudgeter())->estimateTokens('{"aaaa":1}'); // starts with {
        $this->assertGreaterThan($plain, $json);
    }


}
