<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\FeedEventLevel;
use App\Http\Controllers\Controller;
use App\Models\FeedEvent;
use App\Services\Budget\CreditBudget;
use App\Services\Control\FeedControl;
use App\Services\Metrics\FeedMetrics;
use App\Services\Upstream\DriverStateReader;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

class OpsController extends Controller
{
    public function health(
        DriverStateReader $state,
        CreditBudget $budget,
        FeedMetrics $metrics,
    ): JsonResponse {
        $driver = $state->read();
        $lag = $metrics->lagPercentiles();

        return response()->json(['data' => [
            'driver' => $driver['driver']->value,
            'seconds_in_state' => $driver['since'] === 0
                ? 0
                : max(0, CarbonImmutable::now()->getTimestamp() - $driver['since']),
            'reconnects' => $driver['reconnects'],
            'last_error' => $driver['last_error'],
            'credits' => [
                'available' => $budget->available(),
                'capacity' => $budget->capacity(),
                'seconds_until_next' => $budget->secondsUntilNextToken(),
            ],
            'lag' => ['p50' => $lag['p50'], 'p95' => $lag['p95']],
            'ticks_per_minute' => $metrics->ticksPerMinute(),
            'queue_depth' => Queue::size(),
        ]]);
    }

    public function stop(Request $request, FeedControl $control): JsonResponse
    {
        $control->stop();
        $this->audit($request, 'feed.stop_requested', 'feed stopped by operator');

        return response()->json(['data' => ['stopped' => true]]);
    }

    public function start(Request $request, FeedControl $control): JsonResponse
    {
        $control->start();
        $this->audit($request, 'feed.start_requested', 'feed started by operator');

        return response()->json(['data' => ['stopped' => false]]);
    }

    private function audit(Request $request, string $type, string $message): void
    {
        FeedEvent::create([
            'level' => FeedEventLevel::Warn,
            'type' => $type,
            'message' => $message,
            'context' => ['user_id' => $request->user()?->id],
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }
}
