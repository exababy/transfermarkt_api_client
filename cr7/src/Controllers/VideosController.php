<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Core\View;
use App\Repositories\VideoRepository;
use App\Services\Video\VideoText;
use App\Support\Format;
use App\Support\OgImage;
use App\Support\Str;

/**
 * Video gallery (/videolar?kategori=&sayfa=) and video detail pages (/video/{slug}).
 */
final class VideosController
{
    public const PER_PAGE = 24;
    /** Featured row: 5 => mosaic (1 large + 4 small on desktop). */
    public const FEATURED = 5;
    public const RELATED = 6;

    /** Per-category SEO copy: [title (≤ 60), h1, description (120–160), lead]. */
    private const COPY = [
        '' => [
            'Cristiano Ronaldo Videoları: Goller, Özetler, Röportajlar',
            'Cristiano Ronaldo Videoları',
            'Cristiano Ronaldo videoları: CR7’nin en güzel golleri, maç özetleri, röportajları, antrenman görüntüleri ve belgeselleri resmî kanallardan seçildi.',
            'CR7’nin unutulmaz golleri, maç özetleri, röportajları ve kamera arkası görüntüleri. Videolar UEFA, FIFA, kulüplerin ve Ronaldo’nun kendi resmî YouTube kanallarından derleniyor ve düzenli olarak güncelleniyor.',
        ],
        'goller' => [
            'Cristiano Ronaldo Gol Videoları – En Güzel CR7 Golleri',
            'Cristiano Ronaldo Gol Videoları',
            'Cristiano Ronaldo’nun en güzel golleri: rövaşatalar, frikikler, hat-trick’ler ve rekor goller. Real Madrid, Manchester United, Juventus ve Portekiz golleri.',
            'Rövaşatalar, frikikler, kafa golleri ve hat-trick’ler: Cristiano Ronaldo’nun kariyeri boyunca attığı en özel goller tek sayfada.',
        ],
        'mac-ozetleri' => [
            'Cristiano Ronaldo Maç Özetleri – CR7’nin Unutulmaz Maçları',
            'Cristiano Ronaldo Maç Özetleri',
            'Cristiano Ronaldo’nun oynadığı unutulmaz maçların özetleri: Şampiyonlar Ligi gecelerinden Dünya Kupası maçlarına CR7’nin sahneye çıktığı karşılaşmalar.',
            'Şampiyonlar Ligi gecelerinden Dünya Kupası maçlarına, Cristiano Ronaldo’nun damgasını vurduğu karşılaşmaların özetleri.',
        ],
        'roportajlar' => [
            'Cristiano Ronaldo Röportajları ve Basın Toplantıları',
            'Cristiano Ronaldo Röportajları',
            'Cristiano Ronaldo röportajları, basın toplantıları ve ödül konuşmaları: CR7’nin kariyeri, rekorları ve geleceği hakkındaki açıklamaları video olarak burada.',
            'Basın toplantıları, ödül konuşmaları ve özel röportajlar: Cristiano Ronaldo’nun kariyeri ve geleceği hakkındaki açıklamaları.',
        ],
        'antrenman' => [
            'Cristiano Ronaldo Antrenman Videoları – CR7 İdmanları',
            'Cristiano Ronaldo Antrenman Videoları',
            'Cristiano Ronaldo’nun antrenman ve idman videoları: Real Madrid, Juventus ve Al Nassr tesislerinden CR7’nin çalışma disiplinini gösteren görüntüler.',
            'Cristiano Ronaldo’yu zirvede tutan çalışma disiplini: antrenman sahasından ve idman salonundan görüntüler.',
        ],
        'belgesel' => [
            'Cristiano Ronaldo Belgeselleri ve Özel Filmler',
            'Cristiano Ronaldo Belgeselleri',
            'Cristiano Ronaldo belgeselleri ve özel filmler: kulüplerinin hazırladığı veda filmlerinden kariyer hikâyelerine CR7’nin hayatını anlatan videolar burada.',
            'Kulüplerin hazırladığı veda filmleri ve kariyer hikâyeleri: Cristiano Ronaldo’nun yolculuğunu anlatan özel yapımlar.',
        ],
        'ur-cristiano' => [
            'UR · Cristiano Videoları – Ronaldo’nun YouTube Kanalı',
            'UR · Cristiano Videoları',
            'Cristiano Ronaldo’nun kendi YouTube kanalı UR · Cristiano’dan videolar: röportajlar, aile anları, kamera arkası görüntüler ve CR7’nin özel paylaşımları.',
            'Cristiano Ronaldo’nun kendi YouTube kanalı UR · Cristiano’dan seçilen videolar: sohbetler, aile anları ve kamera arkası.',
        ],
        'genel' => [
            'Cristiano Ronaldo’nun Özel Anları – Genel Videolar',
            'Cristiano Ronaldo’nun Özel Anları',
            'Cristiano Ronaldo’nun kariyerinden özel anlar: tanıtım törenleri, ödül geceleri, kutlamalar ve taraftarlarla buluşmalar resmî kanalların videolarıyla burada.',
            'Tanıtım törenleri, ödül geceleri ve kutlamalar: Cristiano Ronaldo’nun kariyerinden unutulmaz anlar.',
        ],
    ];

