<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Test\Fakes\Service\Mollie;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

/**
 * In-memory Guzzle ClientInterface for faking Mollie API HTTP calls in integration tests.
 *
 * Route keys support `METHOD /path` with `*` wildcards (e.g. `GET /v2/payments/*`, `POST /v2/sessions`),
 * or a plain list of sequential responses.
 */
class FakeHttpClient implements ClientInterface
{
    /** @var array<int|string, mixed> */
    private array $responses;

    /** @var list<array{request: RequestInterface, options: array<string, mixed>, body: ?string}> */
    private array $sentRequests = [];

    /**
     * @param array<int|string, mixed> $responses
     */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        $body = $this->extractBody($request, $options);
        $this->sentRequests[] = [
            'request' => $request,
            'options' => $options,
            'body' => $body,
        ];

        $response = $this->resolveResponse($request, $options, $body);
        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        return Create::promiseFor($this->send($request, $options));
    }

    /**
     * @param string $method
     * @param string|UriInterface $uri
     * @param array<string, mixed> $options
     */
    public function request(string $method, $uri, array $options = []): ResponseInterface
    {
        return $this->send(new Request($method, $uri), $options);
    }

    /**
     * @param string $method
     * @param string|UriInterface $uri
     * @param array<string, mixed> $options
     */
    public function requestAsync(string $method, $uri, array $options = []): PromiseInterface
    {
        return Create::promiseFor($this->request($method, $uri, $options));
    }

    public function getConfig(?string $option = null): mixed
    {
        return null;
    }

    /**
     * @return list<array{request: RequestInterface, options: array<string, mixed>, body: ?string}>
     */
    public function getSentRequests(): array
    {
        return $this->sentRequests;
    }

    /**
     * @return list<array{request: RequestInterface, options: array<string, mixed>, body: ?string}>
     */
    public function getSentRequestsMatching(string $pattern): array
    {
        return array_values(array_filter(
            $this->sentRequests,
            fn (array $entry): bool => $this->matches($pattern, $entry['request']),
        ));
    }

    public function assertSent(string $pattern, ?callable $callback = null): void
    {
        $matching = $this->getSentRequestsMatching($pattern);
        Assert::assertNotEmpty($matching, sprintf('Expected a Mollie API request matching "%s" to be sent.', $pattern));

        if ($callback !== null) {
            foreach ($matching as $entry) {
                $payload = $entry['body'] !== null ? (json_decode($entry['body'], true) ?? []) : [];
                $result = $callback($entry['request'], $payload, $entry['options']);
                if ($result === true || $result === null) {
                    return;
                }
            }

            Assert::fail(sprintf('No request matching "%s" satisfied the assertion callback.', $pattern));
        }
    }

    public function assertSentCount(int $expectedCount, ?string $pattern = null): void
    {
        $requests = $pattern === null ? $this->sentRequests : $this->getSentRequestsMatching($pattern);
        Assert::assertCount(
            $expectedCount,
            $requests,
            sprintf(
                'Expected %d Mollie API request(s)%s, got %d.',
                $expectedCount,
                $pattern !== null ? ' matching "' . $pattern . '"' : '',
                count($requests),
            ),
        );
    }

    public function assertNothingSent(): void
    {
        $this->assertSentCount(0);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveResponse(
        RequestInterface $request,
        array $options,
        ?string $body,
    ): ResponseInterface|\Throwable {
        if (array_is_list($this->responses) && $this->responses !== []) {
            $next = array_shift($this->responses);

            return $this->evaluateEntry($next, $request, $options, $body);
        }

        foreach ($this->responses as $pattern => $entry) {
            if (is_string($pattern) && $this->matches($pattern, $request)) {
                if (is_array($entry) && array_is_list($entry) && $entry !== []) {
                    $next = array_shift($this->responses[$pattern]);

                    return $this->evaluateEntry($next, $request, $options, $body);
                }

                return $this->evaluateEntry($entry, $request, $options, $body);
            }
        }

        throw new RuntimeException(sprintf(
            'No fake Mollie API response registered for %s %s',
            $request->getMethod(),
            $request->getUri()->getPath(),
        ));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function evaluateEntry(
        mixed $entry,
        RequestInterface $request,
        array $options,
        ?string $body,
    ): ResponseInterface|\Throwable {
        if (is_callable($entry)) {
            $payload = $body !== null ? (json_decode($body, true) ?? []) : [];

            return $entry($request, $payload, $options);
        }

        if ($entry instanceof ResponseInterface) {
            // Rewind stream in case a static response is reused across multiple calls.
            $stream = $entry->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            return $entry;
        }

        if ($entry instanceof \Throwable) {
            return $entry;
        }

        throw new RuntimeException('Unsupported fake response entry type.');
    }

    private function matches(string $pattern, RequestInterface $request): bool
    {
        if ($pattern === '*') {
            return true;
        }

        $parts = explode(' ', trim($pattern), 2);
        if (count($parts) === 2) {
            [$expectedMethod, $pathPattern] = $parts;
            if (strtoupper($expectedMethod) !== strtoupper($request->getMethod())) {
                return false;
            }
        } else {
            $pathPattern = $parts[0];
        }

        $actualPath = $request->getUri()->getPath();
        $regex = '#^' . str_replace('\*', '[^/]+', preg_quote($pathPattern, '#')) . '$#';

        return preg_match($regex, $actualPath) === 1;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function extractBody(RequestInterface $request, array $options): ?string
    {
        if (isset($options['body']) && is_string($options['body'])) {
            return $options['body'];
        }

        if (isset($options['json'])) {
            return json_encode($options['json'], JSON_THROW_ON_ERROR);
        }

        $contents = (string) $request->getBody();

        return $contents === '' ? null : $contents;
    }
}
