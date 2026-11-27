<?php

/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Service\Mollie;

use Mollie\Api\Models\Components\ListEntityTerminalStatus;
use Mollie\Api\Models\Errors\APIException;
use Mollie\Api\Models\Errors\ErrorResponseThrowable;

class AvailableTerminals
{
    public function __construct(
        private MollieApiClient $mollieApiClient
    ) {}

    /**
     * @return list<array{
     *      id: string,
     *      brand: string,
     *      model: string,
     *      serialNumber: string|null,
     *      description: string
     *  }>
     */
    public function execute(?int $storeId = null): array
    {
        try {
            $mollieApiClient = $this->mollieApiClient->loadByStore($storeId);
            $output = [];

            foreach ($mollieApiClient->terminals->list() as $page) {
                foreach ($page->object?->embedded->terminals ?? [] as $terminal) {
                    if ($terminal->status !== ListEntityTerminalStatus::Active) {
                        continue;
                    }

                    $output[] = [
                        'id' => $terminal->id,
                        'brand' => $terminal->brand->value ?? '',
                        'model' => $terminal->model->value ?? '',
                        'serialNumber' => $terminal->serialNumber,
                        'description' => $terminal->description,
                    ];
                }
            }

            return $output;
        } catch (APIException|ErrorResponseThrowable) {
            return [];
        }
    }
}
