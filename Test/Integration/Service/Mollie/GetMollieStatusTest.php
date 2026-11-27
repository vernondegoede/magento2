<?php

/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Test\Integration\Service\Mollie;

use Magento\Sales\Api\OrderRepositoryInterface;
use Mollie\Payment\Service\Mollie\GetMollieStatus;
use Mollie\Payment\Test\Fakes\Service\Mollie\MockResponse;
use Mollie\Payment\Test\Integration\IntegrationTestCase;

class GetMollieStatusTest extends IntegrationTestCase
{
    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testResolvesLegacyOrderIdBeforeFetchingTheStatus(): void
    {
        $order = $this->loadOrder('100000001');
        $order->setMollieTransactionId('ord_legacy1');
        $this->objectManager->get(OrderRepositoryInterface::class)->save($order);

        $this->loadFakeMollieApiClient()->fake([
            'GET /v2/orders/ord_legacy1' => MockResponse::ok([
                'resource' => 'order',
                'id' => 'ord_legacy1',
                '_embedded' => [
                    'payments' => [
                        ['resource' => 'payment', 'id' => 'tr_paid456', 'status' => 'paid'],
                    ],
                ],
            ]),
            'GET /v2/payments/tr_paid456' => MockResponse::payment([
                'id' => 'tr_paid456',
                'status' => 'paid',
                'method' => 'ideal',
            ]),
        ]);

        $instance = $this->objectManager->create(GetMollieStatus::class);
        $result = $instance->execute((int) $order->getId());

        $this->assertEquals('paid', $result->getStatus());
        $this->assertEquals('ideal', $result->getMethod());
    }
}
