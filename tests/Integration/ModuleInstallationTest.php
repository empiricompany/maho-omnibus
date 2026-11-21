<?php

/**
 * The module must install itself on a clean store: tables, indexer, defaults and
 * autoloadable classes. Everything here is read-only.
 *
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

namespace MM\Omnibus\Tests\Integration;

use Mage;

final class ModuleInstallationTest extends MahoTestCase
{
    public function testTheModuleIsActive(): void
    {
        self::assertTrue(Mage::getConfig()->getModuleConfig('MM_Omnibus')->is('active', 'true'));
    }

    public function testTheInstallerCreatedBothTables(): void
    {
        self::assertSame(0, $this->countRows($this->table('mm_omnibus/price_history')));
        self::assertSame(0, $this->countRows($this->table('mm_omnibus/compliance_index')));
    }

    public function testTheComplianceIndexerIsRegistered(): void
    {
        $process = Mage::getSingleton('index/indexer')->getProcessByCode('mm_omnibus_compliance');

        self::assertNotNull($process);
        self::assertSame('mm_omnibus_compliance', $process->getIndexerCode());
    }


    public function testTheConfigurationDefaultsAreTheOnesShippedWithTheModule(): void
    {
        self::assertSame('1', Mage::getStoreConfig('mm_omnibus/general/enabled'));
        self::assertSame('30', Mage::getStoreConfig('mm_omnibus/general/reference_period'));
        self::assertSame('90', Mage::getStoreConfig('mm_omnibus/general/cleanup_days'));
        self::assertSame('.price-box', Mage::getStoreConfig('mm_omnibus/frontend/price_box_selector'));
        self::assertSame('after', Mage::getStoreConfig('mm_omnibus/frontend/notice_position'));
    }

    public function testTheModuleClassesAreAutoloadable(): void
    {
        $classes = [
            \MM_Omnibus_Helper_Data::class,
            \MM_Omnibus_Helper_Html::class,
            \MM_Omnibus_Model_Compliance_Calculator::class,
            \MM_Omnibus_Model_Indexer_Compliance::class,
            \MM_Omnibus_Model_Observer::class,
            \MM_Omnibus_Model_Observer_Frontend::class,
            \MM_Omnibus_Model_Price_History::class,
            \MM_Omnibus_Model_Resource_Price_History_Collection::class,
            \MM_Omnibus_Model_Service_PriceCalculator::class,
            \MM\Omnibus\Api\NonCompliantProduct::class,
            \MM\Omnibus\Api\NonCompliantProductProvider::class,
        ];

        foreach ($classes as $class) {
            self::assertTrue(class_exists($class), $class);
        }
    }
}
