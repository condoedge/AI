<?php
// routes/api.php

use Condoedge\Ai\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/ai')->group(function () {
    Route::get('health', HealthController::class);
});
