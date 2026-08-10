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
