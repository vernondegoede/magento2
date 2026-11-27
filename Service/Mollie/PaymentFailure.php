<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Service\Mollie;

use Mollie\Api\Models\Components\PaymentDetails;
use stdClass;

class PaymentFailure
{
    public function __construct(
        private readonly ?PaymentFailureReason $reason = null,
        private readonly ?string $message = null,
    ) {}

    public static function fromPaymentDetails(PaymentDetails|stdClass|null $details): self
    {
        if ($details instanceof PaymentDetails) {
            $reasonValue = $details->failureReason?->value ?? '';
            $message = $details->failureMessage ?? '';
        } else {
            $reasonValue = is_string($details->failureReason ?? null) ? $details->failureReason : '';
            $message = is_string($details->failureMessage ?? null) ? $details->failureMessage : '';
        }

        return new self(
            PaymentFailureReason::tryFrom($reasonValue),
            $message === '' ? null : $message,
        );
    }

    public function getReason(): ?PaymentFailureReason
    {
        return $this->reason;
    }

    /**
     * The message is provided by Mollie, translated in the locale of the payment, and safe to show to the customer.
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function isPossibleFraud(): bool
    {
        return $this->reason === PaymentFailureReason::PossibleFraud;
    }
}
