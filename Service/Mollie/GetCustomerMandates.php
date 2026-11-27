<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Service\Mollie;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Mollie\Api\Models\Components\ListMandateResponseStatus;
use Mollie\Api\Models\Components\MandateMethodResponse;
use Mollie\Api\Models\Components\MandateScopes;
use Mollie\Api\Models\Operations\ListMandatesRequest;

class GetCustomerMandates
{
    public function __construct(
        private CustomerRepositoryInterface $customerRepository,
        private MollieApiClient $mollieApiClient,
    ) {}

    public function execute(int $customerId, ?int $storeId = null): array
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException) {
            return [];
        }

        $mollieCustomerId = $customer->getExtensionAttributes()?->getMollieCustomerId();
        if (!$mollieCustomerId) {
            return [];
        }

        $api = $this->mollieApiClient->loadByStore($storeId);
        $mandates = [];

        $request = new ListMandatesRequest(
            customerId: $mollieCustomerId,
            scopes: [MandateScopes::CustomerPresent],
        );

        foreach ($api->mandates->list($request) as $page) {
            foreach ($page->object?->embedded->mandates ?? [] as $mandate) {
                if (
                    $mandate->method !== MandateMethodResponse::Creditcard
                    || $mandate->status !== ListMandateResponseStatus::Valid
                ) {
                    continue;
                }

                $details = $mandate->details;
                $mandates[] = [
                    'mandate_id' => $mandate->id,
                    'card_label' => $details->cardLabel?->value ?? '',
                    'card_number_last4' => $details->cardNumber ?? '',
                    'card_expiry_date' => $details->cardExpiryDate,
                    'card_holder' => $details->cardHolder,
                ];
            }
        }

        return $mandates;
    }
}
