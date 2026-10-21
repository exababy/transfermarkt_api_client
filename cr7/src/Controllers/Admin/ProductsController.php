<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Core\Session;
use App\Core\Settings;
use App\Core\View;
use App\Install\Seeders\ProductSeeder;
use App\Repositories\ProductRepository;
use App\Services\Payment\BankTransferGateway;
use App\Services\Payment\PaymentManager;
use App\Services\Shop\KitIllustrator;
use App\Services\Shop\ProductImage;
use App\Services\Shop\Shop;
use App\Support\Log;
use App\Support\Str;

/**
 * Admin: jersey products (/admin/urunler) and shop + payment settings (/admin/odeme-ayarlari).
 * Every action requires a logged-in admin; every POST verifies the CSRF token.
 */
final class ProductsController
{
    /** Ronaldo's senior clubs (Transfermarkt ids) for the club select. */
    public const CLUBS = ['336' => 'Sporting CP', '985' => 'Manchester United', '418' => 'Real Madrid', '506' => 'Juventus', '18544' => 'Al-Nassr', '3300' => 'Portekiz'];

    /** Secret settings: shown masked, empty submit keeps the stored value. */
    private const SECRETS = ['payment.paytr.merchant_key', 'payment.paytr.merchant_salt', 'payment.iyzico.api_key', 'payment.iyzico.secret_key', 'payment.shopier.api_key', 'payment.shopier.api_secret'];

    public function index(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        $repo = new ProductRepository();
        $q = self::queryText($request, 'q');
        $club = self::queryText($request, 'takim');
        $page = max(1, (int) (ctype_digit((string) $request->query('sayfa', '1')) ? $request->query('sayfa', '1') : 1));
        $perPage = 50;
        [$rows, $total] = $repo->adminList($q, $club, $perPage, ($page - 1) * $perPage);
        return self::page('products', 'Ürünler', [
            'products' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'filters' => ['q' => $q, 'takim' => $club],
            'clubs' => $repo->allClubs(),
            'ordersOpen' => Shop::ordersOpen(),
        ], 200, [['name' => 'Ürünler']]);
    }

    public function create(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        return $this->form(null, self::defaults());
    }

    public function store(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        Csrf::check($request);
        return $this->save($request, null);
    }

    public function edit(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        $product = (new ProductRepository())->find((int) $request->param('id'));
        if ($product === null) {
            return Response::notFound();
        }
        return $this->form($product, self::formValues($product));
    }

    public function update(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        Csrf::check($request);
        $product = (new ProductRepository())->find((int) $request->param('id'));
        if ($product === null) {
            return Response::notFound();
        }
        return $this->save($request, $product);
    }

    public function delete(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        Csrf::check($request);
        $repo = new ProductRepository();
        $product = $repo->find((int) $request->param('id'));
        if ($product === null) {
            return Response::notFound();
        }
        $repo->delete($product['id']);
        ProductImage::delete($product['image_path'] ?? null);
        if (ProductSeeder::kitFor((string) $product['slug']) === null) {
            // Generated artwork of a non-catalogue product (catalogue kits stay: the seeder would recreate the product).
            foreach (['public/assets/img/kits/' . $product['slug'] . '.svg', 'public/assets/img/kits/' . $product['slug'] . '-on.svg', 'public/og/forma-' . $product['slug'] . '.png'] as $file) {
                if (preg_match('#^[a-z0-9/._-]+$#', $file) === 1 && is_file(\App\Core\App::basePath($file))) {
                    @unlink(\App\Core\App::basePath($file));
                }
            }
        }
        Log::info('admin', 'Product deleted', ['id' => $product['id'], 'slug' => $product['slug'], 'user' => Auth::user()['username'] ?? null]);
        Session::flash('success', '“' . $product['title'] . '” silindi. Geçmiş siparişlerdeki ürün bilgileri korunur.');
        return Response::redirect(url('/admin/urunler'));
    }

    public function paymentSettings(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        return $this->settingsPage([], []);
    }

