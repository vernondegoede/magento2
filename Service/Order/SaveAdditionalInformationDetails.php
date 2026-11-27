<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Service\Order;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Mollie\Api\Models\Components\PaymentDetails;
use Mollie\Api\Utils\JSON;
use Mollie\Payment\Config;
use stdClass;

class SaveAdditionalInformationDetails
{
    public function __construct(
        private EncryptorInterface $encryptor,
        private Config $config
    ) {}

    public function execute(OrderPaymentInterface $payment, PaymentDetails|stdClass|null $details): void
    {
        if ($details instanceof PaymentDetails) {
            $encoded = JSON::createSerializer()->serialize($details, 'json');
        } else {
            $encoded = json_encode($details);
        }

        if ($this->config->encryptPaymentDetails()) {
            $encoded = $this->encryptor->encrypt($encoded);
        }

        $payment->setAdditionalInformation('details', $encoded);
    }
}
