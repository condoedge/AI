# Fix AI Settings Modal - Direct Columns Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix the ChatSettingsModal save functionality by adding direct columns to the `ai_user_settings` table instead of using JSON.

**Architecture:** Replace the `chat_settings` JSON column with 9 direct columns. This allows Kompo to save fields automatically without custom `beforeSave()` logic. Update `UserChatSettings` service to read from direct model properties.

**Tech Stack:** Laravel migrations, Eloquent model, Kompo forms

---

## Task 1: Create Migration to Add Columns

**Files:**
- Create: `database/migrations/2026_01_03_000001_add_settings_columns_to_ai_user_settings_table.php`

**Step 1: Create the migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_user_settings', function (Blueprint $table) {
            // Boolean toggle settings
            $table->boolean('show_avatars')->default(true);
            $table->boolean('show_timestamps')->default(false);
            $table->boolean('show_metrics')->default(false);
            $table->boolean('show_suggestions')->default(true);
            $table->boolean('enable_copy')->default(true);
            $table->boolean('enable_feedback')->default(true);
            $table->boolean('enable_regenerate')->default(true);
            $table->boolean('enable_edit')->default(true);

            // String setting
            $table->string('response_style')->default('friendly');

            // Remove JSON column (no longer needed)
            $table->dropColumn('chat_settings');
        });
    }

    public function down(): void
    {
        Schema::table('ai_user_settings', function (Blueprint $table) {
            // Restore JSON column
            $table->json('chat_settings')->nullable();

            // Remove direct columns
            $table->dropColumn([
                'show_avatars',
                'show_timestamps',
                'show_metrics',
                'show_suggestions',
                'enable_copy',
                'enable_feedback',
                'enable_regenerate',
                'enable_edit',
                'response_style',
            ]);
        });
    }
};
```

**Step 2: Verify syntax**

Run: `php -l database/migrations/2026_01_03_000001_add_settings_columns_to_ai_user_settings_table.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add database/migrations/2026_01_03_000001_add_settings_columns_to_ai_user_settings_table.php
git commit -m "migration: add direct settings columns to ai_user_settings"
```

---

## Task 2: Update AiUserSetting Model

**Files:**
- Modify: `src/Models/AiUserSetting.php`

**Step 1: Update $fillable array (replace lines 12-17)**

```php
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
```

**Step 2: Update $casts array (replace lines 19-22)**

```php
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
```

**Step 3: Remove JSON helper methods**

Delete these methods (they used the `chat_settings` JSON column):
- `getSetting()` (lines 66-71)
- `getSettings()` (lines 76-79)
- `setSetting()` (lines 84-90)
- `setSettings()` (lines 95-100)

**Step 4: Verify syntax**

Run: `php -l src/Models/AiUserSetting.php`
Expected: `No syntax errors detected`

**Step 5: Commit**

```bash
git add src/Models/AiUserSetting.php
git commit -m "refactor(model): use direct columns instead of chat_settings JSON"
```

---

## Task 3: Update UserChatSettings Service

**Files:**
- Modify: `src/Services/Settings/UserChatSettings.php`

**Step 1: Update the get() method (replace lines 37-67)**

```php
    /**
     * Get a setting value using the priority chain.
     *
     * Resolution order:
     * 1. Constructor overrides
     * 2. User DB settings (direct columns, if authenticated)
     * 3. Session settings
     * 4. Config file settings
     * 5. Default value
     *
     * @param string $key     The setting key to retrieve
     * @param mixed  $default The default value if no setting found
     *
     * @return mixed The resolved setting value
     */
    protected function get(string $key, mixed $default): mixed
    {
        // Check constructor overrides first (from parent)
        if (isset($this->settings[$key])) {
            return $this->settings[$key];
        }

        // 1. Check user DB direct columns (if authenticated)
        if (auth()->check()) {
            $userSetting = AiUserSetting::forUser(auth()->id());

            // Read from direct model property if it exists and is not null
            if ($userSetting->getAttribute($key) !== null) {
                return $userSetting->getAttribute($key);
            }
        }

        // 2. Check session
        $sessionSettings = session('ai_chat_settings', []);
        if (isset($sessionSettings[$key])) {
            return $sessionSettings[$key];
        }

        // 3. Check config
        $configValue = config("ai.chat.{$key}");
        if ($configValue !== null) {
            return $configValue;
        }

        // 4. Return default
        return $default;
    }
```

**Step 2: Verify syntax**

Run: `php -l src/Services/Settings/UserChatSettings.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add src/Services/Settings/UserChatSettings.php
git commit -m "refactor(settings): read from direct columns instead of JSON"
```

---

## Task 4: Verify ChatSettingsModal Works

**Files:**
- Review: `src/Kompo/Modals/ChatSettingsModal.php`

**Step 1: Verify afterSave() reads direct properties**

The `afterSave()` method (lines 155-172) already reads direct properties:
```php
'show_avatars' => $this->model->show_avatars,
'show_timestamps' => $this->model->show_timestamps,
// etc.
```

This should work automatically with the new direct columns. No changes needed.

**Step 2: Verify syntax of all modified files**

Run: `php -l src/Models/AiUserSetting.php && php -l src/Services/Settings/UserChatSettings.php`
Expected: Both pass

**Step 3: Commit (if any changes were needed)**

No commit needed if no changes required.

---

## Summary

| Before | After |
|--------|-------|
| `chat_settings` JSON column | 9 direct columns |
| Custom JSON helper methods | Direct Eloquent access |
| Kompo can't save automatically | Kompo saves automatically |
| `UserChatSettings` reads JSON | `UserChatSettings` reads properties |

**Files changed:**
1. New migration (add columns, drop `chat_settings`)
2. `AiUserSetting.php` (update `$fillable`, `$casts`, remove JSON methods)
3. `UserChatSettings.php` (update `get()` to use direct properties)
4. `ChatSettingsModal.php` (no changes needed - already works)

**After running migration:** The modal will save settings directly to columns and Kompo handles it automatically.