    public function savePaymentSettings(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        Csrf::check($request);
        $in = static fn (string $key, int $max = 300): string => is_string($request->input($key)) ? mb_substr(trim(str_replace("\0", '', (string) $request->input($key))), 0, $max) : '';
        $on = static fn (string $key): bool => in_array($request->input($key), ['1', 'on'], true);
        $errors = [];
        $values = [];

        $values['shop.enabled'] = $on('shop_enabled');
        $values['shop.orders_enabled'] = $on('shop_orders_enabled');
        $fee = self::parseMoney($in('shop_shipping_fee', 20));
        if ($fee === null || $fee > 10000) {
            $errors['shop_shipping_fee'] = 'Kargo ücreti 0 ile 10.000 TL arasında olmalı.';
        } else {
            $values['shop.shipping_fee'] = $fee;
        }
        $whatsapp = $in('shop_whatsapp', 30);
        $digits = Shop::normalizeWhatsapp($whatsapp); // stored with the country code: 532… => 90532…
        if ($whatsapp !== '' && $digits === '') {
            $errors['shop_whatsapp'] = 'WhatsApp numarasını ülke koduyla yazın (ör. 905321234567).';
        } else {
            $values['shop.whatsapp'] = $digits;
        }
        $email = $in('shop_notify_email', 190);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['shop_notify_email'] = 'Geçerli bir e-posta adresi girin.';
        } else {
            $values['shop.notify_email'] = $email;
        }
        foreach (array_keys(Shop::COMPANY_FIELDS) as $key) {
            $values['shop.' . $key] = $in('shop_' . $key, $key === 'company_address' ? 400 : 190);
        }
        $companyEmail = $values['shop.company_email'];
        if ($companyEmail !== '' && filter_var($companyEmail, FILTER_VALIDATE_EMAIL) === false) {
            $errors['shop_company_email'] = 'Geçerli bir e-posta adresi girin.';
        }

        // PayTR
        $values['payment.paytr.enabled'] = $on('paytr_enabled');
        $values['payment.paytr.test_mode'] = $on('paytr_test_mode');
        $merchantId = $in('paytr_merchant_id', 20);
        if ($merchantId !== '' && !ctype_digit($merchantId)) {
            $errors['paytr_merchant_id'] = 'Mağaza no (merchant_id) yalnızca rakamlardan oluşur.';
        }
        $values['payment.paytr.merchant_id'] = $merchantId;
        // iyzico
        $values['payment.iyzico.enabled'] = $on('iyzico_enabled');
        $values['payment.iyzico.sandbox'] = $on('iyzico_sandbox');
        // Shopier
        $values['payment.shopier.enabled'] = $on('shopier_enabled');
        $index = $in('shopier_website_index', 2);
        if (!in_array($index, ['1', '2', '3', '4', '5'], true)) {
            $errors['shopier_website_index'] = 'Web sitesi indeksi 1–5 arasında olmalı.';
        } else {
            $values['payment.shopier.website_index'] = (int) $index;
        }
        // Havale/EFT
        $values['payment.havale.enabled'] = $on('havale_enabled');
        $values['payment.havale.bank_name'] = $in('havale_bank_name', 100);
        $values['payment.havale.account_holder'] = $in('havale_account_holder', 150);
        $iban = $in('havale_iban', 40);
        if ($iban !== '' && !BankTransferGateway::validIban($iban)) {
            $errors['havale_iban'] = 'Geçerli bir TR IBAN girin (TR ile başlayan 26 karakter).';
        } else {
            $values['payment.havale.iban'] = BankTransferGateway::normalizeIban($iban);
        }

        $secrets = [];
        foreach (self::SECRETS as $key) {
            $field = str_replace(['payment.', '.'], ['', '_'], $key);
            if ($on($field . '_clear')) {
                $secrets[$key] = '';
                continue;
            }
            $value = $in($field, 200);
            if ($value !== '') {
                if (preg_match('/^[\x21-\x7E]+$/', $value) !== 1) {
                    $errors[$field] = 'Anahtar boşluk veya Türkçe karakter içeremez.';
                } else {
                    $secrets[$key] = $value;
                }
            }
        }

