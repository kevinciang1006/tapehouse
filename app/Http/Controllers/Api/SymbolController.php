<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SymbolResource;
use App\Models\Symbol;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SymbolController extends Controller
{
    private const MAX_LIMIT = 50;

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = trim((string) $request->query('q', ''));
        // Capped: an uncapped limit lets one request pull the whole table.
        $limit = min((int) $request->query('limit', 20), self::MAX_LIMIT);

        $symbols = Symbol::query()
            ->where('is_active', true)
            ->when($q !== '', function ($query) use ($q): void {
                $query->where(function ($inner) use ($q): void {
                    $inner->where('ticker', 'ilike', '%'.$q.'%')
                        ->orWhere('name', 'ilike', '%'.$q.'%');
                });
            })
            ->orderBy('ticker')
            ->limit(max(1, $limit))
            ->get();

        return SymbolResource::collection($symbols);
    }
}
