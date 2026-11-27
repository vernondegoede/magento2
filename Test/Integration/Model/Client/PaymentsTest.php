<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Test\Integration\Model\Client;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Mollie\Api\Client;
use Mollie\Api\Models\Components\PaymentResponse;
use Mollie\Api\Models\Components\PaymentResponseStatus;
use Mollie\Api\Models\Errors\APIException;
use Mollie\Payment\Api\Data\TransactionToOrderInterface;
use Mollie\Payment\Api\TransactionToOrderRepositoryInterface;
use Mollie\Payment\Model\Client\Payments;
use Mollie\Payment\Model\Client\Payments\ProcessTransaction;
use Mollie\Payment\Model\OrderLines;
use Mollie\Payment\Test\Fakes\Service\Mollie\MockResponse;
use Mollie\Payment\Test\Integration\IntegrationTestCase;
use Mollie\Payment\Test\Integration\MolliePaymentBuilder;
use PHPUnit\Framework\Attributes\DataProvider;

class PaymentsTest extends IntegrationTestCase
{
    public static function processTransactionProvider(): array
    {
        return [
            ['USD'],
        ];
    }

    /**
     * @dataProvider processTransactionProvider
     * @magentoDataFixture Magento/Sales/_files/order.php
     *
     * @param string $currency
     *
     * @throws LocalizedException
     * @throws APIException
     */
    #[DataProvider('processTransactionProvider')]
    public function testProcessTransaction(string $currency): void
    {
        $fake = $this->loadFakeMollieApiClient();
        $fake->fake([
            'GET /v2/payments/*' => MockResponse::payment([
                'amount' => [
                    'value' => '100.00',
                    'currency' => $currency,
                ],
            ]),
        ]);

        $orderLinesMock = $this->createMock(OrderLines::class);

        $orderSenderMock = $this->createMock(OrderSender::class);
        $orderSenderMock->method('send')->willReturn(true);

        $invoiceSenderMock = $this->createMock(InvoiceSender::class);
        $invoiceSenderMock->method('send')->willReturn(true);

        /** @var Payments\ProcessTransaction $instance */
        $instance = $this->objectManager->create(ProcessTransaction::class, [
            'orderLines' => $orderLinesMock,
            'orderSender' => $orderSenderMock,
            'invoiceSender' => $invoiceSenderMock,
        ]);

        $order = $this->loadOrder('100000001');
        $order->setMollieTransactionId('MOLLIE_TEST_TRANSACTION');
        $order->setBaseCurrencyCode($currency);
        $order->setOrderCurrencyCode($currency);

        /** @var TransactionToOrderInterface $model */
        $model = $this->objectManager->create(TransactionToOrderInterface::class);
        $model->setTransactionId('MOLLIE_TEST_TRANSACTION');
        $model->setOrderId((int)$order->getId());

        $this->objectManager->get(TransactionToOrderRepositoryInterface::class)->save($model);

        $instance->execute($order)->toArray();

        $this->assertEquals(Order::STATE_PROCESSING, $order->getState());
    }

    protected function getMolliePayment(string $status, string $currency): PaymentResponse
    {
        /** @var MolliePaymentBuilder $builder */
        $builder = $this->objectManager->create(MolliePaymentBuilder::class);
        $builder->setId('tr_test_transaction');
        $builder->setStatus($status);
        $builder->setAmount(100, $currency);

        return $builder->build();
    }

    public static function checksIfTheOrderHasAnUpdateProvider(): array
    {
        return [
            [PaymentResponseStatus::Open->value, Order::STATE_NEW],
            [PaymentResponseStatus::Pending->value, Order::STATE_PENDING_PAYMENT],
            [PaymentResponseStatus::Authorized->value, Order::STATE_PROCESSING],
            [PaymentResponseStatus::Canceled->value, Order::STATE_CANCELED],
            [PaymentResponseStatus::Expired->value, Order::STATE_CLOSED],
            [PaymentResponseStatus::Paid->value, Order::STATE_PROCESSING],
            [PaymentResponseStatus::Failed->value, Order::STATE_CANCELED],
        ];
    }

    /**
     * @dataProvider checksIfTheOrderHasAnUpdateProvider
     */
    #[DataProvider('checksIfTheOrderHasAnUpdateProvider')]
    public function testChecksIfTheOrderHasAnUpdate(string $mollieStatus, string $magentoStatus): void
    {
        /** @var OrderInterface $order */
        $order = $this->objectManager->create(OrderInterface::class);
        $order->setMollieTransactionId('MOLLIE_TEST_TRANSACTION');
        $order->setState($magentoStatus);

        /** @var Payments $instance */
        $instance = $this->objectManager->create(Payments::class);

        $fake = $this->loadFakeMollieApiClient();
        $fake->fake([
            'GET /v2/payments/*' => MockResponse::payment(['status' => $mollieStatus]),
        ]);

        $this->assertFalse($instance->orderHasUpdate($order, $fake->loadByStore()));
    }

    public function testItReturnsFalseForCompletedOrders()
    {
        /** @var OrderInterface $order */
        $order = $this->objectManager->create(OrderInterface::class);
        $order->setState(Order::STATE_COMPLETE);

        $mollieApi = Client::builder()->build();

        /** @var Payments $instance */
        $instance = $this->objectManager->create(Payments::class);

        $this->assertFalse($instance->orderHasUpdate($order, $mollieApi));
    }

    public function testItReturnsFalseForClosedOrders()
    {
        /** @var OrderInterface $order */
        $order = $this->objectManager->create(OrderInterface::class);
        $order->setState(Order::STATE_CLOSED);

        $mollieApi = Client::builder()->build();

        /** @var Payments $instance */
        $instance = $this->objectManager->create(Payments::class);

        $this->assertFalse($instance->orderHasUpdate($order, $mollieApi));
    }
}
