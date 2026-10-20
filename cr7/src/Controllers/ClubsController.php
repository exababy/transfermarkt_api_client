<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * Clubs list and club detail pages.
 * Stub created by the foundation ("Bu bölüm hazırlanıyor"); replaced by the stats module.
 */
final class ClubsController
{
    public function index(Request $request): Response
    {
        return Placeholder::page('Oynadığı Takımlar', 'takimlar');
    }

    public function show(Request $request): Response
    {
        return Placeholder::page('Takım', 'takimlar', [['name' => 'Oynadığı Takımlar', 'url' => '/takimlar'], ['name' => 'Takım']]);
    }
}