    private VideoRepository $videos;

    public function __construct(?VideoRepository $videos = null)
    {
        $this->videos = $videos ?? new VideoRepository();
    }

    public function index(Request $request): Response
    {
        $categories = VideoRepository::categories();
        $category = $request->query('kategori');
        if ($category !== null && !is_string($category)) {
            return Response::notFound();
        }
        $category = $category !== null && trim($category) !== '' ? trim($category) : null;
        if ($category !== null && !isset($categories[$category])) {
            return Response::notFound();
        }
        if ($request->query('sayfa') === '1') { // one URL for page 1
            return Response::redirect(url($category === null ? '/videolar' : '/videolar?kategori=' . rawurlencode($category)), 301);
        }
        $page = self::page($request);
        if ($page === null) {
            return Response::notFound();
        }
        $total = $this->videos->count($category);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($page > $pages) {
            return Response::notFound();
        }
        $videos = $this->videos->latest(self::PER_PAGE, $category, ($page - 1) * self::PER_PAGE);
        $featured = ($page === 1 && $category === null) ? $this->videos->featured(self::FEATURED) : [];
        $counts = $this->videos->categoryCounts();
        $base = $category === null ? '/videolar' : '/videolar?kategori=' . rawurlencode($category);
        $canonical = url(page_url($base, $page));

        [$title, $h1, $description, $lead] = self::COPY[$category ?? ''];
        if ($page > 1) {
            $title = ($category === null ? 'Cristiano Ronaldo Videoları' : $h1) . " – Sayfa {$page}";
            $description = Str::limit("Sayfa {$page}: " . $description, 160);
        }
        $breadcrumbs = [['name' => 'Videolar', 'url' => '/videolar']];
        if ($category !== null) {
            $breadcrumbs[] = ['name' => $categories[$category], 'url' => $base];
        }
        if ($page > 1) {
            $breadcrumbs[] = ['name' => "Sayfa {$page}"];
        }

        // Gallery share card (1200x630) instead of a 480x360 thumbnail of whichever video is newest.
        $card = $videos !== [] ? OgImage::page('videolar' . ($category !== null ? '-' . $category : ''), $h1, 'Goller, özetler, röportajlar', 'VİDEOLAR') : null;
        $seo = new Seo(
            title: $title,
            description: $description,
            canonical: $canonical,
            image: $card ?? ($videos !== [] ? VideoText::thumbnail($videos[0]['youtube_id']) : null),
            // The gallery hub is indexable once it has a video; a category page needs Seo::LISTING_INDEX_MIN.
            robots: $total >= ($category === null ? 1 : Seo::LISTING_INDEX_MIN) ? 'index,follow,max-image-preview:large' : 'noindex,follow',
            jsonLd: $videos !== [] ? [self::itemList($videos, $canonical, $h1, ($page - 1) * self::PER_PAGE)] : [],
            breadcrumbs: $breadcrumbs,
            extraMeta: $card !== null ? OgImage::meta($card) : ($videos !== [] ? [['property' => 'og:image:width', 'content' => '480'], ['property' => 'og:image:height', 'content' => '360']] : []),
            prev: $page > 1 ? page_url($base, $page - 1) : null,
            next: $page < $pages ? page_url($base, $page + 1) : null,
        );

        return Response::html(View::render('pages/videos', [
            'seo' => $seo,
            'videos' => $videos,
            'featured' => $featured,
            'category' => $category,
            'categories' => $categories,
            'counts' => $counts,
            'allCount' => array_sum($counts),
            'h1' => $h1,
            'lead' => $lead,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'baseUrl' => $base,
            'activeNav' => 'videolar',
            'extraHead' => self::styles(),
        ]));
    }

