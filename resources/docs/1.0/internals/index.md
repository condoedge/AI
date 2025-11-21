# Internals & Architecture

Welcome to the deep-dive track. Here we document how the system is wired, why certain patterns exist, and how to safely modify the package when you want to contribute new features or swap core components.

## Why This Matters

- **Future maintainers** need a mental model before touching production code.
- **New teammates** can ramp up on RAG systems/dual storage even if they are new to AI.
- **Contributors** gain confidence to refactor services, add providers, or debug complex flows.

## Contents

1. [System Architecture](/docs/{{version}}/internals/architecture) – High-level diagrams, layers, and request/ingestion flows.
2. [Core Components Guide](/docs/{{version}}/internals/components) – Contracts, services, and how they collaborate.
3. [Data & Control Flows](/docs/{{version}}/internals/data-flows) – Sequence diagrams for ingestion, RAG, and response generation.
4. [Storage & Query Playbook](/docs/{{version}}/internals/storage-guide) – Practical Neo4j + Qdrant command reference.
5. [Resilience & Security](/docs/{{version}}/internals/resilience) – Circuit breakers, retries, injection prevention, and logging safeguards.

After internalizing these sections you should be able to:

- Replace a provider (e.g., Pinecone instead of Qdrant) while keeping contracts intact.
- Add new query patterns or modify the Cypher scope adapter confidently.
- Explain the system architecture in interviews, design reviews, or runbooks.
