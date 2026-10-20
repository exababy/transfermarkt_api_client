<?php

declare(strict_types=1);

namespace Tests;

use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\Settings;
use App\Install\Seeders\ProductSeeder;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Services\Payment\BankTransferGateway;
use App\Services\Payment\IyzicoGateway;
use App\Services\Payment\PaymentManager;
use App\Services\Payment\PaytrGateway;
use App\Services\Payment\ShopierGateway;
use App\Services\Shop\KitIllustrator;
use App\Services\Shop\OrderService;
use App\Services\Shop\Shop;
use App\Support\Date;

/**
 * Forma Mağazası + payments. Reference vectors were computed independently with openssl from the
 * documented formulas (see the gateway classes for the doc URLs), e.g.
 *   printf '%s' "$hashStr$salt" | openssl dgst -sha256 -hmac "$key" -binary | base64
 */
final class ShopTest extends TestCase
{
    private const IBAN = 'TR330006100519786457841326';

    private ?Router $router = null;

    // ------------------------------------------------------------------ gateway signatures

    public function testPaytrTokenMatchesDocumentedFormula(): void
    {
        $basket = PaytrGateway::basket([['Test Forma', 149990, 1]]);
        $this->assertSame('W1siVGVzdCBGb3JtYSIsIjE0OTkuOTAiLDFdXQ==', $basket, 'user_basket = base64(json)');
        $this->assertSame([['Test Forma', '1499.90', 1]], json_decode(base64_decode($basket), true));
        $token = PaytrGateway::token('123456', '88.247.1.2', 'CR7AB12CD', 'test@example.com', 149990, $basket, 0, 0, 'TL', 1, 'KEY123', 'SALT456');
        $this->assertSame('jETnZcvTo2ujE5qhtnCcdXmSRA97gq8wzJai0BSYfss=', $token);
        $this->assertSame('eklXTul2l28epAf/Rt8OnwUwf1ePeBQu4rrukY9A5OM=', PaytrGateway::callbackHash('CR7AB12CD', 'success', '149990', 'KEY123', 'SALT456'));
        $this->assertSame('CR7AB12CD', PaytrGateway::merchantOid('CR7-AB12CD'));
        $this->assertSame('CR7-AB12CD', PaytrGateway::orderNoFromOid('CR7AB12CD'));
        $this->assertNull(PaytrGateway::orderNoFromOid('XYZ123'));
    }

    public function testPaytrTokenRequestAndCallbackFlow(): void
    {
        $this->enableShop();
        $sent = [];
        $transport = static function (string $url, string $body, array $headers) use (&$sent): array {
            $sent[] = compact('url', 'body', 'headers');
            return ['status' => 200, 'body' => '{"status":"success","token":"tok123abc"}', 'error' => null];
        };
        $manager = new PaymentManager(null, null, $transport);
        $order = $this->placeOrder('paytr', $manager);
        $start = $manager->get('paytr')->start($order + ['client_ip' => '88.247.1.2']);
        $this->assertSame('iframe', $start['type']);
        $this->assertSame('https://www.paytr.com/odeme/guvenli/tok123abc', $start['url']);
        $this->assertContains('https://www.paytr.com', $start['csp']['frame-src']);
        $this->assertSame(PaytrGateway::TOKEN_URL, $sent[0]['url']);
        parse_str($sent[0]['body'], $fields);
        $this->assertSame('CR7' . substr($order['order_no'], 4), $fields['merchant_oid']);
        $this->assertSame((string) $order['total_kurus'], $fields['payment_amount'], 'kuruş');
        $this->assertSame('88.247.1.2', $fields['user_ip']);
        $expected = PaytrGateway::token('123456', '88.247.1.2', $fields['merchant_oid'], $order['email'], $order['total_kurus'], $fields['user_basket'], 0, 0, 'TL', 1, 'KEY123', 'SALT456');
        $this->assertSame($expected, $fields['paytr_token']);
        $basket = json_decode(base64_decode($fields['user_basket']), true);
        $this->assertSame('49.90', $basket[1][1], 'shipping line');
        $this->assertContains('/odeme/sonuc/' . $order['access_key'], $fields['merchant_ok_url']);

        // Notification: wrong hash => rejected, valid => paid once, repeats are no-ops.
        $oid = $fields['merchant_oid'];
        $total = (string) $order['total_kurus'];
        $orders = new OrderRepository();
        $bad = $this->post('/odeme/paytr/bildirim', ['merchant_oid' => $oid, 'status' => 'success', 'total_amount' => $total, 'hash' => 'x'], false);
        $this->assertSame(400, $bad->status());
        $this->assertSame('bekliyor', $orders->find($order['id'])['payment_status']);
        $hash = PaytrGateway::callbackHash($oid, 'success', $total, 'KEY123', 'SALT456');
        $post = ['merchant_oid' => $oid, 'status' => 'success', 'total_amount' => $total, 'payment_amount' => $total, 'hash' => $hash, 'test_mode' => '1'];
        $ok = $this->post('/odeme/paytr/bildirim', $post, false);
        $this->assertSame(200, $ok->status());
        $this->assertSame('OK', $ok->body(), 'PayTR expects exactly OK');
        $this->assertSame('text/plain; charset=utf-8', $ok->header('Content-Type'));
        $paid = $orders->find($order['id']);
        $this->assertSame('odendi', $paid['payment_status']);
        $this->assertSame('onaylandi', $paid['status']);
        $this->assertSame('paytr', $paid['payload']['gateway']);
        $this->assertFalse(isset($paid['payload']['hash']), 'hash not stored');
        $this->assertSame('OK', $this->post('/odeme/paytr/bildirim', $post, false)->body(), 'repeat answered OK');
        $failHash = PaytrGateway::callbackHash($oid, 'failed', $total, 'KEY123', 'SALT456');
        $this->post('/odeme/paytr/bildirim', ['merchant_oid' => $oid, 'status' => 'failed', 'total_amount' => $total, 'hash' => $failHash], false);
        $this->assertSame('odendi', $orders->find($order['id'])['payment_status'], 'late failure does not undo a payment');
    }

