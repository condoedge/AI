<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Contracts\LlmProviderInterface;
use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\QueryUnderstandingService;
use Condoedge\Ai\Support\AiLogger;
use Condoedge\Ai\Tests\TestCase;
use Mockery;

/**
 * Actions plan B2 — "provide the link of the user profile when asking related" (owner).
 *
 * The QU LLM judges, inside the SAME call and in any language, whether the user asks to
 * see / open / get a link to their OWN profile/account/settings or their OWN team page:
 * `navigation_target` = own_profile | own_team | null. A structured output only — it never
 * touches entity_types or retrieval; the action attacher reads it.
 */
class QueryUnderstandingNavigationTargetTest extends TestCase
{
    /** @var list<array<int, array<string, string>>> */
    private array $prompts = [];

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function analyze(array $llmJson): QueryUnderstandingResult
    {
        $llm = Mockery::mock(LlmProviderInterface::class);
        $llm->shouldReceive('chatJson')->once()->andReturnUsing(function ($messages) use ($llmJson) {
            $this->prompts[] = $messages;

            return $llmJson + [
                'entity_types' => [],
                'person_names' => [],
                'property_concepts' => [],
                'search_query' => 'q',
                'possessive_words' => ['my'],
                'possessive_forms' => ['possessive'],
                'possessive_scope' => 'personal',
                'intent' => 'lookup',
                'question_type' => 'data_query',
                'clarified_question' => '',
                'search_tags' => [],
            ];
        });
        $logger = Mockery::mock(AiLogger::class);
        $logger->shouldReceive('startTimer')->andReturn(null)->byDefault();
        $logger->shouldReceive('stopTimer')->andReturn(0.0)->byDefault();
        $logger->shouldReceive('info')->byDefault();
        $logger->shouldReceive('debug')->byDefault();
        $logger->shouldReceive('error')->byDefault();

        return (new QueryUnderstandingService($llm, $logger, []))->analyze('Can you give me the link to my profile?', []);
    }

    /** @test */
    public function the_prompt_asks_for_the_navigation_target_judgment(): void
    {
        $this->analyze([]);

        $system = $this->prompts[0][0]['content'];
        $this->assertStringContainsString('"navigation_target"', $system);
        $this->assertStringContainsString('"own_profile"', $system);
        $this->assertStringContainsString('"own_team"', $system);
        // Eval p14-B resilience t2: "Where can I add new members?" lost its team chip when the
        // data-is-null rule's example ("their team's members") swallowed the how-to ask.
        $this->assertStringContainsString('Asking WHERE or HOW to do something on that page (add or invite members, change settings) IS a navigation ask', $system);
    }

    /**
     * @test Eval p13: "Show me the balance." came back navigation_target=own_profile and was answered
     * "view your balance on My profile"; rosters and exports came back own_team. A DATA question that
     * names attributes asks for values, never a page.
     */
    public function a_data_question_naming_attributes_is_never_a_navigation_ask(): void
    {
        $this->assertNull($this->analyze([
            'navigation_target' => 'own_profile', 'property_concepts' => ['balance'], 'question_type' => 'data_query',
        ])->navigationTarget);
        $this->assertNull($this->analyze([
            'navigation_target' => 'own_team', 'property_concepts' => ['members', 'roles'], 'question_type' => 'data_query',
        ])->navigationTarget);

        // Real navigation asks keep it: a knowledge ask naming the thing to do, or no attribute at all.
        $this->assertSame('own_team', $this->analyze([
            'navigation_target' => 'own_team', 'property_concepts' => ['members'], 'question_type' => 'knowledge_query',
        ])->navigationTarget);
        $this->assertSame('own_profile', $this->analyze([
            'navigation_target' => 'own_profile', 'property_concepts' => [], 'question_type' => 'data_query',
        ])->navigationTarget);
    }

    /**
     * @test The guard above hung on ONE field: measured live (it9), "Who are the members of my team
     * and what are their roles?" came back navigation_target=own_team on 20 of 20 replies, and on the
     * one reply whose property_concepts was empty the target survived. A DATA question that asks
     * for a SET of records (QU intent list / count / aggregate / compare) asks for those records,
     * never for a page — a second structural signal, so the guard no longer depends on one field.
     */
    public function a_data_question_asking_for_a_set_of_records_is_never_a_navigation_ask(): void
    {
        foreach (['list', 'count', 'aggregate', 'compare'] as $intent) {
            $this->assertNull($this->analyze([
                'navigation_target' => 'own_team', 'property_concepts' => [], 'question_type' => 'data_query', 'intent' => $intent,
            ])->navigationTarget, $intent);
        }

        // A single-record ask naming no attribute keeps it ("link to my profile", "information about my team")…
        foreach (['lookup', 'other'] as $intent) {
            $this->assertSame('own_team', $this->analyze([
                'navigation_target' => 'own_team', 'property_concepts' => [], 'question_type' => 'data_query', 'intent' => $intent,
            ])->navigationTarget, $intent);
        }
        // …and so does a how-to ask, whatever its intent ("where can I add members?").
        $this->assertSame('own_team', $this->analyze([
            'navigation_target' => 'own_team', 'property_concepts' => [], 'question_type' => 'knowledge_query', 'intent' => 'list',
        ])->navigationTarget);
    }

    /** @test */
    public function own_profile_and_own_team_are_parsed_and_anything_else_is_null(): void
    {
        $this->assertSame('own_profile', $this->analyze(['navigation_target' => 'own_profile'])->navigationTarget);
        $this->assertSame('own_team', $this->analyze(['navigation_target' => ' OWN_TEAM '])->navigationTarget);
        $this->assertNull($this->analyze(['navigation_target' => 'settings'])->navigationTarget);
        $this->assertNull($this->analyze(['navigation_target' => null])->navigationTarget);
        $this->assertNull($this->analyze([])->navigationTarget);
    }

    /** @test It never touches entity_types (retrieval), survives the follow-up copy and is traced. */
    public function it_is_a_pure_structured_output(): void
    {
        $result = $this->analyze(['navigation_target' => 'own_profile', 'entity_types' => []]);

        $this->assertSame([], $result->entityTypes);
        $this->assertSame('own_profile', $result->toArray()['navigation_target']);
        $this->assertSame('own_profile', $result->asFollowUpOnPreviousResult()->navigationTarget);
        $this->assertNull(QueryUnderstandingResult::empty()->navigationTarget);
    }
}
