# Entity Actions

Configure clickable action links that the AI can generate in responses.

---

## Overview

Entity actions allow the AI to generate interactive links in responses that trigger specific functionality when clicked. These links can open profiles, modals, navigate to pages, or perform any Kompo-based action.

**Two types of actions:**

| Type | Description | Link Format |
|------|-------------|-------------|
| **Entity Actions** | Tied to specific entity types (Person, Order, etc.) | `[text](entity://Type/id/action)` |
| **Generic Actions** | App-wide navigation not tied to entities | `[text](action://action_key)` |

**Example interaction:**

```
User: "Show me John's profile link"
AI: "Here's the profile for [John Smith](entity://Person/152/profile)"
```

When rendered, "John Smith" becomes a clickable link that opens John's profile.

---

## Entity Actions

Entity actions are tied to specific entity types and require an entity ID. Use these for actions that operate on a specific record.

### Configuration

In `config/ai.php`:

```php
'entity_actions' => [
    'Person' => [
        'profile' => [
            'action' => fn($id, $text = 'View Profile') => _Link($text)->href(route('people.show', $id)),
            'aliases' => ['profile link', 'profile page', 'profile', 'view person'],
            'label' => 'View Profile',
        ],
        'quick_view' => [
            'action' => fn($id, $text = 'Quick View') => _Link($text)->selfGet('personModal', ['id' => $id])->inModal(),
            'aliases' => ['quick view', 'preview', 'details'],
            'label' => 'Quick View',
        ],
    ],
    'Order' => [
        'details' => [
            'action' => fn($id, $text = 'View Order') => _Link($text)->href(route('orders.show', $id)),
            'aliases' => ['order details', 'view order', 'order page'],
            'label' => 'View Order',
        ],
        'invoice' => [
            'action' => fn($id, $text = 'Download Invoice') => _Link($text)->href(route('orders.invoice', $id)),
            'aliases' => ['invoice', 'download invoice', 'get invoice'],
            'label' => 'Download Invoice',
        ],
    ],
],
```

### Configuration Structure

Each entity action requires:

| Key | Type | Description |
|-----|------|-------------|
| `action` | Closure | Function that creates a Kompo element. Receives `$id` and optional `$text`. |
| `aliases` | array | Natural language phrases users might say to trigger this action. |
| `label` | string | Display label for AI context (optional, defaults to action key). |

### Action Closure Parameters

The action closure receives two parameters:

```php
'action' => fn($id, $text = 'Default Text') => _Link($text)->href(route('page', $id)),
```

- **`$id`**: The entity ID extracted from the AI-generated link
- **`$text`**: The display text from the markdown link (e.g., "John Smith" from `[John Smith](entity://...)`)

### Common Action Patterns

**Simple navigation:**
```php
'view' => [
    'action' => fn($id, $text = 'View') => _Link($text)->href(route('customers.show', $id)),
    'aliases' => ['view', 'show', 'open'],
    'label' => 'View Customer',
],
```

**Modal popup:**
```php
'preview' => [
    'action' => fn($id, $text = 'Preview') => _Link($text)->selfGet('customerPreviewModal', ['id' => $id])->inModal(),
    'aliases' => ['preview', 'quick look', 'popup'],
    'label' => 'Quick Preview',
],
```

**Drawer/panel:**
```php
'edit' => [
    'action' => fn($id, $text = 'Edit') => _Link($text)->selfGet('customerEditPanel', ['id' => $id])->inDrawer(),
    'aliases' => ['edit', 'modify', 'change'],
    'label' => 'Edit Customer',
],
```

**External link:**
```php
'website' => [
    'action' => fn($id, $text = 'Visit Website') => _Link($text)->href(Customer::find($id)?->website)->target('_blank'),
    'aliases' => ['website', 'homepage', 'visit site'],
    'label' => 'Visit Website',
],
```

---

## Generic Actions

Generic actions are not tied to specific entities. Use these for app-wide navigation like settings, dashboard, or help pages.

### Configuration

In `config/ai.php`:

