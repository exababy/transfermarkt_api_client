<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Support\Cache;
use App\Support\Http;

/**
 * Extracts the readable article text (paragraphs) from a news page.
 *
 * Strategy, best first:
 *   1. schema.org JSON-LD (NewsArticle / Article / ReportageNews …) `articleBody`
 *   2. [itemprop=articleBody] or <article> paragraphs
 *   3. paragraph density: the element whose direct <p> children carry the most text
 * Boilerplate lines (related links, "Abone ol", photo credits, all-caps sub-headlines,
 * share prompts …) are dropped. Images are never used (no hotlinking).
 *
 * Result: ['paragraphs' => list<string>, 'words' => int, 'title' => ?string, 'method' => string] or null.
 */
final class ArticleExtractor
{
    public const MIN_WORDS = 60;

    private const ARTICLE_TYPES = ['newsarticle', 'article', 'reportagenews', 'analysisnewsarticle', 'blogposting', 'liveblogposting', 'backgroundnewsarticle', 'opinionnewsarticle'];

    /** Elements never containing article text. */
    private const DROP_TAGS = ['script', 'style', 'noscript', 'nav', 'header', 'footer', 'aside', 'form', 'iframe', 'figure', 'figcaption', 'button', 'select', 'svg', 'template', 'video', 'audio'];

    /** class/id fragments of boilerplate containers. */
    private const DROP_ATTR = '/(?:^|[\s_-])(?:comment|comments|yorum|yorumlar|related|ilgili|benzer|share|social|sosyal|paylas|banner|advert|ads|reklam|newsletter|subscribe|abone|sidebar|breadcrumb|tags|etiket|author-box|popup|cookie|outbrain|taboola|recommend|oneri|most-read|cok-okunan|gallery-list|video-list|widget|promo)(?:$|[\s_-])/i';

    /** Paragraph-level boilerplate. */
    private const JUNK = '/^(?:ilgili haber|ilgili haberler|haberin devamı|devamı için|devamını oku|abone ol|bizi takip edin|takip edin|google news|tıklayın|tıklayınız|reklam|fotoğraf:|foto:|kaynak:|editör:|muhabir:|güncelleme:|yayınlanma:|©|copyright|tüm hakları|whatsapp|telegram|son dakika haberleri için|okumaya devam|bu haber|haberi paylaş|paylaş|yorum yap|yorumlar|instagram\'da|twitter\'da|x\'te|e-posta|e-bülten|bültenimize|uygulamamızı indir)/iu';

