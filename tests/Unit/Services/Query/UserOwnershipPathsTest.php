<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Query;

use Condoedge\Ai\Services\Query\UserOwnershipPaths;
use PHPUnit\Framework\TestCase;

/**
 * Eval p16 A#3 / B#1 — whether an EXECUTED query is anchored on the user, and which
 * ownership path its emptiness hangs on, read structurally from the host's
 * ai.user_entity_paths (never from the question's words or QU's possessive flag).
 */
class UserOwnershipPathsTest extends TestCase
{
    private const PATHS = [
        'Unit' => [
            'cypher' => 'MATCH (c:Contact {user_id: $userId})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(n:Unit) WHERE cu.sold_at IS NULL AND cu.deleted_at IS NULL',
        ],
        'Contact' => [
            'cypher' => 'MATCH (n:Contact {user_id: $userId})',
        ],
        'Invoice' => [
            'cypher' => 'MATCH (c:Contact {user_id: $userId})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) WHERE cu.sold_at IS NULL '
                . 'MATCH (n:Invoice) WHERE (n.customer_type = "unit" AND n.customer_id = u.id) OR (n.customer_type = "contact" AND n.customer_id = c.id)',
        ],
        'BoardMember' => [
            'cypher' => 'MATCH (c:Contact {user_id: $userId})<-[:BELONGS_TO_CONTACT]-(n:BoardMember)',
        ],
        'Thread' => [
            'cypher' => 'MATCH (ea:EmailAccount) WHERE (ea.entity_type = "user" AND ea.entity_id = $userId) '
                . 'MATCH (m:Message)-[:BELONGS_TO_THREAD]->(n:Thread) WHERE m.sender_id = ea.id',
        ],
    ];

    private const CONTRIBUTION = "MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(u:Unit) WHERE cu.sold_at IS NULL "
        . "MATCH (i:Invoice)-[:CUSTOMER]->(u) WHERE i.customer_type = 'unit' AND substring(i.invoiced_at, 0, 4) = '2026' "
        . 'RETURN i.invoice_number AS invoice_number, i.due_amount AS contribution, i.id AS invoice_id LIMIT 100';

    private const NEXT_MEETING = 'MATCH (m:Meeting) WHERE m.team_id IN $ai_team_ids AND m.start_date > toString(date()) '
        . 'WITH m ORDER BY m.start_date ASC LIMIT 1 OPTIONAL MATCH (s:Schedule)-[:BELONGS_TO_MEETING]->(m) '
        . 'RETURN m.name AS meeting_name, m.start_date AS start_date, collect(s.title) AS agenda';

    private function paths(): UserOwnershipPaths
    {
        return new UserOwnershipPaths(self::PATHS, 'User');
    }

    /** @test */
    public function the_anchors_are_the_user_bound_nodes_the_host_paths_declare(): void
    {
        $anchors = array_map(static fn (array $a): string => $a['label'] . '.' . $a['property'], $this->paths()->anchors());

        $this->assertContains('Contact.user_id', $anchors, 'inline map form');
        $this->assertContains('EmailAccount.entity_id', $anchors, 'WHERE form');
        $this->assertContains('User.id', $anchors, 'the identity anchor of the authenticated user');
        $this->assertCount(3, $anchors, 'each anchor once');
    }

    /** @test */
    public function a_query_binding_a_configured_anchor_to_this_user_is_anchored_on_the_user(): void
    {
        $paths = $this->paths();

        $this->assertSame(['label' => 'Contact', 'property' => 'user_id'], $paths->anchorIn(self::CONTRIBUTION, 13));
        $this->assertNotNull($paths->anchorIn("MATCH (c:Contact) WHERE c.user_id = 13 RETURN c.id", 13), 'WHERE form');
        $this->assertNotNull($paths->anchorIn("MATCH (c:Contact {user_id: '13'}) RETURN c.id", 13), 'quoted literal');
        $this->assertNotNull($paths->anchorIn('MATCH (u:User {id: 13}) RETURN u.email', '13'), 'identity anchor');
    }

