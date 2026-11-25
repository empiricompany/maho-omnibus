<?php

/**
 * Full price history, one row per record.
 *
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

class MM_Omnibus_Block_Adminhtml_History_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct($attributes = [])
    {
        parent::__construct($attributes);
        $this->setId('omnibusHistoryGrid');
        $this->setDefaultSort('created_at');
        $this->setDefaultDir('DESC');
        $this->setSaveParametersInSession(true);
        $this->setUseAjax(true);
    }

    #[\Override]
    protected function _prepareCollection()
    {
        $this->setCollection(Mage::getResourceModel('mm_omnibus/price_history_collection'));

        return parent::_prepareCollection();
    }

    #[\Override]
    protected function _prepareColumns()
    {
        $helper = Mage::helper('mm_omnibus');
        $store = Mage::app()->getStore();
        $currencyCode = $store instanceof Mage_Core_Model_Store ? $store->getCurrentCurrencyCode() : null;

        $this->addColumn('history_id', [
            'header' => $helper->__('ID'),
            'align' => 'right',
            'width' => '50px',
            'index' => 'history_id',
        ]);

        $this->addColumn('product_id', [
            'header' => $helper->__('Product ID'),
            'align' => 'right',
            'width' => '80px',
            'index' => 'product_id',
        ]);

        $this->addColumn('action', [
            'header' => $helper->__('Action'),
            'align' => 'left',
            'width' => '150px',
            'index' => 'action',
            'frame_callback' => $this->decorateAction(...),
        ]);

        $this->addColumn('price', [
            'header' => $helper->__('Base Price'),
            'align' => 'right',
            'width' => '100px',
            'index' => 'price',
            'type' => 'currency',
            'currency_code' => $currencyCode,
        ]);

        $this->addColumn('special_price', [
            'header' => $helper->__('Special Price'),
            'align' => 'right',
            'width' => '100px',
            'index' => 'special_price',
            'type' => 'currency',
            'currency_code' => $currencyCode,
        ]);

        $this->addColumn('final_price', [
            'header' => $helper->__('Final Price'),
            'align' => 'right',
            'width' => '100px',
            'index' => 'final_price',
            'type' => 'currency',
            'currency_code' => $currencyCode,
        ]);

        $this->addColumn('website_id', [
            'header' => $helper->__('Website'),
            'align' => 'left',
            'index' => 'website_id',
            'type' => 'options',
            'options' => Mage::getSingleton('adminhtml/system_store')->getWebsiteOptionHash(),
        ]);

        $this->addColumn('created_at', [
            'header' => $helper->__('Date'),
            'align' => 'right',
            'width' => '150px',
            'index' => 'created_at',
            'type' => 'datetime',
        ]);

        $this->addColumn('is_reference', [
            'header' => $helper->__('Reference'),
            'align' => 'center',
            'width' => '60px',
            'index' => 'is_reference',
            'filter' => false,
            'sortable' => false,
            'frame_callback' => $this->decorateReferenceFlag(...),
        ]);

        $this->addExportType('*/*/exportCsv', $helper->__('CSV'));
        $this->addExportType('*/*/exportExcel', $helper->__('Excel'));

        return parent::_prepareColumns();
    }

    #[\Override]
    public function getRowUrl($row): string
    {
        return $this->getUrl('adminhtml/catalog_product/edit', ['id' => (int) $row->getData('product_id')]);
    }

    #[\Override]
    public function getGridUrl()
    {
        return $this->getUrl('*/*/grid', ['_current' => true]);
    }

    public function decorateAction(
        mixed $value,
        Maho\DataObject $row,
        Mage_Adminhtml_Block_Widget_Grid_Column $column,
        bool $isExport = false,
    ): string {
        return Mage::helper('mm_omnibus')->getActionLabel((string) $value);
    }

    public function decorateReferenceFlag(
        mixed $value,
        Maho\DataObject $row,
        Mage_Adminhtml_Block_Widget_Grid_Column $column,
        bool $isExport = false,
    ): string {
        if ((int) $value !== 1) {
            return '';
        }

        if ($isExport) {
            return Mage::helper('mm_omnibus')->__('Yes');
        }

        return '<span class="grid-severity-notice"><span>'
            . Mage::helper('mm_omnibus')->__('Reference')
            . '</span></span>';
    }
}
