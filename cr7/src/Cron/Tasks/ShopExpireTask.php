<?php

declare(strict_types=1);

namespace App\Cron\Tasks;

use App\Cron\Task;
use App\Services\Shop\OrderService;
use App\Services\Shop\Shop;

/**
 * Cancels unpaid orders that hold limited stock (card payments after 2 hours, Havale/EFT after 72 hours)
 * and gives the stock back (every 30 minutes), see OrderService::expireUnpaid().
 */
final class ShopExpireTask implements Task
{
    public function name(): string
    {
        return 'shop-expire';
    }

    public function intervalMinutes(): int
    {
        return 30;
    }

    public function run(): string
    {
        $cancelled = (new OrderService())->expireUnpaid();
        return $cancelled . ' ödenmemiş sipariş iptal edildi, stok geri eklendi (kart ' . Shop::CARD_RESERVATION_HOURS
            . ' sa, havale ' . Shop::TRANSFER_RESERVATION_HOURS . ' sa).';
    }
}
