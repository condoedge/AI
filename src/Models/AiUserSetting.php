<?php

declare(strict_types=1);

namespace Condoedge\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUserSetting extends Model
{
    protected $fillable = [
        'user_id',
        'ui_theme',
        'ui_colors',
        // Direct settings columns
        'show_avatars',
        'show_timestamps',
        'show_metrics',
        'show_suggestions',
        'enable_copy',
        'enable_feedback',
        'enable_regenerate',
        'enable_edit',
        'response_style',
    ];

    protected $casts = [
        'ui_colors' => 'array',
        'show_avatars' => 'boolean',
        'show_timestamps' => 'boolean',
        'show_metrics' => 'boolean',
        'show_suggestions' => 'boolean',
        'enable_copy' => 'boolean',
        'enable_feedback' => 'boolean',
        'enable_regenerate' => 'boolean',
        'enable_edit' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', 'App\\Models\\User'));
    }

    /**
     * Get or create settings for a user.
     */
    public static function forUser(int $userId): self
    {
        return static::firstOrCreate(['user_id' => $userId]);
    }

    /**
     * Get the theme name.
     */
    public function getThemeName(): ?string
    {
        return $this->ui_theme;
    }

    /**
     * Get custom color overrides.
     */
    public function getColorOverrides(): array
    {
        return $this->ui_colors ?? [];
    }

    /**
     * Set theme preference.
     */
    public function setTheme(?string $themeName, array $colorOverrides = []): self
    {
        $this->update([
            'ui_theme' => $themeName,
            'ui_colors' => !empty($colorOverrides) ? $colorOverrides : null,
        ]);
        return $this;
    }

}