<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Query;

use Condoedge\Ai\DTOs\QueryUnderstandingResult;
use Condoedge\Ai\Services\Query\PreviousResultExport;
use Condoedge\Ai\Tests\TestCase;

/**
 * Eval p18 (B resilience t3, B french t1, A pdf t1 — 6-8k tokens and ~2 s of query
 * generation each, its query then discarded): an export follow-up that asks for the
 * recorded result AS IT IS needs no new query. The four export follow-ups of the
 * p18 traces, with Query Understanding's own output for them.
 */
class PreviousResultExportTest extends TestCase
{
    private const ROSTER = 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id '
        . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) WHERE tr.team_id = t.id '
        . 'RETURN u.name AS name, u.email AS email, tr.role AS role LIMIT 100';

    private const ROSTER_LOGIN = 'MATCH (t:Team) WHERE t.id = 3 MATCH (u:User) WHERE u.current_team_id = t.id '
        . 'OPTIONAL MATCH (tr:TeamRole)-[:BELONGS_TO_USER]->(u) WHERE tr.team_id = t.id '
        . 'RETURN u.name AS name, u.email AS email, tr.role AS role, u.last_logged_at AS last_login LIMIT 100';

    private const UNIT_BALANCE = "MATCH (u:Unit) WHERE u.name = '310' MATCH (u)-[:UNION]->(un:Union) WHERE un.team_id IN \$ai_team_ids "
        . "OPTIONAL MATCH (i:Invoice)-[:CUSTOMER]->(u) WHERE i.status <> '1' AND i.due_amount >= 0.01 "
        . 'RETURN un.name AS union_name, u.name AS unit_name, round(u.outstanding_balance, 2) AS balance, count(i) AS open_invoices, u.id AS unit_id';

    /** @param array<string, mixed> $qu */
    private function qu(array $qu): QueryUnderstandingResult
    {
        return new QueryUnderstandingResult(...($qu + [
            'questionType' => 'data_query', 'exportFormat' => 'excel', 'followUpReference' => true, 'language' => 'en',
        ]));
    }

    /** @return array<string, mixed> */
    private function recorded(string $cypher, array $row, string $subject): array
    {
        return [
            'last_cypher_query' => $cypher,
            'last_result_count' => 100,
            'last_result_sample' => [$row],
            'last_subject' => $subject,
        ];
    }

    private function roster(string $subject = 'Team Members and Roles'): array
    {
        return $this->recorded(self::ROSTER, ['name' => 'Julie Rousseau', 'email' => 'j@x.test', 'role' => 'team-owner'], $subject);
    }

    // ----------------------------------------------- the four p18 follow-ups

    /** @test */
    public function export_that_members_list_asks_for_the_recorded_result_as_it_is(): void
    {
        // B resilience t3: "Ok, can you export that members list to excel?"
        $this->assertTrue(PreviousResultExport::asksNothingNew(
            $this->qu(['searchQuery' => 'team members last login', 'subjectLabel' => 'Team Members Last Login', 'entityTypes' => ['Team', 'User', 'TeamRole']]),
            $this->recorded(self::ROSTER_LOGIN, ['name' => 'Julie', 'email' => 'j@x.test', 'role' => 'team-owner', 'last_login' => '2026-10-01'], 'Team Members Last Login')
        ));
    }

    /** @test */
    public function the_french_export_follow_up_asks_for_nothing_new(): void
    {
        // B french t1: "Parfait — exporte-la en Excel."
        $this->assertTrue(PreviousResultExport::asksNothingNew(
            $this->qu(['searchQuery' => 'team members roles', 'subjectLabel' => 'Membres de mon équipe', 'language' => 'fr']),
            $this->roster('Membres de mon équipe')
        ));
    }

    /** @test */
    public function a_concept_the_recorded_result_already_carries_is_not_new(): void
    {
        // A pdf t1: "Create a PDF of this." — QU repeats the previous turn's concept.
        $this->assertTrue(PreviousResultExport::asksNothingNew(
            $this->qu(['exportFormat' => 'pdf', 'propertyConcepts' => ['balance'], 'searchQuery' => 'Unit 310 balance', 'subjectLabel' => 'Unit 310 Balance']),
            $this->recorded(self::UNIT_BALANCE, ['union_name' => 'SDC A', 'unit_name' => '310', 'balance' => 0.0, 'open_invoices' => 0, 'unit_id' => 159], 'Unit 310 balance')
        ), 'a subject spelled in another case is the same subject');
    }

    /** @test */
    public function an_export_that_adds_an_attribute_still_needs_its_query(): void
    {
        // A excel t1: "Perfect — give me that as an Excel with their contact info."
        $this->assertFalse(PreviousResultExport::asksNothingNew(
            $this->qu(['propertyConcepts' => ['contact', 'email', 'role'], 'searchQuery' => 'team members contact information', 'subjectLabel' => 'Team Members Contact Information']),
            $this->roster()
        ));
    }

    /** @test */
    public function a_person_name_echoed_from_the_rows_does_not_force_a_query(): void
    {
        // Live 2026-10-06, "Parfait — exporte-la en Excel.": QU returned person_names
        // ["Julie Rousseau"] — the asker, read off the rows it was shown. A query that only
        // narrowed the recorded rows was never the export source anyway.
        $this->assertTrue(PreviousResultExport::asksNothingNew(
            $this->qu(['personNames' => ['Julie Rousseau'], 'subjectLabel' => 'Membres de mon équipe', 'language' => 'fr']),
            $this->roster('Membres de mon équipe')
        ));
    }

    // ------------------------------------------------------- each condition

    /** @test */
    public function a_new_concept_alone_is_enough_to_generate(): void
    {
        $this->assertFalse(PreviousResultExport::asksNothingNew(
            $this->qu(['propertyConcepts' => ['phone'], 'subjectLabel' => 'Team Members and Roles']),
            $this->roster()
        ));
    }

    /** @test */
    public function a_changed_subject_alone_is_enough_to_generate(): void
    {
        // QU named no property concept (measured live for "add their last login to it"),
        // but it re-labelled what the turn is about.
        $this->assertFalse(PreviousResultExport::asksNothingNew(
            $this->qu(['subjectLabel' => 'Team Members Last Login']),
            $this->roster()
        ));
    }

    /**
     * @test Eval p19-A / p20-A roster t1, "Perfect — give me that as an Excel with their
     * contact info.": QU re-labelled the subject ("Team Members Contact Information") while
     * every attribute it named — email, role — is a column of the recorded roster. The
     * turn paid ~7k tokens for a query identical to the recorded one and the same file.
     * The LABEL alone does not force a regeneration: the attributes QU named are all
     * carried, and the turn is still about an entity the recorded query holds.
     */
    public function a_relabelled_subject_whose_named_attributes_are_all_carried_asks_for_nothing_new(): void
    {
        $this->assertTrue(PreviousResultExport::asksNothingNew(
            $this->qu([
                'propertyConcepts' => ['email', 'role'], 'entityTypes' => ['Team', 'User', 'ContactDirectory'],
                'searchQuery' => 'team members contact information', 'subjectLabel' => 'Team Members Contact Information',
            ]),
            $this->roster()
        ));
    }

    /**
     * @test …but only on that evidence. A re-labelled subject still forces the query when
     * QU named no attribute at all (the re-label is then the only sign of what changed —
     * "add their last login to it"), when one named attribute is new, when the turn is about
     * an entity the recorded query does not hold (another population with the same column
     * names), or when QU named no entity.
     */
    public function a_relabelled_subject_still_generates_without_that_evidence(): void
    {
        $relabelled = ['searchQuery' => 'team members contact information', 'subjectLabel' => 'Team Members Contact Information'];

        $this->assertFalse(PreviousResultExport::asksNothingNew(
            $this->qu($relabelled + ['propertyConcepts' => [], 'entityTypes' => ['Team', 'User']]),
            $this->roster()
        ), 'no attribute named');
        $this->assertFalse(PreviousResultExport::asksNothingNew(
            $this->qu($relabelled + ['propertyConcepts' => ['email', 'phone'], 'entityTypes' => ['Team', 'User']]),
            $this->roster()
        ), 'a new attribute');
        $this->assertFalse(PreviousResultExport::asksNothingNew(
            $this->qu(['subjectLabel' => 'Owners e-mails', 'propertyConcepts' => ['email'], 'entityTypes' => ['Contact', 'User']]),
            $this->roster()
        ), 'the primary entity is not one of the recorded query');
        $this->assertFalse(PreviousResultExport::asksNothingNew(
            $this->qu($relabelled + ['propertyConcepts' => ['email', 'role'], 'entityTypes' => []]),
            $this->roster()
        ), 'no entity named');
        $this->assertFalse(PreviousResultExport::asksNothingNew(
            $this->qu($relabelled + ['propertyConcepts' => ['email', 'role'], 'entityTypes' => ['Team', 'User']]),
            ['last_subject' => null] + $this->roster()
        ), 'the recorded subject is unknown');
    }

    // ------------------------------------------- a turn that names nothing (eval p21-B french t1)

    /** The conversation as recorded after the French roster turn, with its exchange. */
    private function frenchRoster(array $exchanges): array
    {
        return $this->roster('Membres de mon équipe') + ['recent_exchanges' => $exchanges];
    }

    private const FRENCH_ROSTER_QUESTION = 'Peux-tu me donner la liste des membres de mon équipe avec leurs rôles ?';

    /** @return list<array{role: string, question: ?string, answer_summary: ?string}> */
    private function exchanges(string ...$turns): array
    {
        $exchanges = [];
        foreach ($turns as $index => $text) {
            $exchanges[] = $index % 2 === 0
                ? ['role' => 'user', 'question' => $text, 'answer_summary' => null]
                : ['role' => 'assistant', 'question' => null, 'answer_summary' => $text];
        }

        return $exchanges;
    }

    /**
     * @test Eval p21-B french t1, "Parfait — exporte-la en Excel.": QU re-labelled the subject
     * ("Liste des membres de l'équipe" for the recorded "Membres de mon équipe") and named NO
     * entity and NO property concept. Generation ran — 6.5k tokens, 1.5 s —, returned an empty
     * query, and the file was the recorded result anyway. An export follow-up that names
     * nothing, and whose label holds only words the conversation already holds (the recorded
     * subject, its columns, its entities, the questions already answered), asks for nothing new.
     */
    public function an_export_follow_up_that_names_nothing_asks_for_nothing_new(): void
    {
        $qu = $this->qu(['subjectLabel' => "Liste des membres de l'équipe", 'searchQuery' => 'team members list', 'language' => 'fr', 'intent' => 'file_listing']);

        $this->assertTrue(PreviousResultExport::asksNothingNew(
            $qu,
            $this->frenchRoster($this->exchanges(self::FRENCH_ROSTER_QUESTION, '523 membres.'))
        ));
        // The typographic apostrophe is the same word boundary.
        $this->assertTrue(PreviousResultExport::asksNothingNew(
            $this->qu(['subjectLabel' => "Liste des membres de l\u{2019}équipe", 'language' => 'fr']),
            $this->frenchRoster($this->exchanges(self::FRENCH_ROSTER_QUESTION, '523 membres.'))
        ));
    }

    /**
     * @test THE CONJUNCTION, each part. "No entity and no property concept" alone proves
     * nothing — measured on the stored runs (eval p16–p21): "Ok, but could you add their last
     * login to it?" came back with neither in seven runs of seven, and it asks for a new
     * column. So the turn must also be an EXPORT that refers back to the result, and its
     * re-label must bring no word of its own: the one thing such a turn still says about a
     * new column is the label QU writes for it ("Team Members Last Login").
     */
    public function a_turn_that_names_nothing_still_generates_when_its_label_brings_a_new_word(): void
    {
        $asked = $this->exchanges('I wanna know the members of my team with their roles.', '523 members.');
        $conversation = $this->roster() + ['recent_exchanges' => $asked];

        // "…export it with their last login": no entity, no concept — and a label of its own.
        $this->assertFalse(PreviousResultExport::asksNothingNew($this->qu(['subjectLabel' => 'Team Members Last Login']), $conversation));
        // A two-letter word is a word ("ID"); only a single letter (an elided article) is none.
        $this->assertFalse(PreviousResultExport::asksNothingNew($this->qu(['subjectLabel' => 'Team Members ID']), $conversation));
        // The same label with nothing new in it.
        $this->assertTrue(PreviousResultExport::asksNothingNew($this->qu(['subjectLabel' => 'Members of my team']), $conversation));

        // Nothing vouches for the word: the conversation's questions are not known.
        $this->assertFalse(PreviousResultExport::asksNothingNew(
            $this->qu(['subjectLabel' => "Liste des membres de l'équipe", 'language' => 'fr']),
            $this->roster('Membres de mon équipe')
        ));
        // A question still WITHOUT its answer is this turn's own (the regenerate path records
        // it before the pipeline runs): its words are what is being asked, not what is known.
        $this->assertFalse(PreviousResultExport::asksNothingNew(
            $this->qu(['subjectLabel' => 'Team Members Last Login']),
            $this->roster() + ['recent_exchanges' => [...$asked, ...$this->exchanges('Ok, export it with their last login.')]]
        ));
        // A word of an ANSWER is not a word the user asked with.
        $this->assertFalse(PreviousResultExport::asksNothingNew(
            $this->qu(['subjectLabel' => 'Team Members Last Login']),
            $this->roster() + ['recent_exchanges' => $this->exchanges('Who are the members of my team?', 'Their last login is not shown here.')]
        ));

        // Not an export, or not a reference to the result: the rule does not apply.
        $nothingNew = ['subjectLabel' => 'Members of my team'];
        $this->assertFalse(PreviousResultExport::asksNothingNew($this->qu($nothingNew + ['exportFormat' => null]), $conversation));
        $this->assertFalse(PreviousResultExport::asksNothingNew($this->qu($nothingNew + ['followUpReference' => false]), $conversation));
        // It names an entity: the re-label rule decides, as before (no attribute named: generate).
        $this->assertFalse(PreviousResultExport::asksNothingNew($this->qu($nothingNew + ['entityTypes' => ['Team', 'User']]), $conversation));
    }

    /**
     * @test Live 2026-10-07, roster then "Ok, export that to Excel.": QU repeated the roster
     * turn's own concepts — ["members", "roles"] — and "members" is no column: the turn paid
     * for query generation again. A concept that NAMES AN ENTITY of the recorded query (the
     * host's own entity names and aliases — "member" is the user entity) is the population
     * the rows already are, not an attribute to add.
     */
    public function a_concept_naming_an_entity_of_the_recorded_query_is_not_new(): void
    {
        config(['entities' => [
            'App\\Models\\User' => ['graph' => ['label' => 'User'], 'metadata' => ['aliases' => ['member', 'team member']]],
            'App\\Models\\Unit' => ['graph' => ['label' => 'Unit'], 'metadata' => ['aliases' => ['apartment']]],
        ]]);
        app()->forgetInstance(\Condoedge\Ai\Services\Discovery\EntityLabelIndex::class);

        $same = ['subjectLabel' => 'Team Members and Roles', 'entityTypes' => ['Team', 'User', 'TeamRole']];

        $this->assertTrue(PreviousResultExport::asksNothingNew($this->qu($same + ['propertyConcepts' => ['members', 'roles']]), $this->roster()));
        // An entity the recorded query does not hold is something new.
        $this->assertFalse(PreviousResultExport::asksNothingNew($this->qu($same + ['propertyConcepts' => ['members', 'apartments']]), $this->roster()));
        // A re-labelled subject needs an ATTRIBUTE it can check: a population name alone is none.
        $this->assertFalse(PreviousResultExport::asksNothingNew(
            $this->qu(['subjectLabel' => 'Team Members Last Login', 'entityTypes' => ['Team', 'User'], 'propertyConcepts' => ['members']]),
            $this->roster()
        ));
    }

    /** @test */
    public function a_concept_is_matched_to_columns_and_projected_properties_in_any_spelling(): void
    {
        $recorded = $this->recorded(
            'MATCH (u:User) WHERE u.team_id IN $ai_team_ids RETURN u.name AS nom, u.email AS courriel, u.last_logged_at AS last_login, u.role AS role',
            ['nom' => 'Julie', 'courriel' => 'j@x.test', 'last_login' => '2026-10-01', 'role' => 'owner'],
            'Membres'
        );
        $same = ['subjectLabel' => 'Membres'];

        $this->assertTrue(PreviousResultExport::asksNothingNew($this->qu($same + ['propertyConcepts' => ['rôles']]), $recorded), 'plural + accent');
        $this->assertTrue(PreviousResultExport::asksNothingNew($this->qu($same + ['propertyConcepts' => ['last login']]), $recorded), 'a two-word concept, one column');
        $this->assertTrue(PreviousResultExport::asksNothingNew($this->qu($same + ['propertyConcepts' => ['email']]), $recorded), 'the projected property behind an alias');
        $this->assertFalse(PreviousResultExport::asksNothingNew($this->qu($same + ['propertyConcepts' => ['email', 'address']]), $recorded));
    }

    /** @test */
    public function it_needs_an_export_follow_up_over_a_recorded_query_with_rows(): void
    {
        $same = ['subjectLabel' => 'Team Members and Roles'];

        $this->assertFalse(PreviousResultExport::asksNothingNew($this->qu($same + ['exportFormat' => null]), $this->roster()), 'not an export');
        $this->assertFalse(PreviousResultExport::asksNothingNew($this->qu($same + ['followUpReference' => false]), $this->roster()), 'a cold ask');
        $this->assertFalse(PreviousResultExport::asksNothingNew($this->qu($same + ['failed' => true]), $this->roster()), 'QU failed: its judgments are unknown');

        $this->assertFalse(PreviousResultExport::asksNothingNew($this->qu($same), ['last_cypher_query' => null] + $this->roster()), 'no recorded query (a digest turn)');
        $this->assertFalse(PreviousResultExport::asksNothingNew($this->qu($same), ['last_result_sample' => []] + $this->roster()), 'no recorded rows');
        $this->assertFalse(PreviousResultExport::asksNothingNew($this->qu($same), ['last_result_count' => 0] + $this->roster()), 'no recorded rows');
        $this->assertFalse(PreviousResultExport::asksNothingNew($this->qu($same), ['last_subject' => null] + $this->roster()), 'the recorded subject is unknown: nothing proves the ask is the same');
        $this->assertFalse(PreviousResultExport::asksNothingNew($this->qu(['subjectLabel' => '', 'searchQuery' => '']), $this->roster()), 'this turn has no subject');
        $this->assertFalse(PreviousResultExport::asksNothingNew($this->qu($same), []), 'no conversation');
    }
}
