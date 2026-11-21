<?php

/**
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

class MM_Omnibus_Model_Price_History extends Mage_Core_Model_Abstract
{
    public const ACTION_PRODUCT_SAVE = 'catalog_product_save';
    public const ACTION_CATALOGRULE_APPLY = 'catalogrule_apply';

    #[\Override]
    protected function _construct(): void
    {
        $this->_init('mm_omnibus/price_history');
    }
}
