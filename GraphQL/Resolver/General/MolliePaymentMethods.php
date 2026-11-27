<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\GraphQL\Resolver\General;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Api\Data\CartInterfaceFactory;
use Mollie\Api\Models\Components\MethodStatus;
use Mollie\Payment\Config;
use Mollie\Payment\Service\Mollie\ListMethods;
use Mollie\Payment\Service\Mollie\MethodParameters;
use Mollie\Payment\Service\Mollie\MollieApiClient;

class MolliePaymentMethods implements ResolverInterface
{
    public function __construct(
        private MollieApiClient $mollieApiClient,
        private MethodParameters $methodParameters,
        private CartInterfaceFactory $cartFactory,
        private Config $config,
        private ListMethods $listMethods,
    ) {}

    /**
     * @param array<string, mixed>|null $value
     * @param array<string, mixed>|null $args
     * @return array<string, mixed>
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $amount = 10;
        $currency = null;

        if (isset($args['input'], $args['input']['amount'])) {
            $amount = $args['input']['amount'];
        }

        if (isset($args['input'], $args['input']['currency'])) {
            $currency = $args['input']['currency'];
        }

        $storeId = storeId($context->getExtensionAttributes()->getStore()->getId());
        $apiMethods = $this->getMethods($amount, $currency, $storeId);

        $methods = [];
        foreach ($apiMethods as $method) {
            $methodId = $method['id'] ?? null;
            if (!is_string($methodId) || $methodId === '' || !$this->config->isMethodActive($methodId, $storeId)) {
                continue;
            }

            $methods[] = [
                'code' => $methodId,
                'name' => $this->config->getMethodTitle($methodId, $storeId),
                'image' => $method['image']['svg'] ?? '',
            ];
        }

        usort($methods, function (array $a, array $b): int {
            // Lowercase as iDEAL would be sorted last because of the lower I.
            return strtolower($a['name']) <=> strtolower($b['name']);
        });

        return [
            'methods' => $methods,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getMethods(float $amount, ?string $currency, int $storeId): array
    {
        $mollieApiClient = $this->mollieApiClient->loadByStore($storeId);

        if ($currency === null) {
            $available = $this->listMethods->execute($mollieApiClient, [
                'includeWallets' => ListMethods::WALLETS,
            ]);

            return array_values(array_filter(
                $available,
                static fn (array $method): bool => ($method['status'] ?? null) === MethodStatus::Activated->value,
            ));
        }

        $parameters = [
            'amount' => [
                'value' => number_format($amount, 2, '.', ''),
                'currency' => $currency,
            ],
            'includeWallets' => ListMethods::WALLETS,
        ];

        return $this->listMethods->execute(
            $mollieApiClient,
            $this->methodParameters->enhance($parameters, $this->cartFactory->create()),
        );
    }
}
