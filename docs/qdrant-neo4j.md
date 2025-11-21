# Qdrant + Neo4j Query Cheatsheet

Use this doc as a fast reference for the most common read/write/search patterns. Both systems expose drivers for TypeScript/JavaScript, Python, Go, and more; samples below stick to REST (Qdrant) and Cypher (Neo4j) so you can translate to any SDK quickly.

## Qdrant Query Patterns

- **Similarity Search (top-k vector query)**

```bash
curl -X POST "http://localhost:6333/collections/documents/points/search" \
  -H "Content-Type: application/json" \
  -d '{
        "vector": [0.11, 0.9, 0.22, ...],
        "limit": 5,
        "with_payload": true
      }'
```

- **Filtered Search (metadata + vector)**

```bash
curl -X POST "http://localhost:6333/collections/documents/points/search" \
  -H "Content-Type: application/json" \
  -d '{
        "vector": [0.11, 0.9, 0.22, ...],
        "filter": {
          "must": [
            {"key": "tenant_id", "match": {"value": "acme"}},
            {"key": "doc_type", "match": {"value": "policy"}}
          ]
        },
        "limit": 3
      }'
```

- **Payload-Only Filtering (no vector)**

```bash
curl -X POST "http://localhost:6333/collections/documents/points/scroll" \
  -H "Content-Type: application/json" \
  -d '{
        "filter": {
          "must_not": [
            {"key": "status", "match": {"value": "archived"}}
          ]
        },
        "with_vectors": false,
        "limit": 20
      }'
```

- **Recommendations (items-to-items)**

```bash
curl -X POST "http://localhost:6333/collections/documents/points/recommend" \
  -H "Content-Type: application/json" \
  -d '{
        "positive": ["doc-id-123", "doc-id-999"],
        "negative": ["doc-id-404"],
        "limit": 5,
        "with_payload": true
      }'
```

- **Batch Upsert (vectors + metadata)**

```bash
curl -X PUT "http://localhost:6333/collections/documents/points" \
  -H "Content-Type: application/json" \
  -d '{
        "points": [
          {
            "id": "doc-id-123",
            "vector": [0.7, 0.2, ...],
            "payload": {"tenant_id": "acme", "title": "Plan A"}
          }
        ]
      }'
```

## Neo4j Cypher Patterns

- **Exact Match & Traversal**

```cypher
MATCH (u:User {id: $userId})-[:PURCHASED]->(p:Plan)
RETURN p
ORDER BY p.created_at DESC
LIMIT 5;
```

- **Path Search With Filters**

```cypher
MATCH path = (u:User {email: $email})-[:MEMBER_OF]->(:Team)-[:OWNS]->(res:Resource)
WHERE res.type IN $allowedTypes
RETURN res.name AS resource, length(path) AS hops
ORDER BY hops;
```

- **Graph Pattern Creation (merge avoids duplicates)**

```cypher
MERGE (u:User {id: $userId})
ON CREATE SET u.created_at = timestamp()
MERGE (p:Plan {id: $planId})
SET p.name = $planName
MERGE (u)-[:PURCHASED {at: datetime()}]->(p);
```

- **Aggregations + Relationship Properties**

```cypher
MATCH (:User)-[r:PURCHASED]->(p:Plan)
RETURN p.id AS planId,
       count(r) AS purchases,
       round(avg(duration.between(r.at, datetime()).years), 2) AS avgAgeYears
ORDER BY purchases DESC
LIMIT 10;
```

- **Graph Search for Authorization**

```cypher
MATCH (actor:User {id: $actorId})-[:HAS_ROLE]->(:Role)-[:GRANTS]->(perm:Permission)
MATCH (resource:Resource {id: $resourceId})
WHERE (perm)-[:ALLOWS]->(:Action {name: $action})
  AND (perm)-[:COVERS]->(resource)
RETURN count(*) > 0 AS allowed;
```

## Coordinating Both for RAG

- Query Qdrant first for semantic neighbors (keep payload IDs, tenant hints, tags).
- Use returned IDs to drive Neo4j lookups (e.g., related owners, processes, lineage).
- Enrich final context bundle with both vector results and graph facts before hitting the LLM.
