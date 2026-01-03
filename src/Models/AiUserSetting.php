<?php

declare(strict_types=1);

namespace Condoedge\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUserSetting extends Model
{
    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::creating(function (self $model) {
            // Set defaults from config when creating new records
            $defaults = $model->getConfigDefaults();
            foreach ($defaults as $key => $value) {
                if ($model->getAttribute($key) === null) {
                    $model->setAttribute($key, $value);
                }
            }
        });
    }

    /**
     * Get default values from config.
     */
    public function getConfigDefaults(): array
    {
        return [
            'show_avatars' => config('ai.chat.show_avatars', true),
            'show_timestamps' => config('ai.chat.show_timestamps', false),
            'show_metrics' => config('ai.chat.show_metrics', false),
            'show_suggestions' => config('ai.chat.show_suggestions', true),
            'enable_copy' => config('ai.chat.enable_copy', true),
            'enable_feedback' => config('ai.chat.enable_feedback', false),
            'enable_regenerate' => config('ai.chat.enable_regenerate', true),
            'enable_edit' => config('ai.chat.enable_edit', true),
            'response_style' => config('ai.chat.response_style', 'friendly'),
            'ui_theme' => config('ai.ui.theme', 'indigo'),
            'enable_animations' => config('ai.chat.enable_animations', true),
            'animation_speed' => config('ai.chat.animation_speed', 'normal'),
            'typing_animation_style' => config('ai.chat.typing_animation_style', 'dots'),
        ];
    }

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
        'enable_animations',
        'animation_speed',
        'typing_animation_style',
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
        'enable_animations' => 'boolean',
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