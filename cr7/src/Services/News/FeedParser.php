<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Support\Date;

/**
 * Tolerant RSS 2.0 / Atom parser.
 *
 * - converts windows-1254 / ISO-8859-9 (and other declared charsets) to UTF-8
 * - repairs common breakage before parsing: BOM / leading junk, control characters,
 *   HTML named entities (&nbsp; &ccedil; …) and bare ampersands
 * - parses with libxml in recover mode (malformed XML still yields the readable items),
 *   falling back to a regex scan of <item>/<entry> blocks
 * - handles CDATA, entity-encoded HTML in descriptions, media:content / enclosure /
 *   media:thumbnail / <image> images, content:encoded, Bing's News:Source and Google's <source>
 *
 * Item shape: ['title','link','guid','description' (plain text),'description_html' (raw),'content' (paragraphs),
 *              'published_at'(UTC|null),'image','source_name','categories'].
 */
final class FeedParser
{
    /** Common trailing "read more" boilerplate in feed descriptions. */
    private const TRAILING_JUNK = '/\s*(?:devamı için tıklayınız|devamını okumak için tıklayın(?:ız)?|haberin devamı(?: için tıklayın(?:ız)?)?|devamı|tıklayın|read more|\[…\]|\[\.\.\.\])\s*\.*\s*$/iu';

    /**
     * @return list<array{title: string, link: string, guid: string, description: string, description_html: string, content: string, published_at: ?string, image: ?string, source_name: ?string, categories: list<string>}>
     */
    public static function parse(string $body, ?string $contentType = null): array
    {
        $xml = self::prepare($body, $contentType);
        if ($xml === '') {
            return [];
        }
        $items = self::parseDom($xml);
        if ($items === null) {
            $items = self::parseRegex($xml);
        }
        $out = [];
        foreach ($items as $item) {
            if ($item['title'] !== '' && $item['link'] !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }

    /** Convert to UTF-8 and repair the document so libxml can read it. */
    public static function prepare(string $body, ?string $contentType = null): string
    {
        $body = self::toUtf8($body, $contentType);
        // BOM and anything before the first tag (some feeds start with whitespace/notices).
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
        $start = strpos($body, '<');
        if ($start === false) {
            return '';
        }
        $body = substr($body, $start);
        // Characters that are illegal in XML 1.0.
        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $body) ?? $body;
        $body = self::fixEntities($body);
        return $body;
    }

    /** Decode $body from its declared charset (HTTP header or XML declaration) to UTF-8. */
    public static function toUtf8(string $body, ?string $contentType = null): string
    {
        $declared = null;
        if (preg_match('/<\?xml[^>]*encoding\s*=\s*["\']([A-Za-z0-9._-]+)["\']/i', substr($body, 0, 300), $m) === 1) {
            $declared = $m[1];
        }
        if ($declared === null && $contentType !== null && preg_match('/charset\s*[=:]\s*"?([A-Za-z0-9._-]+)/i', $contentType, $m) === 1) {
            $declared = $m[1];
        }
        $charset = self::normalizeCharset($declared ?? 'utf-8');
        $isUtf8 = mb_check_encoding($body, 'UTF-8');
        if ($charset !== 'UTF-8') {
            // A feed mislabelled as latin-5 but actually valid multi-byte UTF-8 is kept as is.
            if (!($isUtf8 && preg_match('/[\xC2-\xF4][\x80-\xBF]/', $body) === 1)) {
                $converted = self::convert($body, $charset);
                if ($converted !== null) {
                    $body = $converted;
                    $isUtf8 = true;
                }
            }
        }
        if (!$isUtf8) {
            // Declared UTF-8 but broken bytes: Turkish feeds that lie are nearly always windows-1254.
            $body = self::convert($body, 'Windows-1254') ?? mb_scrub($body, 'UTF-8');
        }
        // The document is UTF-8 now: make the XML declaration say so.
        return preg_replace('/(<\?xml[^>]*encoding\s*=\s*["\'])[A-Za-z0-9._-]+(["\'])/i', '${1}UTF-8${2}', $body, 1) ?? $body;
    }

    /** Plain text from an RSS text node that may contain (entity-encoded) HTML. */
    public static function cleanText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        for ($i = 0; $i < 2 && preg_match('/&(?:[a-z][a-z0-9]*|#\d+|#x[0-9a-f]+);/i', $value) === 1; $i++) {
            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $value = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $value) ?? $value;
        $value = preg_replace('~<br\s*/?>|</(p|div|li|h[1-6]|td|tr|table)>~i', ' ', $value) ?? $value;
        $value = strip_tags($value);
        $value = str_replace("\u{00A0}", ' ', $value);
        $value = preg_replace('/[\x{200B}-\x{200F}\x{2060}\x{FEFF}\x{00AD}]/u', '', $value) ?? $value; // zero-width, BOM, soft hyphen
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        return trim(preg_replace(self::TRAILING_JUNK, '', $value) ?? $value);
    }

