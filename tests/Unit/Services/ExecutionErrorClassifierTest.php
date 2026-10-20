<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services;

use Condoedge\Ai\Exceptions\QueryExecutionException;
use Condoedge\Ai\Exceptions\QueryTimeoutException;
use Condoedge\Ai\Exceptions\SecurityException;
use Condoedge\Ai\Services\ExecutionErrorClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Task 5.5 — which execution errors a regenerated query can fix.
 */
class ExecutionErrorClassifierTest extends TestCase
{
    private const BOLT = 'Failed to execute Neo4j query: Neo4j errors detected. First one with code '
        . '"Neo.ClientError.Statement.SyntaxError" and message "Unknown function \'nofunc\' (line 1, column 26 (offset: 25))'
        . "\n\"MATCH (a:Account) WHERE a.team_id IN \$ai_team_ids RETURN nofunc(a.name)\"\n                          ^\"";

    /** @test */
    public function a_bolt_statement_error_is_repairable_and_its_feedback_drops_the_scoped_query(): void
    {
        $e = new QueryExecutionException('Query execution failed: ' . self::BOLT, 0, new \RuntimeException(self::BOLT));

        $this->assertTrue(ExecutionErrorClassifier::isRepairable($e));
        $this->assertSame(
            "Neo.ClientError.Statement.SyntaxError: Unknown function 'nofunc'",
            ExecutionErrorClassifier::feedback($e)
        );
    }

    /** @test */
    public function an_http_statement_error_is_repairable(): void
    {
        $message = 'Failed to execute Neo4j query: Neo4j query error: [Neo.ClientError.Statement.TypeError] Type mismatch: expected Integer but was String';
        $e = new QueryExecutionException('Query execution failed: ' . $message, 0, new \RuntimeException($message));

        $this->assertTrue(ExecutionErrorClassifier::isRepairable($e));
        $this->assertSame(
            'Neo.ClientError.Statement.TypeError: Type mismatch: expected Integer but was String',
            ExecutionErrorClassifier::feedback($e)
        );
    }

    /**
     * @test
     * @dataProvider infrastructure
     */
    public function infrastructure_failures_are_not_repairable(\Throwable $e): void
    {
        $this->assertFalse(ExecutionErrorClassifier::isRepairable($e));
    }

    public static function infrastructure(): array
    {
        return [
            'timeout' => [new QueryTimeoutException('Query exceeded timeout of 30 seconds')],
            'security refusal' => [new SecurityException('Access denied')],
            'rate limit' => [new QueryExecutionException('Rate limit exceeded for query execution. Please wait before trying again.')],
            'connection' => [new QueryExecutionException('Query execution failed: Cannot connect', 0, new \RuntimeException('Cannot connect to host'))],
            'transient' => [new QueryExecutionException('x', 0, new \RuntimeException('code "Neo.TransientError.Transaction.DeadlockDetected"'))],
            'transaction timeout' => [new QueryExecutionException('x', 0, new \RuntimeException('code "Neo.ClientError.Transaction.TransactionTimedOut"'))],
            'timeout wrapping a statement code' => [new QueryTimeoutException('t', 0, new \RuntimeException('Neo.ClientError.Statement.SyntaxError'))],
            // The database's read-only refusal: no regenerated read query changes it.
            'access mode (read-only refusal)' => [self::bolt('Neo.ClientError.Statement.AccessMode', 'Writing in read access mode not allowed. Attempted write to internal graph 0 (neo4j)')],
            // A reserved INJECTED parameter is missing: the scoping layer is broken, the LLM cannot fix it.
            'missing reserved ai_* parameter' => [self::bolt('Neo.ClientError.Statement.ParameterMissing', 'Expected parameter(s): ai_team_ids')],
            'missing reserved ai_* parameter among others' => [self::bolt('Neo.ClientError.Statement.ParameterMissing', 'Expected parameter(s): userId, ai_team_ids')],
        ];
    }

    /** @test */
    public function a_missing_parameter_the_generator_introduced_is_repairable(): void
    {
        $this->assertTrue(ExecutionErrorClassifier::isRepairable(
            self::bolt('Neo.ClientError.Statement.ParameterMissing', 'Expected parameter(s): userId')
        ));
    }

