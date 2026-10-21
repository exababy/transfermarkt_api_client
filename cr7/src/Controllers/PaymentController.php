<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Seo;
use App\Core\View;
use App\Repositories\OrderRepository;
use App\Services\Payment\PaymentManager;
use App\Services\Payment\PaytrGateway;
use App\Support\Cache;
use App\Support\Date;
use App\Support\Log;

/**
 * Payment start page (/odeme/{key}), gateway callbacks and the result page (/odeme/sonuc/{key}).
 *
 * Callbacks are cross-site POSTs (no session cookie under SameSite=Lax) and CSRF-exempt by design:
 * they are authenticated by the gateway signatures and identify the order by order_no / merchant_oid /
 * basketId — never by session. Paid state changes are idempotent (PaymentManager::applyCallback()).
 */
final class PaymentController
{
    public function start(Request $request): Response
    {
        $orders = new OrderRepository();
        $order = $orders->findByKey((string) $request->param('key'));
        if ($order === null) {
            return Response::notFound();
        }
        $resultUrl = url('/odeme/sonuc/' . $order['access_key']);
        if ($order['payment_status'] === 'odendi') {
            return Response::redirect($resultUrl, 303);
        }
        $payments = new PaymentManager();
        $gateway = $payments->get((string) $order['payment_method']);
        $payment = ['type' => 'error', 'message' => 'Bu siparişin ödeme yöntemi şu anda kullanılamıyor. Lütfen bizimle iletişime geçin.'];
        if ($order['status'] === 'iptal') {
            $payment = ['type' => 'error', 'message' => 'Bu sipariş iptal edildiği için ödeme alınamıyor.'];
        } elseif ($order['payment_status'] === 'iade') {
            $payment = ['type' => 'error', 'message' => 'Bu siparişin ödemesi iade edildi.'];
        } elseif ($gateway !== null && $gateway->isConfigured()) {
            if ($order['payment_status'] === 'basarisiz' && $gateway->key() !== 'havale') {
                $orders->resetForRetry((int) $order['id']); // customer tries again
                $order['payment_status'] = 'bekliyor';
            }
            $context = $order + ['client_ip' => $request->ip()];
            if ($gateway instanceof PaytrGateway) {
                // A PayTR token is valid for timeout_limit (30 min): reuse it on reloads.
                $cacheKey = 'paytr-iframe-' . $order['id'] . '-' . md5($request->ip() . $order['total']);
                $payment = Cache::get($cacheKey);
                if (!is_array($payment) || ($payment['type'] ?? '') !== 'iframe') {
                    $payment = $gateway->start($context);
                    if ($payment['type'] === 'iframe') {
                        Cache::set($cacheKey, $payment, 20 * 60);
                    }
                }
            } else {
                $payment = $gateway->start($context);
            }
            if (($payment['type'] ?? '') === 'checkout' && !empty($payment['token'])) {
                $orders->setPaymentRef((int) $order['id'], $gateway->key() . '-token:' . $payment['token']);
            }
        }

        $seo = new Seo(
            title: 'Ödeme · Sipariş ' . $order['order_no'],
            description: 'CR7.com.tr Forma Mağazası güvenli ödeme sayfası.',
            robots: 'noindex,nofollow',
            breadcrumbs: [['name' => 'Forma Mağazası', 'url' => '/forma-magazasi'], ['name' => 'Ödeme']],
        );
        $response = Response::html(View::render('pages/payment', [
            'seo' => $seo,
            'activeNav' => 'magaza',
            'noAnalytics' => true, // the URL key opens the buyer's personal data
            'extraHead' => ShopController::styles(),
            'order' => $order,
            'payment' => $payment,
            'methodLabel' => $gateway !== null ? $gateway->label() : (string) $order['payment_method'],
            'statusUrl' => url('/siparis/' . $order['access_key']),
        ]));
        foreach ((array) ($payment['csp'] ?? []) as $directive => $sources) {
            $response = $response->withCsp((string) $directive, ...array_map('strval', (array) $sources));
        }
        return ShopController::private($response);
    }

