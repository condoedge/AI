<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The package carries no host domain: no condo, CondoEdge or SISC vocabulary in what
 * it ships (src/, config/, resources/, routes/, database/, README.md, composer.json),
 * comments included. A host's domain goes in its own config (ai.domain).
 *
 * Out of scope: docs/ (internal notes, export-ignored) and tests/ (reworked on a neutral
 * schema after Coolecto v1). The numbered-provision grammar ("art. 12") is generic and
 * not checked; only a specific legal corpus is.
 */
class NoDomainContentTest extends TestCase
{
    private const SCANNED = ['src', 'config', 'resources', 'routes', 'database', 'README.md', 'composer.json'];

    private const EXTENSIONS = ['php', 'json', 'md', 'js', 'css', 'yaml', 'yml', 'txt', 'html'];

    private const TERMS = [
        'condo' => '/condo(?!edge)|condominium|copropri|syndica|\bsyndic\b|co-?owner|cotisation|fonds de pr|contingency fund|reserve fund|'
            . 'charges? communes?|common (?:charges|expenses|portions?)|private portions?|special assessment|\bHOA\b|\bstrata\b|immovable|hypothec/i',
        'product' => '/CondoEdge|\bSISC\b|Maryanne|Viva Tour|825 de Bruxelles|Am[eé]lie Quirion|Novo Gestion/',
        // Not a bare "unit" (unit tests, text units) nor "building" as a verb.
        'dwelling' => '/\bunit_(?:id|name)\b|unit owners?|owners? of (?:this|the|a) unit|unit[ée]s\b|\bapartments?\b|\blogements?\b|residential|'
            . '\bimmeubles?\b|\bbuildings\b|\b(?:a|the|my|this|each|our) building\b|tear(?:ing)? down|\bresidents?\b|r[ée]sidents?\b|'
            . '\blocataires?\b|\brent(?:er|ers|al)?\b|\blease\b|propri[ée]taires?|\bparking\b|\bpets?\b/i',
        'host label' => '/\bUnions?\b|ContactUnit|BoardMember|\bunion_(?:name|id)\b|BELONGS_TO_UNION|contact_unit/',
        'governance' => '/board members?|board of directors|conseil d.administration|assembl[ée]e|\bAGM\b|general meeting|\bquorum\b/i',
        'legal corpus' => '/Qu[ée]bec|\bCCQ\b|Civil Code|\bart(?:icle)?s?\.?\s*1(?:0[3-9]|10)\d\b|declaration of co-?ownership/i',
    ];

    /**
     * Lines that only look domain-flavoured: quality assessment, a Cypher UNION, and the
     * `co_owners` file payload (the other records a document is filed on).
     */
    private const NOISE = '/quality assessment|UNION clause|Cypher UNION|\bco_owners\b|\$coOwners/i';

    /** @test */
    public function the_package_ships_no_host_domain(): void
    {
        $root = dirname(__DIR__, 2);
        $hits = [];

        foreach ($this->files($root) as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
            foreach (preg_split('/\R/', (string) file_get_contents($path)) as $i => $line) {
                if (preg_match(self::NOISE, $line)) {
                    continue;
                }
                foreach (self::TERMS as $group => $pattern) {
                    if (preg_match($pattern, $line)) {
                        $hits[] = sprintf('%s:%d [%s] %s', $relative, $i + 1, $group, mb_substr(trim($line), 0, 140));
                        break;
                    }
                }
            }
        }

        $this->assertSame([], $hits, count($hits) . " domain hit(s):\n" . implode("\n", $hits));
    }

    /** @return iterable<string> */
    private function files(string $root): iterable
    {
        foreach (self::SCANNED as $entry) {
            $path = $root . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path)) {
                yield $path;
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                    yield $file->getPathname();
                }
            }
        }
    }
}
