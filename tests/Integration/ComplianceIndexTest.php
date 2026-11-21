<?php

/**
 * End-to-end of the compliance data on a real (SQLite) store: history rows in,
 * index rows out, then the collection and the API provider reading them.
 *
 * The fixture is a simple product created through its model plus two history
 * rows (reference price 28.00, older price 20.00), so the product is
 * non-compliant by 8.00. Both the product and its rows are removed in tearDown.
 *
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

namespace MM\Omnibus\Tests\Integration;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\State\Pagination\TraversablePaginator;
use DateTimeImmutable;
use DateTimeZone;
use Mage;
use Maho\Db\Expr;
use MM\Omnibus\Api\NonCompliantProduct;
use MM\Omnibus\Api\NonCompliantProductProvider;

final class ComplianceIndexTest extends MahoTestCase
{
    private const WEBSITE_ID = 1;

    private const REFERENCE_PRICE = 28.0;

    private const OLDER_PRICE = 20.0;

    private int $productId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productId = $this->createProduct();

        // Saving the product runs the module observer, which already records a
        // reference row: start from an empty history or the fixture adds a
        // second reference and the index row is written twice.
        $this->resetHistory();
        $this->insertHistoryRow(self::REFERENCE_PRICE, 1, '-1 hour');
        $this->insertHistoryRow(self::OLDER_PRICE, 0, '-3 days');
    }

    protected function tearDown(): void
    {
        if ($this->productId > 0) {
            $adapter = $this->adapter();
            $adapter->delete($this->table('mm_omnibus/compliance_index'), ['product_id = ?' => $this->productId]);
            $adapter->delete($this->table('mm_omnibus/price_history'), ['product_id = ?' => $this->productId]);

            $product = Mage::getModel('catalog/product')->setStoreId(0)->load($this->productId);
            if ($product->getId()) {
                $product->delete();
            }
        }

        parent::tearDown();
    }

    public function testTheIndexerWritesTheNonCompliantRow(): void
    {
        $this->reindex();

        $adapter = $this->adapter();
        $row = $adapter->fetchRow(
            $adapter->select()
                ->from($this->table('mm_omnibus/compliance_index'))
                ->where('product_id = ?', $this->productId),
        );

        self::assertIsArray($row);
        self::assertSame(self::WEBSITE_ID, (int) $row['website_id']);
        self::assertEqualsWithDelta(self::REFERENCE_PRICE, (float) $row['reference_price'], 0.0001);
        self::assertEqualsWithDelta(self::OLDER_PRICE, (float) $row['lowest_price'], 0.0001);
        self::assertEqualsWithDelta(8.0, (float) $row['difference'], 0.0001);
        self::assertSame(1, (int) $row['is_noncompliant']);
        self::assertNotEmpty($row['expires_at']);
    }

    public function testTheIndexerSkipsProductsWithoutReferenceHistory(): void
    {
        $this->reindex([$this->productId + 1_000_000]);

        $adapter = $this->adapter();

        self::assertSame(
            0,
            (int) $adapter->fetchOne(
                $adapter->select()
                    ->from($this->table('mm_omnibus/compliance_index'), ['row_count' => new \Maho\Db\Expr('COUNT(*)')])
                    ->where('product_id = ?', $this->productId + 1_000_000),
            ),
        );
    }

    public function testTheCollectionReportsTheProductAsNonCompliant(): void
    {
        $data = Mage::getResourceModel('mm_omnibus/price_history_collection')
            ->getComplianceData($this->productId, self::WEBSITE_ID);

        self::assertFalse($data['compliant']);
        self::assertEqualsWithDelta(self::REFERENCE_PRICE, $data['reference_price'], 0.0001);
        self::assertEqualsWithDelta(self::OLDER_PRICE, $data['lowest_price'], 0.0001);
    }

    public function testTheApiProviderReadsTheIndex(): void
    {
        $this->reindex();

        $paginator = (new NonCompliantProductProvider())->provide(
            new GetCollection(),
            [],
            ['filters' => ['websiteId' => self::WEBSITE_ID]],
        );

        self::assertInstanceOf(TraversablePaginator::class, $paginator);
        $items = iterator_to_array($paginator, false);
        self::assertCount(1, $items);

        $item = $items[0];
        self::assertInstanceOf(NonCompliantProduct::class, $item);
        self::assertSame($this->productId, $item->productId);
        self::assertSame(self::WEBSITE_ID, $item->websiteId);
        self::assertEqualsWithDelta(8.0, $item->difference, 0.0001);
    }

    public function testTheApiProviderFiltersOutSmallDifferences(): void
    {
        $this->reindex();

        $paginator = (new NonCompliantProductProvider())->provide(
            new GetCollection(),
            [],
            ['filters' => ['websiteId' => self::WEBSITE_ID, 'minDifference' => 9.0]],
        );

        self::assertInstanceOf(TraversablePaginator::class, $paginator);
        self::assertCount(0, iterator_to_array($paginator, false));
    }

    public function testTheNightlyCronRebuildsTheIndex(): void
    {
        // The cron entry point, not the indexer: an empty index must be filled from the history.
        self::assertNull($this->indexedRow());

        Mage::getSingleton('mm_omnibus/observer')->rebuildComplianceIndex();

        $row = $this->indexedRow();
        self::assertNotNull($row);
        self::assertSame(1, (int) $row['is_noncompliant']);
    }

    public function testTheWindowExcludesRecordsOlderThanTheReferencePeriod(): void
    {
        // Cheapest price just outside the 30-day window: it must not count.
        $this->insertHistoryRow(10.0, 0, '-31 days');

        $this->reindex();

        self::assertEqualsWithDelta(self::OLDER_PRICE, $this->indexedLowestPrice(), 0.0001);
    }

    public function testTheWindowIncludesRecordsAtTheEdgeOfTheReferencePeriod(): void
    {
        $this->insertHistoryRow(18.0, 0, '-29 days');

        $this->reindex();

        self::assertEqualsWithDelta(18.0, $this->indexedLowestPrice(), 0.0001);
    }

    public function testARebuildDropsAProductThatLeftTheWindow(): void
    {
        $this->reindex();
        self::assertNotNull($this->indexedRow());

        // The only record inside the window ages out: the nightly rebuild must forget it.
        $this->adapter()->update(
            $this->table('mm_omnibus/price_history'),
            ['created_at' => $this->utcDate('-40 days')],
            ['product_id = ?' => $this->productId, 'is_reference = ?' => 0],
        );

        $this->reindex();

        self::assertNull($this->indexedRow());
    }

    public function testTheCleanupRemovesOnlyTheHistoryOlderThanTheRetention(): void
    {
        $this->insertHistoryRow(15.0, 0, '-100 days');

        Mage::getSingleton('mm_omnibus/observer')->cleanupOldRecords();

        self::assertSame(0, $this->countHistoryRows(15.0));
        self::assertSame(1, $this->countHistoryRows(self::OLDER_PRICE));
    }

    /**
     * @param int[]|null $productIds
     */
    private function reindex(?array $productIds = null): void
    {
        $indexer = Mage::getSingleton('mm_omnibus/indexer_compliance');
        if ($productIds === null) {
            $indexer->rebuild();

            return;
        }

        $indexer->reindexProducts($productIds);
    }

    private function createProduct(): int
    {
        $product = Mage::getModel('catalog/product');
        $product->setStoreId(0)
            ->setAttributeSetId($product->getDefaultAttributeSetId())
            ->setTypeId('simple')
            ->setSku('omnibus-test-' . bin2hex(random_bytes(4)))
            ->setName('Omnibus test product')
            ->setPrice(self::REFERENCE_PRICE)
            ->setStatus(1)
            ->setVisibility(4)
            ->setWebsiteIds([self::WEBSITE_ID])
            ->save();

        return (int) $product->getId();
    }

    private function resetHistory(): void
    {
        $adapter = $this->adapter();
        $adapter->delete($this->table('mm_omnibus/compliance_index'), ['product_id = ?' => $this->productId]);
        $adapter->delete($this->table('mm_omnibus/price_history'), ['product_id = ?' => $this->productId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function indexedRow(): ?array
    {
        $adapter = $this->adapter();
        $row = $adapter->fetchRow(
            $adapter->select()
                ->from($this->table('mm_omnibus/compliance_index'))
                ->where('product_id = ?', $this->productId),
        );

        return is_array($row) ? $row : null;
    }

    private function indexedLowestPrice(): float
    {
        $row = $this->indexedRow();
        self::assertNotNull($row);

        return (float) $row['lowest_price'];
    }

    private function countHistoryRows(float $finalPrice): int
    {
        $adapter = $this->adapter();

        return (int) $adapter->fetchOne(
            $adapter->select()
                ->from($this->table('mm_omnibus/price_history'), ['row_count' => new Expr('COUNT(*)')])
                ->where('product_id = ?', $this->productId)
                ->where('final_price = ?', $finalPrice),
        );
    }

    private function utcDate(string $modifier): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify($modifier)->format('Y-m-d H:i:s');
    }

    private function insertHistoryRow(float $price, int $isReference, string $createdAt): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $this->adapter()->insert($this->table('mm_omnibus/price_history'), [
            'product_id' => $this->productId,
            'parent_product_id' => 0,
            'website_id' => self::WEBSITE_ID,
            'action' => 'price_change',
            'price' => $price,
            'special_price' => 0.0,
            'final_price' => $price,
            'old_price' => 0.0,
            'old_special_price' => 0.0,
            'old_final_price' => 0.0,
            'created_at' => $now->modify($createdAt)->format('Y-m-d H:i:s'),
            'is_reference' => $isReference,
        ]);
    }
}