    /** @test */
    public function a_team_scoped_query_is_not_anchored_on_the_user(): void
    {
        $paths = $this->paths();

        $this->assertNull($paths->anchorIn(self::NEXT_MEETING, 13), 'the live "our next meeting" query');
        $this->assertNull($paths->anchorIn(self::CONTRIBUTION, 14), 'another user id');
        $this->assertNull($paths->anchorIn('MATCH (c:Contact {user_id: 130}) RETURN c', 13), 'never a prefix of a longer id');
        $this->assertNull($paths->anchorIn('MATCH (c:Contact) WHERE c.user_id = 131 RETURN c', 13));
        $this->assertNull($paths->anchorIn('MATCH (i:Invoice {user_id: 13}) RETURN i', 13), 'the property on another label is no anchor');
        $this->assertNull($paths->anchorIn(self::CONTRIBUTION, null));
        $this->assertNull($paths->anchorIn(null, 13));
        $this->assertNull($paths->anchorIn('', 13));
    }

    /** @test */
    public function the_probe_is_the_shallowest_ownership_path_the_executed_query_traverses(): void
    {
        $probe = $this->paths()->probeFor(self::CONTRIBUTION, 13);

        $this->assertNotNull($probe);
        $this->assertSame('Unit', $probe['entity'], 'the entity the own-data question hangs on — not Invoice, not the profile row');
        $this->assertSame(
            'MATCH (c:Contact {user_id: 13})<-[:CONTACT]-(cu:ContactUnit)-[:UNIT]->(n:Unit) WHERE cu.sold_at IS NULL AND cu.deleted_at IS NULL '
            . 'RETURN count(DISTINCT n) AS total',
            $probe['cypher']
        );
    }

    /** @test */
    public function the_probe_needs_the_whole_path_to_be_in_the_executed_query(): void
    {
        $paths = $this->paths();

        $board = 'MATCH (c:Contact {user_id: 13})<-[:BELONGS_TO_CONTACT]-(bm:BoardMember)-[:BELONGS_TO_UNION]->(un:Union) RETURN un.name AS union_name';
        $this->assertSame('BoardMember', $paths->probeFor($board, 13)['entity'] ?? null);

        $profileOnly = 'MATCH (c:Contact {user_id: 13}) RETURN c.phone AS phone';
        $this->assertNull($paths->probeFor($profileOnly, 13), 'the profile row itself is not an entity a question hangs on');

        $this->assertNull($paths->probeFor(self::NEXT_MEETING, 13), 'not anchored on the user: nothing to probe');
        $this->assertNull($paths->probeFor(self::CONTRIBUTION, 14), 'anchored on someone else');
    }

    /** @test */
    public function two_equally_shallow_paths_are_ambiguous_and_probe_nothing(): void
    {
        $paths = new UserOwnershipPaths([
            'BoardMember' => ['cypher' => 'MATCH (c:Contact {user_id: $userId})<-[:BELONGS_TO_CONTACT]-(n:BoardMember)'],
            'Vehicle' => ['cypher' => 'MATCH (c:Contact {user_id: $userId})<-[:OWNER]-(n:Vehicle)'],
        ]);

        $both = 'MATCH (c:Contact {user_id: 13})<-[:BELONGS_TO_CONTACT]-(bm:BoardMember) MATCH (c)<-[:OWNER]-(v:Vehicle) RETURN bm.id, v.id';

        $this->assertNull($paths->probeFor($both, 13));
    }

    /** @test */
    public function a_non_numeric_user_id_is_quoted_and_escaped(): void
    {
        $paths = new UserOwnershipPaths([
            'Unit' => ['cypher' => 'MATCH (c:Contact {user_id: $userId})<-[:CONTACT]-(n:Unit)'],
        ]);
        $id = "ab'c";
        $executed = "MATCH (c:Contact {user_id: 'ab\\'c'})<-[:CONTACT]-(u:Unit) RETURN u.name";

        $probe = $paths->probeFor($executed, $id);

        $this->assertNotNull($probe);
        $this->assertStringContainsString("{user_id: 'ab\\'c'}", $probe['cypher']);
    }

    /** @test */
    public function no_configured_paths_means_no_anchor_but_the_identity_one_and_no_probe(): void
    {
        $paths = new UserOwnershipPaths([], 'User');

        $this->assertNull($paths->anchorIn(self::CONTRIBUTION, 13));
        $this->assertNotNull($paths->anchorIn('MATCH (u:User) WHERE u.id = 13 RETURN u.name', 13));
        $this->assertNull($paths->probeFor(self::CONTRIBUTION, 13));
    }
}
