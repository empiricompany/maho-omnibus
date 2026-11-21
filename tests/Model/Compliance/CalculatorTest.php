<?php

/**
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

namespace MM\Omnibus\Tests\Model\Compliance;

use MM_Omnibus_Model_Compliance_Calculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The calculator is pure arithmetic: no framework, no database, no clock.
 */
final class CalculatorTest extends TestCase
{
    /**
     * @return array<string, array{float, float, bool}>
     */
    public static function complianceProvider(): array
    {
        return [
            'reference above the lowest price' => [100.0, 89.9, false],
            'reference equal to the lowest price' => [89.9, 89.9, true],
            'reference below the lowest price' => [80.0, 89.9, true],
            'no history at all' => [100.0, 0.0, true],
            'free product with a history' => [0.0, 0.0, true],
        ];
    }

    #[DataProvider('complianceProvider')]
    public function testIsCompliant(float $referencePrice, float $lowestPrice, bool $expected): void
    {
        self::assertSame(
            $expected,
            MM_Omnibus_Model_Compliance_Calculator::isCompliant($referencePrice, $lowestPrice),
        );
    }

    /**
     * @return array<string, array{float, float, float}>
     */
    public static function differenceProvider(): array
    {
        return [
            'reference above the lowest price' => [100.0, 89.9, 10.1],
            'equal prices' => [89.9, 89.9, 0.0],
            'reference below the lowest price' => [80.0, 89.9, -9.9],
            'four decimals are kept' => [10.0001, 9.9999, 0.0002],
        ];
    }

    #[DataProvider('differenceProvider')]
    public function testDifference(float $referencePrice, float $lowestPrice, float $expected): void
    {
        self::assertSame(
            $expected,
            MM_Omnibus_Model_Compliance_Calculator::difference($referencePrice, $lowestPrice),
        );
    }

    /**
     * @return array<string, array{?string, int, ?int}>
     */
    public static function expiryProvider(): array
    {
        // The reference point is 2026-01-31 12:00:00 UTC.
        return [
            'no record' => [null, 30, null],
            'empty date' => ['', 30, null],
            'unparsable date' => ['not a date', 30, null],
            'last day of the window' => ['2026-01-01 12:00:00', 30, 0],
            'one day left' => ['2026-01-01 12:00:00', 31, 1],
            'window already closed' => ['2025-12-01 12:00:00', 30, -31],
            'zero period is treated as one day' => ['2026-01-31 12:00:00', 0, 1],
        ];
    }

    #[DataProvider('expiryProvider')]
    public function testDaysUntilExpiry(?string $lowestPriceDate, int $period, ?int $expected): void
    {
        $now = strtotime('2026-01-31 12:00:00');
        self::assertIsInt($now);

        self::assertSame(
            $expected,
            MM_Omnibus_Model_Compliance_Calculator::daysUntilExpiry($lowestPriceDate, $period, $now),
        );
    }

    public function testDaysUntilExpiryUsesTheCurrentTimeByDefault(): void
    {
        $days = MM_Omnibus_Model_Compliance_Calculator::daysUntilExpiry(
            gmdate('Y-m-d H:i:s'),
            30,
        );

        self::assertSame(30, $days);
    }
}
