<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Fixtures;

/**
 * Fixture for the schema-card tests: a small condo-like domain with FIVE selected
 * labels (Contact, Unit, ContactUnit, Invoice, Team) and unselected labels (Bill,
 * Supplier, Union, File, User) whose edges must not leak into the cards unless the
 * selected label's own config declares them.
 */
final class SchemaCardFixture
{
    /** The five selected labels, in selection order. */
    public const SELECTED = ['Contact', 'Unit', 'ContactUnit', 'Invoice', 'Team'];

    public static function entities(): array
    {
        return [
            'App\\Models\\Contact' => [
                'graph' => [
                    'label' => 'Contact',
                    'properties' => ['id', 'first_name', 'last_name', 'email', 'team_id', 'user_id', 'created_at'],
                    'relationships' => [
                        [
                            'type' => 'CONTACT_UNITS',
                            'target_label' => 'ContactUnit',
                            'direction' => 'outgoing',
                            'description' => 'All unit links for this contact (current and sold). Includes sold ones.',
                        ],
                        [
                            'type' => 'BELONGS_TO_TEAM',
                            'target_label' => 'Team',
                            'foreign_key' => 'team_id',
                            'inferred' => true,
                        ],
                        [
                            'type' => 'CURRENT_USER',
                            'target_label' => 'User',
                            'foreign_key' => 'user_id',
                            'direction' => 'outgoing',
                        ],
                    ],
                ],
                'metadata' => [
                    'description' => "A person or organization known to the condo:\n  owners, renters, suppliers' contacts.",
                    'aliases' => ['contact', 'contacts', 'person', 'people', 'resident'],
                    'common_properties' => [
                        'id' => 'Unique identifier',
                        'created_at' => 'Creation timestamp',
                        'team_id' => 'Foreign key to Team',
                        'first_name' => 'First name',
                        'last_name' => 'Last name',
                        'email' => 'Primary email address used for notices',
                    ],
                ],
            ],
            'App\\Models\\Unit' => [
                'graph' => [
                    'label' => 'Unit',
                    'properties' => ['id', 'name', 'union_id', 'team_id', 'created_at'],
                    'relationships' => [
                        [
                            'type' => 'BELONGS_TO_UNION',
                            'target_label' => 'Union',
                            'foreign_key' => 'union_id',
                            'direction' => 'outgoing',
                        ],
                    ],
                ],
                'metadata' => [
                    'description' => 'A condo unit (apartment, parking, locker).',
                    'aliases' => ['unit', 'units', 'apartment'],
                    'common_properties' => [
                        'id' => 'Unique identifier',
                        'created_at' => 'Creation timestamp',
                        'name' => 'Unit number as shown to owners (e.g. 310)',
                    ],
                ],
            ],
            'App\\Models\\ContactUnit' => [
                'graph' => [
                    'label' => 'ContactUnit',
                    'properties' => ['id', 'contact_id', 'unit_id', 'link_id', 'sold_at', 'created_at'],
                    'relationships' => [
                        [
                            'type' => 'UNIT',
                            'target_label' => 'Unit',
                            'foreign_key' => 'unit_id',
                            'direction' => 'outgoing',
                        ],
                    ],
                ],
                'metadata' => [
                    'description' => 'Pivot linking a Contact to a Unit with an occupancy role.',
                    'aliases' => ['unit link', 'occupancy'],
                    'common_properties' => [
                        'created_at' => 'Creation timestamp',
                        'link_id' => 'Integer enum for occupancy role: 1=Owner-Occupying, 2=Owner-Not-Occupying, 3=Renter, 4=Occupant. NOT a foreign key.',
                        'sold_at' => 'Sale date; NULL means the link is current.',
                    ],
                ],
            ],
            'App\\Models\\Invoice' => [
                'graph' => [
                    'label' => 'Invoice',
                    'properties' => ['id', 'status', 'due_at', 'calc_due_amount', 'customer_type', 'customer_id', 'team_id', 'created_at'],
                    'relationships' => [
                        [
                            'type' => 'CUSTOMER',
                            'target_label' => '_polymorphic',
                            'foreign_key' => 'customer_id',
                            'direction' => 'outgoing',
                        ],
                        [
                            'type' => 'BELONGS_TO_TEAM',
                            'target_label' => 'Team',
                            'foreign_key' => 'team_id',
                            'inferred' => true,
                        ],
                    ],
                ],
                'metadata' => [
                    'description' => 'A condo fee invoice issued to a unit owner or contact. NOT a supplier bill.',
                    'aliases' => [
                        'invoice', 'invoices', 'facture client', 'cotisation', 'cotisations', 'contribution',
                        'condo fee', 'condo fees', 'assessment', 'assessments', 'statement', 'charges',
                    ],
                    'common_properties' => [
                        'id' => 'Unique identifier',
                        'created_at' => 'Creation timestamp',
                        'team_id' => 'Foreign key to Team',
                        'status' => 'Status (1=Draft, 2=Approved, 3=Sent, 4=Partially Paid, 5=Paid, 10=Voided)',
                        'due_at' => 'Due timestamp',
                        'calc_due_amount' => 'Calculated remaining amount due',
                        'customer_type' => 'Customer type (polymorphic - Contact or Unit)',
                    ],
                ],
            ],
            'App\\Models\\Team' => [
                'graph' => [
                    'label' => 'Team',
                    'properties' => ['id', 'name', 'created_at'],
                    'relationships' => [],
                ],
                'metadata' => [
                    'description' => 'The management team (tenant).',
                    'aliases' => ['team'],
                    'common_properties' => [
                        'id' => 'Unique identifier',
                        'created_at' => 'Creation timestamp',
                        'name' => 'Name',
                    ],
                ],
            ],
            'App\\Models\\Bill' => [
                'graph' => [
                    'label' => 'Bill',
                    'properties' => ['id', 'status', 'created_at'],
                    'relationships' => [
                        ['type' => 'BELONGS_TO_SUPPLIER', 'target_label' => 'Supplier', 'foreign_key' => 'supplier_id'],
                        ['type' => 'BELONGS_TO_TEAM', 'target_label' => 'Team', 'foreign_key' => 'team_id'],
                    ],
                ],
                'metadata' => [
                    'description' => 'A supplier bill.',
                    'common_properties' => [
                        'id' => 'Unique identifier',
                        'created_at' => 'Creation timestamp',
                    ],
                ],
            ],
            'App\\Models\\Supplier' => [
                'graph' => ['label' => 'Supplier', 'properties' => ['id', 'name']],
                'metadata' => ['description' => 'A supplier.'],
            ],
        ];
    }

