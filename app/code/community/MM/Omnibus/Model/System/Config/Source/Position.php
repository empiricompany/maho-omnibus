<?php

/**
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

class MM_Omnibus_Model_System_Config_Source_Position
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $helper = Mage::helper('mm_omnibus');

        return [
            [
                'value' => MM_Omnibus_Helper_Data::POSITION_INSIDE,
                'label' => $helper->__('Inside (default)'),
            ],
            [
                'value' => MM_Omnibus_Helper_Data::POSITION_BEFORE,
                'label' => $helper->__('Before'),
            ],
            [
                'value' => MM_Omnibus_Helper_Data::POSITION_AFTER,
                'label' => $helper->__('After'),
            ],
        ];
    }
}
