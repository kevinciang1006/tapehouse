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

    /**
     * Laravel's base grammar formats dates as 'Y-m-d H:i:s' with no fractional
     * part, and PostgresGrammar does not override it — so without this every
     * write truncates to whole seconds regardless of the column's declared
     * precision. On ticks that would destroy received_at - quoted_at, which is
     * the ingest lag the ops panel reports. The trailing `P` appends an
     * explicit UTC offset: these are timestamptz columns, so a naive string
     * would be resolved using the session timezone instead of the instant it
     * names.
     */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

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
