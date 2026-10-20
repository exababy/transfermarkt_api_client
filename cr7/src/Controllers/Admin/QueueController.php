<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Placeholder;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * News queue (news_items) review.
 * Stub created by the foundation ("Bu bölüm hazırlanıyor"); replaced by the admin module.
 */
final class QueueController
{
    public function index(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        return Placeholder::admin('Haber Kuyruğu');
    }

    public function publish(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        return Placeholder::admin('Haber Kuyruğu');
    }

    public function skip(Request $request): Response
    {
        if ($redirect = Auth::require()) {
            return $redirect;
        }
        return Placeholder::admin('Haber Kuyruğu');
    }
}
