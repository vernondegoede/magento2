<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Test\Fakes\Service\Mollie;

use Mollie\Api\Client;
use Mollie\Api\Models\Components\Security;
use Mollie\Payment\Service\Mollie\MollieApiClient;

class FakeMollieApiClient extends MollieApiClient
{
    private ?Client $instance = null;

    private ?FakeHttpClient $fakeHttpClient = null;

    public function setInstance(Client $instance): void
    {
        $this->instance = $instance;
    }

    public function loadByStore(?int $storeId = null): Client
    {
        if ($this->instance) {
            return $this->instance;
        }

        return parent::loadByStore($storeId);
    }

    public function loadByApiKey(string $apiKey): Client
    {
        if ($this->instance) {
            return $this->instance;
        }

        return parent::loadByApiKey($apiKey);
    }

    /**
     * @param array<int|string, mixed> $expectedResponses
     */
    public function fake(array $expectedResponses = []): FakeHttpClient
    {
        $this->fakeHttpClient = new FakeHttpClient($expectedResponses);
        $this->instance = Client::builder()
            ->setClient($this->fakeHttpClient)
            ->setSecurity(new Security(apiKey: 'test_dHar4XY7LxsDOtmnkVtjNVWXLSlXsM'))
            ->build();

        return $this->fakeHttpClient;
    }

    public function getFakeHttpClient(): ?FakeHttpClient
    {
        return $this->fakeHttpClient;
    }

    public function assertSent(string $pattern, ?callable $callback = null): void
    {
        $this->fakeHttpClient?->assertSent($pattern, $callback);
    }

    public function assertSentCount(int $count, ?string $pattern = null): void
    {
        $this->fakeHttpClient?->assertSentCount($count, $pattern);
    }

    public function assertNothingSent(): void
    {
        $this->fakeHttpClient?->assertNothingSent();
    }
}
