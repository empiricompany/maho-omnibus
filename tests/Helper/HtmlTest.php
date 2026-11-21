<?php

/**
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

namespace MM\Omnibus\Tests\Helper;

use MM_Omnibus_Helper_Html;
use PHPUnit\Framework\TestCase;

/**
 * The helper only needs ext-dom, so these tests run without the framework.
 */
final class HtmlTest extends TestCase
{
    private const PRICE_BOX = '<div class="price-box">'
        . '<p class="old-price"><span class="price">100,00</span></p>'
        . '<p class="special-price"><span class="price">80,00</span></p>'
        . '</div>';

    private MM_Omnibus_Helper_Html $helper;

    protected function setUp(): void
    {
        $this->helper = new MM_Omnibus_Helper_Html();
    }

    public function testAppendToPutsTheNoticeInsideTheMatchedElement(): void
    {
        $result = $this->helper->appendTo(self::PRICE_BOX, '.old-price', '<span class="omnibus">lowest</span>');

        self::assertStringContainsString('<span class="omnibus">lowest</span>', $result);
        self::assertLessThan(
            strpos($result, '</p><p class="special-price">'),
            strpos($result, 'lowest'),
        );
    }

    public function testInsertBeforePutsTheNoticeBeforeTheSpecialPrice(): void
    {
        $result = $this->helper->insertBefore(self::PRICE_BOX, '.special-price', '<span class="omnibus">before</span>');

        self::assertLessThan(
            $this->positionOf($result, 'special-price'),
            $this->positionOf($result, 'omnibus'),
        );
    }

    public function testInsertAfterPutsTheNoticeAfterTheOldPrice(): void
    {
        $result = $this->helper->insertAfter(self::PRICE_BOX, '.old-price', '<span class="omnibus">after</span>');

        self::assertLessThan(
            $this->positionOf($result, 'special-price'),
            $this->positionOf($result, 'omnibus'),
        );
    }

    public function testAnUnknownSelectorReturnsTheInputUnchanged(): void
    {
        self::assertSame(
            self::PRICE_BOX,
            $this->helper->appendTo(self::PRICE_BOX, '.not-here', '<span>x</span>'),
        );
    }

    public function testAnUnsupportedSelectorReturnsTheInputUnchanged(): void
    {
        self::assertSame(
            self::PRICE_BOX,
            $this->helper->appendTo(self::PRICE_BOX, 'ul > li:first-child', '<span>x</span>'),
        );
    }

    /**
     * The content is parsed as HTML, not XML: `&&` and `<` must survive.
     */
    public function testScriptContentIsNotTreatedAsXml(): void
    {
        $content = '<span class="notice"><script>if (a && b < c) { d("&nbsp;"); }</script></span>';

        $result = $this->helper->appendTo(self::PRICE_BOX, '.old-price', $content);

        self::assertStringContainsString('a && b < c', $result);
    }

    public function testAnEmptyContentLeavesTheInputUnchanged(): void
    {
        self::assertSame(self::PRICE_BOX, $this->helper->appendTo(self::PRICE_BOX, '.old-price', ''));
    }

    private function positionOf(string $haystack, string $needle): int
    {
        $position = strpos($haystack, $needle);
        self::assertIsInt($position);

        return $position;
    }
}
