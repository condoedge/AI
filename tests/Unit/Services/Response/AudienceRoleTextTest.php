<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\Digest\DigestSourceResult;
use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\DTOs\ResolvedAudience;
use Condoedge\Ai\Services\Response\ConversationalReplyComposer;
use Condoedge\Ai\Services\Response\DigestResponseComposer;
use Condoedge\Ai\Services\ResponseSections\ResponseCurrentUserContextSection;
use Condoedge\Ai\Tests\Fixtures\FixedAudienceResolver;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Mockery;

/**
 * The asker's role text (ai.audiences.map.<key>.prompt) reaches every answering prompt:
 * the composition's user section, the conversational reply and the digest. An audience
 * that is not the organisation's own staff (insider_voice false) gets no insider lines.
 */
class AudienceRoleTextTest extends TestCase
{
    private const STAFF = 'Works for the organisation running campaigns; role {role}.';
    private const BUYER = 'Buys or donates; never promise refunds, the organisation decides.';

    /** @var list<array> */
    private array $sent = [];

    public function setUp(): void
    {
        parent::setUp();
        $user = new User();
        $user->id = 42;
        $user->name = 'Jane Smith';
        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('user')->andReturn($user);
        Auth::shouldReceive('id')->andReturn(42);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function audience(?string $key, ?string $role = null): void
    {
        FixedAudienceResolver::$next = $key !== null ? new ResolvedAudience($key, $role) : null;
        config(['ai.audiences' => [
            'resolver' => FixedAudienceResolver::class,
            'map' => [
                'staff' => ['documents' => ['staff'], 'insider_voice' => true, 'prompt' => self::STAFF],
                'buyer' => ['documents' => ['buyer'], 'insider_voice' => false, 'prompt' => self::BUYER],
            ],
        ]]);
    }

    private function userSection(): string
    {
        return (new ResponseCurrentUserContextSection())->format(new PipelineContext());
    }

    private function llm(mixed $reply): LlmProviderInterface
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        foreach (['chat', 'chatJson'] as $method) {
            $llm->shouldReceive($method)->andReturnUsing(function (array $messages) use ($reply) {
                $this->sent[] = $messages;

                return $reply;
            });
        }

        return $llm;
    }

    /** @test */
    public function staff_get_their_role_and_keep_the_insider_voice(): void
    {
        $this->audience('staff', 'owner');
        $section = $this->userSection();

        $this->assertStringContainsString("THE USER'S ROLE (how to frame the answer for them):\nWorks for the organisation running campaigns; role owner.", $section);
        $this->assertStringContainsString('INSIDER VOICE', $section);
    }

    /** @test */
    public function a_buyer_gets_their_role_and_no_insider_voice(): void
    {
        $this->audience('buyer');
        $section = $this->userSection();

        $this->assertStringContainsString(self::BUYER, $section);
        $this->assertStringNotContainsString('INSIDER VOICE', $section);
        $this->assertStringNotContainsString('Answer from INSIDE', $section);
    }

    /** @test */
    public function without_audiences_the_section_is_unchanged(): void
    {
        $section = $this->userSection();

        $this->assertStringNotContainsString("THE USER'S ROLE", $section);
        $this->assertStringContainsString('INSIDER VOICE', $section);
    }

    /** @test */
    public function the_conversational_reply_and_the_digest_get_the_same_role_text(): void
    {
        $this->audience('buyer');
        $ctx = new PipelineContext();
        $ctx->question = 'Hello';
        $ctx->qu = new QueryUnderstandingResult(questionType: 'conversational', language: 'fr');

        (new ConversationalReplyComposer($this->llm(['reply' => 'Bonjour!', 'conversational' => true])))->compose('Hello', $ctx);
        (new DigestResponseComposer($this->llm('Voici.')))->compose('Quoi de neuf?', $ctx, [
            new DigestSourceResult(key: 'open_tasks', label: 'Open tasks', rows: [['id' => 1, 'title' => 'Call back']], rowCount: 1),
        ]);

        $this->assertCount(2, $this->sent);
        foreach ($this->sent as $messages) {
            $this->assertStringContainsString(self::BUYER, json_encode($messages, JSON_UNESCAPED_UNICODE));
        }
    }
}
