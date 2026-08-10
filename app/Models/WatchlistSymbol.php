<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class WatchlistSymbol extends Pivot
{
    protected $table = 'watchlist_symbols';

    public $incrementing = true;

    protected $fillable = ['watchlist_id', 'symbol_id', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
