<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Model\Client;

use Magento\Sales\Api\Data\OrderInterface;
use Mollie\Api\Models\Components\PaymentResponse;

interface PaymentProcessorInterface
{
    public function process(
        OrderInterface $magentoOrder,
        PaymentResponse $molliePayment,
        string $type,
        ProcessTransactionResponse $response,
    ): ?ProcessTransactionResponse;
}
