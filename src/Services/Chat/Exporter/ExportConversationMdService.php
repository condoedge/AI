<?php

namespace Condoedge\Ai\Services\Chat\Exporter;

class ExportConversationMdService
{
    public function export($conversation)
    {
        $markdown = "# " . ($conversation->title ?? 'Conversation') . "\n\n";
        $markdown .= "Exported: " . now()->format('F j, Y g:i A') . "\n\n---\n\n";

        foreach ($conversation->messages as $msg) {
            $role = $msg->role === 'user' ? '**You**' : '**AI Assistant**';
            $time = $msg->created_at->format('g:i A');
            $markdown .= "{$role} ({$time}):\n\n{$msg->content}\n\n---\n\n";
        }

        return $markdown;
    }

    public function getFileExtension(): string
    {
        return 'md';
    }
}