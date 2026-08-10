<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TickSource;
use Database\Factories\TickFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tick extends Model
{
    /** @use HasFactory<TickFactory> */
    use HasFactory;

    /**
     * Ticks are immutable and append-heavy. Two timestamp columns nothing ever
     * reads would cost write throughput on the hottest table in the schema.
     */
    public $timestamps = false;

    protected $fillable = [
        'symbol_id',
        'price',
        'day_change',
        'day_change_pct',
        'source',
        'quoted_at',
        'received_at',
    ];

    /** @return BelongsTo<Symbol, $this> */
    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => TickSource::class,
            'quoted_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }
}
