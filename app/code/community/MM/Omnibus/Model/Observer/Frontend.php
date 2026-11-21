<?php

/**
 * Adds the omnibus notice next to a crossed-out price.
 *
 * The block markup is read from the transport and modified with the DOM helper;
 * the notice itself is rendered once per request, since it only depends on the
 * reference period of the store view.
 *
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

class MM_Omnibus_Model_Observer_Frontend
{
    private ?string $noticeHtml = null;

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

        $notice = $this->getNoticeHtml();
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

    private function getNoticeHtml(): string
    {
        if ($this->noticeHtml !== null) {
            return $this->noticeHtml;
        }

        $period = Mage::helper('mm_omnibus')->getReferencePeriod();
        $block = Mage::app()->getLayout()
            ->createBlock('core/template', 'mm_omnibus.notice')
            ->setTemplate('mm/omnibus/notice.phtml')
            ->setData('period', $period)
            ->setData('text', Mage::helper('mm_omnibus')->__('Lowest price in the last %d days.', $period));

        return $this->noticeHtml = (string) $block->toHtml();
    }
}
