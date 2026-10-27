<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Kompo\Modals;

use Condoedge\Ai\Kompo\MessagesQuery;
use Condoedge\Ai\Kompo\Modals\FeedbackCommentModal;
use Condoedge\Ai\Kompo\Support\ConversationActions;
use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Models\AiMessage;
use Condoedge\Ai\Tests\Fixtures\AdminTestUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

/**
 * The optional comment a user adds after a 👎: opened by the not-helpful button
 * (MessagesQuery::feedbackComment), pre-filled with the comment already given, saved
 * through ConversationActions (owner-scoped, negative answers only).
 */
class FeedbackCommentModalTest extends TestCase
{
    private AdminTestUser $owner;
    private AdminTestUser $intruder;

    protected function getPackageProviders($app): array
    {
        return [\Kompo\KompoServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(__DIR__ . '/../../../../vendor/condoedge/utils/src/Helpers/elements/*.php') ?: [] as $file) {
            require_once $file;
        }
        $this->app['translator']->addJsonPath(__DIR__ . '/../../../../resources/lang');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        $this->owner = AdminTestUser::create(['id' => 1, 'name' => 'Owner']);
        $this->intruder = AdminTestUser::create(['id' => 2, 'name' => 'Intruder']);
    }

    /** @return array{0: AiConversation, 1: AiMessage} */
    private function rated(string $feedback = 'negative', ?string $comment = null): array
    {
        $conversation = AiConversation::create(['user_id' => $this->owner->id]);
        $message = $conversation->messages()->create(['role' => 'assistant', 'content' => 'answer']);
        $actions = new ConversationActions();
        $actions->recordFeedback($conversation->id, $message->id, $feedback, $this->owner);
        if ($comment !== null) {
            $actions->recordFeedbackComment($conversation->id, $message->id, $comment, $this->owner);
        }

        return [$conversation, $message];
    }

    private function modal(AiConversation $conversation, AiMessage $message): FeedbackCommentModal
    {
        return new FeedbackCommentModal(null, ['conversation_id' => $conversation->id, 'message_id' => $message->id]);
    }

    /** @test */
    public function the_not_helpful_button_opens_the_modal_for_the_answer_of_the_open_conversation(): void
    {
        [$conversation, $message] = $this->rated();
        $this->actingAs($this->owner);

        $modal = (new FeedbackPanel($conversation))->feedbackComment($message->id);

        $this->assertInstanceOf(FeedbackCommentModal::class, $modal);
        $this->assertSame($conversation->id, $modal->store('conversation_id'));
        $this->assertSame($message->id, $modal->store('message_id'));
    }

    /** @test */
    public function the_modal_is_pre_filled_with_the_comment_already_given(): void
    {
        [$conversation, $message] = $this->rated('negative', 'The path is wrong.');
        $this->actingAs($this->owner);

        $json = (string) json_encode($this->modal($conversation, $message)->bootForDisplay(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString('The path is wrong.', $json);
        $this->assertStringContainsString('"comment"', $json, 'the textarea');
    }

    /** @test */
    public function saving_stores_the_comment_for_the_owner_only(): void
    {
        [$conversation, $message] = $this->rated();

        $this->actingAs($this->intruder);
        request()->merge(['comment' => 'Injected']);
        $intruderModal = $this->modal($conversation, $message);
        $intruderModal->bootForAction();
        $intruderModal->saveComment();
        $this->assertArrayNotHasKey('feedback_comment', $message->fresh()->metadata);

        $this->actingAs($this->owner);
        request()->merge(['comment' => 'Not what I asked.']);
        $modal = $this->modal($conversation, $message);
        $modal->bootForAction();
        $modal->saveComment();
        $this->assertSame('Not what I asked.', $message->fresh()->metadata['feedback_comment']);
    }

    /** @test */
    public function the_lines_exist_in_english_and_french(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../../src/Kompo/Modals/FeedbackCommentModal.php');
        preg_match_all("/'(ai\\.feedback\\.[a-z0-9_.-]+)'/", $source, $m);
        $this->assertNotEmpty($m[1]);

        foreach (['en', 'fr'] as $locale) {
            $lines = json_decode((string) file_get_contents(__DIR__ . '/../../../../resources/lang/' . $locale . '.json'), true);
            foreach (array_unique($m[1]) as $key) {
                $this->assertNotEmpty($lines[$key] ?? null, "{$locale}: {$key}");
            }
        }
    }
}

/** MessagesQuery on a known conversation, without the Kompo boot. */
class FeedbackPanel extends MessagesQuery
{
    public function __construct($conversation)
    {
        $this->conversation = $conversation;
    }
}
