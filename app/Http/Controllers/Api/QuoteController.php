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
    public function index(Request $request, QuoteCache $cache): AnonymousResourceCollection
    {
        $tickers = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $request->query('symbols', ''))
        ), static fn (string $t): bool => $t !== ''));

        return QuoteResource::collection(array_values($cache->many($tickers)));
    }
}