    /** Every live (Neo4j) relationship pattern, selected or not. */
    public static function allPatterns(): array
    {
        return [
            ['from_label' => 'Contact', 'type' => 'CONTACT_UNITS', 'to_label' => 'ContactUnit', 'count' => 900],
            ['from_label' => 'ContactUnit', 'type' => 'UNIT', 'to_label' => 'Unit', 'count' => 900],
            ['from_label' => 'Contact', 'type' => 'BELONGS_TO_TEAM', 'to_label' => 'Team', 'count' => 1200],
            ['from_label' => 'Invoice', 'type' => 'BELONGS_TO_TEAM', 'to_label' => 'Team', 'count' => 6000],
            ['from_label' => 'Invoice', 'type' => 'CUSTOMER', 'to_label' => 'Unit', 'count' => 5000],
            ['from_label' => 'Invoice', 'type' => 'CUSTOMER', 'to_label' => 'Contact', 'count' => 900],
            ['from_label' => 'Unit', 'type' => 'BELONGS_TO_UNION', 'to_label' => 'Union', 'count' => 1800],
            ['from_label' => 'Unit', 'type' => 'HAS_FILES', 'to_label' => 'File', 'count' => 40],
            ['from_label' => 'Bill', 'type' => 'BELONGS_TO_SUPPLIER', 'to_label' => 'Supplier', 'count' => 300],
            ['from_label' => 'Bill', 'type' => 'BELONGS_TO_TEAM', 'to_label' => 'Team', 'count' => 300],
        ];
    }

    public static function propertyTypes(): array
    {
        return [
            'Contact' => ['id' => 'Integer', 'first_name' => 'String', 'last_name' => 'String', 'email' => 'String', 'team_id' => 'Integer', 'user_id' => 'Integer', 'created_at' => 'String(ISO date)'],
            'Unit' => ['id' => 'Integer', 'name' => 'String', 'union_id' => 'Integer', 'team_id' => 'Integer', 'created_at' => 'String(ISO date)'],
            'ContactUnit' => ['id' => 'Integer', 'contact_id' => 'Integer', 'unit_id' => 'Integer', 'link_id' => 'Integer', 'sold_at' => 'String(ISO date)', 'created_at' => 'String(ISO date)'],
            'Invoice' => ['id' => 'Integer', 'status' => 'String(numeric)', 'due_at' => 'String(ISO date)', 'calc_due_amount' => 'Float', 'customer_type' => 'String', 'customer_id' => 'Integer', 'team_id' => 'Integer', 'created_at' => 'String(ISO date)'],
            'Team' => ['id' => 'Integer', 'name' => 'String', 'created_at' => 'String(ISO date)'],
        ];
    }

    /**
     * The projected schema exactly as ContextRetriever hands it to the prompt:
     * selected labels, both-ends-selected patterns, config properties per label,
     * plus the FULL pattern/type evidence and the sampled property types.
     */
    public static function schema(): array
    {
        $selected = array_fill_keys(self::SELECTED, true);
        $patterns = array_values(array_filter(
            self::allPatterns(),
            fn (array $p) => isset($selected[$p['from_label']]) && isset($selected[$p['to_label']])
        ));

        $properties = [];
        foreach (self::entities() as $config) {
            $label = $config['graph']['label'];
            if (isset($selected[$label])) {
                $properties[$label] = $config['graph']['properties'];
            }
        }

        $allTypes = array_values(array_unique(array_column(self::allPatterns(), 'type')));

        return [
            'labels' => self::SELECTED,
            'relationships' => array_values(array_unique(array_column($patterns, 'type'))),
            'relationshipPatterns' => $patterns,
            'properties' => $properties,
            'propertyKeys' => array_values(array_unique(array_merge(...array_values($properties)))),
            'all_relationship_types' => $allTypes,
            'all_relationship_patterns' => self::allPatterns(),
            'property_types' => self::propertyTypes(),
        ];
    }

    /** Entity metadata as EntityMetadataBuilder::fromSemanticSelection produces it. */
    public static function entityMetadata(): array
    {
        $detected = [];
        $metadata = [];
        foreach (self::entities() as $fqcn => $config) {
            if (in_array($config['graph']['label'], self::SELECTED, true)) {
                $detected[] = $fqcn;
                $metadata[$fqcn] = $config['metadata'] ?? [];
            }
        }

        return ['detected_entities' => $detected, 'entity_metadata' => $metadata, 'detected_scopes' => []];
    }
}
