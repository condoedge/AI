# Security

Comprehensive security features to protect against prompt injection, Cypher injection, and unauthorized data access.

---

## Overview

This package implements multiple security layers to protect your AI-powered application:

| Layer | Component | Protection |
|-------|-----------|------------|
| Input | `InputSanitizer` | Prompt injection prevention |
| Query | `CypherSanitizer` | Cypher injection prevention |
| Conversations | `AiConversationPolicy` | Ownership verification |
| Logging | `SensitiveDataSanitizer` | Credential protection |

> **Security Warning**: AI systems are vulnerable to prompt injection attacks where malicious users attempt to override system instructions. This package provides defense-in-depth through multiple security layers.

---

## Input Sanitization

The `InputSanitizer` class detects and mitigates prompt injection attempts in user input.

### How It Works

User input is analyzed against known injection patterns before being processed by the AI:

```php
use Condoedge\Ai\Services\Security\InputSanitizer;

$sanitizer = app(InputSanitizer::class);

// Analyze input for injection risk
$analysis = $sanitizer->analyze($userInput);

if ($analysis['has_injection_risk']) {
    // Handle risky input
    Log::warning('Injection attempt detected', [
        'risk_level' => $analysis['risk_level'],
        'patterns_matched' => count($analysis['patterns_matched']),
    ]);
}

// Sanitize input by removing dangerous patterns
$safeInput = $sanitizer->sanitize($userInput);

// Or do both at once
$result = $sanitizer->process($userInput);
// Returns: original, sanitized, analysis
```

### Blocked Patterns

The sanitizer detects these injection categories:

| Category | Example Patterns | Description |
|----------|------------------|-------------|
| Instruction Override | "ignore previous instructions" | Attempts to bypass system prompts |
| System Impersonation | "SYSTEM:", "[[...]]", "<system>" | Fake system-level messages |
| Role Manipulation | "you are now a...", "pretend to be" | Attempts to change AI behavior |
| Code Block Injection | ` ```instruction...``` ` | Hidden instructions in code blocks |
| Access Bypass | "bypass security", "show restricted" | Attempts to access protected data |

### Risk Levels

The sanitizer calculates a risk level based on matched patterns:

| Level | Patterns Matched | Recommended Action |
|-------|------------------|-------------------|
| `none` | 0 | Process normally |
| `low` | 1 | Log and monitor |
| `medium` | 2-3 | Consider rejection |
| `high` | 4+ | Block the request |

### Adding Custom Patterns

```php
$sanitizer = app(InputSanitizer::class);

// Add custom injection pattern (regex)
$sanitizer->addPattern('/my\s+custom\s+injection\s+pattern/i');
```

---

## Cypher Injection Prevention

The `CypherSanitizer` class prevents Cypher injection attacks when building Neo4j queries.

> **Important**: Neo4j does not support parameterized labels, relationship types, or property keys. These must be validated before interpolation into queries.

### Validation Methods

```php
use Condoedge\Ai\GraphStore\CypherSanitizer;

// Validate a node label
$safeLabel = CypherSanitizer::validateLabel($userInput);

// Validate a relationship type
$safeType = CypherSanitizer::validateRelationshipType($userInput);

// Validate a property key
$safeKey = CypherSanitizer::validatePropertyKey($userInput);

// Validate multiple identifiers
$safeLabels = CypherSanitizer::validateIdentifiers(['Person', 'Team'], 'label');
```

### Escape Methods (Defense-in-Depth)

```php
// Escape with backtick quoting (validates first)
$escapedLabel = CypherSanitizer::escapeLabel($label);
// Returns: `Person`

$escapedType = CypherSanitizer::escapeRelationshipType($type);
// Returns: `WORKS_FOR`
```

### Validation Rules

All identifiers must pass these checks:

1. **Non-empty**: Cannot be blank
2. **Length limit**: Maximum 255 characters (DoS protection)
3. **Pattern match**: Must match `/^[a-zA-Z_][a-zA-Z0-9_]*$/`
4. **Not reserved**: Cannot be Cypher keywords (MATCH, DELETE, CREATE, etc.)

### Reserved Keywords

The following keywords are blocked:

