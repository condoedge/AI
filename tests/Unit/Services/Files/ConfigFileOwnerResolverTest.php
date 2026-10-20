<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Files;

use Condoedge\Ai\Services\Files\ConfigFileOwnerResolver;
use Condoedge\Ai\Services\Files\RecordAncestry;
use Condoedge\Ai\Support\FileLink;
use Condoedge\Ai\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * An UPLOADED file is linked to the record it is filed on. The owner and its
 * ancestors are derived from structure the host already declared — the File
 * entity's morph parent and its many-to-one relationships with a foreign key —
 * with no host code and no vocabulary:
 *
 *   owner     = the morph parent (`unit:309`), else the first declared
 *               belongs-to record (`union:14`), else null;
 *   ancestors = the records that own that record, most specific first
 *               (the parent's own belongs-to records, then the file's).
 *
 * The identity labels (the current user / team) are never a key: the tenant is
 * the ACL's business and an uploader is not an owner.
 */
class ConfigFileOwnerResolverTest extends TestCase
{
    /** @var array<string, array<int|string, array<string, mixed>>> label => id => attributes */
    private array $rows = [];

    /** @var list<string> */
    private array $lookups = [];

    public function setUp(): void
    {
        parent::setUp();

        config(['ai.response_generation.identity_labels' => ['user' => 'User', 'team' => 'Team']]);
        config(['ai.file_context.file_model' => 'Tests\\Fixtures\\OwnedFile']);
        config(['entities' => [
            'Tests\\Fixtures\\OwnedFile' => ['graph' => [
                'label' => 'File',
                'properties' => ['id', 'team_id', 'parent_type', 'parent_id', 'user_id', 'union_id', 'name'],
                'relationships' => [
                    ['type' => 'PARENT', 'target_label' => '_polymorphic', 'foreign_key' => 'parent_id', 'direction' => 'outgoing'],
                    ['type' => 'FOLDERS', 'target_label' => 'Folder', 'pivot' => 'file_folder'],
                    ['type' => 'BELONGS_TO_TEAM', 'target_label' => 'Team', 'foreign_key' => 'team_id', 'direction' => 'outgoing'],
                    ['type' => 'BELONGS_TO_UNION', 'target_label' => 'Union', 'foreign_key' => 'union_id', 'direction' => 'outgoing'],
                    ['type' => 'BELONGS_TO_USER', 'target_label' => 'User', 'foreign_key' => 'user_id', 'direction' => 'outgoing'],
                ],
            ]],
            'Tests\\Fixtures\\OwnedUnit' => ['graph' => [
                'label' => 'Unit',
                'properties' => ['id', 'name', 'union_id', 'team_id'],
                'relationships' => [
                    ['type' => 'BELONGS_TO_UNION', 'target_label' => 'Union', 'foreign_key' => 'union_id', 'direction' => 'outgoing'],
                    ['type' => 'BELONGS_TO_TEAM', 'target_label' => 'Team', 'foreign_key' => 'team_id', 'direction' => 'outgoing'],
                    ['type' => 'HAS_FILES', 'target_label' => 'File', 'foreign_key' => 'parent_id', 'morph' => 'parent'],
                ],
            ]],
            'Tests\\Fixtures\\OwnedUnion' => ['graph' => [
                'label' => 'Union',
                'properties' => ['id', 'name', 'team_id'],
                'relationships' => [
                    ['type' => 'BELONGS_TO_TEAM', 'target_label' => 'Team', 'foreign_key' => 'team_id', 'direction' => 'outgoing'],
                ],
            ]],
            'Tests\\Fixtures\\OwnedDiscussion' => ['graph' => [
                'label' => 'Discussion',
                'properties' => ['id', 'subject', 'team_id'],
                'relationships' => [
                    ['type' => 'BELONGS_TO_TEAM', 'target_label' => 'Team', 'foreign_key' => 'team_id', 'direction' => 'outgoing'],
                ],
            ]],
            'Tests\\Fixtures\\OwnedTeam' => ['graph' => ['label' => 'Team', 'properties' => ['id', 'name'], 'relationships' => []]],
            'Tests\\Fixtures\\OwnedUser' => ['graph' => ['label' => 'User', 'properties' => ['id', 'name'], 'relationships' => []]],
        ]]);

        Relation::morphMap([
            'unit' => 'Tests\\Fixtures\\OwnedUnit',
            'union' => 'Tests\\Fixtures\\OwnedUnion',
            'discussion' => 'Tests\\Fixtures\\OwnedDiscussion',
        ]);

        $this->rows = [
            'Unit' => [309 => ['id' => 309, 'union_id' => 14, 'team_id' => 1]],
            'Union' => [14 => ['id' => 14, 'team_id' => 1]],
            'Discussion' => [57 => ['id' => 57, 'team_id' => 1]],
        ];
    }

