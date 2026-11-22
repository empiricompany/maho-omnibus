<?php

/**
 * Products that do not comply with the Omnibus directive.
 *
 * The grid reads `omnibus_compliance_index`, so the page never aggregates the
 * price history: the index is rebuilt by the module observers and by the daily
 * cron. `computed_at` is printed in the legend, so a stale index is visible.
 *
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

class MM_Omnibus_Block_Adminhtml_Dashboard_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    /**
     * Columns the user may sort by, with the aggregate each one sorts on.
     *
     * The collection groups by product, so only aggregates are legal here: with
     * `ONLY_FULL_GROUP_BY` a plain column of a joined table is rejected.
     */
    private const SORTABLE_COLUMNS = [
        'entity_id' => 'MIN(e.entity_id)',
        'sku' => 'MIN(e.sku)',
        'status' => 'MAX(status)',
        'reference_price' => 'MAX(ix.reference_price)',
        'lowest_price' => 'MIN(ix.lowest_price)',
        'lowest_price_date' => 'MIN(ix.lowest_price_date)',
        'reference_recorded_at' => 'MAX(ix.reference_recorded_at)',
        'expires_at' => 'MIN(ix.expires_at)',
        'difference' => 'MAX(ix.difference)',
    ];

    /** @var array<int, string[]>|null */
    private ?array $websiteNamesByProduct = null;

    public function __construct($attributes = [])
    {
        parent::__construct($attributes);
        $this->setId('omnibusDashboardGrid');
        $this->setDefaultSort('difference');
        $this->setDefaultDir('DESC');
        $this->setSaveParametersInSession(true);
        $this->setUseAjax(true);
    }

    /**
     * Keeps a configurable product and its children together, then applies the
     * sort the user asked for inside each group.
     */
    #[\Override]
    protected function _setCollectionOrder($column)
    {
        $collection = $this->getCollection();
        if (!$collection || !$column) {
            return $this;
        }

        $filterIndex = $column->getFilterIndex();
        $columnIndex = $filterIndex !== null && $filterIndex !== '' ? $filterIndex : $column->getIndex();
        $index = is_string($columnIndex) ? $columnIndex : '';
        $expression = self::SORTABLE_COLUMNS[$index] ?? self::SORTABLE_COLUMNS['entity_id'];
        $direction = strtoupper((string) $column->getDir()) === 'DESC' ? 'DESC' : 'ASC';

        $select = $collection->getSelect();
        $select->reset(Maho\Db\Select::ORDER);
        $select->order(new Maho\Db\Expr(
            'CASE WHEN cpsl.parent_id > 0 THEN cpsl.parent_id'
            . " WHEN e.type_id = 'configurable' THEN e.entity_id ELSE 999999 END ASC",
        ));
        $select->order(new Maho\Db\Expr('CASE WHEN cpsl.parent_id > 0 THEN 1 ELSE 0 END ASC'));
        $select->order(new Maho\Db\Expr($expression . ' ' . $direction));
        $select->order(new Maho\Db\Expr('e.entity_id ASC'));

        return $this;
    }

    /**
     * The website parameter must always come from the request, never from the
     * session, otherwise the website switcher stops working.
     */
    #[\Override]
    public function getParam($paramName, $default = null)
    {
        if ($paramName === 'website') {
            return $this->getRequest()->getParam($paramName, $default);
        }

        return parent::getParam($paramName, $default);
    }

    #[\Override]
    protected function _prepareCollection()
    {
        $websiteId = $this->getRequest()->getParam('website');
        $websiteId = ($websiteId === null || $websiteId === '') ? null : (int) $websiteId;

        $resource = Mage::getSingleton('core/resource');
        $nameAttributeId = $this->nameAttributeId();

        /** @var Mage_Catalog_Model_Resource_Product_Collection $collection */
        $collection = Mage::getResourceModel('catalog/product_collection');
        $collection->addAttributeToSelect(['name', 'sku']);
        $collection->joinAttribute(
            'status',
            'catalog_product/status',
            'entity_id',
            null,
            'left',
            Mage_Core_Model_App::ADMIN_STORE_ID,
        );
        $collection->joinAttribute(
            'visibility',
            'catalog_product/visibility',
            'entity_id',
            null,
            'left',
            Mage_Core_Model_App::ADMIN_STORE_ID,
        );

        $select = $collection->getSelect();
        $select->joinInner(
            ['ix' => $resource->getTableName('mm_omnibus/compliance_index')],
            'ix.product_id = e.entity_id',
            [],
        );
        $select->joinLeft(
            ['cpsl' => new Maho\Db\Expr(
                '(SELECT product_id, MIN(parent_id) AS parent_id FROM '
                . $resource->getTableName('catalog_product_super_link')
                . ' GROUP BY product_id)',
            )],
            'cpsl.product_id = e.entity_id',
            [],
        );
        $select->joinLeft(
            ['pname' => $resource->getTableName('catalog_product_entity_varchar')],
            'pname.entity_id = cpsl.parent_id'
            . ' AND pname.attribute_id = ' . $nameAttributeId
            . ' AND pname.store_id = 0',
            [],
        );
        $select->columns([
            'reference_price' => new Maho\Db\Expr('MAX(ix.reference_price)'),
            'reference_recorded_at' => new Maho\Db\Expr('MAX(ix.reference_recorded_at)'),
            'lowest_price' => new Maho\Db\Expr('MIN(ix.lowest_price)'),
            'lowest_price_date' => new Maho\Db\Expr('MIN(ix.lowest_price_date)'),
            'expires_at' => new Maho\Db\Expr('MIN(ix.expires_at)'),
            'difference' => new Maho\Db\Expr('MAX(ix.difference)'),
            'computed_at' => new Maho\Db\Expr('MAX(ix.computed_at)'),
            'parent_product_id' => new Maho\Db\Expr('COALESCE(cpsl.parent_id, 0)'),
            'parent_name' => 'pname.value',
        ]);
        $select->where('ix.is_noncompliant = 1');

        if ($websiteId !== null) {
            $select->where('ix.website_id = ?', $websiteId);
        }

        $select->group('e.entity_id');

        $this->setCollection($collection);

        return parent::_prepareCollection();
    }

    #[\Override]
    protected function _prepareColumns()
    {
        $helper = Mage::helper('mm_omnibus');
        $store = Mage::app()->getStore();
        $currencyCode = $store instanceof Mage_Core_Model_Store ? $store->getCurrentCurrencyCode() : null;

        $this->addColumn('entity_id', [
            'header' => $helper->__('ID'),
            'align' => 'right',
            'width' => '60px',
            'index' => 'entity_id',
        ]);

        $this->addColumn('sku', [
            'header' => $helper->__('SKU'),
            'align' => 'left',
            'index' => 'sku',
        ]);

        $this->addColumn('name', [
            'header' => $helper->__('Name'),
            'align' => 'left',
            'index' => 'name',
            'sortable' => false,
        ]);

        $this->addColumn('parent_name', [
            'header' => $helper->__('Parent Product'),
            'align' => 'left',
            'width' => '200px',
            'index' => 'parent_name',
            'sortable' => false,
            'filter_condition_callback' => $this->filterParentName(...),
            'frame_callback' => $this->decorateParentName(...),
        ]);

        $this->addColumn('visibility', [
            'header' => $helper->__('Visibility'),
            'align' => 'left',
            'width' => '120px',
            'index' => 'visibility',
            'sortable' => false,
            'type' => 'options',
            'options' => Mage_Catalog_Model_Product_Visibility::getOptionArray(),
        ]);

        $this->addColumn('status', [
            'header' => $helper->__('Status'),
            'align' => 'center',
            'width' => '80px',
            'index' => 'status',
            'type' => 'options',
            'options' => Mage_Catalog_Model_Product_Status::getOptionArray(),
            'frame_callback' => $this->decorateStatus(...),
        ]);

        if (!$helper->isPriceGlobal()) {
            $this->addColumn('websites', [
                'header' => $helper->__('Websites'),
                'align' => 'left',
                'index' => 'websites',
                'sortable' => false,
                'filter_condition_callback' => $this->filterWebsites(...),
                'frame_callback' => $this->decorateWebsites(...),
            ]);
        }

        $this->addColumn('reference_price', [
            'header' => $helper->__('Base Price'),
            'align' => 'right',
            'index' => 'reference_price',
            'type' => 'currency',
            'currency_code' => $currencyCode,
            'filter_condition_callback' => $this->filterNumericColumn(...),
        ]);

        $this->addColumn('lowest_price', [
            'header' => $helper->__('Lowest Price (%dd)', $helper->getReferencePeriod()),
            'align' => 'right',
            'index' => 'lowest_price',
            'frame_callback' => $this->decorateLowestPrice(...),
            'filter_condition_callback' => $this->filterNumericColumn(...),
        ]);

        $this->addColumn('lowest_price_date', [
            'header' => $helper->__('Lowest Price Date'),
            'align' => 'center',
            'index' => 'lowest_price_date',
            'type' => 'datetime',
        ]);

        $this->addColumn('reference_recorded_at', [
            'header' => $helper->__('Active Promotion Date'),
            'align' => 'center',
            'index' => 'reference_recorded_at',
            'type' => 'datetime',
        ]);

        $this->addColumn('expires_at', [
            'header' => $helper->__('Days Until Expiry'),
            'align' => 'center',
            'width' => '80px',
            'index' => 'expires_at',
            'type' => 'datetime',
            'filter' => false,
            'frame_callback' => $this->decorateDaysUntilExpiry(...),
        ]);

        $this->addColumn('difference', [
            'header' => $helper->__('Base Inflation'),
            'align' => 'right',
            'index' => 'difference',
            'type' => 'currency',
            'currency_code' => $currencyCode,
            'frame_callback' => $this->decorateBaseInflation(...),
            'filter_condition_callback' => $this->filterNumericColumn(...),
        ]);

        $this->addColumn('action', [
            'header' => $helper->__('Action'),
            'width' => '100px',
            'type' => 'text',
            'filter' => false,
            'sortable' => false,
            'frame_callback' => $this->decorateActionLink(...),
        ]);

        $this->addExportType('*/*/exportCsv', $helper->__('CSV'));
        $this->addExportType('*/*/exportExcel', $helper->__('Excel'));

        return parent::_prepareColumns();
    }

    #[\Override]
    public function getRowUrl($row)
    {
        $parentProductId = (int) $row->getData('parent_product_id');

        return $this->getUrl('adminhtml/catalog_product/edit', [
            'id' => $parentProductId > 0 ? $parentProductId : $row->getId(),
        ]);
    }

    #[\Override]
    public function getGridUrl()
    {
        return $this->getUrl('*/*/grid', ['_current' => true]);
    }

    /**
     * Children of a configurable product link to the parent, where the price
     * supplement is managed.
     */
    public function decorateActionLink(
        mixed $value,
        Maho\DataObject $row,
        Mage_Adminhtml_Block_Widget_Grid_Column $column,
        bool $isExport = false,
    ): string {
        $parentProductId = (int) $row->getData('parent_product_id');
        $productId = $parentProductId > 0 ? $parentProductId : (int) $row->getId();
        $caption = Mage::helper('mm_omnibus')->__('Edit Product');

        if ($isExport) {
            return $caption;
        }

        return '<a href="' . $this->getUrl('adminhtml/catalog_product/edit', ['id' => $productId]) . '" class="link">'
            . $this->escapeHtml($caption)
            . '</a>';
    }

    public function decorateStatus(
        mixed $value,
        Maho\DataObject $row,
        Mage_Adminhtml_Block_Widget_Grid_Column $column,
        bool $isExport = false,
    ): string {
        if ($value === null || $value === '') {
            return Mage::helper('mm_omnibus')->__('N/A');
        }

        $statusKey = is_scalar($value) ? (string) $value : '';
        $label = (string) (Mage_Catalog_Model_Product_Status::getOptionArray()[$statusKey] ?? $statusKey);

        if ($isExport) {
            return $label;
        }

        $class = (int) $value === Mage_Catalog_Model_Product_Status::STATUS_ENABLED
            ? 'grid-severity-notice'
            : 'grid-severity-critical';

        return '<span class="' . $class . '"><span>' . $this->escapeHtml($label) . '</span></span>';
    }

    /**
     * Filters the columns of the index, which live in a joined table.
     */
    public function filterNumericColumn(
        Mage_Catalog_Model_Resource_Product_Collection $collection,
        Mage_Adminhtml_Block_Widget_Grid_Column $column,
    ): self {
        $value = $this->filterValue($column);
        if (!is_array($value)) {
            return $this;
        }

        $expressions = [
            'reference_price' => 'ix.reference_price',
            'lowest_price' => 'ix.lowest_price',
            'difference' => 'ix.difference',
        ];
        $index = $column->getIndex();
        $expression = is_string($index) ? ($expressions[$index] ?? null) : null;
        if ($expression === null) {
            return $this;
        }

        $select = $collection->getSelect();
        if (isset($value['from']) && $value['from'] !== '') {
            $select->where($expression . ' >= ?', (float) $value['from']);
        }
        if (isset($value['to']) && $value['to'] !== '') {
            $select->where($expression . ' <= ?', (float) $value['to']);
        }

        return $this;
    }

    public function filterWebsites(
        Mage_Catalog_Model_Resource_Product_Collection $collection,
        Mage_Adminhtml_Block_Widget_Grid_Column $column,
    ): self {
        $value = $this->filterValue($column);
        if (!is_scalar($value) || (string) $value === '') {
            return $this;
        }

        $resource = Mage::getSingleton('core/resource');
        $collection->getSelect()->where(
            'e.entity_id IN (SELECT cpw_filter.product_id FROM '
            . $resource->getTableName('catalog_product_website')
            . ' AS cpw_filter WHERE cpw_filter.website_id = ?)',
            (int) $value,
        );

        return $this;
    }

    /**
     * Filters the configurable children by the name of their parent.
     */
    public function filterParentName(
        Mage_Catalog_Model_Resource_Product_Collection $collection,
        Mage_Adminhtml_Block_Widget_Grid_Column $column,
    ): self {
        $value = $this->filterValue($column);
        if (!is_scalar($value) || (string) $value === '') {
            return $this;
        }
        $value = (string) $value;

        $resource = Mage::getSingleton('core/resource');
        $adapter = $collection->getSelect()->getAdapter();
        $nameAttributeId = $this->nameAttributeId();

        $collection->getSelect()->where(
            'e.entity_id IN ('
            . 'SELECT cpsl_filter.product_id FROM '
            . $resource->getTableName('catalog_product_super_link') . ' AS cpsl_filter '
            . 'WHERE cpsl_filter.parent_id IN ('
            . 'SELECT cpe_name_filter.entity_id FROM '
            . $resource->getTableName('catalog_product_entity_varchar') . ' AS cpe_name_filter '
            . 'WHERE cpe_name_filter.attribute_id = ' . $nameAttributeId . ' '
            . 'AND cpe_name_filter.store_id = 0 '
            . 'AND cpe_name_filter.value LIKE ' . $adapter->quote('%' . $value . '%') . '))',
        );

        return $this;
    }

    public function decorateWebsites(
        mixed $value,
        Maho\DataObject $row,
        Mage_Adminhtml_Block_Widget_Grid_Column $column,
        bool $isExport = false,
    ): string {
        $names = $this->getWebsiteNamesByProduct()[(int) $row->getId()] ?? [];
        if ($names === []) {
            return Mage::helper('mm_omnibus')->__('N/A');
        }

        if ($isExport) {
            return implode(', ', $names);
        }

        return implode('<br/>', array_map(
            fn(string $name): string => '<span class="website-label">' . $this->escapeHtml($name) . '</span>',
            $names,
        ));
    }

    public function decorateDaysUntilExpiry(
        mixed $value,
        Maho\DataObject $row,
        Mage_Adminhtml_Block_Widget_Grid_Column $column,
        bool $isExport = false,
    ): string {
        // A frame callback receives the value already formatted by the datetime
        // renderer, so the stored date comes from the row.
        $expiresAt = $row->getData('expires_at');
        if ($expiresAt === null || $expiresAt === '') {
            return Mage::helper('mm_omnibus')->__('N/A');
        }

        $timestamp = strtotime((string) $expiresAt);
        if ($timestamp === false) {
            return Mage::helper('mm_omnibus')->__('N/A');
        }

        $days = (int) floor(($timestamp - time()) / 86400);

        return $days . ' ' . Mage::helper('mm_omnibus')->__('days');
    }

    public function decorateBaseInflation(
        mixed $value,
        Maho\DataObject $row,
        Mage_Adminhtml_Block_Widget_Grid_Column $column,
        bool $isExport = false,
    ): string {
        $referencePrice = (float) $row->getData('reference_price');
        $lowestPrice = $row->getData('lowest_price');
        if ($lowestPrice === null || $lowestPrice === '') {
            return Mage::helper('mm_omnibus')->__('N/A');
        }

        $inflation = $referencePrice - (float) $lowestPrice;

        if ($isExport) {
            return $inflation > 0
                ? sprintf('+%.2f (base: %.2f, min: %.2f)', $inflation, $referencePrice, (float) $lowestPrice)
                : '0.00';
        }

        if ($inflation <= 0) {
            return '<span class="grid-severity-notice"><span>' . $this->__('OK') . '</span></span>';
        }

        $title = Mage::helper('mm_omnibus')->__(
            'Base: %s, lowest (%dd): %s',
            $this->formatPrice($referencePrice),
            Mage::helper('mm_omnibus')->getReferencePeriod(),
            $this->formatPrice((float) $lowestPrice),
        );

        return sprintf(
            '<span class="grid-severity-critical" title="%s"><span>+%s</span></span>',
            $this->escapeHtml($title),
            $this->formatPrice($inflation),
        );
    }

    public function decorateLowestPrice(
        mixed $value,
        Maho\DataObject $row,
        Mage_Adminhtml_Block_Widget_Grid_Column $column,
        bool $isExport = false,
    ): string {
        if ($value === null || $value === '' || (float) $value <= 0) {
            return Mage::helper('mm_omnibus')->__('N/A');
        }

        $formatted = $this->formatPrice((float) $value);

        if (!$isExport && (int) $row->getData('parent_product_id') > 0) {
            $formatted .= ' <span class="note">(' . Mage::helper('mm_omnibus')->__('composite') . ')</span>';
        }

        return $formatted;
    }

    public function decorateParentName(
        mixed $value,
        Maho\DataObject $row,
        Mage_Adminhtml_Block_Widget_Grid_Column $column,
        bool $isExport = false,
    ): string {
        if ($value === null || $value === '') {
            return Mage::helper('mm_omnibus')->__('-');
        }

        return $isExport ? (string) $value : '<span class="parent-label">' . $this->escapeHtml((string) $value) . '</span>';
    }

    /**
     * Website names of the products of the current page, in one query.
     *
     * @return array<int, string[]>
     */
    private function getWebsiteNamesByProduct(): array
    {
        if ($this->websiteNamesByProduct !== null) {
            return $this->websiteNamesByProduct;
        }

        $this->websiteNamesByProduct = [];

        $productIds = [];
        foreach ($this->getCollection() as $row) {
            $productIds[] = (int) $row->getId();
        }

        if ($productIds === []) {
            return $this->websiteNamesByProduct;
        }

        $resource = Mage::getSingleton('core/resource');
        $readAdapter = Mage::helper('mm_omnibus')->readAdapter();
        $select = $readAdapter->select()
            ->from(['cpw' => $resource->getTableName('catalog_product_website')], ['product_id'])
            ->joinInner(
                ['cw' => $resource->getTableName('core_website')],
                'cw.website_id = cpw.website_id',
                ['name' => 'cw.name'],
            )
            ->where('cpw.product_id IN (?)', $productIds)
            ->order(['cw.name ASC']);

        foreach ($readAdapter->fetchAll($select) as $row) {
            $this->websiteNamesByProduct[(int) $row['product_id']][] = (string) $row['name'];
        }

        return $this->websiteNamesByProduct;
    }

    /**
     * @throws \RuntimeException When the attribute does not exist.
     */
    private function nameAttributeId(): int
    {
        $attribute = Mage::getSingleton('eav/config')->getAttribute('catalog_product', 'name');
        if (!$attribute instanceof Mage_Eav_Model_Entity_Attribute_Abstract) {
            throw new \RuntimeException('The catalog_product name attribute is not available.');
        }

        return (int) $attribute->getId();
    }

    private function formatPrice(float $amount): string
    {
        $store = Mage::app()->getStore();
        if (!$store instanceof Mage_Core_Model_Store) {
            return number_format($amount, 2, '.', '');
        }

        return (string) $store->formatPrice($amount, false);
    }

    /**
     * Value of the column filter, null when the column has none.
     */
    private function filterValue(Mage_Adminhtml_Block_Widget_Grid_Column $column): mixed
    {
        $filter = $column->getFilter();

        return $filter === false ? null : $filter->getValue();
    }
}
