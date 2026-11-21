<?php

/**
 * Dashboard container: the grid plus the legend and the website filter.
 *
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

class MM_Omnibus_Block_Adminhtml_Dashboard extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        $this->_blockGroup = 'mm_omnibus';
        $this->_controller = 'adminhtml_dashboard';
        $this->_headerText = Mage::helper('mm_omnibus')->__('Omnibus Price Compliance Dashboard');

        parent::__construct();
        $this->_removeButton('add');
    }

    #[\Override]
    protected function _prepareLayout(): static
    {
        $this->setTemplate('mm/omnibus/dashboard/container.phtml');

        $legend = $this->getLayout()
            ->createBlock('core/template', 'dashboard.legend')
            ->setTemplate('mm/omnibus/dashboard/legend.phtml')
            ->setData(
                'computed_at',
                Mage::getResourceSingleton('mm_omnibus/compliance_index')->getLastComputedAt(),
            );
        $this->setChild('legend', $legend);

        return parent::_prepareLayout();
    }

    public function getReferencePeriod(): int
    {
        return Mage::helper('mm_omnibus')->getReferencePeriod();
    }

    /**
     * Website labels for the filter, empty when prices are global.
     *
     * @return array<int, string>
     */
    public function getWebsiteOptions(): array
    {
        if (Mage::helper('mm_omnibus')->isPriceGlobal()) {
            return [];
        }

        $options = [];
        foreach (Mage::getSingleton('adminhtml/system_store')->getWebsiteOptionHash() as $websiteId => $label) {
            $options[(int) $websiteId] = is_scalar($label)
                ? (string) $label
                : implode(', ', array_map(strval(...), (array) $label));
        }

        return $options;
    }
}
