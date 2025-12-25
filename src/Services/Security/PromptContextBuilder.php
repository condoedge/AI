<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Security;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * PromptContextBuilder
 *
 * Builds access-aware prompt context for RAG queries.
 * Injects access control instructions into prompts to guide the AI
 * on what data it can reveal based on user permissions.
 *
 * @package Condoedge\Ai\Services\Security
 */
class PromptContextBuilder
{
    protected ?Authenticatable $user;
    protected AccessLevelResolver $resolver;
    protected array $entitySensibleColumns = [];

    public function __construct(?Authenticatable $user)
    {
        $this->user = $user;
        $this->resolver = app(AccessLevelResolver::class);
    }

    /**
     * Set sensible columns for an entity
     *
     * @param string $entity Entity name
     * @param array $columns Sensible column names
     * @return self
     */
    public function setEntitySensibleColumns(string $entity, array $columns): self
    {
        $this->entitySensibleColumns[$entity] = $columns;
        return $this;
    }

    /**
     * Build the access control section for the prompt
     *
     * @param array $entities List of entity names to include
     * @return string Access control prompt section
     */
    public function buildAccessSection(array $entities): string
    {
        $sections = [];

        foreach ($entities as $entity) {
            $sections[] = $this->buildEntitySection($entity);
        }

        return implode("\n\n", $sections);
    }

    /**
     * Build access section for a single entity
     *
     * @param string $entity Entity name
     * @return string Entity access section
     */
    protected function buildEntitySection(string $entity): string
    {
        $context = $this->resolver->buildContextForEntity($this->user, $entity);
        $tags = $context['access_tags'];
        $threshold = $context['threshold'];
        $sensibleColumns = $this->entitySensibleColumns[$entity] ?? $context['sensible_columns'];
        $identifyingFields = $context['identifying_fields'];

        $lines = [];
        $lines[] = "## Data Access for {$entity}";
        $lines[] = "";

        // Allowed access levels
        $lines[] = "ALLOWED:";
        foreach ($tags as $tag) {
            $lines[] = "- {$tag}: " . $this->describeTag($tag);
        }
        $lines[] = "";

        // Restricted items
        $hasSensitiveAccess = in_array("{$entity}_team_sensitive", $tags);
        if (!$hasSensitiveAccess && !empty($sensibleColumns)) {
            $lines[] = "RESTRICTED (no access to these fields):";
            $lines[] = "- " . implode(', ', $sensibleColumns);
            $lines[] = "- Do NOT include or reference these fields in responses";
            $lines[] = "";
        }

        // Threshold rules
        $hasFilteredAccess = in_array("{$entity}_team_filtered_count", $tags);
        if ($hasFilteredAccess) {
            $lines[] = "COUNT THRESHOLD: {$threshold}";
            $lines[] = "- For filtered counts below {$threshold}, say \"fewer than {$threshold}\"";
            $lines[] = "- Never reveal exact counts under {$threshold} (prevents identifying individuals)";
            $lines[] = "";
        }

        // Identifying fields warning
        if (!empty($identifyingFields) && $hasFilteredAccess) {
            $lines[] = "IDENTIFYING FILTERS: " . implode(', ', $identifyingFields);
            $lines[] = "- Queries using these fields are sensitive";
            $lines[] = "- Apply threshold rules strictly for these";
            $lines[] = "";
        }

        // Team context
        if (!empty($context['user_teams'])) {
            $lines[] = "USER'S TEAMS: IDs " . implode(', ', $context['user_teams']);
        }

        return implode("\n", $lines);
    }

    /**
     * Describe what an access tag means
     *
     * @param string $tag Access tag
     * @return string Human-readable description
     */
    protected function describeTag(string $tag): string
    {
        $descriptions = [
            'global_count' => 'Total counts across entire application',
            'team_count' => 'Counts within accessible teams',
            'team_filtered_count' => 'Counts with specific filters (threshold applies)',
            'team_details' => 'Individual record data (non-sensitive fields)',
            'team_sensitive' => 'Full record data including sensitive fields',
        ];

        foreach ($descriptions as $suffix => $description) {
            if (str_ends_with($tag, "_{$suffix}")) {
                return $description;
            }
        }

        return 'Access granted';
    }

