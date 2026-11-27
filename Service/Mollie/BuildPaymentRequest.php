<?php

/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Service\Mollie;

use Brick\DateTime\DateTimeException;
use Brick\DateTime\LocalDate;
use DateTimeImmutable;
use Mollie\Api\Models\Components\Amount;
use Mollie\Api\Models\Components\CaptureMode;
use Mollie\Api\Models\Components\LineCategories;
use Mollie\Api\Models\Components\Locale;
use Mollie\Api\Models\Components\MethodEnum;
use Mollie\Api\Models\Components\PaymentAddress;
use Mollie\Api\Models\Components\PaymentLineType;
use Mollie\Api\Models\Components\PaymentRequest;
use Mollie\Api\Models\Components\PaymentRequestBillingAddress;
use Mollie\Api\Models\Components\PaymentRequestLine;
use Mollie\Api\Models\Components\SequenceType;

class BuildPaymentRequest
{
    /**
     * @param array<string, mixed> $request
     */
    public function execute(array $request): PaymentRequest
    {
        $additional = is_array($request['additional'] ?? null) ? $request['additional'] : [];

        return new PaymentRequest(
            description: (string) ($request['description'] ?? ''),
            amount: $this->convertToAmount($request['amount']),
            redirectUrl: $this->stringOrNull($request['redirectUrl'] ?? null),
            billingAddress: $this->convertToBillingAddress($request['billingAddress'] ?? null),
            shippingAddress: $this->convertToShippingAddress($request['shippingAddress'] ?? null),
            sequenceType: $this->convertSequenceType($request['sequenceType'] ?? null),
            customerId: $this->stringOrNull($request['customerId'] ?? null),
            profileId: $this->stringOrNull($request['profileId'] ?? null),
            storeCredentials: $this->boolOrNull($additional['storeCredentials'] ?? $request['storeCredentials'] ?? null),
            applePayPaymentToken: $this->stringOrNull($additional['applePayPaymentToken'] ?? $request['applePayPaymentToken'] ?? null),
            cardToken: $this->stringOrNull($additional['cardToken'] ?? $request['cardToken'] ?? null),
            googlePayPaymentToken: $this->stringOrNull($additional['googlePayPaymentToken'] ?? $request['googlePayPaymentToken'] ?? null),
            voucherNumber: $this->stringOrNull($additional['voucherNumber'] ?? $request['voucherNumber'] ?? null),
            voucherPin: $this->stringOrNull($additional['voucherPin'] ?? $request['voucherPin'] ?? null),
            consumerDateOfBirth: $this->convertLocalDate($request['consumerDateOfBirth'] ?? null),
            extraMerchantData: is_array($additional['extraMerchantData'] ?? $request['extraMerchantData'] ?? null)
                ? ($additional['extraMerchantData'] ?? $request['extraMerchantData'])
                : null,
            sessionId: $this->stringOrNull($additional['sessionId'] ?? $request['sessionId'] ?? null),
            digitalGoods: $this->boolOrNull($additional['digitalGoods'] ?? $request['digitalGoods'] ?? null),
            customerReference: $this->stringOrNull($additional['customerReference'] ?? $request['customerReference'] ?? null),
            terminalId: $this->stringOrNull($additional['terminalId'] ?? $request['terminalId'] ?? null),
            cancelUrl: $this->stringOrNull($request['cancelUrl'] ?? null),
            webhookUrl: $this->stringOrNull($request['webhookUrl'] ?? null),
            lines: $this->convertLines($request['lines'] ?? null),
            locale: $this->convertLocale($request['locale'] ?? null),
            method: $this->convertMethod($request['method'] ?? null),
            issuer: $this->stringOrNull($request['issuer'] ?? null),
            restrictPaymentMethodsToCountry: $this->stringOrNull($request['restrictPaymentMethodsToCountry'] ?? null),
            metadata: $request['metadata'] ?? null,
            captureMode: $this->convertCaptureMode($request['captureMode'] ?? null),
            captureDelay: $this->stringOrNull($request['captureDelay'] ?? null),
            mandateId: $this->stringOrNull($request['mandateId'] ?? null),
            dueDate: $this->stringOrNull($request['dueDate'] ?? null),
            testmode: $this->boolOrNull($request['testmode'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $amount
     */
    private function convertToAmount(array $amount): Amount
    {
        return new Amount(
            currency: (string) $amount['currency'],
            value: (string) $amount['value'],
        );
    }

    /**
     * @param mixed $address
     */
    private function convertToBillingAddress(mixed $address): ?PaymentRequestBillingAddress
    {
        if (!is_array($address) || $address === []) {
            return null;
        }

        return new PaymentRequestBillingAddress(
            title: $this->stringOrNull($address['title'] ?? null),
            givenName: $this->stringOrNull($address['givenName'] ?? null),
            familyName: $this->stringOrNull($address['familyName'] ?? null),
            organizationName: $this->stringOrNull($address['organizationName'] ?? null),
            streetAndNumber: $this->stringOrNull($address['streetAndNumber'] ?? null),
            streetAdditional: $this->stringOrNull($address['streetAdditional'] ?? null),
            postalCode: $this->stringOrNull($address['postalCode'] ?? null),
            email: $this->stringOrNull($address['email'] ?? null),
            phone: $this->stringOrNull($address['phone'] ?? null),
            city: $this->stringOrNull($address['city'] ?? null),
            region: $this->stringOrNull($address['region'] ?? null),
            country: $this->stringOrNull($address['country'] ?? null),
        );
    }

    /**
     * @param mixed $address
     */
    private function convertToShippingAddress(mixed $address): ?PaymentAddress
    {
        if (!is_array($address) || $address === []) {
            return null;
        }

        return new PaymentAddress(
            title: $this->stringOrNull($address['title'] ?? null),
            givenName: $this->stringOrNull($address['givenName'] ?? null),
            familyName: $this->stringOrNull($address['familyName'] ?? null),
            organizationName: $this->stringOrNull($address['organizationName'] ?? null),
            streetAndNumber: $this->stringOrNull($address['streetAndNumber'] ?? null),
            streetAdditional: $this->stringOrNull($address['streetAdditional'] ?? null),
            postalCode: $this->stringOrNull($address['postalCode'] ?? null),
            email: $this->stringOrNull($address['email'] ?? null),
            phone: $this->stringOrNull($address['phone'] ?? null),
            city: $this->stringOrNull($address['city'] ?? null),
            region: $this->stringOrNull($address['region'] ?? null),
            country: $this->stringOrNull($address['country'] ?? null),
        );
    }

    /**
     * @param mixed $lines
     * @return ?array<PaymentRequestLine>
     */
    private function convertLines(mixed $lines): ?array
    {
        if (!is_array($lines)) {
            return null;
        }

        $result = [];
        foreach ($lines as $line) {
            if ($line instanceof PaymentRequestLine) {
                $result[] = $line;
                continue;
            }

            if (!is_array($line)) {
                continue;
            }

            $categories = null;
            if (is_array($line['categories'] ?? null)) {
                $mappedCategories = [];
                foreach ($line['categories'] as $category) {
                    if ($category instanceof LineCategories) {
                        $mappedCategories[] = $category;
                    } elseif (is_string($category)) {
                        $enum = LineCategories::tryFrom($category);
                        if ($enum !== null) {
                            $mappedCategories[] = $enum;
                        }
                    }
                }
                if ($mappedCategories !== []) {
                    $categories = $mappedCategories;
                }
            }

            $type = $line['type'] ?? null;
            if (is_string($type)) {
                $type = PaymentLineType::tryFrom($type);
            } elseif (!$type instanceof PaymentLineType) {
                $type = null;
            }

            $result[] = new PaymentRequestLine(
                description: (string) ($line['description'] ?? ''),
                quantity: (int) ($line['quantity'] ?? 1),
                unitPrice: $this->convertToAmount($line['unitPrice']),
                totalAmount: $this->convertToAmount($line['totalAmount']),
                type: $type,
                quantityUnit: $this->stringOrNull($line['quantityUnit'] ?? null),
                discountAmount: is_array($line['discountAmount'] ?? null)
                    ? $this->convertToAmount($line['discountAmount'])
                    : null,
                vatRate: isset($line['vatRate']) && is_scalar($line['vatRate'])
                    ? (is_string($line['vatRate']) ? $line['vatRate'] : sprintf('%.2f', (float) $line['vatRate']))
                    : null,
                vatAmount: is_array($line['vatAmount'] ?? null)
                    ? $this->convertToAmount($line['vatAmount'])
                    : null,
                sku: $this->stringOrNull($line['sku'] ?? null),
                categories: $categories,
                imageUrl: $this->nonEmptyStringOrNull($line['imageUrl'] ?? null),
                productUrl: $this->nonEmptyStringOrNull($line['productUrl'] ?? null),
            );
        }

        return $result;
    }

    /**
     * @param mixed $method
     * @return MethodEnum|array<?MethodEnum>|null
     */
    private function convertMethod(mixed $method): MethodEnum|array|null
    {
        if ($method instanceof MethodEnum) {
            return $method;
        }

        if (is_string($method)) {
            return $method === '' ? null : MethodEnum::tryFrom($method);
        }

        if (is_array($method)) {
            $methods = [];
            foreach ($method as $item) {
                if ($item instanceof MethodEnum) {
                    $methods[] = $item;
                } elseif (is_string($item) && $item !== '') {
                    $enum = MethodEnum::tryFrom($item);
                    if ($enum !== null) {
                        $methods[] = $enum;
                    }
                }
            }

            return $methods === [] ? null : $methods;
        }

        return null;
    }

    private function convertLocale(mixed $locale): ?Locale
    {
        if ($locale instanceof Locale) {
            return $locale;
        }

        if (is_string($locale) && $locale !== '') {
            return Locale::tryFrom($locale);
        }

        return null;
    }

    private function convertCaptureMode(mixed $captureMode): ?CaptureMode
    {
        if ($captureMode instanceof CaptureMode) {
            return $captureMode;
        }

        if (is_string($captureMode) && $captureMode !== '') {
            return CaptureMode::tryFrom($captureMode);
        }

        return null;
    }

    private function convertSequenceType(mixed $sequenceType): ?SequenceType
    {
        if ($sequenceType instanceof SequenceType) {
            return $sequenceType;
        }

        if (is_string($sequenceType) && $sequenceType !== '') {
            return SequenceType::tryFrom($sequenceType);
        }

        return null;
    }

    private function convertLocalDate(mixed $date): ?LocalDate
    {
        if ($date instanceof LocalDate) {
            return $date;
        }

        if (!is_string($date) || $date === '') {
            return null;
        }

        try {
            return LocalDate::parse($date);
        } catch (DateTimeException) {
            try {
                $parsed = new DateTimeImmutable($date);

                return LocalDate::of(
                    (int) $parsed->format('Y'),
                    (int) $parsed->format('n'),
                    (int) $parsed->format('j'),
                );
            } catch (\Throwable) {
                return null;
            }
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return $value;
    }

    private function nonEmptyStringOrNull(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function boolOrNull(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }
}
