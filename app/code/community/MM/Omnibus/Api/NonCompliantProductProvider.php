<?php

/**
 * Reads the non-compliant products from the compliance index.
 *
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

namespace MM\Omnibus\Api;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use Maho\ApiPlatform\Provider;
use Maho\Db\Expr;
use Maho\Db\Select;

final class NonCompliantProductProvider extends Provider
{
    /**
     * @return TraversablePaginator<NonCompliantProduct>|null
     */
    #[\Override]
    public function provide(
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): ?TraversablePaginator {
        if (!$operation instanceof CollectionOperationInterface) {
            return null;
        }

        ['page' => $page, 'pageSize' => $pageSize] = $this->extractPagination($context, 100, 500);
        $filters = $context['args'] ?? $context['filters'] ?? [];

        $websiteId = isset($filters['websiteId']) && $filters['websiteId'] !== ''
            ? (int) $filters['websiteId']
            : null;
        $minDifference = isset($filters['minDifference']) && $filters['minDifference'] !== ''
            ? (float) $filters['minDifference']
            : null;

        $adapter = \Mage::helper('mm_omnibus')->readAdapter();
        $total = (int) $adapter->fetchOne($this->buildCountSelect($websiteId, $minDifference));

        $select = $this->buildSelect($websiteId, $minDifference);
        $select->order(['ix.difference DESC', 'ix.product_id ASC']);
        $select->limit($pageSize, ($page - 1) * $pageSize);

        $items = [];
        foreach ($adapter->fetchAll($select) as $row) {
            $items[] = $this->rowToDto($row);
        }

        return new TraversablePaginator(new \ArrayIterator($items), $page, $pageSize, $total);
    }

    /**
     * Count query kept separate from the data query: adding `COUNT(*)` to a
     * select that lists the index columns breaks under `ONLY_FULL_GROUP_BY`.
     */
    private function buildCountSelect(?int $websiteId, ?float $minDifference): Select
    {
        $resource = \Mage::getSingleton('core/resource');
        $adapter = \Mage::helper('mm_omnibus')->readAdapter();

        $select = $adapter->select()
            ->from(
                ['ix' => $resource->getTableName('mm_omnibus/compliance_index')],
                ['row_count' => new Expr('COUNT(*)')],
            );

        return $this->applyFilters($select, $websiteId, $minDifference);
    }

    private function buildSelect(?int $websiteId, ?float $minDifference): Select
    {
        $resource = \Mage::getSingleton('core/resource');
        $adapter = \Mage::helper('mm_omnibus')->readAdapter();
        $nameAttribute = \Mage::getSingleton('eav/config')->getAttribute('catalog_product', 'name');
        if (!$nameAttribute instanceof \Mage_Eav_Model_Entity_Attribute_Abstract) {
            throw new \RuntimeException('The catalog_product name attribute is not available.');
        }
        $nameAttributeId = (int) $nameAttribute->getId();

        $select = $adapter->select()
            ->from(
                ['ix' => $resource->getTableName('mm_omnibus/compliance_index')],
                [
                    'product_id',
                    'website_id',
                    'reference_price',
                    'lowest_price',
                    'lowest_price_date',
                    'difference',
                    'expires_at',
                    'computed_at',
                ],
            )
            ->joinInner(
                ['pe' => $resource->getTableName('catalog_product_entity')],
                'pe.entity_id = ix.product_id',
                ['sku' => 'pe.sku'],
            )
            ->joinLeft(
                ['pn' => $resource->getTableName('catalog_product_entity_varchar')],
                'pn.entity_id = ix.product_id'
                . ' AND pn.attribute_id = ' . $nameAttributeId
                . ' AND pn.store_id = 0',
                ['name' => 'pn.value'],
            )
            ->joinLeft(
                ['cpsl' => new Expr(
                    '(SELECT product_id, MIN(parent_id) AS parent_id FROM '
                    . $resource->getTableName('catalog_product_super_link')
                    . ' GROUP BY product_id)',
                )],
                'cpsl.product_id = ix.product_id',
                [],
            )
            ->joinLeft(
                ['parent' => $resource->getTableName('catalog_product_entity')],
                'parent.entity_id = cpsl.parent_id',
                ['parent_sku' => 'parent.sku'],
            )
            ->joinLeft(
                ['cw' => $resource->getTableName('core_website')],
                'cw.website_id = ix.website_id',
                ['website' => 'cw.name'],
            );

        return $this->applyFilters($select, $websiteId, $minDifference);
    }

    private function applyFilters(Select $select, ?int $websiteId, ?float $minDifference): Select
    {
        $select->where('ix.is_noncompliant = 1');

        if ($websiteId !== null) {
            $select->where('ix.website_id = ?', $websiteId);
        }
        if ($minDifference !== null) {
            $select->where('ix.difference >= ?', $minDifference);
        }

        return $select;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowToDto(array $row): NonCompliantProduct
    {
        $dto = new NonCompliantProduct();
        $dto->productId = (int) $row['product_id'];
        $dto->websiteId = (int) $row['website_id'];
        $dto->sku = (string) ($row['sku'] ?? '');
        $dto->name = (string) ($row['name'] ?? '');
        $dto->parentSku = (string) ($row['parent_sku'] ?? '');
        $dto->website = (string) ($row['website'] ?? '');
        $dto->referencePrice = (float) $row['reference_price'];
        $dto->lowestPrice = (float) $row['lowest_price'];
        $dto->lowestPriceDate = $row['lowest_price_date'] ?: null;
        $dto->difference = (float) $row['difference'];
        $dto->computedAt = (string) $row['computed_at'];
        $dto->daysUntilExpiry = $this->daysUntilExpiry($row['expires_at'] ?: null);

        return $dto;
    }

    private function daysUntilExpiry(?string $expiresAt): int
    {
        if ($expiresAt === null) {
            return 0;
        }

        $timestamp = strtotime($expiresAt);
        if ($timestamp === false) {
            return 0;
        }

        return (int) floor(($timestamp - time()) / 86400);
    }
}
