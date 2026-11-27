<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Service\Mollie;

use Mollie\Api\Models\Errors\APIException;
use Mollie\Api\Models\Errors\ErrorResponseThrowable;
use Throwable;

class ApiExceptionHelper
{
    public static function isApiException(Throwable $exception): bool
    {
        return $exception instanceof ErrorResponseThrowable || $exception instanceof APIException;
    }

    public static function getStatusCode(Throwable $exception): int
    {
        if ($exception instanceof ErrorResponseThrowable) {
            return $exception->container->status;
        }

        if ($exception instanceof APIException) {
            return $exception->statusCode;
        }

        return (int) $exception->getCode();
    }

    public static function getMessage(Throwable $exception): string
    {
        if ($exception instanceof ErrorResponseThrowable) {
            return $exception->container->detail;
        }

        if ($exception instanceof APIException) {
            if ($exception->body !== '') {
                $decoded = json_decode($exception->body, true);
                if (is_array($decoded) && is_string($decoded['detail'] ?? null) && $decoded['detail'] !== '') {
                    return $decoded['detail'];
                }
            }

            if ($exception->statusCode > 0) {
                return sprintf('[%d] %s', $exception->statusCode, $exception->getMessage());
            }
        }

        return $exception->getMessage();
    }

    public static function getField(Throwable $exception): ?string
    {
        if ($exception instanceof ErrorResponseThrowable) {
            return $exception->container->field;
        }

        return null;
    }

    public static function isValidationError(Throwable $exception): bool
    {
        return $exception instanceof ErrorResponseThrowable && $exception->container->status === 422;
    }
}
