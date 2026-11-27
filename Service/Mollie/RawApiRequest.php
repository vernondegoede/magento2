<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Service\Mollie;

use GuzzleHttp\Psr7\Request;
use Mollie\Api\Client;
use Mollie\Api\Models\Errors\APIException;
use Mollie\Api\Models\Errors\ErrorResponse;
use Mollie\Api\Models\Errors\ErrorResponseThrowable;
use Mollie\Api\Utils\JSON;
use Mollie\Api\Utils\Utils;
use Psr\Http\Message\ResponseInterface;
use Speakeasy\Serializer\DeserializationContext;

/**
 * Executes HTTP requests against Mollie endpoints or beta fields that are not yet modeled in the OpenAPI-generated
 * SDK, while reusing the SDK client's configured HTTP adapter, authentication, user-agent, and error handling.
 */
class RawApiRequest
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @throws APIException
     * @throws ErrorResponseThrowable
     */
    public function send(
        Client $client,
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        ?string $idempotencyKey = null,
    ): ResponseInterface {
        $sdkConfig = $client->sdkConfiguration;
        $baseUrl = rtrim($sdkConfig->getTemplatedServerUrl(), '/');
        $url = $baseUrl . '/' . ltrim($path, '/');

        $httpOptions = [
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/hal+json',
                'user-agent' => $sdkConfig->userAgent,
            ],
        ];

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $httpOptions['headers']['Idempotency-Key'] = $idempotencyKey;
        }

        if ($query !== []) {
            $httpOptions['query'] = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        if ($body !== null) {
            $httpOptions['headers']['Content-Type'] = 'application/json';
            $httpOptions['body'] = json_encode($body, JSON_THROW_ON_ERROR);
        }

        $httpClient = $sdkConfig->securitySource !== null
            ? Utils::configureSecurityClient(
                $sdkConfig->defaultClient,
                $sdkConfig->getSecurity(),
                ['apiKey', 'advancedAccessToken', 'oAuth'],
            )
            : $sdkConfig->defaultClient;

        $response = $httpClient->send(new Request($method, $url), $httpOptions);
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 200 && $statusCode < 300) {
            return $response;
        }

        $contentType = $response->getHeader('Content-Type')[0] ?? '';
        $responseBody = (string) $response->getBody();

        if (Utils::matchContentType($contentType, 'application/hal+json') && $responseBody !== '') {
            try {
                $serializer = JSON::createSerializer();
                /** @var ErrorResponse $error */
                $error = $serializer->deserialize(
                    $responseBody,
                    ErrorResponse::class,
                    'json',
                    DeserializationContext::create()->setRequireAllRequiredProperties(true),
                );
                $error->rawResponse = $response;
                throw $error->toException();
            } catch (ErrorResponseThrowable $throwable) {
                throw $throwable;
            } catch (\Throwable) {
                // Fall back to generic APIException below if response body does not match ErrorResponse schema.
            }
        }

        throw new APIException('API error occurred', $statusCode, $responseBody, $response);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     * @throws APIException
     * @throws ErrorResponseThrowable
     */
    public function sendJson(
        Client $client,
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
    ): array {
        $response = $this->send($client, $method, $path, $query, $body);
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
