#!/usr/bin/env php
<?php

/**
 * Pest runner with a throwaway SQLite store, modeled on maho's own
 * tests/pest-with-test-db.php (SQLite only, no sample data, no web server).
 *
 * 1. Backs up app/etc/local.xml.
 * 2. Installs a fresh Maho store into var/db/maho_test.sqlite with this package
 *    as the root, so the module's declarative schema runs and the two tables
 *    exist.
 * 3. Runs vendor/bin/pest, then restores the original app/etc/local.xml.
 *
 * Usage (from the package root, or via composer):
 *   php tests/pest-with-test-db.php
 *   composer test:db
 *
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

final class MahoTestDbRunner
{
    private const LOCAL_XML = 'app/etc/local.xml';

    private const LOCAL_XML_BACKUP = 'app/etc/local.xml.backup';

    private const BASE_URL = 'http://localhost:8901/';

    private readonly string $root;

    private readonly string $dbPath;

    public function __construct()
    {
        $this->root = dirname(__DIR__);
        $this->dbPath = $this->root . '/var/db/maho_test.sqlite';
    }

    /**
     * @param list<string> $pestArgs
     */
    public function run(array $pestArgs): int
    {
        $localXml = $this->root . '/' . self::LOCAL_XML;
        $backup = $this->root . '/' . self::LOCAL_XML_BACKUP;
        $hadLocalXml = is_file($localXml);

        if ($hadLocalXml && !copy($localXml, $backup)) {
            fwrite(STDERR, "Failed to back up app/etc/local.xml\n");

            return 1;
        }

        try {
            $this->prepareDirectories();
            $this->install();
            $this->migrate();

            return $this->runPest($pestArgs);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Error: ' . $exception->getMessage() . "\n");

            return 1;
        } finally {
            if ($hadLocalXml) {
                copy($backup, $localXml);
                unlink($backup);
                echo "Restored the original app/etc/local.xml\n";
            } elseif (is_file($localXml)) {
                unlink($localXml);
                echo "Removed the sandbox app/etc/local.xml\n";
            }
        }
    }

    private function prepareDirectories(): void
    {
        foreach ([dirname($this->dbPath), $this->root . '/public'] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
                throw new RuntimeException("Cannot create {$directory}");
            }
        }

        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }

        // The installer refuses to run while local.xml exists: every run is a
        // fresh store, and the original file has already been backed up.
        $localXml = $this->root . '/' . self::LOCAL_XML;
        if (is_file($localXml)) {
            unlink($localXml);
        }

        echo "Prepared the SQLite test database: {$this->dbPath}\n";
    }

    private function install(): void
    {
        echo "Installing the test store (sqlite)...\n";

        $this->execute([
            PHP_BINARY,
            $this->root . '/tests/maho-cli.php',
            'install',
            '--no-ansi',
            '--license_agreement_accepted', 'yes',
            '--locale', 'en_US',
            '--timezone', 'Europe/Rome',
            '--default_currency', 'EUR',
            '--db_engine', 'sqlite',
            '--db_name', $this->dbPath,
            '--url', self::BASE_URL,
            '--secure_base_url', self::BASE_URL,
            '--use_secure', '0',
            '--use_secure_admin', '0',
            '--admin_lastname', 'Admin',
            '--admin_firstname', 'Admin',
            '--admin_email', 'admin@example.com',
            '--admin_username', 'admin',
            '--admin_password', 'testpassword123',
        ]);
    }

    private function migrate(): void
    {
        echo "Running the declarative schema migration...\n";

        $this->execute([
            PHP_BINARY,
            $this->root . '/tests/maho-cli.php',
            'migrate',
            '--no-ansi',
        ]);
    }

    /**
     * @param list<string> $pestArgs
     */
    private function runPest(array $pestArgs): int
    {
        echo "Running Pest...\n";

        return $this->passthruCommand(array_merge([PHP_BINARY, $this->root . '/vendor/bin/pest'], $pestArgs));
    }

    /**
     * @param list<string> $command
     */
    private function execute(array $command): void
    {
        $exitCode = $this->passthruCommand($command);

        if ($exitCode !== 0) {
            throw new RuntimeException("Command failed with exit code {$exitCode}: " . implode(' ', $command));
        }
    }

    /**
     * @param list<string> $command
     */
    private function passthruCommand(array $command): int
    {
        passthru(implode(' ', array_map(escapeshellarg(...), $command)), $exitCode);

        return $exitCode;
    }
}

exit((new MahoTestDbRunner())->run(array_slice($argv, 1)));