    /**
     * Build complete prompt context including semantic results
     *
     * @param array $entities Entity names involved
     * @param array $semanticResults Results from vector search
     * @param array $aggregates Pre-computed aggregates/counts
     * @return string Complete prompt context
     */
    public function buildFullContext(array $entities, array $semanticResults = [], array $aggregates = []): string
    {
        $parts = [];

        // Access control section
        $parts[] = $this->buildAccessSection($entities);

        // Aggregates section (if provided)
        if (!empty($aggregates)) {
            $parts[] = $this->buildAggregatesSection($aggregates);
        }

        // Semantic context section
        if (!empty($semanticResults)) {
            $parts[] = $this->buildSemanticSection($semanticResults, $entities);
        }

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * Build aggregates section (pre-computed counts)
     *
     * @param array $aggregates Key-value pairs of aggregate labels and values
     * @return string Aggregates section
     */
    protected function buildAggregatesSection(array $aggregates): string
    {
        $lines = ["## Available Aggregates (use these for count questions)"];

        foreach ($aggregates as $key => $value) {
            $lines[] = "- {$key}: {$value}";
        }

        return implode("\n", $lines);
    }

    /**
     * Build semantic context section
     *
     * Filters out sensitive fields from results if user doesn't have access.
     *
     * @param array $results Search results
     * @param array $entities Entities to check access for
     * @return string Semantic context section
     */
    protected function buildSemanticSection(array $results, array $entities = []): string
    {
        $lines = ["## Semantic Context"];

        foreach ($results as $result) {
            $entityType = $result['entity_type'] ?? $result['_entity_type'] ?? 'Unknown';
            $entityId = $result['entity_id'] ?? $result['_entity_id'] ?? $result['id'] ?? 'N/A';

            $lines[] = "";
            $lines[] = "### {$entityType} (ID: {$entityId})";

            // Filter content if user doesn't have sensitive access
            $content = $result['content'] ?? $result['_content'] ?? '';
            $filteredContent = $this->filterSensitiveContent($content, $entityType);

            $lines[] = $filteredContent;
        }

        return implode("\n", $lines);
    }

    /**
     * Filter sensitive content from results
     *
     * @param string $content Raw content
     * @param string $entityType Entity type
     * @return string Filtered content
     */
    protected function filterSensitiveContent(string $content, string $entityType): string
    {
        $hasSensitiveAccess = $this->resolver->hasAccessLevel(
            $this->user,
            $entityType,
            'team_sensitive'
        );

        if ($hasSensitiveAccess) {
            return $content;
        }

        // Get sensible columns for this entity
        $sensibleColumns = $this->entitySensibleColumns[$entityType] ?? [];

        if (empty($sensibleColumns)) {
            $context = $this->resolver->buildContextForEntity($this->user, $entityType);
            $sensibleColumns = $context['sensible_columns'];
        }

        // Redact sensitive fields from content
        foreach ($sensibleColumns as $field) {
            // Pattern to match "field: value" or "field = value" formats
            $patterns = [
                "/\b{$field}\s*[:=]\s*[^\n,;]+/i",
                "/\b{$field}\b[^:=\n]*[:=][^\n]+/i",
            ];

            foreach ($patterns as $pattern) {
                $content = preg_replace($pattern, "{$field}: [REDACTED]", $content);
            }
        }

        return $content;
    }

    /**
     * Build system prompt with access control
     *
     * @param array $entities Entities the user might query about
     * @return string System prompt with access instructions
     */
    public function buildSystemPrompt(array $entities): string
    {
        $accessSection = $this->buildAccessSection($entities);

        return <<<PROMPT
You are an AI assistant with access to company data. You must respect the following access control rules strictly.

{$accessSection}

IMPORTANT RULES:
1. Only provide information matching your ALLOWED access levels
2. Never reveal RESTRICTED fields - they don't exist as far as you're concerned
3. Apply COUNT THRESHOLD rules - say "fewer than X" for small counts
4. When asked about data you can't access, politely explain you don't have that information
5. Never try to infer or guess restricted information

PROMPT;
    }
}
