<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Test\Integration\Service\Mollie;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Mollie\Api\Client as MollieClient;
use Mollie\Api\Models\Components\Security;
use Mollie\Api\Utils\Retry\RetryConfigBackoff;
use Mollie\Payment\Helper\General;
use Mollie\Payment\Logger\MollieLogger;
use Mollie\Payment\Service\Mollie\StartTransaction;
use Mollie\Payment\Test\Fakes\Logger\MollieLoggerFake;
use Mollie\Payment\Test\Fakes\Service\Mollie\MockResponse;
use Mollie\Payment\Test\Integration\IntegrationTestCase;
use Psr\Http\Message\RequestInterface;

class StartTransactionTimeoutTest extends IntegrationTestCase
{
    private const API_KEY = 'test_dHar4XY7LxsDOtmnkVtjNVWXLSlXsM';
    private const CHECKOUT_URL = 'https://www.mollie.com/checkout/select-method/tr_created';
    private const PAYMENTS_ENDPOINT = '/v2/payments';
    private const FIXTURE_QUOTE = 'test01';
    private const FIXTURE_ORDER = '100000001';

    private ?array $history = null;

    private ?MollieLoggerFake $logger = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->history = [];
        $this->loadFakeOrderLockService();
        $this->logger = $this->objectManager->create(MollieLoggerFake::class);
        $this->objectManager->addSharedInstance($this->logger, MollieLogger::class);
        $this->objectManager->addSharedInstance(
            $this->objectManager->create(General::class, ['logger' => $this->logger]),
            General::class,
        );
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/quote.php
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testReusesTheIdempotencyKeyWhenTheClientRetriesATimeout(): void
    {
        $order = $this->givenAMollieOrder();
        $this->givenMollieRespondsWith([
            $this->timeout(),
            $this->createdPayment(),
        ]);

        $result = $this->createInstance()->execute($order);

        $this->assertEquals(self::CHECKOUT_URL, $result);
        $this->assertCount(2, $this->createPaymentRequests());
        $this->assertCount(1, $this->distinctIdempotencyKeys());
        $this->assertSame([], $this->logger->getErrorLogs());
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/quote.php
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testDoesNotCreateAPaymentWithANewIdempotencyKeyWhenEveryClientRetryTimedOut(): void
    {
        $order = $this->givenAMollieOrder();
        $this->givenMollieAlwaysTimesOut(fn (): Response => $this->createdPayment());

        $this->assertFailsWithATimeout($order);

        $this->assertGreaterThan(1, count($this->createPaymentRequests()));
        $this->assertCount(1, $this->distinctIdempotencyKeys());
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/quote.php
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @magentoConfigFixture admin_store payment/mollie_general/debug 1
     */
    public function testLogsTheFailedPaymentCreationWhenDebugLoggingIsEnabled(): void
    {
        $order = $this->givenAMollieOrder();
        $this->givenMollieAlwaysTimesOut();

        $this->assertFailsWithATimeout($order);

        $errorLogs = $this->logger->getErrorLogs();
        $this->assertCount(1, $errorLogs);
        $this->assertSame(self::FIXTURE_ORDER, $errorLogs[0]['data']['order']);
        $this->assertStringContainsString('cURL error 28', $errorLogs[0]['data']['exception']);
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/quote.php
     * @magentoDataFixture Magento/Sales/_files/order.php
     * @magentoConfigFixture admin_store payment/mollie_general/debug 0
     */
    public function testDoesNotLogTheFailedPaymentCreationWhenDebugLoggingIsDisabled(): void
    {
        $order = $this->givenAMollieOrder();
        $this->givenMollieAlwaysTimesOut();

        $this->assertFailsWithATimeout($order);

        $this->assertSame([], $this->logger->getErrorLogs());
    }

    private function assertFailsWithATimeout(OrderInterface $order): void
    {
        try {
            $this->createInstance()->execute($order);
        } catch (ConnectException $exception) {
            $this->assertStringContainsString('cURL error 28', $exception->getMessage());

            return;
        }

        $this->fail('Expected the payment creation to fail with a timeout.');
    }

    private function givenAMollieOrder(): OrderInterface
    {
        $order = $this->loadOrder(self::FIXTURE_ORDER);
        $order->setQuoteId($this->fixtureQuoteId());
        $order->getPayment()->setMethod('mollie_methods_ideal');
        $this->objectManager->get(OrderRepositoryInterface::class)->save($order);

        return $order;
    }

    private function fixtureQuoteId(): int
    {
        return (int)$this->objectManager->create(Quote::class)->load(self::FIXTURE_QUOTE, 'reserved_order_id')->getId();
    }

    private function givenMollieRespondsWith(array $responses): void
    {
        $this->givenMollieHandler(new MockHandler($responses));
    }

    private function givenMollieAlwaysTimesOut(?callable $onNewIdempotencyKey = null): void
    {
        $firstIdempotencyKey = null;

        $this->givenMollieHandler(function (RequestInterface $request) use (&$firstIdempotencyKey, $onNewIdempotencyKey): PromiseInterface {
            $idempotencyKey = $request->getHeaderLine('Idempotency-Key');
            if ($firstIdempotencyKey === null) {
                $firstIdempotencyKey = $idempotencyKey;
            } elseif ($onNewIdempotencyKey !== null && $idempotencyKey !== $firstIdempotencyKey) {
                return Create::promiseFor($onNewIdempotencyKey($request));
            }

            return Create::rejectionFor($this->timeout());
        });
    }

    private function givenMollieHandler(callable $handler): void
    {
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($this->history));

        $client = MollieClient::builder()
            ->setClient(new Client(['handler' => $stack]))
            ->setSecurity(new Security(apiKey: self::API_KEY))
            ->setRetryConfig(new RetryConfigBackoff(
                initialIntervalMs: 100,
                maxIntervalMs: 100,
                exponent: 0,
                maxElapsedTimeMs: 1000,
                retryConnectionErrors: true,
            ))
            ->build();

        $this->loadFakeMollieApiClient()->setInstance($client);
    }

    private function timeout(): ConnectException
    {
        return new ConnectException(
            'cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received',
            new Request('POST', 'https://api.mollie.com' . self::PAYMENTS_ENDPOINT),
        );
    }

    private function createdPayment(): Response
    {
        return MockResponse::payment([
            'id' => 'tr_created',
            'status' => 'open',
            'amount' => ['value' => '100.00', 'currency' => 'USD'],
            'description' => 'Order ' . self::FIXTURE_ORDER,
            'method' => 'ideal',
            '_links' => [
                'checkout' => ['href' => self::CHECKOUT_URL, 'type' => 'text/html'],
            ],
        ], 201);
    }

    private function createPaymentRequests(): array
    {
        return array_values(array_filter(
            array_map(fn (array $transaction): RequestInterface => $transaction['request'], $this->history),
            fn (RequestInterface $request): bool => $request->getMethod() === 'POST'
                && $request->getUri()->getPath() === self::PAYMENTS_ENDPOINT,
        ));
    }

    private function distinctIdempotencyKeys(): array
    {
        return array_values(array_unique(array_map(
            fn (RequestInterface $request): string => $request->getHeaderLine('Idempotency-Key'),
            $this->createPaymentRequests(),
        )));
    }

    private function createInstance(): StartTransaction
    {
        return $this->objectManager->create(StartTransaction::class);
    }
}