    public function tearDown(): void
    {
        Relation::morphMap([], false);
        parent::tearDown();
    }

    private function resolver(): ConfigFileOwnerResolver
    {
        return new ConfigFileOwnerResolver(new RecordAncestry(function (string $label, int|string $id): ?array {
            $this->lookups[] = "{$label}:{$id}";

            return $this->rows[$label][$id] ?? null;
        }));
    }

    private function file(array $attributes): object
    {
        return (object) ($attributes + ['id' => 1, 'name' => 'a.pdf', 'path' => 'a.pdf', 'team_id' => 1, 'user_id' => 9]);
    }

    /** @test */
    public function a_file_filed_on_a_unit_is_owned_by_the_unit_and_its_building_is_an_ancestor(): void
    {
        $link = $this->resolver()->ownerOf($this->file(['parent_type' => 'unit', 'parent_id' => 309, 'union_id' => 14]));

        $this->assertSame(['owner' => 'unit:309', 'ancestors' => ['union:14']], $link);
        $this->assertSame(['Unit:309'], $this->lookups, 'one lookup per file: the parent row');
    }

    /** @test */
    public function the_parents_own_ancestors_are_found_when_the_file_does_not_carry_them(): void
    {
        $link = $this->resolver()->ownerOf($this->file(['parent_type' => 'unit', 'parent_id' => 309, 'union_id' => null]));

        $this->assertSame(['owner' => 'unit:309', 'ancestors' => ['union:14']], $link);
    }

    /** @test */
    public function ancestors_are_ordered_most_specific_first_whatever_the_declaration_order(): void
    {
        // A unit of a wing of a building: the unit declares its union BEFORE its
        // wing, and the wing itself belongs to the union.
        $entities = config('entities');
        $entities['Tests\\Fixtures\\OwnedUnit']['graph']['properties'][] = 'wing_id';
        $entities['Tests\\Fixtures\\OwnedUnit']['graph']['relationships'][] =
            ['type' => 'BELONGS_TO_WING', 'target_label' => 'Wing', 'foreign_key' => 'wing_id', 'direction' => 'outgoing'];
        $entities['Tests\\Fixtures\\OwnedWing'] = ['graph' => [
            'label' => 'Wing',
            'properties' => ['id', 'name', 'union_id'],
            'relationships' => [
                ['type' => 'BELONGS_TO_UNION', 'target_label' => 'Union', 'foreign_key' => 'union_id', 'direction' => 'outgoing'],
            ],
        ]];
        config(['entities' => $entities]);
        $this->rows['Unit'][309]['wing_id'] = 4;
        $this->rows['Wing'][4] = ['id' => 4, 'union_id' => 14];

        $link = $this->resolver()->ownerOf($this->file(['parent_type' => 'unit', 'parent_id' => 309, 'union_id' => 14]));

        $this->assertSame(['owner' => 'unit:309', 'ancestors' => ['wing:4', 'union:14']], $link);
    }

    /** @test */
    public function a_file_filed_on_the_building_itself_has_no_ancestor(): void
    {
        $link = $this->resolver()->ownerOf($this->file(['parent_type' => 'union', 'parent_id' => 14, 'union_id' => 14]));

        $this->assertSame(['owner' => 'union:14', 'ancestors' => []], $link);
    }

