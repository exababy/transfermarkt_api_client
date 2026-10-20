<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Core\View;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Services\Payment\PaymentManager;
use App\Services\Shop\KitIllustrator;
use App\Services\Shop\OrderService;
use App\Services\Shop\Shop;
use App\Support\Format;
use App\Support\Str;

/**
 * Jersey shop ("Forma Mağazası"): listing (/forma-magazasi?takim=), product pages (/forma/{slug}),
 * order submission (POST /forma/{slug}/siparis), order status (/siparis/{key}) and the Turkish
 * e-commerce legal pages (Mesafeli Satış Sözleşmesi, Ön Bilgilendirme Formu, İptal ve İade).
 *
 * The shop is public and indexable; ordering is closed unless the admin enables `shop.orders_enabled`
 * AND at least one payment method is available. While closed, product pages show "Yakında satışta"
 * and POST returns 403.
 */
final class ShopController
{
    public function index(Request $request): Response
    {
        if (!Shop::enabled()) {
            return Response::notFound();
        }
        $repo = new ProductRepository();
        $clubs = $repo->clubs();
        $clubId = $request->query('takim');
        if ($clubId !== null && (!is_string($clubId) || !isset($clubs[$clubId]))) {
            return Response::notFound();
        }
        $products = $repo->all($clubId);
        $clubName = $clubId !== null ? $clubs[$clubId] : null;
        $canonical = url('/forma-magazasi') . ($clubId !== null ? '?takim=' . rawurlencode($clubId) : '');

        $items = [];
        foreach ($products as $i => $product) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => url($product['url']), 'name' => $product['title']];
        }
        $title = $clubName !== null ? $clubName . ' Ronaldo Formaları' : 'Cristiano Ronaldo Forma Mağazası';
        $description = $clubName !== null
            ? Str::limit('Cristiano Ronaldo’nun ' . $clubName . ' dönemine ait ' . count($products) . ' forma: fiyatlar, bedenler (S–XXL ve çocuk) ve RONALDO isim-numara baskı seçeneği CR7.com.tr Forma Mağazası’nda.', 160)
            : 'Sporting CP’den Al-Nassr’a, Ronaldo’nun oynadığı her kulüp ve Portekiz için retro ve yeni sezon formalar. S–XXL ve çocuk bedenleri, RONALDO 7 baskı seçeneği.';
        $seo = new Seo(
            title: $title,
            description: $description,
            canonical: $canonical,
            jsonLd: [[
                '@type' => 'ItemList',
                'name' => $title,
                'numberOfItems' => count($items),
                'itemListElement' => $items,
            ]],
            breadcrumbs: $clubName !== null
                ? [['name' => 'Forma Mağazası', 'url' => '/forma-magazasi'], ['name' => $clubName]]
                : [['name' => 'Forma Mağazası']],
        );
        $printPrices = array_filter(array_map(static fn (array $p): float => $p['allow_print'] ? $p['print_price'] : 0.0, $repo->all()));
        return Response::html(View::render('pages/shop', [
            'seo' => $seo,
            'activeNav' => 'magaza',
            'extraHead' => self::styles(),
            'products' => $products,
            'clubs' => $clubs,
            'clubId' => $clubId,
            'clubName' => $clubName,
            'ordersOpen' => Shop::ordersOpen(),
            'shippingKurus' => Shop::shippingFeeKurus(),
            'printPrice' => $printPrices !== [] ? min($printPrices) : null,
            'whatsappLink' => Shop::whatsappLink('Merhaba, CR7.com.tr Forma Mağazası siparişe açıldığında haber verir misiniz?'),
        ]));
    }

    public function show(Request $request): Response
    {
        if (!Shop::enabled()) {
            return Response::notFound();
        }
        $product = (new ProductRepository())->findBySlug((string) $request->param('slug'));
        if ($product === null) {
            return Response::notFound();
        }
        return $this->renderProduct($product);
    }

    public function order(Request $request): Response
    {
        if (!Shop::enabled()) {
            return Response::notFound();
        }
        $product = (new ProductRepository())->findBySlug((string) $request->param('slug'));
        if ($product === null) {
            return Response::notFound();
        }
        $payments = new PaymentManager();
        if (!Shop::ordersOpen($payments)) {
            abort(403, 'Siparişler şu anda kapalı. Formalarımız çok yakında satışta!');
        }
        Csrf::check($request);

        $result = (new OrderService(null, null, $payments))->place($product, $request->all(), $request->ip());
        if ($result['order'] === null) {
            return $this->renderProduct($product, $result['errors'], self::oldInput($request), $result['status'], $payments);
        }
        return Response::redirect(url('/odeme/' . $result['order']['access_key']), 303);
    }

    public function status(Request $request): Response
    {
        $order = (new OrderRepository())->findByKey((string) $request->param('key'));
        if ($order === null) {
            return Response::notFound();
        }
        $payments = new PaymentManager();
        $gateway = $payments->get((string) $order['payment_method']);
        $product = (new ProductRepository())->find((int) $order['product_id']);
        $seo = new Seo(
            title: 'Sipariş ' . $order['order_no'],
            description: 'CR7.com.tr Forma Mağazası sipariş durumu: ' . $order['order_no'] . '.',
            robots: 'noindex,nofollow',
            breadcrumbs: [['name' => 'Forma Mağazası', 'url' => '/forma-magazasi'], ['name' => 'Sipariş ' . $order['order_no']]],
        );
        return self::private(Response::html(View::render('pages/order-status', [
            'seo' => $seo,
            'activeNav' => 'magaza',
            'extraHead' => self::styles(),
            'order' => $order,
            'product' => $product,
            'methodLabel' => $gateway !== null ? $gateway->label() : $order['payment_method'],
            'canPay' => $order['payment_status'] !== 'odendi' && $order['status'] !== 'iptal' && $gateway !== null && $gateway->isConfigured(),
            'whatsappLink' => Shop::whatsappLink('Merhaba, ' . $order['order_no'] . ' numaralı siparişim hakkında bilgi almak istiyorum.'),
        ])));
    }

    public function distanceSales(Request $request): Response
    {
        return $this->legal('mesafeli', 'Mesafeli Satış Sözleşmesi',
            'CR7.com.tr Forma Mağazası mesafeli satış sözleşmesi: taraflar, ürün ve ödeme bilgileri, teslimat, cayma hakkı ve uyuşmazlık çözümü (6502 sayılı Kanun).');
    }

    public function preInfo(Request $request): Response
    {
        return $this->legal('on-bilgi', 'Ön Bilgilendirme Formu',
            'CR7.com.tr Forma Mağazası ön bilgilendirme formu: satıcı bilgileri, ürünlerin temel nitelikleri, toplam fiyat, ödeme, teslimat ve cayma hakkı koşulları.');
    }

    public function returns(Request $request): Response
    {
        return $this->legal('iade', 'İptal ve İade Koşulları',
            'CR7.com.tr Forma Mağazası iptal ve iade koşulları: 14 günlük cayma hakkı, kişiye özel baskılı formalarda istisna, iade süreci ve ücret iadesi.');
    }

    /** Product page (also re-rendered with errors after a failed order POST). */
    private function renderProduct(array $product, array $errors = [], array $old = [], int $status = 200, ?PaymentManager $payments = null): Response
    {
        $payments ??= new PaymentManager();
        $repo = new ProductRepository();
        $ordersOpen = Shop::ordersOpen($payments);
        $gateways = $ordersOpen ? $payments->available() : [];
        $service = new OrderService(null, $repo, $payments);
        $quote = $service->quote($product, 1, $product['allow_print']);
        $base = $service->quote($product, 1, false);

        $availability = match (true) {
            !$ordersOpen => 'https://schema.org/OutOfStock',
            $product['stock'] === null => 'https://schema.org/PreOrder',
            $product['stock'] > 0 => 'https://schema.org/InStock',
            default => 'https://schema.org/OutOfStock',
        };
        $pageUrl = url($product['url']);
        $share = KitIllustrator::pngPath((string) $product['slug']);
        $images = array_values(array_unique(array_filter([
            $share !== null ? url($share) : null,
            ...array_map(static fn (array $g): string => url($g['url']), $product['gallery']),
        ])));
        $plain = trim((string) preg_replace('/\s+/u', ' ', (string) $product['description']));
        $price = Shop::decimal($base['unit_kurus']);
        $productLd = array_filter([
            '@type' => 'Product',
            '@id' => $pageUrl . '#urun',
            'name' => $product['title'],
            'description' => Str::limit($plain, 500),
            'image' => $images,
            'sku' => 'CR7-F' . str_pad((string) $product['id'], 4, '0', STR_PAD_LEFT),
            'brand' => ['@type' => 'Brand', 'name' => $product['club_name']],
            'category' => 'Futbol Forması',
            'size' => $product['sizes'] !== [] ? implode(', ', $product['sizes']) : null,
            'offers' => [
                '@type' => 'Offer',
                'url' => $pageUrl,
                'price' => $price,
                'priceCurrency' => 'TRY',
                'availability' => $availability,
                'itemCondition' => 'https://schema.org/NewCondition',
                'seller' => ['@type' => 'Organization', 'name' => Seo::siteName(), 'url' => url('/')],
                'hasMerchantReturnPolicy' => [
                    '@type' => 'MerchantReturnPolicy',
                    'applicableCountry' => 'TR',
                    'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                    'merchantReturnDays' => 14,
                    'returnMethod' => 'https://schema.org/ReturnByMail',
                    'returnFees' => 'https://schema.org/ReturnFeesCustomerResponsibility',
                    'merchantReturnLink' => url('/iptal-ve-iade'),
                ],
            ],
        ], static fn ($v): bool => $v !== null && $v !== []);

        $number = Shop::defaultNumber((string) $product['slug']);
        $seo = new Seo(
            title: $product['title'],
            description: Str::limit($product['title'] . ': ' . Format::price($price) . '. ' . ($product['club_id'] === '3300' ? 'Kaptan Ronaldo’nun Portekiz milli takımı renklerinde forma; ' : 'Ronaldo’nun ' . $product['club_name'] . ' dönemini anımsatan forma; ')
                . ($product['kids_sizes'] !== [] ? 'S–XXL ve çocuk bedenleri' : 'S–XXL bedenler') . ($product['allow_print'] ? ', RONALDO ' . $number . ' baskı seçeneği.' : '.'), 160),
            canonical: $pageUrl,
            image: $share,
            type: 'product',
            jsonLd: [$productLd],
            breadcrumbs: array_values(array_filter([
                ['name' => 'Forma Mağazası', 'url' => '/forma-magazasi'],
                $product['club_id'] !== null ? ['name' => $product['club_name'], 'url' => '/forma-magazasi?takim=' . rawurlencode((string) $product['club_id'])] : null,
                ['name' => $product['title']],
            ])),
            extraMeta: [
                ['property' => 'product:price:amount', 'content' => $price],
                ['property' => 'product:price:currency', 'content' => 'TRY'],
                ['property' => 'product:availability', 'content' => $ordersOpen ? 'preorder' : 'out of stock'],
                ['property' => 'product:brand', 'content' => (string) $product['club_name']],
            ],
        );

        $response = Response::html(View::render('pages/product', [
            'seo' => $seo,
            'activeNav' => 'magaza',
            'extraHead' => self::styles(),
            'product' => $product,
            'ordersOpen' => $ordersOpen,
            'ordersSwitchedOn' => Shop::ordersEnabled(),
            'gateways' => $gateways,
            'quote' => $quote,
            'basePriceKurus' => $base['unit_kurus'],
            'shippingKurus' => Shop::shippingFeeKurus(),
            'errors' => $errors,
            'old' => $old,
            'related' => $repo->related($product, 4),
            'cities' => Shop::CITIES,
            'defaultNumber' => $number,
            'whatsappLink' => Shop::whatsappLink('Merhaba, “' . $product['title'] . '” satışa çıktığında haber verir misiniz?'),
            'askLink' => Shop::whatsappLink('Merhaba, “' . $product['title'] . '” hakkında bilgi almak istiyorum.'),
        ]), $status);
        return $errors !== [] ? self::private($response) : $response;
    }

    private function legal(string $doc, string $title, string $description): Response
    {
        if (!Shop::enabled()) {
            return Response::notFound();
        }
        $path = match ($doc) {
            'mesafeli' => '/mesafeli-satis-sozlesmesi',
            'on-bilgi' => '/on-bilgilendirme-formu',
            default => '/iptal-ve-iade',
        };
        $seo = new Seo(
            title: $title,
            description: $description,
            canonical: url($path),
            jsonLd: [['@type' => 'WebPage', 'name' => $title, 'url' => url($path), 'inLanguage' => 'tr-TR', 'isPartOf' => ['@type' => 'WebSite', 'name' => Seo::siteName(), 'url' => url('/')]]],
            breadcrumbs: [['name' => 'Forma Mağazası', 'url' => '/forma-magazasi'], ['name' => $title]],
        );
        $payments = new PaymentManager();
        return Response::html(View::render('pages/shop-legal', [
            'seo' => $seo,
            'activeNav' => 'magaza',
            'extraHead' => self::styles(),
            'doc' => $doc,
            'title' => $title,
            'company' => Shop::company(),
            'shippingKurus' => Shop::shippingFeeKurus(),
            'methods' => array_map(static fn ($g): string => $g->label(), $payments->available()),
            'contactEmail' => (string) config('app.contact_email', ''),
        ]));
    }

    /** Posted form values to re-fill the form (never prices). */
    private static function oldInput(Request $request): array
    {
        $old = [];
        foreach (['size', 'quantity', 'print', 'print_name', 'print_number', 'customer_name', 'phone', 'email', 'city', 'district', 'address', 'note', 'payment_method', 'kvkk', 'contract'] as $key) {
            $value = $request->input($key);
            if (is_string($value)) {
                $old[$key] = mb_substr($value, 0, 600);
            }
        }
        return $old;
    }

    /** Personal pages (order data): never cached, never indexed. */
    public static function private(Response $response): Response
    {
        return $response->withHeader('Cache-Control', 'no-store, private')->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /** Shop-specific styles on top of app.css (.product-*, .order-box, .size-options …), inlined like other modules. */
    public static function styles(): string
    {
        return '<style>'
            . '.shop-hero{padding-block:clamp(28px,5vw,64px) clamp(20px,3vw,36px);border-bottom:1px solid var(--border);background:radial-gradient(900px 340px at 100% 0,rgba(212,175,55,.16),transparent 62%),radial-gradient(700px 300px at 0 100%,rgba(200,16,46,.12),transparent 60%)}'
            . '.shop-hero h1{margin-bottom:.3em}.shop-hero .lead{margin-bottom:18px}'
            . '.shop-facts{display:flex;flex-wrap:wrap;gap:8px 18px;margin:0;padding:0;list-style:none;color:var(--text-muted);font-size:.9rem}'
            . '.shop-facts li{display:flex;align-items:center;gap:8px}.shop-facts li::before{content:"";width:8px;height:8px;border-radius:50%;background:var(--gold)}'
            . '.shop-chips{display:flex;gap:8px;overflow-x:auto;padding-bottom:6px;margin-bottom:22px;scrollbar-width:thin}.shop-chips .tag{flex:none}'
            . '.product-card .card-media{display:grid;place-items:center}.card-kicker{margin:0;color:var(--text-muted);font-size:.8rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}@media(max-width:639.98px){.product-card .badge-sale{display:none}}'
            . '.product-card .price-row .price{font-size:1.05rem}.soon-tag{color:var(--text-muted);font-size:.82rem}.shop-note{margin:18px 0 0;color:var(--text-muted);font-size:.88rem}.product-card .badge-sale{left:auto;right:10px}'
            . '.product-gallery{position:relative}.gallery-thumbs{display:flex;gap:10px;margin-top:12px}'
            . '.gallery-thumbs a{display:block;width:84px;aspect-ratio:1;border:1px solid var(--border);border-radius:10px;background:#141418;padding:6px}'
            . '.gallery-thumbs a[aria-current="true"]{border-color:var(--gold)}.gallery-thumbs img{width:100%;height:100%;object-fit:contain}.gallery-note{margin-top:10px}'
            . '.product-info h1{font-size:clamp(1.7rem,1.2rem + 2vw,2.6rem)}.product-info .price{font-size:1.6rem}'
            . '.product-meta{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px}'
            . '.size-group{margin:0;padding:0;border:0;min-width:0}.size-group legend{padding:0;margin-bottom:8px;font-weight:700;font-size:.92rem}'
            . '.size-sub{display:block;margin:10px 0 6px;color:var(--text-muted);font-size:.82rem}'
            . '.print-fields{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:12px}'
            . '.qty-input{max-width:120px}.order-section{display:grid;gap:16px;padding-top:18px;margin-top:4px;border-top:1px solid var(--border)}'
            . '.order-section h2{font-size:1.15rem;margin:0}'
            . '.pay-options{display:grid;gap:10px}.pay-option{display:flex;gap:12px;align-items:flex-start;padding:14px;border:1px solid var(--border);border-radius:12px;background:#0f0f13;cursor:pointer}'
            . '.pay-option:has(input:checked){border-color:var(--gold);background:rgba(212,175,55,.08)}.pay-option input{width:20px;height:20px;margin:2px 0 0;accent-color:var(--gold);flex:none}'
            . '.pay-option strong{display:block}.pay-option small{display:block;color:var(--text-muted);font-size:.84rem;line-height:1.45}'
            . '.order-total{display:flex;justify-content:space-between;align-items:baseline;gap:12px;font-weight:800;font-size:1.15rem}'
            . '.soon-box{display:grid;gap:12px;padding:clamp(18px,3vw,26px);border:1px solid rgba(212,175,55,.45);border-radius:var(--radius);background:linear-gradient(160deg,rgba(212,175,55,.12),rgba(212,175,55,.03))}'
            . '.soon-box h2{margin:0;font-size:1.35rem}.soon-box .badge{justify-self:start}.nowrap{white-space:nowrap}.summary-box .section-title,.order-box .section-title{font-size:1.3rem}.summary-box .form-help{margin-top:14px}@media(min-width:960px){.product-layout>:first-child{position:sticky;top:calc(var(--header-h) + 16px)}}.soon-box p{margin:0;color:var(--text-muted)}'
            . '.product-desc{margin-top:8px}.product-desc p{color:var(--text-muted)}'
            . '.info-list{display:grid;gap:10px;margin:0;padding:0;list-style:none}.info-list li{padding:12px 14px;border:1px solid var(--border);border-radius:11px;background:var(--bg-elev);font-size:.92rem}'
            . '.pay-frame{display:block;width:100%;min-height:760px;border:0;border-radius:12px;background:#fff}.pay-frame+.form-help{margin-top:10px}'
            . '.pay-layout{display:grid;gap:clamp(20px,3vw,36px);grid-template-columns:minmax(0,1fr)}@media(min-width:960px){.pay-layout{grid-template-columns:minmax(0,1fr) 360px;align-items:start}}'
            . '.bank-details{display:grid;gap:10px;margin:0}.bank-details>div{display:grid;gap:2px;padding:12px 14px;border:1px solid var(--border);border-radius:11px;background:#0f0f13}'
            . '.bank-details dt{color:var(--text-muted);font-size:.82rem}.bank-details dd{margin:0;font-weight:800;word-break:break-word}'
            . '.copy-row{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px}'
            . '.status-steps{display:grid;gap:0;margin:0;padding:0;list-style:none}.status-steps li{position:relative;padding:0 0 18px 30px;color:var(--text-muted)}'
            . '.status-steps li::before{content:"";position:absolute;left:6px;top:6px;width:12px;height:12px;border-radius:50%;border:2px solid var(--border);background:var(--bg)}'
            . '.status-steps li::after{content:"";position:absolute;left:12px;top:20px;bottom:0;width:2px;background:var(--border)}.status-steps li:last-child::after{display:none}'
            . '.status-steps li.done{color:var(--text)}.status-steps li.done::before{border-color:var(--gold);background:var(--gold)}.status-steps li.current{color:var(--gold-soft);font-weight:800}'
            . '.result-icon{display:grid;place-items:center;width:72px;height:72px;margin:0 auto 14px;border-radius:50%;font-size:2rem;font-weight:900}'
            . '.result-ok{background:rgba(34,197,94,.14);box-shadow:inset 0 0 0 2px #22c55e;color:#4ade80}.result-fail{background:rgba(200,16,46,.15);box-shadow:inset 0 0 0 2px var(--red);color:var(--red-soft)}'
            . '.result-actions{margin-top:22px}.order-box.center .summary-list{margin-top:18px;text-align:left}.result-wait{background:rgba(212,175,55,.12);box-shadow:inset 0 0 0 2px var(--gold);color:var(--gold)}'
            . '.legal-doc h2{font-size:1.25rem;margin-top:1.6em}.legal-doc .ph{padding:0 4px;border-radius:4px;background:rgba(212,175,55,.16);color:var(--gold-soft)}'
            . '</style>';
    }
}
