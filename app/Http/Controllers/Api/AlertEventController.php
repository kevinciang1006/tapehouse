<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlertEventResource;
use App\Models\AlertEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AlertEventController extends Controller
{
    private const MAX_LIMIT = 50;

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $limit = min((int) $request->query('limit', self::MAX_LIMIT), self::MAX_LIMIT);

        $events = AlertEvent::query()
            ->whereHas('rule', fn ($query) => $query->where('user_id', $user->id))
            ->with('rule.symbol')
            ->orderByDesc('fired_at')
            ->limit(max(1, $limit))
            ->get();

        return AlertEventResource::collection($events);
    }
}
