<?php

declare(strict_types=1);

use App\Http\Controllers\Api\FeedEventController;
use App\Http\Controllers\Api\OpsController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\SymbolController;
use App\Http\Controllers\Api\WatchlistController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'throttle:120,1'])->group(function (): void {
    Route::get('/symbols', [SymbolController::class, 'index']);
    Route::get('/watchlist', [WatchlistController::class, 'show']);
    Route::post('/watchlist/symbols', [WatchlistController::class, 'store']);
    Route::delete('/watchlist/symbols/{symbolId}', [WatchlistController::class, 'destroy'])
        ->whereNumber('symbolId');

    Route::get('/quotes', [QuoteController::class, 'index']);
    Route::get('/ops/health', [OpsController::class, 'health']);
    Route::post('/ops/feed/stop', [OpsController::class, 'stop']);
    Route::post('/ops/feed/start', [OpsController::class, 'start']);
    Route::get('/feed-events', [FeedEventController::class, 'index']);
});
