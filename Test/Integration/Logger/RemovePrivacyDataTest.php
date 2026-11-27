<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Test\Integration\Logger;

use Mollie\Api\Models\Components\PaymentAddress;
use Mollie\Api\Models\Components\PaymentResponseBillingAddress;
use Mollie\Payment\Logger\RemovePrivacyData;
use Mollie\Payment\Test\Integration\IntegrationTestCase;
use Mollie\Payment\Test\Integration\MolliePaymentBuilder;

class RemovePrivacyDataTest extends IntegrationTestCase
{
    public function testRemovesTheDataFromAnArray(): void
    {
        $data = array (
            'amount' =>
                array (
                    'currency' => 'EUR',
                    'value' => '76.00',
                ),
            'description' => '000000316',
            'billingAddress' =>
                array (
                    'givenName' => 'Jan',
                    'familyName' => 'Jansen',
                    'organizationName' => 'Jansens BV',
                    'streetAndNumber' => 'Vinex straat',
                    'postalCode' => '1234AB',
                    'email' => 'jan@jansensbv.nl',
                    'telephone' => '0123456789',
                    'city' => 'Vinex wijk',
                    'region' => NULL,
                    'country' => 'NL',
                    'phone' => '+3112345678',
                ),
            'redirectUrl' => 'https://mollie-opensource-248-v3.controlaltdelete.dev/mollie/checkout/process/?order_id=312&payment_token=46MfKK2sAzNGzqVz4TIhrRKiSsQochbn&utm_nooverride=1',
            'webhookUrl' => 'https://mollie-opensource-248-v3.controlaltdelete.dev/mollie/checkout/webhook/?isAjax=1&orderId[]=MDozOlIzTFd5bUd0U1Q3SUJpbXVsMnJMNy84WUx5MCtpYUN0RDJDZ09ERENhZz09',
            'method' => 'ideal',
            'metadata' =>
                array (
                    'order_id' => '312',
                    'store_id' => 1,
                    'payment_token' => '46MfKK2sAzNGzqVz4TIhrRKiSsQochbn',
                ),
            'locale' => NULL,
            'shippingAddress' =>
                array (
                    'givenName' => 'Jan',
                    'familyName' => 'Jansen',
                    'organizationName' => 'Jansens BV',
                    'streetAndNumber' => 'Vinex straat',
                    'postalCode' => '1234AB',
                    'email' => 'jan@jansensbv.nl',
                    'telephone' => '0123456789',
                    'city' => 'Vinex wijk',
                    'region' => NULL,
                    'country' => 'NL',
                    'phone' => '+3112345678',
                ),
        );

        $instance = $this->objectManager->create(RemovePrivacyData::class);

        $result = var_export($instance->execute($data), true);

        $this->assertStringNotContainsString('Jan', $result);
        $this->assertStringNotContainsString('Jansens BV', $result);
        $this->assertStringNotContainsString('Jansens', $result);
        $this->assertStringNotContainsString('1234AB', $result);
        $this->assertStringNotContainsString('jan@jansensbv.nl', $result);
        $this->assertStringNotContainsString('Vinex straat', $result);
        $this->assertStringNotContainsString('Vinex wijk', $result);
        $this->assertStringNotContainsString('0123456789', $result);
        $this->assertStringNotContainsString('+3112345678', $result);
    }

    public function testRemovesDataFromThePaymentObject(): void
    {
        $payment = (new MolliePaymentBuilder())->build();
        $payment->billingAddress = $this->getBillingAddress();
        $payment->shippingAddress = $this->getShippingAddress();

        $instance = $this->objectManager->create(RemovePrivacyData::class);

        $result = var_export($instance->execute($payment), true);

        $this->assertStringNotContainsString('Jan', $result);
        $this->assertStringNotContainsString('Jansens BV', $result);
        $this->assertStringNotContainsString('Jansens', $result);
        $this->assertStringNotContainsString('1234AB', $result);
        $this->assertStringNotContainsString('jan@jansensbv.nl', $result);
        $this->assertStringNotContainsString('Vinex straat', $result);
        $this->assertStringNotContainsString('Vinex wijk', $result);
        $this->assertStringNotContainsString('0123456789', $result);
        $this->assertStringNotContainsString('+3112345678', $result);
    }

    public function testDoesNotAlterExistingObject(): void
    {
        $original = (new MolliePaymentBuilder())->build();
        $original->billingAddress = $this->getBillingAddress();
        $original->shippingAddress = $this->getShippingAddress();

        $instance = $this->objectManager->create(RemovePrivacyData::class);
        $result = $instance->execute($original);

        $this->assertEquals($result->billingAddress->familyName, '********');
        $this->assertEquals($original->billingAddress->familyName, 'Jansens');
    }

    private function getBillingAddress(): PaymentResponseBillingAddress
    {
        return new PaymentResponseBillingAddress(
            givenName: 'Jan',
            familyName: 'Jansens',
            organizationName: 'Jansens BV',
            streetAndNumber: 'Vinex straat',
            postalCode: '1234AB',
            email: 'jan@jansensbv.nl',
            phone: '+3112345678',
            city: 'Vinex wijk',
            country: 'NL',
        );
    }

    private function getShippingAddress(): PaymentAddress
    {
        return new PaymentAddress(
            givenName: 'Jan',
            familyName: 'Jansens',
            organizationName: 'Jansens BV',
            streetAndNumber: 'Vinex straat',
            postalCode: '1234AB',
            email: 'jan@jansensbv.nl',
            phone: '+3112345678',
            city: 'Vinex wijk',
            country: 'NL',
        );
    }

}
