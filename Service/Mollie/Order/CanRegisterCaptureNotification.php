<?php

/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Service\Mollie\Order;

use Magento\Sales\Api\Data\OrderInterface;
use Mollie\Api\Models\Components\PaymentResponse;
use Mollie\Payment\Service\Mollie\PaymentResponseHelper;

class CanRegisterCaptureNotification
{
    public function __construct(
        private readonly CanUseManualCapture $canUseManualCapture,
        private readonly IsSharedTransaction $isSharedTransaction,
    ) {
    }

    public function execute(OrderInterface $order, PaymentResponse $molliePayment): bool
    {
        if (!$this->canUseManualCapture->execute($order)) {
            return true;
        }

        // A shared transaction is captured per order, and each capture creates the invoice of its own order. The
        // captured amount of the payment does not say which order it belongs to, so it never invoices this one.
        if ($this->isSharedTransaction->execute($order)) {
            return false;
        }

        return PaymentResponseHelper::getAmountCaptured($molliePayment) !== 0.0;
    }
}
