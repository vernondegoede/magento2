<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Model\Client;

use GuzzleHttp\Exception\GuzzleException;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\OrderRepository;
use Mollie\Api\Client;
use Mollie\Api\Models\Components\PaymentResponse as MolliePayment;
use Mollie\Api\Models\Components\PaymentResponseStatus;
use Mollie\Api\Models\Errors\APIException;
use Mollie\Api\Models\Errors\ErrorResponseThrowable;
use Mollie\Api\Models\Operations\CreatePaymentRequest;
use Mollie\Api\Models\Operations\GetPaymentRequest;
use Mollie\Payment\Exceptions\PaymentAborted;
use Mollie\Payment\Helper\General as MollieHelper;
use Mollie\Payment\Model\Client\Payments\ProcessTransaction;
use Mollie\Payment\Service\Mollie\ApiExceptionHelper;
use Mollie\Payment\Service\Mollie\BuildPaymentRequest;
use Mollie\Payment\Service\Mollie\IdempotencyKeyGenerator;
use Mollie\Payment\Service\Mollie\Order\LinkTransactionToOrder;
use Mollie\Payment\Service\Mollie\PaymentResponseHelper;
use Mollie\Payment\Service\Mollie\TransactionDescription;
use Mollie\Payment\Service\Order\BuildTransaction;
use Mollie\Payment\Service\Order\MethodCode;
use Mollie\Payment\Service\Order\Transaction;
use Mollie\Payment\Service\PaymentToken\PaymentTokenForOrder;
use Throwable;

class Payments
{
    public const CHECKOUT_TYPE = 'payment';
    public const TRANSACTION_TYPE_WEBHOOK = 'webhook';
    public const TRANSACTION_TYPE_SUBSCRIPTION = 'subscription';

    public function __construct(
        private OrderRepository $orderRepository,
        private MollieHelper $mollieHelper,
        private BuildTransaction $buildTransaction,
        private Transaction $transaction,
        private TransactionDescription $transactionDescription,
        private PaymentTokenForOrder $paymentTokenForOrder,
        private EventManager $eventManager,
        private LinkTransactionToOrder $linkTransactionToOrder,
        private ProcessTransaction $processTransaction,
        private MethodCode $methodCode,
        private BuildPaymentRequest $buildPaymentRequest,
        private IdempotencyKeyGenerator $idempotencyKeyGenerator,
    ) {}

    /**
     * @throws APIException
     * @throws ErrorResponseThrowable
     * @throws PaymentAborted
     */
    public function startTransaction(OrderInterface $order, Client $mollieApi): ?string
    {
        $storeId = storeId($order->getStoreId());
        $orderId = $order->getEntityId();

        $transactionId = $order->getMollieTransactionId();
        if (
            !empty($transactionId) &&
            substr($transactionId, 0, 4) != 'ord_' &&
            $checkoutUrl = $this->getCheckoutUrl($mollieApi, $transactionId)
        ) {
            return $checkoutUrl;
        }

        $paymentToken = $this->paymentTokenForOrder->execute($order);
        $method = $this->methodCode->execute($order);

        $paymentData = [
            'amount' => $this->mollieHelper->getOrderAmountByOrder($order),
            'description' => $this->transactionDescription->forRegularTransaction($order),
            'billingAddress' => $this->getAddressLine($order->getBillingAddress()),
            'redirectUrl' => $this->transaction->getRedirectUrl($order, $paymentToken),
            'webhookUrl' => $this->transaction->getWebhookUrl([$order]),
            'method' => $method,
            'metadata' => [
                'order_id' => $orderId,
                'store_id' => storeId($order->getStoreId()),
                'payment_token' => $paymentToken,
            ],
            'locale' => $this->mollieHelper->getLocaleCode($storeId),
        ];

        if (!$order->getIsVirtual() && $order->hasData('shipping_address_id')) {
            $paymentData['shippingAddress'] = $this->getAddressLine($order->getShippingAddress());
        }

        if ($method == 'banktransfer') {
            $paymentData['dueDate'] = $this->mollieHelper->getBanktransferDueDate($storeId);
        }

        if (!$paymentData['billingAddress']['email']) {
            $paymentData['billingAddress']['email'] = $order->getCustomerEmail();
        }

        $paymentData = $this->buildTransaction->execute($order, $paymentData);

        $this->mollieHelper->addTolog('request', $paymentData);

        $payment = $this->createPayment($order, $mollieApi, $paymentData);

        $this->processResponse($order, $payment);

        // Order is paid immediately (eg. Credit Card with Components, Apple Pay), process transaction
        if (PaymentResponseHelper::isAuthorized($payment) || PaymentResponseHelper::isPaid($payment)) {
            $this->processTransaction->execute($order, static::TRANSACTION_TYPE_WEBHOOK);
        }

        return PaymentResponseHelper::getCheckoutUrl($payment);
    }

