<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuoteResource;
use App\Services\Quotes\QuoteCache;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class QuoteController extends Controller
{
    private const MAX_TICKERS = 50;

    public function index(Request $request, QuoteCache $cache): AnonymousResourceCollection
    {
        $tickers = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $request->query('symbols', ''))
        ), static fn (string $t): bool => $t !== ''));

        // The hottest endpoint in the app — called on every reconnect — so an
        // unbounded ticker list is an unbounded cache read on every hit.
        $tickers = array_slice($tickers, 0, self::MAX_TICKERS);

        return QuoteResource::collection(array_values($cache->many($tickers)));
    }
}
