<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Model;

use Exception;
use Magento\Checkout\Model\Session;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Asset\Repository;
use Magento\Payment\Gateway\Command\CommandManagerInterface;
use Magento\Payment\Gateway\Command\CommandPoolInterface;
use Magento\Payment\Gateway\Config\ValueHandlerPoolInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectFactory;
use Magento\Payment\Gateway\Validator\ValidatorPoolInterface;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\Adapter;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\OrderRepository;
use Mollie\Api\Client;
use Mollie\Api\Models\Components\Amount;
use Mollie\Api\Models\Components\MethodId;
use Mollie\Api\Models\Components\RefundRequest;
use Mollie\Api\Models\Errors\APIException;
use Mollie\Api\Models\Errors\ErrorResponseThrowable;
use Mollie\Api\Models\Operations\CreateRefundRequest;
use Mollie\Api\Models\Operations\GetMethodRequest;
use Mollie\Payment\Config;
use Mollie\Payment\Helper\General;
use Mollie\Payment\Model\Client\Payments;
use Mollie\Payment\Model\Client\Payments\ProcessTransaction;
use Mollie\Payment\Model\Client\ProcessTransactionResponse;
use Mollie\Payment\Service\Mollie\ApiExceptionHelper;
use Mollie\Payment\Service\Mollie\IdempotencyKeyGenerator;
use Mollie\Payment\Service\Mollie\Order\ResolvePaymentId;
use Mollie\Payment\Service\OrderLockService;
use Psr\Log\LoggerInterface;

/**
 * Class Mollie
 *
 * @package Mollie\Payment\Model
 */
class Mollie extends Adapter
{
    public const CODE = 'mollie';
    private ManagerInterface $eventManager;
    private IdempotencyKeyGenerator $idempotencyKeyGenerator;

    public function __construct(
        ManagerInterface $eventManager,
        ValueHandlerPoolInterface $valueHandlerPool,
        PaymentDataObjectFactory $paymentDataObjectFactory,
        private OrderRepository $orderRepository,
        private Payments $paymentsApi,
        private General $mollieHelper,
        private Session $checkoutSession,
        private Repository $assetRepository,
        protected Config $config,
        private ProcessTransaction $paymentsProcessTransaction,
        private OrderLockService $orderLockService,
        private \Mollie\Payment\Service\Mollie\MollieApiClient $mollieApiClient,
        private ResolvePaymentId $resolvePaymentId,
        $formBlockType,
        $infoBlockType,
        ?CommandPoolInterface $commandPool = null,
        ?ValidatorPoolInterface $validatorPool = null,
        ?CommandManagerInterface $commandExecutor = null,
        ?LoggerInterface $logger = null,
        ?IdempotencyKeyGenerator $idempotencyKeyGenerator = null,
    ) {
        parent::__construct(
            $eventManager,
            $valueHandlerPool,
            $paymentDataObjectFactory,
            static::CODE,
            $formBlockType,
            $infoBlockType,
            $commandPool,
            $validatorPool,
            $commandExecutor,
            $logger,
        );
        $this->eventManager = $eventManager;
        $this->idempotencyKeyGenerator = $idempotencyKeyGenerator ?? new IdempotencyKeyGenerator();
    }

    /**
     * @param DataObject $data
     *
     * @return $this
     * @throws LocalizedException
     */
    public function assignData(DataObject $data)
    {
        parent::assignData($data);

        $additionalData = $data->getAdditionalData();
        if (isset($additionalData['applepay_payment_token'])) {
            $this->getInfoInstance()->setAdditionalInformation('applepay_payment_token', $additionalData['applepay_payment_token']);
        }

        if (isset($additionalData['card_token'])) {
            $this->getInfoInstance()->setAdditionalInformation('card_token', $additionalData['card_token']);
        }

        if (isset($additionalData['selected_issuer'])) {
            $this->getInfoInstance()->setAdditionalInformation('selected_issuer', $additionalData['selected_issuer']);
        }

        if (isset($additionalData['selected_terminal'])) {
            $this->getInfoInstance()->setAdditionalInformation('selected_terminal', $additionalData['selected_terminal']);
        }

        if (isset($additionalData['mollie_save_card']) && $additionalData['mollie_save_card'] === '1') {
            $this->getInfoInstance()->setAdditionalInformation('mollie_save_card', true);
        }

        if (isset($additionalData['mollie_mandate_id']) && $additionalData['mollie_mandate_id'] !== '') {
            $this->getInfoInstance()->setAdditionalInformation('mollie_mandate_id', (string)$additionalData['mollie_mandate_id']);
        }

        if (isset($additionalData['mollie_consent_timestamp']) && $additionalData['mollie_consent_timestamp'] !== '') {
            $this->getInfoInstance()->setAdditionalInformation('mollie_consent_timestamp', (string)$additionalData['mollie_consent_timestamp']);
        }

        return $this;
    }

