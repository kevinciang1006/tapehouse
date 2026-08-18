<?php

declare(strict_types=1);

namespace App\Services\Control\Exceptions;

use RuntimeException;

/**
 * Thrown when stop()/start() is called against a locked FeedControl.
 *
 * Production is the public demo — anyone with the credentials on the login
 * screen can reach these endpoints, and the control flag is a single global
 * Redis key shared by every visitor, not a per-session toggle. One curious
 * click would stop the feed for everyone after them, including whoever
 * opens the link days later. Locking is applied in production only; see
 * TapehouseServiceProvider.
 */
final class FeedControlLockedException extends RuntimeException {}
