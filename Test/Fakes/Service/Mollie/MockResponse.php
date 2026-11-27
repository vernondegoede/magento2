<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Test\Fakes\Service\Mollie;

use GuzzleHttp\Psr7\Response;
use Mollie\Api\Models\Components\ErrorResponseDocumentation;
use Mollie\Api\Models\Components\ErrorResponseLinks;
use Mollie\Api\Models\Errors\ErrorResponse;
use Mollie\Api\Models\Errors\ErrorResponseThrowable;

class MockResponse
{
    /**
     * @param string|array<string, mixed> $body
     */
    public static function ok(string|array $body = 'payment', int $status = 200): Response
    {
        if (is_string($body)) {
            $payload = self::fixture($body);
        } else {
            $payload = $body;
        }

        return new Response(
            $status,
            ['Content-Type' => 'application/hal+json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param string|array<string, mixed> $body
     */
    public static function created(string|array $body = 'payment'): Response
    {
        return self::ok($body, 201);
    }

    public static function noContent(): Response
    {
        return new Response(204, [], '');
    }

    public static function error(int $status, string $title, string $detail, ?string $field = null): Response
    {
        $payload = [
            'status' => $status,
            'title' => $title,
            'detail' => $detail,
            '_links' => [
                'documentation' => [
                    'href' => 'https://docs.mollie.com/overview/handling-errors',
                    'type' => 'text/html',
                ],
            ],
        ];

        if ($field !== null) {
            $payload['field'] = $field;
        }

        return new Response(
            $status,
            ['Content-Type' => 'application/hal+json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    public static function errorThrowable(
        int $status,
        string $title,
        string $detail,
        ?string $field = null,
    ): ErrorResponseThrowable {
        $error = new ErrorResponse(
            status: $status,
            title: $title,
            detail: $detail,
            links: new ErrorResponseLinks(
                documentation: new ErrorResponseDocumentation(
                    href: 'https://docs.mollie.com/overview/handling-errors',
                    type: 'text/html',
                ),
            ),
            field: $field,
        );

        return $error->toException();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function payment(array $overrides = [], int $status = 200): Response
    {
        return self::ok(self::merge(self::paymentData(), $overrides), $status);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function session(array $overrides = [], int $status = 200): Response
    {
        return self::ok(self::merge(self::sessionData(), $overrides), $status);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function profile(array $overrides = [], int $status = 200): Response
    {
        return self::ok(self::merge(self::profileData(), $overrides), $status);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function capture(array $overrides = [], int $status = 201): Response
    {
        return self::ok(self::merge(self::captureData(), $overrides), $status);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function refund(array $overrides = [], int $status = 201): Response
    {
        return self::ok(self::merge(self::refundData(), $overrides), $status);
    }

    /**
     * @param array<int, array<string, mixed>> $refunds
     */
    public static function refundList(array $refunds = []): Response
    {
        $items = array_map(
            static fn (array $refund): array => self::merge(self::listRefundData(), $refund),
            $refunds,
        );

        return self::ok([
            'count' => count($items),
            '_embedded' => [
                'refunds' => $items,
            ],
            '_links' => [
                'self' => ['href' => 'https://api.mollie.com/v2/payments/tr_WDqYK6vllg/refunds', 'type' => 'application/hal+json'],
                'documentation' => ['href' => 'https://docs.mollie.com/reference/v2/refunds-api/list-refunds', 'type' => 'text/html'],
                'previous' => null,
                'next' => null,
            ],
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $methods
     */
    public static function methodList(?array $methods = null): Response
    {
        if ($methods === null) {
            return self::ok('method-list');
        }

        $items = array_map(
            static fn (array $method): array => self::merge(self::methodData(), $method),
            $methods,
        );

        return self::ok([
            'count' => count($items),
            '_embedded' => [
                'methods' => $items,
            ],
            '_links' => [
                'self' => ['href' => 'https://api.mollie.com/v2/methods', 'type' => 'application/hal+json'],
                'documentation' => ['href' => 'https://docs.mollie.com/reference/v2/methods-api/list-methods', 'type' => 'text/html'],
            ],
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $terminals
     */
    public static function terminalList(?array $terminals = null): Response
    {
        if ($terminals === null) {
            return self::ok('terminal-list');
        }

        $items = array_map(
            static fn (array $terminal): array => self::merge(self::terminalData(), $terminal),
            $terminals,
        );

        return self::ok([
            'count' => count($items),
            '_embedded' => [
                'terminals' => $items,
            ],
            '_links' => [
                'self' => ['href' => 'https://api.mollie.com/v2/terminals', 'type' => 'application/hal+json'],
                'documentation' => ['href' => 'https://docs.mollie.com/reference/v2/terminals-api/list-terminals', 'type' => 'text/html'],
                'previous' => null,
                'next' => null,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function fixture(string $name): array
    {
        return match ($name) {
            'payment' => self::paymentData(),
            'session' => self::sessionData(),
            'profile' => self::profileData(),
            'capture' => self::captureData(),
            'refund' => self::refundData(),
            'method-list' => [
                'count' => 2,
                '_embedded' => [
                    'methods' => [
                        self::methodData(['id' => 'ideal', 'description' => 'iDEAL']),
                        self::methodData(['id' => 'creditcard', 'description' => 'Credit card']),
                    ],
                ],
                '_links' => [
                    'self' => ['href' => 'https://api.mollie.com/v2/methods', 'type' => 'application/hal+json'],
                    'documentation' => ['href' => 'https://docs.mollie.com/reference/v2/methods-api/list-methods', 'type' => 'text/html'],
                ],
            ],
            'terminal-list' => [
                'count' => 1,
                '_embedded' => [
                    'terminals' => [
                        self::terminalData(),
                    ],
                ],
                '_links' => [
                    'self' => ['href' => 'https://api.mollie.com/v2/terminals', 'type' => 'application/hal+json'],
                    'documentation' => ['href' => 'https://docs.mollie.com/reference/v2/terminals-api/list-terminals', 'type' => 'text/html'],
                    'previous' => null,
                    'next' => null,
                ],
            ],
            default => json_decode($name, true) ?? [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function paymentData(array $overrides = []): array
    {
        $base = [
            'resource' => 'payment',
            'id' => 'tr_WDqYK6vllg',
            'mode' => 'test',
            'createdAt' => '2024-01-01T12:00:00+00:00',
            'amount' => [
                'value' => '100.00',
                'currency' => 'USD',
            ],
            'description' => 'Order #100000001',
            'method' => 'ideal',
            'metadata' => null,
            'status' => 'paid',
            'isCancelable' => false,
            'sequenceType' => 'oneoff',
            'profileId' => 'pfl_QkEhN94Ba',
            'redirectUrl' => 'https://example.org/redirect',
            'webhookUrl' => 'https://example.org/webhook',
            '_links' => [
                'self' => [
                    'href' => 'https://api.mollie.com/v2/payments/tr_WDqYK6vllg',
                    'type' => 'application/hal+json',
                ],
                'checkout' => [
                    'href' => 'https://www.mollie.com/checkout/select-method/7UhSN1zuXS',
                    'type' => 'text/html',
                ],
                'dashboard' => [
                    'href' => 'https://www.mollie.com/dashboard/org_12345678/payments/tr_WDqYK6vllg',
                    'type' => 'text/html',
                ],
            ],
        ];

        return self::merge($base, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function sessionData(array $overrides = []): array
    {
        $base = [
            'resource' => 'session',
            'id' => 'sess_test123',
            'mode' => 'test',
            'clientAccessToken' => 'cat_test_token',
            'status' => 'open',
            'amount' => [
                'currency' => 'EUR',
                'value' => '10.00',
            ],
            'description' => 'Test Store',
            'lines' => [],
            'redirectUrl' => 'https://example.org/redirect',
            'profileId' => 'pfl_QkEhN94Ba',
            'createdAt' => '2024-01-01T12:00:00+00:00',
            '_links' => [
                'self' => [
                    'href' => 'https://api.mollie.com/v2/sessions/sess_test123',
                    'type' => 'application/hal+json',
                ],
            ],
        ];

        return self::merge($base, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function profileData(array $overrides = []): array
    {
        $base = [
            'resource' => 'profile',
            'id' => 'pfl_QkEhN94Ba',
            'mode' => 'test',
            'name' => 'My website name',
            'website' => 'https://www.mywebsite.com',
            'email' => 'info@mywebsite.com',
            'phone' => '+31208202070',
            'businessCategory' => 'OTHER_MERCHANDISE',
            'status' => 'verified',
            'createdAt' => '2024-01-01T12:00:00+00:00',
            '_links' => [
                'self' => [
                    'href' => 'https://api.mollie.com/v2/profiles/pfl_QkEhN94Ba',
                    'type' => 'application/hal+json',
                ],
            ],
        ];

        return self::merge($base, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function captureData(array $overrides = []): array
    {
        $base = [
            'resource' => 'capture',
            'id' => 'cpt_4qqhO89gsT',
            'mode' => 'test',
            'status' => 'succeeded',
            'paymentId' => 'tr_WDqYK6vllg',
            'createdAt' => '2024-01-01T12:00:00+00:00',
            'amount' => [
                'currency' => 'EUR',
                'value' => '100.00',
            ],
            '_links' => [
                'self' => [
                    'href' => 'https://api.mollie.com/v2/payments/tr_WDqYK6vllg/captures/cpt_4qqhO89gsT',
                    'type' => 'application/hal+json',
                ],
                'payment' => [
                    'href' => 'https://api.mollie.com/v2/payments/tr_WDqYK6vllg',
                    'type' => 'application/hal+json',
                ],
                'documentation' => [
                    'href' => 'https://docs.mollie.com/reference/v2/captures-api/get-capture',
                    'type' => 'text/html',
                ],
            ],
        ];

        return self::merge($base, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function refundData(array $overrides = []): array
    {
        $base = [
            'resource' => 'refund',
            'id' => 're_4qqhO89gsT',
            'mode' => 'test',
            'description' => 'Refund for order 100000001',
            'amount' => [
                'currency' => 'EUR',
                'value' => '10.00',
            ],
            'paymentId' => 'tr_WDqYK6vllg',
            'status' => 'pending',
            'createdAt' => '2024-01-01T12:00:00+00:00',
            'metadata' => null,
            '_links' => [
                'self' => [
                    'href' => 'https://api.mollie.com/v2/payments/tr_WDqYK6vllg/refunds/re_4qqhO89gsT',
                    'type' => 'application/hal+json',
                ],
                'payment' => [
                    'href' => 'https://api.mollie.com/v2/payments/tr_WDqYK6vllg',
                    'type' => 'application/hal+json',
                ],
                'documentation' => [
                    'href' => 'https://docs.mollie.com/reference/v2/refunds-api/get-refund',
                    'type' => 'text/html',
                ],
            ],
        ];

        return self::merge($base, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function listRefundData(array $overrides = []): array
    {
        $base = [
            'resource' => 'refund',
            'id' => 're_4qqhO89gsT',
            'mode' => 'test',
            'description' => 'Refund for order 100000001',
            'amount' => [
                'currency' => 'EUR',
                'value' => '10.00',
            ],
            'paymentId' => 'tr_WDqYK6vllg',
            'status' => 'refunded',
            'createdAt' => '2024-01-01T12:00:00+00:00',
            'metadata' => null,
            '_links' => [
                'self' => [
                    'href' => 'https://api.mollie.com/v2/payments/tr_WDqYK6vllg/refunds/re_4qqhO89gsT',
                    'type' => 'application/hal+json',
                ],
                'payment' => [
                    'href' => 'https://api.mollie.com/v2/payments/tr_WDqYK6vllg',
                    'type' => 'application/hal+json',
                ],
            ],
        ];

        return self::merge($base, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function methodData(array $overrides = []): array
    {
        $id = $overrides['id'] ?? 'ideal';
        $base = [
            'resource' => 'method',
            'id' => $id,
            'description' => ucfirst((string) $id),
            'minimumAmount' => ['value' => '0.01', 'currency' => 'EUR'],
            'maximumAmount' => ['value' => '50000.00', 'currency' => 'EUR'],
            'image' => [
                'size1x' => 'https://www.mollie.com/external/icons/payment-methods/' . $id . '.png',
                'size2x' => 'https://www.mollie.com/external/icons/payment-methods/' . $id . '%402x.png',
                'svg' => 'https://www.mollie.com/external/icons/payment-methods/' . $id . '.svg',
            ],
            'status' => 'activated',
            '_links' => [
                'self' => [
                    'href' => 'https://api.mollie.com/v2/methods/' . $id,
                    'type' => 'application/hal+json',
                ],
            ],
        ];

        return self::merge($base, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function terminalData(array $overrides = []): array
    {
        $base = [
            'resource' => 'terminal',
            'id' => 'term_7UhSN1zuXS',
            'mode' => 'test',
            'description' => 'Terminal #1',
            'status' => 'active',
            'brand' => 'PAX',
            'model' => 'A920',
            'serialNumber' => '1234567890',
            'currency' => 'EUR',
            'profileId' => 'pfl_QkEhN94Ba',
            'createdAt' => '2024-01-01T12:00:00+00:00',
            'updatedAt' => '2024-01-01T12:00:00+00:00',
            '_links' => [
                'self' => [
                    'href' => 'https://api.mollie.com/v2/terminals/term_7UhSN1zuXS',
                    'type' => 'application/hal+json',
                ],
            ],
        ];

        return self::merge($base, $overrides);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value)) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
