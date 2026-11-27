<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Test\Integration\Model\Client\Payments\Processors;

use Magento\Sales\Api\Data\CreditmemoCommentInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Mollie\Api\Models\Components\PaymentResponse;
use Mollie\Payment\Model\Client\Payments\Processors\HandleExternalRefunds;
use Mollie\Payment\Model\Client\ProcessTransactionResponse;
use Mollie\Payment\Test\Fakes\Service\Mollie\MockResponse;
use Mollie\Payment\Test\Integration\IntegrationTestCase;
use Mollie\Payment\Test\Integration\MolliePaymentBuilder;

class HandleExternalRefundsTest extends IntegrationTestCase
{
    /**
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testCreatesACreditmemoForAnExternalRefundByDefault(): void
    {
        $order = $this->loadPreparedOrder();
        $this->fakeRefunds([
            $this->refundData('re_external_123', 10),
        ]);

        $this->processRefunds($order, $this->buildPayment());

        $freshOrder = $this->loadOrder($order->getIncrementId());
        $this->assertSame(1, $freshOrder->getCreditmemosCollection()->getSize());
        $this->assertSame([], array_filter(
            $this->getCommentsMentioning($freshOrder, 're_external_123'),
            fn (OrderStatusHistoryInterface $history): bool => str_contains(
                (string) $history->getComment(),
                'No credit memo was created',
            ),
        ));
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testCreatesACreditmemoForEachExternalRefundInTheSameCall(): void
    {
        $order = $this->loadPreparedOrder();
        $this->fakeRefunds([
            $this->refundData('re_external_123', 10),
            $this->refundData('re_external_456', 5),
        ]);

        $this->processRefunds($order, $this->buildPayment());

        $this->assertCreditmemoBaseGrandTotals($order, [5.0, 10.0]);
        foreach ($this->loadOrder($order->getIncrementId())->getCreditmemosCollection() as $creditmemo) {
            $comments = array_map(
                fn (CreditmemoCommentInterface $comment): string => (string) $comment->getComment(),
                array_values($creditmemo->getCommentsCollection()->getItems()),
            );
            $this->assertContains(
                'This refund was created outside Magento, possible through the Mollie Dashboard.',
                $comments,
            );
        }
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testDoesNotCreateTheCreditmemosAgainWhenThePaymentIsProcessedAgain(): void
    {
        $order = $this->loadPreparedOrder();
        $this->fakeRefunds([
            $this->refundData('re_external_123', 10),
            $this->refundData('re_external_456', 5),
        ]);
        $payment = $this->buildPayment();

        $this->processRefunds($order, $payment);
        $this->processRefunds($this->loadOrder($order->getIncrementId()), $payment);

        $this->assertCreditmemoBaseGrandTotals($order, [5.0, 10.0]);
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     * @magentoConfigFixture current_store payment/mollie_general/create_creditmemo_for_external_refunds 0
     */
    public function testAddsAnOrderCommentInsteadOfACreditmemoWhenDisabled(): void
    {
        $order = $this->loadPreparedOrder();
        $this->fakeRefunds([
            $this->refundData('re_external_123', 10),
        ]);

        $this->processRefunds($order, $this->buildPayment());

        $freshOrder = $this->loadOrder($order->getIncrementId());
        $this->assertSame(0, $freshOrder->getCreditmemosCollection()->getSize());
        $this->assertCount(1, $this->getCommentsMentioning($freshOrder, 're_external_123'));
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     * @magentoConfigFixture current_store payment/mollie_general/create_creditmemo_for_external_refunds 0
     */
    public function testAddsTheCommentOnlyOnceWhenThePaymentIsProcessedAgain(): void
    {
        $order = $this->loadPreparedOrder();
        $this->fakeRefunds([
            $this->refundData('re_external_123', 10),
        ]);
        $payment = $this->buildPayment();

        $this->processRefunds($order, $payment);
        $this->processRefunds($order, $payment);

        $freshOrder = $this->loadOrder($order->getIncrementId());
        $this->assertCount(1, $this->getCommentsMentioning($freshOrder, 're_external_123'));
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     * @magentoConfigFixture current_store payment/mollie_general/create_creditmemo_for_external_refunds 0
     */
    public function testAddsACommentForEachNewRefund(): void
    {
        $order = $this->loadPreparedOrder();
        $fake = $this->loadFakeMollieApiClient();
        $fake->fake([
            'GET /v2/payments/*/refunds' => [
                MockResponse::refundList([
                    $this->refundData('re_external_123', 10),
                ]),
                MockResponse::refundList([
                    $this->refundData('re_external_123', 10),
                    $this->refundData('re_external_456', 5),
                ]),
            ],
        ]);
        $payment = $this->buildPayment();

        $this->processRefunds($order, $payment);
        $this->processRefunds($order, $payment);

        $freshOrder = $this->loadOrder($order->getIncrementId());
        $this->assertCount(1, $this->getCommentsMentioning($freshOrder, 're_external_123'));
        $this->assertCount(1, $this->getCommentsMentioning($freshOrder, 're_external_456'));
    }

    /**
     * @param array<int, array<string, mixed>> $refunds
     */
    private function fakeRefunds(array $refunds): void
    {
        $fake = $this->loadFakeMollieApiClient();
        $fake->fake([
            'GET /v2/payments/*/refunds' => MockResponse::refundList($refunds),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function refundData(string $id, float $amount): array
    {
        return [
            'id' => $id,
            'paymentId' => 'tr_external_refunds',
            'amount' => [
                'currency' => 'EUR',
                'value' => number_format($amount, 2, '.', ''),
            ],
        ];
    }

    private function buildPayment(): PaymentResponse
    {
        /** @var MolliePaymentBuilder $builder */
        $builder = $this->objectManager->create(MolliePaymentBuilder::class);
        $builder->setId('tr_external_refunds');
        $builder->setStatus('paid');

        return $builder->build();
    }

    private function loadPreparedOrder(): OrderInterface
    {
        $order = $this->loadOrder('100000001');
        $order->setBaseCurrencyCode('EUR');
        $order->setOrderCurrencyCode('EUR');
        $order->setMollieTransactionId('tr_external_refunds');

        $this->objectManager->get(OrderRepositoryInterface::class)->save($order);

        return $order;
    }

    private function processRefunds(OrderInterface $order, PaymentResponse $payment): void
    {
        $this->objectManager->create(HandleExternalRefunds::class)->process(
            $order,
            $payment,
            'webhook',
            $this->objectManager->create(ProcessTransactionResponse::class, [
                'success' => true,
                'status' => 'paid',
                'order_id' => $order->getIncrementId(),
                'type' => 'webhook',
            ]),
        );
    }

    /**
     * @param float[] $expected
     */
    private function assertCreditmemoBaseGrandTotals(OrderInterface $order, array $expected): void
    {
        $freshOrder = $this->loadOrder($order->getIncrementId());
        $grandTotals = array_map(
            'floatval',
            $freshOrder->getCreditmemosCollection()->getColumnValues('base_grand_total'),
        );
        sort($grandTotals);

        $this->assertSame($expected, $grandTotals);
    }

    /**
     * @return OrderStatusHistoryInterface[]
     */
    private function getCommentsMentioning(OrderInterface $order, string $refundId): array
    {
        return array_values(array_filter(
            $order->getStatusHistories() ?? [],
            fn (OrderStatusHistoryInterface $history): bool => str_contains((string) $history->getComment(), $refundId),
        ));
    }
}