    public function getCode()
    {
        return static::CODE;
    }

    /**
     * Get list of Issuers from API
     */
    public function getIssuers(string $method, string $issuerListType, int $count = 0): ?array
    {
        $issuers = [];
        // iDEAL 2.0 does not have issuers anymore.
        if ($issuerListType == 'none' || $method == 'mollie_methods_ideal') {
            return $issuers;
        }

        $mollieApi = $this->mollieApiClient->loadByStore();
        $methodCode = str_replace('mollie_methods_', '', $method);
        $methodId = MethodId::tryFrom($methodCode);
        if ($methodId === null) {
            return null;
        }

        try {
            $response = $mollieApi->methods->get(
                new GetMethodRequest(methodId: $methodId, include: 'issuers'),
            );
            $issuers = $response->entityMethodGet?->issuers;

            // If the list can't be retrieved for some reason, try again.
            if (!$issuers && $count == 0) {
                $this->mollieHelper->addTolog(
                    'error',
                    'Retrieving method issuers gave an issue. Retrying.' . var_export($issuers, true),
                );

                return $this->getIssuers($method, $issuerListType, 1);
            }

            if (!$issuers) {
                return null;
            }
        } catch (Exception $e) {
            $this->mollieHelper->addTolog('error', ApiExceptionHelper::getMessage($e));
        }

        if ($this->mollieHelper->addQrOption() && $methodCode == 'ideal') {
            $issuers[] = [
                'resource' => 'issuer',
                'id' => '',
                'name' => __('QR Code'),
                'image' => [
                    'size2x' => $this->assetRepository->getUrlWithParams(
                        'Mollie_Payment::images/qr-select.svg',
                        ['area' => 'frontend'],
                    ),
                    'svg' => $this->assetRepository->getUrlWithParams(
                        'Mollie_Payment::images/qr-select.svg',
                        ['area' => 'frontend'],
                    ),
                ],
            ];
        }

        // Sort the list by name
        uasort($issuers, function ($a, $b): int {
            $a = (array)$a;
            $b = (array)$b;

            return strcmp(strtolower($a['name']), strtolower($b['name']));
        });

        if ($issuers && $issuerListType == 'dropdown') {
            array_unshift($issuers, [
                'resource' => 'issuer',
                'id' => '',
                'name' => __('-- Please Select --'),
            ]);
        }

        return array_values($issuers);
    }

    /**
     * @param $storeId
     * @return Client|null
     */
    public function getMollieApi($storeId = null): ?Client
    {
        $apiKey = $this->mollieHelper->getApiKey($storeId);

        try {
            return $this->loadMollieApi($apiKey);
        } catch (Exception $e) {
            $this->mollieHelper->addTolog('error', ApiExceptionHelper::getMessage($e));

            return null;
        }
    }

    /**
     * @param string $paymentAction
     * @param object $stateObject
     *
     * @return $this
     * @throws LocalizedException
     */
    public function initialize($paymentAction, $stateObject): static
    {
        /** @var Payment $payment */
        $payment = $this->getInfoInstance();

        /** @var Order $order */
        $order = $payment->getOrder();
        $order->setCanSendNewEmailFlag(false);

        $status = $this->mollieHelper->getStatusPending(storeId($order->getStoreId()));
        $stateObject->setState(Order::STATE_NEW);
        $stateObject->setStatus($status);
        $stateObject->setIsNotified(false);

        return $this;
    }

    /**
     * Extra checks for method availability
     *
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function isAvailable(?CartInterface $quote = null): bool
    {
        if ($quote == null) {
            $quote = $this->checkoutSession->getQuote();
        }

        $storeId = storeId($quote->getStoreId());
        if (!$this->mollieHelper->isAvailable($storeId)) {
            return false;
        }

        if ($quote->getIsMultiShipping() && !$this->config->isMultishippingEnabled()) {
            return false;
        }

        $activeMethods = $this->mollieHelper->getAllActiveMethods($storeId);
        if ($this::CODE != 'mollie_methods_paymentlink' && !array_key_exists($this::CODE, $activeMethods)) {
            return false;
        }

        return parent::isAvailable($quote);
    }

    /**
     * @param string $apiKey
     *
     * @return Client
     * @throws LocalizedException
     *
     * @deprecated Use \Mollie\Payment\Service\Mollie\MollieApiClient::loadByApiKey instead
     */
    public function loadMollieApi(string $apiKey): Client
    {
        return $this->mollieApiClient->loadByApiKey($apiKey);
    }

