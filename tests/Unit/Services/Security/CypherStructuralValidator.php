<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Security;

use Condoedge\Ai\Services\Security\CypherStructuralValidator as RuntimeValidator;

/**
 * Test-facing static facade over the runtime {@see RuntimeValidator}.
 *
 * The structural validity gate is a single implementation living in src/ (it is
 * the runtime defence-in-depth check the injector runs before any rewrite may
 * reach Neo4j). This thin static wrapper preserves the existing test call sites
 * (`CypherStructuralValidator::isValid()` / `::diagnose()`).
 */
final class CypherStructuralValidator
{
    public static function isValid(string $cypher): bool
    {
        return (new RuntimeValidator())->isValid($cypher);
    }

    public static function diagnose(string $cypher): ?string
    {
        return (new RuntimeValidator())->diagnose($cypher);
    }
}
