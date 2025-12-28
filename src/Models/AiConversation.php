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
        'last_message_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_message_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid = $model->uuid ?? (string) Str::uuid();
        });
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', 'App\\Models\\User'));
    }

    public function addMessage(string $role, string $content, array $data = []): AiMessage
    {
        $message = $this->messages()->create([
            'role' => $role,
            'content' => $content,
            'response_data' => $data['response_data'] ?? null,
            'context_used' => $data['context_used'] ?? null,
            'cypher_query' => $data['cypher_query'] ?? null,
            'execution_time_ms' => $data['execution_time_ms'] ?? null,
            'confidence_score' => $data['confidence_score'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);

        $this->update(['last_message_at' => now()]);

        // Auto-generate title from first user message
        if ($this->title === null && $role === 'user') {
            $this->update(['title' => Str::limit($content, 50)]);
        }

        return $message;
    }

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
}
