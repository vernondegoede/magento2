<?php

/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Test\Integration\Model\Client\Payments;

use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Invoice;
use Mollie\Payment\Exceptions\PartialCaptureNotSupported;
use Mollie\Payment\Model\Client\Payments\CapturePaymentForInvoice;
use Mollie\Payment\Test\Fakes\Service\Mollie\FakeMollieApiClient;
use Mollie\Payment\Test\Fakes\Service\Mollie\MockResponse;
use Mollie\Payment\Test\Integration\IntegrationTestCase;
use Psr\Http\Message\RequestInterface;

class CapturePaymentForInvoiceTest extends IntegrationTestCase
{
    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testRefusesAPartialInvoiceWhenTheMethodDoesNotSupportPartialCaptures(): void
    {
        $this->fakeCaptureApi();

        $instance = $this->objectManager->create(CapturePaymentForInvoice::class);

        $this->expectException(PartialCaptureNotSupported::class);
        $instance->execute($this->prepareInvoice('mollie_methods_riverty', 44.0));
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testCallsNoApiForARefusedPartialInvoice(): void
    {
        $fake = $this->fakeCaptureApi();

        $instance = $this->objectManager->create(CapturePaymentForInvoice::class);

        try {
            $instance->execute($this->prepareInvoice('mollie_methods_riverty', 44.0));
            $this->fail('Expected the partial invoice to be refused');
        } catch (PartialCaptureNotSupported $exception) {
            $fake->assertSentCount(0);
        }
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testCapturesAnInvoiceThatCoversTheCompleteOrder(): void
    {
        $fake = $this->fakeCaptureApi();

        $instance = $this->objectManager->create(CapturePaymentForInvoice::class);
        $instance->execute($this->prepareInvoice('mollie_methods_riverty', 100.0));

        $fake->assertSent('POST /v2/payments/*/captures');
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testCapturesAPartialInvoiceWhenTheMethodSupportsPartialCaptures(): void
    {
        $fake = $this->fakeCaptureApi();

        $instance = $this->objectManager->create(CapturePaymentForInvoice::class);
        $instance->execute($this->prepareInvoice('mollie_methods_creditcard', 44.0));

        $fake->assertSent('POST /v2/payments/*/captures');
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testCapturesTheWholePaymentForAnInvoiceThatCoversItsOnlyOrder(): void
    {
        $fake = $this->fakeCaptureApi();

        $instance = $this->objectManager->create(CapturePaymentForInvoice::class);
        $instance->execute($this->prepareInvoice('mollie_methods_creditcard', 100.0));

        $this->assertNull($this->getSentCapturePayload($fake)['amount'] ?? null);
    }

    /**
     * @magentoConfigFixture current_store payment/mollie_general/currency 1
     * @magentoDataFixture Magento/Sales/_files/order_list.php
     */
    public function testCapturesOnlyTheInvoiceAmountWhenOtherOrdersShareTheTransaction(): void
    {
        $fake = $this->fakeCaptureApi();
        $order = $this->saveWithTransaction('100000002', 'tr_dummytransaction');
        $this->saveWithTransaction('100000004', 'tr_dummytransaction');

        $instance = $this->objectManager->create(CapturePaymentForInvoice::class);
        $instance->execute($this->createInvoice($order, (float) $order->getBaseGrandTotal(), (float) $order->getGrandTotal()));

        $this->assertSame(['currency' => 'EUR', 'value' => '120.00'], $this->getSentCapturePayload($fake)['amount']);
    }

    /**
     * @magentoConfigFixture current_store payment/mollie_general/currency 0
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testCapturesAPartialInvoiceInTheOrderCurrencyWhenTheBaseCurrencyIsNotUsed(): void
    {
        $fake = $this->fakeCaptureApi();
        $order = $this->loadOrderById('100000001');
        $order->getPayment()->setMethod('mollie_methods_creditcard');
        $order->setMollieTransactionId('tr_dummytransaction');
        $order->setBaseGrandTotal(100.0);
        $order->setGrandTotal(110.0);
        $order->setOrderCurrencyCode('USD');

        $instance = $this->objectManager->create(CapturePaymentForInvoice::class);
        $instance->execute($this->createInvoice($order, 40.0, 44.0));

        $this->assertSame(['currency' => 'USD', 'value' => '44.00'], $this->getSentCapturePayload($fake)['amount']);
    }

    /**
     * @return array<string, mixed>
     */
    private function getSentCapturePayload(FakeMollieApiClient $fake): array
    {
        $capturedPayload = [];
        $fake->assertSent(
            'POST /v2/payments/*/captures',
            static function (RequestInterface $request, array $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;

                return true;
            },
        );

        return $capturedPayload;
    }

    private function saveWithTransaction(string $incrementId, string $transactionId): OrderInterface
    {
        $order = $this->loadOrderById($incrementId);
        $order->getPayment()->setMethod('mollie_methods_creditcard');
        $order->setMollieTransactionId($transactionId);

        return $this->objectManager->get(OrderRepositoryInterface::class)->save($order);
    }

    private function createInvoice(OrderInterface $order, float $baseGrandTotal, float $grandTotal): InvoiceInterface
    {
        /** @var Invoice $invoice */
        $invoice = $this->objectManager->create(Invoice::class);
        $invoice->setOrder($order);
        $invoice->setBaseGrandTotal($baseGrandTotal);
        $invoice->setGrandTotal($grandTotal);

        return $invoice;
    }

    private function fakeCaptureApi(): FakeMollieApiClient
    {
        $fake = $this->loadFakeMollieApiClient();
        $fake->fake([
            'POST /v2/payments/*/captures' => MockResponse::capture([
                'id' => 'cpt_dummycapture',
                'paymentId' => 'tr_dummytransaction',
                'status' => 'pending',
            ]),
        ]);

        return $fake;
    }

    private function prepareInvoice(string $method, float $invoiceGrandTotal): InvoiceInterface
    {
        $order = $this->loadOrderById('100000001');
        $order->getPayment()->setMethod($method);
        $order->setMollieTransactionId('tr_dummytransaction');
        $order->setBaseGrandTotal(100.0);

        /** @var Invoice $invoice */
        $invoice = $this->objectManager->create(Invoice::class);
        $invoice->setOrder($order);
        $invoice->setBaseGrandTotal($invoiceGrandTotal);

        return $invoice;
    }
}
