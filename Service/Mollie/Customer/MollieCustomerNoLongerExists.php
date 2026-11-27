<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Service\Mollie\Customer;

use Mollie\Api\Models\Errors\APIException;
use Mollie\Api\Models\Errors\ErrorResponseThrowable;
use Mollie\Payment\Service\Mollie\ApiExceptionHelper;

class MollieCustomerNoLongerExists
{
    private const GONE_STATUS_CODE = 410;
    private const GONE_RESOURCE = 'customer';
    private const GONE_DESCRIPTION = 'no longer available';

    public function check(APIException|ErrorResponseThrowable $exception): bool
    {
        if (ApiExceptionHelper::getStatusCode($exception) !== self::GONE_STATUS_CODE) {
            return false;
        }

        $message = strtolower(ApiExceptionHelper::getMessage($exception));
        if ($exception instanceof APIException && $exception->body !== '') {
            $message .= ' ' . strtolower($exception->body);
        }

        return str_contains($message, self::GONE_RESOURCE) && str_contains($message, self::GONE_DESCRIPTION);
    }
}
