<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services;

use Condoedge\Ai\Exceptions\QueryTimeoutException;
use Condoedge\Ai\Exceptions\ReadOnlyViolationException;
use Condoedge\Ai\Exceptions\SecurityException;

/**
 * ExecutionErrorClassifier (Task 5.5)
 *
 * Tells a REPAIRABLE query error — Neo4j rejected the statement itself
 * (`Neo.ClientError.Statement.*`: syntax / semantic errors such as "Variable not
 * defined", type errors, "Unknown function", missing parameter) — from every
 * outcome a regenerated query cannot fix: timeouts, security refusals, read-only
 * violations, rate limits, connection / transient / database errors.
 *
 * Structural only: the decision is the Neo4j STATUS CODE carried in the driver
 * message (Bolt: `…with code "Neo.ClientError.Statement.SyntaxError"…`, HTTP:
 * `Neo4j query error: [Neo.ClientError.Statement.SyntaxError] …`) plus the
 * package's own exception types. No code → not repairable (fail safe: the
 * existing error answer).
 */
final class ExecutionErrorClassifier
{
    private const STATEMENT_CODE = '/\bNeo\.ClientError\.Statement\.[A-Za-z]+\b/';

    private const STATEMENT_PREFIX = 'Neo.ClientError.Statement.';

    /** Parameters the security layer injects (TeamPathResolver::TEAM_IDS_PARAM …). */
    private const RESERVED_PARAMETER_PREFIX = 'ai_';

    /** Upper bound on the feedback text handed to the generator. */
    public const MAX_FEEDBACK_CHARS = 500;

    public static function isRepairable(\Throwable $e): bool
    {
        foreach (self::chain($e) as $link) {
            if ($link instanceof QueryTimeoutException
                || $link instanceof SecurityException
                || $link instanceof ReadOnlyViolationException) {
                return false;
            }
        }

        ['code' => $code, 'line' => $line] = self::parse($e);
        if ($code === null) {
            return false;
        }

        // The database refused a write in read access mode: no regenerated read
        // query changes that.
        if ($code === self::STATEMENT_PREFIX . 'AccessMode') {
            return false;
        }

        // A RESERVED injected parameter (ai_*, e.g. $ai_team_ids) is missing: the
        // scoping layer is broken — the LLM cannot fix it. Only the error line is
        // read (the echoed query always mentions $ai_team_ids).
        if ($code === self::STATEMENT_PREFIX . 'ParameterMissing'
            && preg_match('/(?<![A-Za-z0-9_])' . preg_quote(self::RESERVED_PARAMETER_PREFIX, '/') . '[A-Za-z0-9_]+/', $line) === 1) {
            return false;
        }

        return true;
    }

    /**
     * The Neo4j error as generator feedback: status code + the error's first line,
     * WITHOUT the query excerpt / caret Neo4j echoes (that is the tenant-scoped
     * rewrite, not the generator's draft), without the line/column position (it
     * points into the rewrite too), and with every DATA literal redacted to
     * `<value>` — a runtime TypeError / ArgumentError line can carry a property
     * value, possibly from another tenant's row evaluated before the scope predicate.
     */
    public static function feedback(\Throwable $e): string
    {
        ['code' => $code, 'line' => $line] = self::parse($e);
        $line = self::redact($line, $code);

        $feedback = $code !== null ? "{$code}: {$line}" : $line;

        return mb_substr($feedback, 0, self::MAX_FEEDBACK_CHARS);
    }

    /**
     * Replace data payloads with `<value>`: `List{…}` / `Map{…}` bodies, typed value
     * wrappers (`String("…")`, `Long(42)`, `Double(1.5)` …), double-quoted strings,
     * and single-quoted strings — except a function name (`'toInteger()'`) and, on a
     * COMPILE-TIME error (no row was evaluated), a bare identifier (`'nofunc'`).
     * Backticked identifiers and `$parameter` names are kept.
     */
    public static function redact(string $line, ?string $code = null): string
    {
        // A lone wrapper quote closing a single-line Bolt message.
        if (substr_count($line, '"') % 2 === 1 && str_ends_with($line, '"')) {
            $line = substr($line, 0, -1);
        }

        $line = self::redactCollections($line);

        $line = preg_replace(
            '/\b([A-Z][A-Za-z]*)\(\s*(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|-?\d[\w.+-]*|true|false|null)\s*\)/',
            '$1(<value>)',
            $line
        ) ?? $line;

        $line = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '<value>', $line) ?? $line;

