<?php

namespace Condoedge\Ai\Http\Controllers;

use Condoedge\Ai\Services\Chat\UseCases\ExportConversationUseCase;
use Illuminate\Routing\Controller;

class ConversationController extends Controller
{
    public function export($id, $type = 'md')
    {
        $result = app()->make(ExportConversationUseCase::class)
            ->execute((int) $id, $type, auth()->user());

        return response($result->content)
            ->header('Content-Type', $result->mime)
            ->header('Content-Disposition', 'attachment; filename="' . $result->filename . '"');
    }
}
