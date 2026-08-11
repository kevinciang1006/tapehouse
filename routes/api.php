<?php

declare(strict_types=1);

use App\Http\Controllers\Api\SymbolController;
use App\Http\Controllers\Api\WatchlistController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'throttle:120,1'])->group(function (): void {
    Route::get('/symbols', [SymbolController::class, 'index']);
    Route::get('/watchlist', [WatchlistController::class, 'show']);
    Route::post('/watchlist/symbols', [WatchlistController::class, 'store']);
    Route::delete('/watchlist/symbols/{symbolId}', [WatchlistController::class, 'destroy'])
        ->whereNumber('symbolId');
});
