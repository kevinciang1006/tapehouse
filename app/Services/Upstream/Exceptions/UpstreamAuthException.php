<?php

declare(strict_types=1);

namespace App\Services\Upstream\Exceptions;

use RuntimeException;

/**
 * The upstream rejected our API key. On a Twelve Data trial this is the
 * expected outcome for the WebSocket feed, not an exceptional one — callers
 * demote to polling rather than crashing.
 */
final class UpstreamAuthException extends RuntimeException {}