    /** PayTR server-to-server notification: answer exactly "OK" once verified (also for repeats). */
    public function paytrCallback(Request $request): Response
    {
        $payments = new PaymentManager();
        $gateway = $payments->get('paytr');
        $result = $gateway->handleCallback($request);
        if (empty($result['valid'])) {
            $payments->applyCallback($gateway, $result, new OrderRepository()); // logs the rejection
            return Response::text('PAYTR notification failed: bad hash', 400);
        }
        $payments->applyCallback($gateway, $result, new OrderRepository());
        return Response::text('OK');
    }

    /** iyzico browser POST with `token`; the result is retrieved from the iyzico API. */
    public function iyzicoCallback(Request $request): Response
    {
        return $this->browserCallback('iyzico', $request);
    }

    /** Shopier browser POST (signed). */
    public function shopierCallback(Request $request): Response
    {
        return $this->browserCallback('shopier', $request);
    }

    public function result(Request $request): Response
    {
        $order = (new OrderRepository())->findByKey((string) $request->param('key'));
        if ($order === null) {
            return Response::notFound();
        }
        $payments = new PaymentManager();
        $gateway = $payments->get((string) $order['payment_method']);
        $failedRedirect = $request->query('durum') === 'basarisiz';
        $state = match (true) {
            $order['payment_status'] === 'odendi' => 'paid',
            $order['payment_status'] === 'basarisiz' || ($failedRedirect && $order['payment_status'] === 'bekliyor') => 'failed',
            $order['payment_method'] === 'havale' => 'transfer',
            !empty($order['payload']['review']) => 'review', // reported by the gateway, confirmed by the owner
            default => 'pending',
        };
        $recent = strtotime((string) $order['created_at'] . ' UTC') > time() - 1800;
        $seo = new Seo(
            title: match ($state) {
                'paid' => 'Ödemeniz alındı',
                'failed' => 'Ödeme tamamlanamadı',
                default => 'Siparişiniz alındı',
            },
            description: 'CR7.com.tr Forma Mağazası ödeme sonucu.',
            robots: 'noindex,nofollow',
            breadcrumbs: [['name' => 'Forma Mağazası', 'url' => '/forma-magazasi'], ['name' => 'Ödeme sonucu']],
        );
        return ShopController::private(Response::html(View::render('pages/payment-result', [
            'seo' => $seo,
            'activeNav' => 'magaza',
            'noAnalytics' => true,
            // Online payments: the gateway notification may arrive a few seconds after the redirect.
            'extraHead' => ShopController::styles() . ($state === 'pending' && $recent ? '<meta http-equiv="refresh" content="8">' : ''),
            'order' => $order,
            'state' => $state,
            'methodLabel' => $gateway !== null ? $gateway->label() : (string) $order['payment_method'],
            'payUrl' => url('/odeme/' . $order['access_key']),
            'statusUrl' => url('/siparis/' . $order['access_key']),
        ])));
    }

    private function browserCallback(string $key, Request $request): Response
    {
        $payments = new PaymentManager();
        $gateway = $payments->get($key);
        $orders = new OrderRepository();
        $result = $gateway->handleCallback($request);
        $applied = $payments->applyCallback($gateway, $result, $orders);
        $order = $applied['order'];
        // Only a verified callback may lead to the order's private result URL: redirecting on an
        // unverified POST would hand out access keys for guessed order numbers.
        if ($order !== null && in_array($applied['outcome'], ['paid', 'failed', 'duplicate', 'pending', 'mismatch'], true)) {
            return Response::redirect(url('/odeme/sonuc/' . $order['access_key']), 303);
        }
        Log::warning('payment', 'Browser callback without a matching order', ['gateway' => $key, 'outcome' => $applied['outcome'], 'at' => Date::now()]);
        return ErrorController::render(400, 'Ödeme sonucu doğrulanamadı. Ödemeniz alındıysa sipariş durumunuz kısa süre içinde güncellenecektir; sorun devam ederse lütfen bizimle iletişime geçin.');
    }
}
