<?php

declare(strict_types=1);

namespace Condoedge\Ai\Models;

use Condoedge\Ai\DTOs\Tasks\TaskDraft;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One run of a task definition in a conversation: its draft, status, counters and outcome.
 * Written only through TaskStore, which bumps `version` on every draft change.
 */
class AiTask extends Model
{
    public const STATUS_COLLECTING = 'collecting';
    public const STATUS_REVIEWING = 'reviewing';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_EXECUTING = 'executing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'uuid',
        'definition_key',
        'conversation_id',
        'user_id',
        'team_id',
        'status',
        'draft',
        'version',
        'turns',
        'llm_calls',
        'tokens',
        'expires_at',
        'confirmed_at',
        'executed_at',
        'outcome',
    ];

    protected $casts = [
        'draft' => 'array',
        'outcome' => 'array',
        'version' => 'integer',
        'turns' => 'integer',
        'llm_calls' => 'integer',
        'tokens' => 'integer',
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    // RELATIONSHIPS
    public function events(): HasMany
    {
        return $this->hasMany(AiTaskEvent::class, 'task_id');
    }

    // ATTRIBUTES
    public function draft(): TaskDraft
    {
        return TaskDraft::fromArray($this->draft, $this->version);
    }
}