    // ------------------------------------------------------------ redaction

    /**
     * Runtime TypeError / ArgumentError lines can carry property VALUES — possibly
     * from another tenant's row evaluated before the scope predicate. The feedback
     * keeps the code, the error shape, backticked identifiers and parameter names,
     * and replaces every data payload with <value>.
     *
     * @test
     * @dataProvider valueCarryingErrors
     */
    public function feedback_redacts_data_literals(string $code, string $message, string $expected): void
    {
        $feedback = ExecutionErrorClassifier::feedback(self::bolt($code, $message));

        $this->assertSame($expected, $feedback);
    }

    public static function valueCarryingErrors(): array
    {
        return [
            'coerce a String value' => [
                'Neo.ClientError.Statement.TypeError',
                'Cannot coerce `String("Jean Tremblay")` to Integer',
                'Neo.ClientError.Statement.TypeError: Cannot coerce `String(<value>)` to Integer',
            ],
            'function argument, double-quoted value' => [
                'Neo.ClientError.Statement.ArgumentError',
                "Invalid input for function 'toInteger()': Expected a String, Number or Boolean, got: \"514-555-0199\"",
                "Neo.ClientError.Statement.ArgumentError: Invalid input for function 'toInteger()': Expected a String, Number or Boolean, got: <value>",
            ],
            'function argument, list value with numbers' => [
                'Neo.ClientError.Statement.ArgumentError',
                "Invalid input for function 'toFloat()': Expected a String or Number, got: List{String(\"12 rue Principale\"), Long(4521), Double(12.5)}",
                "Neo.ClientError.Statement.ArgumentError: Invalid input for function 'toFloat()': Expected a String or Number, got: List{<value>}",
            ],
            'map value' => [
                'Neo.ClientError.Statement.TypeError',
                'Type mismatch: expected a map but was Map{name -> String("Unité 310"), balance -> Double(1250.75)}',
                'Neo.ClientError.Statement.TypeError: Type mismatch: expected a map but was Map{<value>}',
            ],
            'typed scalar wrapper' => [
                'Neo.ClientError.Statement.TypeError',
                'Expected Long(4521) to be a org.neo4j.values.storable.TextValue, but it was a org.neo4j.values.storable.LongValue',
                'Neo.ClientError.Statement.TypeError: Expected Long(<value>) to be a org.neo4j.values.storable.TextValue, but it was a org.neo4j.values.storable.LongValue',
            ],
            'single-quoted value' => [
                'Neo.ClientError.Statement.ArgumentError',
                "Text cannot be parsed to a Date: 'Tremblay, Jean'",
                'Neo.ClientError.Statement.ArgumentError: Text cannot be parsed to a Date: <value>',
            ],
            'identifiers and parameters are kept' => [
                'Neo.ClientError.Statement.SyntaxError',
                'Variable `ab` not defined (line 1, column 30 (offset: 29))',
                'Neo.ClientError.Statement.SyntaxError: Variable `ab` not defined',
            ],
            'unknown function name is kept on a compile-time error' => [
                'Neo.ClientError.Statement.SyntaxError',
                "Unknown function 'nofunc' (line 1, column 26 (offset: 25))",
                "Neo.ClientError.Statement.SyntaxError: Unknown function 'nofunc'",
            ],
            'parameter name is kept' => [
                'Neo.ClientError.Statement.TypeError',
                'Type mismatch for parameter `$unitId`: expected Integer but was String("310")',
                'Neo.ClientError.Statement.TypeError: Type mismatch for parameter `$unitId`: expected Integer but was String(<value>)',
            ],
        ];
    }

    /** Bolt-shaped wrapping (driver → store → executor), echoing the scoped query after the first line. */
    private static function bolt(string $code, string $message): QueryExecutionException
    {
        $driver = 'Neo4j errors detected. First one with code "' . $code . '" and message "' . $message
            . "\n\"MATCH (u:Unit) WHERE u.team_id IN \$ai_team_ids RETURN toInteger(u.name)\"\n ^\"";

        return new QueryExecutionException(
            'Query execution failed: Failed to execute Neo4j query: ' . $driver,
            0,
            new \RuntimeException('Failed to execute Neo4j query: ' . $driver, 0, new \RuntimeException($driver))
        );
    }
}
