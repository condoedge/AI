<?php
// src/Models/AiConversation.php

declare(strict_types=1);

namespace Condoedge\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AiConversation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'user_id',
        'team_id',
        'title',
        'status',
        'metadata',
        'context_snapshot',
        'last_message_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'context_snapshot' => 'array',
        'last_message_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid = $model->uuid ?? (string) Str::uuid();
        });
    }

    // RELATIONSHIPS
    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', 'App\\Models\\User'));
    }

    // SCOPES
    public function scopeForFilter($query, string $filter)
    {
        if ($filter === 'pinned') {
            return $query->whereRaw("JSON_EXTRACT(metadata, '$.pinned') = true");
        } elseif ($filter === 'archived') {
            return $query->where('status', 'archived');
        } else {
            return $query->where('status', 'active');
        }
    }

    public function scopeSearch($query, $search)
    {
        $searchPattern = '%' . str_replace(' ', '%', trim($search)) . '%';

        return $query->where(function ($q) use ($searchPattern) {
            $q->where('title', 'like', $searchPattern)
                ->orWhereHas('messages', function ($mq) use ($searchPattern) {
                    $mq->where('content', 'like', $searchPattern);
                });
        });
    }

    // CALCULATED FIELDS
    public function getRecentMessages(int $limit = 10): array
    {
        return $this->messages()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->toArray();
    }

    /**
     * Get the currently focused entity type
     */
    public function getFocusedEntity(): ?string
    {
        return $this->context_snapshot['focused_entity'] ?? null;
    }

    /**
     * Get the last query type (count, list, aggregate, etc.)
     */
    public function getLastQueryType(): ?string
    {
        return $this->context_snapshot['last_query_type'] ?? null;
    }

    /**
     * Get mentioned entities from context
     */
    public function getMentionedEntities(): array
    {
        return $this->context_snapshot['mentioned_entities'] ?? [];
    }

    /**
     * Get the last Cypher query from most recent assistant message
     */
    public function getLastCypherQuery(): ?string
    {
        $lastAssistantMessage = $this->messages()
            ->where('role', 'assistant')
            ->whereNotNull('cypher_query')
            ->orderBy('created_at', 'desc')
            ->first();

        return $lastAssistantMessage?->cypher_query;
    }

    /**
     * Update context with entity data from query results.
     */
    public function updateEntityContext(array $entityData): void
    {
        $this->updateContextSnapshot([
            'focused_entity_data' => $entityData,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get the focused entity's identifying filter.
     */
    public function getFocusedEntityFilter(): ?string
    {
        return $this->context_snapshot['focused_entity_filter'] ?? null;
    }

    /**
     * Get a sample of the last query results.
     */
    public function getLastResultSample(): array
    {
        return $this->context_snapshot['last_result_sample'] ?? [];
    }

    /**
     * Get previous query for reference.
     */
    public function getPreviousCypherQuery(): ?string
    {
        return $this->context_snapshot['last_cypher_query'] ?? null;
    }

    /**
     * Get the count of last query results.
     */
    public function getLastResultCount(): int
    {
        return $this->context_snapshot['last_result_count'] ?? 0;
    }

    // ACTIONS
    public function addMessage(string $role, string $content, array $data = []): AiMessage
    {
        // Extract referenced_files and merge into metadata
        $metadata = $data['metadata'] ?? [];
        if (isset($data['referenced_files'])) {
            $metadata['referenced_files'] = $data['referenced_files'];
        }

        $message = $this->messages()->create([
            'role' => $role,
            'content' => $content,
            'response_data' => $data['response_data'] ?? null,
            'context_used' => $data['context_used'] ?? null,
            'cypher_query' => $data['cypher_query'] ?? null,
            'execution_time_ms' => $data['execution_time_ms'] ?? null,
            'confidence_score' => $data['confidence_score'] ?? null,
            'metadata' => !empty($metadata) ? $metadata : null,
        ]);

        $this->update(['last_message_at' => now()]);

        // Auto-generate title from first user message
        if ($this->title === null && $role === 'user') {
            $this->update(['title' => Str::limit($content, 50)]);
        }

        // Track referenced files in context snapshot (merge, don't overwrite)
        if (!empty($metadata['referenced_files'])) {
            // Refresh from DB to get latest context (recordResponse may have updated it)
            $this->refresh();
            $existingFiles = $this->context_snapshot['referenced_files'] ?? [];
            $newFileIds = array_column($metadata['referenced_files'], 'id');
            $allFiles = array_unique(array_merge($existingFiles, $newFileIds));

            $this->updateContextSnapshot(['referenced_files' => array_values($allFiles)]);
        }

        return $message;
    }

    /**
     * Update the context snapshot by merging new data with existing.
     *
     * This preserves existing context data (like last_referenced_files) while
     * updating only the keys provided in the new snapshot.
     */
    public function updateContextSnapshot(array $newData): void
    {
        $existingSnapshot = $this->context_snapshot ?? [];
        $mergedSnapshot = array_merge($existingSnapshot, $newData);
        $this->update(['context_snapshot' => $mergedSnapshot]);
    }
}
