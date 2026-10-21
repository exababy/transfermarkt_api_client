<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Allowlist HTML sanitizer (DOMDocument) and HTML => text conversion.
 */
final class Html
{
    /** Allowed elements (all attributes are stripped except a[href|title]). */
    private const ALLOWED = [
        'p' => true, 'br' => true, 'h2' => true, 'h3' => true, 'h4' => true, 'ul' => true, 'ol' => true, 'li' => true,
        'strong' => true, 'em' => true, 'b' => true, 'i' => true, 'blockquote' => true, 'a' => true,
        'table' => true, 'thead' => true, 'tbody' => true, 'tr' => true, 'th' => true, 'td' => true,
        'figure' => true, 'figcaption' => true,
    ];

    /** Elements removed together with their content. */
    private const DROP = [
        'script' => true, 'style' => true, 'iframe' => true, 'object' => true, 'embed' => true, 'noscript' => true,
        'template' => true, 'svg' => true, 'math' => true, 'form' => true, 'input' => true, 'button' => true,
        'select' => true, 'textarea' => true, 'head' => true, 'title' => true, 'meta' => true, 'link' => true,
        'frame' => true, 'frameset' => true, 'applet' => true, 'base' => true, 'video' => true, 'audio' => true, 'canvas' => true,
    ];

    /** Headings outside the allowed range are mapped instead of unwrapped. */
    private const RENAME = ['h1' => 'h2', 'h5' => 'h4', 'h6' => 'h4'];

    /** Block elements that are unwrapped get whitespace around their content. */
    private const BLOCKS = ['div' => true, 'section' => true, 'article' => true, 'header' => true, 'footer' => true, 'main' => true, 'aside' => true, 'nav' => true, 'dl' => true, 'dt' => true, 'dd' => true];

    public static function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }
        self::clean($body);

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    /** Plain text with paragraph breaks preserved. */
    public static function toText(string $html): string
    {
        $html = (string) preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html);
        $html = (string) preg_replace('~<br\s*/?>|</(p|div|li|h[1-6]|tr|blockquote|figcaption|table|ul|ol)>~i', "$0\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_map(static fn (string $l): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $l)), explode("\n", $text));
        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    private static function clean(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMCdataSection || (!$child instanceof \DOMText && !$child instanceof \DOMElement)) {
                $node->removeChild($child); // comments, CDATA, processing instructions
                continue;
            }
            if ($child instanceof \DOMText) {
                continue;
            }
            $tag = strtolower($child->nodeName);
            if (isset(self::DROP[$tag])) {
                $node->removeChild($child);
                continue;
            }
            if (isset(self::RENAME[$tag])) {
                $child = self::rename($child, self::RENAME[$tag]);
                $tag = self::RENAME[$tag];
            }
            self::clean($child);
            if (!isset(self::ALLOWED[$tag]) || ($tag === 'a' && !self::cleanLink($child))) {
                self::unwrap($child, isset(self::BLOCKS[$tag]));
                continue;
            }
            if ($tag !== 'a') {
                while ($child->attributes->length > 0) {
                    $child->removeAttribute($child->attributes->item(0)->nodeName);
                }
            }
        }
    }

    /** Keep a safe href (+title), mark external links; false => link must be unwrapped. */
    private static function cleanLink(\DOMElement $a): bool
    {
        $href = trim((string) preg_replace('/[\x00-\x20\x7F]+/', '', $a->getAttribute('href')));
        $title = $a->getAttribute('title');
        while ($a->attributes->length > 0) {
            $a->removeAttribute($a->attributes->item(0)->nodeName);
        }
        if ($href === '') {
            return false;
        }
        $scheme = preg_match('/^([a-z][a-z0-9+.-]*):/i', $href, $m) === 1 ? strtolower($m[1]) : null;
        if ($scheme !== null && $scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        $a->setAttribute('href', $href);
        if ($title !== '') {
            $a->setAttribute('title', $title);
        }
        $host = $scheme !== null || str_starts_with($href, '//') ? parse_url(str_starts_with($href, '//') ? 'https:' . $href : $href, PHP_URL_HOST) : null;
        $siteHost = parse_url((string) \App\Core\Config::get('app.url', ''), PHP_URL_HOST);
        if (is_string($host) && strcasecmp($host, (string) $siteHost) !== 0) {
            $a->setAttribute('rel', 'nofollow noopener');
            $a->setAttribute('target', '_blank');
        }
        return true;
    }

    private static function unwrap(\DOMElement $element, bool $spaced): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }
        $doc = $element->ownerDocument;
        if ($spaced && $doc !== null) {
            $parent->insertBefore($doc->createTextNode(' '), $element);
        }
        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }
        if ($spaced && $doc !== null) {
            $parent->insertBefore($doc->createTextNode(' '), $element);
        }
        $parent->removeChild($element);
    }

    private static function rename(\DOMElement $element, string $tag): \DOMElement
    {
        $replacement = $element->ownerDocument->createElement($tag);
        while ($element->firstChild !== null) {
            $replacement->appendChild($element->firstChild);
        }
        $element->parentNode->replaceChild($replacement, $element);
        return $replacement;
    }
}
