<?php

declare(strict_types=1);

namespace App\Services\Upstream;

use App\Enums\DriverState;
use App\Enums\TickSource;
use App\Services\Upstream\DTO\Quote;
use Carbon\CarbonImmutable;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Throwable;

final class WebSocketDriver implements UpstreamDriver
{
    /** @var list<string> */
    private array $tickers = [];

    /** @var (callable(Quote): void)|null */
    private $onQuote = null;

    private ?WebSocket $socket = null;

    private ?string $lastError = null;

    private int $consecutiveFailures = 0;

    private bool $authRejected = false;

    private ?CarbonImmutable $lastMessageAt = null;

    public function __construct(
        private readonly Connector $connector,
        private readonly string $wsUrl,
        private readonly string $apiKey,
        private readonly int $silenceSeconds = 90,
        private readonly int $failureThreshold = 3,
    ) {}

    public function name(): DriverState
    {
        return DriverState::WebSocket;
    }

    public function start(array $tickers, callable $onQuote): void
    {
        $this->tickers = $tickers;
        $this->onQuote = $onQuote;
        $this->authRejected = false;
        $this->consecutiveFailures = 0;
        $this->lastError = null;
        $this->lastMessageAt = CarbonImmutable::now();

        $this->connect();
    }

    /**
     * No I/O. The socket is driven by the ReactPHP loop; this only asks
     * whether the connection still looks alive.
     */
    public function tick(): void
    {
        if ($this->lastMessageAt === null || $this->authRejected) {
            return;
        }

        $silentFor = CarbonImmutable::now()->diffInSeconds($this->lastMessageAt, true);

        if ($silentFor > $this->silenceSeconds) {
            $this->lastError = sprintf('socket silent for %ds', (int) $silentFor);
            $this->consecutiveFailures = $this->failureThreshold;
        }
    }

    public function stop(): void
    {
        $this->socket?->close();
        $this->socket = null;
        $this->tickers = [];
        $this->onQuote = null;
    }

    public function isHealthy(): bool
    {
        return ! $this->authRejected && $this->consecutiveFailures < $this->failureThreshold;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function consecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function subscribePayload(): string
    {
        return json_encode([
            'action' => 'subscribe',
            'params' => ['symbols' => implode(',', $this->tickers)],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Public so the loop and the tests can feed frames in without a live
     * server standing between the parser and its test.
     */
    public function handleMessage(string $raw): void
    {
        $this->lastMessageAt = CarbonImmutable::now();

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return;
        }

        if (($payload['status'] ?? null) === 'error') {
            $messages = $payload['messages'] ?? [$payload['message'] ?? 'upstream rejected the subscription'];
            $this->authRejected = true;
            $this->lastError = is_array($messages) ? implode('; ', array_map('strval', $messages)) : (string) $messages;

            return;
        }

        if (($payload['event'] ?? null) !== 'price' || ! isset($payload['symbol'], $payload['price'])) {
            $this->consecutiveFailures = 0;

            return;
        }

        $this->consecutiveFailures = 0;

        $receivedAt = CarbonImmutable::now();

        ($this->onQuote)(new Quote(
            ticker: (string) $payload['symbol'],
            price: $this->stringifyPrice($payload['price']),
            dayChange: isset($payload['day_change']) ? $this->stringifyPrice($payload['day_change']) : null,
            dayChangePct: isset($payload['percent_change']) ? $this->stringifyPrice($payload['percent_change']) : null,
            source: TickSource::WebSocket,
            quotedAt: isset($payload['timestamp'])
                ? CarbonImmutable::createFromTimestampUTC((int) $payload['timestamp'])
                : $receivedAt,
            receivedAt: $receivedAt,
        ));
    }

    public function handleFailure(string $error): void
    {
        $this->consecutiveFailures++;
        $this->lastError = $error;
        $this->socket = null;
    }

    private function connect(): void
    {
        $url = sprintf('%s?apikey=%s', $this->wsUrl, urlencode($this->apiKey));

        try {
            ($this->connector)($url)->then(
                function (WebSocket $socket): void {
                    $this->socket = $socket;
                    $this->consecutiveFailures = 0;
                    $this->lastMessageAt = CarbonImmutable::now();

                    $socket->on('message', function ($message): void {
                        $this->handleMessage((string) $message);
                    });

                    $socket->on('close', function ($code = null): void {
                        $this->handleFailure(sprintf('socket closed %s', $code ?? 'unknown'));
                    });

                    $socket->send($this->subscribePayload());
                },
                function (Throwable $e): void {
                    $this->handleFailure($e->getMessage());
                },
            );
        } catch (Throwable $e) {
            $this->handleFailure($e->getMessage());
        }
    }

    /**
     * Twelve Data sends prices as JSON numbers. Render without exponent or
     * trailing float noise so the value that reaches Postgres is the value
     * that arrived.
     */
    private function stringifyPrice(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return rtrim(rtrim(number_format((float) $value, 8, '.', ''), '0'), '.') ?: '0';
    }
}
