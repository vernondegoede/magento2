<?php

/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Test\Integration\Model\Client\Payments;

use Mollie\Payment\Model\Client\Payments\PaymentProcessors;
use Mollie\Payment\Model\Client\Payments\ProcessTransaction;
use Mollie\Payment\Test\Fakes\Service\Mollie\MockResponse;
use Mollie\Payment\Test\Integration\IntegrationTestCase;

class ProcessTransactionTest extends IntegrationTestCase
{
    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testResolvesLegacyOrderIdBeforeFetchingThePayment(): void
    {
        $order = $this->loadOrder('100000001');
        $order->setMollieTransactionId('ord_legacy1');

        $fake = $this->loadFakeMollieApiClient();
        $fake->fake([
            'GET /v2/orders/*' => MockResponse::ok([
                'resource' => 'order',
                'id' => 'ord_legacy1',
                '_embedded' => [
                    'payments' => [
                        ['resource' => 'payment', 'id' => 'tr_paid456', 'status' => 'paid'],
                    ],
                ],
            ]),
            'GET /v2/payments/*' => MockResponse::payment([
                'id' => 'tr_paid456',
                'status' => 'paid',
                'method' => 'ideal',
            ]),
        ]);

        $processors = $this->createMock(PaymentProcessors::class);
        $processors->method('process')->willReturnArgument(4);

        $instance = $this->objectManager->create(ProcessTransaction::class, [
            'paymentProcessors' => $processors,
        ]);

        $response = $instance->execute($order);

        $this->assertEquals('paid', $response->getStatus());
    }
}
