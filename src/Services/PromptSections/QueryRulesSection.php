<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\PromptSections;

use Condoedge\Ai\DTOs\PipelineContext;
use Condoedge\Ai\Support\DomainProfile;

/**
 * QueryRulesSection
 *
 * Provides rules for query generation including:
 * - Schema compliance
 * - Data type rules
 * - Relationship direction rules
 * - Business rules
 * - Query best practices
 * - Output format
 *
 * Priority: 75 (after pattern library, before question)
 */
class QueryRulesSection extends BasePromptSection
{
    protected string $name = 'query_rules';
    protected int $priority = 75;

    public function role(): string
    {
        // Static, stable Cypher-generation rules belong in the system framing.
        return 'system';
    }

    /**
     * Custom rules that can be added dynamically
     */
    private array $customRules = [];

    /**
     * Add a custom rule
     *
     * @param string $category Rule category (e.g., 'SCHEMA_COMPLIANCE', 'CUSTOM')
     * @param string $rule The rule text
     * @return self
     */
    public function addRule(string $category, string $rule): self
    {
        $this->customRules[$category][] = $rule;
        return $this;
    }

    /**
     * Set all custom rules at once
     *
     * @param array $rules Array of [category => [rules...]]
     * @return self
     */
    public function setRules(array $rules): self
    {
        $this->customRules = $rules;
        return $this;
    }

    public function format(string $question, PipelineContext $context, array $options = []): string
    {
        $allowWrite = $options['allowWrite'] ?? false;

        $output = $this->header('QUERY GENERATION RULES');
        $output .= "When generating Cypher queries, you MUST follow these rules:\n\n";

        $output .= $this->formatSchemaCompliance();
        $output .= $this->formatDataTypeRules();
        $output .= $this->formatRelationshipRules();
        $output .= $this->formatBusinessRules();
        $output .= $this->formatFieldProjection();
        $output .= $this->formatBestPractices();
        $output .= $this->formatOutputFormat();

        if (!$allowWrite) {
            $output .= $this->formatReadOnlyConstraint();
        }

        $output .= $this->formatCustomRules();

        return $output;
    }

    private function formatSchemaCompliance(): string
    {
        return "1. SCHEMA COMPLIANCE:\n" .
               "   - Use only labels and relationships from the GRAPH SCHEMA block\n" .
               "   - Use only properties that exist in the GRAPH SCHEMA block\n" .
               "   - CRITICAL: Use the EXACT relationship directions shown (arrows matter!)\n" .
               "   - CRITICAL: Respect each property's stored type as shown on its GRAPH SCHEMA card (name:Type)\n\n";
    }

    /**
     * Matches the graph as ingested (Task 5.8, verified live on a host graph 2026-09-30):
     * date columns are ISO-8601 strings with a time part ('2021-07-05T04:00:00.000000Z',
     * valueType STRING); `due_at < datetime()` matched 0 of 6006 invoices while
     * `due_at < toString(date())` matched them. The cards tag such properties
     * `String(ISO date)` and digit-string codes `String(numeric)` (PropertyTypeSampler).
     * Revisit when typed ingestion (Phase 9) stores real temporal values.
     */
    private function formatDataTypeRules(): string
    {
        return "2. DATA TYPE RULES:\n" .
               "   - Follow each property's type on its GRAPH SCHEMA card.\n" .
               "   - When the card shows String(ISO date) (an ISO-8601 string, e.g. '2024-01-15T05:00:00.000000Z'): compare as strings, never against date()/datetime() values — a string compared with datetime() matches nothing. For relative dates (today, now, overdue, upcoming, past) compare with toString(date()), e.g. x.due_at < toString(date()) — that is a day boundary: an item due today counts as NOT yet overdue. To match a whole day compare substring(x.due_at, 0, 10) = '2024-01-15'\n" .
               "   - A Date/DateTime-typed property compares with date()/datetime() directly (e.g. x.due_at < date())\n" .
               "   - NEVER hardcode today's date — use date()/toString(date())\n" .
               "   - A String(numeric) property holds digit strings: compare it with a quoted value (x.code = '2'), not a number\n" .
               "   - EXCEPTION: If the DETECTED BUSINESS CONCEPTS block provides a CYPHER PATTERN, use that pattern EXACTLY as written\n\n";
    }

    private function formatRelationshipRules(): string
    {
        return "3. RELATIONSHIP DIRECTION RULES:\n" .
               "   - ALWAYS check the relationship direction in the schema\n" .
               "   - (a)-[:REL]->(b) means relationship goes FROM a TO b\n" .
               "   - (a)<-[:REL]-(b) means relationship goes FROM b TO a\n" .
               "   - Do NOT infer relationship traversals from *_id properties, Eloquent relation names, natural-language phrases, or configured foreign_key metadata\n" .
               "   - Use a relationship traversal only when the exact source label, relationship type, target label, and direction are listed in the out/in edges of a GRAPH SCHEMA card or in a DETECTED BUSINESS CONCEPTS block CYPHER PATTERN\n" .
               "   - If a node exposes a scalar foreign-key property such as current_team_id, team_id, or user_id and the exact edge is not listed on its GRAPH SCHEMA card (see the card's fk (no edge) line), filter on that property with WHERE instead of traversing an invented edge\n" .
               "   - Getting the direction wrong will return zero results!\n\n";
    }

