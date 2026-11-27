<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Service\Order;

use Magento\Sales\Api\Data\OrderInterface;
use Mollie\Api\Models\Components\PaymentResponse;
use Mollie\Payment\Service\Order\ProcessTransaction\ProcessTransactionInterface;

class TransactionProcessor
{
    public function __construct(
        private array $processors = []
    ) {}

    public function process(OrderInterface $order, PaymentResponse $molliePayment): void
    {
        /** @var ProcessTransactionInterface $processor */
        foreach ($this->processors as $processor) {
            $processor->process($order, $molliePayment);
        }
    }
}
