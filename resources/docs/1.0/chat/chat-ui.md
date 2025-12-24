# AI Chat UI Components

Beautiful, production-ready chat interface components for the AI package. Built on Kompo with proper AJAX handling and session persistence.

## Overview

The AI Chat system provides two main components:

| Component | Description | Use Case |
|-----------|-------------|----------|
| `AiChatModal` | Modal dialog with full chat | Popup chat triggered by buttons |
| `AiChatFloating` | Floating action button | Global chat access from any page |

## Quick Start

### 1. Add Floating Button to Your Layout

The simplest way to add AI chat to your application:

```php
use Condoedge\Ai\Kompo\AiChatFloating;

// In your layout component
public function render()
{
    return _Rows(
        // Your page content...

        // Add floating chat button
        new AiChatFloating()
    );
}
```

That's it! A floating button appears in the bottom-right corner. Click it to open the AI chat modal.

### 2. Open Chat from Any Button

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

### 3. Customize with Props

```php
public function openChat()
{
    return new AiChatModal(null, [
        'welcome_title' => 'Sales Assistant',
        'welcome_message' => 'Ask me about your sales data.',
        'example_questions' => [
            'What were yesterday\'s sales?',
            'Show top customers',
            'Revenue by region',
        ],
    ]);
}
```

## AiChatModal

The main chat interface, displayed as a modal dialog.

### Basic Usage

```php
use Condoedge\Ai\Kompo\AiChatModal;

// Default configuration from config/ai.php
return new AiChatModal();

// With custom props
return new AiChatModal(null, [
    'welcome_title' => 'AI Assistant',
    'welcome_message' => 'How can I help you today?',
    'example_questions' => ['Question 1', 'Question 2'],
    'theme' => 'modern',
    'show_metrics' => true,
]);
```

### Available Props

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `welcome_title` | string | 'AI Assistant' | Title on welcome screen |
| `welcome_message` | string | 'Ask me anything...' | Message on welcome screen |
| `example_questions` | array | [] | Clickable example questions |
| `theme` | string | 'modern' | UI theme |
| `show_timestamps` | bool | false | Show message timestamps |
| `show_avatars` | bool | true | Show user/assistant avatars |
| `show_typing_indicator` | bool | true | Show typing animation |
| `show_metrics` | bool | false | Show execution time |
| `show_suggestions` | bool | true | Show follow-up suggestions |
| `max_suggestions` | int | 3 | Max suggestions to display |
| `enable_copy` | bool | true | Enable copy message button |
| `enable_feedback` | bool | false | Enable thumbs up/down |
| `enable_markdown` | bool | true | Render markdown in responses |
| `persist_history` | bool | true | Save chat to session |
| `max_messages` | int | 50 | Max messages to keep |
| `input_placeholder` | string | 'Ask a question...' | Input placeholder text |

### Modal Title

```php
$modal = new AiChatModal();
$modal->_Title = 'Custom Title';
return $modal;
```

## AiChatFloating

A floating action button that opens the chat modal.

### Basic Usage

```php
use Condoedge\Ai\Kompo\AiChatFloating;

// Default configuration
new AiChatFloating()

// With props
new AiChatFloating([
    'position' => 'bottom-left',
    'theme' => 'solid',
    'pulse' => true,
])
```

### Fluent Configuration

```php
(new AiChatFloating())
    ->position('bottom-left')     // Position on screen
    ->size('lg')                  // Button size
    ->theme('gradient')           // Visual theme
    ->withLabel('Need help?')     // Add text label
    ->withPulse()                 // Add pulse animation
    ->modalConfig([               // Pass config to modal
        'welcome_title' => 'Help Center',
        'example_questions' => ['How do I...?'],
    ]);
```

### Position Options

```php
->position('bottom-right')  // Default
->position('bottom-left')
->position('top-right')
->position('top-left')
```

### Size Options

```php
->size('sm')   // 48x48px
->size('md')   // 56x56px
->size('lg')   // 64x64px (default)
```

### Theme Options

```php
->theme('gradient')  // Purple gradient (default)
->theme('solid')     // Solid indigo
->theme('outline')   // White with border
->theme('dark')      // Dark gray
```

