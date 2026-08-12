<?php

declare(strict_types=1);

namespace App\Enums;

enum FeedEventLevel: string
{
    case Info = 'info';
    case Warn = 'warn';
    case Error = 'error';
}