        $compileTime = in_array($code, [self::STATEMENT_PREFIX . 'SyntaxError', self::STATEMENT_PREFIX . 'SemanticError'], true);
        $line = preg_replace_callback('/\'((?:[^\'\\\\]|\\\\.)*)\'/', static function (array $m) use ($compileTime): string {
            if (preg_match('/^[A-Za-z_][\w.]*\(\)$/', $m[1]) === 1) {
                return $m[0]; // function name
            }
            if ($compileTime && preg_match('/^[A-Za-z_][\w.]*$/', $m[1]) === 1) {
                return $m[0]; // identifier from the query text
            }

            return '<value>';
        }, $line) ?? $line;

        // An unterminated quote left over (odd count): redact from it to the end.
        foreach (['"', "'"] as $quote) {
            if (substr_count($line, $quote) % 2 === 1) {
                $line = substr($line, 0, (int) strrpos($line, $quote)) . '<value>';
            }
        }

        return $line;
    }

    /** `List{…}` / `Map{…}` → `List{<value>}` / `Map{<value>}` (nesting- and quote-aware). */
    private static function redactCollections(string $line): string
    {
        $out = '';
        $len = strlen($line);
        $i = 0;
        while ($i < $len) {
            if (preg_match('/\G(List|Map)\{/', $line, $m, 0, $i) === 1) {
                $j = $i + strlen($m[0]);
                $depth = 1;
                $quote = null;
                while ($j < $len && $depth > 0) {
                    $c = $line[$j];
                    if ($quote !== null) {
                        if ($c === '\\') {
                            $j++;
                        } elseif ($c === $quote) {
                            $quote = null;
                        }
                    } elseif ($c === '"' || $c === "'") {
                        $quote = $c;
                    } elseif ($c === '{') {
                        $depth++;
                    } elseif ($c === '}') {
                        $depth--;
                    }
                    $j++;
                }
                $out .= $m[1] . '{<value>}';
                $i = $j;
                continue;
            }
            $out .= $line[$i];
            $i++;
        }

        return $out;
    }

    /**
     * The Neo4j status code and the error's FIRST line (position suffix removed),
     * from the least-wrapped exception in the chain that carries a statement code.
     *
     * @return array{code: ?string, line: string}
     */
    private static function parse(\Throwable $e): array
    {
        $message = '';
        foreach (self::chain($e) as $link) {
            if (preg_match(self::STATEMENT_CODE, $link->getMessage()) === 1) {
                $message = $link->getMessage(); // the deepest carrier wins (least wrapped)
            }
        }
        if ($message === '') {
            $message = $e->getMessage();
        }

        $code = preg_match(self::STATEMENT_CODE, $message, $m) === 1 ? $m[0] : null;

        // Bolt shape: … code "X" and message "<text>
        if (preg_match('/\bmessage\s+"(.*)$/s', $message, $mm) === 1) {
            $text = $mm[1];
        } else {
            $text = $message;
            if ($code !== null) {
                $text = preg_replace('/^.*?\[?' . preg_quote($code, '/') . '\]?[:\s]*/s', '', $text) ?? $text;
            }
        }

        $firstLine = trim(strtok($text, "\r\n") ?: $text);
        $firstLine = trim(preg_replace('/\s*\(line \d+, column \d+ \(offset: \d+\)\)/', '', $firstLine) ?? $firstLine);

        return ['code' => $code, 'line' => $firstLine];
    }

    /** @return list<\Throwable> */
    private static function chain(\Throwable $e): array
    {
        $chain = [];
        for ($link = $e; $link !== null && count($chain) < 10; $link = $link->getPrevious()) {
            $chain[] = $link;
        }

        return $chain;
    }
}