    /** Fetch (cached 6 h per URL) and extract. */
    public function extract(string $url): ?array
    {
        $key = 'news:article:' . sha1($url);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached['paragraphs'] === [] ? null : $cached;
        }
        $response = Http::get($url, [
            'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5',
            'Accept-Language' => 'tr-TR,tr;q=0.9',
        ], 20);
        $result = null;
        if ($response['error'] === null && $response['status'] < 400 && $response['body'] !== '') {
            $result = self::fromHtml($response['body'], $response['headers']['content-type'] ?? null);
        }
        Cache::set($key, $result ?? ['paragraphs' => [], 'words' => 0, 'title' => null, 'method' => 'none'], $result === null ? 1800 : 21600);
        return $result;
    }

    /** Extract from an HTML string (no network). */
    public static function fromHtml(string $html, ?string $contentType = null): ?array
    {
        $html = self::toUtf8($html, $contentType);
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($doc);

        $title = self::metaTitle($xpath);
        $candidates = [];

        $ld = self::fromJsonLd($xpath);
        if ($ld !== null) {
            $title = $ld['title'] ?? $title;
            if ($ld['paragraphs'] !== []) {
                $candidates[] = ['paragraphs' => $ld['paragraphs'], 'method' => 'jsonld'];
            }
        }

        self::dropBoilerplate($xpath);

        foreach (['//*[@itemprop="articleBody"]' => 'itemprop', '//article' => 'article'] as $query => $method) {
            $nodes = $xpath->query($query);
            if ($nodes === false) {
                continue;
            }
            $bestNode = [];
            foreach ($nodes as $node) {
                $paragraphs = self::paragraphsOf($node);
                if (self::wordCount($paragraphs) > self::wordCount($bestNode)) {
                    $bestNode = $paragraphs;
                }
            }
            if ($bestNode !== []) {
                $candidates[] = ['paragraphs' => $bestNode, 'method' => $method];
            }
        }

        $density = self::densest($xpath);
        if ($density !== []) {
            $candidates[] = ['paragraphs' => $density, 'method' => 'density'];
        }

        // First candidate (in priority order) that is long enough; otherwise the longest one.
        $chosen = null;
        foreach ($candidates as $candidate) {
            if (self::wordCount($candidate['paragraphs']) >= self::MIN_WORDS) {
                $chosen = $candidate;
                break;
            }
        }
        if ($chosen === null) {
            usort($candidates, static fn (array $a, array $b): int => self::wordCount($b['paragraphs']) <=> self::wordCount($a['paragraphs']));
            $chosen = $candidates[0] ?? null;
        }
        if ($chosen === null || $chosen['paragraphs'] === []) {
            return null;
        }
        $paragraphs = array_slice(self::dedupe($chosen['paragraphs']), 0, 30);
        return [
            'paragraphs' => $paragraphs,
            'words' => self::wordCount($paragraphs),
            'title' => $title,
            'method' => $chosen['method'],
        ];
    }

    /** Paragraphs from plain text or HTML (JSON-LD articleBody, feed content). */
    public static function paragraphsFromText(string $text): array
    {
        if (preg_match('~<(p|br|div)\b~i', $text) === 1) {
            $text = FeedParser::htmlToParagraphs($text);
        } else {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $parts = preg_split('/\n+/', $text) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = self::cleanParagraph($part);
            if ($part !== null) {
                $out[] = $part;
            }
        }
        // One huge block (articleBody without line breaks): split into ~3-sentence paragraphs.
        if (count($out) === 1 && self::wordCount($out) > 90) {
            $sentences = Spinner::sentences($out[0]);
            $out = array_map(static fn (array $chunk): string => implode(' ', $chunk), array_chunk($sentences, 3));
        }
        return $out;
    }

    /** @param list<string> $paragraphs */
    public static function wordCount(array $paragraphs): int
    {
        $count = 0;
        foreach ($paragraphs as $p) {
            $count += count(preg_split('/\s+/u', trim($p), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        }
        return $count;
    }

    /** Trimmed paragraph text, or null when it is boilerplate / too short. */
    public static function cleanParagraph(string $text): ?string
    {
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/[\x{200B}-\x{200F}\x{2060}\x{FEFF}\x{00AD}]/u', '', $text) ?? $text;
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $text = (string) preg_replace('/(\p{Ll}{2})([.!?])(\p{Lu}\p{Ll})/u', '$1$2 $3', $text); // "görülüyor.İlk" => "görülüyor. İlk"
        // Inline ALL-CAPS sub-headlines glued to the text ("NE OLMUŞTU? Uluslar Ligi'nde …").
        $text = trim((string) preg_replace_callback(
            '/(?:^|(?<=[.!?…"”]\s))((?:[\p{Lu}\d\'’]+[?!:,.]?\s+){2,})(?=\p{Lu}\p{Ll})/u',
            static fn (array $m): string => mb_strlen((string) preg_replace('/[^\p{Lu}]/u', '', $m[1])) >= 8 ? '' : $m[0],
            $text
        ));
        if (mb_strlen($text) < 25 || preg_match(self::JUNK, $text) === 1) {
            return null;
        }
        // SEO keyword lists ("Ronaldo ayrıldı mı, Ronaldo bıraktı mı, Portekiz son durum, …").
        if (substr_count($text, ',') >= 4) {
            $kept = array_filter(Spinner::sentences($text), static fn (string $s): bool => !self::isKeywordList($s));
            $text = trim(implode(' ', $kept));
            if (mb_strlen($text) < 25) {
                return null;
            }
        }
        $letters = preg_replace('/[^\p{L}]/u', '', $text) ?? '';
        if ($letters === '' || mb_strlen($letters) < 15) {
            return null;
        }
        // ALL-CAPS sub-headlines ("YENİ TARİH 30 KASIM OLARAK BELİRLENDİ").
        if (mb_strtoupper($letters, 'UTF-8') === $letters && mb_strlen($text) < 160) {
            return null;
        }
        // Lines that are just a link list / hashtags / credits.
        if (preg_match('/^(?:#\S+\s*)+$|^\(?(?:aa|dha|iha|ap|afp|reuters)\)?$/iu', $text) === 1 || preg_match('#https?://#', $text) === 1 && mb_strlen($text) < 120) {
            return null;
        }
        // A real paragraph ends like a sentence (allows quotes/brackets after the stop).
        if (preg_match('/[.!?…:"”’»)\]]$/u', $text) !== 1 && mb_strlen($text) < 80) {
            return null;
        }
        return $text;
    }

    /** A comma-separated keyword list rather than prose (5+ comma segments, mostly of at most 6 words). */
    public static function isKeywordList(string $text): bool
    {
        $segments = preg_split('/\s*,\s*/u', $text) ?: [];
        if (count($segments) < 5) {
            return false;
        }
        $short = count(array_filter($segments, static fn (string $s): bool => count(preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: []) <= 6));
        return $short / count($segments) >= 0.6;
    }

    private static function toUtf8(string $html, ?string $contentType): string
    {
        $charset = null;
        if (preg_match('/<meta[^>]+charset\s*=\s*["\']?([A-Za-z0-9._-]+)/i', substr($html, 0, 4096), $m) === 1) {
            $charset = strtolower($m[1]);
        } elseif ($contentType !== null && preg_match('/charset\s*=\s*"?([A-Za-z0-9._-]+)/i', $contentType, $m) === 1) {
            $charset = strtolower($m[1]);
        }
        if ($charset !== null && !in_array($charset, ['utf-8', 'utf8'], true) && !mb_check_encoding($html, 'UTF-8')) {
            $from = in_array($charset, ['iso-8859-9', 'windows-1254', 'cp1254', 'latin5'], true) ? 'Windows-1254' : $charset;
            try {
                $converted = mb_convert_encoding($html, 'UTF-8', $from);
                if (is_string($converted)) {
                    return $converted;
                }
            } catch (\ValueError) {
                // fall through
            }
        }
        return mb_check_encoding($html, 'UTF-8') ? $html : mb_convert_encoding($html, 'UTF-8', 'Windows-1254');
    }

    private static function metaTitle(\DOMXPath $xpath): ?string
    {
        foreach (['//meta[@property="og:title"]/@content', '//title'] as $query) {
            $node = $xpath->query($query)?->item(0);
            if ($node !== null && trim($node->textContent) !== '') {
                return trim(html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }
        return null;
    }

    /** @return array{paragraphs: list<string>, title: ?string}|null */
    private static function fromJsonLd(\DOMXPath $xpath): ?array
    {
        $scripts = $xpath->query('//script[@type="application/ld+json"]');
        if ($scripts === false) {
            return null;
        }
        $best = null;
        foreach ($scripts as $script) {
            $json = trim($script->textContent);
            $data = json_decode($json, true);
            if (!is_array($data)) {
                $data = json_decode(self::repairJson($json), true); // raw newlines/tabs inside strings
            }
            if (!is_array($data)) {
                continue;
            }
            foreach (self::walkLd($data) as $node) {
                $types = array_map('strtolower', (array) ($node['@type'] ?? []));
                if (array_intersect($types, self::ARTICLE_TYPES) === []) {
                    continue;
                }
                $body = is_string($node['articleBody'] ?? null) ? $node['articleBody'] : '';
                $paragraphs = $body !== '' ? self::paragraphsFromText($body) : [];
                $title = is_string($node['headline'] ?? null) ? trim(html_entity_decode($node['headline'], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : null;
                if ($best === null || self::wordCount($paragraphs) > self::wordCount($best['paragraphs'])) {
                    $best = ['paragraphs' => $paragraphs, 'title' => $title];
                }
            }
        }
        return $best;
    }

    /** Escape raw control characters inside JSON string literals (common in hand-made JSON-LD). */
    public static function repairJson(string $json): string
    {
        $out = '';
        $inString = false;
        $escaped = false;
        $length = strlen($json);
        for ($i = 0; $i < $length; $i++) {
            $c = $json[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($c === '\\') {
                    $escaped = true;
                } elseif ($c === '"') {
                    $inString = false;
                } elseif ($c === "\n") {
                    $out .= '\\n';
                    continue;
                } elseif (ord($c) < 0x20) {
                    $out .= ' ';
                    continue;
                }
            } elseif ($c === '"') {
                $inString = true;
            }
            $out .= $c;
        }
        return $out;
    }

    /** Depth-first list of JSON-LD objects (handles @graph and nested arrays). */
    private static function walkLd(array $data, int $depth = 0): array
    {
        if ($depth > 6) {
            return [];
        }
        $out = [];
        if (!array_is_list($data)) {
            $out[] = $data;
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                array_push($out, ...self::walkLd($value, $depth + 1));
            }
        }
        return $out;
    }

    private static function dropBoilerplate(\DOMXPath $xpath): void
    {
        $remove = [];
        foreach (self::DROP_TAGS as $tag) {
            foreach ($xpath->query('//' . $tag) ?: [] as $node) {
                $remove[] = $node;
            }
        }
        foreach ($xpath->query('//*[@class or @id]') ?: [] as $node) {
            if (!$node instanceof \DOMElement || in_array(strtolower($node->nodeName), ['html', 'body', 'article', 'main'], true)) {
                continue;
            }
            $attr = $node->getAttribute('class') . ' ' . $node->getAttribute('id');
            if (preg_match(self::DROP_ATTR, $attr) === 1 && $node->getAttribute('itemprop') !== 'articleBody') {
                $remove[] = $node;
            }
        }
        foreach ($remove as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /** Paragraph texts (<p> and <li>-free) under $node. */
    private static function paragraphsOf(\DOMNode $node): array
    {
        $out = [];
        $xpath = new \DOMXPath($node->ownerDocument ?? $node);
        $nodes = $xpath->query('.//p', $node);
        foreach ($nodes ?: [] as $p) {
            $text = self::cleanParagraph($p->textContent);
            if ($text !== null) {
                $out[] = $text;
            }
        }
        // Some sites put the text in <div>/<br> without <p>.
        if ($out === [] && $node instanceof \DOMElement) {
            $html = $node->ownerDocument?->saveHTML($node) ?? '';
            foreach (self::paragraphsFromText($html) as $text) {
                $out[] = $text;
            }
        }
        return $out;
    }

    /** Readability-like: parent with the most paragraph text. */
    private static function densest(\DOMXPath $xpath): array
    {
        $scores = [];
        $nodes = [];
        foreach ($xpath->query('//p') ?: [] as $p) {
            $text = self::cleanParagraph($p->textContent);
            if ($text === null || $p->parentNode === null) {
                continue;
            }
            $len = mb_strlen($text);
            $parent = $p->parentNode;
            $id = spl_object_id($parent);
            $scores[$id] = ($scores[$id] ?? 0) + $len;
            $nodes[$id] = $parent;
            $grand = $parent->parentNode;
            if ($grand !== null) {
                $gid = spl_object_id($grand);
                $scores[$gid] = ($scores[$gid] ?? 0) + (int) ($len / 2);
                $nodes[$gid] = $grand;
            }
        }
        if ($scores === []) {
            return [];
        }
        arsort($scores);
        return self::paragraphsOf($nodes[array_key_first($scores)]);
    }

    /** @param list<string> $paragraphs */
    private static function dedupe(array $paragraphs): array
    {
        $seen = [];
        $out = [];
        foreach ($paragraphs as $p) {
            $key = mb_strtolower(mb_substr($p, 0, 80));
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $p;
            }
        }
        return $out;
    }
}
