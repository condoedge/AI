<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Services\CypherValidator;
use PHPUnit\Framework\TestCase;

/**
 * Focused tests for the extracted CypherValidator.
 *
 * CypherValidator owns the single canonical write/dangerous keyword list and
 * the validate / sanitize / complexity logic that QueryGenerator delegates to.
 * Pure logic — no Laravel bootstrapping required.
 */
class CypherValidatorTest extends TestCase
{
    private CypherValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new CypherValidator();
    }

    public function test_canonical_keyword_list_is_the_single_source(): void
    {
        // Every dangerous keyword must be flagged by validate() with allow_write=false,
        // proving the constant and the validation path share one list.
        foreach (CypherValidator::DANGEROUS_KEYWORDS as $keyword) {
            $query = "MATCH (n) {$keyword} n RETURN n LIMIT 10";
            $result = $this->validator->validate($query, ['allow_write' => false]);

            $this->assertFalse($result['valid'], "Expected {$keyword} to be rejected");
            $this->assertFalse($result['is_read_only'], "Expected {$keyword} to mark query as not read-only");
        }
    }

    public function test_validate_accepts_read_only_query(): void
    {
        $result = $this->validator->validate('MATCH (n:Customer) RETURN n LIMIT 100');

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertTrue($result['is_read_only']);
    }

    public function test_validate_allows_write_when_permitted(): void
    {
        $result = $this->validator->validate('MATCH (n:Customer) DELETE n', ['allow_write' => true]);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertFalse($result['is_read_only']);
    }

    public function test_validate_warns_about_missing_limit(): void
    {
        $result = $this->validator->validate('MATCH (n:Customer) RETURN n');

        $this->assertTrue($result['valid']);
        $this->assertStringContainsString('LIMIT', $result['warnings'][0]);
    }

    public function test_validate_rejects_query_without_match_or_return(): void
    {
        $result = $this->validator->validate('SHOW DATABASES');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_validate_does_not_false_positive_on_substring_keywords(): void
    {
        // "deleted_at" contains "delete" but only as a substring, so word-boundary
        // matching must NOT flag it.
        $result = $this->validator->validate('MATCH (n:Customer) WHERE n.deleted_at IS NULL RETURN n LIMIT 10');

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['is_read_only']);
    }

    public function test_validate_throws_for_empty_query(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->validator->validate('   ');
    }

    public function test_validate_warns_when_complexity_exceeds_threshold(): void
    {
        $query = 'MATCH (a)-[r1]->(b)-[r2]->(c)-[r3]->(d) WHERE a.prop = 1 AND b.prop = 2 RETURN a, b, c, d LIMIT 10';

        $result = $this->validator->validate($query, ['max_complexity' => 20]);

        $this->assertTrue($result['valid']);
        $this->assertGreaterThan(20, $result['complexity']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_sanitize_removes_dangerous_keywords_and_adds_limit(): void
    {
        $sanitized = $this->validator->sanitize('MATCH (n:Customer) DELETE n; DROP INDEX x; CREATE (m:New)', 100);

        $this->assertStringNotContainsString('DELETE', $sanitized);
        $this->assertStringNotContainsString('DROP', $sanitized);
        $this->assertStringNotContainsString('CREATE', $sanitized);
    }

    public function test_sanitize_adds_limit_when_missing(): void
    {
        $sanitized = $this->validator->sanitize('MATCH (n:Customer) RETURN n', 100);

        $this->assertStringContainsString('LIMIT', $sanitized);
        $this->assertStringContainsString('100', $sanitized);
    }

    public function test_sanitize_preserves_existing_limit(): void
    {
        $sanitized = $this->validator->sanitize('MATCH (n:Customer) RETURN n LIMIT 50', 100);

        $this->assertStringContainsString('LIMIT 50', $sanitized);
        $this->assertEquals(1, substr_count(strtoupper($sanitized), 'LIMIT'));
    }

    public function test_complexity_scales_with_clauses(): void
    {
        $simple = $this->validator->complexity('MATCH (n:Customer) RETURN n LIMIT 10');
        $complex = $this->validator->complexity(
            'MATCH (a)-[r]->(b) WHERE a.x = 1 RETURN count(a) LIMIT 10'
        );

        $this->assertGreaterThan(0, $simple);
        $this->assertGreaterThan($simple, $complex);
    }
}
