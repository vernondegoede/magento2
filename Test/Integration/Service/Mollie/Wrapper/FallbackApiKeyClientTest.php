<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Test\Integration\Service\Mollie\Wrapper;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mollie\Payment\Service\Mollie\Wrapper\FallbackApiKeyClient;
use Mollie\Payment\Service\Mollie\Wrapper\FetchFallbackApiKeys;
use Mollie\Payment\Test\Fakes\Service\Mollie\FakeHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class FallbackApiKeyClientTest extends TestCase
{
    public function testRetriesExistingResourceWithFallbackKeyWithoutHijackingPrimaryKey(): void
    {
        $seenAuthHeaders = [];
        $fakeClient = new FakeHttpClient([
            'GET /v2/payments/tr_old' => function (RequestInterface $request, array $body, array $options) use (&$seenAuthHeaders): ResponseInterface {
                $auth = $options['headers']['Authorization'] ?? '';
                $seenAuthHeaders[] = $auth;

                return $auth === 'Bearer live_fallback_key'
                    ? new Response(200, [], '{"id":"tr_old"}')
                    : new Response(404, [], '{"status":404}');
            },
            'GET /v2/payments/tr_new' => function (RequestInterface $request, array $body, array $options) use (&$seenAuthHeaders): ResponseInterface {
                $auth = $options['headers']['Authorization'] ?? '';
                $seenAuthHeaders[] = $auth;

                return $auth === 'Bearer live_primary_key'
                    ? new Response(200, [], '{"id":"tr_new"}')
                    : new Response(404, [], '{"status":404}');
            },
        ]);

        $fallbackKeys = $this->createMock(FetchFallbackApiKeys::class);
        $fallbackKeys->expects($this->once())
            ->method('retrieve')
            ->willReturn(['live_primary_key', 'live_fallback_key']);

        $client = new FallbackApiKeyClient($fakeClient, $fallbackKeys);

        $oldResponse = $client->send(
            new Request('GET', 'https://api.mollie.com/v2/payments/tr_old'),
            ['headers' => ['Authorization' => 'Bearer live_primary_key']],
        );
        $newResponse = $client->send(
            new Request('GET', 'https://api.mollie.com/v2/payments/tr_new'),
            ['headers' => ['Authorization' => 'Bearer live_primary_key']],
        );

        $this->assertSame(200, $oldResponse->getStatusCode());
        $this->assertSame(200, $newResponse->getStatusCode());
        $this->assertSame(
            ['Bearer live_primary_key', 'Bearer live_fallback_key', 'Bearer live_primary_key'],
            $seenAuthHeaders,
        );
    }

    public function testDoesNotFallbackOnMethodsProfilesOrNewResourceCreation(): void
    {
        $fakeClient = new FakeHttpClient([
            'GET /v2/methods' => new Response(401, [], '{"status":401}'),
            'GET /v2/profiles/me' => new Response(401, [], '{"status":401}'),
            'POST /v2/payments' => new Response(401, [], '{"status":401}'),
        ]);

        $fallbackKeys = $this->createMock(FetchFallbackApiKeys::class);
        $fallbackKeys->expects($this->never())->method('retrieve');

        $client = new FallbackApiKeyClient($fakeClient, $fallbackKeys);

        $this->assertSame(401, $client->send(new Request('GET', 'https://api.mollie.com/v2/methods'))->getStatusCode());
        $this->assertSame(401, $client->send(new Request('GET', 'https://api.mollie.com/v2/profiles/me'))->getStatusCode());
        $this->assertSame(401, $client->send(new Request('POST', 'https://api.mollie.com/v2/payments'))->getStatusCode());
        $fakeClient->assertSentCount(3);
    }

    public function testRewindsRequestBodyAndReturnsPrimaryResponseWhenAllFallbacksFail(): void
    {
        $seenBodies = [];
        $fakeClient = new FakeHttpClient([
            'POST /v2/payments/tr_old/refunds' => function (RequestInterface $request, array $body, array $options) use (&$seenBodies): ResponseInterface {
                $seenBodies[] = $request->getBody()->getContents();
                $auth = $options['headers']['Authorization'] ?? '';

                return $auth === 'Bearer live_primary_key'
                    ? new Response(404, [], '{"detail":"Primary not found"}')
                    : new Response(401, [], '{"detail":"Revoked old key"}');
            },
        ]);

        $fallbackKeys = $this->createMock(FetchFallbackApiKeys::class);
        $fallbackKeys->method('retrieve')->willReturn(['live_revoked_key']);

        $client = new FallbackApiKeyClient($fakeClient, $fallbackKeys);
        $response = $client->send(
            new Request('POST', 'https://api.mollie.com/v2/payments/tr_old/refunds', [], '{"amount":{"currency":"EUR","value":"10.00"}}'),
            ['headers' => ['Authorization' => 'Bearer live_primary_key']],
        );

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
}
