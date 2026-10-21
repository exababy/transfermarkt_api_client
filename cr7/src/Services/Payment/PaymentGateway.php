<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Core\Request;

/**
 * A payment method shown at checkout (PayTR, iyzico, Shopier, Havale/EFT).
 *
 * start() returns view data for /odeme/{key}:
 *   ['type' => 'iframe',   'url' => …, 'height' => int, 'csp' => [directive => [sources]]]       PayTR
 *   ['type' => 'checkout', 'html' => …(gateway snippet), 'page_url' => ?string, 'csp' => …]       iyzico
 *   ['type' => 'form',     'action' => …, 'fields' => [name => value], 'csp' => …]                Shopier (auto-submit)
 *   ['type' => 'bank',     'bank_name' => …, 'account_holder' => …, 'iban' => …, 'reference' => …] Havale/EFT
 *   ['type' => 'error',    'message' => Turkish text for the customer]
 *
 * handleCallback() verifies a gateway notification and returns a normalised result:
 *   ['valid' => bool, 'order_no' => ?string, 'paid' => bool, 'amount_kurus' => ?int, 'ref' => ?string,
 *    'payload' => array (sanitised, stored in orders.payment_payload), 'message' => string (log/debug)]
 * Amount and idempotency checks against the stored order are done by PaymentManager::applyCallback().
 */
interface PaymentGateway
{
    /** Machine key stored in orders.payment_method: paytr|iyzico|shopier|havale. */
    public function key(): string;

    /** Turkish label shown at checkout. */
    public function label(): string;

    /** Short Turkish description shown under the label at checkout. */
    public function description(): string;

    /** Enabled by the admin AND all required credentials are filled in. */
    public function isAvailable(): bool;

    /** Credentials present (callbacks are still verified after the admin disables a method). */
    public function isConfigured(): bool;

    /** @param array<string, mixed> $order decorated order row (+ 'client_ip') */
    public function start(array $order): array;

    public function handleCallback(Request $request): array;
}
