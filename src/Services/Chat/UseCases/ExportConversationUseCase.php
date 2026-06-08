<?php

declare(strict_types=1);

namespace Condoedge\Ai\Services\Chat\UseCases;

use Condoedge\Ai\Models\AiConversation;
use Condoedge\Ai\Services\Chat\Exporter\ExporterConversationFactory;
use Condoedge\Ai\Services\Chat\Results\ExportResult;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Use case: export a conversation in a given format.
 *
 * Concrete, container-autowired (never bound, never interfaced) per the clean-architecture
 * design. Read-only: no DB::transaction. Owns the ownership/authorization gate (step 0)
 * that the controller previously inlined as `where('user_id', auth()->id())`, but uses the
 * passed-in $user instead of the auth() global. Resolves the exporter through the factory
 * (which uses the container, so exporters get dependency injection) and assembles the
 * content + mime + filename into an immutable ExportResult, fixing the controller's
 * hardcoded "text/markdown" Content-Type.
 */
class ExportConversationUseCase
{
    public function __construct(
        protected ExporterConversationFactory $exporterFactory,
    ) {
    }

    /**
     * @param  int    $conversationId The conversation to export.
     * @param  string $format         The exporter format key (e.g. "md").
     * @param  mixed  $user           The acting user; must own the conversation.
     *
     * @throws ModelNotFoundException             When the conversation does not exist or is not owned by $user.
     * @throws \InvalidArgumentException          When the requested format has no registered exporter.
     */
    public function execute(int $conversationId, string $format, $user): ExportResult
    {
        // Step 0: authorize — scope by ownership exactly like the controller's
        // `where('user_id', auth()->id())`, but against the passed $user.
        $conversation = AiConversation::query()
            ->where('user_id', $user->id ?? null)
            ->find($conversationId);

        if (!$conversation) {
            throw (new ModelNotFoundException())->setModel(AiConversation::class, [$conversationId]);
        }

        $exporter = $this->exporterFactory->make($format);

        $content = $exporter->export($conversation);
        $mime = $exporter->getMimeType();
        $extension = $exporter->getFileExtension();

        return new ExportResult(
            content: $content,
            mime: $mime,
            filename: "conversation-{$conversation->id}.{$extension}",
        );
    }
}
