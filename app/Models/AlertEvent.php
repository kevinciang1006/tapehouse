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
