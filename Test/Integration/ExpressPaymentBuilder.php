<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Test\Integration;

use Mollie\Api\Models\Components\Amount;
use Mollie\Api\Models\Components\MethodResponse;
use Mollie\Api\Models\Components\Mode;
use Mollie\Api\Models\Components\PaymentAddress;
use Mollie\Api\Models\Components\PaymentLineTypeResponse;
use Mollie\Api\Models\Components\PaymentResponse;
use Mollie\Api\Models\Components\PaymentResponseBillingAddress;
use Mollie\Api\Models\Components\PaymentResponseLine;
use Mollie\Api\Models\Components\PaymentResponseLinks;
use Mollie\Api\Models\Components\PaymentResponseStatus;
use Mollie\Api\Models\Components\SequenceTypeResponse;
use Mollie\Api\Models\Components\Url;
use Mollie\Payment\Service\Mollie\Express\ExpressPaymentResponseLine;

class ExpressPaymentBuilder
{
    public function build(string $transactionId, ?string $streetAdditional = null): PaymentResponse
    {
        return new PaymentResponse(
            resource: 'payment',
            id: $transactionId,
            mode: Mode::Test,
            description: 'Express Order',
            amount: new Amount(currency: 'EUR', value: '15.00'),
            sequenceType: SequenceTypeResponse::Oneoff,
            profileId: 'pfl_QkEhN94Ba',
            status: PaymentResponseStatus::Paid,
            createdAt: '2024-01-01T12:00:00+00:00',
            links: new PaymentResponseLinks(
                self: new Url(href: 'https://api.mollie.com/v2/payments/' . $transactionId, type: 'application/hal+json'),
                dashboard: new Url(href: 'https://www.mollie.com/dashboard/payments/' . $transactionId, type: 'text/html'),
            ),
            billingAddress: $this->billingAddress($streetAdditional),
            shippingAddress: $this->shippingAddress($streetAdditional),
            lines: [$this->productLine(), $this->shippingLine('5.00')],
            method: MethodResponse::Applepay,
        );
    }

    public function billingAddress(?string $streetAdditional = null): PaymentResponseBillingAddress
    {
        return new PaymentResponseBillingAddress(
            givenName: 'John',
            familyName: 'Doe',
            streetAndNumber: 'Keizersgracht 126',
            streetAdditional: $streetAdditional,
            postalCode: '1015 CW',
            email: 'aaa@aaa.com',
            phone: '0612345678',
            city: 'Amsterdam',
            country: 'NL',
        );
    }

    public function shippingAddress(?string $streetAdditional = null): PaymentAddress
    {
        return new PaymentAddress(
            givenName: 'John',
            familyName: 'Doe',
            streetAndNumber: 'Keizersgracht 126',
            streetAdditional: $streetAdditional,
            postalCode: '1015 CW',
            email: 'aaa@aaa.com',
            phone: '0612345678',
            city: 'Amsterdam',
            country: 'NL',
        );
    }

    public function address(?string $streetAdditional = null): PaymentAddress
    {
        return $this->shippingAddress($streetAdditional);
    }

    public function productLine(string $sku = 'simple', string $name = 'Simple Product'): PaymentResponseLine
    {
        return new PaymentResponseLine(
            description: '[' . $sku . '] ' . $name,
            quantity: 1,
            unitPrice: new Amount(currency: 'EUR', value: '10.00'),
            totalAmount: new Amount(currency: 'EUR', value: '10.00'),
            type: PaymentLineTypeResponse::Physical,
        );
    }

    public function shippingLine(string $value, ?string $reference = null): ExpressPaymentResponseLine
    {
        return new ExpressPaymentResponseLine(
            description: 'Standard delivery',
            quantity: 1,
            unitPrice: new Amount(currency: 'EUR', value: $value),
            totalAmount: new Amount(currency: 'EUR', value: $value),
            type: PaymentLineTypeResponse::ShippingFee,
            reference: $reference,
        );
    }

    public function shippingLineWithVat(string $value, string $vatRate, string $vatAmount): ExpressPaymentResponseLine
    {
        return new ExpressPaymentResponseLine(
            description: 'Standard delivery',
            quantity: 1,
            unitPrice: new Amount(currency: 'EUR', value: $value),
            totalAmount: new Amount(currency: 'EUR', value: $value),
            type: PaymentLineTypeResponse::ShippingFee,
            vatRate: $vatRate,
            vatAmount: new Amount(currency: 'EUR', value: $vatAmount),
        );
    }
}
