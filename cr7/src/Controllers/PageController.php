<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * Static pages: about, privacy policy, contact.
 * Stub created by the foundation ("Bu bölüm hazırlanıyor"); replaced by the site module.
 */
final class PageController
{
    public function about(Request $request): Response
    {
        return Placeholder::page('Hakkında', '');
    }

    public function privacy(Request $request): Response
    {
        return Placeholder::page('Gizlilik Politikası', '');
    }

    public function contact(Request $request): Response
    {
        return Placeholder::page('İletişim', '');
    }
}
