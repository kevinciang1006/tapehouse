<?php

declare(strict_types=1);

namespace App\Services\Upstream;

use App\Enums\TickSource;
use App\Services\Upstream\DTO\Quote;
use App\Services\Upstream\Exceptions\UpstreamAuthException;
use Carbon\CarbonImmutable;
use GuzzleHttp\ClientInterface;

final readonly class TwelveDataClient
{
    public function __construct(
        private ClientInterface $http,
        private string $apiKey,
        private string $restUrl,
    ) {}

    /**
     * @param  list<string>  $tickers
     * @return list<Quote>
     *
     * @throws UpstreamAuthException
     */
    public function quotes(array $tickers): array
    {
        if ($tickers === []) {
            return [];
        }

        $response = $this->http->request('GET', $this->restUrl.'/quote', [
            'query' => [
                'symbol' => implode(',', $tickers),
                'apikey' => $this->apiKey,
            ],
            'timeout' => 10,
        ]);

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getBody(), true) ?: [];

        $this->guardAgainstAuthFailure($payload);

        // A single symbol comes back as a bare object; a batch comes back
        // keyed by ticker. Normalise to a list of rows.
        $rows = isset($payload['symbol']) ? [$payload] : array_values($payload);

        $receivedAt = CarbonImmutable::now();
        $quotes = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['symbol'], $row['close'])) {
                continue; // upstream reported an error for this symbol; keep the rest
            }

            $quotes[] = new Quote(
                ticker: (string) $row['symbol'],
                price: (string) $row['close'],
                dayChange: isset($row['change']) ? (string) $row['change'] : null,
                dayChangePct: isset($row['percent_change']) ? (string) $row['percent_change'] : null,
                source: TickSource::Polling,
                quotedAt: isset($row['timestamp'])
                    ? CarbonImmutable::createFromTimestampUTC((int) $row['timestamp'])
                    : $receivedAt,
                receivedAt: $receivedAt,
            );
        }

        return $quotes;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws UpstreamAuthException
     */
    private function guardAgainstAuthFailure(array $payload): void
    {
        $code = isset($payload['code']) ? (int) $payload['code'] : null;

        if (($payload['status'] ?? null) === 'error' && in_array($code, [401, 403], true)) {
            throw new UpstreamAuthException((string) ($payload['message'] ?? 'upstream rejected the api key'));
        }
    }
}
