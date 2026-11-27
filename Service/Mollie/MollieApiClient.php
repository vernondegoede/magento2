<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Service\Mollie;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\Manager;
use Mollie\Api\Client;
use Mollie\Api\Models\Components\Security;
use Mollie\Payment\Config;
use Mollie\Payment\Service\Mollie\Wrapper\FallbackApiKeyClient;
use Mollie\Payment\Service\Mollie\Wrapper\FetchFallbackApiKeys;

class MollieApiClient
{
    /**
     * @var array<string, Client>
     */
    private array $instances = [];

    public function __construct(
        private Config $config,
        private FetchFallbackApiKeys $fetchFallbackApiKeys,
        private Manager $moduleManager,
        private CreateHttpAdapter $createHttpAdapter,
    ) {}

    public function loadByStore(?int $storeId = null): Client
    {
        if (!class_exists(Client::class)) {
            throw new LocalizedException(__('Class Mollie\Api\Client does not exist'));
        }

        return $this->loadByApiKey($this->config->getApiKey($storeId));
    }

    public function loadByApiKey(string $apiKey): Client
    {
        $apiKey = trim($apiKey);
        if (isset($this->instances[$apiKey])) {
            return $this->instances[$apiKey];
        }

        $httpClient = new FallbackApiKeyClient(
            $this->createHttpAdapter->execute(),
            $this->fetchFallbackApiKeys,
        );

        $mollieApiClient = Client::builder()
            ->setClient($httpClient)
            ->setSecurity(new Security(apiKey: $apiKey))
            ->setCustomUserAgent($this->buildCustomUserAgent())
            ->build();

        $this->instances[$apiKey] = $mollieApiClient;

        return $mollieApiClient;
    }

    /**
     * @throws LocalizedException
     */
    public function validateApiKey(string $apiKey): void
    {
        $apiKey = trim($apiKey);
        if (!preg_match('/^(live|test)_\w{30,}$/', $apiKey)) {
            throw new LocalizedException(
                __(
                    "Invalid API key: '%1'. An API key must start with 'test_' or 'live_' and must be at least 30 characters long.",
                    $apiKey,
                )
            );
        }
    }

    private function buildCustomUserAgent(): string
    {
        $parts = [
            'Magento/' . $this->config->getMagentoVersion(),
            'MagentoEdition/' . $this->config->getMagentoEdition(),
            'MollieMagento2/' . $this->config->getVersion(),
        ];

        if ($this->moduleManager->isEnabled('Hyva_Theme')) {
            $parts[] = 'HyvaTheme';
        }

        if ($this->moduleManager->isEnabled('Hyva_Checkout')) {
            $parts[] = 'HyvaCheckout';
        }

        return implode(' ', $parts);
    }
}
