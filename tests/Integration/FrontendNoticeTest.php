<?php

/**
 * The notice text and the bulk read of the lowest price behind it.
 *
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

namespace MM\Omnibus\Tests\Integration;

use Mage;
use MM_Omnibus_Model_Observer_Frontend;
use MM_Omnibus_Model_Resource_Compliance_Index;
use MM_Omnibus_Model_Service_PriceCalculator;
use ReflectionMethod;

final class FrontendNoticeTest extends MahoTestCase
{
    private const WEBSITE_ID = 1;

    private int $firstProductId = 0;

    private int $secondProductId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $store = Mage::app()->getDefaultStoreView();
        if ($store instanceof Mage_Core_Model_Store) {
            Mage::app()->setCurrentStore($store->getId());
        }

        $this->firstProductId = $this->createProduct(28.0);
        $this->secondProductId = $this->createProduct(19.9);
    }

    /**
     * The fixture is cleaned only when setUp got as far as creating it: a skipped
     * test has no store to talk to and no products to remove.
     */
    protected function tearDown(): void
    {
        if ($this->firstProductId > 0 || $this->secondProductId > 0) {
            $adapter = $this->adapter();

            foreach ([$this->firstProductId, $this->secondProductId] as $productId) {
                if ($productId <= 0) {
                    continue;
                }

                $adapter->delete($this->table('mm_omnibus/compliance_index'), ['product_id = ?' => $productId]);
                $adapter->delete($this->table('mm_omnibus/price_history'), ['product_id = ?' => $productId]);

                $product = Mage::getModel('catalog/product')->setStoreId(0)->load($productId);
                if ($product->getId()) {
                    $product->delete();
                }
            }
        }

        parent::tearDown();
    }

    public function testTheOptionIsOffByDefault(): void
    {
        self::assertSame(
            '0',
            (string) Mage::getConfig()->getNode('default/mm_omnibus/frontend/show_lowest_price'),
        );
    }

    public function testTheBulkLookupReadsTheIndexForManyProductsAtOnce(): void
    {
        $this->insertIndexRow($this->firstProductId, 20.0);
        $this->insertIndexRow($this->secondProductId, 17.5);

        $prices = $this->index()->getLowestPricesByProductIds(
            [$this->firstProductId, $this->secondProductId, $this->firstProductId + 1_000_000],
            self::WEBSITE_ID,
        );

        self::assertCount(2, $prices);
        self::assertEqualsWithDelta(20.0, $prices[$this->firstProductId], 0.0001);
        self::assertEqualsWithDelta(17.5, $prices[$this->secondProductId], 0.0001);
    }

    public function testTheBulkLookupOfAnEmptyListReadsNothing(): void
    {
        self::assertSame([], $this->index()->getLowestPricesByProductIds([], self::WEBSITE_ID));
    }

    public function testTheBulkLookupOfAnUnknownWebsiteReturnsNothing(): void
    {
        $this->insertIndexRow($this->firstProductId, 20.0);

        self::assertSame(
            [],
            $this->index()->getLowestPricesByProductIds([$this->firstProductId], self::WEBSITE_ID + 1_000),
        );
    }

    public function testTheNoticeShowsTheAmountWhenItIsAvailable(): void
    {
        $html = $this->noticeHtml(854.99);

        self::assertStringContainsString('omnibus-price-notice', $html);
        self::assertStringContainsString('854', $html);
    }

    public function testTheNoticeKeepsThePlainTextWithoutTheAmount(): void
    {
        $html = $this->noticeHtml(null);

        self::assertStringContainsString('omnibus-price-notice', $html);
        self::assertStringNotContainsString('854', $html);
    }

    public function testTheIndexedLowestPriceWinsOverTheCrossedOutPrice(): void
    {
        $this->insertIndexRow($this->firstProductId, 20.0);

        self::assertEqualsWithDelta(20.0, $this->noticePrice($this->firstProductId), 0.0001);
    }

    public function testTheCrossedOutPriceIsShownWhenTheIndexHasNoRow(): void
    {
        $product = Mage::getModel('catalog/product')->setStoreId(0)->load($this->firstProductId);
        $expected = (new MM_Omnibus_Model_Service_PriceCalculator())->getDisplayedReferencePrice($product);

        self::assertGreaterThan(0.0, $expected);
        self::assertEqualsWithDelta($expected, $this->noticePrice($this->firstProductId), 0.0001);
    }

    private function noticePrice(int $productId): ?float
    {
        $product = Mage::getModel('catalog/product')->setStoreId(0)->load($productId);
        $observer = new MM_Omnibus_Model_Observer_Frontend();
        $method = new ReflectionMethod($observer, 'lowestPrice');
        $value = $method->invoke($observer, $product, $productId);

        return $value === null ? null : (float) $value;
    }

    private function noticeHtml(?float $lowestPrice): string
    {
        $observer = new MM_Omnibus_Model_Observer_Frontend();
        $method = new ReflectionMethod($observer, 'getNoticeHtml');

        return (string) $method->invoke($observer, $this->firstProductId, $lowestPrice);
    }

    private function index(): MM_Omnibus_Model_Resource_Compliance_Index
    {
        $resource = Mage::getResourceSingleton('mm_omnibus/compliance_index');
        self::assertInstanceOf(MM_Omnibus_Model_Resource_Compliance_Index::class, $resource);

        return $resource;
    }

    private function createProduct(float $price): int
    {
        $product = Mage::getModel('catalog/product');
        $product->setStoreId(0)
            ->setAttributeSetId($product->getDefaultAttributeSetId())
            ->setTypeId('simple')
            ->setSku('omnibus-notice-' . bin2hex(random_bytes(4)))
            ->setName('Omnibus notice product')
            ->setPrice($price)
            ->setStatus(1)
            ->setVisibility(4)
            ->setWebsiteIds([self::WEBSITE_ID])
            ->save();

        return (int) $product->getId();
    }

    private function insertIndexRow(int $productId, float $lowestPrice): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->adapter()->insert($this->table('mm_omnibus/compliance_index'), [
            'product_id' => $productId,
            'website_id' => self::WEBSITE_ID,
            'reference_price' => 28.0,
            'reference_recorded_at' => $now,
            'lowest_price' => $lowestPrice,
            'lowest_price_date' => $now,
            'expires_at' => $now,
            'difference' => 28.0 - $lowestPrice,
            'is_noncompliant' => 1,
            'computed_at' => $now,
        ]);
    }
}