    private function createPayment(OrderInterface $order, Client $mollieApi, array $paymentData): MolliePayment
    {
        try {
            $response = $mollieApi->payments->create(
                new CreatePaymentRequest(
                    idempotencyKey: $this->idempotencyKeyGenerator->generate(),
                    body: $this->buildPaymentRequest->execute($paymentData),
                ),
            );

            return $response->paymentResponse;
        } catch (APIException|ErrorResponseThrowable|GuzzleException $exception) {
            $this->logFailedPaymentCreation($order, $paymentData, $exception);

            throw $exception;
        }
    }

    private function logFailedPaymentCreation(
        OrderInterface $order,
        array $paymentData,
        Throwable $exception,
    ): void {
        $this->mollieHelper->addTolog('error', [
            'message' => 'Payment creation failed',
            'order' => $order->getIncrementId(),
            'store_id' => $order->getStoreId(),
            'amount' => $paymentData['amount'],
            'exception' => ApiExceptionHelper::getMessage($exception),
        ]);
    }

    public function getAddressLine(OrderAddressInterface $address): array
    {
        // Phone is added in \Mollie\Payment\Service\Order\TransactionPart\PhoneNumber

        $output = [
            'givenName' => $address->getFirstname(),
            'familyName' => $address->getLastname(),
            'organizationName' => $address->getCompany(),
            'streetAndNumber' => rtrim(implode(' ', $address->getStreet()), ' '),
            'postalCode' => $address->getPostcode(),
            'email' => $address->getEmail(),
            'city' => $address->getCity(),
            'region' => $address->getRegion(),
            'country' => $address->getCountryId(),
        ];

        if ($address->getAddressType() == Address::TYPE_BILLING) {
            $output['givenName'] = $address->getFirstname();
            $output['familyName'] = $address->getLastname();
        }

        return $output;
    }

    public function processResponse(OrderInterface $order, MolliePayment $payment): void
    {
        $eventData = [
            'order' => $order,
            'mollie_payment' => $payment,
        ];

        $this->eventManager->dispatch('mollie_process_response', $eventData);
        $this->eventManager->dispatch('mollie_process_response_payments_api', $eventData);
        $this->mollieHelper->addTolog('response', $payment);

        // The order is canceled before but now restarted, so uncancel the order.
        if ($order->getState() == Order::STATE_CANCELED) {
            $this->mollieHelper->uncancelOrder($order);
        }

        $order->getPayment()->setAdditionalInformation('checkout_url', PaymentResponseHelper::getCheckoutUrl($payment));
        $order->getPayment()->setAdditionalInformation('checkout_type', self::CHECKOUT_TYPE);
        $order->getPayment()->setAdditionalInformation('payment_status', $payment->status->value);
        if ($payment->expiresAt !== null) {
            $order->getPayment()->setAdditionalInformation('expires_at', $payment->expiresAt);
        }

        if ($payment->links->changePaymentState?->href !== null) {
            $order->getPayment()->setAdditionalInformation(
                'mollie_change_payment_state_url',
                $payment->links->changePaymentState->href,
            );
        }

        $message = __('Customer redirected to Mollie');
        if ($order->getPayment()->getMethodInstance()->getCode() == 'mollie_methods_paymentlink') {
            $message = __('Created Mollie Checkout Url');
        }

        $status = $this->mollieHelper->getPendingPaymentStatus($order);
        $order->addStatusToHistory($status, $message, false);
        $order->setState(Order::STATE_PENDING_PAYMENT);
        $this->linkTransactionToOrder->execute($payment->id, $order);
        $this->orderRepository->save($order);
    }

    public function orderHasUpdate(OrderInterface $order, Client $mollieApi): bool
    {
        if (in_array($order->getState(), [Order::STATE_COMPLETE, Order::STATE_CLOSED])) {
            return false;
        }

        $transactionId = $order->getMollieTransactionId();
        if ($transactionId === null || $transactionId === '') {
            return false;
        }

        $paymentData = $mollieApi->payments->get(new GetPaymentRequest(paymentId: $transactionId))->paymentResponse;
        if ($paymentData === null) {
            return false;
        }

        $expectedStatus = match ($paymentData->status) {
            PaymentResponseStatus::Open => Order::STATE_NEW,
            PaymentResponseStatus::Pending => Order::STATE_PENDING_PAYMENT,
            PaymentResponseStatus::Authorized, PaymentResponseStatus::Paid => Order::STATE_PROCESSING,
            PaymentResponseStatus::Canceled, PaymentResponseStatus::Failed => Order::STATE_CANCELED,
            PaymentResponseStatus::Expired => Order::STATE_CLOSED,
        };

        return $expectedStatus != $order->getState();
    }

    private function getCheckoutUrl(Client $mollieApi, ?string $transactionId): ?string
    {
        if ($transactionId === null) {
            return null;
        }

        $payment = $mollieApi->payments->get(new GetPaymentRequest(paymentId: $transactionId))->paymentResponse;
        if ($payment->status === PaymentResponseStatus::Paid) {
            throw new PaymentAborted(__('This order already has been paid.'));
        }

        return PaymentResponseHelper::getCheckoutUrl($payment);
    }
}
