<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Test\Integration;

use Mollie\Api\Models\Components\Amount;
use Mollie\Api\Models\Components\CaptureModeResponse;
use Mollie\Api\Models\Components\MethodResponse;
use Mollie\Api\Models\Components\Mode;
use Mollie\Api\Models\Components\PaymentDetails;
use Mollie\Api\Models\Components\PaymentResponse;
use Mollie\Api\Models\Components\PaymentResponseAmountCaptured;
use Mollie\Api\Models\Components\PaymentResponseAmountChargedBack;
use Mollie\Api\Models\Components\PaymentResponseAmountRefunded;
use Mollie\Api\Models\Components\PaymentResponseLinks;
use Mollie\Api\Models\Components\PaymentResponseStatus;
use Mollie\Api\Models\Components\SequenceTypeResponse;
use Mollie\Api\Models\Components\Url;

class MolliePaymentBuilder
{
    private PaymentResponse $payment;

    public function __construct()
    {
        $this->payment = new PaymentResponse(
            resource: 'payment',
            id: 'tr_WDqYK6vllg',
            mode: Mode::Test,
            description: 'Test payment',
            amount: new Amount(currency: 'EUR', value: '10.00'),
            sequenceType: SequenceTypeResponse::Oneoff,
            profileId: 'pfl_QkEhN94Ba',
            status: PaymentResponseStatus::Open,
            createdAt: '2024-01-01T12:00:00+00:00',
            links: new PaymentResponseLinks(
                self: new Url(href: 'https://api.mollie.com/v2/payments/tr_WDqYK6vllg', type: 'application/hal+json'),
                dashboard: new Url(href: 'https://www.mollie.com/dashboard/payments/tr_WDqYK6vllg', type: 'text/html'),
            ),
        );
    }

    public function setId(string $id): self
    {
        $this->payment->id = $id;

        return $this;
    }

    public function setAmount(float $value, string $currency = 'EUR'): self
    {
        $this->payment->amount = new Amount(
            currency: $currency,
            value: number_format($value, 2, '.', ''),
        );

        return $this;
    }

    public function setStatus(string $status): self
    {
        $this->payment->status = PaymentResponseStatus::from($status);

        return $this;
    }

    public function setMethod(?string $method): self
    {
        $this->payment->method = $method !== null ? MethodResponse::from($method) : null;

        return $this;
    }

    public function setCaptureMode(?string $captureMode): self
    {
        $this->payment->captureMode = $captureMode !== null ? CaptureModeResponse::tryFrom($captureMode) : null;

        return $this;
    }

    public function setAmountCaptured(float $value, string $currency = 'EUR'): self
    {
        $this->payment->amountCaptured = new PaymentResponseAmountCaptured(
            currency: $currency,
            value: number_format($value, 2, '.', ''),
        );

        return $this;
    }

    public function setAmountChargedBack(float $value, string $currency = 'EUR'): self
    {
        $this->payment->amountChargedBack = new PaymentResponseAmountChargedBack(
            currency: $currency,
            value: number_format($value, 2, '.', ''),
        );

        return $this;
    }

    public function setAmountRefunded(float $value, string $currency = 'EUR'): self
    {
        $this->payment->amountRefunded = new PaymentResponseAmountRefunded(
            currency: $currency,
            value: number_format($value, 2, '.', ''),
        );

        return $this;
    }

    public function setDetails(?PaymentDetails $details): self
    {
        $this->payment->details = $details;

        return $this;
    }

    public function setCheckoutUrl(string $url): self
    {
        $this->payment->links->checkout = new Url(href: $url, type: 'text/html');

        return $this;
    }

    public function build(): PaymentResponse
    {
        return $this->payment;
    }
}
