<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Test\Integration\Controller\Checkout;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Request;
use Mollie\Api\Models\Components\PaymentResponseStatus;
use Mollie\Payment\Api\Data\TransactionToOrderInterface;
use Mollie\Payment\Api\TransactionToOrderRepositoryInterface;
use Mollie\Payment\Model\Methods\ApplePay;
use Mollie\Payment\Service\Mollie\MollieApiClient as MollieApiClientService;
use Mollie\Payment\Service\PaymentToken\PaymentTokenForOrder;
use Mollie\Payment\Test\Fakes\Service\Mollie\FakeMollieApiClient;
use Mollie\Payment\Test\Fakes\Service\Mollie\MockResponse;
use Mollie\Payment\Test\Integration\ControllerTestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class WebhookDuplicatePaymentsTest extends ControllerTestCase
{
    private const FIXTURE_ORDER_INCREMENT_ID = '100000001';
    private const PAID_TRANSACTION_ID = 'tr_paidDuplicate';
    private const FAILED_TRANSACTION_ID = 'tr_failedDuplicate';
    private const OPEN_TRANSACTION_ID = 'tr_openOriginal';
    private const CANCELED_DUPLICATE_TRANSACTION_ID = 'tr_canceledDuplicate';
    private const WEBHOOK_PATH = 'mollie/checkout/webhook';

    /**
     * Nullable because older Magento test frameworks set every test case property to null after each test.
     *
     * @var array<string, string>|null
     */
    private ?array $paymentStatuses = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paymentStatuses = [
            self::PAID_TRANSACTION_ID => PaymentResponseStatus::Paid->value,
            self::FAILED_TRANSACTION_ID => PaymentResponseStatus::Failed->value,
            self::OPEN_TRANSACTION_ID => PaymentResponseStatus::Open->value,
            self::CANCELED_DUPLICATE_TRANSACTION_ID => PaymentResponseStatus::Canceled->value,
        ];
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @magentoConfigFixture default_store payment/mollie_general/process_transactions_in_the_queue 0
     */
    public function testKeepsThePaidOrderWhenASecondLinkedPaymentFails(): void
    {
        $order = $this->preparePendingOrder(self::FAILED_TRANSACTION_ID);
        $this->linkTransaction($order, self::PAID_TRANSACTION_ID);
        $this->linkTransaction($order, self::FAILED_TRANSACTION_ID);

        $this->dispatchWebhook(['id' => self::FAILED_TRANSACTION_ID]);

        $this->assertSame(Order::STATE_PROCESSING, $this->reloadOrder($order)->getState());
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @magentoConfigFixture default_store payment/mollie_general/process_transactions_in_the_queue 0
     */
    public function testRecordsAPaidPaymentThatWasNeverLinkedToTheOrder(): void
    {
        $order = $this->preparePendingOrder(self::FAILED_TRANSACTION_ID);
        $this->linkTransaction($order, self::FAILED_TRANSACTION_ID);
        $this->dispatchWebhook(['id' => self::FAILED_TRANSACTION_ID]);
        $this->assertSame(Order::STATE_CANCELED, $this->reloadOrder($order)->getState());
        $this->resetRequest();

        $this->dispatchWebhook($this->getWebhookParamsWithOrderIdFallback(self::PAID_TRANSACTION_ID, $order));

        $this->assertContains(self::PAID_TRANSACTION_ID, $this->getLinkedTransactionIds($order));
        $this->assertSame(Order::STATE_PROCESSING, $this->reloadOrder($order)->getState());
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @magentoConfigFixture default_store payment/mollie_general/process_transactions_in_the_queue 0
     */
    public function testKeepsAnUnlinkedPaidPaymentWhenTheLinkedPaymentFailsLater(): void
    {
        $order = $this->preparePendingOrder(self::FAILED_TRANSACTION_ID);
        $this->linkTransaction($order, self::FAILED_TRANSACTION_ID);
        $this->paymentStatuses[self::FAILED_TRANSACTION_ID] = PaymentResponseStatus::Open->value;
        $this->dispatchWebhook($this->getWebhookParamsWithOrderIdFallback(self::PAID_TRANSACTION_ID, $order));
        $this->resetRequest();
        $this->paymentStatuses[self::FAILED_TRANSACTION_ID] = PaymentResponseStatus::Failed->value;

        $this->dispatchWebhook(['id' => self::FAILED_TRANSACTION_ID]);

        $reloadedOrder = $this->reloadOrder($order);
        $this->assertSame(Order::STATE_PROCESSING, $reloadedOrder->getState());
        $this->assertSame(1, $reloadedOrder->getInvoiceCollection()->getSize());
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @magentoConfigFixture default_store payment/mollie_general/process_transactions_in_the_queue 0
     */
    public function testKeepsTheOrderOpenWhenAnUnlinkedDuplicateIsCanceled(): void
    {
        $order = $this->preparePendingOrder(self::OPEN_TRANSACTION_ID);
        $this->linkTransaction($order, self::OPEN_TRANSACTION_ID);

        $this->dispatchWebhook(
            $this->getWebhookParamsWithOrderIdFallback(self::CANCELED_DUPLICATE_TRANSACTION_ID, $order),
        );

        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->reloadOrder($order)->getState());
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @magentoConfigFixture default_store payment/mollie_general/process_transactions_in_the_queue 0
     */
    public function testCancelsTheOrderWhenItsPaymentExpiresAfterACanceledDuplicate(): void
    {
        $order = $this->preparePendingOrder(self::OPEN_TRANSACTION_ID);
        $this->linkTransaction($order, self::OPEN_TRANSACTION_ID);
        $this->dispatchWebhook(
            $this->getWebhookParamsWithOrderIdFallback(self::CANCELED_DUPLICATE_TRANSACTION_ID, $order),
        );
        $this->resetRequest();
        $this->paymentStatuses[self::OPEN_TRANSACTION_ID] = PaymentResponseStatus::Expired->value;

        $this->dispatchWebhook(['id' => self::OPEN_TRANSACTION_ID]);

        $this->assertSame(Order::STATE_CANCELED, $this->reloadOrder($order)->getState());
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @magentoConfigFixture default_store payment/mollie_general/process_transactions_in_the_queue 0
     */
    public function testKeepsTheOrderOpenUntilItsPaymentExpiresAfterAnExpiredDuplicate(): void
    {
        $order = $this->preparePendingOrder(self::OPEN_TRANSACTION_ID);
        $this->linkTransaction($order, self::OPEN_TRANSACTION_ID);
        $this->paymentStatuses[self::CANCELED_DUPLICATE_TRANSACTION_ID] = PaymentResponseStatus::Expired->value;
        $this->dispatchWebhook(
            $this->getWebhookParamsWithOrderIdFallback(self::CANCELED_DUPLICATE_TRANSACTION_ID, $order),
        );
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->reloadOrder($order)->getState());
        $this->resetRequest();
        $this->paymentStatuses[self::OPEN_TRANSACTION_ID] = PaymentResponseStatus::Expired->value;

        $this->dispatchWebhook(['id' => self::OPEN_TRANSACTION_ID]);

        $this->assertSame(Order::STATE_CANCELED, $this->reloadOrder($order)->getState());
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @magentoConfigFixture default_store payment/mollie_general/process_transactions_in_the_queue 0
     */
    public function testCancelsTheOrderWhenItsOpenPaymentExpiresAfterASecondLinkedPaymentIsCanceled(): void
    {
        $order = $this->preparePendingOrder(self::CANCELED_DUPLICATE_TRANSACTION_ID);
        $this->linkTransaction($order, self::OPEN_TRANSACTION_ID);
        $this->linkTransaction($order, self::CANCELED_DUPLICATE_TRANSACTION_ID);
        $this->dispatchWebhook(['id' => self::CANCELED_DUPLICATE_TRANSACTION_ID]);
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->reloadOrder($order)->getState());
        $this->resetRequest();
        $this->paymentStatuses[self::OPEN_TRANSACTION_ID] = PaymentResponseStatus::Expired->value;

        $this->dispatchWebhook(['id' => self::OPEN_TRANSACTION_ID]);

        $this->assertSame(Order::STATE_CANCELED, $this->reloadOrder($order)->getState());
    }

    private function preparePendingOrder(string $transactionId): OrderInterface
    {
        $order = $this->_objectManager->get(OrderInterface::class)->loadByIncrementId(
            self::FIXTURE_ORDER_INCREMENT_ID,
        );
        $order->setBaseCurrencyCode('EUR');
        $order->setOrderCurrencyCode('EUR');
        $order->setState(Order::STATE_PENDING_PAYMENT);
        $order->setStatus(Order::STATE_PENDING_PAYMENT);
        $order->setMollieTransactionId($transactionId);
        $order->getPayment()->setMethod(ApplePay::CODE);
        array_map($this->ensureItemHasSku(...), $order->getItems());
        $this->fakeMolliePayments($order);

        return $this->_objectManager->get(OrderRepositoryInterface::class)->save($order);
    }

    private function ensureItemHasSku(Order\Item $item): void
    {
        $item->setSku($item->getSku() ?: $item->getProduct()->getSku());
    }

    private function linkTransaction(OrderInterface $order, string $transactionId): void
    {
        /** @var TransactionToOrderInterface $transactionToOrder */
        $transactionToOrder = $this->_objectManager->create(TransactionToOrderInterface::class);
        $transactionToOrder->setOrderId((int)$order->getEntityId());
        $transactionToOrder->setTransactionId($transactionId);

        $this->_objectManager->get(TransactionToOrderRepositoryInterface::class)->save($transactionToOrder);
    }

    private function fakeMolliePayments(OrderInterface $order): void
    {
        $cart = $this->_objectManager->create(CartInterface::class);
        $cart->setStoreId((int) $order->getStoreId());
        $this->_objectManager->get(CartRepositoryInterface::class)->save($cart);
        $order->setQuoteId((int) $cart->getId());
        $paymentToken = $this->_objectManager->get(PaymentTokenForOrder::class)->execute($order);

        /** @var FakeMollieApiClient $fakeMollieApiClient */
        $fakeMollieApiClient = $this->_objectManager->create(FakeMollieApiClient::class);
        $fakeMollieApiClient->fake([
            'GET /v2/payments/*' => fn (RequestInterface $request): ResponseInterface => $this->createPaymentResponse(
                $request,
                $order,
                $paymentToken,
            ),
        ]);
        $this->_objectManager->addSharedInstance($fakeMollieApiClient, MollieApiClientService::class);
    }

    private function createPaymentResponse(
        RequestInterface $request,
        OrderInterface $order,
        string $paymentToken,
    ): ResponseInterface {
        $transactionId = basename($request->getUri()->getPath());

        return MockResponse::payment([
            'id' => $transactionId,
            'status' => $this->paymentStatuses[$transactionId],
            'method' => 'applepay',
            'amount' => [
                'value' => number_format((float)$order->getBaseGrandTotal(), 2, '.', ''),
                'currency' => 'EUR',
            ],
            'metadata' => [
                'order_id' => $order->getEntityId(),
                'store_id' => $order->getStoreId(),
                'payment_token' => $paymentToken,
            ],
        ]);
    }

    /**
     * @param array<string, string|string[]> $params
     */
    private function dispatchWebhook(array $params): void
    {
        $this->getRequest()->setMethod(Request::METHOD_POST);
        $this->getRequest()->setParams($params);

        $this->dispatch(self::WEBHOOK_PATH);
    }

    /**
     * @return array<string, string|string[]>
     */
    private function getWebhookParamsWithOrderIdFallback(string $transactionId, OrderInterface $order): array
    {
        $encryptor = $this->_objectManager->get(EncryptorInterface::class);

        return [
            'id' => $transactionId,
            'orderId' => [base64_encode($encryptor->encrypt((string)$order->getEntityId()))],
        ];
    }

    /**
     * @return string[]
     */
    private function getLinkedTransactionIds(OrderInterface $order): array
    {
        $searchCriteria = $this->_objectManager->create(SearchCriteriaBuilder::class)
            ->addFilter('order_id', $order->getEntityId())
            ->create();

        return array_map(
            fn (TransactionToOrderInterface $transaction): ?string => $transaction->getTransactionId(),
            array_values(
                $this->_objectManager->get(TransactionToOrderRepositoryInterface::class)
                    ->getList($searchCriteria)
                    ->getItems(),
            ),
        );
    }

    private function reloadOrder(OrderInterface $order): OrderInterface
    {
        return $this->_objectManager->create(OrderRepositoryInterface::class)->get($order->getEntityId());
    }
}
