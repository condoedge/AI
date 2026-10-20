<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Query;

/**
 * UserOwnershipPaths — a structural reader of the host's `ai.user_entity_paths`
 * (how the authenticated user reaches "their" records).
 *
 * It answers two questions about a query that RAN, from the query text and the
 * host config alone — never from the question's words, never from Query
 * Understanding's possessive judgment (eval p16 A#3 / B#1: "our next meeting" ran
 * a TEAM-scoped query and the abstention still said "nothing linked to you";
 * "my contribution" for a user who owns no unit said "no matching records"
 * instead of the reason):
 *
 *  1. Is it ANCHORED ON THE USER? ({@see anchorIn()}) — it binds one of the
 *     user-bound nodes the host paths declare (`(c:Contact {user_id: $userId})` →
 *     Contact.user_id; `ea.entity_id = $userId` → that node's label + property) to
 *     this user's id, or the identity node itself (User.id). The generator inlines
 *     the id as a literal ("Replace $userId with the Current user ID"), so the
 *     executed text carries it.
 *
 *  2. Which ownership path does its emptiness HANG ON? ({@see probeFor()}) — the
 *     SHALLOWEST configured path the executed query traverses whole (every node
 *     label of the path is in the query, and the path's own anchor is the one the
 *     query binds), the user's profile row itself excluded. For "my contribution"
 *     (Contact → ContactUnit → Unit → Invoice) that is the user's Units: when the
 *     user has none, that — not "no invoice matched" — is why nothing came back.
 *     Returned as a ready count query (the configured path + `RETURN
 *     count(DISTINCT <target>) AS total`), to run through the scoped executor.
 *     Two equally shallow paths are ambiguous: nothing is probed.
 *
 * No entity name lives here: labels, properties and paths all come from config.
 */
final class UserOwnershipPaths
{
    private const USER_PARAMETER = '/\$userId\b/';

    /**
     * @param array<string, mixed>|null $paths ai.user_entity_paths (null = live config)
     * @param string|null $userLabel the identity label of the authenticated user (null = config, else "User")
     */
    public function __construct(
        private readonly ?array $paths = null,
        private readonly ?string $userLabel = null,
    ) {
    }

    /**
     * The user-bound nodes: one per (label, property) the configured paths bind to
     * `$userId`, then the identity node (the user label's `id`).
     *
     * @return list<array{label: string, property: string}>
     */
    public function anchors(): array
    {
        $anchors = [];
        foreach ($this->pathCyphers() as $cypher) {
            foreach ($this->anchorsOf($cypher) as $anchor) {
                $anchors[$anchor['label'] . '.' . $anchor['property']] = $anchor;
            }
        }

        $userLabel = $this->identityLabel();
        if ($userLabel !== '') {
            $anchors[$userLabel . '.id'] ??= ['label' => $userLabel, 'property' => 'id'];
        }

        return array_values($anchors);
    }

    /**
     * The user anchor $cypher binds to this user's id, or null when the query is not
     * anchored on the user (a team / portfolio-scoped query, another user's id, no id).
     *
     * @return array{label: string, property: string}|null
     */
    public function anchorIn(?string $cypher, int|string|null $userId): ?array
    {
        if ($cypher === null || trim($cypher) === '' || $userId === null || (string) $userId === '') {
            return null;
        }

        foreach ($this->anchors() as $anchor) {
            if ($this->binds($cypher, $anchor, $userId)) {
                return $anchor;
            }
        }

        return null;
    }

