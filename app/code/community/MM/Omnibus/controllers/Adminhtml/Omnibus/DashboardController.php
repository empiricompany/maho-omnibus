<?php

/**
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

class MM_Omnibus_Adminhtml_Omnibus_DashboardController extends Mage_Adminhtml_Controller_Action
{
    #[Maho\Config\Route('/admin/omnibus_dashboard/index')]
    public function indexAction(): void
    {
        $this->initAction();
        $this->_title($this->__('Catalog'))->_title($this->__('Omnibus Dashboard'));
        $this->renderLayout();
    }

    #[Maho\Config\Route('/admin/omnibus_dashboard/grid')]
    public function gridAction(): void
    {
        $this->loadLayout();
        $this->renderLayout();
    }

    #[Maho\Config\Route('/admin/omnibus_dashboard/exportCsv')]
    public function exportCsvAction(): void
    {
        $this->_validateFormKey();
        $fileName = 'omnibus_non_compliant_' . str_replace([' ', ':'], ['_', ''], Mage_Core_Model_Locale::nowUtc()) . '.csv';
        $content = $this->getLayout()->createBlock('mm_omnibus/adminhtml_dashboard_grid')->getCsv();
        $this->sendUploadResponse($fileName, (string) $content);
    }

    #[Maho\Config\Route('/admin/omnibus_dashboard/exportExcel')]
    public function exportExcelAction(): void
    {
        $this->_validateFormKey();
        $fileName = 'omnibus_non_compliant_' . str_replace([' ', ':'], ['_', ''], Mage_Core_Model_Locale::nowUtc()) . '.xml';
        $content = $this->getLayout()->createBlock('mm_omnibus/adminhtml_dashboard_grid')->getExcel($fileName);
        $this->sendUploadResponse($fileName, (string) $content);
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
            ->_addBreadcrumb($helper->__('Omnibus Dashboard'), $helper->__('Omnibus Dashboard'));

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
