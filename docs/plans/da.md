  ┌─────────────────────────────────────────────────────────────────────────────────────────┐
  │                              USER ASKS QUESTION                                          │
  │                    "How many employees are named Ezequiel?"                              │
  └─────────────────────────────────────────────────────────────────────────────────────────┘
                                          │
                                          ▼
  ┌─────────────────────────────────────────────────────────────────────────────────────────┐
  │  LAYER 1: SEMANTIC CONTEXT SELECTION                                                    │
  │  Identifies entities: [Employee]                                                        │
  └─────────────────────────────────────────────────────────────────────────────────────────┘
                                          │
                                          ▼
  ┌─────────────────────────────────────────────────────────────────────────────────────────┐
  │  LAYER 2: QUICK ACCESS GATE (Fail-fast only)                                            │
  │  ─────────────────────────────────────────────────────────────────────────────────────  │
  │                                                                                         │
  │  Question: "Does user have ANY permission for Employee on ANY team?"                    │
  │                                                                                         │
  │  $teamIds = $user->getTeamsIdsWithPermission('Employee', READ);                        │
  │  if ($teamIds->isEmpty()) → DENY (user has zero team access to this entity)            │
  │                                                                                         │
  │  Also determines access level:                                                          │
  │  • Has Employee.AiRetrieving on any team? → RETRIEVE                                   │
  │  • Has Employee.AiCount on any team? → COUNT                                           │
  │  • Has Employee (base) on any team? → RETRIEVE (fallback)                              │
  │                                                                                         │
  │  This is just optimization - real filtering happens in Layer 4                         │
  └─────────────────────────────────────────────────────────────────────────────────────────┘
                                          │
                                          ▼
  ┌─────────────────────────────────────────────────────────────────────────────────────────┐
  │  LAYER 3: QUERY GENERATION + EXECUTION                                                  │
  │  ─────────────────────────────────────────────────────────────────────────────────────  │
  │                                                                                         │
  │  Generate Cypher → Execute on Neo4j                                                     │
  │                                                                                         │
  │  Raw results (UNFILTERED - may include records from other teams):                       │
  │  [                                                                                      │
  │      ['id' => 101, 'name' => 'Ezequiel', 'team_id' => 1],  // User's team              │
  │      ['id' => 102, 'name' => 'Ezequiel', 'team_id' => 2],  // OTHER team              │
  │      ['id' => 103, 'name' => 'Ezequiel', 'team_id' => 1],  // User's team              │
  │  ]                                                                                      │
  └─────────────────────────────────────────────────────────────────────────────────────────┘
                                          │
                                          ▼
  ┌─────────────────────────────────────────────────────────────────────────────────────────┐
  │  LAYER 4: ELOQUENT SECURITY FILTER (THE REAL SECURITY)                                  │
  │  ─────────────────────────────────────────────────────────────────────────────────────  │
  │                                                                                         │
  │  $ids = [101, 102, 103];                                                               │
  │  $accessible = Employee::whereIn('id', $ids)->get();                                   │
  │                                                                                         │
  │  HasSecurity global scope automatically adds:                                           │
  │  WHERE team_id IN (1)  // Only user's accessible teams                                 │
  │                                                                                         │
  │  Result: Collection with IDs [101, 103] only                                           │
  │  (ID 102 filtered out - belongs to team user can't access)                             │
  │                                                                                         │
  │  sensibleColumns also hides sensitive fields automatically                             │
  └─────────────────────────────────────────────────────────────────────────────────────────┘
                                          │
                                          ▼
  ┌─────────────────────────────────────────────────────────────────────────────────────────┐
  │  LAYER 5: COUNT INFERENCE PROTECTION (NEW)                                              │
  │  ─────────────────────────────────────────────────────────────────────────────────────  │
  │                                                                                         │
  │  $accessLevel = AiAccessLevel::COUNT;  // From Layer 2                                 │
  │  $count = $accessible->count();         // 2 employees                                 │
  │  $threshold = config('ai.security.count_inference_threshold', 5);                      │
  │                                                                                         │
  │  DECISION MATRIX:                                                                       │
  │  ┌─────────────────────────────────────────────────────────────────────────────────┐   │
  │  │  Access Level  │  Count >= Threshold  │  Count < Threshold                      │   │
  │  ├─────────────────────────────────────────────────────────────────────────────────┤   │
  │  │  RETRIEVE      │  Return full data    │  Return full data                       │   │
  │  │  COUNT         │  Return count only   │  Return "Some results found" (no count) │   │
  │  │  NONE          │  Block entirely      │  Block entirely                         │   │
  │  └─────────────────────────────────────────────────────────────────────────────────┘   │
  │                                                                                         │
  │  In this case: COUNT access + count(2) < threshold(5)                                  │
  │  → Response: "Some employees matching your criteria were found."                       │
  │  → No exact count exposed (prevents inference attack)                                  │
  │                                                                                         │
  │  INFERENCE ATTACK BLOCKED:                                                             │
  │  ✗ "How many employees named Ezequiel born in 1999?" → "Some found" (not "1")         │
  │  ✗ Attacker can't narrow down to individual records                                   │
  └─────────────────────────────────────────────────────────────────────────────────────────┘