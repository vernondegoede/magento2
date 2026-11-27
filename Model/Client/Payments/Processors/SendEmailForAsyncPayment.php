<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Model\Client\Payments\Processors;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Mollie\Api\Models\Components\PaymentResponse;
use Mollie\Payment\Helper\General;
use Mollie\Payment\Model\Client\PaymentProcessorInterface;
use Mollie\Payment\Model\Client\ProcessTransactionResponse;
use Mollie\Payment\Model\Client\ProcessTransactionResponseFactory;
use Mollie\Payment\Service\Mollie\AsyncPaymentMethods;
use Mollie\Payment\Service\Order\SendOrderEmails;
use Mollie\Payment\Service\Order\TransactionProcessor;

class SendEmailForAsyncPayment implements PaymentProcessorInterface
{
    public function __construct(
        private General $mollieHelper,
        private AsyncPaymentMethods $asyncPaymentMethods,
        private ProcessTransactionResponseFactory $processTransactionResponseFactory,
        private TransactionProcessor $transactionProcessor,
        private SendOrderEmails $sendOrderEmails
    ) {}

    public function process(
        OrderInterface $magentoOrder,
        PaymentResponse $molliePayment,
        string $type,
        ProcessTransactionResponse $response,
    ): ?ProcessTransactionResponse {
        $method = $molliePayment->method?->value;
        if ($method === null || !$this->asyncPaymentMethods->contains($method) || $magentoOrder->getEmailSent()) {
            return $response;
        }

        $statusPending = $this->mollieHelper->getPendingPaymentStatus($magentoOrder) ?: $magentoOrder->getStatus();

        $magentoOrder->setStatus($statusPending);
        $magentoOrder->setState(Order::STATE_PENDING_PAYMENT);
        $this->sendOrderEmails->sendOrderConfirmation($magentoOrder);

        $this->transactionProcessor->process($magentoOrder, $molliePayment);

        return $this->processTransactionResponseFactory->create([
            'success' => true,
            'status' => $molliePayment->status->value,
            'order_id' => $magentoOrder->getId(),
            'type' => $type,
        ]);
    }
}
