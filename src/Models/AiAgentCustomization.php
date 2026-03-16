<?php

declare(strict_types=1);

namespace Condoedge\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAgentCustomization extends Model
{
    protected $fillable = [
        'user_id',
        'team_id',
        'agent_id',
        'custom_instructions',
        'custom_business_rules',
        'custom_example_questions',
    ];

    protected $casts = [
        'custom_business_rules' => 'array',
        'custom_example_questions' => 'array',
    ];

    // RELATIONSHIPS
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', 'App\\Models\\User'));
    }
}