        if ($errors !== []) {
            return $this->settingsPage($request->all(), $errors, 422);
        }
        foreach ($values + $secrets as $key => $value) {
            Settings::set($key, $value);
        }
        Settings::flush();
        Log::info('admin', 'Shop/payment settings saved', [
            'orders_enabled' => $values['shop.orders_enabled'],
            'secrets_changed' => array_keys($secrets),
            'user' => Auth::user()['username'] ?? null,
        ]);
        $message = 'Mağaza ve ödeme ayarları kaydedildi.';
        if ($values['shop.orders_enabled'] && (new PaymentManager())->available() === []) {
            Session::flash('warning', 'Siparişler açık ama kullanılabilir ödeme yöntemi yok: ürün sayfalarında hâlâ “Yakında satışta” görünür. En az bir ödeme yöntemini etkinleştirip bilgilerini girin.');
        }
        Session::flash('success', $message);
        return Response::redirect(url('/admin/odeme-ayarlari'));
    }

    /** Render an admin template inside layouts/admin (noindex). Shared with OrdersController. */
    public static function page(string $template, string $title, array $data = [], int $status = 200, array $crumbs = []): Response
    {
        $seo = new Seo(title: $title, robots: 'noindex,nofollow');
        $html = View::render('admin/' . $template, $data + ['title' => $title, 'seo' => $seo, 'crumbs' => $crumbs], 'layouts/admin');
        return Response::html($html, $status)->withHeader('X-Robots-Tag', 'noindex, nofollow')->withHeader('Cache-Control', 'no-store, private');
    }

    /** "1.499,90" / "1499,90" / "1499.90" / "1499" => 1499.9 (null when invalid or negative). */
    public static function parseMoney(string $value): ?float
    {
        $value = str_replace([' ', 'TL', '₺', "\u{00A0}"], '', trim($value));
        if ($value === '') {
            return 0.0;
        }
        if (str_contains($value, ',')) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $value) === 1) {
            $value = str_replace('.', '', $value); // "1.499" = one thousand four hundred ninety-nine
        }
        if (preg_match('/^\d+(\.\d{1,2})?$/', $value) !== 1) {
            return null;
        }
        return round((float) $value, 2);
    }

    /** "••••••••abcd" for stored secrets ('' when none). */
    public static function mask(string $secret): string
    {
        if ($secret === '') {
            return '';
        }
        return str_repeat('•', 8) . (strlen($secret) >= 12 ? substr($secret, -4) : '');
    }

    private function settingsPage(array $old, array $errors, int $status = 200): Response
    {
        $payments = new PaymentManager();
        $current = [
            'shop_enabled' => Shop::enabled(),
            'shop_orders_enabled' => Shop::ordersEnabled(),
            'shop_shipping_fee' => Shop::shippingFeeKurus() / 100,
            'shop_whatsapp' => (string) Settings::get('shop.whatsapp', ''),
            'shop_notify_email' => (string) Settings::get('shop.notify_email', ''),
        ];
        foreach (Shop::company() as $key => $value) {
            $current['shop_' . $key] = $value;
        }
        $masked = [];
        foreach (PaymentManager::KEYS as $key) {
            $config = $payments->get($key)?->config() ?? [];
            foreach ($config as $name => $value) {
                $field = $key . '_' . $name;
                if (in_array('payment.' . $key . '.' . $name, self::SECRETS, true)) {
                    $masked[$field] = self::mask(is_scalar($value) ? (string) $value : '');
                } else {
                    $current[$field] = is_bool($value) ? $value : (is_scalar($value) ? (string) $value : '');
                }
            }
        }
        $gatewayState = [];
        foreach ($payments->all() as $key => $gateway) {
            $gatewayState[$key] = [
                'label' => $gateway->label(),
                'available' => $gateway->isAvailable(),
                'configured' => $gateway->isConfigured(),
                'enabled' => Shop::bool($gateway->config()['enabled'] ?? false),
            ];
        }
        return self::page('payment-settings', 'Mağaza ve Ödeme Ayarları', [
            'current' => $current,
            'old' => $old,
            'errors' => $errors,
            'masked' => $masked,
            'gateways' => $gatewayState,
            'ordersOpen' => Shop::ordersOpen($payments),
            'paymentWarnings' => $payments->testModeWarnings(),
            'callbacks' => [
                'paytr' => url('/odeme/paytr/bildirim'),
                'iyzico' => url('/odeme/iyzico/donus'),
                'shopier' => url('/odeme/shopier/donus'),
            ],
            'companyFields' => Shop::COMPANY_FIELDS,
        ], $status, [['name' => 'Ödeme Ayarları']]);
    }

    private function form(?array $product, array $values, array $errors = [], int $status = 200): Response
    {
        $title = $product === null ? 'Yeni Ürün' : 'Ürünü Düzenle';
        return self::page('product-form', $title, [
            'product' => $product,
            'values' => $values,
            'errors' => $errors,
            'clubs' => self::CLUBS,
            'kitTypes' => Shop::KIT_TYPES,
            'catalogKit' => $product !== null && ProductSeeder::kitFor((string) $product['slug']) !== null,
        ], $status, [['name' => 'Ürünler', 'url' => '/admin/urunler'], ['name' => $product === null ? 'Yeni ürün' : (string) $product['title']]]);
    }

    private function save(Request $request, ?array $product): Response
    {
        $repo = new ProductRepository();
        $in = static fn (string $key, int $max = 300): string => is_string($request->input($key)) ? mb_substr(trim(str_replace("\0", '', (string) $request->input($key))), 0, $max + 1) : '';
        $on = static fn (string $key): bool => in_array($request->input($key), ['1', 'on'], true);
        $errors = [];
        $values = [
            'title' => $in('title', 255),
            'slug' => $in('slug', 190),
            'club_id' => $in('club_id', 20),
            'club_name' => $in('club_name', 150),
            'season' => $in('season', 20),
            'kit_type' => $in('kit_type', 20),
            'description' => mb_substr(str_replace("\r", '', is_string($request->input('description')) ? (string) $request->input('description') : ''), 0, 5001),
            'price' => $in('price', 20),
            'old_price' => $in('old_price', 20),
            'sizes' => $in('sizes', 190),
            'kids_sizes' => $in('kids_sizes', 190),
            'allow_print' => $on('allow_print'),
            'print_price' => $in('print_price', 20),
            'color1' => $in('color1', 10),
            'color2' => $in('color2', 10),
            'stock' => $in('stock', 10),
            'sort_order' => $in('sort_order', 10),
            'is_active' => $on('is_active'),
            'remove_image' => $on('remove_image'),
            'generate_kit' => $on('generate_kit'),
            'kit_pattern' => $in('kit_pattern', 10),
        ];
        if (mb_strlen($values['title']) < 5 || mb_strlen($values['title']) > 255) {
            $errors['title'] = 'Ürün adı 5–255 karakter olmalı.';
        }
        $slug = Str::slug($values['slug'] !== '' ? $values['slug'] : $values['title'], 190);
        $values['slug'] = $slug;
        if ($slug === '') {
            $errors['slug'] = 'Geçerli bir adres (slug) girin.';
        } else {
            $existing = $repo->findAnyBySlug($slug);
            if ($existing !== null && ($product === null || $existing['id'] !== $product['id'])) {
                $errors['slug'] = 'Bu adres başka bir ürün tarafından kullanılıyor.';
            }
        }
        if ($values['club_id'] !== '' && preg_match('/^[A-Za-z0-9-]{1,20}$/', $values['club_id']) !== 1) {
            $errors['club_id'] = 'Geçersiz kulüp kimliği.';
        }
        if ($values['club_name'] === '' && isset(self::CLUBS[$values['club_id']])) {
            $values['club_name'] = self::CLUBS[$values['club_id']];
        }
        if (mb_strlen($values['club_name']) < 2 || mb_strlen($values['club_name']) > 150) {
            $errors['club_name'] = 'Kulüp / takım adını yazın (marka olarak gösterilir).';
        }
        if (!isset(Shop::KIT_TYPES[$values['kit_type']])) {
            $errors['kit_type'] = 'Forma türünü seçin.';
        }
        if (mb_strlen($values['description']) > 5000) {
            $errors['description'] = 'Açıklama en fazla 5000 karakter olabilir.';
        }
        $price = self::parseMoney($values['price']);
        if ($price === null || $price <= 0 || $price > 1000000) {
            $errors['price'] = 'Geçerli bir fiyat girin (ör. 1.499,90).';
        }
        $oldPrice = $values['old_price'] === '' ? null : self::parseMoney($values['old_price']);
        if ($values['old_price'] !== '' && ($oldPrice === null || ($price !== null && $oldPrice <= $price))) {
            $errors['old_price'] = 'Eski fiyat, satış fiyatından yüksek olmalı (boş bırakılabilir).';
        }
        $printPrice = self::parseMoney($values['print_price']);
        if ($printPrice === null || $printPrice > 100000) {
            $errors['print_price'] = 'Geçerli bir baskı ücreti girin (0 olabilir).';
        }
        $sizes = ProductRepository::sizeList($values['sizes']);
        $kids = ProductRepository::sizeList($values['kids_sizes']);
        if ($sizes === [] && $kids === []) {
            $errors['sizes'] = 'En az bir beden girin (virgülle ayırın).';
        }
        foreach ([...$sizes, ...$kids] as $size) {
            if (preg_match('/^[\p{L}\d .\/-]{1,20}$/u', $size) !== 1) {
                $errors['sizes'] = 'Bedenler harf, rakam, boşluk, nokta, eğik çizgi ve tire içerebilir.';
                break;
            }
        }
        foreach (['color1', 'color2'] as $key) {
            if ($values[$key] !== '' && KitIllustrator::color($values[$key], '') === '') {
                $errors[$key] = 'Renk #RRGGBB biçiminde olmalı.';
            }
        }
        if ($values['stock'] !== '' && (!ctype_digit($values['stock']) || (int) $values['stock'] > 100000)) {
            $errors['stock'] = 'Stok boş (sipariş üzerine) veya 0 ve üzeri bir sayı olmalı.';
        }
        if ($values['sort_order'] !== '' && preg_match('/^-?\d{1,6}$/', $values['sort_order']) !== 1) {
            $errors['sort_order'] = 'Sıra bir tam sayı olmalı.';
        }

        $upload = ['path' => null, 'error' => null];
        if ($errors === []) {
            $file = $_FILES['image'] ?? null;
            $upload = ProductImage::store(is_array($file) ? $file : null);
            if ($upload['error'] !== null) {
                $errors['image'] = $upload['error'];
            }
        }
        if ($errors !== []) {
            return $this->form($product, $values, $errors, 422);
        }

        $imagePath = $product['image_path'] ?? null;
        $oldUpload = null;
        if ($upload['path'] !== null) {
            $oldUpload = $imagePath;
            $imagePath = $upload['path'];
        } elseif ($values['remove_image']) {
            $oldUpload = $imagePath;
            $imagePath = null;
        }
        $color1 = KitIllustrator::color($values['color1'], '#c8102e');
        $color2 = KitIllustrator::color($values['color2'], '#ffffff');
        $kitFile = Shop::kitPath($slug);
        if ($upload['path'] === null && ($values['generate_kit'] || ($imagePath === null && $kitFile === null))) {
            // Original illustration from the product colours (catalogue products keep their designed kit).
            $spec = ProductSeeder::kitFor($slug) ?? [
                'pattern' => in_array($values['kit_pattern'], ['solid', 'hoops', 'stripes', 'halves'], true) ? $values['kit_pattern'] : 'solid',
                'base' => $color1,
                'alt' => $color2,
                'trim' => $color2,
                'text' => self::contrast($color1),
                'outline' => self::contrast($color1) === '#ffffff' ? '#111111' : '#ffffff',
            ];
            $written = KitIllustrator::writeSvgs($slug, $spec, $values['title']);
            KitIllustrator::png($spec, $slug, $values['title'], $values['club_name'] . ($values['season'] !== '' ? ' · ' . $values['season'] : ''));
            if ($written !== null && ($imagePath === null || str_starts_with((string) $imagePath, '/assets/img/kits/'))) {
                $imagePath = $written[0];
            }
        } elseif ($imagePath !== null && str_starts_with((string) $imagePath, '/assets/img/kits/') && $product !== null && $product['slug'] !== $slug) {
            $imagePath = Shop::kitPath($slug) ?? $imagePath; // slug changed: keep pointing at an existing file
        }

        $data = [
            'slug' => $slug,
            'club_id' => $values['club_id'] !== '' ? $values['club_id'] : null,
            'club_name' => $values['club_name'],
            'season' => $values['season'] !== '' ? $values['season'] : null,
            'kit_type' => $values['kit_type'],
            'title' => $values['title'],
            'description' => $values['description'] !== '' ? $values['description'] : null,
            'price' => number_format((float) $price, 2, '.', ''),
            'old_price' => $oldPrice !== null ? number_format($oldPrice, 2, '.', '') : null,
            'sizes' => implode(',', $sizes),
            'kids_sizes' => $kids !== [] ? implode(',', $kids) : null,
            'allow_print' => $values['allow_print'] ? 1 : 0,
            'print_price' => number_format((float) $printPrice, 2, '.', ''),
            'image_path' => $imagePath,
            'color1' => $values['color1'] !== '' ? $color1 : null,
            'color2' => $values['color2'] !== '' ? $color2 : null,
            'stock' => $values['stock'] !== '' ? (int) $values['stock'] : null,
            'sort_order' => $values['sort_order'] !== '' ? (int) $values['sort_order'] : 0,
            'is_active' => $values['is_active'] ? 1 : 0,
        ];
        if ($product === null) {
            $id = $repo->create($data);
            Session::flash('success', '“' . $data['title'] . '” eklendi.');
        } else {
            $id = $product['id'];
            $repo->update($id, $data);
            Session::flash('success', 'Değişiklikler kaydedildi.');
        }
        if ($oldUpload !== null && $oldUpload !== $imagePath) {
            ProductImage::delete($oldUpload);
        }
        Log::info('admin', $product === null ? 'Product created' : 'Product updated', ['id' => $id, 'slug' => $slug, 'user' => Auth::user()['username'] ?? null]);
        return Response::redirect(url('/admin/urunler/' . $id . '/duzenle'));
    }

    private static function defaults(): array
    {
        $max = 0;
        foreach ((new ProductRepository())->all(null, false) as $p) {
            $max = max($max, $p['sort_order']);
        }
        return [
            'title' => '', 'slug' => '', 'club_id' => '', 'club_name' => '', 'season' => '', 'kit_type' => 'ic-saha', 'description' => '',
            'price' => '', 'old_price' => '', 'sizes' => 'S,M,L,XL,XXL', 'kids_sizes' => '6-7 Yaş,8-9 Yaş,10-11 Yaş,12-13 Yaş',
            'allow_print' => true, 'print_price' => '250', 'color1' => '#c8102e', 'color2' => '#ffffff', 'stock' => '',
            'sort_order' => (string) ($max + 10), 'is_active' => true, 'remove_image' => false, 'generate_kit' => true, 'kit_pattern' => 'solid',
        ];
    }

    private static function formValues(array $p): array
    {
        $money = static fn (?float $v): string => $v === null ? '' : str_replace('.', ',', number_format($v, 2, '.', ''));
        return [
            'title' => $p['title'], 'slug' => $p['slug'], 'club_id' => (string) $p['club_id'], 'club_name' => $p['club_name'],
            'season' => (string) $p['season'], 'kit_type' => $p['kit_type'], 'description' => (string) $p['description'],
            'price' => $money($p['price']), 'old_price' => $money($p['old_price']), 'sizes' => implode(',', $p['sizes']),
            'kids_sizes' => implode(',', $p['kids_sizes']), 'allow_print' => $p['allow_print'], 'print_price' => $money($p['print_price']),
            'color1' => (string) $p['color1'], 'color2' => (string) $p['color2'], 'stock' => $p['stock'] === null ? '' : (string) $p['stock'],
            'sort_order' => (string) $p['sort_order'], 'is_active' => $p['is_active'], 'remove_image' => false, 'generate_kit' => false, 'kit_pattern' => 'solid',
        ];
    }

    /** Readable text colour on a background. */
    private static function contrast(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $l = (0.299 * hexdec(substr($hex, 0, 2)) + 0.587 * hexdec(substr($hex, 2, 2)) + 0.114 * hexdec(substr($hex, 4, 2))) / 255;
        return $l > 0.6 ? '#1b1b21' : '#ffffff';
    }

    private static function queryText(Request $request, string $key): string
    {
        $value = $request->query($key, '');
        return is_string($value) ? mb_substr(trim($value), 0, 100) : '';
    }
}
