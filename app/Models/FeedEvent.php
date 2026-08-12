<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeedEventLevel;
use Database\Factories\FeedEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeedEvent extends Model
{
    /** @use HasFactory<FeedEventFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Laravel's base grammar formats dates as 'Y-m-d H:i:s' with no fractional
     * part, and PostgresGrammar does not override it. Without it the
     * precision(3) columns on this table cannot hold a fraction. The trailing
     * `P` appends an explicit UTC offset: these are timestamptz columns, so a
     * naive string would be resolved using the session timezone instead of
     * the instant it names.
     */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['level', 'type', 'message', 'context', 'occurred_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'level' => FeedEventLevel::class,
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
