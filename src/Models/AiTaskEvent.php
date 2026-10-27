<?php

declare(strict_types=1);

namespace Condoedge\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One audit event of a task run. Append-only and hash-chained per task (see TaskStore).
 */
class AiTaskEvent extends Model
{
    public const KINDS = [
        'started', 'asked', 'answered', 'extracted', 'rejected_value', 'validated', 'summary_shown', 'token_issued',
        'confirmed', 'executed', 'failed', 'cancelled', 'expired', 'limit_hit', 'reopened', 'confirm_refused',
    ];

    public $timestamps = false;

    protected $fillable = [
        'task_id',
        'at',
        'actor_user_id',
        'ip',
        'kind',
        'field',
        'value_before',
        'value_after',
        'payload',
        'hash',
        'prev_hash',
    ];

    protected $casts = [
        'task_id' => 'integer',
        'actor_user_id' => 'integer',
        'at' => 'datetime',
        'value_before' => 'json',
        'value_after' => 'json',
        'payload' => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::updating(fn () => throw new \LogicException('Task events are append-only.'));
        static::deleting(fn () => throw new \LogicException('Task events are append-only.'));
    }

    // RELATIONSHIPS
    public function task(): BelongsTo
    {
        return $this->belongsTo(AiTask::class, 'task_id');
    }
}
