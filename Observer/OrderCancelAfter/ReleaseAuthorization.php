<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Observer\OrderCancelAfter;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Mollie\Api\Models\Errors\APIException;
use Mollie\Api\Models\Errors\ErrorResponseThrowable;
use Mollie\Api\Models\Operations\ReleaseAuthorizationRequest;
use Mollie\Payment\Logger\MollieLogger;
use Mollie\Payment\Service\Mollie\ApiExceptionHelper;
use Mollie\Payment\Service\Mollie\IdempotencyKeyGenerator;
use Mollie\Payment\Service\Mollie\MollieApiClient;
use Mollie\Payment\Service\Mollie\Order\CanUseManualCapture;
use Mollie\Payment\Service\Mollie\Order\IsAuthorizationNeededByOtherOrders;

class ReleaseAuthorization implements ObserverInterface
{
    public function __construct(
        private MollieApiClient $mollieApiClient,
        private CanUseManualCapture $canUseManualCapture,
        private MollieLogger $logger,
        private ManagerInterface $messageManager,
        private IsAuthorizationNeededByOtherOrders $isAuthorizationNeededByOtherOrders,
        private IdempotencyKeyGenerator $idempotencyKeyGenerator,
    ) {}

    public function execute(Observer $observer)
    {
        /** @var OrderInterface $order */
        $order = $observer->getEvent()->getOrder();

        if (!$this->canUseManualCapture->execute($order)) {
            return;
        }

        if ($order->getBaseTotalInvoiced() >= $order->getBaseGrandTotal()) {
            return;
        }

        $mollieTransactionId = $order->getMollieTransactionId();
        if ($mollieTransactionId === null) {
            return;
        }

        if ($this->isAuthorizationNeededByOtherOrders->execute($order)) {
            $order->addCommentToStatusHistory(
                __(
                    'The Mollie payment authorization (%1) was not released, as other orders paid with the same ' .
                    'payment still have to be captured.',
                    $mollieTransactionId,
                ),
            );

            return;
        }

        $mollieApi = $this->mollieApiClient->loadByStore(storeId($order->getStoreId()));

        try {
            $mollieApi->payments->releaseAuthorization(
                new ReleaseAuthorizationRequest(
                    paymentId: $mollieTransactionId,
                    idempotencyKey: $this->idempotencyKeyGenerator->generate(),
                ),
            );
        } catch (APIException|ErrorResponseThrowable $exception) {
            $errorMessage = ApiExceptionHelper::getMessage($exception);
            $this->logger->addErrorLog(
                'release_authorization',
                'Could not release authorization for ' . $mollieTransactionId . ': ' . $errorMessage
            );

            $this->messageManager->addWarningMessage(
                __(
                    'The order was canceled, but the remaining Mollie payment authorization (%1) could not be ' .
                    'released automatically: %2. Any uncaptured amount is released by Mollie when the authorization ' .
                    'expires.',
                    $mollieTransactionId,
                    $errorMessage
                )
            );
        }
    }
}
