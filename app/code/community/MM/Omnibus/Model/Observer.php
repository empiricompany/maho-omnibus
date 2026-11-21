<?php

/**
 * Writes the price history, keeps the compliance index aligned and warns the admin.
 *
 * Registered with PHP attributes, so `composer dump-autoload` is required after a
 * change of an event name or of the cron expression.
 *
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

class MM_Omnibus_Model_Observer
{
    private const DELETE_CHUNK_SIZE = 500;

    private ?MM_Omnibus_Helper_Data $helper = null;

    private ?MM_Omnibus_Model_Service_PriceCalculator $priceCalculator = null;

    /**
     * Records the price of a saved product, on every website it uses.
     */
    #[Maho\Config\Observer('catalog_product_save_after')]
    public function catalogProductSaveAfter(Maho\Event\Observer $event): void
    {
        if (!$this->helper()->isEnabled()) {
            return;
        }

        $product = $event->getEvent()->getProduct();
        if (!$product instanceof Mage_Catalog_Model_Product || !$product->getId()) {
            return;
        }

        if (!$this->hasReferenceRecord((int) $product->getId())) {
            $this->helper()->log(sprintf(
                'Product save (first reference): product_id=%d, type=%s',
                (int) $product->getId(),
                $product->getTypeId(),
            ));
            $this->recordForAllWebsites($product, MM_Omnibus_Model_Price_History::ACTION_PRODUCT_SAVE);
            $this->recordConfigurableVariants($product);
            $this->deduplicateDailyRecords();
            $this->reindexProducts([(int) $product->getId()]);

            return;
        }

        $originalData = $product->getOrigData();
        if (!is_array($originalData)) {
            return;
        }

        $priceChanged = abs((float) $product->getData('price') - (float) ($originalData['price'] ?? 0)) >= 0.0001;
        $specialPriceChanged = abs((float) $product->getData('special_price') - (float) ($originalData['special_price'] ?? 0)) >= 0.0001
            || (string) $product->getData('special_from_date') !== (string) ($originalData['special_from_date'] ?? '')
            || (string) $product->getData('special_to_date') !== (string) ($originalData['special_to_date'] ?? '');

        if (!$priceChanged && !$specialPriceChanged) {
            return;
        }

        $this->helper()->log(sprintf(
            'Product save: product_id=%d, type=%s, price_changed=%s, special_price_changed=%s',
            (int) $product->getId(),
            $product->getTypeId(),
            $priceChanged ? 'true' : 'false',
            $specialPriceChanged ? 'true' : 'false',
        ));

        $this->recordForAllWebsites($product, MM_Omnibus_Model_Price_History::ACTION_PRODUCT_SAVE);
        $this->recordConfigurableVariants($product);
        $this->deduplicateDailyRecords();
        $this->reindexProducts(array_merge(
            [(int) $product->getId()],
            $this->getConfigurableChildIds($product),
        ));
    }

    /**
     * Records the prices after a catalog rule is applied.
     *
     * The event carries neither the rule nor the affected products, so the products
     * are read from `catalogrule_product`, and a product is recorded only when the
     * rule price actually changed: a daily re-apply with the same price must not
     * shift the reference window.
     */
    #[Maho\Config\Observer('catalogrule_after_apply')]
    public function catalogruleAfterApply(Maho\Event\Observer $event): void
    {
        if (!$this->helper()->isEnabled()) {
            return;
        }

        $resource = Mage::getSingleton('core/resource');
        $readAdapter = $this->helper()->readAdapter();
        $timestamp = strtotime(Mage_Core_Model_Locale::nowUtc());

        $select = $readAdapter->select()
            ->distinct()
            ->from($resource->getTableName('catalogrule/rule_product'), ['product_id'])
            ->where('from_time <= ?', $timestamp)
            ->where('to_time = 0 OR to_time >= ?', $timestamp);

        /** @var int[] $productIds */
        $productIds = array_map(intval(...), $readAdapter->fetchCol($select));
        if ($productIds === []) {
            $this->helper()->log('Catalog rule applied but no affected products found in catalogrule_product');

            return;
        }

        $this->helper()->log(sprintf('Catalog rule applied: affected_products=%d', count($productIds)));

        $collection = Mage::getResourceModel('catalog/product_collection')
            ->addAttributeToSelect(['price', 'special_price', 'special_from_date', 'special_to_date'])
            ->addIdFilter($productIds);

        $recordedIds = [];
        foreach ($collection as $product) {
            if (!$this->priceCalculator()->hasCatalogRulePriceChanged((int) $product->getId())) {
                $this->helper()->log(sprintf(
                    'Skipping catalogrule_apply: product_id=%d (final_price unchanged)',
                    $product->getId(),
                ));
                continue;
            }

            $this->recordForAllWebsites($product, MM_Omnibus_Model_Price_History::ACTION_CATALOGRULE_APPLY);
            $this->recordConfigurableVariants($product);
            $recordedIds[] = (int) $product->getId();
        }

        $this->deduplicateDailyRecords();
        $this->reindexProducts($recordedIds);
    }

    /**
     * Rebuilds the compliance index once a day: the reference window slides, so a
     * product that leaves it must stop being reported even when nobody saves it.
     */
    #[Maho\Config\CronJob('mm_omnibus_reindex', schedule: '0 3 * * *')]
    public function rebuildComplianceIndex(): void
    {
        if (!$this->helper()->isEnabled()) {
            return;
        }

        Mage::getSingleton('mm_omnibus/indexer_compliance')->rebuild();
    }

    /**
     * Removes the history older than the configured number of days.
     */
    #[Maho\Config\CronJob('mm_omnibus_cleanup', schedule: '30 3 * * *')]
    public function cleanupOldRecords(): void
    {
        if (!$this->helper()->isEnabled()) {
            return;
        }

        $cleanupDays = $this->helper()->getCleanupDays();
        $date = gmdate('Y-m-d H:i:s', strtotime(Mage_Core_Model_Locale::nowUtc()) - ($cleanupDays * 86400));

        $this->helper()->log(sprintf('Cleanup started: removing records older than %s', $date));

        $resource = Mage::getSingleton('core/resource');
        $writeAdapter = $this->helper()->writeAdapter();
        $affectedRows = $writeAdapter->delete(
            $resource->getTableName('mm_omnibus/price_history'),
            ['created_at < ?' => $date],
        );

        $this->helper()->log(sprintf('Cleanup completed: %d records removed', $affectedRows));
    }

    /**
     * Rebuilds the compliance index for the products a save just touched.
     *
     * @param int[] $productIds
     */
    public function reindexProducts(array $productIds): void
    {
        Mage::getSingleton('mm_omnibus/indexer_compliance')->reindexProducts($productIds);
    }

    /**
     * Adds the compliance warning on the product edit page, before the messages
     * block is rendered.
     */
    #[Maho\Config\Observer('controller_action_predispatch_adminhtml_catalog_product_edit', area: 'adminhtml')]
    public function addOmnibusWarning(Maho\Event\Observer $event): void
    {
        if (!$this->helper()->isEnabled()) {
            return;
        }

        $controller = $event->getEvent()->getControllerAction();
        if (!$controller instanceof Mage_Core_Controller_Varien_Action) {
            return;
        }

        $productId = (int) $controller->getRequest()->getParam('id');
        if (!$productId) {
            return;
        }

        $product = Mage::getModel('catalog/product')->load($productId);
        if (!$product->getId()) {
            return;
        }

        $this->checkCompliance($product);

        if ($product->getTypeId() === Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE) {
            foreach ($this->getConfigurableChildIds($product) as $childId) {
                $child = Mage::getModel('catalog/product')->load($childId);
                if ($child->getId()) {
                    $this->checkCompliance($child, $product);
                }
            }
        }
    }

    private function hasReferenceRecord(int $productId): bool
    {
        $resource = Mage::getSingleton('core/resource');
        $readAdapter = $this->helper()->readAdapter();
        $select = $readAdapter->select()
            ->from($resource->getTableName('mm_omnibus/price_history'), ['COUNT(*)'])
            ->where('product_id = ?', $productId)
            ->where('is_reference = 1');

        return (bool) $readAdapter->fetchOne($select);
    }

    /**
     * Writes the price snapshot of a product on every website it is assigned to.
     */
    private function recordForAllWebsites(
        Mage_Catalog_Model_Product $product,
        string $action,
    ): void {
        $websiteIds = $product->getWebsiteIds();
        if (empty($websiteIds)) {
            return;
        }

        [$oldPrice, $oldSpecialPrice, $oldFinalPrice] = $this->getOldPrices($product);
        $price = (float) $product->getPrice();
        $specialPrice = (float) $product->getSpecialPrice();
        $now = Mage_Core_Model_Locale::nowUtc();

        $rows = [];
        foreach ($websiteIds as $websiteId) {
            if ($action === MM_Omnibus_Model_Price_History::ACTION_CATALOGRULE_APPLY) {
                $rulePrice = $this->priceCalculator()->getCatalogRulePrice(
                    (int) $product->getId(),
                    0,
                    null,
                    (int) $websiteId,
                );
                $finalPrice = $rulePrice > 0 ? $rulePrice : (float) $product->getFinalPrice();
            } else {
                $finalPrice = (float) $product->getFinalPrice();
            }

            if ($finalPrice <= 0) {
                continue;
            }

            $rows[] = [
                'product_id' => (int) $product->getId(),
                'parent_product_id' => 0,
                'website_id' => (int) $websiteId,
                'action' => $action,
                'price' => $price,
                'special_price' => $specialPrice,
                'final_price' => $finalPrice,
                'old_price' => $oldPrice,
                'old_special_price' => $oldSpecialPrice,
                'old_final_price' => $oldFinalPrice,
                'is_reference' => 1,
                'created_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        $resource = Mage::getSingleton('core/resource');
        $writeAdapter = $this->helper()->writeAdapter();
        $table = $resource->getTableName('mm_omnibus/price_history');

        $writeAdapter->insertMultiple($table, $rows);
        $writeAdapter->update($table, ['is_reference' => 0], [
            'product_id = ?' => (int) $product->getId(),
            'is_reference = 1',
            'created_at < ?' => $now,
        ]);

        $this->helper()->log(sprintf(
            'Recorded price: product_id=%d, action=%s, websites=%d',
            (int) $product->getId(),
            $action,
            count($rows),
        ));
    }

    /**
     * @return array{0: float, 1: float, 2: float} the previous price, special price and final price
     */
    private function getOldPrices(Mage_Catalog_Model_Product $product): array
    {
        $originalData = $product->getOrigData();
        if (!is_array($originalData) || !isset($originalData['price'])) {
            return [0.0, 0.0, 0.0];
        }

        $oldPrice = (float) $originalData['price'];
        $oldSpecialPrice = (float) ($originalData['special_price'] ?? 0);
        $oldFinalPrice = $oldPrice;

        if ($oldSpecialPrice > 0 && $this->wasSpecialPriceActive(
            $originalData['special_from_date'] ?? null,
            $originalData['special_to_date'] ?? null,
        )) {
            $oldFinalPrice = $oldSpecialPrice;
        }

        return [$oldPrice, $oldSpecialPrice, $oldFinalPrice];
    }

    private function wasSpecialPriceActive(mixed $fromDate, mixed $toDate): bool
    {
        $now = strtotime(Mage_Core_Model_Locale::nowUtc());

        if ($fromDate) {
            $fromTimestamp = strtotime((string) $fromDate);
            if ($fromTimestamp !== false && $now < $fromTimestamp) {
                return false;
            }
        }

        if ($toDate) {
            $toTimestamp = strtotime((string) $toDate);
            if ($toTimestamp !== false && $now > strtotime('+1 day', $toTimestamp)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Records the composite price of every child of a configurable product.
     */
    private function recordConfigurableVariants(Mage_Catalog_Model_Product $product): void
    {
        if ($product->getTypeId() !== Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE) {
            return;
        }

        $childIds = $this->getConfigurableChildIds($product);
        if ($childIds === []) {
            return;
        }

        $parentBasePrice = (float) $product->getPrice();
        $parentSpecialPrice = (float) $product->getSpecialPrice();
        $parentFinalPrice = (float) $product->getFinalPrice();

        $childPrices = [];
        $childCollection = Mage::getResourceModel('catalog/product_collection')
            ->addIdFilter($childIds)
            ->addAttributeToSelect('price');
        foreach ($childCollection as $child) {
            $childPrices[(int) $child->getId()] = (float) $child->getPrice();
        }

        $surcharges = $this->loadConfigurableSurcharges($product, $childIds, $parentBasePrice);
        $websiteIds = $product->getWebsiteIds();
        if (empty($websiteIds)) {
            return;
        }

        $now = Mage_Core_Model_Locale::nowUtc();
        $rows = [];
        $childIdsRecorded = [];

        foreach ($childIds as $childId) {
            if (isset($surcharges[$childId])) {
                $surcharge = $surcharges[$childId];
            } elseif (($childPrices[$childId] ?? 0) > 0) {
                $surcharge = $childPrices[$childId] - $parentBasePrice;
            } else {
                continue;
            }

            $compositePrice = $parentFinalPrice + $surcharge;
            if ($compositePrice <= 0) {
                continue;
            }

            $childIdsRecorded[] = $childId;
            foreach ($websiteIds as $websiteId) {
                $rows[] = [
                    'product_id' => $childId,
                    'parent_product_id' => (int) $product->getId(),
                    'website_id' => (int) $websiteId,
                    'action' => MM_Omnibus_Model_Price_History::ACTION_PRODUCT_SAVE,
                    'price' => $parentBasePrice,
                    'special_price' => $parentSpecialPrice,
                    'final_price' => $compositePrice,
                    'old_price' => 0,
                    'old_special_price' => 0,
                    'old_final_price' => 0,
                    'is_reference' => 1,
                    'created_at' => $now,
                ];
            }
        }

        if ($rows === []) {
            return;
        }

        $resource = Mage::getSingleton('core/resource');
        $writeAdapter = $this->helper()->writeAdapter();
        $table = $resource->getTableName('mm_omnibus/price_history');

        $writeAdapter->insertMultiple($table, $rows);
        $writeAdapter->update($table, ['is_reference' => 0], [
            'product_id IN (?)' => $childIdsRecorded,
            'is_reference = 1',
            'created_at < ?' => $now,
        ]);

        $this->helper()->log(sprintf(
            'Configurable variants recorded: parent_id=%d, variants=%d, websites=%d, total_records=%d',
            (int) $product->getId(),
            count($childIdsRecorded),
            count($websiteIds),
            count($rows),
        ));
    }

    /**
     * Surcharges from `catalog_product_super_attribute_pricing`, fixed and percentage.
     *
     * @param int[] $childIds
     * @return array<int, float>
     */
    private function loadConfigurableSurcharges(
        Mage_Catalog_Model_Product $product,
        array $childIds,
        float $parentBasePrice,
    ): array {
        $resource = Mage::getSingleton('core/resource');
        $adapter = $this->helper()->readAdapter();

        $attributeTable = $resource->getTableName('catalog/product_super_attribute');
        $pricingTable = $resource->getTableName('catalog/product_super_attribute_pricing');
        $intTable = $resource->getTableName('catalog_product_entity_int');

        $superAttributeSelect = $adapter->select()
            ->from($attributeTable, ['product_super_attribute_id'])
            ->where('product_id = ?', (int) $product->getId());
        if ($adapter->fetchCol($superAttributeSelect) === []) {
            return [];
        }

        $select = $adapter->select()
            ->from(['pricing' => $pricingTable], ['pricing_value', 'is_percent'])
            ->joinInner(
                ['attr' => $attributeTable],
                'attr.product_super_attribute_id = pricing.product_super_attribute_id',
                [],
            )
            ->joinInner(
                ['int' => $intTable],
                'int.attribute_id = attr.attribute_id AND int.value = pricing.value_index',
                ['child_id' => 'int.entity_id'],
            )
            ->where('attr.product_id = ?', (int) $product->getId())
            ->where('int.entity_id IN (?)', $childIds)
            ->where('int.store_id = 0');

        $surcharges = [];
        foreach ($adapter->fetchAll($select) as $row) {
            $childId = (int) $row['child_id'];
            $pricingValue = (float) $row['pricing_value'];
            $surcharges[$childId] ??= 0.0;
            $surcharges[$childId] += (int) $row['is_percent'] === 1
                ? $parentBasePrice * $pricingValue / 100
                : $pricingValue;
        }

        return $surcharges;
    }

    /**
     * Keeps one record per product, parent, website and action for the current day.
     *
     * Two phases, both portable: the day's rows are grouped in PHP and the ids to
     * delete are removed in chunks. When both a product save and a catalog rule
     * apply exist for the same day, the catalog rule record wins: the save happens
     * in the admin context, where catalog rules are not applied.
     */
    private function deduplicateDailyRecords(): void
    {
        $resource = Mage::getSingleton('core/resource');
        $readAdapter = $this->helper()->readAdapter();
        $writeAdapter = $this->helper()->writeAdapter();
        $table = $resource->getTableName('mm_omnibus/price_history');

        $dayStart = Mage_Core_Model_Locale::todayUtc() . ' 00:00:00';

        $select = $readAdapter->select()
            ->from($table, ['history_id', 'product_id', 'parent_product_id', 'website_id', 'action', 'created_at'])
            ->where('created_at >= ?', $dayStart)
            ->order(['created_at ASC', 'history_id ASC']);

        $groups = [];
        $catalogRuleGroups = [];
        foreach ($readAdapter->fetchAll($select) as $row) {
            $key = implode(':', [
                (int) $row['product_id'],
                (int) $row['parent_product_id'],
                (int) $row['website_id'],
                (string) $row['action'],
            ]);
            $groups[$key][] = (int) $row['history_id'];

            if ((string) $row['action'] === MM_Omnibus_Model_Price_History::ACTION_CATALOGRULE_APPLY) {
                // Key without the action, to drop the product_save rows of the same day.
                $catalogRuleGroups[implode(':', [
                    (int) $row['product_id'],
                    (int) $row['parent_product_id'],
                    (int) $row['website_id'],
                ])] = true;
            }
        }

        $deleteIds = [];
        foreach ($groups as $ids) {
            if (count($ids) < 2) {
                continue;
            }

            // The rows are ordered by created_at and history_id, so the last one is kept.
            array_pop($ids);
            $deleteIds = array_merge($deleteIds, $ids);
        }

        if ($catalogRuleGroups !== []) {
            $saveSelect = $readAdapter->select()
                ->from($table, ['history_id', 'product_id', 'parent_product_id', 'website_id'])
                ->where('created_at >= ?', $dayStart)
                ->where('action = ?', MM_Omnibus_Model_Price_History::ACTION_PRODUCT_SAVE);

            foreach ($readAdapter->fetchAll($saveSelect) as $row) {
                $key = implode(':', [
                    (int) $row['product_id'],
                    (int) $row['parent_product_id'],
                    (int) $row['website_id'],
                ]);
                if (isset($catalogRuleGroups[$key])) {
                    $deleteIds[] = (int) $row['history_id'];
                }
            }
        }

        $deleteIds = array_values(array_unique($deleteIds));
        foreach (array_chunk($deleteIds, self::DELETE_CHUNK_SIZE) as $chunk) {
            $writeAdapter->delete($table, ['history_id IN (?)' => $chunk]);
        }
    }

    /**
     * Warns the admin when a product does not comply, reading the compliance index.
     */
    private function checkCompliance(
        Mage_Catalog_Model_Product $product,
        ?Mage_Catalog_Model_Product $parentProduct = null,
    ): void {
        $row = Mage::getResourceSingleton('mm_omnibus/compliance_index')->getRow((int) $product->getId());
        if ($row === null || (int) $row['is_noncompliant'] !== 1) {
            return;
        }

        $coreHelper = Mage::helper('core');
        $productLabel = (string) $product->getSku();
        if ($parentProduct instanceof Mage_Catalog_Model_Product) {
            $productLabel .= ' (' . $this->helper()->__('child of %s', $parentProduct->getSku()) . ')';
        }

        $message = $this->helper()->__(
            'Omnibus warning: %s. The crossed-out reference price (%s) is higher than the lowest price (%s) recorded in the last %d days, last on %s. Difference: + %s',
            $productLabel,
            $coreHelper->currency((float) $row['reference_price'], true, false),
            $coreHelper->currency((float) $row['lowest_price'], true, false),
            $this->helper()->getReferencePeriod(),
            $coreHelper->formatDate((string) $row['lowest_price_date'], 'medium', false),
            $coreHelper->currency(abs((float) $row['difference']), true, false),
        );

        Mage::getSingleton('adminhtml/session')->addWarning($message);
    }

    /**
     * @return int[]
     */
    private function getConfigurableChildIds(Mage_Catalog_Model_Product $product): array
    {
        if ($product->getTypeId() !== Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE) {
            return [];
        }

        $ids = [];
        foreach (Mage::getModel('catalog/product_type_configurable')->getChildrenIds((int) $product->getId()) as $group) {
            foreach ($group as $childId) {
                $ids[] = (int) $childId;
            }
        }

        return array_values(array_unique($ids));
    }

    private function helper(): MM_Omnibus_Helper_Data
    {
        if (!$this->helper instanceof MM_Omnibus_Helper_Data) {
            $helper = Mage::helper('mm_omnibus');
            if (!$helper instanceof MM_Omnibus_Helper_Data) {
                throw new Mage_Core_Exception('The Omnibus helper is not available.');
            }
            $this->helper = $helper;
        }

        return $this->helper;
    }

    private function priceCalculator(): MM_Omnibus_Model_Service_PriceCalculator
    {
        return $this->priceCalculator ??= new MM_Omnibus_Model_Service_PriceCalculator();
    }
}
