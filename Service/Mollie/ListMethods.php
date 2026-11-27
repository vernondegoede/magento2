<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Service\Mollie;

use BackedEnum;
use Mollie\Api\Client;
use Mollie\Api\Models\Errors\APIException;
use Mollie\Api\Models\Errors\ErrorResponseThrowable;

/**
 * Lists payment methods via GET /v2/methods.
 *
 * Uses RawApiRequest because mollie/php's ListMethodsRequest currently types `includeWallets` and
 * `orderLineCategories` as single enums rather than comma-separated lists (blocking `applepay,googlepay`),
 * and because decoding raw method entries avoids checkout failures if Mollie adds a new method ID before
 * the SDK's closed `ListEntityMethodId` enum is regenerated.
 */
class ListMethods
{
    public const WALLETS = 'applepay,googlepay';

    public function __construct(
        private RawApiRequest $rawApiRequest,
    ) {}

    /**
     * @param array<string, mixed> $parameters
     * @return array<int, array<string, mixed>>
     * @throws APIException
     * @throws ErrorResponseThrowable
     */
    public function execute(Client $client, array $parameters = []): array
    {
        $response = $this->rawApiRequest->sendJson(
            $client,
            'GET',
            '/v2/methods',
            $this->normalizeParameters($parameters),
        );

        $methods = $response['_embedded']['methods'] ?? [];

        return is_array($methods) ? array_values(array_filter($methods, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function normalizeParameters(array $parameters): array
    {
        $normalized = [];

        foreach ($parameters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if ($value instanceof BackedEnum) {
                $normalized[$key] = $value->value;
                continue;
            }

            if (is_bool($value)) {
                $normalized[$key] = $value ? 'true' : 'false';
                continue;
            }

            if (is_array($value)) {
                if (array_is_list($value)) {
                    $items = array_map(
                        static fn (mixed $item): string => $item instanceof BackedEnum ? (string) $item->value : (string) $item,
                        array_filter($value, static fn (mixed $item): bool => $item !== null && $item !== ''),
                    );

                    if ($items !== []) {
                        $normalized[$key] = implode(',', $items);
                    }
                    continue;
                }

                $nested = $this->normalizeParameters($value);
                if ($nested !== []) {
                    $normalized[$key] = $nested;
                }
                continue;
            }

            $normalized[$key] = (string) $value;
        }

        return $normalized;
    }
}
