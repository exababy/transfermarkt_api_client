<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * Jersey shop: listing, product page, order submission, order status.
 * Stub created by the foundation ("Bu bölüm hazırlanıyor"); replaced by the shop module.
 */
final class ShopController
{
    public function index(Request $request): Response
    {
        if (!setting('shop.enabled', true)) {
            return Response::notFound();
        }
        return Placeholder::page('Forma Mağazası', 'magaza');
    }

    public function show(Request $request): Response
    {
        if (!setting('shop.enabled', true)) {
            return Response::notFound();
        }
        return Placeholder::page('Forma', 'magaza', [['name' => 'Forma Mağazası', 'url' => '/forma-magazasi'], ['name' => 'Forma']]);
    }

    public function order(Request $request): Response
    {
        if (!setting('shop.enabled', true) || !setting('shop.orders_enabled', false)) {
            abort(403, 'Siparişler şu anda kapalı. Formalarımız çok yakında satışta!');
        }
        return Placeholder::page('Sipariş', 'magaza');
    }

    public function status(Request $request): Response
    {
        return Placeholder::page('Sipariş Durumu', 'magaza');
    }
}
