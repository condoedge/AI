<?php

use Condoedge\Ai\Http\Controllers\ConversationController;
use Condoedge\Ai\Kompo\Admin\AiConversationsAdminPage;
use Condoedge\Ai\Kompo\AiChatScreen;
use Condoedge\Ai\Services\Admin\ConversationInsights;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
	Route::get('export-chat/{id}', [ConversationController::class, 'export'])
		->name('ai.export-chat');

	// Routed full-page chat surface (config('ai.chat.surface') === 'screen').
	Route::get('ai/chat', AiChatScreen::class)
		->name('ai.chat.screen');
});

// Admin review of the assistant's conversations and users' feedback (read-only).
// Gate ability viewAiConversations is DENIED unless the host grants it
// (Gate::define('viewAiConversations', fn ($user) => ...)); the page components
// re-check it on every Kompo boot / AJAX call.
// Host config (all optional): ai.admin.enabled (true), ai.admin.path,
// ai.admin.middleware (['web', 'auth']), ai.admin.layout (the host's Blade layout —
// without one Kompo returns the bare component), ai.admin.users_table,
// ai.admin.user_name_column, ai.admin.user_team_column, ai.admin.teams_table,
// ai.admin.team_name_column, ai.admin.slow_answer_ms (20000: the "slow" answer filter).
if (config('ai.admin.enabled', true)) {
	Route::group(array_filter([
		'middleware' => array_merge(
			(array) config('ai.admin.middleware', ['web', 'auth']),
			['can:' . ConversationInsights::ABILITY]
		),
		// Kompo reads the page layout from the route action (what Route::layout() sets).
		'layout' => config('ai.admin.layout'),
	]), function () {
		Route::get(config('ai.admin.path', 'ai/admin/conversations'), AiConversationsAdminPage::class)
			->name('ai.admin.conversations');
	});
}
