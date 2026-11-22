<?php

/**
 * Adds the omnibus notice next to a crossed-out price.
 *
 * The block markup is read from the transport and modified with the DOM helper.
 * When the store view also shows the amount, the lowest price of the reference
 * period is read from the compliance index: one select for the products of a
 * collection, a single lookup for a product loaded on its own.
 *
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

class MM_Omnibus_Model_Observer_Frontend
{
    /**
     * Product data key carrying the lowest price of the reference window. It
     * travels with the product, so the price blocks of the same product read the
     * model instead of querying the index again.
     */
    private const LOWEST_PRICE_KEY = 'omnibus_lowest_price';

    /**
     * Products already decorated in this request. Storefront themes render the same
     * product price more than once (product page, options area, sticky bar): the
     * notice must appear only next to the first one, not three times.
     *
     * Static because the dispatcher instantiates the observer for every event:
     * an instance property would not survive to the next price block.
     *
     * @var array<int, bool>
     */
    private static array $notifiedProducts = [];

    /**
     * Lowest price of a whole listing with one select: the index is not read once
     * per product, and a disabled option costs no query at all.
     */
    #[Maho\Config\Observer('catalog_product_collection_load_after', area: 'frontend')]
    public function addLowestPriceToCollection(Maho\Event\Observer $event): void
    {
        $helper = Mage::helper('mm_omnibus');
        if (!$helper instanceof MM_Omnibus_Helper_Data
            || !$helper->isEnabled()
            || !$helper->shouldShowLowestPrice()
        ) {
            return;
        }

        $collection = $event->getEvent()->getCollection();
        if (!$collection instanceof Mage_Catalog_Model_Resource_Product_Collection) {
            return;
        }

        $productIds = [];
        foreach ($collection->getItems() as $product) {
            if ($product instanceof Mage_Catalog_Model_Product) {
                $productIds[] = (int) $product->getId();
            }
        }

        $prices = $this->index()->getLowestPricesByProductIds($productIds, $this->websiteId());

        foreach ($collection->getItems() as $product) {
            if ($product instanceof Mage_Catalog_Model_Product) {
                $product->setData(self::LOWEST_PRICE_KEY, $prices[(int) $product->getId()] ?? null);
            }
        }
    }

    #[Maho\Config\Observer('core_block_abstract_to_html_after', area: 'frontend')]
    public function appendOmnibusNotice(Maho\Event\Observer $event): void
    {
        $helper = Mage::helper('mm_omnibus');
        if (!$helper instanceof MM_Omnibus_Helper_Data || !$helper->isEnabled()) {
            return;
        }

        $block = $event->getEvent()->getBlock();
        if (!$block instanceof Mage_Catalog_Block_Product_Price) {
            return;
        }

        $transport = $event->getEvent()->getTransport();
        if (!$transport instanceof Maho\DataObject) {
            return;
        }

        $html = (string) $transport->getHtml();
        if (!str_contains($html, 'class="special-price"')) {
            return;
        }

        $productId = $this->resolveProductId($block, $html);
        if ($productId > 0 && isset(self::$notifiedProducts[$productId])) {
            return;
        }

        $htmlHelper = new MM_Omnibus_Helper_Html();

        $lowestPrice = $helper->shouldShowLowestPrice()
            ? $this->lowestPrice($block->getProduct(), $productId)
            : null;
        $notice = $this->getNoticeHtml($productId, $lowestPrice);
        $selector = $helper->getPriceBoxSelector();

        $result = match ($helper->getNoticePosition()) {
            MM_Omnibus_Helper_Data::POSITION_BEFORE => $htmlHelper->insertBefore($html, $selector, $notice),
            MM_Omnibus_Helper_Data::POSITION_AFTER => $htmlHelper->insertAfter($html, $selector, $notice),
            default => $htmlHelper->appendTo($html, $selector, $notice),
        };

        if ($result === $html) {
            return;
        }

        $transport->setHtml($result);

        if ($productId > 0) {
            self::$notifiedProducts[$productId] = true;
        }
    }

    /**
     * Product the price block belongs to: the block carries it, the markup carries
     * it as well (`id="product-price-<id>"`, `id="old-price-<id>"`).
     */
    private function resolveProductId(Mage_Catalog_Block_Product_Price $block, string $html): int
    {
        $product = $block->getProduct();
        if ($product instanceof Mage_Catalog_Model_Product && $product->getId()) {
            return (int) $product->getId();
        }

        if (preg_match('/id="(?:product-price|old-price)-(\d+)"/', $html, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * Amount the notice prints: the lowest price of the reference window, or the
     * crossed-out price itself when the history has nothing cheaper, so the notice
     * always carries a figure.
     *
     * A product of a collection already carries the indexed value (see
     * `addLowestPriceToCollection()`); a product loaded on its own costs one
     * lookup, which is then kept on the model for its other price blocks.
     */
    private function lowestPrice(mixed $product, int $productId): ?float
    {
        $product = $product instanceof Mage_Catalog_Model_Product && $product->getId() ? $product : null;

        $lowest = $this->indexedLowestPrice($product, $productId);
        if ($lowest !== null) {
            return $lowest;
        }

        if ($product === null) {
            return null;
        }

        $reference = $this->priceCalculator()->getDisplayedReferencePrice($product);

        return $reference > 0 ? $reference : null;
    }

    private function indexedLowestPrice(?Mage_Catalog_Model_Product $product, int $productId): ?float
    {
        if ($product instanceof Mage_Catalog_Model_Product && $product->hasData(self::LOWEST_PRICE_KEY)) {
            $value = $product->getData(self::LOWEST_PRICE_KEY);

            return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
        }

        if ($productId <= 0) {
            return null;
        }

        $row = $this->index()->getRow($productId, $this->websiteId());
        $value = $row === null ? null : (float) $row['lowest_price'];

        if ($product instanceof Mage_Catalog_Model_Product) {
            $product->setData(self::LOWEST_PRICE_KEY, $value);
        }

        return $value !== null && $value > 0 ? $value : null;
    }

    private function getNoticeHtml(int $productId, ?float $lowestPrice): string
    {
        $helper = Mage::helper('mm_omnibus');
        if (!$helper instanceof MM_Omnibus_Helper_Data) {
            return '';
        }

        $period = $helper->getReferencePeriod();
        $amount = $this->formatAmount($lowestPrice);

        $text = $amount === null
            ? $helper->__('Lowest price in the last %d days.', $period)
            : $helper->__('Lowest price in the last %d days: %s', $period, $amount);

        $block = Mage::app()->getLayout()
            ->createBlock('core/template', 'mm_omnibus.notice.' . $productId)
            ->setTemplate('mm/omnibus/notice.phtml')
            ->setData('period', $period)
            ->setData('text', $text);

        return (string) $block->toHtml();
    }

    /**
     * Amount in the currency of the store view, as plain text: the notice keeps
     * its own style on every theme instead of inheriting the price markup.
     */
    private function formatAmount(?float $amount): ?string
    {
        if ($amount === null || $amount <= 0) {
            return null;
        }

        $store = Mage::app()->getStore();
        if (!$store instanceof Mage_Core_Model_Store) {
            return null;
        }

        $formatted = $store->formatPrice($amount, false);

        return is_scalar($formatted) ? (string) $formatted : null;
    }

    private function index(): MM_Omnibus_Model_Resource_Compliance_Index
    {
        $resource = Mage::getResourceSingleton('mm_omnibus/compliance_index');

        if (!$resource instanceof MM_Omnibus_Model_Resource_Compliance_Index) {
            throw new \RuntimeException('The omnibus compliance index resource is not available.');
        }

        return $resource;
    }

    private function priceCalculator(): MM_Omnibus_Model_Service_PriceCalculator
    {
        return new MM_Omnibus_Model_Service_PriceCalculator();
    }

    /**
     * Website of the store view being rendered, null when there is none (admin
     * context): a zero website id must not filter the index, it would hide every row.
     */
    private function websiteId(): ?int
    {
        $store = Mage::app()->getStore();
        if (!$store instanceof Mage_Core_Model_Store) {
            return null;
        }

        $websiteId = (int) $store->getWebsiteId();

        return $websiteId > 0 ? $websiteId : null;
    }
}
