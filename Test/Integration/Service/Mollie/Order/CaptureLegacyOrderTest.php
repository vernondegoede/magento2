<?php

/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Test\Integration\Service\Mollie\Order;

use Magento\Framework\Exception\LocalizedException;
use Mollie\Payment\Service\Mollie\Order\CaptureLegacyOrder;
use Mollie\Payment\Test\Fakes\Service\Mollie\FakeMollieApiClient;
use Mollie\Payment\Test\Fakes\Service\Mollie\MockResponse;
use Mollie\Payment\Test\Integration\IntegrationTestCase;
use Psr\Http\Message\RequestInterface;

class CaptureLegacyOrderTest extends IntegrationTestCase
{
    public function testShipsTheLegacyOrderAndReturnsTheShipmentId(): void
    {
        $fake = $this->fakeShipmentRequest(MockResponse::created([
            'resource' => 'shipment',
            'id' => 'shp_abc123',
            'orderId' => 'ord_legacy1',
        ]));

        $instance = $this->objectManager->create(CaptureLegacyOrder::class);
        $result = $instance->execute($fake->loadByStore(), 'ord_legacy1');

        $this->assertEquals('shp_abc123', $result);
    }

    public function testShipsTheWholeOrderWhenNoLinesAreProvided(): void
    {
        $fake = $this->fakeShipmentRequest(MockResponse::created([
            'resource' => 'shipment',
            'id' => 'shp_abc123',
            'orderId' => 'ord_legacy1',
        ]));

        $instance = $this->objectManager->create(CaptureLegacyOrder::class);
        $instance->execute($fake->loadByStore(), 'ord_legacy1');

        $fake->assertSent('POST /v2/orders/*/shipments', function (RequestInterface $request, array $payload): bool {
            $this->assertArrayNotHasKey('lines', $payload);

            return true;
        });
    }

    public function testShipsOnlyTheProvidedLinesForAPartialCapture(): void
    {
        $fake = $this->fakeShipmentRequest(MockResponse::created([
            'resource' => 'shipment',
            'id' => 'shp_abc123',
            'orderId' => 'ord_legacy1',
        ]));

        $lines = [['id' => 'odl_1', 'quantity' => 1]];

        $instance = $this->objectManager->create(CaptureLegacyOrder::class);
        $result = $instance->execute($fake->loadByStore(), 'ord_legacy1', ['lines' => $lines]);

        $this->assertEquals('shp_abc123', $result);

        $fake->assertSent('POST /v2/orders/*/shipments', function (RequestInterface $request, array $payload) use ($lines): bool {
            $this->assertSame($lines, $payload['lines'] ?? null);

            return true;
        });
    }

    public function testThrowsWhenTheShipmentResponseHasNoId(): void
    {
        $fake = $this->fakeShipmentRequest(MockResponse::created([
            'resource' => 'shipment',
            'orderId' => 'ord_legacy1',
        ]));

        $instance = $this->objectManager->create(CaptureLegacyOrder::class);

        $this->expectException(LocalizedException::class);
        $instance->execute($fake->loadByStore(), 'ord_legacy1');
    }

    private function fakeShipmentRequest(mixed $response): FakeMollieApiClient
    {
        $fake = $this->loadFakeMollieApiClient();
        $fake->fake([
            'POST /v2/orders/*/shipments' => $response,
        ]);

        return $fake;
    }
}