```php
'generic_actions' => [
    'settings' => [
        'action' => fn($text = 'Settings') => _Link($text)->href(route('settings.index')),
        'aliases' => ['settings', 'settings page', 'preferences', 'configuration'],
        'label' => 'Settings',
    ],
    'dashboard' => [
        'action' => fn($text = 'Dashboard') => _Link($text)->href(route('dashboard')),
        'aliases' => ['dashboard', 'home', 'main page', 'home page'],
        'label' => 'Dashboard',
    ],
    'help' => [
        'action' => fn($text = 'Help') => _Link($text)->href(route('help.index')),
        'aliases' => ['help', 'help page', 'documentation', 'docs', 'support'],
        'label' => 'Help Center',
    ],
    'new_customer' => [
        'action' => fn($text = 'Create Customer') => _Link($text)->selfGet('createCustomerModal')->inModal(),
        'aliases' => ['new customer', 'create customer', 'add customer'],
        'label' => 'Create Customer',
    ],
],
```

### Action Closure Parameters

Generic actions only receive the text parameter:

```php
'action' => fn($text = 'Default Text') => _Link($text)->href(route('page')),
```

- **`$text`**: The display text from the markdown link

---

## How It Works

The entity actions system involves three key components that work together:

### 1. AI Learns Available Actions

The `EntityActionAwarenessSection` informs the AI during query generation about:

- Available action aliases (so AI knows "profile link" is an action, not a database field)
- When to skip queries (if entity IDs are already in conversation context)
- Available entity IDs from previous results

This prevents the AI from trying to query for "profile_link" as a database column.

### 2. AI Generates Action Links

When responding, the `ResponseEntityActionsSection` teaches the AI:

- The exact link format to use: `[text](entity://Type/id/action)`
- Available actions for each entity type
- Entity IDs from query results or conversation context

The AI then includes properly formatted links in its response.

### 3. Links are Processed

The `ActionLinkHandler` processes the AI's response:

1. Finds all `entity://` and `action://` links using regex patterns
2. Extracts the entity type, ID, and action key
3. Looks up the configured resolver from `config/ai.php`
4. Executes the closure to create Kompo elements
5. Returns elements that get rendered as clickable links

**Link format examples:**
```
Entity action: [John Smith](entity://Person/152/profile)
                          └── Type ─┘ └id┘ └action┘

Generic action: [Go to Settings](action://settings)
                                        └─ action_key ─┘
```

---

## Aliases Best Practices

Aliases help the AI understand when users are requesting actions. Good aliases:

### Be Comprehensive

Include all natural ways users might request the action:

```php
'aliases' => [
    'profile',           // Single word
    'profile link',      // With "link"
    'profile page',      // With "page"
    'view profile',      // Verb form
    'show profile',      // Alternative verb
    'person profile',    // With entity name
],
```

### Be Specific

Avoid overly generic aliases that might conflict:

```php
// Good - specific
'aliases' => ['customer profile', 'client profile', 'view customer'],

// Avoid - too generic (might conflict with other entities)
'aliases' => ['profile', 'view'],
```

### Consider Context

Think about how users naturally phrase requests:

```php
// For a "quick view" modal
'aliases' => [
    'quick view',
    'quick look',
    'preview',
    'popup',
    'modal',
    'details popup',
],
```

---

## Security Considerations

### ID Validation

Action resolvers receive IDs directly from AI output. Always validate:

```php
'profile' => [
    'action' => function($id, $text = 'View') {
        // Validate ID is numeric
        if (!is_numeric($id)) {
            return null;
        }

        return _Link($text)->href(route('people.show', $id));
    },
    'aliases' => ['profile'],
    'label' => 'View Profile',
],
```

### Authorization Checks

For sensitive actions, include authorization in the resolver:

```php
'edit' => [
    'action' => function($id, $text = 'Edit') {
        $person = Person::find($id);

        // Check authorization
        if (!$person || !auth()->user()->can('update', $person)) {
            return null; // Don't render link if unauthorized
        }

        return _Link($text)->href(route('people.edit', $id));
    },
    'aliases' => ['edit', 'modify'],
    'label' => 'Edit Person',
],
```

### Safe Link Rendering

The handler returns `null` for unresolvable links, which prevents broken links from appearing. Invalid entity types, action keys, or failed lookups silently skip rendering.

---

## Integration with Conversation Context

