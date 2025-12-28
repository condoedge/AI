<?php
// src/Models/AiQueryLog.php

declare(strict_types=1);

namespace Condoedge\Ai\Models;

use Illuminate\Database\Eloquent\Model;

class AiQueryLog extends Model
{
    protected $fillable = [
        'user_id', 'team_id', 'conversation_id',
        'question', 'cypher_query', 'template_used',
        'confidence_score', 'execution_time_ms', 'result_count',
        'status', 'error_message', 'context_stats', 'metadata',
    ];

    protected $casts = [
        'context_stats' => 'array',
        'metadata' => 'array',
        'confidence_score' => 'float',
    ];

    public static function logSuccess(array $data): self
    {
        return self::create(array_merge($data, ['status' => 'success']));
    }

    public static function logFailure(array $data, string $error): self
    {
        return self::create(array_merge($data, [
            'status' => 'failed',
            'error_message' => $error,
        ]));
    }
}
