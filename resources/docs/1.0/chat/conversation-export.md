# Conversation Export

Export AI chat conversations to various formats for archiving, sharing, or offline access.

---

## Overview

The Conversation Export system allows you to export complete chat conversations to different file formats. This is useful for:

- **Archiving** - Save important conversations for future reference
- **Sharing** - Export conversations to share with team members
- **Documentation** - Convert chat interactions into documentation
- **Compliance** - Keep records of AI interactions for audit purposes

The system uses a factory pattern to support multiple export formats, making it easy to add new formats as needed.

### Key Features

- **Multiple Formats** - Export to Markdown (more formats can be added)
- **Factory Pattern** - Extensible architecture for custom exporters
- **Preserves Context** - Exports include timestamps, roles, and message content
- **Clean Output** - Well-formatted exports ready for use

---

## Available Formats

### Markdown (.md)

The default export format produces clean, readable Markdown files.

**Exporter:** `ExportConversationMdService`

**Output Example:**

```markdown
# My Conversation Title

Exported: January 13, 2026 3:45 PM

---

**You** (3:30 PM):

How do I configure authentication?

---

**AI Assistant** (3:30 PM):

You can configure authentication by...

---
```

**Features:**
- Conversation title as heading
- Export timestamp
- Role labels (You / AI Assistant)
- Message timestamps
- Clean separator between messages

---

## Usage

### Using the Factory

The recommended way to export conversations is through the `ExporterConversationFactory`:

```php
use Condoedge\Ai\Services\Chat\Exporter\ExporterConversationFactory;
use Condoedge\Ai\Models\AiConversation;

// Get the factory
$factory = new ExporterConversationFactory();

// Create an exporter for the desired format
$exporter = $factory->make('md');

// Get a conversation
$conversation = AiConversation::with('messages')->find($id);

// Export to markdown string
$markdown = $exporter->export($conversation);

// Get the file extension
$extension = $exporter->getFileExtension(); // 'md'
```

### Download Response

To provide a download to the user:

```php
use Condoedge\Ai\Services\Chat\Exporter\ExporterConversationFactory;
use Illuminate\Support\Str;

public function downloadConversation(AiConversation $conversation, string $format = 'md')
{
    $factory = new ExporterConversationFactory();
    $exporter = $factory->make($format);

    $content = $exporter->export($conversation);
    $extension = $exporter->getFileExtension();

    $filename = Str::slug($conversation->title ?? 'conversation') . '.' . $extension;

    return response($content)
        ->header('Content-Type', 'text/markdown')
        ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
}
```

### In a Kompo Component

```php
use Condoedge\Ai\Services\Chat\Exporter\ExporterConversationFactory;
use Condoedge\Ai\Models\AiConversation;

class ConversationList extends Table
{
    public function query()
    {
        return AiConversation::where('user_id', auth()->id());
    }

    public function headers()
    {
        return [
            _Th('Title'),
            _Th('Actions'),
        ];
    }

    public function render($conversation)
    {
        return _TableRow(
            _Html($conversation->title),
            _Link('Export')
                ->selfGet('exportConversation', ['id' => $conversation->id])
                ->inNewTab()
        );
    }

    public function exportConversation($id)
    {
        $conversation = AiConversation::with('messages')
            ->where('user_id', auth()->id())
            ->findOrFail($id);

        $factory = new ExporterConversationFactory();
        $exporter = $factory->make('md');

        $content = $exporter->export($conversation);
        $filename = Str::slug($conversation->title ?? 'conversation') . '.md';

        return response($content)
            ->header('Content-Type', 'text/markdown')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }
}
```

---

## Creating Custom Exporters

You can create custom exporters to support additional formats like PDF, HTML, or plain text.

### 1. Implement the Interface

Create a class that implements `ExportConversationServiceInterface`:

```php
namespace App\Services\Exporters;

use Condoedge\Ai\Services\Chat\Exporter\ExportConversationServiceInterface;

class ExportConversationPdfService implements ExportConversationServiceInterface
{
    /**
     * Export the conversation to PDF format
     *
     * @param \Condoedge\Ai\Models\AiConversation $conversation
     * @return string The PDF content
     */
    public function export($conversation)
    {
        // Build PDF content using your preferred PDF library
        $pdf = app('dompdf.wrapper');

        $html = view('exports.conversation', [
            'conversation' => $conversation,
            'exportedAt' => now(),
        ])->render();

        $pdf->loadHTML($html);

        return $pdf->output();
    }

    /**
     * Get the file extension for this format
     *
     * @return string
     */
    public function getFileExtension(): string
    {
        return 'pdf';
    }
}
```

