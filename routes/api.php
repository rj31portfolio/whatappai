<?php

use App\Http\Controllers\ApiContactController;
use App\Http\Middleware\ResolveApiTenant;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', ResolveApiTenant::class, 'throttle:60,1'])->group(function () {
    Route::get('/contacts', [ApiContactController::class, 'index']);
    Route::post('/contacts', [ApiContactController::class, 'store']);
    Route::get('/contacts/{contact}', [ApiContactController::class, 'show']);
    Route::put('/contacts/{contact}', [ApiContactController::class, 'update']);
    Route::delete('/contacts/{contact}', [ApiContactController::class, 'destroy']);
});
