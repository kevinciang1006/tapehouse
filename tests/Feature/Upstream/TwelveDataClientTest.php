<?php

declare(strict_types=1);

use App\Enums\TickSource;
use App\Services\Upstream\Exceptions\UpstreamAuthException;
use App\Services\Upstream\TwelveDataClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * @param  list<Response>  $responses
 */
function client(array $responses): TwelveDataClient
{
    $stack = HandlerStack::create(new MockHandler($responses));

    return new TwelveDataClient(
        new Client(['handler' => $stack]),
        'test-key',
        'https://api.twelvedata.com',
    );
}

it('parses a single-symbol response', function (): void {
    $c = client([new Response(200, [], json_encode([
        'symbol' => 'AAPL',
        'close' => '228.41',
        'change' => '1.82',
        'percent_change' => '0.80',
        'timestamp' => 1786089600,
    ]))]);

    $quotes = $c->quotes(['AAPL']);

    expect($quotes)->toHaveCount(1)
        ->and($quotes[0]->ticker)->toBe('AAPL')
        ->and($quotes[0]->price)->toBe('228.41')
        ->and($quotes[0]->source)->toBe(TickSource::Polling);
});

it('parses a batch response keyed by ticker', function (): void {
    $c = client([new Response(200, [], json_encode([
        'AAPL' => ['symbol' => 'AAPL', 'close' => '228.41', 'change' => '1.82', 'percent_change' => '0.80', 'timestamp' => 1786089600],
        'EUR/USD' => ['symbol' => 'EUR/USD', 'close' => '1.08234', 'change' => '-0.00041', 'percent_change' => '-0.04', 'timestamp' => 1786089600],
    ]))]);

    $quotes = $c->quotes(['AAPL', 'EUR/USD']);

    expect($quotes)->toHaveCount(2)
        ->and($quotes[1]->ticker)->toBe('EUR/USD')
        ->and($quotes[1]->price)->toBe('1.08234');
});

it('throws UpstreamAuthException when the key is rejected', function (): void {
    // The expected outcome on a trial key, not an exceptional one.
    $c = client([new Response(200, [], json_encode([
        'code' => 401,
        'message' => '**api_key** not valid',
        'status' => 'error',
    ]))]);

    $c->quotes(['AAPL']);
})->throws(UpstreamAuthException::class);

it('skips symbols the upstream reports an error for, keeping the rest', function (): void {
    $c = client([new Response(200, [], json_encode([
        'AAPL' => ['symbol' => 'AAPL', 'close' => '228.41', 'change' => '1.82', 'percent_change' => '0.80', 'timestamp' => 1786089600],
        'BADSYM' => ['code' => 404, 'message' => 'symbol not found', 'status' => 'error'],
    ]))]);

    $quotes = $c->quotes(['AAPL', 'BADSYM']);

    expect($quotes)->toHaveCount(1)
        ->and($quotes[0]->ticker)->toBe('AAPL');
});

it('sends the api key and the comma-joined symbol list', function (): void {
    $captured = null;
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
    $stack->push(function (callable $next) use (&$captured): callable {
        return function ($request, array $options) use ($next, &$captured) {
            $captured = $request;

            return $next($request, $options);
        };
    });

    (new TwelveDataClient(new Client(['handler' => $stack]), 'test-key', 'https://api.twelvedata.com'))
        ->quotes(['AAPL', 'MSFT']);

    parse_str($captured->getUri()->getQuery(), $query);

    expect($query['symbol'])->toBe('AAPL,MSFT')
        ->and($query['apikey'])->toBe('test-key');
});
