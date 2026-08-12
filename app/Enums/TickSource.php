<?php

declare(strict_types=1);

namespace App\Enums;

enum TickSource: string
{
    case WebSocket = 'websocket';
    case Polling = 'polling';
    case Simulated = 'simulated';
}