    public function testPaytrAmountMismatchIsNotMarkedPaid(): void
    {
        $this->enableShop();
        $manager = new PaymentManager();
        $order = $this->placeOrder('paytr', $manager);
        $oid = PaytrGateway::merchantOid($order['order_no']);
        $tampered = (string) ($order['total_kurus'] - 100);
        $request = Request::create('POST', '/odeme/paytr/bildirim', [
            'merchant_oid' => $oid, 'status' => 'success', 'total_amount' => $tampered, 'payment_amount' => $tampered,
            'hash' => PaytrGateway::callbackHash($oid, 'success', $tampered, 'KEY123', 'SALT456'),
        ]);
        $gateway = $manager->get('paytr');
        $result = $manager->applyCallback($gateway, $gateway->handleCallback($request), new OrderRepository());
        $this->assertSame('mismatch', $result['outcome']);
        $this->assertSame('basarisiz', (new OrderRepository())->find($order['id'])['payment_status']);
    }

    public function testIyzicoAuthorizationHeaderAndPriceFormat(): void
    {
        $header = IyzicoGateway::authorization('apiKeyX', 'secretY', 'rnd-123', '/payment/iyzipos/checkoutform/auth/ecom/detail', '{"locale":"tr","token":"tok-1"}');
        $this->assertSame('IYZWSv2 YXBpS2V5OmFwaUtleVgmcmFuZG9tS2V5OnJuZC0xMjMmc2lnbmF0dXJlOmY2MTI0NmVmMWRkMGIzMDE0OTliNTgzNGVhNjBhZTY3NWI1YTA2YTM3NWVmZDkwODlmNTYzMzJkMmU5Y2JkNTA=', $header);
        $decoded = base64_decode(substr($header, 8));
        $this->assertTrue(preg_match('/^apiKey:apiKeyX&randomKey:rnd-123&signature:[0-9a-f]{64}$/', $decoded) === 1, 'decoded header format');
        $this->assertSame('1499.9', IyzicoGateway::price(149990));
        $this->assertSame('250.0', IyzicoGateway::price(25000));
        $this->assertSame('1499.95', IyzicoGateway::price(149995));
        $this->assertSame('10', IyzicoGateway::signaturePrice(10.0));
        $this->assertSame('10.5', IyzicoGateway::signaturePrice('10.50'));
        $this->assertSame('1499.9', IyzicoGateway::signaturePrice(1499.9));
        $this->assertSame('c305f2d8cfc0813478c49782f4074fb24a173b0abb60d4631a8cf92ae3a9735f',
            IyzicoGateway::responseSignature(['SUCCESS', '12345', 'TRY', 'CR7-AB12CD', 'CR7-AB12CD', '1499.9', '1499.9', 'tok-1'], 'secretY'));
    }

