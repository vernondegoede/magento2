<?php
/*
 * Copyright Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Mollie\Payment\Service\Mollie\Express;

use Mollie\Api\Models\Components\Amount;
use Mollie\Api\Models\Components\PaymentLineTypeResponse;
use Mollie\Api\Models\Components\PaymentResponseLine;
use Mollie\Api\Models\Components\RecurringLineItem;

/**
 * Extends PaymentResponseLine to carry the Express Components beta `reference` field returned on shipping_fee lines.
 */
class ExpressPaymentResponseLine extends PaymentResponseLine
{
    public ?string $reference = null;

    /**
     * @param ?array<\Mollie\Api\Models\Components\LineCategoriesResponse> $categories
     */
    public function __construct(
        string $description,
        int $quantity,
        Amount $unitPrice,
        Amount $totalAmount,
        ?PaymentLineTypeResponse $type = null,
        ?string $quantityUnit = null,
        ?Amount $discountAmount = null,
        ?string $vatRate = null,
        ?Amount $vatAmount = null,
        ?string $sku = null,
        ?array $categories = null,
        ?string $imageUrl = null,
        ?string $productUrl = null,
        ?RecurringLineItem $recurring = null,
        ?string $reference = null,
    ) {
        parent::__construct(
            description: $description,
            quantity: $quantity,
            unitPrice: $unitPrice,
            totalAmount: $totalAmount,
            type: $type,
            quantityUnit: $quantityUnit,
            discountAmount: $discountAmount,
            vatRate: $vatRate,
            vatAmount: $vatAmount,
            sku: $sku,
            categories: $categories,
            imageUrl: $imageUrl,
            productUrl: $productUrl,
            recurring: $recurring,
        );
        $this->reference = $reference;
    }

    public static function fromPaymentResponseLine(PaymentResponseLine $line, ?string $reference): self
    {
        return new self(
            description: $line->description,
            quantity: $line->quantity,
            unitPrice: $line->unitPrice,
            totalAmount: $line->totalAmount,
            type: $line->type,
            quantityUnit: $line->quantityUnit,
            discountAmount: $line->discountAmount,
            vatRate: $line->vatRate,
            vatAmount: $line->vatAmount,
            sku: $line->sku,
            categories: $line->categories,
            imageUrl: $line->imageUrl,
            productUrl: $line->productUrl,
            recurring: $line->recurring,
            reference: $reference,
        );
    }
}
