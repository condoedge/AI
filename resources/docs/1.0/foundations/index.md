# Foundations

The Foundations track helps you install the AI Text-to-Query package, verify your infrastructure (Neo4j, Qdrant, LLM providers), and understand the configuration surface before writing a single line of application code.

## Who This Section Is For

- Developers spinning up the stack for the first time
- Platform engineers responsible for provisioning graph/vector infrastructure
- Teammates who prefer a guided, copy-paste friendly onboarding flow

## What You Will Learn

1. **Core Requirements** – Supported PHP/Laravel versions, extension prerequisites, and recommended hardware sizing.
2. **Installation Workflow** – Composer install, provider registration, config publishing, entity discovery, and initial data ingestion.
3. **Infrastructure Playbooks** – Battle-tested Docker Compose recipes for Neo4j + Qdrant, plus connectivity diagnostics.
4. **Configuration Reference** – Complete guide to project context, auto-discovery, auto-sync, file processing, and all `.env` settings.
5. **Operational Safety Nets** – Troubleshooting checklists, log locations, health probes, and documentation routing via `binarytorch/larecipe`.

## Key Commands You'll Use

```bash
# Generate entity configurations
php artisan ai:discover

# Bulk ingest existing data
php artisan ai:ingest

# Reconcile missing relationships
php artisan ai:sync-relationships

# Process files for semantic search
php artisan ai:process-files

## Recommended Reading Order

1. [Requirements & Compatibility](/docs/{{version}}/foundations/requirements)
2. [Installing & Verifying the Stack](/docs/{{version}}/foundations/installing)
3. [Infrastructure Playbook](/docs/{{version}}/foundations/infrastructure)
4. [Configuration Reference](/docs/{{version}}/foundations/configuration)
5. [Troubleshooting & Diagnostics](/docs/{{version}}/foundations/troubleshooting)

When you feel confident with the basics, continue to the [Usage & Extension track](/docs/{{version}}/usage) to hook the package into real workloads.
