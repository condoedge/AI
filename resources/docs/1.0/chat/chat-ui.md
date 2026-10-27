# AI Chat UI Components

Production-ready chat interface components for the AI package. Built on Kompo with conversation persistence, file references, regenerate/edit, feedback, and a response-style selector.

## Overview

Every chat surface renders the **same shared body** (conversation sidebar + message list + chat JS). They differ only in chrome (how they are wrapped/opened). The body lives on `AbstractChatSurface`; the concrete surfaces are thin shells.

| Component | Description | Opened via |
|-----------|-------------|------------|
| `AiChatModal` | Chat inside a modal overlay | `->selfGet(...)->inModal()` |
| `AiChatDrawer` | Chat inside a slide-over drawer | `->selfGet(...)->inDrawer()` |
| `AiChatScreen` | Routed, full-page chat | route `ai.chat.screen` (`GET /ai/chat`) |
| `AiChatFloating` | Floating action button that opens one of the above | placed in a layout |
| `AiChatPanel` | Backward-compatible alias of `AiChatModal` | same as `AiChatModal` |

All of these live in `Condoedge\Ai\Kompo`.

> The chat surfaces read their configuration from `config('ai.chat')` (resolved through `ChatSettingsInterface`), **not** from a constructor props array. The only prop a surface reads directly is `page_context` (see [Page Context](#page-context)). To change titles, example questions, features, etc., edit `config/ai.php` or the matching `AI_CHAT_*` env vars.

## Quick Start

### 1. Add the Floating Button to Your Layout

```php
use Condoedge\Ai\Kompo\AiChatFloating;

// In your layout component
public function render()
{
    return _Rows(
        // Your page content...

        // Floating chat button (bottom-right)
        new AiChatFloating()
    );
}
```

The button appears in the bottom-right corner. Clicking it opens the surface configured by `config('ai.chat.surface')` — a modal (default), a drawer, or it navigates to the full-page screen.

### 2. Open the Chat from Any Button

```php
use Condoedge\Ai\Kompo\AiChatModal;

class MyPage extends Form
{
    public function render()
    {
        return _Rows(
            _Button('Ask AI')
                ->selfGet('openChat')
                ->inModal()
        );
    }

    public function openChat()
    {
        return new AiChatModal();
    }
}
```

Swap `AiChatModal` + `->inModal()` for `AiChatDrawer` + `->inDrawer()` to open it in a slide-over instead.

### 3. Use the Full-Page Screen

The package registers a route for `AiChatScreen`:

```php
// routes/web.php (registered by the package)
Route::get('ai/chat', AiChatScreen::class)->name('ai.chat.screen');
```

Link to it with `->redirect('ai.chat.screen')`, or set `AI_CHAT_SURFACE=screen` so the floating button navigates there.

## Choosing the Surface (Floating Button)

`AiChatFloating` opens whichever surface is configured. Set it once:

```php
// config/ai.php → 'chat' => [ ... ]
'surface' => env('AI_CHAT_SURFACE', 'modal'), // 'modal' | 'drawer' | 'screen'
```

```env
AI_CHAT_SURFACE=drawer
```

There is no fluent builder on `AiChatFloating` (no `->position()`, `->theme()`, `->withLabel()`, `->withPulse()`, `->modalConfig()`). Visual defaults come from the theme configuration (see Theming below).

## Page Context

A host can make the chat aware of the page/record it was opened from by passing a single `page_context` array prop. It is forwarded through the surface to the chat pipeline (and used to pick per-entity welcome examples via `config('ai.chat.page_examples')`):

```php
public function openChat()
{
    return new AiChatModal(null, [
        'page_context' => [
            'entity_type' => 'Order',
            'entity_id' => $this->model->id,
        ],
    ]);
}
```

Arrays are used because Kompo serializes props across the request boundary; an object would not survive. Absent or empty `page_context` simply means the chat is not page-aware.

## Configuration

All chat defaults live under `config('ai.chat')` in `config/ai.php`, each backed by an `AI_CHAT_*` env var:

```php
'chat' => [
    // Service implementation (must implement AiChatServiceInterface)
    'service' => env('AI_CHAT_SERVICE', \Condoedge\Ai\Services\Chat\AiChatService::class),

    // Surface the floating button opens: 'modal' | 'drawer' | 'screen'
    'surface' => env('AI_CHAT_SURFACE', 'modal'),

    // Welcome screen
    'welcome_title' => env('AI_CHAT_WELCOME_TITLE', 'AI Assistant'),
    'welcome_message' => env('AI_CHAT_WELCOME_MESSAGE', 'Ask me anything about your data...'),

    // Example questions on the welcome screen
    'example_questions' => [
        'How many records do we have?',
        'Show me recent activity',
    ],

    // Per-entity-type welcome examples, keyed by page_context.entity_type
    'page_examples' => [
        // 'Order' => ['Show this order\'s payment history', 'Who placed this order?'],
    ],

    // Conversation history sent to the LLM
    'max_history_messages' => env('AI_CHAT_MAX_HISTORY_MESSAGES', 10),

    // UI features
    'show_timestamps' => env('AI_CHAT_SHOW_TIMESTAMPS', false),
    'show_avatars' => env('AI_CHAT_SHOW_AVATARS', true),
    'show_typing' => env('AI_CHAT_SHOW_TYPING', true),
    'show_suggestions' => env('AI_CHAT_SHOW_SUGGESTIONS', true),
    'max_suggestions' => env('AI_CHAT_MAX_SUGGESTIONS', 3),
    'show_metrics' => env('AI_CHAT_SHOW_METRICS', false),

    // Features
    'enable_copy' => env('AI_CHAT_ENABLE_COPY', true),
    'enable_feedback' => env('AI_CHAT_ENABLE_FEEDBACK', false),
    'enable_regenerate' => env('AI_CHAT_ENABLE_REGENERATE', true),
    'enable_edit' => env('AI_CHAT_ENABLE_EDIT', true),
    'response_style' => env('AI_CHAT_RESPONSE_STYLE', 'friendly'),

    // Input
    'input_placeholder' => env('AI_CHAT_INPUT_PLACEHOLDER', 'ai.chat.input-placeholder'),
],
```

See `config/ai.php` for the complete, commented list.

## Theming

Theme resolution is configured under `config('ai.ui')`:

```php
'ui' => [
    // ConfigChatThemeFactory (config only) or UserChatThemeFactory (per-user, falls back to config)
    'factory' => env('AI_UI_FACTORY', \Condoedge\Ai\Services\UI\UserChatThemeFactory::class),

    // Built-in themes: 'indigo', 'green', 'config'
    'theme' => env('AI_UI_THEME', 'green'),
],
```

See [Theming](theming) for the full theme surface.

## Custom Chat Service

Chat turns are processed by `AskQuestionUseCase` (invoked through `ChatPresenter`), which owns the security gate, conversation context, `AI::answerQuestion` and message persistence. `AiChatServiceInterface` is only the availability seam: it has a single method, `isAvailable()`, which drives the chat surface's online badge. Implement and bind it to customize that check.

### 1. Implement the Interface

```php
namespace App\Services;

use Condoedge\Ai\Services\Chat\AiChatServiceInterface;

class CustomChatService implements AiChatServiceInterface
{
    public function isAvailable(): bool
    {
        return true; // e.g. check API keys / store connectivity
    }
}
```

### 2. Register It

```php
// config/ai.php
'chat' => [
    'service' => \App\Services\CustomChatService::class,
    // ...
],
```

Or bind the interface in a service provider:

```php
use Condoedge\Ai\Services\Chat\AiChatServiceInterface;
use App\Services\CustomChatService;

public function register()
{
    $this->app->bind(AiChatServiceInterface::class, CustomChatService::class);
}
```

## Architecture

The surfaces share one body and a set of traits.

### Class Structure

```
AbstractChatSurface          (shared body: sidebar + MessagesQuery + chat JS)
├── AiChatModal              (modal chrome)
│   └── AiChatPanel          (alias of AiChatModal)
├── AiChatDrawer             (slide-over chrome)
└── AiChatScreen             (full-page chrome)
```

### Traits used by `AbstractChatSurface`

| Trait | Purpose |
|-------|---------|
| `HasChatSettings` | Reads chat config/settings (`settings()`, `cfg()`) |
| `HasChatTheme` | Theme/gradient helpers |
| `HasAvatars` | User/assistant avatar rendering |
| `HasConversationCreation` | `createConversation()` action |
| `HasMethodsAsProperties` | Lets snake_case props proxy to camelCase theme methods |

### Extending a Surface

Subclass any surface to override chrome or behavior:

```php
namespace App\Kompo;

use Condoedge\Ai\Kompo\AiChatModal;

class CustomChatModal extends AiChatModal
{
    // Override surfaceStyle()/surfaceClass() for custom chrome,
    // or add your own action methods.
}
```

## Troubleshooting

### Chat not responding

1. Confirm `config('ai.chat.service')` (or the `AiChatServiceInterface` binding) resolves to a working service.
2. Verify your LLM/store credentials are configured in `.env`.
3. Check Laravel logs for errors. `isAvailable()` returning `false` renders the offline state.

### Messages not persisting

1. Conversations are stored in the database: ensure the package migrations ran (`ai_conversations` / `ai_messages` tables).
2. Check Laravel logs for errors raised while saving the turn.

### Styling issues

1. Make sure Tailwind CSS is configured.
2. Ensure the component classes aren't being purged:

```js
// tailwind.config.js
content: [
    './vendor/condoedge/ai/src/Kompo/**/*.php',
]
```
