<?php

/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Test\Integration\Service\Mollie\Order;

use Magento\Framework\Exception\LocalizedException;
use Mollie\Api\Client;
use Mollie\Payment\Service\Mollie\Order\ResolvePaymentId;
use Mollie\Payment\Test\Fakes\Service\Mollie\MockResponse;
use Mollie\Payment\Test\Integration\IntegrationTestCase;

class ResolvePaymentIdTest extends IntegrationTestCase
{
    public function testReturnsPaymentIdUnchangedWhenItIsNotALegacyOrderId(): void
    {
        $client = $this->fakeClient();

        $instance = $this->objectManager->create(ResolvePaymentId::class);
        $result = $instance->execute($client, 'tr_abc123');

        $this->assertEquals('tr_abc123', $result);
    }

    public function testResolvesLegacyOrderIdToItsSettledPayment(): void
    {
        $client = $this->fakeClient([
            'GET /v2/orders/*' => MockResponse::ok($this->orderWithPaymentsResponse()),
        ]);

        $instance = $this->objectManager->create(ResolvePaymentId::class);
        $result = $instance->execute($client, 'ord_legacy1');

        $this->assertEquals('tr_paid456', $result);
    }

    public function testFallsBackToTheLastPaymentWhenNoneAreSettled(): void
    {
        $client = $this->fakeClient([
            'GET /v2/orders/*' => MockResponse::ok($this->orderWithoutSettledPaymentsResponse()),
        ]);

        $instance = $this->objectManager->create(ResolvePaymentId::class);
        $result = $instance->execute($client, 'ord_legacy2');

        $this->assertEquals('tr_canceled789', $result);
    }

    public function testThrowsWhenLegacyOrderHasNoPayments(): void
    {
        $client = $this->fakeClient([
            'GET /v2/orders/*' => MockResponse::ok([
                'resource' => 'order',
                'id' => 'ord_empty',
                '_embedded' => [
                    'payments' => [],
                ],
            ]),
        ]);

        $instance = $this->objectManager->create(ResolvePaymentId::class);

        $this->expectException(LocalizedException::class);
        $instance->execute($client, 'ord_empty');
    }

    /**
     * @param array<int|string, mixed> $responses
     */
    private function fakeClient(array $responses = []): Client
    {
        $fake = $this->loadFakeMollieApiClient();
        $fake->fake($responses);

        return $fake->loadByStore();
    }

    /**
     * @return array<string, mixed>
     */
    private function orderWithPaymentsResponse(): array
    {
        return [
            'resource' => 'order',
            'id' => 'ord_legacy1',
            '_embedded' => [
                'payments' => [
                    ['resource' => 'payment', 'id' => 'tr_failed123', 'status' => 'failed'],
                    ['resource' => 'payment', 'id' => 'tr_paid456', 'status' => 'paid'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderWithoutSettledPaymentsResponse(): array
    {
        return [
            'resource' => 'order',
            'id' => 'ord_legacy2',
            '_embedded' => [
                'payments' => [
                    ['resource' => 'payment', 'id' => 'tr_expired456', 'status' => 'expired'],
                    ['resource' => 'payment', 'id' => 'tr_canceled789', 'status' => 'canceled'],
                ],
            ],
        ];
    }
}
