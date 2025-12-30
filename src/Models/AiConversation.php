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
        return $query->where(function ($q) use ($search) {
            $q->where('title', 'like', wildcardSpace($search))
                ->orWhereHas('messages', function ($mq) use ($search) {
                    $mq->where('content', 'like', wildcardSpace($search));
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

        // Track referenced files in context snapshot
        if (!empty($metadata['referenced_files'])) {
            $existingFiles = $this->context_snapshot['referenced_files'] ?? [];
            $newFileIds = array_column($metadata['referenced_files'], 'id');
            $allFiles = array_unique(array_merge($existingFiles, $newFileIds));

            $this->updateContextSnapshot([
                'referenced_files' => array_values($allFiles),
            ]);
        }

        return $message;
    }

    /**
     * Update the conversation's context snapshot
     */
    public function updateContextSnapshot(array $context): void
    {
        $this->update(['context_snapshot' => array_merge(
            $this->context_snapshot ?? [],
            $context
        )]);
    }
}