    public function orderHasUpdate(string $orderId): bool
    {
        $order = $this->orderRepository->get($orderId);

        if (!$order->getMollieTransactionId()) {
            throw new LocalizedException(__('Transaction ID not found'));
        }

        $mollieApi = $this->mollieApiClient->loadByStore(storeId($order->getStoreId()));

        return $this->paymentsApi->orderHasUpdate($order, $mollieApi);
    }

    /**
     * @param        $orderId
     * @param string $type
     *
     * @return ProcessTransactionResponse
     * @throws LocalizedException
     * @throws APIException
     * @throws ErrorResponseThrowable
     */
    public function processTransaction($orderId, string $type = 'webhook'): ProcessTransactionResponse
    {
        /** @var Order $order */
        $order = $this->orderRepository->get($orderId);

        return $this->processTransactionForOrder($order, $type);
    }

    public function processTransactionForOrder(
        OrderInterface $order,
        string $type = 'webhook',
    ): ProcessTransactionResponse
    {
        $this->eventManager->dispatch('mollie_process_transaction_start', ['order' => $order]);

        $output = $this->orderLockService->execute($order, function (OrderInterface $order) use ($type): array {
            $result = $this->paymentsProcessTransaction->execute($order, $type);

            $order->getPayment()->setAdditionalInformation('mollie_success', $result->isSuccess());

            // Return the order and the result so we can use this outside this closure.
            return [
                'order' => $order,
                'result' => $result,
            ];
        });

        // Extract the contents of the closure.
        $order = $output['order'];
        $result = $output['result'];

        $this->eventManager->dispatch('mollie_process_transaction_end', ['order' => $order]);

        return $result;
    }

    /**
     * @param float $amount
     *
     * @return $this
     * @throws LocalizedException
     */
    public function refund(InfoInterface $payment, $amount): self
    {
        // The refund has been issue by an external system, eg the Mollie dashboard.
        if ($payment->getCreditmemo() && $payment->getCreditmemo()->getMollieTransactionId()) {
            $refundId = $payment->getCreditmemo()->getMollieTransactionId();

            $payment->setTransactionId($refundId);
            $payment->setLastTransId($refundId);
            return $this;
        }

        /** @var Order $order */
        $order = $payment->getOrder();
        $storeId = storeId($order->getStoreId());

        $transactionId = $order->getMollieTransactionId();
        if (empty($transactionId)) {
            throw new LocalizedException(__('Transaction ID not found'));
        }

        $apiKey = $this->mollieHelper->getApiKey($storeId);
        if (empty($apiKey)) {
            throw new LocalizedException(__('Api key not found'));
        }

        try {
            // The provided $amount is in the currency of the default store. If we are not using the base currency,
            // Get the order amount in the currency of the order.
            if (!$this->config->useBaseCurrency(storeId($order->getStoreId()))) {
                $amount = $payment->getCreditMemo()->getGrandTotal();
            }

            if (is_string($amount)) {
                $amount = (float)$amount;
            }

            $mollieApi = $this->loadMollieApi($apiKey);
            $transactionId = $this->resolvePaymentId->execute($mollieApi, $transactionId);

            $refund = $mollieApi->refunds->create(
                new CreateRefundRequest(
                    paymentId: $transactionId,
                    idempotencyKey: $this->idempotencyKeyGenerator->generate(),
                    body: new RefundRequest(
                        amount: new Amount(
                            currency: $order->getOrderCurrencyCode(),
                            value: $this->mollieHelper->formatCurrencyValue($amount, $order->getOrderCurrencyCode()),
                        ),
                        description: (string) __('Refund for order %1', $order->getIncrementId()),
                    ),
                ),
            )->refundResponse;

            $order->getPayment()->setTransactionId($refund->id);
            $order->getPayment()->setLastTransId($refund->id);
        } catch (Exception $exception) {
            $errorMessage = ApiExceptionHelper::getMessage($exception);
            $this->mollieHelper->addTolog('error', $errorMessage);
            throw new LocalizedException(__('Error: not possible to create an online refund: %1', $errorMessage));
        }

        return $this;
    }
}
