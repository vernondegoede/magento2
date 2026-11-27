<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Test\Integration\Service\Mollie\Wrapper;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Mollie\Api\Client as SdkClient;
use Mollie\Api\Models\Components\Security;
use Mollie\Api\Models\Operations\GetPaymentRequest;
use Mollie\Payment\Service\Mollie\Wrapper\FallbackApiKeyMiddleware;
use Mollie\Payment\Service\Mollie\Wrapper\FetchFallbackApiKeys;
use Mollie\Payment\Test\Fakes\Service\Mollie\MockResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class FallbackApiKeyMiddlewareTest extends TestCase
{
    public function testRetriesExistingResourceWithFallbackKeyWithoutHijackingPrimaryKey(): void
    {
        $seenAuthHeaders = [];
        $transport = function (RequestInterface $request) use (&$seenAuthHeaders): PromiseInterface {
            $auth = $request->getHeaderLine('Authorization');
            $seenAuthHeaders[] = $auth;
            $path = $request->getUri()->getPath();

            if ($path === '/v2/payments/tr_old') {
                return Create::promiseFor(
                    $auth === 'Bearer live_fallback_key'
                        ? new Response(200, [], '{"id":"tr_old"}')
                        : new Response(404, [], '{"status":404}'),
                );
            }

            return Create::promiseFor(
                $auth === 'Bearer live_primary_key'
                    ? new Response(200, [], '{"id":"tr_new"}')
                    : new Response(404, [], '{"status":404}'),
            );
        };

        $fallbackKeys = $this->createMock(FetchFallbackApiKeys::class);
        $fallbackKeys->expects($this->once())
            ->method('retrieve')
            ->willReturn(['live_primary_key', 'live_fallback_key']);

        $client = $this->buildClient($transport, $fallbackKeys);

        $oldResponse = $client->request('GET', 'https://api.mollie.com/v2/payments/tr_old', [
            'headers' => ['Authorization' => 'Bearer live_primary_key'],
        ]);
        $newResponse = $client->request('GET', 'https://api.mollie.com/v2/payments/tr_new', [
            'headers' => ['Authorization' => 'Bearer live_primary_key'],
        ]);

        $this->assertSame(200, $oldResponse->getStatusCode());
        $this->assertSame(200, $newResponse->getStatusCode());
        $this->assertSame(
            ['Bearer live_primary_key', 'Bearer live_fallback_key', 'Bearer live_primary_key'],
            $seenAuthHeaders,
        );
    }

    public function testDoesNotFallbackOnMethodsProfilesOrNewResourceCreation(): void
    {
        $calls = 0;
        $transport = function () use (&$calls): PromiseInterface {
            $calls++;

            return Create::promiseFor(new Response(401, [], '{"status":401}'));
        };

        $fallbackKeys = $this->createMock(FetchFallbackApiKeys::class);
        $fallbackKeys->expects($this->never())->method('retrieve');

        $client = $this->buildClient($transport, $fallbackKeys);

        $this->assertSame(401, $client->request('GET', 'https://api.mollie.com/v2/methods')->getStatusCode());
        $this->assertSame(401, $client->request('GET', 'https://api.mollie.com/v2/profiles/me')->getStatusCode());
        $this->assertSame(401, $client->request('POST', 'https://api.mollie.com/v2/payments')->getStatusCode());
        $this->assertSame(3, $calls);
    }

    public function testRewindsRequestBodyAndReturnsPrimaryResponseWhenAllFallbacksFail(): void
    {
        $seenBodies = [];
        $transport = function (RequestInterface $request) use (&$seenBodies): PromiseInterface {
            $seenBodies[] = $request->getBody()->getContents();
            $auth = $request->getHeaderLine('Authorization');

            return Create::promiseFor(
                $auth === 'Bearer live_primary_key'
                    ? new Response(404, [], '{"detail":"Primary not found"}')
                    : new Response(401, [], '{"detail":"Revoked old key"}'),
            );
        };

        $fallbackKeys = $this->createMock(FetchFallbackApiKeys::class);
        $fallbackKeys->method('retrieve')->willReturn(['live_revoked_key']);

        $client = $this->buildClient($transport, $fallbackKeys);
        $response = $client->request('POST', 'https://api.mollie.com/v2/payments/tr_old/refunds', [
            'headers' => ['Authorization' => 'Bearer live_primary_key'],
            'body' => '{"amount":{"currency":"EUR","value":"10.00"}}',
        ]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Primary not found', (string) $response->getBody());
        $this->assertSame(
            [
                '{"amount":{"currency":"EUR","value":"10.00"}}',
                '{"amount":{"currency":"EUR","value":"10.00"}}',
            ],
            $seenBodies,
        );
    }

    public function testComposesCleanlyWithMollieHooksWithoutLeakingInternalHeaders(): void
    {
        $seenRequests = [];
        $transport = function (RequestInterface $request) use (&$seenRequests): PromiseInterface {
            $seenRequests[] = $request;

            return Create::promiseFor(
                $request->getHeaderLine('Authorization') === 'Bearer live_fallback_key'
                    ? MockResponse::payment(['id' => 'tr_old'])
                    : MockResponse::error(404, 'Not Found', 'No payment exists with token tr_old.'),
            );
        };

        $fallbackKeys = $this->createMock(FetchFallbackApiKeys::class);
        $fallbackKeys->method('retrieve')->willReturn(['live_fallback_key']);

        $guzzle = $this->buildClient($transport, $fallbackKeys);
        $sdk = SdkClient::builder()
            ->setClient($guzzle)
            ->setSecurity(new Security(apiKey: 'live_primary_key'))
            ->setCustomUserAgent('MollieMagento2/3.4.0')
            ->build();

        $payment = $sdk->payments->get(new GetPaymentRequest(paymentId: 'tr_old'))->paymentResponse;

        $this->assertSame('tr_old', $payment->id);
        $this->assertCount(2, $seenRequests);

        foreach ($seenRequests as $request) {
            $this->assertFalse($request->hasHeader('x-mollie-sdk-fallback-idempotency-key'));
            $this->assertFalse($request->hasHeader('x-mollie-sdk-operation-id'));
            $this->assertNotSame('', $request->getHeaderLine('idempotency-key'));
            $this->assertStringContainsString('MollieMagento2/3.4.0', $request->getHeaderLine('user-agent'));
        }

        $this->assertSame(
            $seenRequests[0]->getHeaderLine('idempotency-key'),
            $seenRequests[1]->getHeaderLine('idempotency-key'),
        );
    }

    private function buildClient(callable $transport, FetchFallbackApiKeys $fallbackKeys): Client
    {
        $stack = HandlerStack::create($transport);
        $stack->push(new FallbackApiKeyMiddleware($fallbackKeys), 'mollie-fallback-api-key');

        return new Client([
            'http_errors' => false,
            'handler' => $stack,
        ]);
    }
}