    /** UTC 'Y-m-d H:i:s' from an RSS/Atom date, or null. */
    public static function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $value = str_replace(['&#x2B;', '&#43;'], '+', $value);
        try {
            $utc = Date::toUtc($value);
        } catch (\InvalidArgumentException) {
            $ts = strtotime($value);
            if ($ts === false) {
                return null;
            }
            $utc = gmdate('Y-m-d H:i:s', $ts);
        }
        // Reject obviously broken dates (far future / before 2000).
        $ts = strtotime($utc . ' UTC');
        if ($ts === false || $ts > time() + 86400 || $ts < 946684800) {
            return null;
        }
        return $utc;
    }

    private static function normalizeCharset(string $charset): string
    {
        $c = strtolower(trim($charset));
        return match (true) {
            in_array($c, ['utf-8', 'utf8'], true) => 'UTF-8',
            in_array($c, ['windows-1254', 'cp1254', 'win-1254', 'iso-8859-9', 'iso8859-9', 'latin5', 'iso-8859-9:1989'], true) => 'Windows-1254',
            in_array($c, ['iso-8859-1', 'latin1', 'windows-1252', 'cp1252'], true) => 'Windows-1252',
            default => strtoupper($charset),
        };
    }

    private static function convert(string $body, string $charset): ?string
    {
        // windows-1254 is a superset of ISO-8859-9 (adds “ ” ‘ ’ … – in 0x80–0x9F), so it is used for both.
        try {
            if (in_array($charset, mb_list_encodings(), true)) {
                $out = mb_convert_encoding($body, 'UTF-8', $charset);
                if (is_string($out)) {
                    return $out;
                }
            }
        } catch (\ValueError) {
            // unknown to mbstring: try iconv
        }
        $out = @iconv($charset, 'UTF-8//IGNORE', $body);
        return is_string($out) ? $out : null;
    }

    /** HTML named entities => numeric references; bare '&' => '&amp;'. */
    private static function fixEntities(string $xml): string
    {
        $xml = preg_replace_callback('/&([A-Za-z][A-Za-z0-9]{1,31});/', static function (array $m): string {
            if (in_array($m[1], ['amp', 'lt', 'gt', 'quot', 'apos'], true)) {
                return $m[0];
            }
            $decoded = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $m[0]) {
                return '&amp;' . $m[1] . ';';
            }
            $out = '';
            foreach (mb_str_split($decoded) as $char) {
                $out .= '&#' . mb_ord($char) . ';';
            }
            return $out;
        }, $xml) ?? $xml;
        return preg_replace('/&(?!(?:[A-Za-z][A-Za-z0-9]{1,31}|#\d{1,7}|#x[0-9A-Fa-f]{1,6});)/', '&amp;', $xml) ?? $xml;
    }

    /** @return list<array<string, mixed>>|null null when libxml could not build a document */
    private static function parseDom(string $xml): ?array
    {
        $doc = new \DOMDocument();
        $doc->recover = true;
        $doc->strictErrorChecking = false;
        $previous = libxml_use_internal_errors(true);
        try {
            $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_BIGLINES);
        } catch (\Throwable) {
            $ok = false;
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok || $doc->documentElement === null) {
            return null;
        }
        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query('//*[local-name()="item" or local-name()="entry"]');
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        $items = [];
        foreach ($nodes as $node) {
            if ($node instanceof \DOMElement) {
                $items[] = self::itemFromElement($node);
            }
        }
        return $items;
    }

    private static function itemFromElement(\DOMElement $el): array
    {
        $fields = [];
        $images = [];
        $categories = [];
        $link = '';
        $source = null;
        foreach ($el->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $name = strtolower($child->localName ?? $child->nodeName);
            $qname = strtolower($child->nodeName);
            if (str_contains($name, ':')) { // namespace errors (Bing's invalid xmlns:News URI) keep the prefix
                $name = substr($name, (int) strrpos($name, ':') + 1);
            }
            $text = trim($child->textContent);
            switch ($name) {
                case 'link':
                    $href = $child->getAttribute('href');
                    $rel = strtolower($child->getAttribute('rel'));
                    if ($href !== '' && ($rel === '' || $rel === 'alternate')) {
                        if ($link === '' || $rel === 'alternate') {
                            $link = $href;
                        }
                    } elseif ($href === '' && $text !== '' && !str_contains($qname, 'atom')) {
                        $link = $text;
                    } elseif ($link === '' && $href !== '' && $rel !== 'enclosure' && $rel !== 'self') {
                        $link = $href;
                    }
                    if ($rel === 'enclosure' && str_starts_with(strtolower($child->getAttribute('type')), 'image')) {
                        $images[] = $href;
                    }
                    break;
                case 'encoded':
                    $fields['content'] = $child->textContent;
                    break;
                case 'content':
                    if ($child->hasAttribute('url')) { // media:content
                        $medium = strtolower($child->getAttribute('medium') . ' ' . $child->getAttribute('type'));
                        if ($medium === ' ' || str_contains($medium, 'image')) {
                            $images[] = $child->getAttribute('url');
                        }
                    } else { // Atom content
                        $fields['content'] = $child->textContent;
                    }
                    break;
                case 'thumbnail':
                case 'enclosure':
                    $url = $child->getAttribute('url');
                    $type = strtolower($child->getAttribute('type'));
                    if ($url !== '' && ($type === '' || str_starts_with($type, 'image'))) {
                        $images[] = $url;
                    }
                    break;
                case 'image':
                    if ($text !== '' && !str_contains($text, '<')) {
                        $images[] = $child->getElementsByTagName('url')->item(0)?->textContent ?? $text;
                    }
                    break;
                case 'source':
                    $source = $text !== '' ? $text : $source;
                    break;
                case 'category':
                    $term = $child->getAttribute('term') ?: $text;
                    if ($term !== '') {
                        $categories[] = self::cleanText($term);
                    }
                    break;
                case 'creator':
                case 'author':
                    $fields['author'] ??= trim($child->getElementsByTagName('name')->item(0)?->textContent ?? $text);
                    break;
                default:
                    if (!isset($fields[$name])) {
                        $fields[$name] = $child->textContent;
                    }
            }
        }
        return self::finish($fields, $link, $images, $source, $categories);
    }

    /** Last-resort scan for feeds libxml cannot read at all. */
    private static function parseRegex(string $xml): array
    {
        if (preg_match_all('~<(item|entry)\b[^>]*>(.*?)</\1>~is', $xml, $blocks, PREG_SET_ORDER) === 0) {
            return [];
        }
        $items = [];
        foreach ($blocks as $block) {
            $inner = $block[2];
            $get = static function (string $tag) use ($inner): string {
                if (preg_match('~<' . $tag . '\b[^>]*>(.*?)</' . $tag . '>~is', $inner, $m) !== 1) {
                    return '';
                }
                $value = $m[1];
                if (preg_match('~^\s*<!\[CDATA\[(.*)\]\]>\s*$~s', $value, $c) === 1) {
                    return $c[1];
                }
                return html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
            };
            $link = $get('link');
            if ($link === '' && preg_match('~<link\b[^>]*href=["\']([^"\']+)~i', $inner, $m) === 1) {
                $link = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            $images = [];
            if (preg_match_all('~<(?:media:content|media:thumbnail|enclosure)\b[^>]*url=["\']([^"\']+)~i', $inner, $m) > 0) {
                $images = array_map(static fn (string $u): string => html_entity_decode($u, ENT_QUOTES | ENT_XML1, 'UTF-8'), $m[1]);
            }
            $fields = [
                'title' => $get('title'),
                'guid' => $get('guid') ?: $get('id'),
                'description' => $get('description') ?: $get('summary'),
                'content' => $get('content:encoded') ?: $get('content'),
                'pubdate' => $get('pubDate') ?: ($get('published') ?: $get('updated')),
            ];
            $source = $get('News:Source') ?: ($get('source') ?: null);
            $items[] = self::finish($fields, trim($link), $images, $source, []);
        }
        return $items;
    }

    private static function finish(array $fields, string $link, array $images, ?string $source, array $categories): array
    {
        $description = (string) ($fields['description'] ?? $fields['summary'] ?? '');
        $content = (string) ($fields['content'] ?? '');
        // First <img> of the description/content HTML as a last image fallback.
        foreach ([$description, $content] as $html) {
            $decoded = str_contains($html, '&lt;') ? html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $html;
            if (preg_match('~<img\b[^>]*\bsrc=["\']([^"\']+)~i', $decoded, $m) === 1) {
                $images[] = $m[1];
            }
        }
        $image = null;
        foreach ($images as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && !str_starts_with($candidate, 'data:')) {
                $image = $candidate;
                break;
            }
        }
        $guid = trim((string) ($fields['guid'] ?? $fields['id'] ?? ''));
        $link = trim($link);
        if ($link === '' && preg_match('#^https?://#i', $guid) === 1) {
            $link = $guid;
        }
        $date = '';
        foreach (['pubdate', 'published', 'date', 'updated', 'issued', 'modified'] as $key) {
            if (!empty($fields[$key])) {
                $date = (string) $fields[$key];
                break;
            }
        }
        $contentText = $content !== '' ? self::htmlToParagraphs($content) : '';
        return [
            'title' => self::cleanText((string) ($fields['title'] ?? '')),
            'link' => html_entity_decode($link, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'guid' => $guid,
            'description' => self::cleanText($description),
            'description_html' => $description,
            'content' => $contentText,
            'published_at' => self::parseDate($date),
            'image' => $image !== null ? html_entity_decode($image, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null,
            'source_name' => $source !== null && trim($source) !== '' ? self::cleanText($source) : (isset($fields['author']) && $fields['author'] !== '' ? self::cleanText((string) $fields['author']) : null),
            'categories' => array_values(array_unique(array_filter($categories))),
        ];
    }

    /** Feed content HTML (content:encoded / Atom content) => paragraphs separated by blank lines. */
    public static function htmlToParagraphs(string $html): string
    {
        if (str_contains($html, '&lt;p') || str_contains($html, '&lt;br')) {
            $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $html = preg_replace('~<(script|style|figure|figcaption|table|header|footer|aside)\b[^>]*>.*?</\1>~is', ' ', $html) ?? $html;
        $html = preg_replace('~<br\s*/?>\s*<br\s*/?>|</(p|div|h[1-6]|li|blockquote)>~i', "\n\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $paragraphs = [];
        foreach (preg_split('/\n\s*\n/', $text) ?: [] as $p) {
            $p = trim(preg_replace('/\s+/u', ' ', $p) ?? $p);
            if ($p !== '') {
                $paragraphs[] = $p;
            }
        }
        return implode("\n\n", $paragraphs);
    }
}
