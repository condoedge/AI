<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Retrieval;

use Condoedge\Ai\Services\Actions\Attach\RecordResolver;
use Condoedge\Ai\Services\Discovery\EntityLabelIndex;
use Condoedge\Ai\Services\Files\RecordAncestry;
use Condoedge\Ai\Support\FileLink;

/**
 * DocumentScopeResolver — which record this turn's question is about, from
 * STRUCTURAL signals only (no reading of the question's words), first match wins:
 *
 *  1. PAGE — the record of the page the chat was opened on
 *     (`options['page_context']`: entity_type + entity_id);
 *  2. CONVERSATION — the record the conversation is about: the previous answer's
 *     result was exactly ONE row that is a record of a configured entity (the
 *     rule the record chips follow, {@see RecordResolver}); a list is not one;
 *  3. CITED FILES — the records the uploaded files cited by the previous answer
 *     are filed on: one owner → that record; several owners of one root → the
 *     root (the building of two units); several roots → no scope;
 *  4. CURRENT — the user's current scope as the host declares it, a cacheable
 *     attribute map: `ai.file_context.scope.current = ['union' => 'current_union_id']`
 *     (entity kind => attribute of the user). A DEFAULT: weaker than 1–3.
 *
 * The focus' ancestors are read from the host's entity configuration
 * ({@see RecordAncestry}: one read of the focus row).
 *
 * NOT RESOLVED HERE: a record merely NAMED in the question text ("unit 310").
 * That needs record ids before the document search — the seam is
 * {@see self::resolve()}'s precedence list: a fifth, stronger-than-current
 * signal goes between 3 and 4 (or first, if the user named it explicitly).
 */
final class DocumentScopeResolver
{
    private readonly RecordAncestry $ancestry;

    private readonly EntityLabelIndex $index;

    public function __construct(?RecordAncestry $ancestry = null, ?EntityLabelIndex $index = null)
    {
        $this->ancestry = $ancestry ?? new RecordAncestry();
        $this->index = $index ?? EntityLabelIndex::shared();
    }

    /**
     * @param array<string, mixed> $pageContext         options['page_context']
     * @param array<string, mixed> $conversationContext the turn's conversation context
     */
    public function resolve(array $pageContext, array $conversationContext, mixed $user): DocumentScope
    {
        try {
            $current = $this->fromCurrent($user);
            $explicit = $this->fromPage($pageContext)
                ?? $this->fromConversation($conversationContext)
                ?? $this->fromCitedFiles($conversationContext);

            return $explicit === null
                ? ($current ?? DocumentScope::unknown())
                : $this->insideCurrent($explicit, $current);
        } catch (\Throwable $e) {
            // A scope is a narrowing: when it cannot be told, nothing is narrowed.
            return DocumentScope::unknown();
        }
    }

    /**
     * A focus that sits in NO record of the current scope's kind (a supplier, a
     * contact: records of the tenant, not of one building) is asked about from
     * inside the user's current one: its documents apply, as an owner's would.
     * A focus that is, or already has, a record of that kind is left as it is —
     * the current scope never replaces or adds a second building.
     */
    private function insideCurrent(DocumentScope $scope, ?DocumentScope $current): DocumentScope
    {
        if ($current === null || !$current->isKnown()) {
            return $scope;
        }
        $kind = FileLink::kindOf((string) $current->focus);
        foreach (array_merge([(string) $scope->focus], $scope->ancestors) as $key) {
            if (FileLink::kindOf($key) === $kind) {
                return $scope;
            }
        }

        return new DocumentScope(
            $scope->focus,
            array_values(array_unique(array_merge($scope->ancestors, [(string) $current->focus], $current->ancestors))),
            $scope->source
        );
    }

    private function fromPage(array $pageContext): ?DocumentScope
    {
        return $this->ofRecord($pageContext['entity_type'] ?? null, $pageContext['entity_id'] ?? null, DocumentScope::PAGE);
    }

