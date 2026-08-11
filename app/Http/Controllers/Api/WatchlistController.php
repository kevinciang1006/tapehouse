<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWatchlistSymbolRequest;
use App\Http\Resources\WatchlistResource;
use App\Models\User;
use App\Models\Watchlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WatchlistController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $watchlist = $this->watchlistFor($request);

        // firstOrCreate() may create the watchlist here; without an explicit
        // status ResourceResponse would report 201 on a GET.
        return (new WatchlistResource($watchlist->load('symbols')))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    public function store(StoreWatchlistSymbolRequest $request): JsonResponse
    {
        $watchlist = $this->watchlistFor($request);
        $this->authorize('update', $watchlist);

        $position = (int) $watchlist->symbols()->max('position');

        $watchlist->symbols()->attach($request->integer('symbol_id'), [
            'position' => $watchlist->symbols()->count() === 0 ? 0 : $position + 1,
        ]);

        return (new WatchlistResource($watchlist->load('symbols')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(Request $request, int $symbolId): Response
    {
        $watchlist = $this->watchlistFor($request);
        $this->authorize('update', $watchlist);

        // Detaching through the caller's own relation is what stops a symbol
        // id from reaching another operator's rows.
        $watchlist->symbols()->detach($symbolId);

        return response()->noContent();
    }

    private function watchlistFor(Request $request): Watchlist
    {
        /** @var User $user */
        $user = $request->user();

        return Watchlist::firstOrCreate(['user_id' => $user->id], ['name' => 'Default']);
    }
}
