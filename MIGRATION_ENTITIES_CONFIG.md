# Migration Guide: Consolidated Entity Configuration

## What Changed?

We've consolidated all config files into a single, well-documented `config/entities.php` file.

### Before (Confusing!)
```
config/
├── entities.php                              (which one to use?)
├── entities-semantic.example.php             (is this better?)
├── entities-with-relationship-patterns.example.php  (do I need this?)
└── ai-patterns.example.php                   (what about this?)
```

### After (Clear!)
```
config/
└── entities.php  (ONE file with everything!)
```

## What You Need to Do

### If You Were Using `entities.php`

**Good news:** Your existing configuration still works! We've just cleaned up the file to:
- Add comprehensive inline documentation
- Include all examples as commented code
- Organize by complexity (simple to advanced)
- Add "Getting Started" section at the top

**Action:** Review the new format and consider simplifying your config using the examples.

### If You Were Using an Example File

**Action:** Copy your customizations from the example file into the new `config/entities.php`.

1. Open your old example file (e.g., `entities-semantic.example.php`)
2. Find your custom entity configurations
3. Copy them to the bottom of the new `config/entities.php`
4. Delete the old example file

## Key Improvements

### 1. Clear Getting Started Section

The top of the file now explains:
- 90% of use cases need zero configuration
- Just use the `Nodeable` interface
- Auto-discovery handles most scenarios

### 2. Inline Examples

Instead of separate example files, all examples are now in the main file as comments:

```php
// Example: Uncomment to use
// 'Order' => [
//     'graph' => [
//         'label' => 'Order',
//         'properties' => ['id', 'total', 'status'],
//     ],
//     'metadata' => [
//         'aliases' => ['order', 'orders', 'purchase'],
//         'scopes' => [
//             'pending' => [
//                 'description' => 'Orders awaiting processing',
//                 'examples' => ['Show pending orders'],
//             ],
//         ],
//     ],
// ],
```

### 3. Organized by Complexity

Examples progress from simple to advanced:
1. **Example 1:** Simple property filters (status, type)
2. **Example 2:** Relationship traversal (graph patterns)
3. **Example 3:** File entity (dual storage)
4. **Example 4:** Graph-only entity (no vector search)

### 4. Comprehensive Reference Sections

- **Common Patterns Reference** - All 8 pattern types explained
- **Scope Specification Types** - When to use each type
- **Best Practices** - Do's and don'ts
- **Testing Guide** - How to verify your config
- **Documentation Links** - Where to find more help

## Migration Examples

### Example 1: Simple Entity

**Before:** Had to look at `entities-semantic.example.php` to understand

**After:** Just uncomment and customize:

```php
'Order' => [
    'graph' => [
        'label' => 'Order',
        'properties' => ['id', 'total', 'status'],
    ],
    'metadata' => [
        'aliases' => ['order', 'purchase'],
        'scopes' => [
            'pending' => [
                'description' => 'Orders awaiting processing',
                'filter' => ['status' => 'pending'],
                'examples' => ['Show pending orders'],
            ],
        ],
    ],
],
```

### Example 2: Relationship-Based Scope

**Before:** Had to study `entities-with-relationship-patterns.example.php`

**After:** Full example in main file with detailed comments:

```php
'Person' => [
    'metadata' => [
        'scopes' => [
            'volunteers' => [
                'specification_type' => 'relationship_traversal',
                'concept' => 'People who volunteer their time on teams',
                'relationship_spec' => [
                    'start_entity' => 'Person',
                    'path' => [
                        [
                            'relationship' => 'HAS_ROLE',
                            'target_entity' => 'PersonTeam',
                            'direction' => 'outgoing',
                        ],
                    ],
                    'filter' => [
                        'entity' => 'PersonTeam',
                        'property' => 'role_type',
                        'operator' => 'equals',
                        'value' => 'volunteer',
                    ],
                    'return_distinct' => true,
                ],
                'examples' => ['Show me all volunteers'],
            ],
        ],
    ],
],
```

### Example 3: Pattern Library Usage

**Before:** Had to reference `ai-patterns.example.php`

**After:** Pattern reference built into main file + example:

```php
'Person' => [
    'metadata' => [
        'scopes' => [
            'people_without_teams' => [
                'specification_type' => 'pattern',
                'pattern' => 'entity_without_relationship',
                'pattern_params' => [
                    'entity' => 'Person',
                    'relationship' => 'MEMBER_OF',
                    'target_entity' => 'Team',
                ],
                'examples' => ['Show people without teams'],
            ],
        ],
    ],
],
```

## Files to Delete

After migrating your custom configurations, you can safely delete:

```bash
# Delete example files
rm config/entities-semantic.example.php
rm config/entities-with-relationship-patterns.example.php
rm config/ai-patterns.example.php
```

## Backwards Compatibility

The new format is **100% backwards compatible**. All existing configurations work without changes.

## Need Help?

1. **Documentation:** See inline comments in `config/entities.php`
2. **Quick Start:** Read the "Getting Started" section at the top
3. **Examples:** Uncomment and customize the examples
4. **Full Docs:** See `docs/ENTITY_METADATA_QUICKSTART.md`
5. **Test:** Run `php examples/EntityMetadataDemo.php`

## Questions?

If you're unsure about migrating, here's the simplest approach:

1. Keep your current config as-is (it still works!)
2. Read through the new `config/entities.php` at your leisure
3. Gradually adopt the cleaner format when you need to add new entities

No rush! The old format works perfectly.
