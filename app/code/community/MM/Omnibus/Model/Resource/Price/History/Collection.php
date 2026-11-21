<?php

/**
 * Reads the compliance data of the price history.
 *
 * The select is the source of the compliance index: it returns one row for every
 * product and website that has a reference record and older records inside the
 * reference window, with the current reference price and the lowest recorded price.
 *
 * It is portable on purpose: the window start is computed in PHP and passed as a
 * parameter, and no MySQL-only function is used.
 *
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

class MM_Omnibus_Model_Resource_Price_History_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    #[\Override]
    protected function _construct(): void
    {
        $this->_init('mm_omnibus/price_history');
    }

    /**
     * Compliance data for every product and website with history in the window.
     *
     * @param int|null       $websiteId  Limit to one website
     * @param int|int[]|null $productId  Limit to one product or a list of products
     */
    public function getComplianceSelect(?int $websiteId = null, int|array|null $productId = null): Maho\Db\Select
    {
        $helper = Mage::helper('mm_omnibus');
        $windowStart = $this->getWindowStart($helper->getReferencePeriodByWebsite($websiteId));
        $adapter = $this->getConnection();
        $table = $this->getMainTable();

        $lowestSelect = $adapter->select()
            ->from($table, [
                'product_id',
                'website_id',
                'lowest_price' => new Maho\Db\Expr('MIN(final_price)'),
            ])
            ->where('is_reference = 0')
            ->where('created_at >= ?', $windowStart)
            ->where('final_price > 0')
            ->group(['product_id', 'website_id']);

        if ($websiteId !== null) {
            $lowestSelect->where('website_id = ?', $websiteId);
        }
        if (is_int($productId)) {
            $lowestSelect->where('product_id = ?', $productId);
        } elseif (is_array($productId) && $productId !== []) {
            $lowestSelect->where('product_id IN (?)', $productId);
        }

        $windowStartQuoted = $adapter->quote($windowStart);

        $select = $adapter->select()
            ->from(['ref' => $table], [
                'product_id',
                'website_id',
                'reference_price' => 'ref.price',
                'reference_recorded_at' => 'ref.created_at',
            ])
            ->joinInner(
                ['lowest' => $lowestSelect],
                'lowest.product_id = ref.product_id AND lowest.website_id = ref.website_id',
                ['lowest_price' => 'lowest.lowest_price'],
            )
            ->joinInner(
                ['lowest_date' => $table],
                'lowest_date.product_id = ref.product_id'
                . ' AND lowest_date.website_id = ref.website_id'
                . ' AND lowest_date.final_price = lowest.lowest_price'
                . ' AND lowest_date.is_reference = 0'
                . ' AND lowest_date.created_at >= ' . $windowStartQuoted,
                ['lowest_price_date' => new Maho\Db\Expr('MIN(lowest_date.created_at)')],
            )
            ->where('ref.is_reference = 1')
            ->group([
                'ref.product_id',
                'ref.website_id',
                'ref.price',
                'ref.created_at',
                'lowest.lowest_price',
            ]);

        if ($websiteId !== null) {
            $select->where('ref.website_id = ?', $websiteId);
        }
        if (is_int($productId)) {
            $select->where('ref.product_id = ?', $productId);
        } elseif (is_array($productId) && $productId !== []) {
            $select->where('ref.product_id IN (?)', $productId);
        }

        return $select;
    }

    /**
     * Raw compliance data of one product, read live from the history.
     *
     * @return array{
     *     compliant: bool,
     *     reference_price: float,
     *     lowest_price: float,
     *     lowest_price_date: ?string
     * }
     */
    public function getComplianceData(int $productId, ?int $websiteId = null): array
    {
        $row = $this->getConnection()->fetchRow($this->getComplianceSelect($websiteId, $productId));

        if ($row === false) {
            return [
                'compliant' => true,
                'reference_price' => 0.0,
                'lowest_price' => 0.0,
                'lowest_price_date' => null,
            ];
        }

        $referencePrice = (float) $row['reference_price'];
        $lowestPrice = (float) $row['lowest_price'];

        return [
            'compliant' => MM_Omnibus_Model_Compliance_Calculator::isCompliant($referencePrice, $lowestPrice),
            'reference_price' => $referencePrice,
            'lowest_price' => $lowestPrice,
            'lowest_price_date' => $row['lowest_price_date'] ?: null,
        ];
    }

    private function getWindowStart(int $period): string
    {
        $days = max(1, $period);

        return gmdate('Y-m-d H:i:s', strtotime(Mage_Core_Model_Locale::nowUtc()) - ($days * 86400));
    }
}
