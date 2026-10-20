<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Core\Database;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Services\Payment\PaymentManager;
use App\Support\Date;
use App\Support\Log;
use App\Support\Str;

/**
 * Order placement: validation of the public order form, server-side pricing (the posted form never
 * carries prices), honeypot + per-IP rate limit (Shop::RATE_LIMIT_PER_HOUR orders/hour), optional stock
 * reservation, order number 'CR7-XXXXXX' and the 32-hex access key used by /siparis/{key} and /odeme/{key}.
 */
final class OrderService
{
    /** Order number alphabet without look-alikes (0/O, 1/I). */
    private const ORDER_NO_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const HONEYPOT = 'website';

    private OrderRepository $orders;
    private ProductRepository $products;
    private PaymentManager $payments;

    public function __construct(?OrderRepository $orders = null, ?ProductRepository $products = null, ?PaymentManager $payments = null)
    {
        $this->orders = $orders ?? new OrderRepository();
        $this->products = $products ?? new ProductRepository();
        $this->payments = $payments ?? new PaymentManager();
    }

    /**
     * Price breakdown in kuruş.
     *
     * @return array{unit_kurus: int, print_kurus: int, subtotal_kurus: int, shipping_kurus: int, total_kurus: int, quantity: int, with_print: bool}
     */
    public function quote(array $product, int $quantity, bool $withPrint, ?int $shippingKurus = null): array
    {
        $quantity = max(1, min(Shop::MAX_QUANTITY, $quantity));
        $withPrint = $withPrint && !empty($product['allow_print']);
        $print = $withPrint ? Shop::kurus($product['print_price'] ?? 0) : 0;
        $unit = Shop::kurus($product['price']) + $print;
        $subtotal = $unit * $quantity;
        $shipping = $shippingKurus ?? Shop::shippingFeeKurus();
        return [
            'unit_kurus' => $unit,
            'print_kurus' => $print,
            'subtotal_kurus' => $subtotal,
            'shipping_kurus' => $shipping,
            'total_kurus' => $subtotal + $shipping,
            'quantity' => $quantity,
            'with_print' => $withPrint,
        ];
    }

