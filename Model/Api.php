<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Model;

use Mollie\Api\Client;
use Mollie\Payment\Helper\General as MollieHelper;
use Mollie\Payment\Service\Mollie\MollieApiClient;

/**
 * @deprecated Use \Mollie\Payment\Service\Mollie\MollieApiClient instead.
 */
class Api
{
    public function __construct(
        public MollieHelper $mollieHelper,
        private MollieApiClient $mollieApiClient,
    ) {}

    public function load(?int $storeId = null): Client
    {
        return $this->mollieApiClient->loadByStore($storeId);
    }
}
