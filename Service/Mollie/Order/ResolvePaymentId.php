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
 * Orders placed on the module's V2 stored an Orders API id (ord_...) in mollie_transaction_id. V3 dropped the
 * Orders API and only talks to the Payments API, so passing such a legacy id to payments->get() returns a 404
 * and the order can never be processed. This resolves a legacy ord_ id to its underlying payment id (tr_...) so
 * those orders keep working under V3. Ids that are already payment ids are returned untouched.
 *
 * @deprecated This is a transitional fallback for V2 orders only. Remove once no ord_ orders remain in flight.
 */
class ResolvePaymentId
{
    private const SETTLED_STATUSES = ['paid', 'authorized'];

    public function __construct(
        private LegacyOrderTransactionId $legacyOrderTransactionId,
        private RawApiRequest $rawApiRequest,
    ) {
    }

    public function execute(Client $mollieApi, string $transactionId): string
    {
        if (!$this->legacyOrderTransactionId->matches($transactionId)) {
            return $transactionId;
        }

        $payments = $this->fetchEmbeddedPayments($mollieApi, $transactionId);

        return $this->selectPayment($payments, $transactionId)['id'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchEmbeddedPayments(Client $mollieApi, string $transactionId): array
    {
        $attributes = $this->rawApiRequest->sendJson(
            $mollieApi,
            'GET',
            '/v2/orders/' . $transactionId,
            ['embed' => 'payments'],
        );

        $embedded = $attributes['_embedded'] ?? [];
        if (!is_array($embedded)) {
            return [];
        }

        return is_array($embedded['payments'] ?? null) ? $embedded['payments'] : [];
    }

    /**
     * @param array<int, array<string, mixed>> $payments
     * @return array<string, mixed>
     */
    private function selectPayment(array $payments, string $transactionId): array
    {
        foreach ($payments as $payment) {
            if (in_array($payment['status'] ?? null, self::SETTLED_STATUSES, true)) {
                return $payment;
            }
        }

        $payment = end($payments);
        if ($payment === false) {
            throw new LocalizedException(
                __('No Mollie payment found for legacy order %1', $transactionId),
            );
        }

        return $payment;
    }
}