    public function show(Request $request): Response
    {
        $slug = (string) $request->param('slug', '');
        $video = $slug !== '' ? $this->videos->findBySlug($slug) : null;
        if ($video === null) {
            $moved = $slug !== '' ? $this->videos->findMovedSlug($slug) : null;
            return $moved !== null && $moved['slug'] !== $slug
                ? Response::redirect(url($moved['url']), 301)
                : Response::notFound();
        }

        $canonical = url($video['url']);
        $id = $video['youtube_id'];
        $categoryUrl = '/videolar?kategori=' . rawurlencode($video['category']);
        $channel = trim((string) ($video['channel_title'] ?? ''));
        $related = $this->videos->related($video, self::RELATED);
        $thumbs = array_values(array_unique([
            (string) $video['thumbnail_url'],
            VideoText::thumbnail($id, 'hqdefault'),
            VideoText::thumbnail($id, 'mqdefault'),
        ]));
        $summary = self::summaryText($video);
        $metaDescription = self::metaDescription($video);

        $schema = [
            '@type' => 'VideoObject',
            'name' => $video['title'],
            'description' => $video['description'] !== '' ? Str::limit(str_replace("\n", ' ', $video['description']), 2000) : $summary,
            'thumbnailUrl' => $thumbs,
            'uploadDate' => Format::iso($video['date']),
            'embedUrl' => VideoText::embedUrl($id),
            'contentUrl' => VideoText::watchUrl($id),
            'url' => $canonical,
            'isFamilyFriendly' => true,
            'genre' => $video['category_name'],
            'about' => LifeController::personRef(),
            'publisher' => $channel !== ''
                ? ['@type' => 'Organization', 'name' => $channel] + (!empty($video['channel_id']) ? ['url' => 'https://www.youtube.com/channel/' . $video['channel_id']] : [])
                : ['@type' => 'Organization', 'name' => Seo::siteName(), 'url' => url('/')],
        ];
        $duration = VideoText::isoDuration($video['duration_seconds']);
        if ($duration !== null) {
            $schema['duration'] = $duration;
        }
        if ($video['view_count'] !== null) {
            $schema['interactionStatistic'] = [
                '@type' => 'InteractionCounter',
                'interactionType' => ['@type' => 'WatchAction'],
                'userInteractionCount' => $video['view_count'],
            ];
        }

        [$thumbWidth, $thumbHeight] = VideoText::thumbnailSize((string) $video['thumbnail_url']);
        $extraMeta = [
            ['property' => 'og:image:width', 'content' => (string) $thumbWidth],
            ['property' => 'og:image:height', 'content' => (string) $thumbHeight],
            ['property' => 'og:video', 'content' => VideoText::embedUrl($id)],
            ['property' => 'og:video:secure_url', 'content' => VideoText::embedUrl($id)],
            ['property' => 'og:video:type', 'content' => 'text/html'],
            ['property' => 'og:video:width', 'content' => '1280'],
            ['property' => 'og:video:height', 'content' => '720'],
            ['property' => 'video:release_date', 'content' => Format::iso($video['date'])],
            ['property' => 'video:tag', 'content' => 'Cristiano Ronaldo'],
        ];
        if ($video['duration_seconds'] !== null) {
            $extraMeta[] = ['property' => 'video:duration', 'content' => (string) $video['duration_seconds']];
        }

        $seo = new Seo(
            title: Str::limit($video['title'], 60),
            description: $metaDescription,
            canonical: $canonical,
            robots: !empty($video['is_indexable']) ? 'index,follow,max-image-preview:large,max-video-preview:-1' : 'noindex,follow',
            image: (string) $video['thumbnail_url'],
            type: 'video.other',
            jsonLd: [$schema],
            breadcrumbs: [
                ['name' => 'Videolar', 'url' => '/videolar'],
                ['name' => $video['category_name'], 'url' => $categoryUrl],
                ['name' => Str::limit($video['title'], 60)],
            ],
            extraMeta: $extraMeta,
        );

        [$descriptionShort, $descriptionMore] = self::splitDescription($video['description']);

        return Response::html(View::render('pages/video-show', [
            'seo' => $seo,
            'video' => $video,
            'related' => $related,
            'summary' => $summary,
            'categoryUrl' => $categoryUrl,
            'counts' => $this->videos->categoryCounts(),
            'categories' => VideoRepository::categories(),
            'descriptionHtml' => VideoText::descriptionHtml($descriptionShort),
            'descriptionMoreHtml' => VideoText::descriptionHtml($descriptionMore),
            'activeNav' => 'videolar',
            // The template renders the youtube-nocookie iframe server-side ('facade' => false) so
            // crawlers find the player without a click (VideoObject + video sitemap point here).
            'extraHead' => self::styles(),
        ]));
    }

