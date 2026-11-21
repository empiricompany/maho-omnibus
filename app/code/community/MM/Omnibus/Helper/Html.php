<?php

/**
 * Inserts an HTML fragment relative to the first element matching a CSS selector.
 *
 * The fragment is parsed as HTML in a service document and then imported into the
 * target document: DOMDocument::createDocumentFragment()->appendXML() is an XML
 * parser, so `<`, `&&` and `&nbsp;` in the markup would make it fail silently.
 *
 * Every method returns the input unchanged when the selector matches nothing or
 * when the markup cannot be parsed, so a block that the module does not understand
 * is rendered exactly as the core rendered it.
 *
 * SPDX-FileCopyrightText: 2026 Tony MageMEGA <https://magemega.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

final class MM_Omnibus_Helper_Html
{
    private const WRAPPER_ID = 'omnibus-fragment';

    /**
     * Appends the fragment as the last child of the matched element.
     */
    public function appendTo(string $html, string $cssSelector, string $content): string
    {
        return $this->insertAt($html, $cssSelector, $content, 'inside');
    }

    /**
     * Inserts the fragment immediately before the matched element.
     */
    public function insertBefore(string $html, string $cssSelector, string $content): string
    {
        return $this->insertAt($html, $cssSelector, $content, 'before');
    }

    /**
     * Inserts the fragment immediately after the matched element.
     */
    public function insertAfter(string $html, string $cssSelector, string $content): string
    {
        return $this->insertAt($html, $cssSelector, $content, 'after');
    }

    private function insertAt(string $html, string $cssSelector, string $content, string $position): string
    {
        $xpath = self::cssToXpath($cssSelector);
        if ($xpath === null) {
            return $html;
        }

        $document = self::loadHtml($html);
        if (!$document instanceof DOMDocument) {
            return $html;
        }

        $nodes = new DOMXPath($document)->query($xpath);
        if ($nodes === false || $nodes->length === 0) {
            return $html;
        }

        $target = $nodes->item(0);
        if (!$target instanceof DOMNode) {
            return $html;
        }

        $fragment = self::fragment($document, $content);
        if (!$fragment instanceof DOMDocumentFragment) {
            return $html;
        }

        $parent = $target->parentNode;
        if (!$parent instanceof DOMNode) {
            return $html;
        }

        if ($position === 'before') {
            $parent->insertBefore($fragment, $target);
        } elseif ($position === 'after') {
            if ($target->nextSibling instanceof DOMNode) {
                $parent->insertBefore($fragment, $target->nextSibling);
            } else {
                $parent->appendChild($fragment);
            }
        } else {
            $target->appendChild($fragment);
        }

        $result = self::saveHtml($document);

        return $result ?? $html;
    }

    private static function loadHtml(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }

        $document = new DOMDocument();
        $document->preserveWhiteSpace = true;
        $document->formatOutput = false;

        $previousErrors = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        return $loaded ? $document : null;
    }

    private static function saveHtml(DOMDocument $document): ?string
    {
        $result = $document->saveHTML();
        if ($result === false) {
            return null;
        }

        return (string) preg_replace('/^<\?xml encoding="UTF-8">/', '', $result);
    }

    /**
     * Parses the content as HTML and imports its nodes into the target document.
     */
    private static function fragment(DOMDocument $document, string $content): ?DOMDocumentFragment
    {
        if (trim($content) === '') {
            return null;
        }

        $wrapper = new DOMDocument();
        $previousErrors = libxml_use_internal_errors(true);
        $loaded = $wrapper->loadHTML(
            '<?xml encoding="UTF-8"><div id="' . self::WRAPPER_ID . '">' . $content . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
        if (!$loaded) {
            return null;
        }

        $nodes = new DOMXPath($wrapper)->query('//*[@id="' . self::WRAPPER_ID . '"]');
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $container = $nodes->item(0);
        if (!$container instanceof DOMNode) {
            return null;
        }

        $fragment = $document->createDocumentFragment();
        foreach ($container->childNodes as $child) {
            $imported = $document->importNode($child, true);
            if ($imported !== false) {
                $fragment->appendChild($imported);
            }
        }

        return $fragment->hasChildNodes() ? $fragment : null;
    }

    /**
     * Converts the supported subset of CSS to XPath: tag, .class, tag.class, #id,
     * tag#id.
     */
    private static function cssToXpath(string $cssSelector): ?string
    {
        $selector = trim($cssSelector);

        if (preg_match('/^\.([\w-]+)$/', $selector, $matches) === 1) {
            return "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$matches[1]} ')]";
        }

        if (preg_match('/^([\w-]+)\.([\w-]+)$/', $selector, $matches) === 1) {
            return "//{$matches[1]}[contains(concat(' ', normalize-space(@class), ' '), ' {$matches[2]} ')]";
        }

        if (preg_match('/^#([\w-]+)$/', $selector, $matches) === 1) {
            return "//*[@id='{$matches[1]}']";
        }

        if (preg_match('/^([\w-]+)#([\w-]+)$/', $selector, $matches) === 1) {
            return "//{$matches[1]}[@id='{$matches[2]}']";
        }

        if (preg_match('/^([\w-]+)$/', $selector, $matches) === 1) {
            return "//{$matches[1]}";
        }

        return null;
    }
}