```
ALL, AND, AS, ASC, ASCENDING, BY, CALL, CASE, CONTAINS, CREATE,
DELETE, DESC, DESCENDING, DETACH, DISTINCT, ELSE, END, ENDS,
EXISTS, FALSE, FIELDTERMINATOR, IN, IS, LIMIT, MATCH, MERGE,
NOT, NULL, ON, OPTIONAL, OR, ORDER, REMOVE, RETURN, SET, SKIP,
STARTS, THEN, TRUE, UNION, UNIQUE, UNWIND, WHEN, WHERE, WITH,
XOR, YIELD
```

### Exception Handling

Invalid input throws `CypherInjectionException`:

```php
use Condoedge\Ai\Exceptions\CypherInjectionException;

try {
    $safeLabel = CypherSanitizer::validateLabel($userInput);
} catch (CypherInjectionException $e) {
    Log::warning('Cypher injection attempt', [
        'message' => $e->getMessage(),
        'input' => substr($userInput, 0, 50),
    ]);

    // Return safe error to user
    throw new \InvalidArgumentException('Invalid query parameter');
}
```

---

## Access Control

### Access Levels

The `AccessLevelResolver` implements a five-tier access control system:

| Level | Tag | Description | Requirement |
|-------|-----|-------------|-------------|
| 0 | `global_count` | Total counts across application | Anyone |
| 1 | `team_count` | Counts within user's teams | Team membership |
| 2 | `team_filtered_count` | Filtered counts with threshold | READ permission |
| 3 | `team_details` | Record data (non-sensitive) | READ permission |
| 4 | `team_sensitive` | Record data (including sensitive) | sensibleColumns permission |

### Resolving User Access

```php
use Condoedge\Ai\Services\Security\AccessLevelResolver;

$resolver = app(AccessLevelResolver::class);

// Get access tags for a user and entity
$tags = $resolver->resolveForEntity($user, 'Customer');
// Returns: ['Customer_global_count', 'Customer_team_count', ...]

// Check if user has specific access level
$hasSensitiveAccess = $resolver->hasAccessLevel($user, 'Customer', 'team_sensitive');

// Build complete context for an entity
$context = $resolver->buildContextForEntity($user, 'Customer');
// Returns: entity, access_tags, threshold, sensible_columns, identifying_fields, user_teams
```

### Configuring Thresholds

Thresholds protect against identifying individuals through specific counts:

```php
// config/ai.php
'access_control' => [
    'default_threshold' => 5,
    'thresholds' => [
        'Customer' => 5,
        'Employee' => 10,
        'Transaction' => 3,
    ],
],
```

When a count is below the threshold, the system returns "fewer than N" instead of the exact number.

### Configuring Identifying Fields

Fields that could identify individuals when used in filters:

```php
// config/ai.php
'access_control' => [
    'identifying_fields' => [
        '*' => ['email', 'phone', 'ssn'],  // Apply to all entities
        'Employee' => ['employee_id', 'badge_number'],
        'Customer' => ['customer_id', 'account_number'],
    ],
],
```

---

## Team-Based Filtering

The `TeamFilteredQuery` class enables database-level security filtering for both Neo4j and Qdrant.

### Creating Filtered Queries

```php
use Condoedge\Ai\Services\Security\TeamFilteredQuery;

// Create filter for user's teams
$filter = new TeamFilteredQuery($user->getAccessibleTeamIds());

// Or include owner bypass
$filter = new TeamFilteredQuery($teamIds, $user->id);
```

### Qdrant Vector Search

```php
// Get Qdrant filter structure
$qdrantFilter = $filter->toQdrantFilter();
// Returns filter with team_ids or owner_id matching

// Search with filter applied
$results = $filter->searchQdrant(
    $vectorStore,
    'entities',
    $queryVector,
    limit: 10
);
```

### Neo4j Cypher Queries

```php
// Get WHERE clause for existing query
$whereClause = $filter->toCypherWhereClause('n');
// Returns: (n)-[:BELONGS_TO_TEAM]->(t:Team) WHERE t.id IN $teamIds

// Get complete MATCH clause
$matchClause = $filter->toCypherMatchClause('Person', 'p');
// Returns: MATCH (p:Person)-[:BELONGS_TO_TEAM]->(t:Team) WHERE t.id IN $teamIds

// Count with team filter
$count = $filter->countInNeo4j($graph, 'Person', ['status' => 'active']);
```

### Threshold Protection

