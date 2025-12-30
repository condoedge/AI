<?php

namespace Condoedge\Ai\Http\Controllers;

use Condoedge\Ai\Models\AiConversation;
use Illuminate\Routing\Controller;

class ConversationController extends Controller
{
    public function export($id)
    {
        $conversation = AiConversation::where('user_id', auth()->id())->find($id);
        if (!$conversation) {
            return;
        }

        $markdown = "# " . ($conversation->title ?? 'Conversation') . "\n\n";
        $markdown .= "Exported: " . now()->format('F j, Y g:i A') . "\n\n---\n\n";

        foreach ($conversation->messages as $msg) {
            $role = $msg->role === 'user' ? '**You**' : '**AI Assistant**';
            $time = $msg->created_at->format('g:i A');
            $markdown .= "{$role} ({$time}):\n\n{$msg->content}\n\n---\n\n";
        }

        return response($markdown)
            ->header('Content-Type', 'text/markdown')
            ->header('Content-Disposition', 'attachment; filename="conversation-' . $conversation->id . '.md"');
    }
}