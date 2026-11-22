<?php

/**
 * Product tab with the price history of the product (and of the children of a
 * configurable one), plus the compliance data of the index.
 *
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

class MM_Omnibus_Block_Adminhtml_Catalog_Product_Tab_OmnibusHistory extends Mage_Adminhtml_Block_Widget_Grid implements Mage_Adminhtml_Block_Widget_Tab_Interface
{
    public function __construct($attributes = [])
    {
        parent::__construct($attributes);
        $this->setId('omnibusHistoryTabGrid');
        $this->setDefaultSort('created_at');
        $this->setDefaultDir('DESC');
        $this->setUseAjax(true);
        $this->setSaveParametersInSession(true);
    }

    #[\Override]
    public function getTabLabel(): string
    {
        return Mage::helper('mm_omnibus')->__('Omnibus History');
    }

    #[\Override]
    public function getTabTitle(): string
    {
        return Mage::helper('mm_omnibus')->__('Omnibus Price History');
    }

    #[\Override]
    public function canShowTab(): bool
    {
        return $this->getProductId() !== null;
    }

    #[\Override]
    public function isHidden(): bool
    {
        return false;
    }

    public function getProductId(): ?int
    {
        $product = Mage::registry('current_product');
        if (!$product instanceof Mage_Catalog_Model_Product) {
            return null;
        }

        $productId = (int) $product->getId();

        return $productId > 0 ? $productId : null;
    }

    #[\Override]
    public function getGridUrl()
    {
        return $this->getUrl('adminhtml/omnibus_history/productTabGrid', [
            'id' => $this->getProductId(),
            'grid_id' => $this->getId(),
        ]);
    }

    #[\Override]
    protected function _prepareCollection()
    {
        $productIds = $this->getRelevantProductIds();
        $resource = Mage::getSingleton('core/resource');
        $nameAttribute = Mage::getSingleton('eav/config')->getAttribute('catalog_product', 'name');
        if (!$nameAttribute instanceof Mage_Eav_Model_Entity_Attribute_Abstract) {
            throw new \RuntimeException('The catalog_product name attribute is not available.');
        }
        $nameAttributeId = (int) $nameAttribute->getId();

        /** @var MM_Omnibus_Model_Resource_Price_History_Collection $collection */
        $collection = Mage::getResourceModel('mm_omnibus/price_history_collection');
        $select = $collection->getSelect();

        if ($productIds !== []) {
            $select->where('main_table.product_id IN (?)', $productIds);
        } else {
            $select->where('1 = 0');
        }

        $select->joinLeft(
            ['pe' => $resource->getTableName('catalog_product_entity')],
            'pe.entity_id = main_table.product_id',
            ['sku' => 'pe.sku'],
        );
        $select->joinLeft(
            ['pn' => $resource->getTableName('catalog_product_entity_varchar')],
            'pn.entity_id = main_table.product_id'
            . ' AND pn.attribute_id = ' . $nameAttributeId
            . ' AND pn.store_id = 0',
            ['product_name' => 'pn.value'],
        );
        $select->joinLeft(
            ['cpsl' => new Maho\Db\Expr(
                '(SELECT product_id, MIN(parent_id) AS parent_id FROM '
                . $resource->getTableName('catalog_product_super_link')
                . ' GROUP BY product_id)',
            )],
            'cpsl.product_id = main_table.product_id',
            ['parent_id' => 'cpsl.parent_id'],
        );
        $select->joinLeft(
            ['ppn' => $resource->getTableName('catalog_product_entity_varchar')],
            'ppn.entity_id = cpsl.parent_id'
            . ' AND ppn.attribute_id = ' . $nameAttributeId
            . ' AND ppn.store_id = 0',
            ['parent_name' => 'ppn.value'],
        );
        $select->joinLeft(
            ['ix' => $resource->getTableName('mm_omnibus/compliance_index')],
            'ix.product_id = main_table.product_id',
            [],
        );
        $select->columns([
            'compliance_price' => new Maho\Db\Expr('MAX(ix.reference_price)'),
            'compliance_lowest' => new Maho\Db\Expr('MIN(ix.lowest_price)'),
            'compliance_date' => new Maho\Db\Expr('MIN(ix.lowest_price_date)'),
            'compliance_diff' => new Maho\Db\Expr('MAX(ix.difference)'),
        ]);
        $select->group('main_table.history_id');

        $this->setCollection($collection);

        return parent::_prepareCollection();
    }

    #[\Override]
    protected function _prepareColumns()
    {
        $helper = Mage::helper('mm_omnibus');
        $store = Mage::app()->getStore();
        $currencyCode = $store instanceof Mage_Core_Model_Store ? $store->getCurrentCurrencyCode() : null;

        $this->addColumn('history_id', [
            'header' => $helper->__('#'),
            'align' => 'right',
            'width' => '40px',
            'index' => 'history_id',
            'filter' => false,
            'sortable' => false,
        ]);

        $this->addColumn('sku', [
            'header' => $helper->__('SKU'),
            'align' => 'left',
            'index' => 'sku',
            'sortable' => false,
        ]);

        $this->addColumn('product_name', [
            'header' => $helper->__('Name'),
            'align' => 'left',
            'index' => 'product_name',
            'sortable' => false,
        ]);

        if ($this->isParentProduct()) {
            $this->addColumn('parent_name', [
                'header' => $helper->__('Parent Product'),
                'align' => 'left',
                'width' => '200px',
                'index' => 'parent_name',
                'sortable' => false,
            ]);
        }

        $this->addColumn('product_id', [
            'header' => $helper->__('Product ID'),
            'align' => 'right',
            'width' => '60px',
            'index' => 'product_id',
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

        $this->addColumn('action', [
            'header' => $helper->__('Action'),
            'align' => 'left',
            'width' => '150px',
            'index' => 'action',
            'type' => 'options',
            'options' => [
                MM_Omnibus_Model_Price_History::ACTION_PRODUCT_SAVE => $helper->getActionLabel(
                    MM_Omnibus_Model_Price_History::ACTION_PRODUCT_SAVE,
                ),
                MM_Omnibus_Model_Price_History::ACTION_CATALOGRULE_APPLY => $helper->getActionLabel(
                    MM_Omnibus_Model_Price_History::ACTION_CATALOGRULE_APPLY,
                ),
            ],
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

        $this->addColumn('discount_pct', [
            'header' => $helper->__('Discount %'),
            'align' => 'center',
            'width' => '80px',
            'index' => 'discount_pct',
            'filter' => false,
            'sortable' => false,
            'frame_callback' => $this->decorateDiscount(...),
        ]);

        $this->addColumn('compliance_price', [
            'header' => $helper->__('Current Reference'),
            'align' => 'right',
            'index' => 'compliance_price',
            'type' => 'currency',
            'currency_code' => $currencyCode,
            'sortable' => false,
        ]);

        $this->addColumn('compliance_lowest', [
            'header' => $helper->__('Lowest Price (%dd)', $helper->getReferencePeriod()),
            'align' => 'right',
            'index' => 'compliance_lowest',
            'type' => 'currency',
            'currency_code' => $currencyCode,
            'sortable' => false,
        ]);

        $this->addColumn('compliance_diff', [
            'header' => $helper->__('Inflation'),
            'align' => 'right',
            'index' => 'compliance_diff',
            'type' => 'currency',
            'currency_code' => $currencyCode,
            'sortable' => false,
            'frame_callback' => $this->decorateDifference(...),
        ]);

        return parent::_prepareColumns();
    }

    public function decorateDiscount(
        mixed $value,
        Maho\DataObject $row,
        Mage_Adminhtml_Block_Widget_Grid_Column $column,
        bool $isExport = false,
    ): string {
        $base = max((float) $row->getData('price'), (float) $row->getData('special_price'));
        if ($base <= 0) {
            return '';
        }

        $discount = round((($base - (float) $row->getData('final_price')) / $base) * 100, 2);

        return $discount > 0 ? (string) $discount : '';
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

    public function decorateDifference(
        mixed $value,
        Maho\DataObject $row,
        Mage_Adminhtml_Block_Widget_Grid_Column $column,
        bool $isExport = false,
    ): string {
        if ((int) $row->getData('is_reference') !== 1) {
            return '';
        }

        // A currency frame callback receives the amount already formatted, so the
        // stored value comes from the row.
        $difference = (float) $row->getData('compliance_diff');
        if (abs($difference) < 0.0001) {
            return '';
        }

        $currency = Mage::helper('core')->currency(abs($difference), true, false);
        $label = $difference > 0 ? '+' . $currency : $currency;

        if ($isExport) {
            return strip_tags($label);
        }

        $class = $difference > 0 ? 'critical' : 'notice';

        return '<span class="grid-severity-' . $class . '"><span>' . $label . '</span></span>';
    }

    private function isParentProduct(): bool
    {
        $product = Mage::registry('current_product');

        return $product instanceof Mage_Catalog_Model_Product
            && $product->getTypeId() === Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE;
    }

    /**
     * @return int[]
     */
    private function getRelevantProductIds(): array
    {
        $productId = $this->getProductId();
        if ($productId === null) {
            return [];
        }

        $ids = [$productId];
        if ($this->isParentProduct()) {
            foreach (Mage::getModel('catalog/product_type_configurable')->getChildrenIds($productId) as $group) {
                foreach ($group as $childId) {
                    $ids[] = (int) $childId;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
