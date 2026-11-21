<?php

/**
 * Precomputes the compliance data of every product and website into
 * `omnibus_compliance_index`.
 *
 * The dashboard, the product tab and the API read that table, so no page has to
 * aggregate the whole price history. Reindex methods:
 *
 * - `reindexAll()`: full rebuild, used by the daily cron, by `index:reindex` and
 *   by the admin index management, because the reference window slides by itself.
 * - `reindexProducts()`: rebuild of the products an observer just touched, so
 *   the admin warning and the dashboard are correct right after a save.
 *
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

class MM_Omnibus_Model_Indexer_Compliance extends Mage_Index_Model_Indexer_Abstract
{
    private const CHUNK_SIZE = 500;

    #[\Override]
    public function getName(): string
    {
        return Mage::helper('mm_omnibus')->__('Omnibus Compliance');
    }

    #[\Override]
    public function getDescription(): string
    {
        return Mage::helper('mm_omnibus')->__('Lowest price of the reference period, per product and website');
    }

    #[\Override]
    public function reindexAll(): void
    {
        $this->rebuild();
    }

    /**
     * Full rebuild of the index.
     */
    public function rebuild(): void
    {
        $this->writeRows(null);
    }

    /**
     * Rebuild the index rows of the given products, on every website they use.
     *
     * @param int[] $productIds
     */
    public function reindexProducts(array $productIds): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map(intval(...), $productIds),
            static fn(int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return;
        }

        $this->writeRows($ids);
    }

    /**
     * @param int[]|null $productIds null for a full rebuild
     */
    private function writeRows(?array $productIds): void
    {
        $resource = Mage::getSingleton('core/resource');
        $adapter = Mage::helper('mm_omnibus')->writeAdapter();
        $table = $resource->getTableName('mm_omnibus/compliance_index');

        /** @var MM_Omnibus_Model_Resource_Price_History_Collection $collection */
        $collection = Mage::getResourceModel('mm_omnibus/price_history_collection');
        $rows = $adapter->fetchAll($collection->getComplianceSelect(null, $productIds));
        $computedAt = Mage_Core_Model_Locale::nowUtc();
        $period = Mage::helper('mm_omnibus')->getReferencePeriod();

        $data = [];
        foreach ($rows as $row) {
            $referencePrice = (float) $row['reference_price'];
            $lowestPrice = (float) $row['lowest_price'];
            $data[] = [
                'product_id' => (int) $row['product_id'],
                'website_id' => (int) $row['website_id'],
                'reference_price' => $referencePrice,
                'reference_recorded_at' => $row['reference_recorded_at'] ?: null,
                'lowest_price' => $lowestPrice,
                'lowest_price_date' => $row['lowest_price_date'] ?: null,
                'expires_at' => $this->expiresAt($row['lowest_price_date'] ?: null, $period),
                'difference' => MM_Omnibus_Model_Compliance_Calculator::difference($referencePrice, $lowestPrice),
                'is_noncompliant' => MM_Omnibus_Model_Compliance_Calculator::isCompliant($referencePrice, $lowestPrice)
                    ? 0
                    : 1,
                'computed_at' => $computedAt,
            ];
        }

        $adapter->beginTransaction();
        try {
            if ($productIds === null) {
                $adapter->delete($table);
            } else {
                $adapter->delete($table, ['product_id IN (?)' => $productIds]);
            }

            foreach (array_chunk($data, self::CHUNK_SIZE) as $chunk) {
                $adapter->insertMultiple($table, $chunk);
            }

            $adapter->commit();
        } catch (Throwable $exception) {
            $adapter->rollBack();

            throw $exception;
        }

        Mage::helper('mm_omnibus')->log(sprintf(
            'Compliance index rebuilt: products=%s, rows=%d',
            $productIds === null ? 'all' : implode(',', $productIds),
            count($data),
        ));
    }

    /**
     * When the lowest price record exits the reference window. Stored so the grid
     * can sort and filter on it without database-specific date arithmetic; the
     * daily rebuild keeps it aligned with the sliding window.
     */
    private function expiresAt(?string $lowestPriceDate, int $period): ?string
    {
        if ($lowestPriceDate === null) {
            return null;
        }

        $timestamp = strtotime($lowestPriceDate);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp + (max(1, $period) * 86400));
    }

    #[\Override]
    protected function _registerEvent(Mage_Index_Model_Event $event): void {}

    #[\Override]
    protected function _processEvent(Mage_Index_Model_Event $event): void {}
}
