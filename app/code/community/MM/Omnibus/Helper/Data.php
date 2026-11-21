<?php

/**
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

class MM_Omnibus_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const CONFIG_ENABLED = 'mm_omnibus/general/enabled';
    public const CONFIG_REFERENCE_PERIOD = 'mm_omnibus/general/reference_period';
    public const CONFIG_CLEANUP_DAYS = 'mm_omnibus/general/cleanup_days';
    public const CONFIG_DEBUG_ENABLED = 'mm_omnibus/debug/enabled';
    public const CONFIG_PRICE_BOX_SELECTOR = 'mm_omnibus/frontend/price_box_selector';
    public const CONFIG_NOTICE_POSITION = 'mm_omnibus/frontend/notice_position';
    public const LOG_FILE = 'mm_omnibus.log';

    public const POSITION_INSIDE = 'inside';
    public const POSITION_BEFORE = 'before';
    public const POSITION_AFTER = 'after';

    public function isEnabled(int|string|null $storeId = null): bool
    {
        return Mage::getStoreConfigFlag(self::CONFIG_ENABLED, $storeId);
    }

    public function getReferencePeriod(int|string|null $storeId = null): int
    {
        return (int) Mage::getStoreConfig(self::CONFIG_REFERENCE_PERIOD, $storeId);
    }

    /**
     * Reference period resolved for a website, since prices are website-scoped.
     * The website id is converted to its default store to read the store-scoped value.
     */
    public function getReferencePeriodByWebsite(?int $websiteId = null): int
    {
        if ($websiteId === null) {
            return $this->getReferencePeriod();
        }

        $defaultStore = Mage::app()->getWebsite($websiteId)->getDefaultStore();

        return $this->getReferencePeriod($defaultStore ? $defaultStore->getId() : null);
    }

    public function getCleanupDays(int|string|null $storeId = null): int
    {
        return (int) Mage::getStoreConfig(self::CONFIG_CLEANUP_DAYS, $storeId);
    }

    public function getActionLabel(string $action): string
    {
        $labels = [
            MM_Omnibus_Model_Price_History::ACTION_PRODUCT_SAVE => $this->__('Product Save'),
            MM_Omnibus_Model_Price_History::ACTION_CATALOGRULE_APPLY => $this->__('Catalog Rule Apply'),
        ];

        return $labels[$action] ?? $action;
    }

    /**
     * Default `.price-box` with `after`: the notice stays out of the price grid, so
     * the crossed-out price and the discount badge keep the position they have
     * without the module (see `skin/frontend/base/default/css/mm/omnibus.css`).
     */
    public function getPriceBoxSelector(int|string|null $storeId = null): string
    {
        $selector = (string) Mage::getStoreConfig(self::CONFIG_PRICE_BOX_SELECTOR, $storeId);

        return $selector !== '' ? $selector : '.price-box';
    }

    public function getNoticePosition(int|string|null $storeId = null): string
    {
        $position = (string) Mage::getStoreConfig(self::CONFIG_NOTICE_POSITION, $storeId);

        return $position !== '' ? $position : self::POSITION_AFTER;
    }

    public function isDebugEnabled(int|string|null $storeId = null): bool
    {
        return Mage::getStoreConfigFlag(self::CONFIG_DEBUG_ENABLED, $storeId);
    }

    /**
     * @param \Monolog\Level|int|null $level one of the `Mage::LOG_*` constants, which are Monolog levels
     */
    public function log(string $message, \Monolog\Level|int|null $level = null): void
    {
        if (!$this->isDebugEnabled()) {
            return;
        }

        Mage::log($message, $level, self::LOG_FILE, true);
    }

    /**
     * @throws \RuntimeException When the connection is not configured.
     */
    public function readAdapter(): Maho\Db\Adapter\AdapterInterface
    {
        return $this->adapter('core_read');
    }

    /**
     * @throws \RuntimeException When the connection is not configured.
     */
    public function writeAdapter(): Maho\Db\Adapter\AdapterInterface
    {
        return $this->adapter('core_write');
    }

    /**
     * Whether catalog prices are global, which makes the website filter and the
     * websites column of the dashboard irrelevant.
     */
    public function isPriceGlobal(): bool
    {
        return Mage::helper('catalog')->isPriceGlobal();
    }

    public function getPriceScopeLabel(): string
    {
        if ($this->isPriceGlobal()) {
            return $this->__('Global: prices are the same across all websites');
        }

        return $this->__('Website: prices can vary per website');
    }

    /**
     * `Mage_Core_Model_Resource::getConnection()` returns false for an unknown
     * connection name; a missing `core_read` or `core_write` is a broken
     * installation, not a case to handle at the call site.
     */
    private function adapter(string $connectionName): Maho\Db\Adapter\AdapterInterface
    {
        $adapter = Mage::getSingleton('core/resource')->getConnection($connectionName);

        if (!$adapter instanceof Maho\Db\Adapter\AdapterInterface) {
            throw new \RuntimeException("The {$connectionName} connection is not available.");
        }

        return $adapter;
    }
}
