<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Service\Mollie\Order;

use Magento\Framework\Exception\LocalizedException;
use Mollie\Api\Client;
use Mollie\Payment\Service\Mollie\RawApiRequest;

/**
 * Orders placed on the module's V2 stored an Orders API id (ord_...) in mollie_transaction_id. Those payments are
 * linked to an order and cannot be captured through the Payments API: captures->create(ord_...) returns
 * a 404 followed by a 422 ("use the Shipments API instead"). The Orders API captures an authorised order by shipping
 * it. Passing no payload ships every line to capture the full authorised amount, matching the pre-V3 behaviour;
 * passing a lines payload ships only those order lines, which is how a partial capture is performed for a legacy order.
 *
 * @deprecated This is a transitional fallback for V2 orders only. Remove once no ord_ orders remain in flight.
 */
class CaptureLegacyOrder
{
    public function __construct(
        private readonly RawApiRequest $rawApiRequest,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(Client $mollieApi, string $transactionId, array $payload = []): string
    {
        $shipment = $this->rawApiRequest->sendJson(
            $mollieApi,
            'POST',
            '/v2/orders/' . $transactionId . '/shipments',
            [],
            $payload,
        );

        $shipmentId = $shipment['id'] ?? null;
        if (!is_string($shipmentId) || $shipmentId === '') {
            throw new LocalizedException(
                __('Could not capture legacy Mollie order %1', $transactionId),
            );
        }

        return $shipmentId;
    }
}
