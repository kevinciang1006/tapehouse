<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FeedEventResource;
use App\Models\FeedEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FeedEventController extends Controller
{
    private const MAX_LIMIT = 50;

    public function index(Request $request): AnonymousResourceCollection
    {
        $limit = min((int) $request->query('limit', self::MAX_LIMIT), self::MAX_LIMIT);

        $events = FeedEvent::query()
            ->orderByDesc('occurred_at')
            ->limit(max(1, $limit))
            ->get();

        return FeedEventResource::collection($events);
    }
}
