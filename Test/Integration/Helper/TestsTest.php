<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Test\Integration\Helper;

use Mollie\Payment\Helper\Tests;
use Mollie\Payment\Service\Mollie\ListMethods;
use Mollie\Payment\Test\Fakes\Service\Mollie\FakeMollieApiClient;
use Mollie\Payment\Test\Fakes\Service\Mollie\MockResponse;
use Mollie\Payment\Test\Integration\IntegrationTestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class TestsTest extends IntegrationTestCase
{
    public function testRequestsTheWalletMethodsForBothApiKeys(): void
    {
        $requestedWallets = [];

        /** @var FakeMollieApiClient $fakeMollieApiClient */
        $fakeMollieApiClient = $this->objectManager->get(FakeMollieApiClient::class);
        $fakeMollieApiClient->fake([
            'GET /v2/methods' => function (
                RequestInterface $request,
                array $payload,
                array $options,
            ) use (&$requestedWallets): ResponseInterface {
                $queryString = is_string($options['query'] ?? null)
                    ? $options['query']
                    : $request->getUri()->getQuery();
                parse_str($queryString, $query);
                $requestedWallets[] = $query['includeWallets'] ?? null;

                return MockResponse::ok('method-list');
            },
            'GET /v2/terminals' => MockResponse::ok('terminal-list'),
        ]);
        $this->objectManager->addSharedInstance($fakeMollieApiClient, \Mollie\Payment\Service\Mollie\MollieApiClient::class);

        /** @var Tests $instance */
        $instance = $this->objectManager->create(Tests::class);
        $instance->getMethods('test_dummyapikeythatisvalidandislongenough', 'live_dummyapikeythatisvalidandislongenough');

        $this->assertEquals(
            [ListMethods::WALLETS, ListMethods::WALLETS],
            $requestedWallets,
        );
    }
}
