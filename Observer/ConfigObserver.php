<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Observer;

use Exception;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Mollie\Api\Client;
use Mollie\Api\Models\Components\MethodStatus;
use Mollie\Payment\Config;
use Mollie\Payment\Helper\General as MollieHelper;
use Mollie\Payment\Model\Methods\Directdebit;
use Mollie\Payment\Model\Methods\Expresscomponents;
use Mollie\Payment\Model\Methods\GooglePay;
use Mollie\Payment\Model\Methods\Pointofsale;
use Mollie\Payment\Service\Mollie\ApiExceptionHelper;
use Mollie\Payment\Service\Mollie\ListMethods;
use Mollie\Payment\Service\Mollie\MollieApiClient;

/**
 * Class ConfigObserver
 *
 * @package Mollie\Payment\Observer
 */
class ConfigObserver implements ObserverInterface
{
    public function __construct(
        private ManagerInterface $messageManager,
        private MollieHelper $mollieHelper,
        private MollieApiClient $mollieApiClient,
        private Config $config,
        private ListMethods $listMethods,
    ) {}

    /**
     * @param EventObserver $observer
     */
    public function execute(EventObserver $observer): void
    {
        $storeId = storeId($observer->getStore());

        $enabled = $this->mollieHelper->isAvailable($storeId);
        $modus = $this->mollieHelper->getModus($storeId);
        if ($enabled && $modus) {
            $this->validatePaymentMethods($storeId, $modus);
        }
    }

    /**
     * Validate Magento config values against Mollie API
     *
     * @param $storeId
     * @param $modus
     *
     * @return void
     */
    public function validatePaymentMethods(?int $storeId, string $modus): void
    {
        if (!class_exists(Client::class)) {
            $error = $this->mollieHelper->getPhpApiErrorMessage(false);
            $this->mollieHelper->disableExtension();
            $this->mollieHelper->addTolog('error', $error);
            $this->messageManager->addErrorMessage($error);

            return;
        }

        if ($modus == 'test') {
            return;
        }

        try {
            $apiMethods = $this->listMethods->execute(
                $this->mollieApiClient->loadByStore($storeId),
                ['includeWallets' => ListMethods::WALLETS],
            );
        } catch (Exception $e) {
            $errorMessage = ApiExceptionHelper::getMessage($e);
            $this->mollieHelper->addTolog('error', $errorMessage);
            $this->messageManager->addErrorMessage($errorMessage);

            return;
        }

        if ($apiMethods === []) {
            return;
        }

        $activeMethods = $this->mollieHelper->getAllActiveMethods($storeId);

        $methods = [];
        $apiMethods = array_filter(
            $apiMethods,
            static fn (array $method): bool => ($method['status'] ?? null) === MethodStatus::Activated->value,
        );

        foreach ($apiMethods as $apiMethod) {
            $id = $apiMethod['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $methods[$id] = $apiMethod;
            }
        }

        $disabledMethods = [];
        $doNotCheckMethods = [Pointofsale::CODE, Directdebit::CODE, GooglePay::CODE, Expresscomponents::CODE];
        foreach ($activeMethods as $method) {
            $code = $method['code'];
            if (!in_array('mollie_methods_' . $code, $doNotCheckMethods) && !isset($methods[$code])) {
                $disabledMethods[] = $this->config->getMethodTitle($code);
            }
        }

        if ($disabledMethods) {
            $this->messageManager->addComplexErrorMessage(
                'MollieUnavailableMethodsMessage',
                [
                    'methods' => $disabledMethods,
                ],
            );
        }
    }
}
