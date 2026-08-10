<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssetType;
use Database\Factories\SymbolFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read WatchlistSymbol $pivot
 */
class Symbol extends Model
{
    /** @use HasFactory<SymbolFactory> */
    use HasFactory;

    protected $fillable = [
        'ticker',
        'name',
        'asset_type',
        'exchange',
        'currency',
        'price_decimals',
        'is_active',
    ];

    /** @return HasMany<Tick, $this> */
    public function ticks(): HasMany
    {
        return $this->hasMany(Tick::class);
    }

    /** @return BelongsToMany<Watchlist, $this, WatchlistSymbol, 'pivot'> */
    public function watchlists(): BelongsToMany
    {
        return $this->belongsToMany(Watchlist::class, 'watchlist_symbols')
            ->using(WatchlistSymbol::class)
            ->withPivot('position')
            ->withTimestamps();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'asset_type' => AssetType::class,
            'price_decimals' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