    public function testIyzicoInitializeAndRetrieveWithFakeApi(): void
    {
        $this->enableShop();
        $calls = [];
        $retrieve = null;
        $transport = static function (string $url, string $body, array $headers) use (&$calls, &$retrieve): array {
            $calls[] = compact('url', 'body', 'headers');
            if (str_ends_with($url, IyzicoGateway::INITIALIZE_PATH)) {
                $req = json_decode($body, true);
                return ['status' => 200, 'error' => null, 'body' => json_encode([
                    'status' => 'success', 'conversationId' => $req['conversationId'], 'token' => 'tok9-0001-abcd',
                    'checkoutFormContent' => '<script type="text/javascript">var iyziInit = {};</script>',
                    'paymentPageUrl' => 'https://sandbox-cpp.iyzipay.com?token=tok9-0001-abcd',
                    'signature' => IyzicoGateway::responseSignature([$req['conversationId'], 'tok9-0001-abcd'], 'sandbox-secret'),
                ])];
            }
            return ['status' => 200, 'error' => null, 'body' => (string) json_encode($retrieve)];
        };
        $manager = new PaymentManager(null, null, $transport);
        $order = $this->placeOrder('iyzico', $manager);
        $gateway = $manager->get('iyzico');
        $this->assertTrue($gateway instanceof IyzicoGateway);
        $gateway->randomKey = static fn (): string => 'rnd-1';
        $start = $gateway->start($order + ['client_ip' => '88.247.1.2']);
        $this->assertSame('checkout', $start['type']);
        $this->assertSame(IyzicoGateway::SANDBOX_URL . IyzicoGateway::INITIALIZE_PATH, $calls[0]['url']);
        $this->assertSame(IyzicoGateway::authorization('sandbox-key', 'sandbox-secret', 'rnd-1', IyzicoGateway::INITIALIZE_PATH, $calls[0]['body']), $calls[0]['headers']['Authorization']);
        $this->assertSame('rnd-1', $calls[0]['headers']['x-iyzi-rnd']);
        $req = json_decode($calls[0]['body'], true);
        $this->assertSame($order['order_no'], $req['basketId']);
        $this->assertSame(IyzicoGateway::price($order['total_kurus']), $req['price']);
        $this->assertSame($req['price'], $req['paidPrice']);
        $sum = array_sum(array_map(static fn (array $i): int => (int) round(((float) $i['price']) * 100), $req['basketItems']));
        $this->assertSame($order['total_kurus'], $sum, 'basket items add up to price');
        $this->assertSame('+905321234567', $req['buyer']['gsmNumber']);
        $this->assertSame(['Ahmet', 'Yılmaz'], [$req['buyer']['name'], $req['buyer']['surname']]);
        $this->assertSame(url('/odeme/iyzico/donus'), $req['callbackUrl']);
        $this->assertContains('https://*.iyzipay.com', $start['csp']['script-src']);

        // Callback: the result comes from the (fake) retrieve API, verified by signature, basketId and price.
        $price = IyzicoGateway::price($order['total_kurus']);
        $make = static fn (string $status, string $price): array => [
            'status' => 'success', 'paymentStatus' => $status, 'paymentId' => '777', 'currency' => 'TRY', 'basketId' => $order['order_no'],
            'conversationId' => $order['order_no'], 'price' => (float) $price, 'paidPrice' => (float) $price, 'token' => 'tok9-0001-abcd', 'fraudStatus' => 1,
            'signature' => IyzicoGateway::responseSignature([$status, '777', 'TRY', $order['order_no'], $order['order_no'],
                IyzicoGateway::signaturePrice((float) $price), IyzicoGateway::signaturePrice((float) $price), 'tok9-0001-abcd'], 'sandbox-secret'),
        ];
        $retrieve = $make('SUCCESS', $price);
        $retrieve['signature'] = str_repeat('0', 64);
        $result = $gateway->handleCallback(Request::create('POST', '/odeme/iyzico/donus', ['token' => 'tok9-0001-abcd']));
        $this->assertFalse($result['valid'], 'bad response signature');
        $this->assertSame(IyzicoGateway::RETRIEVE_PATH, substr(end($calls)['url'], strlen(IyzicoGateway::SANDBOX_URL)));

        $retrieve = $make('SUCCESS', '1.0');
        $applied = $manager->applyCallback($gateway, $gateway->handleCallback(Request::create('POST', '/odeme/iyzico/donus', ['token' => 'tok9-0001-abcd'])), new OrderRepository());
        $this->assertSame('mismatch', $applied['outcome'], 'price must match the order');

        (new OrderRepository())->resetForRetry($order['id']);
        $retrieve = $make('SUCCESS', $price);
        $applied = $manager->applyCallback($gateway, $gateway->handleCallback(Request::create('POST', '/odeme/iyzico/donus', ['token' => 'tok9-0001-abcd'])), new OrderRepository());
        $this->assertSame('paid', $applied['outcome']);
        $this->assertSame('iyzico:777', $applied['order']['payment_ref']);
        $this->assertSame('odendi', $applied['order']['payment_status']);
    }

