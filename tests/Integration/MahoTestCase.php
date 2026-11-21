<?php

/**
 * Base class for tests that need a running Maho installation.
 *
 * The store is installed by tests/pest-with-test-db.php inside the package and
 * skipped when app/etc/local.xml is missing, so a plain "composer test" stays
 * green on a checkout without a test database.
 *
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

namespace MM\Omnibus\Tests\Integration;

use Mage;
use Maho\Db\Adapter\AdapterInterface;
use Maho\Db\Expr;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class MahoTestCase extends BaseTestCase
{
    private bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/app/etc/local.xml')) {
            self::markTestSkipped('No test store installed: run "composer test:db".');
        }

        Mage::setRoot($root);
        Mage::register('isSecureArea', true);
        Mage::app('admin');
        $this->bootstrapped = true;
    }

    protected function tearDown(): void
    {
        if ($this->bootstrapped) {
            Mage::reset();
            restore_error_handler();
            restore_exception_handler();
        }

        parent::tearDown();
    }

    protected function adapter(): AdapterInterface
    {
        return Mage::helper('mm_omnibus')->writeAdapter();
    }

    protected function table(string $alias): string
    {
        return Mage::getSingleton('core/resource')->getTableName($alias);
    }

    protected function countRows(string $table): int
    {
        $adapter = $this->adapter();

        return (int) $adapter->fetchOne(
            $adapter->select()->from($table, ['row_count' => new Expr('COUNT(*)')]),
        );
    }
}
