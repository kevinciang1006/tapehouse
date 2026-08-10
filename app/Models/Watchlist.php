<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WatchlistFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Watchlist extends Model
{
    /** @use HasFactory<WatchlistFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'name'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Symbol, $this, WatchlistSymbol, 'pivot'> */
    public function symbols(): BelongsToMany
    {
        return $this->belongsToMany(Symbol::class, 'watchlist_symbols')
            ->using(WatchlistSymbol::class)
            ->withPivot('position')
            ->withTimestamps()
            ->orderBy('watchlist_symbols.position');
    }
}
