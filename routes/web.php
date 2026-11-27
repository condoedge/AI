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

// The eval panel: suites of questions asked to the assistant as real people, graded against the
// application's data (Condoedge\Ai\Eval\EvalPanel lists its settings, config ai.eval.*). Gate
// ability manageAiEval is DENIED unless the host grants it; every component re-checks it.
if (config('ai.eval.enabled', true)) {
	Route::group(array_filter([
		'middleware' => array_merge(
			(array) config('ai.eval.middleware', config('ai.admin.middleware', ['web', 'auth'])),
			['can:' . \Condoedge\Ai\Eval\EvalPanel::ABILITY]
		),
		'layout' => config('ai.eval.layout', config('ai.admin.layout')),
	]), function () {
		$path = trim(config('ai.eval.path', 'ai/admin/eval'), '/');

		Route::get($path, \Condoedge\Ai\Kompo\Admin\Eval\AiEvalPage::class)->name('ai.eval');
		Route::get($path . '/{id}', \Condoedge\Ai\Kompo\Admin\Eval\AiEvalRunView::class)->name('ai.eval.run');
		Route::get($path . '/{id}/export', \Condoedge\Ai\Http\Controllers\EvalExportController::class)->name('ai.eval.export');
	});
}