    /**
     * The ownership count to run for an own-data query that returned nothing: the
     * shallowest configured path it traverses (see the class doc), as
     * ['entity' => <the path's target label>, 'cypher' => <count query>]; null when
     * the query is not anchored on the user, traverses no configured path, or two
     * paths tie.
     *
     * @return array{entity: string, cypher: string}|null
     */
    public function probeFor(?string $cypher, int|string|null $userId): ?array
    {
        if ($this->anchorIn($cypher, $userId) === null) {
            return null;
        }

        $executed = array_fill_keys(array_column($this->nodes((string) $cypher), 'label'), true);

        $best = null;
        $bestDepth = PHP_INT_MAX;
        $tied = false;
        foreach ($this->pathCyphers() as $entity => $path) {
            if (preg_match(self::USER_PARAMETER, $path) !== 1) {
                continue;
            }

            $nodes = $this->nodes($path);
            $labels = array_values(array_unique(array_column($nodes, 'label')));
            // The profile row alone (a one-node path) is the user, not something they own.
            if (count($labels) < 2 || array_diff_key(array_fill_keys($labels, true), $executed) !== []) {
                continue;
            }

            $target = null;
            foreach ($nodes as $node) {
                if ($node['label'] === $entity && $node['variable'] !== '') {
                    $target = $node['variable'];
                    break;
                }
            }
            if ($target === null) {
                continue;
            }

            // The path's own anchor must be the one the executed query binds.
            $bound = false;
            foreach ($this->anchorsOf($path) as $anchor) {
                $bound = $bound || $this->binds((string) $cypher, $anchor, $userId);
            }
            if (!$bound) {
                continue;
            }

            $depth = count($labels);
            if ($depth < $bestDepth) {
                $bestDepth = $depth;
                $tied = false;
                $best = [
                    'entity' => (string) $entity,
                    'cypher' => rtrim($this->withUserId($path, $userId)) . " RETURN count(DISTINCT {$target}) AS total",
                ];
            } elseif ($depth === $bestDepth) {
                $tied = true;
            }
        }

        return $tied ? null : $best;
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<string, string> entity label => path cypher
     */
    private function pathCyphers(): array
    {
        $paths = $this->paths;
        if ($paths === null) {
            try {
                $paths = function_exists('config') ? config('ai.user_entity_paths', []) : [];
            } catch (\Throwable $e) {
                $paths = [];
            }
        }

        $cyphers = [];
        foreach (is_array($paths) ? $paths : [] as $entity => $path) {
            $cypher = is_array($path) ? ($path['cypher'] ?? null) : null;
            if (is_string($entity) && is_string($cypher) && trim($cypher) !== '') {
                $cyphers[$entity] = trim($cypher);
            }
        }

        return $cyphers;
    }

    private function identityLabel(): string
    {
        if ($this->userLabel !== null) {
            return trim($this->userLabel);
        }

        try {
            $label = function_exists('config') ? config('ai.response_generation.identity_labels.user', 'User') : 'User';
        } catch (\Throwable $e) {
            $label = 'User';
        }

        return is_string($label) ? trim($label) : 'User';
    }

    /**
     * The (label, property) pairs one path binds to `$userId`: the inline map form
     * `(v:Label {prop: $userId})` and the predicate form `v.prop = $userId`.
     *
     * @return list<array{label: string, property: string}>
     */
    private function anchorsOf(string $path): array
    {
        $anchors = [];

        if (preg_match_all('/\(\s*\w*\s*:\s*`?([A-Za-z_]\w*)`?\s*\{([^}]*)\}/', $path, $maps, PREG_SET_ORDER)) {
            foreach ($maps as $map) {
                if (preg_match('/([A-Za-z_]\w*)\s*:\s*\$userId\b/', $map[2], $property) === 1) {
                    $anchors[] = ['label' => $map[1], 'property' => $property[1]];
                }
            }
        }

        if (preg_match_all('/(?<![\w.$])([A-Za-z_]\w*)\.([A-Za-z_]\w*)\s*=\s*\$userId\b/', $path, $predicates, PREG_SET_ORDER)) {
            $labels = [];
            foreach ($this->nodes($path) as $node) {
                if ($node['variable'] !== '') {
                    $labels[$node['variable']] ??= $node['label'];
                }
            }
            foreach ($predicates as $predicate) {
                if (isset($labels[$predicate[1]])) {
                    $anchors[] = ['label' => $labels[$predicate[1]], 'property' => $predicate[2]];
                }
            }
        }

        return $anchors;
    }

    /**
     * Whether $cypher binds $anchor (label + property) to this user's id — inline map or
     * equality predicate on a variable of that label; the id as a bare number or a quoted
     * string, never as the prefix of a longer value.
     *
     * @param array{label: string, property: string} $anchor
     */
    private function binds(string $cypher, array $anchor, int|string $userId): bool
    {
        $label = preg_quote($anchor['label'], '/');
        $property = preg_quote($anchor['property'], '/');
        $id = $this->idPattern($userId);

        if (preg_match('/\(\s*\w*\s*:\s*`?' . $label . '`?\s*\{[^}]*?(?<![\w.])' . $property . '\s*:\s*' . $id . '\s*[,}]/', $cypher) === 1) {
            return true;
        }

        if (!preg_match_all('/\(\s*([A-Za-z_]\w*)\s*:\s*`?' . $label . '`?(?![\w`])/', $cypher, $variables)) {
            return false;
        }
        foreach (array_unique($variables[1]) as $variable) {
            if (preg_match('/(?<![\w.$])' . preg_quote($variable, '/') . '\.' . $property . '\s*=\s*' . $id . '(?![\w.])/', $cypher) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The user id as it can appear in a query: bare (numeric ids only) or quoted. */
    private function idPattern(int|string $userId): string
    {
        $forms = [];
        if (is_int($userId) || ctype_digit((string) $userId)) {
            $forms[] = preg_quote((string) $userId, '/') . '(?![\w.])';
        }
        $escaped = addcslashes((string) $userId, "\\'\"");
        $forms[] = preg_quote("'" . $escaped . "'", '/');
        $forms[] = preg_quote('"' . $escaped . '"', '/');

        return '(?:' . implode('|', $forms) . ')';
    }

    private function withUserId(string $path, int|string $userId): string
    {
        $literal = is_int($userId) || ctype_digit((string) $userId)
            ? (string) $userId
            : "'" . addcslashes((string) $userId, "\\'") . "'";

        return (string) preg_replace_callback(self::USER_PARAMETER, static fn (): string => $literal, $path);
    }

    /**
     * The labelled node patterns of a query, in order of appearance (string literals
     * blanked first, so a quoted "(x:Y" never counts).
     *
     * @return list<array{variable: string, label: string}>
     */
    private function nodes(string $cypher): array
    {
        $cypher = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/", "''", $cypher);
        preg_match_all('/\(\s*([A-Za-z_]\w*)?\s*:\s*`?([A-Za-z_]\w*)`?/', $cypher, $matches, PREG_SET_ORDER);

        return array_map(
            static fn (array $m): array => ['variable' => $m[1], 'label' => $m[2]],
            $matches
        );
    }
}
