<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Services\Payment\PaymentManager;
use App\Services\Shop\OrderNotifier;
use App\Services\Shop\Shop;
use App\Support\Log;

/**
 * Admin: shop orders (/admin/siparisler): filters by status / payment status / method, search by
 * order no, phone, name or e-mail; detail page with fulfilment status + cargo tracking updates,
 * "Havale ödemesi alındı" and manual payment status changes (e.g. refunds).
 */
final class OrdersController
{
    private const PER_PAGE = 30;

    public function index(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        $repo = new OrderRepository();
        $text = static function (string $key) use ($request): string {
            $value = $request->query($key, '');
            return is_string($value) ? mb_substr(trim($value), 0, 100) : '';
        };
        $filters = [
            'status' => $text('durum'),
            'payment_status' => $text('odeme'),
            'payment_method' => $text('yontem'),
            'q' => $text('q'),
        ];
        $filters['status'] = isset(Shop::STATUSES[$filters['status']]) ? $filters['status'] : '';
        $filters['payment_status'] = isset(Shop::PAYMENT_STATUSES[$filters['payment_status']]) ? $filters['payment_status'] : '';
        $filters['payment_method'] = in_array($filters['payment_method'], PaymentManager::KEYS, true) ? $filters['payment_method'] : '';
        $sayfa = (string) $request->query('sayfa', '1');
        $page = ctype_digit($sayfa) ? max(1, (int) $sayfa) : 1;
        [$orders, $total] = $repo->search($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $payments = new PaymentManager();
        $methods = [];
        foreach ($payments->all() as $key => $gateway) {
            $methods[$key] = $gateway->label();
        }
        return ProductsController::page('orders', 'Siparişler', [
            'orders' => $orders,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'filters' => $filters,
            'statusCounts' => $repo->countByStatus(),
            'paymentCounts' => $repo->countByPaymentStatus(),
            'methods' => $methods,
            'ordersOpen' => Shop::ordersOpen($payments),
        ], 200, [['name' => 'Siparişler']]);
    }

    public function show(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        $order = (new OrderRepository())->find((int) $request->param('id'));
        if ($order === null) {
            return Response::notFound();
        }
        $payments = new PaymentManager();
        $gateway = $payments->get((string) $order['payment_method']);
        return ProductsController::page('order-show', 'Sipariş ' . $order['order_no'], [
            'order' => $order,
            'product' => (new ProductRepository())->find($order['product_id']),
            'methodLabel' => $gateway !== null ? $gateway->label() : $order['payment_method'],
            'statusUrl' => url('/siparis/' . $order['access_key']),
            'payUrl' => url('/odeme/' . $order['access_key']),
        ], 200, [['name' => 'Siparişler', 'url' => '/admin/siparisler'], ['name' => $order['order_no']]]);
    }

    public function updateStatus(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        Csrf::check($request);
        $repo = new OrderRepository();
        $order = $repo->find((int) $request->param('id'));
        if ($order === null) {
            return Response::notFound();
        }
        $back = Response::redirect(url('/admin/siparisler/' . $order['id']));
        $action = AdminPage::input($request, '_action'); // '' => status form (array inputs read as '')
        $user = Auth::user()['username'] ?? null;

        if ($action === 'mark_paid') {
            if ($repo->markPaid($order['id'], 'manual:' . ($user ?? 'admin'), json_encode([
                'gateway' => $order['payment_method'],
                'manual' => true,
                'by' => $user,
                'note' => mb_substr(AdminPage::input($request, 'payment_note'), 0, 200),
                'received_at' => gmdate('c'),
            ], JSON_UNESCAPED_UNICODE) ?: null, ['bekliyor', 'basarisiz', 'iade'])) {
                Log::info('admin', 'Order marked paid manually', ['order_no' => $order['order_no'], 'user' => $user]);
                $fresh = $repo->find($order['id']);
                if ($fresh !== null) {
                    OrderNotifier::paid($fresh);
                }
                Session::flash('success', 'Ödeme alındı olarak işaretlendi; sipariş “Onaylandı” durumuna geçti.');
            } else {
                Session::flash('info', 'Bu siparişin ödemesi zaten alınmış.');
            }
            return $back;
        }

        if ($action === 'payment') {
            $status = AdminPage::input($request, 'payment_status');
            if (!isset(Shop::PAYMENT_STATUSES[$status])) {
                Session::flash('error', 'Geçersiz ödeme durumu.');
                return $back;
            }
            if ($status === 'odendi') {
                $repo->markPaid($order['id'], $order['payment_ref'] ?? ('manual:' . ($user ?? 'admin')), $order['payment_payload'] ?? null, ['bekliyor', 'basarisiz', 'iade']);
            } else {
                $repo->update($order['id'], ['payment_status' => $status] + ($status === 'bekliyor' ? ['paid_at' => null] : []));
            }
            Log::info('admin', 'Order payment status changed', ['order_no' => $order['order_no'], 'from' => $order['payment_status'], 'to' => $status, 'user' => $user]);
            Session::flash('success', 'Ödeme durumu güncellendi: ' . Shop::paymentStatusLabel($status) . '.');
            return $back;
        }

        $status = AdminPage::input($request, 'status');
        $tracking = mb_substr(AdminPage::input($request, 'cargo_tracking'), 0, 100);
        if (!isset(Shop::STATUSES[$status])) {
            Session::flash('error', 'Geçersiz sipariş durumu.');
            return $back;
        }
        if ($tracking !== '' && preg_match('/^[\p{L}\d .:\/#-]{3,100}$/u', $tracking) !== 1) {
            Session::flash('error', 'Kargo takip bilgisi yalnızca harf, rakam ve - . / : # içerebilir.');
            return $back;
        }
        // Re-activating a cancelled order takes its units again: refused when they were sold meanwhile
        // (atomic "stock >= quantity" update, a no-op for "on order" products).
        if ($order['status'] === 'iptal' && $status !== 'iptal'
            && !(new ProductRepository())->reserveStock((int) $order['product_id'], (int) $order['quantity'])) {
            Session::flash('error', 'Stok yetersiz: ürünün kalan stoğu bu siparişi karşılamıyor, sipariş iptal durumunda bırakıldı.');
            return $back;
        }
        $repo->update($order['id'], ['status' => $status, 'cargo_tracking' => $tracking !== '' ? $tracking : null]);
        if ($status === 'iptal' && $order['status'] !== 'iptal') {
            // Give a limited stock back (no-op for "on order" products).
            \App\Core\Database::execute('UPDATE products SET stock = stock + ? WHERE id = ? AND stock IS NOT NULL', [$order['quantity'], $order['product_id']]);
        }
        Log::info('admin', 'Order status changed', ['order_no' => $order['order_no'], 'from' => $order['status'], 'to' => $status, 'user' => $user]);
        Session::flash('success', 'Sipariş durumu güncellendi: ' . Shop::statusLabel($status) . '.');
        return $back;
    }
}
