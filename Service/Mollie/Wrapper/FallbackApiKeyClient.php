<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Service\Mollie\Wrapper;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * Wraps a Guzzle client to retry requests for existing historical resources that fail with 401 or 404 using
 * previously stored fallback API keys.
 *
 * Retries are stateless per request (the primary key is always tried first on every request so a cached client
 * is never permanently switched to an old key) and restricted to existing-resource endpoints so API key tests,
 * profile lookups, and new payment/session creations never silently hit a retired Mollie account.
 */
class FallbackApiKeyClient implements ClientInterface
{
    /**
     * Only paths referencing an existing resource ID (e.g. /v2/payments/tr_..., /v2/orders/ord_...,
     * /v2/customers/cst_..., /v2/sessions/sess_...) are eligible for fallback API key retries.
     */
    private const FALLBACK_ELIGIBLE_PATH_PATTERN = '#^/v2/(payments|orders|customers|sessions)/[^/]+#';

    /** @var string[] */
    private array $fallbackApiKeys = [];

    private bool $fallbackKeysLoaded = false;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly ?FetchFallbackApiKeys $fetchFallbackApiKeys = null,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        $primaryResponse = $this->client->send($request, $options);

        if (!$this->shouldRetryWithFallback($primaryResponse, $request->getUri()->getPath())) {
            return $primaryResponse;
        }

        $primaryAuthorization = $this->extractAuthorizationHeader($request, $options);

        foreach ($this->getFallbackApiKeys() as $fallbackKey) {
            $authorization = 'Bearer ' . $fallbackKey;
            if ($authorization === $primaryAuthorization) {
                continue;
            }

            $this->rewindRequestBody($request);
            $retryOptions = $this->withAuthorizationHeader($options, $authorization);
            $fallbackResponse = $this->client->send($request, $retryOptions);

            if (!in_array($fallbackResponse->getStatusCode(), [401, 404], true)) {
                return $fallbackResponse;
            }
        }

        return $primaryResponse;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        return $this->client->sendAsync($request, $options);
    }

    /**
     * @param string $method
     * @param string|UriInterface $uri
     * @param array<string, mixed> $options
     */
    public function request(string $method, $uri, array $options = []): ResponseInterface
    {
        $primaryResponse = $this->client->request($method, $uri, $options);

        $path = $uri instanceof UriInterface ? $uri->getPath() : (string) parse_url((string) $uri, PHP_URL_PATH);
        if (!$this->shouldRetryWithFallback($primaryResponse, $path)) {
            return $primaryResponse;
        }

        $primaryAuthorization = $this->extractAuthorizationFromOptions($options);

        foreach ($this->getFallbackApiKeys() as $fallbackKey) {
            $authorization = 'Bearer ' . $fallbackKey;
            if ($authorization === $primaryAuthorization) {
                continue;
            }

            $retryOptions = $this->withAuthorizationHeader($options, $authorization);
            $fallbackResponse = $this->client->request($method, $uri, $retryOptions);

            if (!in_array($fallbackResponse->getStatusCode(), [401, 404], true)) {
                return $fallbackResponse;
            }
        }

        return $primaryResponse;
    }

    /**
     * @param string $method
     * @param string|UriInterface $uri
     * @param array<string, mixed> $options
     */
    public function requestAsync(string $method, $uri, array $options = []): PromiseInterface
    {
        return $this->client->requestAsync($method, $uri, $options);
    }

    public function getConfig(?string $option = null): mixed
    {
        return $this->client->getConfig($option);
    }

    private function shouldRetryWithFallback(ResponseInterface $response, string $path): bool
    {
        if ($this->fetchFallbackApiKeys === null) {
            return false;
        }

        if (!in_array($response->getStatusCode(), [401, 404], true)) {
            return false;
        }

        return preg_match(self::FALLBACK_ELIGIBLE_PATH_PATTERN, $path) === 1;
    }

    /**
     * @return string[]
     */
    private function getFallbackApiKeys(): array
    {
        if (!$this->fallbackKeysLoaded && $this->fetchFallbackApiKeys !== null) {
            $this->fallbackKeysLoaded = true;
            $keys = array_map(
                static fn (mixed $key): string => is_string($key) ? trim($key) : '',
                $this->fetchFallbackApiKeys->retrieve(),
            );
            $this->fallbackApiKeys = array_values(array_unique(array_filter($keys, static fn (string $k): bool => $k !== '')));
        }

        return $this->fallbackApiKeys;
    }

    private function rewindRequestBody(RequestInterface $request): void
    {
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function extractAuthorizationHeader(RequestInterface $request, array $options): ?string
    {
        $fromOptions = $this->extractAuthorizationFromOptions($options);
        if ($fromOptions !== null) {
            return $fromOptions;
        }

        $header = $request->getHeaderLine('Authorization');

        return $header !== '' ? $header : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function extractAuthorizationFromOptions(array $options): ?string
    {
        if (!isset($options['headers']) || !is_array($options['headers'])) {
            return null;
        }

        foreach ($options['headers'] as $name => $value) {
            if (is_string($name) && strcasecmp($name, 'Authorization') === 0) {
                return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function withAuthorizationHeader(array $options, string $authorization): array
    {
        $headers = isset($options['headers']) && is_array($options['headers']) ? $options['headers'] : [];

        foreach (array_keys($headers) as $name) {
            if (is_string($name) && strcasecmp($name, 'Authorization') === 0) {
                unset($headers[$name]);
            }
        }

        $headers['Authorization'] = $authorization;
        $options['headers'] = $headers;

        return $options;
    }
}