### 2. Create a Plain Text Exporter

```php
namespace App\Services\Exporters;

use Condoedge\Ai\Services\Chat\Exporter\ExportConversationServiceInterface;

class ExportConversationTxtService implements ExportConversationServiceInterface
{
    public function export($conversation)
    {
        $output = $conversation->title ?? 'Conversation';
        $output .= "\n";
        $output .= str_repeat('=', strlen($output) - 1);
        $output .= "\n\n";
        $output .= "Exported: " . now()->format('F j, Y g:i A') . "\n\n";

        foreach ($conversation->messages as $message) {
            $role = $message->role === 'user' ? 'You' : 'AI Assistant';
            $time = $message->created_at->format('g:i A');

            $output .= "[{$time}] {$role}:\n";
            $output .= $message->content . "\n\n";
        }

        return $output;
    }

    public function getFileExtension(): string
    {
        return 'txt';
    }
}
```

### 3. Register Custom Exporters

Register your custom exporters with the factory:

```php
use Condoedge\Ai\Services\Chat\Exporter\ExporterConversationFactory;
use App\Services\Exporters\ExportConversationPdfService;
use App\Services\Exporters\ExportConversationTxtService;

// In a service provider or controller
$factory = new ExporterConversationFactory();

// Register custom exporters
$factory->registerExporter('pdf', ExportConversationPdfService::class);
$factory->registerExporter('txt', ExportConversationTxtService::class);

// Now you can use them
$pdfExporter = $factory->make('pdf');
$txtExporter = $factory->make('txt');
```

### 4. Using a Singleton Factory

For consistent access throughout your application, register the factory as a singleton:

```php
// app/Providers/AppServiceProvider.php
use Condoedge\Ai\Services\Chat\Exporter\ExporterConversationFactory;
use App\Services\Exporters\ExportConversationPdfService;
use App\Services\Exporters\ExportConversationTxtService;

public function register()
{
    $this->app->singleton(ExporterConversationFactory::class, function ($app) {
        $factory = new ExporterConversationFactory();

        // Register all custom exporters
        $factory->registerExporter('pdf', ExportConversationPdfService::class);
        $factory->registerExporter('txt', ExportConversationTxtService::class);

        return $factory;
    });
}
```

Then inject the factory where needed:

```php
public function export(ExporterConversationFactory $factory, $conversationId, $format)
{
    $exporter = $factory->make($format);
    // ...
}
```

---

## Interface Reference

### ExportConversationServiceInterface

```php
interface ExportConversationServiceInterface
{
    /**
     * Export the conversation to the target format
     *
     * @param \Condoedge\Ai\Models\AiConversation $conversation The conversation to export
     * @return string The exported content
     */
    public function export($conversation);

    /**
     * Get the file extension for the export format
     *
     * @return string The file extension (without dot)
     */
    public function getFileExtension(): string;
}
```

### ExporterConversationFactory

```php
class ExporterConversationFactory
{
    /**
     * Register a custom exporter type
     *
     * @param string $type The format identifier (e.g., 'pdf', 'txt')
     * @param string $class The exporter class name
     */
    public function registerExporter($type, $class);

    /**
     * Create an exporter instance
     *
     * @param string $type The format identifier
     * @return ExportConversationServiceInterface
     * @throws \InvalidArgumentException If the type is not supported
     */
    public function make($type);
}
```

---

## Error Handling

The factory throws an `InvalidArgumentException` if an unsupported format is requested:

```php
try {
    $exporter = $factory->make('unsupported');
} catch (\InvalidArgumentException $e) {
    // Handle error: "Exporter type 'unsupported' is not supported."
    return back()->with('error', 'Export format not supported.');
}
```

To check available formats before attempting export:

```php
public function exportConversation($conversationId, $format)
{
    $supportedFormats = ['md', 'pdf', 'txt'];

    if (!in_array($format, $supportedFormats)) {
        abort(400, "Unsupported export format: {$format}");
    }

    $factory = app(ExporterConversationFactory::class);
    $exporter = $factory->make($format);

    // Continue with export...
}
```

---

## Related Documentation

- [Chat Components](/docs/{{version}}/chat/chat-ui) - Chat UI components
- [Conversation Context](/docs/{{version}}/chat/conversation-context-management) - Multi-turn conversations
- [Chat Pipeline](/docs/{{version}}/chat/module-pipeline) - Module pipeline system
