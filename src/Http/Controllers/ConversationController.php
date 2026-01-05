<?php

namespace Condoedge\Ai\Http\Controllers;

use Condoedge\Ai\Models\AiConversation;
use Illuminate\Routing\Controller;

class ConversationController extends Controller
{
    public function export($id, $type = 'md')
    {
        $conversation = AiConversation::where('user_id', auth()->id())->find($id);
        if (!$conversation) {
            abort(404, 'Conversation not found');
        }

        $factory = app()->make(\Condoedge\Ai\Services\Chat\Exporter\ExporterConversationFactory::class);
        $exporter = $factory->make($type);
        $exportedContent = $exporter->export($conversation);
        $extension = $exporter->getFileExtension();

        $filename = "conversation_{$conversation->id}." . $extension;

        return response($exportedContent)
            ->header('Content-Type', 'text/markdown')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
}