    // ------------------------------------------------------------------ helpers

    /** ?sayfa= => page number; null for invalid values (=> 404). */
    private static function page(Request $request): ?int
    {
        $raw = $request->query('sayfa');
        if ($raw === null || $raw === '') {
            return 1;
        }
        if (!is_string($raw) || preg_match('/^[1-9]\d{0,4}$/', $raw) !== 1) {
            return null;
        }
        return (int) $raw;
    }

    /** schema.org ItemList of a listing page (summary-page pattern: url + name per item). */
    private static function itemList(array $videos, string $url, string $name, int $offset): array
    {
        $items = [];
        foreach ($videos as $i => $video) {
            $items[] = ['@type' => 'ListItem', 'position' => $offset + $i + 1, 'url' => url($video['url']), 'name' => $video['title']];
        }
        return ['@type' => 'ItemList', 'name' => $name, 'url' => $url, 'numberOfItems' => count($items), 'itemListElement' => $items];
    }

    /** Turkish context paragraph shown under the player (also the JSON-LD fallback description). */
    public static function summaryText(array $video): string
    {
        $channel = trim((string) ($video['channel_title'] ?? ''));
        $parts = [];
        $published = !empty($video['published_at']) ? Format::date((string) $video['published_at']) : '';
        if ($channel !== '' && $published !== '') {
            $parts[] = "“{$video['title']}” videosu {$channel} tarafından {$published} tarihinde YouTube’da yayımlandı.";
        } elseif ($channel !== '') {
            $parts[] = "“{$video['title']}” videosu {$channel} kanalına ait.";
        } else {
            $parts[] = "“{$video['title']}” Cristiano Ronaldo’nun kariyerinden seçilmiş bir video.";
        }
        $human = VideoText::humanDuration($video['duration_seconds'] ?? null);
        if ($human !== '') {
            $parts[] = "Süresi {$human}.";
        }
        $parts[] = 'Video, ' . $video['category_name'] . ' kategorisinde yer alıyor; Cristiano Ronaldo’nun diğer videolarına “Benzer Videolar” listesinden ulaşabilirsiniz.';
        return implode(' ', $parts);
    }

    /** 120–160 character Turkish meta description. */
    public static function metaDescription(array $video): string
    {
        $description = trim((string) ($video['description'] ?? ''));
        $first = trim((string) (preg_split("/\n\s*\n/", $description, 2)[0] ?? ''));
        // Curated/Turkish descriptions are used as-is; channel texts (often English/Arabic boilerplate) are not.
        if (mb_strlen($first) >= 100 && preg_match('/[çğışöüÇĞİŞÖÜ]/u', $first) === 1 && preg_match('#https?://#', $first) !== 1) {
            return Str::limit(str_replace("\n", ' ', $first), 160);
        }
        $channel = trim((string) ($video['channel_title'] ?? ''));
        $text = Str::limit($video['title'], 80) . ' – ' . ($channel !== '' ? $channel . ' kanalından ' : '')
            . 'Cristiano Ronaldo videosu. ' . $video['category_name'] . ' kategorisindeki bu videoyu CR7.com.tr’de izleyin.';
        if (mb_strlen($text) < 120) {
            $text .= ' CR7’nin golleri, maç özetleri ve röportajları da burada.';
        }
        return Str::limit($text, 160);
    }

    /** Long descriptions: first ~500 characters (paragraph boundary) + the rest behind "Devamını göster". */
    private static function splitDescription(string $description): array
    {
        if (mb_strlen($description) <= 700) {
            return [$description, ''];
        }
        $cut = mb_strpos($description, "\n", 400);
        if ($cut === false || $cut > 900) {
            $cut = mb_strrpos(mb_substr($description, 0, 600), ' ') ?: 600;
        }
        return [rtrim(mb_substr($description, 0, $cut)), ltrim(mb_substr($description, $cut))];
    }