    private function formatBusinessRules(): string
    {
        return "4. BUSINESS RULES:\n" .
               "   - CRITICAL: If the DETECTED BUSINESS CONCEPTS block has a CYPHER PATTERN, you MUST use it in your WHERE clause\n" .
               "   - Before filtering on a status/role/type field, use the {code=meaning} enum legend on the GRAPH SCHEMA card, written in the property's type (Integer → 2, String(numeric) → '2')\n" .
               "   - Apply filters exactly as specified in scope definitions\n" .
               "   - A name the user gives for a record is how they say it, not how it is stored: match it with toLower(x.name) CONTAINS toLower(\"<its distinctive words>\"), never with =\n" .
               "   - Use DISTINCT when specified to avoid duplicates\n" .
               "   - When returning entity data, JOIN to related entities to get human-readable names (e.g., " . DomainProfile::text('query_rules.join_example') . ")\n\n";
    }

    private function formatFieldProjection(): string
    {
        return "5. FIELD PROJECTION:\n" .
               "   - If the REQUESTED ATTRIBUTES block lists data concepts, each mapped property MUST appear in the RETURN clause as an output column\n" .
               "   - RETURN requested attributes as columns in addition to a human-readable identifying column (the record's name, title or number, as listed on its GRAPH SCHEMA card) — do NOT only use them in a WHERE filter\n" .
               // Eval p9-B digest t1 (2026-10-01): "when was our last meeting?"
               // returned date + id only, so neither the answer nor the chip could
               // name the meeting.
               "   - When the rows are records, return that human-readable identifying column even when the question asks only for its date or amount (\"when was the last X\" returns X's name with its date) — an id alone identifies nothing to the user\n" .
               // Actions plan B1: the record chips under the answer are attached by code
               // from the rows' ids — a record row without its id gets no link.
               "   - When the rows are records — one row per " . DomainProfile::text('query_rules.record_examples') . "…, including per-record aggregates (WITH u, sum(...)) — ALSO return each record's id (e.g. " . DomainProfile::text('query_rules.record_id_example') . "): the system uses it to attach the record's link and never shows it. Omit it only for a single overall total or count.\n" .
               "   - A request to see/include an attribute is a projection requirement, not just a filter\n" .
               "   - PRECEDENCE: REQUESTED ATTRIBUTES OVERRIDE any column list suggested in an entity description — descriptions offer default columns, the user's requested attributes always win, joining to related entities when the attribute of the listed records lives there — but an attribute no property holds for the listed records is omitted (not recorded), never taken from another entity's field\n" .
               // Eval p9 (2026-10-01): "with contact info" projected every scalar
               // property of the joined Contact, including a never-populated flag.
               "   - Return the identifying column(s) plus the columns the question asks for — never every property of a joined entity\n" .
               // Eval p9 (2026-10-01): User -> Contact -> ContactDirectory OPTIONAL
               // MATCHes fanned 523 members out to 530 / 1352 rows.
               "   - ROW GRAIN: return ONE row per record of the entity being listed (the " . DomainProfile::text('query_rules.row_grain_examples') . "… the question enumerates). When a joined entity can match SEVERAL times per record (a one-to-many relationship), aggregate it per record instead of returning one row per match: WITH r, collect(DISTINCT x.value) AS values for a list of values, or WITH r, head(collect(x)) AS x for one related record. Never let a join duplicate the listed records or inflate their count\n\n";
    }

    private function formatBestPractices(): string
    {
        return "6. QUERY BEST PRACTICES:\n" .
               "   - Always include LIMIT clause (default LIMIT 100)\n" .
               "   - Use DISTINCT when traversing relationships\n" .
               "   - Use descriptive variable names (p for Person, o for Order, etc.)\n" .
               // Generic Cypher correctness — needed with or without tenant scoping, so it
               // lives here, once; TenantScopingRulesSection keeps only the per-branch
               // team-filter placement (review M-3).
               "   - With UNION, every branch's RETURN must project the same column names/aliases in the same order (e.g. RETURN x.name AS name)\n" .
               "   - Optimize for performance (use indexes, avoid cartesian products)\n\n";
    }

    private function formatOutputFormat(): string
    {
        return "7. OUTPUT FORMAT:\n" .
               "   - Respond with ONE JSON object and nothing else:\n" .
               "     {\"needs_query\": true, \"cypher\": \"<the Cypher query>\", \"reason\": \"<one short sentence>\"}\n" .
               "   - \"cypher\" is clean, executable Cypher: NO markdown code blocks, NO comments, NO decorations\n" .
               "   - Set \"needs_query\": false and \"cypher\": null ONLY when no query is needed or possible (see YOUR TASK)\n\n";
    }

    private function formatReadOnlyConstraint(): string
    {
        return "8. READ-ONLY CONSTRAINT:\n" .
               "   - NO write operations (CREATE, MERGE, SET, DELETE, REMOVE, etc.)\n" .
               "   - Only generate read queries (MATCH, RETURN, WHERE, etc.)\n\n";
    }

    private function formatCustomRules(): string
    {
        if (empty($this->customRules)) {
            return '';
        }

        $output = '';
        $ruleNumber = 9; // Continue from last standard rule

        foreach ($this->customRules as $category => $rules) {
            $output .= "{$ruleNumber}. {$category}:\n";
            foreach ($rules as $rule) {
                $output .= "   - {$rule}\n";
            }
            $output .= "\n";
            $ruleNumber++;
        }

        return $output;
    }
}