    public function testShopierSignaturesAndCallback(): void
    {
        $this->assertSame('STCkx5/xmdY5tL5PBklVyAnGkDsbODdTjF8M/VlyqE4=', ShopierGateway::requestSignature('123456', 'CR7-AB12CD', '1499.90', '0', 'SHOPSECRET'));
        $this->assertSame('D7XyEDA6qaqM+sh2MTapKbpAdmH517WZWXl54/FPbxU=', ShopierGateway::callbackSignature('654321', 'CR7-AB12CD', 'SHOPSECRET'));

        $this->enableShop();
        $manager = new PaymentManager();
        $order = $this->placeOrder('shopier', $manager);
        $gateway = $manager->get('shopier');
        $this->assertTrue($gateway instanceof ShopierGateway);
        $gateway->random = static fn (): int => 123456;
        $start = $gateway->start($order);
        $this->assertSame('form', $start['type']);
        $this->assertSame(ShopierGateway::PAYMENT_URL, $start['action']);
        $fields = $start['fields'];
        $this->assertSame(Shop::decimal($order['total_kurus']), $fields['total_order_value']);
        $this->assertSame(ShopierGateway::requestSignature('123456', $order['order_no'], $fields['total_order_value'], '0', 'shop-secret'), $fields['signature']);
        $this->assertSame(['https://www.shopier.com'], $start['csp']['form-action']);

        $bad = $this->post('/odeme/shopier/donus', ['platform_order_id' => $order['order_no'], 'status' => 'success', 'random_nr' => '1', 'signature' => 'nope'], false);
        $this->assertSame(400, $bad->status(), 'unverified callback never redirects to the private order URL');
        $this->assertSame('bekliyor', (new OrderRepository())->find($order['id'])['payment_status']);
        $ok = $this->post('/odeme/shopier/donus', [
            'platform_order_id' => $order['order_no'], 'status' => 'success', 'payment_id' => '998', 'installment' => '0', 'random_nr' => '654321',
            'signature' => ShopierGateway::callbackSignature('654321', $order['order_no'], 'shop-secret'),
        ], false);
        $this->assertSame(303, $ok->status());
        $this->assertSame(url('/odeme/sonuc/' . $order['access_key']), $ok->header('Location'));
        $this->assertSame('odendi', (new OrderRepository())->find($order['id'])['payment_status']);
    }

    // ------------------------------------------------------------------ orders

    public function testQuoteAndValidation(): void
    {
        $this->enableShop();
        $product = $this->product();
        $service = new OrderService();
        $quote = $service->quote($product, 2, true);
        $this->assertSame(174990 + 25000, $quote['unit_kurus']);
        $this->assertSame(2 * 199990 + 4990, $quote['total_kurus']);
        $this->assertSame(174990 + 4990, $service->quote($product, 1, false)['total_kurus']);
        $this->assertSame(5, $service->quote($product, 50, false)['quantity'], 'quantity capped');

        ['errors' => $errors] = $service->validate($product, ['size' => 'XXXL', 'quantity' => '0', 'print' => '1', 'print_name' => 'R0NALDO!', 'print_number' => '123', 'phone' => '12345', 'email' => 'x@', 'city' => 'Paris', 'payment_method' => 'paypal']);
        foreach (['size', 'quantity', 'print_name', 'print_number', 'customer_name', 'phone', 'email', 'city', 'district', 'address', 'payment_method', 'kvkk', 'contract'] as $field) {
            $this->assertTrue(isset($errors[$field]), 'error for ' . $field);
        }
        ['data' => $data, 'errors' => $errors] = $service->validate($product, $this->form(['size' => '8-9 Yaş', 'print_name' => 'cr yedi', 'print_number' => '07']));
        $this->assertSame([], $errors);
        $this->assertSame('CR YEDİ', $data['print_name'], 'Turkish upper-case');
        $this->assertSame('7', $data['print_number']);

        $this->assertSame('05321234567', OrderService::normalizePhone('0532 123 45 67'));
        $this->assertSame('05321234567', OrderService::normalizePhone('+90 (532) 123-45-67'));
        $this->assertSame('05321234567', OrderService::normalizePhone('5321234567'));
        $this->assertSame('02125550011', OrderService::normalizePhone('0212 555 00 11'));
        $this->assertNull(OrderService::normalizePhone('0132 123 45 67'));
        $this->assertNull(OrderService::normalizePhone('532123456'));
        $this->assertNull(OrderService::normalizePhone('0532abc4567'));
        $this->assertSame('0532 123 45 67', OrderService::formatPhone('05321234567'));
    }

    public function testPlaceOrderUsesServerPricesAndRandomKeys(): void
    {
        $this->enableShop();
        $result = (new OrderService())->place($this->product(), $this->form(['price' => '1', 'total' => '1', 'unit_price' => '1', 'quantity' => '2']), '10.0.0.1');
        $this->assertSame(201, $result['status']);
        $order = $result['order'];
        $this->assertTrue(preg_match('/^CR7-[A-HJ-NP-Z2-9]{6}$/', $order['order_no']) === 1, 'order no ' . $order['order_no']);
        $this->assertTrue(preg_match('/^[a-f0-9]{32}$/', $order['access_key']) === 1, 'access key');
        $this->assertSame(1999.9, $order['unit_price']);
        $this->assertSame(49.9, $order['shipping_fee']);
        $this->assertSame(4049.7, $order['total']);
        $this->assertSame('bekliyor', $order['payment_status']);
        $this->assertSame('yeni', $order['status']);
        $this->assertSame('RONALDO', $order['print_name']);
        $this->assertSame('05321234567', $order['phone']);
        $this->assertSame($order['id'], (new OrderRepository())->findByKey($order['access_key'])['id']);
        $this->assertNull((new OrderRepository())->findByKey('nothex'));
    }

