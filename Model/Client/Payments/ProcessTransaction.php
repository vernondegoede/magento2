<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Model\Client\Payments;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Mollie\Api\Models\Components\PaymentResponseStatus;
use Mollie\Api\Models\Operations\GetPaymentRequest;
use Mollie\Payment\Helper\General as MollieHelper;
use Mollie\Payment\Model\Client\ProcessTransactionResponse;
use Mollie\Payment\Model\Client\ProcessTransactionResponseFactory;
use Mollie\Payment\Service\Mollie\MollieApiClient;
use Mollie\Payment\Service\Mollie\Order\GetTransactionId;
use Mollie\Payment\Service\Mollie\Order\ResolvePaymentId;
use Mollie\Payment\Service\Mollie\PaymentResponseHelper;

class ProcessTransaction
{
    public function __construct(
        private ProcessTransactionResponseFactory $processTransactionResponseFactory,
        private PaymentProcessors $paymentProcessors,
        private MollieApiClient $mollieApiClient,
        private MollieHelper $mollieHelper,
        private GetTransactionId $getTransactionId,
        private ResolvePaymentId $resolvePaymentId
    ) {}

    public function execute(
        OrderInterface $magentoOrder,
        string $type = 'webhook',
    ): ProcessTransactionResponse {
        $mollieApi = $this->mollieApiClient->loadByStore((int) $magentoOrder->getStoreId());
        $transactionId = $this->getTransactionId->forOrder($magentoOrder);
        $paymentId = $this->resolvePaymentId->execute($mollieApi, $transactionId);
        $molliePayment = $mollieApi->payments->get(new GetPaymentRequest(paymentId: $paymentId))->paymentResponse;
        $this->mollieHelper->addTolog($type, $molliePayment);
        $status = $molliePayment->status->value;

        $response = $this->processTransactionResponseFactory->create([
            'success' => true,
            'status' => $status,
            'order_id' => $magentoOrder->getEntityId(),
            'type' => $type,
        ]);

        $this->paymentProcessors->process(
            'preprocess',
            $magentoOrder,
            $molliePayment,
            $type,
            $response,
        );

        $isPaid = in_array(
            $molliePayment->status,
            [PaymentResponseStatus::Paid, PaymentResponseStatus::Authorized],
            true,
        );

        if ($isPaid) {
            $response = $this->paymentProcessors->process(
                'paid',
                $magentoOrder,
                $molliePayment,
                $type,
                $response,
            );
        }

        if (PaymentResponseHelper::hasRefunds($molliePayment)) {
            return $this->paymentProcessors->process(
                'refunded',
                $magentoOrder,
                $molliePayment,
                $type,
                $response,
            );
        }

        if ($isPaid) {
            return $response;
        }

        if ($molliePayment->status === PaymentResponseStatus::Open) {
            return $this->paymentProcessors->process(
                'open',
                $magentoOrder,
                $molliePayment,
                $type,
                $response,
            );
        }

        if ($molliePayment->status === PaymentResponseStatus::Pending) {
            return $this->paymentProcessors->process(
                'pending',
                $magentoOrder,
                $molliePayment,
                $type,
                $response,
            );
        }

        if ($molliePayment->status === PaymentResponseStatus::Expired) {
            return $this->paymentProcessors->process(
                'expired',
                $magentoOrder,
                $molliePayment,
                $type,
                $response,
            );
        }

        if (in_array(
            $molliePayment->status,
            [PaymentResponseStatus::Canceled, PaymentResponseStatus::Failed, PaymentResponseStatus::Expired],
            true,
        )) {
            return $this->paymentProcessors->process(
                'failed',
                $magentoOrder,
                $molliePayment,
                $type,
                $response,
            );
        }

        throw new LocalizedException(__('Unknown status'));
    }
}
