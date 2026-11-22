<?php

/**
 * Single source of truth for the effective price:
 * catalog rule, then active special price, then base price.
 *
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

class MM_Omnibus_Model_Service_PriceCalculator
{
    /**
     * Rule price of a product for a customer group, a date and a website.
     *
     * The website filter matters: two websites can have different catalog rules.
     */
    public function getCatalogRulePrice(
        int $productId,
        int $customerGroupId = 0,
        ?string $date = null,
        ?int $websiteId = null,
    ): float {
        $adapter = Mage::helper('mm_omnibus')->readAdapter();
        $select = $adapter->select()
            ->from(Mage::getSingleton('core/resource')->getTableName('catalogrule/rule_product_price'), ['rule_price'])
            ->where('product_id = ?', $productId)
            ->where('rule_date = ?', $date ?? date('Y-m-d'))
            ->where('customer_group_id = ?', $customerGroupId);

        if ($websiteId !== null) {
            $select->where('website_id = ?', $websiteId);
        }

        $select->order('rule_price ASC')->limit(1);

        return (float) $adapter->fetchOne($select);
    }

    /**
     * Special price when its date range includes now, zero otherwise.
     */
    public function getActiveSpecialPrice(Mage_Catalog_Model_Product $product): float
    {
        $specialPrice = (float) $product->getSpecialPrice();
        if ($specialPrice <= 0) {
            return 0.0;
        }

        $now = strtotime(Mage_Core_Model_Locale::nowUtc());
        $fromDate = $product->getSpecialFromDate();
        $toDate = $product->getSpecialToDate();

        if ($fromDate) {
            $fromTimestamp = strtotime((string) $fromDate);
            if ($fromTimestamp !== false && $now < $fromTimestamp) {
                return 0.0;
            }
        }

        if ($toDate) {
            $toTimestamp = strtotime((string) $toDate);
            if ($toTimestamp !== false && $now > strtotime('+1 day', $toTimestamp)) {
                return 0.0;
            }
        }

        return $specialPrice;
    }

    /**
     * Regular price as the price box prints it: same currency conversion and same
     * tax configuration, so the amount added to the notice coincides with the
     * crossed-out price when the history has nothing cheaper to show.
     */
    public function getDisplayedReferencePrice(Mage_Catalog_Model_Product $product): float
    {
        $store = Mage::app()->getStore();
        if (!$store instanceof Mage_Core_Model_Store) {
            return 0.0;
        }

        $taxHelper = Mage::helper('tax');
        $price = $store->roundPrice($store->convertPrice((float) $product->getPrice()));
        $includeTax = $taxHelper->displayPriceIncludingTax() || $taxHelper->displayBothPrices();

        return (float) $taxHelper->getPrice($product, $price, $includeTax);
    }

    /**
     * Whether the last catalog rule record of a product differs from the current
     * rule price.
     *
     * A daily re-apply of the same rule must not create a new record, otherwise the
     * reference window of the directive shifts forward every day.
     */
    public function hasCatalogRulePriceChanged(int $productId, ?int $websiteId = null): bool
    {
        $currentRulePrice = $this->getCatalogRulePrice($productId, 0, null, $websiteId);
        if ($currentRulePrice <= 0) {
            return true;
        }

        $resource = Mage::getSingleton('core/resource');
        $readAdapter = Mage::helper('mm_omnibus')->readAdapter();
        $select = $readAdapter->select()
            ->from($resource->getTableName('mm_omnibus/price_history'), ['final_price'])
            ->where('product_id = ?', $productId)
            ->where('action = ?', MM_Omnibus_Model_Price_History::ACTION_CATALOGRULE_APPLY)
            ->where('parent_product_id = 0')
            ->order('created_at DESC')
            ->limit(1);

        $lastFinalPrice = $readAdapter->fetchOne($select);
        if ($lastFinalPrice === false) {
            return true;
        }

        return abs((float) $lastFinalPrice - $currentRulePrice) >= 0.0001;
    }
}