    public function testHoneypotRateLimitAndStock(): void
    {
        $this->enableShop();
        $service = new OrderService();
        $product = $this->product();
        $this->assertSame(422, $service->place($product, $this->form([OrderService::HONEYPOT => 'http://spam']), '10.0.0.2')['status']);
        $this->assertSame(0, (int) Database::fetchValue('SELECT COUNT(*) FROM orders'));
        for ($i = 0; $i < Shop::RATE_LIMIT_PER_HOUR; $i++) {
            $this->assertSame(201, $service->place($product, $this->form(), '10.0.0.3')['status'], 'order ' . $i);
        }
        $limited = $service->place($product, $this->form(), '10.0.0.3');
        $this->assertSame(429, $limited['status']);
        $this->assertTrue(isset($limited['errors']['form']));
        $this->assertSame(201, $service->place($product, $this->form(), '10.0.0.4')['status'], 'other IP unaffected');
        Database::execute("UPDATE orders SET created_at = ? WHERE ip = '10.0.0.3'", [Date::ago(7200)]);
        $this->assertSame(201, $service->place($product, $this->form(), '10.0.0.3')['status'], 'window expired');

        Database::execute('UPDATE products SET stock = 1 WHERE id = ?', [$product['id']]);
        $this->assertSame(409, $service->place($this->product(), $this->form(['quantity' => '2']), '10.0.0.5')['status']);
        $this->assertSame(201, $service->place($this->product(), $this->form(['quantity' => '1']), '10.0.0.5')['status']);
        $this->assertSame(0, (int) Database::fetchValue('SELECT stock FROM products WHERE id = ?', [$product['id']]));
    }

    // ------------------------------------------------------------------ HTTP: closed vs open ordering

