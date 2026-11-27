<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Service\Order\ProcessTransaction;

use Magento\Sales\Api\Data\OrderInterface;
use Mollie\Api\Models\Components\PaymentResponse;

interface ProcessTransactionInterface
{
    public function process(OrderInterface $order, PaymentResponse $molliePayment): void;
}
