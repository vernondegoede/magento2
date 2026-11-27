<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Test\Integration\Model\Methods;

use Mollie\Payment\Helper\General;
use Mollie\Payment\Model\Methods\ApplePay;
use Mollie\Payment\Model\MollieConfigProvider;
use Mollie\Payment\Service\Mollie\ListMethods;
use Mollie\Payment\Test\Fakes\Service\Mollie\MockResponse;
use Psr\Http\Message\RequestInterface;

class ApplePayMethodTest extends AbstractTestMethod
{
    protected ?string $instance = ApplePay::class;

    protected ?string $code = 'applepay';

    public function testTheIncludeWalletsParameterIsUsed(): void
    {
        $fake = $this->loadFakeMollieApiClient();
        $fake->fake([
            'GET /v2/methods' => MockResponse::methodList(),
        ]);

        $mollieHelperMock = $this->createMock(General::class);
        $mollieHelperMock->method('getOrderAmountByQuote')->willReturn(['value' => 100, 'currency' => 'EUR']);

        /** @var MollieConfigProvider $instance */
        $instance = $this->objectManager->create(MollieConfigProvider::class, [
            'mollieHelper' => $mollieHelperMock,
        ]);
        $instance->getActiveMethods();

        $fake->assertSent(
            'GET /v2/methods',
            function (RequestInterface $request, array $payload, array $options): bool {
                $queryString = (string) ($options['query'] ?? $request->getUri()->getQuery());
                parse_str($queryString, $queryParams);

                $this->assertArrayHasKey('includeWallets', $queryParams);
                $this->assertEquals(ListMethods::WALLETS, $queryParams['includeWallets']);

                return true;
            },
        );
    }
}
