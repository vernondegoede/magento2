<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Model\Client\Payments;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Mollie\Api\Models\Components\PaymentResponse;
use Mollie\Payment\Config;
use Mollie\Payment\Model\Client\PaymentProcessorInterface;
use Mollie\Payment\Model\Client\ProcessTransactionResponse;
use Mollie\Payment\Model\Client\ProcessTransactionResponseFactory;

class PaymentProcessors
{
    public function __construct(
        private Config $config,
        private ProcessTransactionResponseFactory $processTransactionResponseFactory,
        private array $processors
    ) {}

    public function process(
        string $event,
        OrderInterface $magentoOrder,
        PaymentResponse $molliePayment,
        string $type,
        ProcessTransactionResponse $response,
    ): ?ProcessTransactionResponse {
        if (!isset($this->processors[$event])) {
            $this->config->addToLog('success', $response->toArray());

            return $response;
        }

        foreach ($this->processors[$event] as $name => $processor) {
            if (!$processor instanceof PaymentProcessorInterface) {
                throw new LocalizedException(__('"%1" does not implement %1', $name, PaymentProcessorInterface::class));
            }

            $response = $processor->process($magentoOrder, $molliePayment, $type, $response);
        }

        if (!$response) {
            $response = $this->returnResponse($molliePayment, $magentoOrder, $type);
        }

        $this->config->addToLog('success', $response->toArray());

        return $response;
    }

    protected function returnResponse(
        PaymentResponse $molliePayment,
        OrderInterface $magentoOrder,
        string $type
    ): ProcessTransactionResponse {
        return $this->processTransactionResponseFactory->create([
            'success' => false,
            'status' => $molliePayment->status->value,
            'order_id' => $magentoOrder->getEntityId(),
            'type' => $type,
        ]);
    }
}
