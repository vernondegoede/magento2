<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Service\Mollie;

use Mollie\Api\Models\Components\PaymentResponse;
use Mollie\Api\Models\Components\PaymentResponseStatus;

class PaymentResponseHelper
{
    public static function isAuthorized(PaymentResponse $payment): bool
    {
        return $payment->status === PaymentResponseStatus::Authorized;
    }

    public static function isPaid(PaymentResponse $payment): bool
    {
        return $payment->status === PaymentResponseStatus::Paid;
    }

    public static function getCheckoutUrl(PaymentResponse $payment): ?string
    {
        return $payment->links->checkout?->href;
    }

    public static function hasRefunds(PaymentResponse $payment): bool
    {
        if ($payment->links->refunds !== null) {
            return true;
        }

        return $payment->amountRefunded !== null && (float) $payment->amountRefunded->value > 0.0;
    }

    public static function hasChargebacks(PaymentResponse $payment): bool
    {
        if ($payment->links->chargebacks !== null) {
            return true;
        }

        return $payment->amountChargedBack !== null && (float) $payment->amountChargedBack->value > 0.0;
    }

    public static function getAmountChargedBack(PaymentResponse $payment): float
    {
        return (float) ($payment->amountChargedBack->value ?? 0.0);
    }

    public static function getAmountCaptured(PaymentResponse $payment): float
    {
        return (float) ($payment->amountCaptured->value ?? 0.0);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getMetadataArray(PaymentResponse $payment): array
    {
        if (is_array($payment->metadata)) {
            return $payment->metadata;
        }

        return [];
    }
}