```php
$count = $filter->countInNeo4j($graph, 'Employee');

// Apply threshold protection
$safeCount = TeamFilteredQuery::applyThreshold($count, threshold: 5);
// Returns: 3 -> "fewer than 5", 10 -> 10
```

---

## Query Result Filtering

The `QueryResultFilter` provides server-side filtering as defense-in-depth beyond prompt-level access control.

> **Security Note**: Access restrictions in prompts can be bypassed via prompt injection. This adds a second layer of protection at the data level.

### Filtering Results

```php
use Condoedge\Ai\Services\Security\QueryResultFilter;

$filter = app(QueryResultFilter::class);

// Filter query results based on user access
$safeResults = $filter->filterResults($results, 'Employee', $user);
// Removes sensitive columns if user lacks team_sensitive access

// Apply count threshold
$safeCount = $filter->applyCountThreshold($count, 'Employee');
// Returns "fewer than 5" if count is below threshold
```

### How Filtering Works

1. Get user's access context for the entity type
2. Check if user has `team_sensitive` access
3. If not, remove all `sensibleColumns` from each result row
4. Also removes nested versions (e.g., `employee.salary`)

---

## Conversation Security

The `AiConversationPolicy` enforces ownership verification for AI conversations.

### Policy Actions

| Action | Owner | Team Member | Others |
|--------|-------|-------------|--------|
| `view` | Yes | Yes (team conversations) | No |
| `sendMessage` | Yes | Yes (team conversations) | No |
| `delete` | Yes | No | No |

### Using the Policy

```php
// In controllers
public function show(AiConversation $conversation)
{
    $this->authorize('view', $conversation);

    return view('conversation.show', compact('conversation'));
}

public function destroy(AiConversation $conversation)
{
    $this->authorize('delete', $conversation);

    $conversation->delete();
}

// Manual checks
if (Gate::allows('sendMessage', $conversation)) {
    // Send message
}
```

### Team Membership Detection

The policy checks team membership using these methods (in order):

1. `$user->belongsToTeam($teamId)` - Direct method
2. `$user->teams()->where('id', $teamId)->exists()` - Relationship query
3. `$user->currentTeamId() === $teamId` - Current team check

---

## Prompt Context Security

The `PromptContextBuilder` builds access-aware prompt context for RAG queries.

### Building Access-Controlled Prompts

```php
use Condoedge\Ai\Services\Security\PromptContextBuilder;

$builder = new PromptContextBuilder($user);

// Set entity-specific sensitive columns
$builder->setEntitySensibleColumns('Employee', ['salary', 'ssn']);

// Build access section for prompt
$accessSection = $builder->buildAccessSection(['Employee', 'Customer']);

// Build complete context with semantic results
$context = $builder->buildFullContext(
    entities: ['Employee', 'Customer'],
    semanticResults: $searchResults,
    aggregates: ['total_employees' => 150]
);

// Build system prompt with access instructions
$systemPrompt = $builder->buildSystemPrompt(['Employee', 'Customer']);
```

### Generated Prompt Sections

The builder generates structured access instructions:

```
## Data Access for Employee

ALLOWED:
- Employee_global_count: Total counts across entire application
- Employee_team_count: Counts within accessible teams
- Employee_team_filtered_count: Counts with specific filters (threshold applies)
- Employee_team_details: Individual record data (non-sensitive fields)

RESTRICTED (no access to these fields):
- salary, ssn, bank_account
- Do NOT include or reference these fields in responses

COUNT THRESHOLD: 5
- For filtered counts below 5, say "fewer than 5"
- Never reveal exact counts under 5 (prevents identifying individuals)

USER'S TEAMS: IDs 1, 5, 12
```

### Content Redaction

When users lack `team_sensitive` access, sensitive fields are automatically redacted:

```php
// Original content
"John Smith, Salary: $85,000, Department: Engineering"

// After redaction
"John Smith, Salary: [REDACTED], Department: Engineering"
```

---

## Sensitive Data Sanitization

The `SensitiveDataSanitizer` removes credentials from logs and error messages.

### Automatic Credential Detection

```php
use Condoedge\Ai\Services\Security\SensitiveDataSanitizer;

// Sanitize any data for logging
$safeData = SensitiveDataSanitizer::forLogging($data);

// Sanitize a string
$safeString = SensitiveDataSanitizer::sanitizeString($apiResponse);

// Sanitize an array (recursively)
$safeArray = SensitiveDataSanitizer::sanitizeArray($config);

// Sanitize an exception
$safeException = SensitiveDataSanitizer::sanitizeException($e);
```

