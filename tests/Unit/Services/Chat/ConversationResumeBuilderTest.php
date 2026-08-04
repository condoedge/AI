<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Chat;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\ConversationResumeBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Orchestra\Testbench\TestCase;

/**
 * The clipboard mini resume the conversation-limit banner copies (ConversationResumeBuilder).
 *
 * Pinned contracts:
 *  - Deterministic, LLM-free serialization of the context snapshot + recent user questions.
 *  - Row COUNT + COLUMN NAMES only — row VALUES never leak into the resume.
 *  - Sections render only when their source is non-empty (empty conversation => header only).
 *  - Labels localize through the ai.chat.resume-* JSON keys (en + fr).
 *  - Output is stable: no randomness, no time source beyond created_at.
 */
class ConversationResumeBuilderTest extends TestCase
{
    use RefreshDatabase;

    private ConversationResumeBuilder $builder;

    /**
     * Don't load the full AI service provider to avoid API key requirements — the JSON
     * translations it would register are added directly in setUp instead.
     */
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations');
    }

    public function setUp(): void
    {
        parent::setUp();

        // The package lang files, exactly as AiServiceProvider::boot registers them.
        $this->app['translation.loader']->addJsonPath(__DIR__ . '/../../../../resources/lang');
        $this->app->setLocale('en');

        $this->builder = new ConversationResumeBuilder();
    }

    public function tearDown(): void
    {
        Carbon::setTestNow();
        $this->app->setLocale('en');
        parent::tearDown();
    }

    private function richConversation(): AiConversation
    {
        $conversation = AiConversation::create([
            'user_id' => 1,
            'context_snapshot' => [
                'last_subject' => 'active members',
                'focused_entity' => 'Member',
                'last_result_count' => 42,
                'last_result_sample' => [
                    ['name' => 'Alice Smith', 'email' => 'alice@example.com', 'role' => 'admin'],
                    ['name' => 'Bob Jones', 'email' => 'bob@example.com', 'role' => 'member'],
                ],
                'last_answer_summary' => 'There are 42 active members across 3 teams.',
            ],
        ]);

        // 6 user questions (interleaved with assistant answers) — the resume must carry only
        // the LAST 5, in the order the user asked them.
        foreach (['Q1 oldest', 'Q2', 'Q3', 'Q4', 'Q5', 'Q6 newest'] as $i => $question) {
            $conversation->messages()->create(['role' => 'user', 'content' => $question]);
            $conversation->messages()->create(['role' => 'assistant', 'content' => 'Answer ' . $i]);
        }

        return $conversation;
    }

    /** @test */
    public function empty_conversation_builds_a_minimal_resume_of_just_the_header(): void
    {
        $conversation = AiConversation::create(['user_id' => 1]);

        $resume = $this->builder->build($conversation);

        $this->assertSame(
            __('ai.chat.resume-header', ['date' => $conversation->created_at->format('Y-m-d')]),
            $resume,
            'An empty conversation must serialize to the header line only — no empty sections.'
        );
        $this->assertStringNotContainsString('Subject:', $resume);
        $this->assertStringNotContainsString('columns', $resume);
        $this->assertStringNotContainsString('Recent questions', $resume);
    }

    /** @test */
    public function rich_snapshot_serializes_subject_count_columns_answer_and_recent_questions_in_order(): void
    {
        $resume = $this->builder->build($this->richConversation());
        $lines = explode("\n", $resume);

        $this->assertStringContainsString('Resume of a previous conversation', $lines[0]);
        $this->assertSame('Subject: active members', $lines[1]);
        $this->assertSame(
            'Last shown result: 42 rows about active members; columns: name, email, role',
            $lines[2]
        );
        $this->assertSame('Last answer: There are 42 active members across 3 teams.', $lines[3]);
        $this->assertSame('Recent questions:', $lines[4]);

        // Last 5 user questions, oldest first — Q1 dropped, user order preserved.
        $this->assertSame(['- Q2', '- Q3', '- Q4', '- Q5', '- Q6 newest'], array_slice($lines, 5));
        $this->assertStringNotContainsString('Q1 oldest', $resume);

        // Assistant replies never appear as questions.
        $this->assertStringNotContainsString('Answer', $resume);
    }

    /** @test */
    public function row_values_never_appear_in_the_resume_only_column_names(): void
    {
        $resume = $this->builder->build($this->richConversation());

        $this->assertStringContainsString('name, email, role', $resume);
        $this->assertStringNotContainsString('Alice', $resume);
        $this->assertStringNotContainsString('alice@example.com', $resume);
        $this->assertStringNotContainsString('Bob', $resume);
        $this->assertStringNotContainsString('admin', $resume);
    }

    /** @test */
    public function a_scalar_context_snapshot_degrades_to_the_header_only_instead_of_throwing(): void
    {
        // The 'array' cast happily round-trips a scalar (json_encode('garbage') is valid
        // JSON), so a corrupted row must degrade gracefully — the banner renders the resume
        // at request time, so a TypeError here would break the whole messages panel.
        $conversation = AiConversation::create(['user_id' => 1]);
        $conversation->context_snapshot = 'garbage-scalar';
        $conversation->save();

        $resume = $this->builder->build($conversation->fresh());

        $this->assertSame(
            __('ai.chat.resume-header', ['date' => $conversation->created_at->format('Y-m-d')]),
            $resume,
            'A non-array snapshot must be treated as empty, not fatal.'
        );
    }

    /** @test */
    public function subject_falls_back_to_focused_entity_when_last_subject_is_absent(): void
    {
        $conversation = AiConversation::create([
            'user_id' => 1,
            'context_snapshot' => ['focused_entity' => 'Invoice'],
        ]);

        $resume = $this->builder->build($conversation);

        $this->assertStringContainsString('Subject: Invoice', $resume);
    }

    /** @test */
    public function result_line_uses_the_generic_variant_when_no_subject_exists(): void
    {
        $conversation = AiConversation::create([
            'user_id' => 1,
            'context_snapshot' => [
                'last_result_count' => 3,
                'last_result_sample' => [['total' => 100, 'status' => 'paid']],
            ],
        ]);

        $resume = $this->builder->build($conversation);

        $this->assertStringContainsString('Last shown result: 3 rows; columns: total, status', $resume);
        $this->assertStringNotContainsString('about', $resume);
        $this->assertStringNotContainsString('Subject:', $resume);
        // Values stay out on this path too.
        $this->assertStringNotContainsString('100', $resume);
        $this->assertStringNotContainsString('paid', $resume);
    }

    /** @test */
    public function a_single_row_result_uses_singular_wording(): void
    {
        $conversation = AiConversation::create([
            'user_id' => 1,
            'context_snapshot' => [
                'last_subject' => 'unit 101',
                'last_result_count' => 1,
                'last_result_sample' => [['balance' => 250]],
            ],
        ]);

        $resume = $this->builder->build($conversation);

        $this->assertStringContainsString(
            'Last shown result: 1 row about unit 101; columns: balance',
            $resume
        );
        $this->assertStringNotContainsString('1 rows', $resume);
        // Values still never leak on the singular path.
        $this->assertStringNotContainsString('250', $resume);
    }

    /** @test */
    public function a_single_row_result_uses_singular_wording_in_french_on_the_generic_path(): void
    {
        $this->app->setLocale('fr');

        // No subject -> generic variant; count 1 -> singular "ligne".
        $conversation = AiConversation::create([
            'user_id' => 1,
            'context_snapshot' => [
                'last_result_count' => 1,
                'last_result_sample' => [['solde' => 250]],
            ],
        ]);

        $resume = $this->builder->build($conversation);

        $this->assertStringContainsString('Dernier resultat affiche: 1 ligne; colonnes: solde', $resume);
        $this->assertStringNotContainsString('1 lignes', $resume);
    }

    /** @test */
    public function result_line_is_omitted_when_the_count_is_zero_or_the_sample_is_empty(): void
    {
        $conversation = AiConversation::create([
            'user_id' => 1,
            'context_snapshot' => [
                'last_subject' => 'members',
                'last_result_count' => 0,
                'last_result_sample' => [],
            ],
        ]);

        $resume = $this->builder->build($conversation);

        $this->assertStringNotContainsString('columns', $resume);
        $this->assertStringNotContainsString('rows', $resume);
    }

    /** @test */
    public function fr_locale_localizes_the_labels(): void
    {
        $this->app->setLocale('fr');

        $resume = $this->builder->build($this->richConversation());

        $this->assertStringContainsString('Resume d\'une conversation precedente', $resume);
        $this->assertStringContainsString('Sujet: active members', $resume);
        $this->assertStringContainsString(
            'Dernier resultat affiche: 42 lignes sur active members; colonnes: name, email, role',
            $resume
        );
        $this->assertStringContainsString('Derniere reponse:', $resume);
        $this->assertStringContainsString('Questions recentes:', $resume);

        // English labels must not leak through.
        $this->assertStringNotContainsString('Subject:', $resume);
        $this->assertStringNotContainsString('Recent questions:', $resume);
    }

    /** @test */
    public function output_is_stable_across_calls_and_uses_no_time_source_beyond_created_at(): void
    {
        $conversation = $this->richConversation();

        $first = $this->builder->build($conversation);
        // Jump the application clock a full day: ANY now()-derived content — ISO timestamps,
        // date-only formats, anything Carbon — must differ between the two builds and fail
        // assertSame. (A real sleep can't pin this: second-granularity formats survive
        // sub-second sleeps.) created_at is stored, so it is unaffected by the jump.
        Carbon::setTestNow(now()->addDay());
        $second = $this->builder->build($conversation->fresh());

        $this->assertSame($first, $second);
        $this->assertStringContainsString($conversation->created_at->format('Y-m-d'), $first);
    }
}