    private function fromConversation(array $conversation): ?DocumentScope
    {
        $rows = $conversation['last_result_sample'] ?? null;
        $cypher = $conversation['last_cypher_query'] ?? null;
        if ((int) ($conversation['last_result_count'] ?? 0) !== 1 || !is_array($rows) || count($rows) !== 1
            || !is_array(reset($rows)) || !is_string($cypher) || trim($cypher) === '') {
            return null;
        }

        $labels = [];
        foreach ($this->entities() as $config) {
            $label = is_array($config) ? ($config['graph']['label'] ?? null) : null;
            if (is_string($label) && $label !== '') {
                $labels[$label] = true;
            }
        }
        $record = (new RecordResolver(array_keys($labels), $cypher, array_values(array_filter(
            (array) ($conversation['last_entity_types'] ?? []),
            'is_string'
        ))))->resolve([reset($rows)])[0] ?? null;

        return is_array($record) ? $this->ofRecord($record['label'] ?? null, $record['id'] ?? null, DocumentScope::CONVERSATION) : null;
    }

    private function fromCitedFiles(array $conversation): ?DocumentScope
    {
        $owners = [];
        foreach ((array) ($conversation['last_referenced_files'] ?? []) as $file) {
            $owner = is_array($file) ? ($file['owner'] ?? null) : null;
            if (is_string($owner) && $owner !== '') {
                $owners[$owner] = array_values(array_filter((array) ($file['ancestors'] ?? []), 'is_string'));
            }
        }
        if ($owners === []) {
            return null;
        }
        if (count($owners) === 1) {
            return new DocumentScope((string) array_key_first($owners), reset($owners), DocumentScope::CITED_FILES);
        }

        $roots = [];
        foreach ($owners as $owner => $ancestors) {
            $roots[(string) FileLink::root(['owner' => $owner, 'ancestors' => $ancestors])] = true;
        }

        return count($roots) === 1
            ? new DocumentScope((string) array_key_first($roots), [], DocumentScope::CITED_FILES)
            : null;
    }

    private function fromCurrent(mixed $user): ?DocumentScope
    {
        if (!is_object($user)) {
            return null;
        }

        try {
            $map = function_exists('config') ? config('ai.file_context.scope.current', []) : [];
        } catch (\Throwable $e) {
            $map = [];
        }

        foreach (is_array($map) ? $map : [] as $kind => $attribute) {
            if (!is_string($kind) || !is_string($attribute) || $attribute === '') {
                continue;
            }
            $id = $user->{$attribute} ?? null;
            $scope = $this->ofRecord($this->ancestry->labelOfKey($kind . ':0') ?? $kind, $id, DocumentScope::CURRENT);
            if ($scope !== null) {
                return $scope;
            }
        }

        return null;
    }

    private function ofRecord(mixed $label, mixed $id, string $source): ?DocumentScope
    {
        if (!is_string($label) || $label === '' || !(is_int($id) || (is_string($id) && $id !== '')) || (string) $id === '0') {
            return null;
        }
        $label = $this->index->isRegistered($label) ? $label : ($this->index->labelForTerm($label) ?? '');
        $config = $label !== '' ? $this->index->configForLabel($label) : null;
        $graphLabel = is_array($config) ? ($config['graph']['label'] ?? $label) : null;
        if (!is_string($graphLabel) || $graphLabel === '') {
            return null;
        }

        return new DocumentScope(
            FileLink::key($graphLabel, $id),
            $this->ancestry->ancestorsOf($graphLabel, is_string($id) && ctype_digit($id) ? (int) $id : $id),
            $source
        );
    }

    /** @return array<int|string, mixed> */
    private function entities(): array
    {
        try {
            $entities = function_exists('config') ? config('entities', []) : [];
        } catch (\Throwable $e) {
            $entities = [];
        }

        return is_array($entities) ? $entities : [];
    }
}