    /** Page-specific styles (kept out of app.css, which the foundation owns). */
    private static function styles(): string
    {
        return '<style>'
            . '.video-tabs{margin-bottom:26px}'
            . '.tab-count{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:999px;background:var(--bg-elev);color:var(--text-muted);font-size:.74rem;font-weight:750;vertical-align:1px}'
            . '.tab.active .tab-count,.tab[aria-current] .tab-count{background:rgba(212,175,55,.16);color:var(--gold-soft)}'
            . '.video-grid .card-title{font-size:1rem}'
            . '.video-mosaic>:first-child .card-title{font-size:clamp(1.15rem,1rem + .6vw,1.45rem)}'
            . '@media (max-width:639px){.video-mosaic{grid-template-columns:none;grid-auto-flow:column;grid-auto-columns:84%;overflow-x:auto;overscroll-behavior-x:contain;scroll-snap-type:x mandatory;padding-bottom:10px;scrollbar-width:thin}.video-mosaic>*{scroll-snap-align:start}.video-mosaic .card:hover{transform:none}}'
            . '@media (max-width:639px){.video-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.video-grid .card-body{padding:10px 10px 12px;gap:4px}.video-grid .card-title{font-size:.86rem;line-height:1.32}.video-grid .meta{font-size:.72rem;gap:2px 8px}.video-grid .meta{flex-direction:column;align-items:flex-start}.video-grid .meta>*+*::before{display:none}.video-grid .video-card-play{width:30px;height:30px;left:8px;bottom:8px;background-size:14px}.video-grid .duration{right:6px;bottom:6px;font-size:.68rem}}'
            . '@media (min-width:640px){.video-mosaic{grid-template-columns:repeat(2,minmax(0,1fr))}.video-mosaic>:first-child{grid-column:1/-1}}'
            . '@media (min-width:1200px){.video-mosaic{grid-template-columns:2fr 1fr 1fr}.video-mosaic>:first-child{grid-column:auto;grid-row:span 2}}'
            . '.video-lead .card-body{gap:10px}.video-lead-badge{align-self:flex-start;position:relative;z-index:2;text-decoration:none}.video-lead .card-text{display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}'
            . '@media (max-width:1199px){.video-lead .card-text{display:none}}'
            . '.list-head{display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:6px 16px;margin-bottom:18px}'
            . '.list-head .section-title{margin:0}'
            . '.video-intro{max-width:72ch;color:var(--text-muted)}.video-intro a{color:var(--gold-soft);font-weight:600}'
            . '.video-page{padding-top:12px}'
            . '.video-head{margin:22px 0 14px}'
            . '.video-head h1{font-size:clamp(1.5rem,1.1rem + 1.8vw,2.3rem);line-height:1.2;margin:10px 0 10px}'
            . '.video-head .badge{text-decoration:none}'
            . '.video-summary{color:var(--text-muted);margin:0 0 18px;max-width:72ch}'
            . '.video-actions{margin-bottom:28px}'
            . '.video-description{padding:20px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-elev)}'
            . '.video-description h2{font-size:1.1rem;margin-bottom:10px}'
            . '.video-description .prose{max-width:none;font-size:1rem;line-height:1.7;overflow-wrap:anywhere}'
            . '.video-description details{margin-top:10px}.video-description summary{cursor:pointer;color:var(--gold-soft);font-weight:700}'
            . '.video-description details[open] summary{margin-bottom:10px}'
            . '.related-title{font-size:1.15rem;margin-bottom:14px}'
            . '.related-list li+li{margin-top:14px}'
            . '.related-item{position:relative;display:grid;grid-template-columns:140px minmax(0,1fr);gap:12px;align-items:start}'
            . '@media (min-width:960px){.video-layout{grid-template-columns:minmax(0,1fr) 340px}.related-item{grid-template-columns:128px minmax(0,1fr)}}'
            . '.related-thumb{position:relative;display:block;overflow:hidden;aspect-ratio:16/9;border-radius:10px;background:#111115}'
            . '.related-thumb img{width:100%;height:100%;object-fit:cover}'
            . '.related-item a.related-link{color:var(--text);font-weight:650;line-height:1.35;text-decoration:none;font-size:.95rem;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}'
            . '.related-item a.related-link::after{content:"";position:absolute;inset:0}'
            . '.related-item:hover a.related-link{color:var(--gold-soft)}'
            . '.related-item .meta{margin-top:4px;font-size:.78rem}'
            . '.related-thumb .duration{right:6px;bottom:6px;font-size:.7rem}'
            . '@media (min-width:960px){.video-aside{position:sticky;top:calc(var(--header-h,72px) + 20px);align-self:start}}'
            . '.video-more{margin-top:24px}'
            . '.video-categories{margin:40px 0 56px;padding-top:24px;border-top:1px solid var(--border)}'
            . '</style>';
    }
}
