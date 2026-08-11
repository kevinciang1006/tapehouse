<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AlertEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertEvent extends Model
{
    /** @use HasFactory<AlertEventFactory> */
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

    protected $fillable = ['alert_rule_id', 'price', 'fired_at'];

    /** @return BelongsTo<AlertRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['fired_at' => 'immutable_datetime'];
    }
}
