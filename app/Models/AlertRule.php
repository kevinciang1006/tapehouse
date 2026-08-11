<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertCondition;
use App\Enums\AlertMetric;
use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertRule extends Model
{
    /** @use HasFactory<AlertRuleFactory> */
    use HasFactory;

    /**
     * Laravel's base grammar formats dates as 'Y-m-d H:i:s' with no fractional
     * part, and PostgresGrammar does not override it. Without it the
     * precision(3) columns on this table cannot hold a fraction. The trailing
     * `P` appends an explicit UTC offset: these are timestamptz columns, so a
     * naive string would be resolved using the session timezone instead of
     * the instant it names.
     */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = [
        'user_id',
        'symbol_id',
        'metric',
        'condition',
        'threshold',
        'is_active',
        'cooldown_seconds',
        'last_fired_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Symbol, $this> */
    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    /** @return HasMany<AlertEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AlertEvent::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metric' => AlertMetric::class,
            'condition' => AlertCondition::class,
            'is_active' => 'boolean',
            'cooldown_seconds' => 'integer',
            'last_fired_at' => 'immutable_datetime',
        ];
    }
}
