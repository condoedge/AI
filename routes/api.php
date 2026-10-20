<?php
// routes/api.php

use Condoedge\Ai\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// The health probe reveals infrastructure status, so it is guarded by default.
// Hosts that point a load balancer / uptime checker at it can override the stack
// via config('ai.health.middleware') (e.g. an IP-allowlist or a signed token middleware).
Route::prefix('api/ai')->middleware((array) config('ai.health.middleware', ['api', 'auth']))->group(function () {
    Route::get('health', HealthController::class);
});
