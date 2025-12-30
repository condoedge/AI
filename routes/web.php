<?php

use Condoedge\Ai\Http\Controllers\ConversationController;
use Illuminate\Support\Facades\Route;

Route::get('export-chat/{id}', [ConversationController::class, 'export'])->name('ai.export-chat');