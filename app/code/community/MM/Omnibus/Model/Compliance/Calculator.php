<?php

/**
 * Pure compliance arithmetic, so it can be unit-tested without the framework.
 *
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

final class MM_Omnibus_Model_Compliance_Calculator
{
    /**
     * A product complies when the crossed-out reference price is not higher than
     * the lowest price recorded in the reference window, or when it has no
     * history at all.
     */
    public static function isCompliant(float $referencePrice, float $lowestPrice): bool
    {
        if ($lowestPrice <= 0.0) {
            return true;
        }

        return $referencePrice <= $lowestPrice;
    }

    /**
     * How much the reference price exceeds the lowest price. Zero or negative
     * when the product complies.
     */
    public static function difference(float $referencePrice, float $lowestPrice): float
    {
        return round($referencePrice - $lowestPrice, 4);
    }

    /**
     * Days before the lowest price record exits the reference window.
     *
     * Dates are the GMT values stored in the history table: 'Y-m-d H:i:s'.
     */
    public static function daysUntilExpiry(?string $lowestPriceDate, int $period, ?int $now = null): ?int
    {
        if ($lowestPriceDate === null || $lowestPriceDate === '') {
            return null;
        }

        $lowestTimestamp = strtotime($lowestPriceDate);
        if ($lowestTimestamp === false) {
            return null;
        }

        $now ??= time();
        $expiresAt = $lowestTimestamp + (max(1, $period) * 86400);

        return (int) floor(($expiresAt - $now) / 86400);
    }
}
