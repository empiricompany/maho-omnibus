<?php

/**
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

class MM_Omnibus_Adminhtml_Omnibus_HistoryController extends Mage_Adminhtml_Controller_Action
{
    #[Maho\Config\Route('/admin/omnibus_history/index')]
    public function indexAction(): void
    {
        $this->initAction();
        $this->_title($this->__('Catalog'))->_title($this->__('Omnibus Price History'));
        $this->renderLayout();
    }

    #[Maho\Config\Route('/admin/omnibus_history/grid')]
    public function gridAction(): void
    {
        $this->loadLayout();
        $this->renderLayout();
    }

    /**
     * Grid of the product tab: the product is registered for the collection, and
     * the grid id comes from the request so several tabs can coexist.
     */
    #[Maho\Config\Route('/admin/omnibus_history/productTabGrid')]
    public function productTabGridAction(): void
    {
        $productId = (int) $this->getRequest()->getParam('id');
        if ($productId) {
            Mage::register('current_product', Mage::getModel('catalog/product')->load($productId));
        }

        $this->loadLayout();

        $gridId = $this->getRequest()->getParam('grid_id');
        if ($gridId) {
            $block = $this->getLayout()->getBlock('omnibus.history.tab.grid');
            if ($block) {
                $block->setId($gridId);
            }
        }

        $this->renderLayout();
    }

    #[Maho\Config\Route('/admin/omnibus_history/exportCsv')]
    public function exportCsvAction(): void
    {
        $this->_validateFormKey();
        $content = $this->getLayout()->createBlock('mm_omnibus/adminhtml_history_grid')->getCsv();
        $this->sendUploadResponse('omnibus_price_history.csv', (string) $content);
    }

    #[Maho\Config\Route('/admin/omnibus_history/exportExcel')]
    public function exportExcelAction(): void
    {
        $this->_validateFormKey();
        $content = $this->getLayout()->createBlock('mm_omnibus/adminhtml_history_grid')->getExcel('omnibus_price_history.xml');
        $this->sendUploadResponse('omnibus_price_history.xml', (string) $content);
    }

    #[\Override]
    protected function _isAllowed(): bool
    {
        return Mage::getSingleton('admin/session')->isAllowed('catalog/mm_omnibus');
    }

    private function initAction(): static
    {
        $helper = Mage::helper('mm_omnibus');

        $this->loadLayout()
            ->_setActiveMenu('catalog/mm_omnibus')
            ->_addBreadcrumb($helper->__('Catalog'), $helper->__('Catalog'))
            ->_addBreadcrumb($helper->__('Omnibus Price History'), $helper->__('Omnibus Price History'));

        return $this;
    }

    private function sendUploadResponse(string $fileName, string $content): void
    {
        $this->getResponse()
            ->setHeader('HTTP/1.1 200 OK', '')
            ->setHeader('Content-Type', 'application/octet-stream')
            ->setHeader('Content-Disposition', 'attachment; filename=' . $fileName)
            ->setBody($content);
    }
}
