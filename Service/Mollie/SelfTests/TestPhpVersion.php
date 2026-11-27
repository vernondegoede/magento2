<?php

/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Service\Mollie\SelfTests;

class TestPhpVersion extends AbstractSelfTest
{
    public const MIN_PHP_VERSION = '8.2.0';

    public function execute(): void
    {
        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>=')) {
            $message = __('Success: PHP version: %1.', PHP_VERSION);
            $this->addMessage('success', $message);

            return;
        }

        $message = __('Error: The client requires PHP version >= %1, you have %2.', self::MIN_PHP_VERSION, PHP_VERSION);
        $this->addMessage('error', $message);
    }
}