    public function testClosedOrderingShowsNoticeAndRejectsPost(): void
    {
        (new ProductSeeder())->run();
        $page = $this->get('/forma/real-madrid-2013-14-retro-formasi');
        $this->assertSame(200, $page->status());
        $html = $page->body();
        $this->assertContains('Yakında satışta', $html);
        $this->assertNotContains('id="order-form"', $html);
        $this->assertNotContains('name="payment_method"', $html);
        $this->assertContains('https://schema.org/OutOfStock', $html);
        $this->assertContains('"@type":"Product"', $html);
        $this->assertContains('"priceCurrency":"TRY"', $html);
        $this->assertContains('<link rel="canonical" href="' . url('/forma/real-madrid-2013-14-retro-formasi') . '">', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
        try {
            $this->post('/forma/real-madrid-2013-14-retro-formasi/siparis', $this->form());
            $this->fail('expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status());
        }
        $this->assertSame(0, (int) Database::fetchValue('SELECT COUNT(*) FROM orders'));

        // Orders switched on but no payment method configured => still closed.
        Settings::set('shop.orders_enabled', true);
        $this->assertFalse(Shop::ordersOpen());
        $this->assertNotContains('id="order-form"', $this->get('/forma/real-madrid-2013-14-retro-formasi')->body());
    }

    public function testOpenOrderingRendersFormAndPlacesHavaleOrder(): void
    {
        (new ProductSeeder())->run();
        $this->enableShop();
        $html = $this->get('/forma/real-madrid-2013-14-retro-formasi')->body();
        $this->assertContains('id="order-form"', $html);
        $this->assertContains('name="' . OrderService::HONEYPOT . '"', $html);
        $this->assertContains('https://schema.org/PreOrder', $html);
        foreach (['paytr', 'iyzico', 'shopier', 'havale'] as $key) {
            $this->assertContains('name="payment_method" value="' . $key . '"', $html);
        }
        $invalid = $this->post('/forma/real-madrid-2013-14-retro-formasi/siparis', $this->form(['email' => 'bozuk']));
        $this->assertSame(422, $invalid->status());
        $this->assertContains('Geçerli bir e-posta adresi girin.', $invalid->body());
        $this->assertContains('value="Ahmet Yılmaz"', $invalid->body(), 'old input kept');

        $response = $this->post('/forma/real-madrid-2013-14-retro-formasi/siparis', $this->form(['payment_method' => 'havale']));
        $this->assertSame(303, $response->status());
        $order = (new OrderRepository())->findByOrderNo((string) Database::fetchValue('SELECT order_no FROM orders'));
        $this->assertSame(url('/odeme/' . $order['access_key']), $response->header('Location'));
        $pay = $this->get('/odeme/' . $order['access_key']);
        $this->assertSame(200, $pay->status());
        $this->assertContains('TR33 0006 1005 1978 6457 8413 26', $pay->body());
        $this->assertContains($order['order_no'], $pay->body());
        $this->assertContains('noindex', (string) $pay->header('X-Robots-Tag'));
        $this->assertSame(200, $this->get('/siparis/' . $order['access_key'])->status());
        $this->assertSame(404, $this->get('/siparis/' . str_repeat('0', 32))->status());
        $this->assertSame(200, $this->get('/odeme/sonuc/' . $order['access_key'])->status());
    }

    public function testShopListingFilterLegalPagesAndDisabledShop(): void
    {
        (new ProductSeeder())->run();
        $list = $this->get('/forma-magazasi');
        $this->assertSame(200, $list->status());
        $this->assertContains('"@type":"ItemList"', $list->body());
        $this->assertSame(11, substr_count($list->body(), 'class="card product-card"'));
        $filtered = $this->get('/forma-magazasi?takim=985');
        $this->assertSame(3, substr_count($filtered->body(), 'class="card product-card"'), 'three United kits');
        $this->assertContains('Manchester United Ronaldo Formaları', $filtered->body());
        $this->assertSame(404, $this->get('/forma-magazasi?takim=1')->status());
        foreach (['/mesafeli-satis-sozlesmesi', '/on-bilgilendirme-formu', '/iptal-ve-iade'] as $path) {
            $this->assertSame(200, $this->get($path)->status(), $path);
        }
        Settings::set('shop.company_name', 'Örnek Ticaret Ltd. Şti.');
        $this->assertContains('Örnek Ticaret Ltd. Şti.', $this->get('/mesafeli-satis-sozlesmesi')->body());
        Settings::set('shop.enabled', false);
        $this->assertSame(404, $this->get('/forma-magazasi')->status());
        $this->assertSame(404, $this->get('/forma/real-madrid-2013-14-retro-formasi')->status());
    }

    // ------------------------------------------------------------------ repositories, seeder, artwork

    public function testRepositoryContractsAndSeeder(): void
    {
        $seeder = new ProductSeeder();
        $seeder->run();
        $seeder->run();
        $repo = new ProductRepository();
        $all = $repo->all();
        $this->assertCount(11, $all, 'idempotent by slug');
        foreach (['id', 'slug', 'title', 'club_id', 'club_name', 'season', 'kit_type', 'price', 'old_price', 'image_url', 'url', 'sizes'] as $key) {
            $this->assertTrue(array_key_exists($key, $all[0]), 'key ' . $key);
        }
        $this->assertSame(['S', 'M', 'L', 'XL', 'XXL'], $all[0]['sizes']);
        $this->assertSame('/forma/' . $all[0]['slug'], $all[0]['url']);
        $this->assertSame(['336' => 'Sporting CP', '985' => 'Manchester United', '418' => 'Real Madrid', '506' => 'Juventus', '18544' => 'Al-Nassr', '3300' => 'Portekiz'], $repo->clubs());
        $this->assertCount(3, $repo->all('985'));
        $sitemap = $repo->forSitemap();
        $this->assertCount(11, $sitemap);
        $this->assertSame(url('/forma/' . $all[0]['slug']), $sitemap[0]['url']);
        foreach ($all as $p) {
            $this->assertFalse(str_contains(mb_strtolower($p['description'] . $p['title']), 'orijinal') || str_contains(mb_strtolower($p['description']), 'lisanslı'), 'no originality claims: ' . $p['slug']);
            $this->assertTrue($p['price'] >= 1499.9 && $p['price'] <= 2299.9, 'price range ' . $p['slug']);
        }
        $edited = $all[0];
        Database::execute('UPDATE products SET title = ? WHERE id = ?', ['Admin başlığı', $edited['id']]);
        $seeder->run();
        $this->assertSame('Admin başlığı', $repo->find($edited['id'])['title'], 'seeder keeps admin edits');
        Database::execute('UPDATE products SET is_active = 0 WHERE id = ?', [$edited['id']]);
        $this->assertNull($repo->findBySlug($edited['slug']));
        $this->assertCount(10, $repo->all());
        $this->assertCount(11, $repo->all(null, false));

        Database::execute('DELETE FROM products WHERE id = ?', [$edited['id']]);
        $seeder->run();
        $this->assertCount(10, $repo->all(null, false), 'a deleted catalogue product is not re-seeded');

        $counts = (new OrderRepository())->countByStatus();
        $this->assertSame(['yeni' => 0, 'onaylandi' => 0, 'hazirlaniyor' => 0, 'kargoda' => 0, 'tamamlandi' => 0, 'iptal' => 0], $counts);
    }

    public function testKitArtworkAndHelpers(): void
    {
        $svg = KitIllustrator::svg(['pattern' => 'hoops', 'base' => '#ffffff', 'alt' => '#00703c', 'number' => '28'], 'back', 'Test & "kit"');
        $xml = simplexml_load_string($svg);
        $this->assertTrue($xml !== false, 'valid XML');
        $this->assertContains('RONALDO', $svg);
        $this->assertContains('>28<', $svg);
        $this->assertNotContains('<image', $svg, 'no embedded raster/logos');
        $this->assertTrue(simplexml_load_string(KitIllustrator::svg(['base' => '<script>'], 'front')) !== false, 'invalid colours fall back');
        foreach (ProductSeeder::products() as $p) {
            $this->assertTrue(is_file(App::basePath('public/assets/img/kits/' . $p['slug'] . '.svg')), 'kit file ' . $p['slug']);
        }
        $this->assertTrue(BankTransferGateway::validIban('TR33 0006 1005 1978 6457 8413 26'));
        $this->assertFalse(BankTransferGateway::validIban('TR34 0006 1005 1978 6457 8413 26'));
        $this->assertSame('TR33 0006 1005 1978 6457 8413 26', BankTransferGateway::formatIban(self::IBAN));
        $this->assertSame(149990, Shop::kurus('1499.90'));
        $this->assertSame('1499.90', Shop::decimal(149990));
        $this->assertSame("2016\u{2060}–\u{2060}17", Shop::nobr('2016–17'));
    }

    public function testGatewayAvailabilityFollowsSettings(): void
    {
        $manager = new PaymentManager();
        $this->assertSame([], $manager->available(), 'nothing available by default');
        Settings::set('payment.paytr.enabled', true);
        $this->assertSame([], (new PaymentManager())->available(), 'enabled without credentials is not available');
        Settings::set('payment.paytr.merchant_id', '1');
        Settings::set('payment.paytr.merchant_key', 'k');
        Settings::set('payment.paytr.merchant_salt', 's');
        Settings::set('payment.havale.iban', self::IBAN);
        Settings::set('payment.havale.account_holder', 'Test');
        $this->assertSame(['paytr', 'havale'], array_keys((new PaymentManager())->available()));
        Settings::set('shop.payment_methods', ['havale', 'paytr']);
        $this->assertSame(['havale', 'paytr'], array_keys((new PaymentManager())->available()), 'checkout order from shop.payment_methods');
    }

    public function testAdminPaymentSettingsKeepSecretsAndRequireLogin(): void
    {
        $this->assertSame(302, $this->get('/admin/odeme-ayarlari')->status());
        $this->assertSame(302, $this->get('/admin/siparisler')->status());
        $this->assertSame(302, $this->get('/admin/urunler')->status());
        Database::insert('users', ['username' => 'admin', 'password_hash' => password_hash('test-sifre-1', PASSWORD_DEFAULT), 'created_at' => Date::now()]);
        $this->assertTrue(Auth::attempt('admin', 'test-sifre-1', '127.0.0.1'));
        $form = [
            'shop_enabled' => '1', 'shop_orders_enabled' => '1', 'shop_shipping_fee' => '49,90', 'shop_whatsapp' => '0532 123 45 67',
            'paytr_enabled' => '1', 'paytr_merchant_id' => '123456', 'paytr_merchant_key' => 'KEY123', 'paytr_merchant_salt' => 'SALT456',
            'shopier_website_index' => '1', 'havale_enabled' => '1', 'havale_iban' => 'TR33 0006 1005 1978 6457 8413 26', 'havale_account_holder' => 'Test',
        ];
        $this->assertSame(302, $this->post('/admin/odeme-ayarlari', $form)->status());
        Settings::flush();
        $this->assertSame('KEY123', Settings::get('payment.paytr.merchant_key'));
        $this->assertTrue(Shop::ordersOpen());
        $this->assertSame(4990, Shop::shippingFeeKurus());
        unset($form['paytr_merchant_key'], $form['paytr_merchant_salt']);
        $this->post('/admin/odeme-ayarlari', $form);
        Settings::flush();
        $this->assertSame('KEY123', Settings::get('payment.paytr.merchant_key'), 'empty secret keeps the stored value');
        $page = $this->get('/admin/odeme-ayarlari')->body();
        $this->assertNotContains('KEY123', $page, 'secrets never printed');
        $this->assertContains(url('/odeme/paytr/bildirim'), $page);
        $this->assertSame(422, $this->post('/admin/odeme-ayarlari', ['havale_iban' => 'TR00 1234'] + $form)->status());

        // Orders admin: cancelling an order gives limited stock back; mark-paid is idempotent.
        $product = $this->product();
        Database::execute('UPDATE products SET stock = 5 WHERE id = ?', [$product['id']]);
        $order = (new OrderService())->place($this->product(), $this->form(['quantity' => '2']), '10.9.9.9')['order'];
        $this->assertSame(3, (int) Database::fetchValue('SELECT stock FROM products WHERE id = ?', [$product['id']]));
        $this->assertSame(200, $this->get('/admin/siparisler/' . $order['id'])->status());
        $this->assertSame(200, $this->get('/admin/siparisler?q=' . rawurlencode(substr($order['order_no'], 4)))->status());
        $this->post('/admin/siparisler/' . $order['id'] . '/durum', ['_action' => 'status', 'status' => 'iptal', 'cargo_tracking' => '']);
        $this->assertSame(5, (int) Database::fetchValue('SELECT stock FROM products WHERE id = ?', [$product['id']]));
        $this->post('/admin/siparisler/' . $order['id'] . '/durum', ['_action' => 'mark_paid']);
        $this->post('/admin/siparisler/' . $order['id'] . '/durum', ['_action' => 'mark_paid']);
        $this->assertSame('odendi', (new OrderRepository())->find($order['id'])['payment_status']);
        $this->post('/admin/siparisler/' . $order['id'] . '/durum', ['_action' => 'status', 'status' => 'kargoda', 'cargo_tracking' => 'YK 12345']);
        $fresh = (new OrderRepository())->find($order['id']);
        $this->assertSame(['kargoda', 'YK 12345'], [$fresh['status'], $fresh['cargo_tracking']]);
        $this->assertSame(3, (int) Database::fetchValue('SELECT stock FROM products WHERE id = ?', [$product['id']]), 'un-cancel takes stock again');
        $this->assertSame(200, $this->get('/admin/urunler')->status());
        $this->assertSame(200, $this->get('/admin/siparisler')->status());
    }

    // ------------------------------------------------------------------ helpers

    /** Shop open with every gateway configured (fake credentials) and a 49,90 TL shipping fee. */
    private function enableShop(): void
    {
        foreach ([
            'shop.orders_enabled' => true, 'shop.shipping_fee' => 49.9,
            'payment.paytr.enabled' => true, 'payment.paytr.merchant_id' => '123456', 'payment.paytr.merchant_key' => 'KEY123', 'payment.paytr.merchant_salt' => 'SALT456', 'payment.paytr.test_mode' => true,
            'payment.iyzico.enabled' => true, 'payment.iyzico.api_key' => 'sandbox-key', 'payment.iyzico.secret_key' => 'sandbox-secret', 'payment.iyzico.sandbox' => true,
            'payment.shopier.enabled' => true, 'payment.shopier.api_key' => 'shop-key', 'payment.shopier.api_secret' => 'shop-secret',
            'payment.havale.enabled' => true, 'payment.havale.iban' => self::IBAN, 'payment.havale.account_holder' => 'Örnek Ltd.', 'payment.havale.bank_name' => 'Örnek Bank',
        ] as $key => $value) {
            Settings::set($key, $value);
        }
    }

    private function product(): array
    {
        $repo = new ProductRepository();
        $product = $repo->findBySlug('real-madrid-2013-14-retro-formasi');
        if ($product === null) {
            (new ProductSeeder())->run();
            $product = $repo->findBySlug('real-madrid-2013-14-retro-formasi');
        }
        return $product;
    }

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'size' => 'M', 'quantity' => '2', 'print' => '1', 'print_name' => 'ronaldo', 'print_number' => '7',
            'customer_name' => 'Ahmet Yılmaz', 'phone' => '0532 123 45 67', 'email' => 'ahmet@example.com', 'city' => 'İstanbul',
            'district' => 'Kadıköy', 'address' => 'Caferağa Mah. Moda Cad. No: 7 D: 3', 'note' => '', 'payment_method' => 'havale',
            'kvkk' => '1', 'contract' => '1', OrderService::HONEYPOT => '',
        ];
    }

    private function placeOrder(string $method, PaymentManager $manager): array
    {
        $result = (new OrderService(null, null, $manager))->place($this->product(), $this->form(['payment_method' => $method]), '10.1.1.' . random_int(1, 200));
        $this->assertSame(201, $result['status'], 'order placed: ' . json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
        return $result['order'];
    }

    private function get(string $uri): Response
    {
        return $this->dispatch(Request::create('GET', $uri));
    }

    private function post(string $uri, array $post, bool $withToken = true): Response
    {
        if ($withToken) {
            $post['_token'] = Csrf::token();
        }
        return $this->dispatch(Request::create('POST', $uri, $post));
    }

    private function dispatch(Request $request): Response
    {
        if ($this->router === null) {
            $this->router = new Router();
            (require App::basePath('routes.php'))($this->router);
        }
        App::setRequest($request);
        Auth::reset();
        Settings::flush();
        return $this->router->dispatch($request);
    }
}
