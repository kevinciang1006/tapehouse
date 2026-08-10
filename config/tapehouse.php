<?php

declare(strict_types=1);

return [

    'api_key' => env('TWELVEDATA_API_KEY', ''),

    'ws_enabled' => (bool) env('TWELVEDATA_WS_ENABLED', true),

    'ws_url' => env('TWELVEDATA_WS_URL', 'wss://ws.twelvedata.com/v1/quotes/price'),

    'rest_url' => env('TWELVEDATA_REST_URL', 'https://api.twelvedata.com'),

    /*
     | Redis token bucket. Twelve Data charges one credit per symbol, not per
     | request, so a batch of eight symbols costs eight tokens.
     */
    'budget' => [
        'capacity' => (int) env('TAPEHOUSE_BUDGET_CAPACITY', 8),
        'refill_per_minute' => (int) env('TAPEHOUSE_BUDGET_REFILL_PER_MINUTE', 8),
    ],

    'poll' => [
        'interval_seconds' => (int) env('TAPEHOUSE_POLL_INTERVAL_SECONDS', 8),
        'batch_size' => (int) env('TAPEHOUSE_POLL_BATCH_SIZE', 8),
    ],

    'broadcast' => [
        'coalesce_ms' => (int) env('TAPEHOUSE_BROADCAST_COALESCE_MS', 250),
    ],

    'ticks' => [
        'buffer_size' => (int) env('TAPEHOUSE_TICKS_BUFFER_SIZE', 200),
        'flush_ms' => (int) env('TAPEHOUSE_TICKS_FLUSH_MS', 1000),
        'retention_hours' => (int) env('TAPEHOUSE_TICKS_RETENTION_HOURS', 24),
    ],

    'driver' => [
        'failure_threshold' => (int) env('TAPEHOUSE_DRIVER_FAILURE_THRESHOLD', 3),
        'promotion_backoff' => [60, 120, 300],
    ],

    /*
     | Seconds without a tick before a symbol reads as stale. Driver-relative,
     | because a polling feed on a trial key legitimately refreshes far slower
     | than a streaming one — staleness should mean the feed is behind, not
     | that the account is on a free plan.
     */
    'stale' => [
        'websocket' => (int) env('TAPEHOUSE_STALE_WEBSOCKET', 30),
        'polling' => (int) env('TAPEHOUSE_STALE_POLLING', 90),
        'simulated' => (int) env('TAPEHOUSE_STALE_SIMULATED', 30),
    ],

    /*
     | The simulated driver exists so the tape has enough ticks to exercise the
     | flash during development and in the deployed demo. It always reports
     | itself as `simulated` in the ops panel; it never masquerades as live.
     */
    'simulator' => [
        'enabled' => (bool) env('TAPEHOUSE_SIMULATOR_ENABLED', false),
        'interval_ms' => (int) env('TAPEHOUSE_SIMULATOR_INTERVAL_MS', 620),
    ],

];