### With Label

```php
// Button expands to include text
(new AiChatFloating())->withLabel('Chat with AI')
```

### Pulse Animation

```php
// Adds attention-grabbing pulse effect
(new AiChatFloating())->withPulse()
```

## Configuration

All defaults can be set in `config/ai.php`:

```php
'chat' => [
    // Service implementation
    'service' => \Condoedge\Ai\Services\Chat\AiChatService::class,

    // Theme: modern, minimal, gradient, glassmorphism
    'theme' => 'modern',
    'primary_color' => '#6366f1',

    // Welcome screen
    'welcome' => [
        'title' => 'AI Assistant',
        'message' => 'Ask me anything about your data.',
    ],

    // Example questions for welcome screen
    'example_questions' => [
        'How many records do we have?',
        'Show recent activity',
    ],

    // Message display
    'show_timestamps' => false,
    'show_avatars' => true,
    'show_typing_indicator' => true,
    'show_suggestions' => true,
    'show_metrics' => false,
    'max_suggestions' => 3,

    // Features
    'enable_copy' => true,
    'enable_feedback' => false,
    'enable_markdown' => true,

    // Persistence
    'persist_history' => true,
    'max_messages' => 50,

    // Input
    'input_placeholder' => 'Ask a question...',
    'auto_focus' => true,
],
```

## Custom Chat Service

Create a custom service to change how the AI processes questions:

### 1. Implement the Interface

```php
namespace App\Services;

use Condoedge\Ai\Services\Chat\AiChatServiceInterface;
use Condoedge\Ai\Services\Chat\AiChatMessage;
use Condoedge\Ai\Services\Chat\AiChatResponseData;

class CustomChatService implements AiChatServiceInterface
{
    public function ask(string $question, array $options = []): AiChatMessage
    {
        // Your custom AI logic here
        $answer = $this->processQuestion($question);

        // Build response with optional rich data
        $responseData = AiChatResponseData::text()
            ->withSuggestions(['Follow-up 1', 'Follow-up 2']);

        return AiChatMessage::assistant($answer, $responseData);
    }

    public function askWithHistory(
        string $question,
        array $history,
        array $options = []
    ): AiChatMessage {
        // Use conversation history for context
        return $this->ask($question, $options);
    }

    public function getSuggestions(string $question, string $response): array
    {
        return [
            'Tell me more',
            'Show related data',
        ];
    }

    public function getExampleQuestions(): array
    {
        return [
            'What can you help me with?',
            'Show me a summary',
        ];
    }

    public function isAvailable(): bool
    {
        return true; // Check if AI service is configured
    }

    protected function processQuestion(string $question): string
    {
        // Call your AI backend (OpenAI, Anthropic, custom, etc.)
        return "Response to: {$question}";
    }
}
```

### 2. Register in Service Provider

```php
// app/Providers/AppServiceProvider.php
use Condoedge\Ai\Services\Chat\AiChatServiceInterface;
use App\Services\CustomChatService;

public function register()
{
    $this->app->bind(AiChatServiceInterface::class, CustomChatService::class);
}
```

## Response Data Types

The chat supports rich response formats beyond plain text.

### Text Response

```php
use Condoedge\Ai\Services\Chat\AiChatResponseData;

$responseData = AiChatResponseData::text();
```

### Table Response

Display tabular data in the chat:

```php
$responseData = AiChatResponseData::table(
    headers: ['Name', 'Email', 'Orders'],
    rows: [
        ['John Doe', 'john@example.com', 15],
        ['Jane Smith', 'jane@example.com', 23],
    ]
);
```

### Metric Response

Display a large number/value:

```php
$responseData = AiChatResponseData::metric(
    label: 'Total Revenue',
    value: '$125,430',
    icon: '💰',
    trend: '+12%'
);
```

### List Response

Display a structured list:

```php
$responseData = AiChatResponseData::list([
    ['title' => 'Order #1234', 'description' => 'Pending - $150'],
    ['title' => 'Order #1235', 'description' => 'Shipped - $89'],
]);
```

### Adding Actions and Suggestions

