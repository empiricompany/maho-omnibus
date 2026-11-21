#!/usr/bin/env php
<?php

/**
 * Minimal Maho CLI shim for this package's test sandbox.
 *
 * The maho binary shipped with a project hardcodes MAHO_ROOT_DIR to the project
 * root; the integration tests install a throwaway store with the package itself
 * as the root, so app/etc/local.xml points at the SQLite test database. Only the
 * commands used by tests/pest-with-test-db.php are registered.
 *
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

define('MAHO_ROOT_DIR', dirname(__DIR__));
define('MAHO_PUBLIC_DIR', dirname(__DIR__) . '/public');

require dirname(__DIR__) . '/vendor/autoload.php';

use MahoCLI\Commands\CacheFlush;
use MahoCLI\Commands\Install;
use MahoCLI\Commands\Migrate;
use Symfony\Component\Console\Application;

$application = new Application('Maho', Mage::getVersion());
$application->addCommand(new Install());
$application->addCommand(new Migrate());
$application->addCommand(new CacheFlush());
$application->run();