    /**
     * Normalise and validate the posted order form.
     *
     * @return array{data: array<string, mixed>, errors: array<string, string>}
     */
    public function validate(array $product, array $input): array
    {
        $str = static function (string $key, int $max = 500) use ($input): string {
            $value = $input[$key] ?? '';
            if (!is_string($value)) {
                return '';
            }
            $value = str_replace(["\0", "\r"], '', $value);
            $value = (string) preg_replace('/[\x{0001}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}]/u', '', $value);
            return mb_substr(trim($value), 0, $max + 1);
        };
        $errors = [];
        $data = [];

        $sizes = array_merge($product['sizes'] ?? [], $product['kids_sizes'] ?? []);
        $data['size'] = $str('size', 20);
        if ($data['size'] === '' || !in_array($data['size'], $sizes, true)) {
            $errors['size'] = 'Lütfen bir beden seçin.';
        }

        $quantity = $str('quantity', 3);
        $data['quantity'] = ctype_digit($quantity) ? (int) $quantity : 0;
        if ($data['quantity'] < 1 || $data['quantity'] > Shop::MAX_QUANTITY) {
            $errors['quantity'] = 'Adet 1 ile ' . Shop::MAX_QUANTITY . ' arasında olmalıdır.';
        }

        $data['with_print'] = !empty($product['allow_print']) && in_array($input['print'] ?? null, ['1', 'on', 'evet'], true);
        $data['print_name'] = null;
        $data['print_number'] = null;
        if ($data['with_print']) {
            $name = Str::upper((string) preg_replace('/\s+/u', ' ', $str('print_name', 40)));
            if ($name === '' || mb_strlen($name) > Shop::PRINT_NAME_MAX || preg_match('/^[A-ZÇĞİÖŞÜÂÎÛ .\'-]+$/u', $name) !== 1) {
                $errors['print_name'] = 'Baskı ismi en fazla ' . Shop::PRINT_NAME_MAX . ' harf olmalı (yalnızca harf, boşluk, nokta, kesme ve tire).';
            }
            $number = $str('print_number', 5);
            if (preg_match('/^\d{1,2}$/', $number) !== 1) {
                $errors['print_number'] = 'Forma numarası 0 ile 99 arasında olmalıdır.';
            }
            $data['print_name'] = $name;
            $data['print_number'] = $number !== '' ? (string) (int) $number : '';
        }

        $data['customer_name'] = (string) preg_replace('/\s+/u', ' ', $str('customer_name', 150));
        if (mb_strlen($data['customer_name']) < 5 || mb_strlen($data['customer_name']) > 150
            || preg_match('/^[\p{L}][\p{L}\'’. -]*\s[\p{L}][\p{L}\'’. -]*$/u', $data['customer_name']) !== 1) {
            $errors['customer_name'] = 'Lütfen adınızı ve soyadınızı yazın.';
        }

        $phone = self::normalizePhone($str('phone', 30));
        $data['phone'] = $phone ?? $str('phone', 30);
        if ($phone === null) {
            $errors['phone'] = 'Geçerli bir telefon numarası girin (ör. 0532 123 45 67).';
        }

        $data['email'] = Str::lower($str('email', 190));
        if ($data['email'] === '' || mb_strlen($data['email']) > 190 || filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Geçerli bir e-posta adresi girin.';
        }

        $data['city'] = $str('city', 80);
        if (!in_array($data['city'], Shop::CITIES, true)) {
            $errors['city'] = 'Lütfen il seçin.';
        }
        $data['district'] = $str('district', 80);
        if (mb_strlen($data['district']) < 2 || mb_strlen($data['district']) > 80) {
            $errors['district'] = 'Lütfen ilçeyi yazın.';
        }
        $data['address'] = (string) preg_replace("/\n{3,}/", "\n\n", $str('address', 500));
        if (mb_strlen($data['address']) < 10 || mb_strlen($data['address']) > 500) {
            $errors['address'] = 'Teslimat adresini mahalle, sokak ve numara ile birlikte yazın (10–500 karakter).';
        }
        $data['note'] = $str('note', 500);
        if (mb_strlen($data['note']) > 500) {
            $errors['note'] = 'Sipariş notu en fazla 500 karakter olabilir.';
        }

        $data['payment_method'] = $str('payment_method', 20);
        if (!array_key_exists($data['payment_method'], $this->payments->available())) {
            $errors['payment_method'] = 'Lütfen bir ödeme yöntemi seçin.';
        }

        if (($input['kvkk'] ?? null) !== '1') {
            $errors['kvkk'] = 'Devam etmek için KVKK aydınlatma metnini onaylamanız gerekir.';
        }
        if (($input['contract'] ?? null) !== '1') {
            $errors['contract'] = 'Devam etmek için Ön Bilgilendirme Formu ve Mesafeli Satış Sözleşmesi’ni onaylamanız gerekir.';
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * Validate, price and store an order.
     *
     * @return array{order: ?array<string, mixed>, errors: array<string, string>, status: int, data: array<string, mixed>}
     */
    public function place(array $product, array $input, string $ip): array
    {
        $honeypot = $input[self::HONEYPOT] ?? '';
        if (!is_string($honeypot) || $honeypot !== '') {
            Log::warning('shop', 'Order honeypot triggered', ['ip' => $ip, 'product' => $product['slug'] ?? null]);
            return ['order' => null, 'errors' => ['form' => 'Siparişiniz alınamadı. Lütfen sayfayı yenileyip tekrar deneyin.'], 'status' => 422, 'data' => []];
        }
        if ($this->orders->countByIpSince($ip, Date::ago(3600)) >= Shop::RATE_LIMIT_PER_HOUR) {
            Log::warning('shop', 'Order rate limit reached', ['ip' => $ip]);
            return ['order' => null, 'errors' => ['form' => 'Kısa sürede çok fazla sipariş oluşturuldu. Lütfen bir saat sonra tekrar deneyin veya bizimle iletişime geçin.'], 'status' => 429, 'data' => []];
        }

        ['data' => $data, 'errors' => $errors] = $this->validate($product, $input);
        if ($errors !== []) {
            return ['order' => null, 'errors' => $errors, 'status' => 422, 'data' => $data];
        }

        $quote = $this->quote($product, (int) $data['quantity'], (bool) $data['with_print']);
        $order = Database::transaction(function () use ($product, $data, $quote, $ip): ?array {
            if (!$this->products->reserveStock((int) $product['id'], (int) $data['quantity'])) {
                return null;
            }
            $row = [
                'order_no' => $this->generateOrderNo(),
                'access_key' => self::accessKey(),
                'product_id' => (int) $product['id'],
                'product_title' => mb_substr((string) $product['title'], 0, 255),
                'size' => $data['size'],
                'quantity' => (int) $data['quantity'],
                'print_name' => $data['with_print'] ? $data['print_name'] : null,
                'print_number' => $data['with_print'] ? $data['print_number'] : null,
                'unit_price' => Shop::decimal($quote['unit_kurus']),
                'shipping_fee' => Shop::decimal($quote['shipping_kurus']),
                'total' => Shop::decimal($quote['total_kurus']),
                'customer_name' => $data['customer_name'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'city' => $data['city'],
                'district' => $data['district'] !== '' ? $data['district'] : null,
                'address' => $data['address'],
                'note' => $data['note'] !== '' ? $data['note'] : null,
                'payment_method' => $data['payment_method'],
                'payment_status' => 'bekliyor',
                'status' => 'yeni',
                'ip' => mb_substr($ip, 0, 45),
                'kvkk_consent' => 1,
            ];
            $id = $this->orders->create($row);
            return $this->orders->find($id);
        });
        if ($order === null) {
            return ['order' => null, 'errors' => ['quantity' => 'Bu üründen istediğiniz adette stok kalmadı.'], 'status' => 409, 'data' => $data];
        }
        Log::info('shop', 'Order created', ['order_no' => $order['order_no'], 'method' => $order['payment_method'], 'total' => $order['total']]);
        OrderNotifier::created($order);
        return ['order' => $order, 'errors' => [], 'status' => 201, 'data' => $data];
    }

    /** Turkish phone => '05XXXXXXXXX' (mobile 5xx or landline 2xx–4xx), null when invalid. */
    public static function normalizePhone(string $phone): ?string
    {
        if (preg_match('/[^\d\s()+.-]/', $phone) === 1) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '0090')) {
            $digits = substr($digits, 4);
        } elseif (strlen($digits) === 12 && str_starts_with($digits, '90')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        if (preg_match('/^[2-5]\d{9}$/', $digits) !== 1) {
            return null;
        }
        return '0' . $digits;
    }

    /** "05321234567" => "0532 123 45 67" */
    public static function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) !== 11) {
            return $phone;
        }
        return substr($digits, 0, 4) . ' ' . substr($digits, 4, 3) . ' ' . substr($digits, 7, 2) . ' ' . substr($digits, 9, 2);
    }

    /** 'CR7-' + 6 unambiguous upper-case characters, unique in `orders`. */
    public function generateOrderNo(): string
    {
        $max = strlen(self::ORDER_NO_ALPHABET) - 1;
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::ORDER_NO_ALPHABET[random_int(0, $max)];
            }
            $orderNo = 'CR7-' . $code;
            if (!$this->orders->orderNoExists($orderNo)) {
                return $orderNo;
            }
        }
        throw new \RuntimeException('Could not generate a unique order number.');
    }

    /** 32 lower-case hex characters (128 bits). */
    public static function accessKey(): string
    {
        return bin2hex(random_bytes(16));
    }
}