Entity actions work seamlessly with conversation context. When a user asks a follow-up question about entities from previous results, the AI can generate action links without re-querying:

```
User: "Show me active customers"
AI: "Here are 3 active customers: Acme Corp, TechStart, Global Systems"

User: "Give me the profile link for Acme"
AI: "Here's the profile: [Acme Corp](entity://Customer/42/profile)"
```

The `EntityActionAwarenessSection` instructs the AI to return "NO QUERY REQUIRED" when entity IDs are already available in context, and the `ResponseEntityActionsSection` provides those IDs for link generation.

---

## Troubleshooting

### Links Not Appearing

1. **Check configuration**: Ensure entity type matches exactly (case-sensitive)
2. **Verify aliases**: Add more natural language aliases
3. **Check resolver**: Ensure closure doesn't throw exceptions

### Wrong Action Triggered

1. **Review aliases**: Ensure aliases are unique across actions
2. **Be more specific**: Use entity-specific aliases like "customer profile" vs "profile"

### AI Queries Instead of Using Context

1. **Check EntityActionAwarenessSection**: Ensure it's in `query_generator_sections`
2. **Verify conversation context**: Previous results must be stored correctly

### Debug Mode

Enable detailed logging to troubleshoot:

```php
// The handler logs warnings for failed resolutions
\Log::warning('Content link handler failed', [
    'handler' => ActionLinkHandler::class,
    'match' => '[full match]',
    'error' => 'Error message',
]);
```

---

## Complete Example

Here's a full configuration example for a CRM application:

```php
// config/ai.php

'entity_actions' => [
    'Customer' => [
        'profile' => [
            'action' => fn($id, $text = 'View Profile') => _Link($text)
                ->href(route('customers.show', $id))
                ->class('text-blue-600 hover:underline'),
            'aliases' => ['profile', 'customer profile', 'view customer', 'customer page'],
            'label' => 'View Profile',
        ],
        'orders' => [
            'action' => fn($id, $text = 'View Orders') => _Link($text)
                ->href(route('customers.orders', $id)),
            'aliases' => ['orders', 'customer orders', 'order history', 'purchases'],
            'label' => 'View Orders',
        ],
        'contact' => [
            'action' => fn($id, $text = 'Contact') => _Link($text)
                ->selfGet('contactCustomerModal', ['id' => $id])
                ->inModal(),
            'aliases' => ['contact', 'email', 'reach out', 'send message'],
            'label' => 'Contact Customer',
        ],
    ],
    'Order' => [
        'details' => [
            'action' => fn($id, $text = 'View Order') => _Link($text)
                ->href(route('orders.show', $id)),
            'aliases' => ['order details', 'view order', 'order page'],
            'label' => 'View Order',
        ],
        'invoice' => [
            'action' => fn($id, $text = 'Invoice') => _Link($text)
                ->href(route('orders.invoice', $id))
                ->target('_blank'),
            'aliases' => ['invoice', 'download invoice', 'pdf', 'receipt'],
            'label' => 'Download Invoice',
        ],
    ],
],

'generic_actions' => [
    'dashboard' => [
        'action' => fn($text = 'Dashboard') => _Link($text)->href(route('dashboard')),
        'aliases' => ['dashboard', 'home', 'main page'],
        'label' => 'Dashboard',
    ],
    'new_customer' => [
        'action' => fn($text = 'Add Customer') => _Link($text)
            ->selfGet('createCustomerModal')
            ->inModal(),
        'aliases' => ['new customer', 'add customer', 'create customer'],
        'label' => 'Add Customer',
    ],
    'reports' => [
        'action' => fn($text = 'Reports') => _Link($text)->href(route('reports.index')),
        'aliases' => ['reports', 'analytics', 'statistics', 'metrics'],
        'label' => 'Reports',
    ],
],
```

---

## Related Documentation

- [Entity Configuration](/docs/{{version}}/configuration/entities) - Configure entity properties and relationships
- [Response Styles](/docs/{{version}}/configuration/response-styles) - Control response formatting
- [Conversation Context](/docs/{{version}}/chat/conversation-context-management) - How context flows between messages
- [Custom Prompt Sections](/docs/{{version}}/extending/prompt-sections) - Create custom AI prompt sections
