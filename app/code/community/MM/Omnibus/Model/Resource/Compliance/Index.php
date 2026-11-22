<?php

/**
 * Reads the precomputed compliance rows of `omnibus_compliance_index`.
 *
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

class MM_Omnibus_Model_Resource_Compliance_Index extends Mage_Core_Model_Resource_Db_Abstract
{
    private const CHUNK_SIZE = 500;

    #[\Override]
    protected function _construct(): void
    {
        $this->_init('mm_omnibus/compliance_index', 'product_id');
    }

    /**
     * Lowest price of the reference window for many products at once, keyed by
     * product id. One select per chunk, so a listing never reads the index once
     * per product.
     *
     * @param int[] $productIds
     *
     * @return array<int, float>
     */
    public function getLowestPricesByProductIds(array $productIds, ?int $websiteId = null): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(intval(...), $productIds),
            static fn(int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return [];
        }

        $adapter = $this->getReadConnection();
        $prices = [];

        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            $select = $adapter->select()
                ->from($this->getMainTable(), ['product_id', 'lowest_price'])
                ->where('product_id IN (?)', $chunk);

            if ($websiteId !== null) {
                $select->where('website_id = ?', $websiteId);
            }

            foreach ($adapter->fetchAll($select) as $row) {
                $productId = (int) $row['product_id'];
                $price = (float) $row['lowest_price'];
                $prices[$productId] = isset($prices[$productId])
                    ? min($prices[$productId], $price)
                    : $price;
            }
        }

        return $prices;
    }

    /**
     * Index row of a product, for one website or for the first website it has.
     *
     * @return array<string, mixed>|null
     */
    public function getRow(int $productId, ?int $websiteId = null): ?array
    {
        $adapter = $this->getReadConnection();
        $select = $adapter->select()
            ->from($this->getMainTable())
            ->where('product_id = ?', $productId);

        if ($websiteId !== null) {
            $select->where('website_id = ?', $websiteId);
        }

        $select->order('website_id ASC')->limit(1);

        $row = $adapter->fetchRow($select);

        return $row === false ? null : $row;
    }

    /**
     * When the index was last rebuilt, null when it never ran.
     */
    public function getLastComputedAt(): ?string
    {
        $adapter = $this->getReadConnection();
        $select = $adapter->select()
            ->from($this->getMainTable(), ['computed_at' => new Maho\Db\Expr('MAX(computed_at)')]);

        $value = $adapter->fetchOne($select);

        return $value === false || $value === null ? null : (string) $value;
    }
}
