<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Services\Shop\Shop;

/**
 * Havale / EFT: the customer transfers the total to the shop IBAN with the order number as reference;
 * the admin marks the order paid in /admin/siparisler/{id}. No callback.
 */
final class BankTransferGateway extends AbstractGateway
{
    public function key(): string
    {
        return 'havale';
    }

    public function label(): string
    {
        return 'Havale / EFT';
    }

    public function description(): string
    {
        return 'Sipariş numaranızı açıklamaya yazarak banka hesabımıza ödeme yaparsınız; ödemeniz kontrol edildikten sonra siparişiniz hazırlanır.';
    }

    public function isConfigured(): bool
    {
        return self::normalizeIban($this->str('iban')) !== '' && $this->str('account_holder') !== '';
    }

    /** "tr12 0006 …" => "TR120006…" ('' when it is not a plausible TR IBAN). */
    public static function normalizeIban(string $iban): string
    {
        $iban = strtoupper((string) preg_replace('/\s+/', '', $iban));
        return preg_match('/^TR\d{24}$/', $iban) === 1 ? $iban : '';
    }

    /** ISO 13616 mod-97 check. */
    public static function validIban(string $iban): bool
    {
        $iban = self::normalizeIban($iban);
        if ($iban === '') {
            return false;
        }
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) (($remainder . $chunk) % 97);
        }
        return $remainder === 1;
    }

    /** "TR120006…" => "TR12 0006 …" */
    public static function formatIban(string $iban): string
    {
        $iban = self::normalizeIban($iban);
        return trim(chunk_split($iban, 4, ' '));
    }

    public function start(array $order): array
    {
        if (!$this->isConfigured()) {
            return ['type' => 'error', 'message' => 'Havale/EFT bilgileri henüz eklenmedi. Lütfen bizimle iletişime geçin.'];
        }
        return [
            'type' => 'bank',
            'bank_name' => $this->str('bank_name'),
            'account_holder' => $this->str('account_holder'),
            'iban' => self::formatIban($this->str('iban')),
            'iban_raw' => self::normalizeIban($this->str('iban')),
            'reference' => (string) $order['order_no'],
            'amount' => Shop::decimal(Shop::kurus($order['total'])),
        ];
    }
}
