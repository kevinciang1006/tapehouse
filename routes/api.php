<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'throttle:120,1'])->group(function (): void {
    // Endpoints are added by later tasks in this plan.
});
