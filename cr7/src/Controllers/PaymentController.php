<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * Payment start page, gateway callbacks (PayTR, iyzico, Shopier) and result page.
 * Stub created by the foundation ("Bu bölüm hazırlanıyor"); replaced by the shop module.
 * Callbacks are CSRF-exempt by design and must verify the gateway signature.
 */
final class PaymentController
{
    public function start(Request $request): Response
    {
        return Placeholder::page('Ödeme', 'magaza');
    }

    public function paytrCallback(Request $request): Response
    {
        return Response::text('Bu bölüm hazırlanıyor', 503);
    }

    public function iyzicoCallback(Request $request): Response
    {
        return Placeholder::page('Ödeme Sonucu', 'magaza');
    }

    public function shopierCallback(Request $request): Response
    {
        return Placeholder::page('Ödeme Sonucu', 'magaza');
    }

    public function result(Request $request): Response
    {
        return Placeholder::page('Ödeme Sonucu', 'magaza');
    }
}
