<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Service\Mollie\Wrapper;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle HandlerStack middleware that retries requests for existing historical resources failing with 401 or 404
 * using previously stored fallback API keys.
 *
 * Implemented as a HandlerStack middleware (rather than a ClientInterface decorator) so the HTTP client passed to
 * mollie/php remains a genuine GuzzleHttp\Client instance, allowing MollieHooks::sdkInit() to install its own
 * request-finalizer middleware alongside this one.
 */
class FallbackApiKeyMiddleware
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
        private readonly ?FetchFallbackApiKeys $fetchFallbackApiKeys = null,
    ) {}

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            return $handler($request, $options)->then(
                function (ResponseInterface $primaryResponse) use (
                    $handler,
                    $request,
                    $options,
                ): ResponseInterface|PromiseInterface {
                    if (!$this->shouldRetryWithFallback($primaryResponse, $request->getUri()->getPath())) {
                        return $primaryResponse;
                    }

                    $primaryAuthorization = $request->getHeaderLine('Authorization');
                    $fallbackKeys = array_values(array_filter(
                        $this->getFallbackApiKeys(),
                        static fn (string $key): bool => ('Bearer ' . $key) !== $primaryAuthorization,
                    ));

                    if ($fallbackKeys === []) {
                        return $primaryResponse;
                    }

                    return $this->retryWithKeys($handler, $request, $options, $fallbackKeys, 0, $primaryResponse);
                },
            );
        };
    }

    /**
     * @param array<string, mixed> $options
     * @param string[] $fallbackKeys
     */
    private function retryWithKeys(
        callable $handler,
        RequestInterface $request,
        array $options,
        array $fallbackKeys,
        int $index,
        ResponseInterface $primaryResponse,
    ): PromiseInterface {
        $this->rewindRequestBody($request);
        $retryRequest = $request->withHeader('Authorization', 'Bearer ' . $fallbackKeys[$index]);

        return $handler($retryRequest, $options)->then(
            function (ResponseInterface $fallbackResponse) use (
                $handler,
                $request,
                $options,
                $fallbackKeys,
                $index,
                $primaryResponse,
            ): ResponseInterface|PromiseInterface {
                if (!in_array($fallbackResponse->getStatusCode(), [401, 404], true)) {
                    return $fallbackResponse;
                }

                if ($index + 1 >= count($fallbackKeys)) {
                    return $primaryResponse;
                }

                return $this->retryWithKeys($handler, $request, $options, $fallbackKeys, $index + 1, $primaryResponse);
            },
        );
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
            $this->fallbackApiKeys = array_values(
                array_unique(array_filter($keys, static fn (string $k): bool => $k !== '')),
            );
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
}