```php
$responseData = AiChatResponseData::text()
    ->withActions([
        ['label' => 'View Details', 'url' => '/orders/123'],
        ['label' => 'Export CSV', 'url' => '/export'],
    ])
    ->withSuggestions([
        'Show order details',
        'Filter by status',
        'Export this data',
    ])
    ->withMetrics(
        executionTimeMs: 145,
        rowsReturned: 25,
        query: 'MATCH (n:Order) RETURN n'
    );
```

## Architecture

The chat system is built with a trait-based architecture for flexibility:

### Core Traits

| Trait | Purpose |
|-------|---------|
| `HasAiChatConfig` | Configuration loading and persistence |
| `HasAiMessages` | Message history management |
| `HasMessageBubbles` | Message bubble rendering |
| `HasWelcomeScreen` | Welcome screen rendering |

### Component Structure

```
AiChatModal
├── HasAiChatConfig      (config from props/config)
├── HasAiMessages        (message history in session)
├── HasMessageBubbles    (renders each message)
└── HasWelcomeScreen     (renders when no messages)
```

### Extending with Custom Traits

Create your own traits to customize behavior:

```php
namespace App\Kompo\Traits;

trait HasCustomMessageBubbles
{
    protected function renderMessageBubble($message, $config)
    {
        // Your custom rendering logic
        return _Rows(
            _Html($message->content)->class('my-custom-bubble')
        );
    }
}
```

Then create a custom modal:

```php
namespace App\Kompo;

use Condoedge\Ai\Kompo\AiChatModal;
use App\Kompo\Traits\HasCustomMessageBubbles;

class CustomChatModal extends AiChatModal
{
    use HasCustomMessageBubbles;
}
```

## Examples

### Dashboard with Chat Button

```php
class Dashboard extends Form
{
    public function render()
    {
        return _Rows(
            _FlexBetween(
                _TitleMain('Dashboard'),
                _Button('Ask AI')
                    ->icon(_Sax('message-question', 18))
                    ->selfGet('openAiChat')
                    ->inModal()
            )->class('mb-6'),

            // Dashboard content...
        );
    }

    public function openAiChat()
    {
        return new AiChatModal(null, [
            'welcome_title' => 'Dashboard Assistant',
            'example_questions' => [
                'Show today\'s summary',
                'What needs my attention?',
                'Compare to last week',
            ],
        ]);
    }
}
```

### Global Floating Button in Layout

```php
class MainLayout extends Form
{
    public function render()
    {
        return _Rows(
            // Header, navigation, content...

            // Global floating chat - appears on all pages
            (new AiChatFloating())
                ->position('bottom-right')
                ->theme('gradient')
                ->withPulse()
                ->modalConfig([
                    'welcome_title' => 'Help Center',
                    'welcome_message' => 'Ask me anything!',
                ])
        );
    }
}
```

### Context-Specific Chat

```php
class OrderDetails extends Form
{
    public $model = Order::class;

    public function render()
    {
        return _Rows(
            // Order details...

            _Button('Ask about this order')
                ->selfGet('openOrderChat')
                ->inModal()
        );
    }

    public function openOrderChat()
    {
        return new AiChatModal(null, [
            'welcome_title' => "Order #{$this->model->id} Assistant",
            'welcome_message' => 'Ask me about this order.',
            'example_questions' => [
                'What is the order status?',
                'Show shipping details',
                'List all items',
            ],
            // Pass order context to service
            'context' => [
                'order_id' => $this->model->id,
            ],
        ]);
    }
}
```

## Troubleshooting

### Chat not responding

1. Check that `AiChatServiceInterface` is bound in your service provider
2. Verify your AI API keys are configured in `.env`
3. Check Laravel logs for errors

### Messages not persisting

1. Ensure `persist_history` is `true` in config
2. Check that sessions are working in your app
3. Verify `max_messages` isn't set too low

### Styling issues

1. Make sure Tailwind CSS is configured
2. Check that the component classes aren't being purged
3. Try adding ai component paths to your Tailwind content config:

```js
// tailwind.config.js
content: [
    './vendor/condoedge/ai/src/Kompo/**/*.php',
]
```