### Detected Patterns

| Type | Pattern | Replacement |
|------|---------|-------------|
| OpenAI API Keys | `sk-...` (48 chars) | `sk-***REDACTED***` |
| Anthropic Keys | `sk-ant-...` (95+ chars) | `sk-ant-***REDACTED***` |
| AWS Access Keys | `AKIA...` (20 chars) | `AKIA***REDACTED***` |
| Bearer Tokens | `Bearer abc123...` | `Bearer ***REDACTED***` |
| Passwords | `password: secret` | `password: ***REDACTED***` |
| Basic Auth | `Authorization: Basic ...` | `Authorization: Basic ***REDACTED***` |
| URL Credentials | `://user:pass@` | `://***REDACTED***:***REDACTED***@` |

### Sensitive Keys

These array keys are automatically redacted:

```php
api_key, apiKey, password, passwd, pwd, secret, token,
access_token, accessToken, refresh_token, refreshToken,
private_key, privateKey, client_secret, clientSecret,
db_password, dbPassword, redis_password, redisPassword
```

### Safe Logging Example

```php
try {
    $response = $llmClient->chat($messages);
} catch (\Exception $e) {
    Log::error('LLM request failed', SensitiveDataSanitizer::forLogging([
        'exception' => $e,
        'config' => config('ai.llm'),
        'request' => $requestData,
    ]));
}
```

---

## Best Practices

### 1. Defense in Depth

Never rely on a single security layer:

```php
// Layer 1: Input sanitization
$analysis = $sanitizer->analyze($input);
if ($analysis['risk_level'] === 'high') {
    throw new SecurityException('Request blocked');
}

// Layer 2: Query sanitization
$safeLabel = CypherSanitizer::validateLabel($entityType);

// Layer 3: Access control
$accessTags = $resolver->resolveForEntity($user, $entityType);

// Layer 4: Result filtering
$safeResults = $resultFilter->filterResults($results, $entityType, $user);
```

### 2. Log Security Events

```php
if ($analysis['has_injection_risk']) {
    Log::warning('Prompt injection attempt', [
        'user_id' => $user->id,
        'risk_level' => $analysis['risk_level'],
        'ip' => request()->ip(),
    ]);
}
```

### 3. Configure Thresholds Appropriately

```php
// config/ai.php
'access_control' => [
    // Higher threshold for sensitive entities
    'thresholds' => [
        'Employee' => 10,  // HR data
        'Customer' => 5,   // Business data
        'LogEntry' => 1,   // Audit data (no threshold)
    ],
],
```

### 4. Use Team Filtering at Database Level

```php
// Prefer database-level filtering over post-processing
$filter = new TeamFilteredQuery($user->getAccessibleTeamIds());
$results = $filter->searchQdrant($vectorStore, 'entities', $vector);

// Not this (less secure, less efficient)
$results = $vectorStore->search('entities', $vector);
$results = array_filter($results, fn($r) => in_array($r['team_id'], $teamIds));
```

### 5. Always Sanitize Logs

```php
// Always use forLogging() when logging potentially sensitive data
Log::info('API call completed', SensitiveDataSanitizer::forLogging([
    'response' => $response,
    'config' => $config,
]));
```

---

## Configuration Reference

### Environment Variables

```env
# Access control defaults
AI_ACCESS_DEFAULT_THRESHOLD=5

# Input sanitization
AI_SANITIZE_INPUT=true
AI_BLOCK_HIGH_RISK_INPUT=true
```

### Configuration File

```php
// config/ai.php
'security' => [
    'input_sanitization' => [
        'enabled' => true,
        'block_high_risk' => true,
    ],

    'access_control' => [
        'default_threshold' => 5,
        'thresholds' => [
            'Employee' => 10,
        ],
        'identifying_fields' => [
            '*' => ['email', 'phone'],
        ],
    ],
],
```

---

## Related Documentation

- [Resilience & Security](/docs/{{version}}/internals/resilience) - Circuit breakers, retries, and operational resilience
- [Scopes & Business Logic](/docs/{{version}}/advanced/scopes) - Query filters and access patterns
- [Entity Configuration](/docs/{{version}}/configuration/entities) - Entity setup and sensible columns
