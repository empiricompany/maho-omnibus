<?php

/**
 * Products whose crossed-out reference price is higher than the lowest price of
 * the reference period, as an API resource.
 *
 * Every HTTP operation of this resource becomes an MCP tool, so the admin
 * assistant can answer "which products do not comply with the Omnibus directive"
 * without a custom tool.
 *
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

namespace MM\Omnibus\Api;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\GetCollection;
use Maho\Config\ApiResource;

#[ApiResource(
    mahoId: 'omnibus-non-compliant-products',
    mahoLabel: 'Omnibus Non-Compliant Products',
    mahoSection: 'Catalog',
    mahoOperations: ['read' => 'View'],
    security: "is_granted('ROLE_ADMIN') or is_granted('omnibus-non-compliant-products/read')",
    shortName: 'NonCompliantProduct',
    description: 'Products that do not comply with the EU Omnibus Directive: the crossed-out reference price is higher '
        . 'than the lowest price applied in the reference period (30 days by default). Each row gives the reference '
        . 'price, the lowest price with its date, the difference and how many days are left before the lowest price '
        . 'record exits the window. Data comes from the compliance index, rebuilt after every product save and every '
        . 'day by cron, so it can be a few hours old: computedAt says when.',
    provider: NonCompliantProductProvider::class,
    operations: [
        new GetCollection(
            uriTemplate: '/omnibus/non-compliant-products',
            security: "is_granted('ROLE_ADMIN') or is_granted('omnibus-non-compliant-products/read')",
            description: 'List the non-compliant products, biggest violation first. '
                . 'Query: websiteId (default all websites), minDifference (only rows whose difference is at least this '
                . 'amount), page and itemsPerPage (default 100, max 500). daysUntilExpiry is negative when the lowest '
                . 'price record is about to exit the window.',
        ),
    ],
)]
class NonCompliantProduct extends \Maho\ApiPlatform\Resource
{
    public const ADMIN_RESOURCE = 'catalog/mm_omnibus';

    #[ApiProperty(identifier: true, description: 'Product ID')]
    public int $productId = 0;

    #[ApiProperty(description: 'Product SKU')]
    public string $sku = '';

    #[ApiProperty(description: 'Product name')]
    public string $name = '';

    #[ApiProperty(description: 'SKU of the configurable parent, empty for a standalone product')]
    public string $parentSku = '';

    #[ApiProperty(description: 'Website ID the comparison belongs to')]
    public int $websiteId = 0;

    #[ApiProperty(description: 'Website name')]
    public string $website = '';

    #[ApiProperty(description: 'Crossed-out reference price currently shown to customers')]
    public float $referencePrice = 0.0;

    #[ApiProperty(description: 'Lowest price recorded in the reference period')]
    public float $lowestPrice = 0.0;

    #[ApiProperty(description: 'When the lowest price was recorded (UTC)')]
    public ?string $lowestPriceDate = null;

    #[ApiProperty(description: 'How much the reference price exceeds the lowest price')]
    public float $difference = 0.0;

    #[ApiProperty(description: 'Days before the lowest price record exits the reference window')]
    public int $daysUntilExpiry = 0;

    #[ApiProperty(description: 'When the compliance index row was computed (UTC)')]
    public string $computedAt = '';
}
