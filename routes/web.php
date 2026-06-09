<?php

use Condoedge\Ai\Http\Controllers\ConversationController;
use Condoedge\Ai\Kompo\AiChatScreen;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
	Route::get('export-chat/{id}', [ConversationController::class, 'export'])
		->name('ai.export-chat');

	// Routed full-page chat surface (config('ai.chat.surface') === 'screen').
	Route::get('ai/chat', AiChatScreen::class)
		->name('ai.chat.screen');
});