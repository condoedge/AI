<?php
// src/Models/AiMessage.php

declare(strict_types=1);

namespace Condoedge\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'response_data',
        'context_used',
        'cypher_query',
        'execution_time_ms',
        'confidence_score',
        'metadata',
    ];

    protected $casts = [
        'response_data' => 'array',
        'context_used' => 'array',
        'metadata' => 'array',
        'confidence_score' => 'float',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    /**
     * Get referenced files from metadata.
     *
     * Checks both 'sources' (current storage key) and 'referenced_files'
     * for backwards compatibility.
     */
    public function getReferencedFiles(): array
    {
        return $this->metadata['sources'] ?? $this->metadata['referenced_files'] ?? [];
    }

    /**
     * Check if message has file references.
     */
    public function hasFileReferences(): bool
    {
        return !empty($this->getReferencedFiles());
    }
}
