<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\DriverState;
use App\Enums\FeedEventLevel;
use App\Http\Controllers\Controller;
use App\Models\FeedEvent;
use App\Services\Budget\CreditBudget;
use App\Services\Control\Exceptions\FeedControlLockedException;
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
        FeedControl $control,
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
            // Driver-relative: a polling feed on a trial key legitimately
            // refreshes slower than a streaming one, so the frontend must not
            // hardcode this. Stopped has no meaningful threshold — its raw
            // value is PHP_INT_MAX, which would serialise as a huge, useless
            // number rather than a sentinel the UI can special-case.
            'stale_seconds' => $driver['driver'] === DriverState::Stopped
                ? 0
                : $driver['driver']->staleThreshold(),
            // `driver: 'stopped'` alone cannot distinguish an operator having
            // pressed Stop from ingest never having started at all, so the
            // status-bar button cannot choose its label without this.
            'feed_stopped' => $control->isStopped(),
        ]]);
    }

    public function stop(Request $request, FeedControl $control): JsonResponse
    {
        try {
            $control->stop();
        } catch (FeedControlLockedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        $this->audit($request, 'feed.stop_requested', 'feed stopped by operator');

        return response()->json(['data' => ['stopped' => true]]);
    }

    public function start(Request $request, FeedControl $control): JsonResponse
    {
        try {
            $control->start();
        } catch (FeedControlLockedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

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