    /** @test */
    public function a_file_with_no_parent_but_a_building_is_owned_by_the_building(): void
    {
        $link = $this->resolver()->ownerOf($this->file(['parent_type' => null, 'parent_id' => null, 'union_id' => 14]));

        $this->assertSame(['owner' => 'union:14', 'ancestors' => []], $link);
        $this->assertSame([], $this->lookups, 'nothing to look up: the file carries its only link');
    }

    /** @test */
    public function a_file_that_belongs_to_no_record_has_no_owner(): void
    {
        $link = $this->resolver()->ownerOf($this->file(['parent_type' => null, 'parent_id' => null, 'union_id' => null]));

        $this->assertSame(['owner' => null, 'ancestors' => []], $link);
    }

    /** @test */
    public function a_parent_without_a_building_keeps_its_own_key(): void
    {
        $link = $this->resolver()->ownerOf($this->file(['parent_type' => 'discussion', 'parent_id' => 57, 'union_id' => null]));

        $this->assertSame(['owner' => 'discussion:57', 'ancestors' => []], $link);
    }

    /** @test */
    public function identity_labels_are_never_a_key(): void
    {
        $link = $this->resolver()->ownerOf($this->file(['parent_type' => 'unit', 'parent_id' => 309, 'union_id' => 14]));

        foreach (array_merge([(string) $link['owner']], $link['ancestors']) as $key) {
            $this->assertStringStartsNotWith('team:', $key);
            $this->assertStringStartsNotWith('user:', $key);
        }
    }

    /** @test */
    public function a_morph_type_that_names_no_configured_entity_falls_back_to_the_declared_belongs_to(): void
    {
        $link = $this->resolver()->ownerOf($this->file(['parent_type' => 'import', 'parent_id' => 3, 'union_id' => 14]));

        $this->assertSame(['owner' => 'union:14', 'ancestors' => []], $link);
    }

    /** @test */
    public function an_eloquent_like_object_is_read_through_its_attributes(): void
    {
        $file = new class {
            public int $id = 5;
            public function getAttributes(): array
            {
                return ['id' => 5, 'parent_type' => 'unit', 'parent_id' => 309, 'union_id' => 14, 'team_id' => 1];
            }
        };

        $this->assertSame(['owner' => 'unit:309', 'ancestors' => ['union:14']], $this->resolver()->ownerOf($file));
    }

    /** @test */
    public function the_ancestors_of_a_record_are_available_on_their_own(): void
    {
        $ancestry = new RecordAncestry(fn (string $label, int|string $id): ?array => $this->rows[$label][$id] ?? null);

        $this->assertSame(['union:14'], $ancestry->ancestorsOf('Unit', 309));
        $this->assertSame([], $ancestry->ancestorsOf('Union', 14));
        $this->assertSame([], $ancestry->ancestorsOf('Unit', 999), 'an unknown record has no ancestors');
        $this->assertSame('Unit', $ancestry->labelOfKey('unit:309'));
        $this->assertNull($ancestry->labelOfKey('nothing:1'));
    }

    /** @test */
    public function the_dedupe_key_is_the_normalised_text_within_one_owner(): void
    {
        $a = FileLink::dedupeKey("Article 5\n  The private   portions.", 'unit:309');
        $b = FileLink::dedupeKey('Article 5 The private portions.', 'unit:309');
        $c = FileLink::dedupeKey('Article 5 The private portions.', 'unit:310');
        $d = FileLink::dedupeKey('Article 5 The private portions.', null);

        $this->assertSame($a, $b, 'whitespace does not make a text another text');
        $this->assertNotSame($a, $c, 'identical text under another owner stays separate');
        $this->assertNotSame($a, $d);
        $this->assertSame(
            ['owner' => 'unit:309', 'ancestors' => ['union:14'], 'dedupe' => $a],
            FileLink::payload(['owner' => 'unit:309', 'ancestors' => ['union:14']], 'Article 5 The private portions.')
        );
    }
}
