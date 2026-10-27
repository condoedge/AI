<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Admin;

use Carbon\Carbon;
use Condoedge\Ai\AiServiceProvider;
use Condoedge\Ai\Kompo\Admin\AdminConversationsTable;
use Condoedge\Ai\Kompo\Admin\AdminFeedbackTable;
use Condoedge\Ai\Kompo\Admin\AdminTranscriptModal;
use Condoedge\Ai\Kompo\Admin\AiConversationsAdminPage;
use Condoedge\Ai\Services\Admin\ConversationInsights;
use Condoedge\Ai\Tests\Fixtures\AdminInsightsSeed;
use Condoedge\Ai\Tests\Fixtures\AdminTestUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase;

/**
 * Privacy of the admin conversations / feedback page: the Gate ability is denied by
 * default, the route and every component boot (= every Kompo AJAX call) enforce it, and
 * the read-only transcript escapes message content.
 */
class AiConversationsAdminAccessTest extends TestCase
{
    use AdminInsightsSeed;

    private AdminTestUser $admin;
    private AdminTestUser $member;

    protected function getPackageProviders($app): array
    {
        return [
            \Kompo\KompoServiceProvider::class,
            AiServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        // The provider validates the LLM / embedding keys at boot.
        $app['config']->set('ai.llm.default', 'openai');
        $app['config']->set('ai.llm.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
        $app['config']->set('ai.embedding.default', 'openai');
        $app['config']->set('ai.embedding.openai.api_key', 'test-api-key-placeholder-for-testing-that-is-long-enough');
        $app['config']->set('auth.providers.users.model', AdminTestUser::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        // The condoedge/utils element helpers the Modal base uses (_Modal, _ModalHeader…);
        // a host loads them through the utils service provider.
        foreach (glob(__DIR__ . '/../../../vendor/condoedge/utils/src/Helpers/elements/*.php') ?: [] as $file) {
            require_once $file;
        }
        $this->createHostTables();
        $this->seedInsights();

        $this->admin = AdminTestUser::create(['id' => 50, 'name' => 'Super Admin', 'email' => 'admin@example.com']);
        $this->member = AdminTestUser::create(['id' => 51, 'name' => 'Member', 'email' => 'member@example.com']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** What a host does: grant the ability to its admins only. */
    private function grantToAdmin(): void
    {
        Gate::define(ConversationInsights::ABILITY, fn ($user) => (int) $user->id === 50);
    }

    /** @test */
    public function the_ability_is_denied_by_default(): void
    {
        $this->assertTrue(Gate::has(ConversationInsights::ABILITY));
        $this->assertFalse(Gate::forUser($this->admin)->allows(ConversationInsights::ABILITY));
        $this->assertFalse(Gate::forUser($this->member)->allows(ConversationInsights::ABILITY));
    }

    /** @test */
    public function the_route_is_registered_behind_auth_and_the_ability(): void
    {
        $route = app('router')->getRoutes()->getByName('ai.admin.conversations');

        $this->assertNotNull($route);
        $this->assertSame('ai/admin/conversations', $route->uri());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('can:viewAiConversations', $route->gatherMiddleware());
    }

    /** @test */
    public function a_guest_and_a_non_admin_are_refused_by_the_route(): void
    {
        $this->getJson('/ai/admin/conversations')->assertStatus(401);

        // Default-deny: even the future admin is refused until the host grants it.
        $this->actingAs($this->admin)->getJson('/ai/admin/conversations')->assertStatus(403);

        $this->grantToAdmin();
        $this->actingAs($this->member)->getJson('/ai/admin/conversations')->assertStatus(403);
    }

    /** @test */
    public function a_granted_admin_gets_the_page_with_the_kpis(): void
    {
        $this->grantToAdmin();

        $response = $this->actingAs($this->admin)->getJson('/ai/admin/conversations?days=30');

        $response->assertOk();
        $json = (string) $response->getContent();
        // Satisfaction 50 %, failed answers 2, tokens 850 (see AdminInsightsSeed).
        $this->assertStringContainsString('50 %', $json);
        $this->assertStringContainsString('850', $json);
    }

    /** @test */
    public function the_page_shows_kpis_per_audience_only_while_audiences_are_configured(): void
    {
        $this->grantToAdmin();

        $without = (string) $this->actingAs($this->admin)->getJson('/ai/admin/conversations?days=30')->getContent();
        $this->assertStringNotContainsString(__('ai.admin.audience.title'), $without);

        config(['ai.audiences.resolver' => 'App\AnyResolver']);
        $with = (string) $this->actingAs($this->admin)->getJson('/ai/admin/conversations?days=30')->getContent();
        $this->assertStringContainsString(__('ai.admin.audience.title'), $with);
        $this->assertStringContainsString('members', $with);
        $this->assertStringContainsString('staff', $with);
        $this->assertStringContainsString(__('ai.admin.audience.unresolved'), $with);
        $this->assertStringContainsString('1.3 s', $with, 'unresolved: average of 2 s and 0.5 s');
    }

    /** @test */
    public function the_conversations_table_filters_by_answer_kind(): void
    {
        $this->grantToAdmin();
        $this->actingAs($this->admin);

        request()->merge(['answers' => 'regenerated']);
        $table = new AdminConversationsTable(['days' => 30, 'from' => '2026-09-22', 'to' => '2026-10-21']);
        $json = (string) json_encode($table->bootForDisplay(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString('Units question', $json);
        $this->assertStringNotContainsString('Bills', $json);
        $this->assertStringContainsString(__('ai.admin.filter.answers-slow', ['seconds' => 20]), $json);
    }

    /** @return array<string, array{0: class-string}> */
    public static function components(): array
    {
        return [
            'page' => [AiConversationsAdminPage::class],
            'feedback table' => [AdminFeedbackTable::class],
            'conversations table' => [AdminConversationsTable::class],
            'transcript modal' => [AdminTranscriptModal::class],
        ];
    }

    /**
     * Kompo AJAX (filter, paginate, open the transcript) re-boots the component with
     * bootForAction(): a non-admin is refused there too (AuthorizationException → 403).
     *
     * @test
     * @dataProvider components
     */
    public function every_component_refuses_a_non_admin_on_boot(string $class): void
    {
        $this->grantToAdmin();
        $this->actingAs($this->member);

        $this->expectException(AuthorizationException::class);
        (new $class())->bootForAction();
    }

    /**
     * @test
     * @dataProvider components
     */
    public function every_component_refuses_the_browse_and_self_call_gate_for_a_non_admin(string $class): void
    {
        $this->grantToAdmin();
        $this->actingAs($this->member);

        $this->assertFalse((new $class())->authorize());
        $this->assertFalse((new $class())->authorizeBoot());
    }

    private function transcriptJson(string $conversation, ?int $message = null): string
    {
        $modal = (new AdminTranscriptModal(['conversation' => $conversation, 'message' => $message]))->bootForDisplay();

        return (string) json_encode($modal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @test */
    public function the_transcript_escapes_hostile_content(): void
    {
        $this->grantToAdmin();
        $this->actingAs($this->admin);

        $json = $this->transcriptJson($this->conversations['F']->uuid, $this->messages['a8']->id);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; hello', $json);
        $this->assertStringNotContainsString('<script>', $json);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(2)&gt; digest', $json);
        $this->assertStringNotContainsString('<img', $json);
        $this->assertStringContainsString('Digest &lt;b&gt;bold&lt;/b&gt;', $json, 'the title too');
        $this->assertStringNotContainsString('<b>bold', $json);

        // The answer's trace summary: route, usage, cited file NAMES (never the passage).
        $this->assertStringContainsString('minutes-2026.pdf', $json);
        $this->assertStringContainsString('rules.md', $json);
        $this->assertStringNotContainsString('secret passage text', $json);
        $this->assertStringContainsString('ai-admin-message-' . $this->messages['a8']->id, $json);
    }

    /** @test */
    public function the_transcript_shows_feedback_and_failed_answers_and_has_no_submit(): void
    {
        $this->grantToAdmin();
        $this->actingAs($this->admin);

        $a = $this->transcriptJson($this->conversations['A']->uuid);
        $this->assertStringContainsString('There are 12 units.', $a);
        $this->assertStringContainsString(__('ai.admin.feedback.negative'), $a);
        $this->assertStringContainsString(__('ai.admin.feedback.positive'), $a);
        $this->assertStringNotContainsString('SubmitButton', $a);

        $b = $this->transcriptJson($this->conversations['B']->uuid);
        $this->assertStringContainsString(__('ai.admin.trace.failed'), $b);
        $this->assertStringContainsString('execution: boom', $b);
    }

    /** @test */
    public function the_transcript_and_the_feedback_table_show_the_comment_and_time_of_a_rating_escaped(): void
    {
        $this->grantToAdmin();
        $this->actingAs($this->admin);

        $transcript = $this->transcriptJson($this->conversations['A']->uuid);
        $table = (string) json_encode(
            (new AdminFeedbackTable(['days' => 30, 'from' => '2026-09-22', 'to' => '2026-10-21']))->bootForDisplay(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        foreach (['transcript' => $transcript, 'feedback table' => $table] as $where => $json) {
            $this->assertStringContainsString('Wrong &lt;b&gt;count&lt;/b&gt;', $json, $where);
            $this->assertStringNotContainsString('<b>count', $json, $where);
            $this->assertStringContainsString('2026-10-19 12:05', $json, $where);
        }
    }

    /** @test */
    public function the_transcript_copies_the_conversation_as_an_anonymised_eval_case(): void
    {
        $this->grantToAdmin();
        $this->actingAs($this->admin);

        $modal = json_decode($this->transcriptJson($this->conversations['A']->uuid), true);
        $copied = null;
        array_walk_recursive($modal, function ($value, $key) use (&$copied) {
            if ($key === 'jsFunction' && is_string($value) && preg_match('/const text = (".*?");/s', $value, $m)) {
                $copied = json_decode($m[1]);
            }
        });

        $this->assertIsString($copied, 'a button copies text to the clipboard');
        $case = json_decode($copied, true);
        $this->assertSame(['How many units?', 'And owners?'], array_column($case['conversations'][0]['turns'], 'ask'));
        $this->assertSame(['data'], $case['conversations'][0]['turns'][0]['expect']['branch_in']);
        foreach (['Alice', 'alice@example.com', 'Jardins'] as $personal) {
            $this->assertStringNotContainsString($personal, $copied);
        }
    }

    /** @test */
    public function the_transcript_is_not_found_for_ids_eval_or_deleted_conversations(): void
    {
        $this->grantToAdmin();
        $this->actingAs($this->admin);

        $notFound = __('ai.admin.transcript-not-found');
        $this->assertStringContainsString($notFound, $this->transcriptJson((string) $this->conversations['A']->id));
        $this->assertStringContainsString($notFound, $this->transcriptJson($this->conversations['D']->uuid));
        $this->assertStringContainsString($notFound, $this->transcriptJson($this->conversations['E']->uuid));
        $this->assertStringNotContainsString('Deleted question', $this->transcriptJson($this->conversations['E']->uuid));
    }

    /** @test */
    public function the_feedback_table_renders_rows_that_open_the_transcript(): void
    {
        $this->grantToAdmin();
        $this->actingAs($this->admin);

        $table = new AdminFeedbackTable(['days' => 30, 'from' => '2026-09-22', 'to' => '2026-10-21']);
        $json = (string) json_encode($table->bootForDisplay(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString('How many units?', $json);
        $this->assertStringContainsString('Alice Martin', $json);
        $this->assertStringNotContainsString('Eval question', $json);

        request()->merge(['conversation' => $this->conversations['A']->uuid, 'message' => $this->messages['a1']->id]);
        $modal = $table->openTranscript();
        $this->assertInstanceOf(AdminTranscriptModal::class, $modal);
    }

    /** @test */
    public function the_page_and_lines_exist_in_english_and_french(): void
    {
        $en = json_decode((string) file_get_contents(__DIR__ . '/../../../resources/lang/en.json'), true);
        $fr = json_decode((string) file_get_contents(__DIR__ . '/../../../resources/lang/fr.json'), true);
        $this->assertIsArray($en);
        $this->assertIsArray($fr);

        $used = [];
        foreach (glob(__DIR__ . '/../../../src/Kompo/Admin/{*,Concerns/*}.php', GLOB_BRACE) ?: [] as $file) {
            preg_match_all("/'(ai\\.admin\\.[a-z0-9_.-]+)'/", (string) file_get_contents($file), $m);
            $used = array_merge($used, $m[1]);
        }
        // Not lines: the route name and the dynamic prefixes (checked below).
        $used = array_values(array_unique(array_filter($used, fn ($k) => !str_ends_with($k, '.') && $k !== 'ai.admin.conversations')));
        $this->assertNotEmpty($used);

        foreach ($used as $key) {
            $this->assertNotEmpty($en[$key] ?? null, "en: {$key}");
            $this->assertNotEmpty($fr[$key] ?? null, "fr: {$key}");
        }
        foreach (['data', 'knowledge_query', 'digest', 'export', 'empty_result', 'clarify', 'conversational', 'error', 'navigation', 'page_narration'] as $route) {
            $this->assertNotEmpty($en['ai.admin.route.' . $route] ?? null, "en route {$route}");
            $this->assertNotEmpty($fr['ai.admin.route.' . $route] ?? null, "fr route {$route}");
        }
        foreach (['user', 'assistant', 'system'] as $role) {
            $this->assertNotEmpty($en['ai.admin.role.' . $role] ?? null);
            $this->assertNotEmpty($fr['ai.admin.role.' . $role] ?? null);
        }
    }
}
