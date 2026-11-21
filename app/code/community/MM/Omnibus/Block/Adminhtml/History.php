<?php

/**
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

class MM_Omnibus_Block_Adminhtml_History extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        $this->_blockGroup = 'mm_omnibus';
        $this->_controller = 'adminhtml_history';
        $this->_headerText = Mage::helper('mm_omnibus')->__('Omnibus Price History');

        parent::__construct();
        $this->_removeButton('add');
    }
}
