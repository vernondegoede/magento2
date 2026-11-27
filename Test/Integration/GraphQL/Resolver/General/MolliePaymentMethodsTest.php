<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Test\Integration\GraphQL\Resolver\General;

use Exception;
use Magento\PageCache\Model\Cache\Type;
use Mollie\Payment\Service\Mollie\ListMethods;
use Mollie\Payment\Test\Fakes\Service\Mollie\FakeHttpClient;
use Mollie\Payment\Test\Fakes\Service\Mollie\FakeMollieApiClient;
use Mollie\Payment\Test\Fakes\Service\Mollie\MockResponse;
use Mollie\Payment\Test\Integration\GraphQLTestCase;
use Psr\Http\Message\RequestInterface;
use Zend_Cache;

/**
 * @magentoAppArea graphql
 */
class MolliePaymentMethodsTest extends GraphQLTestCase
{
    /**
     * @magentoConfigFixture default_store payment/mollie_methods_ideal/active 1
     * @magentoConfigFixture default_store payment/mollie_methods_ideal/title custom_title
     * @return void
     */
    public function testReturnsMethodsWithTheCorrectTitle(): void
    {
        $result = $this->callEndpoint();

        $found = false;
        foreach ($result as $method) {
            if ($method['code'] == 'ideal') {
                $this->assertEquals('ideal', $method['code']);
                $this->assertEquals('custom_title', $method['name']);
                $found = true;
                break;
            }
        }

        if (!$found) {
            throw new Exception('The result didn\'t include iDEAL | Wero');
        }
    }

    /**
     * @magentoConfigFixture default_store payment/mollie_methods_ideal/active 1
     * @magentoConfigFixture default_store payment/mollie_methods_creditcard/active 0
     * @return void
     */
    public function testDoesNotReturnMethodWhenNotActive(): void
    {
        $result = $this->callEndpoint();

        $this->assertCount(1, $result);
        $this->assertEquals('ideal', $result[0]['code']);
    }

    /**
     * @magentoConfigFixture default_store payment/mollie_methods_creditcard/active 1
     * @magentoConfigFixture default_store payment/mollie_methods_creditcard/title CREDITCARD
     * @magentoConfigFixture default_store payment/mollie_methods_ideal/active 1
     * @magentoConfigFixture default_store payment/mollie_methods_ideal/title iDEAL
     * @magentoConfigFixture default_store payment/mollie_methods_kbc/active 1
     * @magentoConfigFixture default_store payment/mollie_methods_kbc/title KBC/CBC
     * @return void
     */
    public function testSortsMethodsByName(): void
    {
        $this->cleanCache();

        $result = $this->callEndpoint();

        $this->assertCount(2, $result);
        $this->assertEquals('CREDITCARD', $result[0]['name']);
        $this->assertEquals('iDEAL', $result[1]['name']);
    }

    public function testForwardsTheAmountAndCurrencyToTheMethodsApi(): void
    {
        $this->cleanCache();
        $client = $this->fakeMollieApiClient();

        $this->graphQlQuery('
            query {
                molliePaymentMethods(input: {amount: 34.00, currency: "PLN"}) {
                    methods {
                        code
                    }
                }
            }
        ');

        $client->assertSent('GET /v2/methods', function (RequestInterface $request, array $payload, array $options): bool {
            $query = $this->parseQuery($request, $options);

            return ($query['amount']['currency'] ?? null) === 'PLN'
                && ($query['amount']['value'] ?? null) === '34.00';
        });
    }

    public function testRequestsTheWalletMethodsWhenNoCurrencyIsGiven(): void
    {
        $this->cleanCache();
        $client = $this->fakeMollieApiClient();

        $this->graphQlQuery('
            query {
                molliePaymentMethods {
                    methods {
                        code
                    }
                }
            }
        ');

        $client->assertSent('GET /v2/methods', function (RequestInterface $request, array $payload, array $options): bool {
            $query = $this->parseQuery($request, $options);

            return ($query['includeWallets'] ?? null) === ListMethods::WALLETS;
        });
    }

    public function testRequestsTheWalletMethodsWhenACurrencyIsGiven(): void
    {
        $this->cleanCache();
        $client = $this->fakeMollieApiClient();

        $this->graphQlQuery('
            query {
                molliePaymentMethods(input: {amount: 34.00, currency: "EUR"}) {
                    methods {
                        code
                    }
                }
            }
        ');

        $client->assertSent('GET /v2/methods', function (RequestInterface $request, array $payload, array $options): bool {
            $query = $this->parseQuery($request, $options);

            return ($query['includeWallets'] ?? null) === ListMethods::WALLETS;
        });
    }

    private function fakeMollieApiClient(): FakeHttpClient
    {
        $this->loadFakeEncryptor()->addReturnValue('', 'test_dummyapikeythatisvalidandislongenough');

        /** @var FakeMollieApiClient $fakeMollieApiClient */
        $fakeMollieApiClient = $this->objectManager->get(FakeMollieApiClient::class);
        $httpClient = $fakeMollieApiClient->fake([
            'GET /v2/methods' => MockResponse::ok('method-list'),
            'GET /v2/terminals' => MockResponse::ok('terminal-list'),
        ]);
        $this->objectManager->addSharedInstance($fakeMollieApiClient, \Mollie\Payment\Service\Mollie\MollieApiClient::class);

        return $httpClient;
    }

    private function callEndpoint(): array
    {
        $this->fakeMollieApiClient();

        return $this->graphQlQuery('
            query {
                molliePaymentMethods {
                    methods {
                        code
                        name
                    }
                }
            }
        ')['molliePaymentMethods']['methods'];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function parseQuery(RequestInterface $request, array $options): array
    {
        $queryString = is_string($options['query'] ?? null)
            ? $options['query']
            : $request->getUri()->getQuery();
        parse_str($queryString, $query);

        return $query;
    }

    /**
     * @return void
     */
    public function cleanCache(): void
    {
        /** @var Type::class $cache */
        $cache = $this->objectManager->get(Type::class);
        $cache->clean(
            Zend_Cache::CLEANING_MODE_MATCHING_TAG,
            ['mollie_payment_methods'],
        );
    }
}
