<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Service\Mollie\Wrapper;

use Mollie\Api\Models\Components\PaymentResponse;
use Mollie\Api\Models\Operations\GetPaymentRequest;
use Mollie\Payment\Service\Mollie\Express\ExpressPaymentResponseLine;
use Mollie\Payment\Service\Mollie\MollieApiClient;

class GetExpressPayment
{
    public function __construct(
        readonly private MollieApiClient $mollieApiClient,
    ) {}

    public function execute(int $storeId, string $paymentId): PaymentResponse
    {
        $mollieApi = $this->mollieApiClient->loadByStore($storeId);

        $response = $mollieApi->payments->get(new GetPaymentRequest(paymentId: $paymentId));
        $payment = $response->paymentResponse;

        if ($payment->lines !== null) {
            $rawLines = $this->extractRawLines($response->rawResponse);
            foreach ($payment->lines as $index => $line) {
                $reference = $rawLines[$index]['reference'] ?? null;
                if (is_string($reference) && $reference !== '') {
                    $payment->lines[$index] = ExpressPaymentResponseLine::fromPaymentResponseLine($line, $reference);
                }
            }
        }

        return $payment;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractRawLines(\Psr\Http\Message\ResponseInterface $rawResponse): array
    {
        $body = $rawResponse->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $decoded = json_decode($body->getContents(), true);
        if (!is_array($decoded) || !is_array($decoded['lines'] ?? null)) {
            return [];
        }

        return $decoded['lines'];
    }
}
