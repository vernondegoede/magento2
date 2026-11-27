<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Model\Client\Payments\Processors;

use Magento\Sales\Api\Data\OrderInterface;
use Mollie\Api\Models\Components\PaymentResponse;
use Mollie\Payment\Model\Adminhtml\Source\CaptureMode;
use Mollie\Payment\Model\Client\PaymentProcessorInterface;
use Mollie\Payment\Model\Client\ProcessTransactionResponse;
use Mollie\Payment\Service\Mollie\Order\CanUseManualCapture;

/**
 * Stores the capture mode of the Mollie payment on the order. Mollie knows how the payment was actually created, which
 * also covers orders placed before the capture mode was stored, and orders placed before the configuration changed.
 */
class SaveCaptureMode implements PaymentProcessorInterface
{
    private const KNOWN_CAPTURE_MODES = [
        CaptureMode::AUTOMATIC,
        CaptureMode::MANUAL,
    ];

    public function process(
        OrderInterface $magentoOrder,
        PaymentResponse $molliePayment,
        string $type,
        ProcessTransactionResponse $response,
    ): ?ProcessTransactionResponse {
        $captureMode = $molliePayment->captureMode?->value;
        if (!in_array($captureMode, self::KNOWN_CAPTURE_MODES, true)) {
            return $response;
        }

        $magentoOrder->getPayment()->setAdditionalInformation(
            CanUseManualCapture::CAPTURE_MODE,
            $captureMode,
        );

        return $response;
    }
}
