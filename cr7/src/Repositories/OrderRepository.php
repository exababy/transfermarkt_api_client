<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Services\Shop\Shop;
use App\Support\Date;

/**
 * Shop orders (table `orders`). Public contract (spec §9b): countByStatus().
 * Payment updates are conditional UPDATEs so repeated gateway callbacks are idempotent.
 */
final class OrderRepository
{
    /** @return array<string, int> every order status => count (zeros included), e.g. ['yeni' => 2, 'onaylandi' => 0, …] */
    public function countByStatus(): array
    {
        $counts = array_fill_keys(array_keys(Shop::STATUSES), 0);
        foreach (Database::fetchAll('SELECT status, COUNT(*) AS n FROM orders GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }
        return $counts;
    }

    /** @return array<string, int> payment status => count (zeros included) */
    public function countByPaymentStatus(): array
    {
        $counts = array_fill_keys(array_keys(Shop::PAYMENT_STATUSES), 0);
        foreach (Database::fetchAll('SELECT payment_status, COUNT(*) AS n FROM orders GROUP BY payment_status') as $row) {
            $counts[(string) $row['payment_status']] = (int) $row['n'];
        }
        return $counts;
    }

    public function find(int $id): ?array
    {
        $row = Database::fetchOne('SELECT * FROM orders WHERE id = ?', [$id]);
        return $row === null ? null : self::decorate($row);
    }

    /** Order by its public access key (32 hex chars); null for anything else. */
    public function findByKey(string $key): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $key) !== 1) {
            return null;
        }
        $row = Database::fetchOne('SELECT * FROM orders WHERE access_key = ?', [$key]);
        return $row === null ? null : self::decorate($row);
    }

    public function findByOrderNo(string $orderNo): ?array
    {
        $orderNo = strtoupper(trim($orderNo));
        if (preg_match('/^CR7-[A-Z0-9]{6}$/', $orderNo) !== 1) {
            return null;
        }
        $row = Database::fetchOne('SELECT * FROM orders WHERE order_no = ?', [$orderNo]);
        return $row === null ? null : self::decorate($row);
    }

    /** Order whose payment_ref equals $ref (e.g. 'iyzico-token:…' stored when the payment form was opened). */
    public function findByPaymentRef(string $ref): ?array
    {
        $row = Database::fetchOne('SELECT * FROM orders WHERE payment_ref = ? ORDER BY id DESC LIMIT 1', [$ref]);
        return $row === null ? null : self::decorate($row);
    }

    public function orderNoExists(string $orderNo): bool
    {
        return Database::fetchValue('SELECT id FROM orders WHERE order_no = ?', [$orderNo]) !== null;
    }

    /** Orders placed from an IP since a UTC datetime (rate limit). */
    public function countByIpSince(string $ip, string $sinceUtc): int
    {
        return (int) Database::fetchValue('SELECT COUNT(*) FROM orders WHERE ip = ? AND created_at >= ?', [$ip, $sinceUtc]);
    }

    /** Insert an order row; returns its id. */
    public function create(array $data): int
    {
        $now = Date::now();
        $data += ['created_at' => $now, 'updated_at' => $now];
        return Database::insert('orders', $data);
    }

    /** Admin: change fulfilment status / cargo tracking / payment status. Returns affected rows. */
    public function update(int $id, array $data): int
    {
        $allowed = array_intersect_key($data, array_flip(['status', 'cargo_tracking', 'payment_status', 'paid_at', 'payment_ref', 'payment_payload']));
        if ($allowed === []) {
            return 0;
        }
        $allowed['updated_at'] = Date::now();
        return Database::update('orders', $allowed, 'id = ?', [$id]);
    }

    /** Store gateway data (e.g. iyzico token) on a still-unpaid order without changing its state. */
    public function setPaymentRef(int $id, string $ref, ?string $payload = null): void
    {
        $data = ['payment_ref' => mb_substr($ref, 0, 190), 'updated_at' => Date::now()];
        if ($payload !== null) {
            $data['payment_payload'] = $payload;
        }
        Database::update('orders', $data, "id = ? AND payment_status <> 'odendi'", [$id]);
    }

    /**
     * Mark an order paid exactly once (idempotent): returns true only for the call that changed it.
     * A new order moves to 'onaylandi' at the same time. Only a waiting or failed order can become paid, so a
     * replayed/late gateway callback never revives a refunded ('iade') order; the admin may pass 'iade' in $from.
     *
     * @param list<string> $from payment statuses the order may be in
     */
    public function markPaid(int $id, ?string $ref, ?string $payload, array $from = ['bekliyor', 'basarisiz']): bool
    {
        $from = array_values(array_intersect($from, ['bekliyor', 'basarisiz', 'iade']));
        if ($from === []) {
            return false;
        }
        $now = Date::now();
        return Database::execute(
            "UPDATE orders SET payment_status = 'odendi', paid_at = ?, payment_ref = COALESCE(?, payment_ref), payment_payload = ?, "
            . "status = CASE WHEN status = 'yeni' THEN 'onaylandi' ELSE status END, updated_at = ? "
            . 'WHERE id = ? AND payment_status IN (' . implode(', ', array_fill(0, count($from), '?')) . ')',
            [$now, $ref !== null ? mb_substr($ref, 0, 190) : null, $payload, $now, $id, ...$from]
        ) === 1;
    }

    /** Record a failed payment attempt (only while the order is still waiting for payment). */
    public function markFailed(int $id, ?string $ref, ?string $payload): bool
    {
        return Database::execute(
            "UPDATE orders SET payment_status = 'basarisiz', payment_ref = COALESCE(?, payment_ref), payment_payload = ?, updated_at = ? "
            . "WHERE id = ? AND payment_status IN ('bekliyor', 'basarisiz')",
            [$ref !== null ? mb_substr($ref, 0, 190) : null, $payload, Date::now(), $id]
        ) === 1;
    }

    /**
     * A gateway reported a payment it cannot prove (Shopier return): keep the order waiting with the report
     * stored for the owner to verify. Returns true when the order was updated.
     */
    public function markReview(int $id, ?string $ref, ?string $payload): bool
    {
        return Database::execute(
            "UPDATE orders SET payment_status = 'bekliyor', payment_ref = COALESCE(?, payment_ref), payment_payload = ?, updated_at = ? "
            . "WHERE id = ? AND payment_status IN ('bekliyor', 'basarisiz')",
            [$ref !== null ? mb_substr($ref, 0, 190) : null, $payload, Date::now(), $id]
        ) === 1;
    }

    /** A failed order the customer retries goes back to "waiting". */
    public function resetForRetry(int $id): void
    {
        Database::execute("UPDATE orders SET payment_status = 'bekliyor', updated_at = ? WHERE id = ? AND payment_status = 'basarisiz'", [Date::now(), $id]);
    }

    /**
     * Admin list. Filters: status, payment_status, q (order no / phone digits / name / e-mail).
     *
     * @return array{0: list<array<string, mixed>>, 1: int} [rows, total]
     */
    public function search(array $filters, int $limit = 30, int $offset = 0): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['status']) && isset(Shop::STATUSES[$filters['status']])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['payment_status']) && isset(Shop::PAYMENT_STATUSES[$filters['payment_status']])) {
            $where[] = 'payment_status = ?';
            $params[] = $filters['payment_status'];
        }
        if (!empty($filters['payment_method'])) {
            $where[] = 'payment_method = ?';
            $params[] = (string) $filters['payment_method'];
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . Database::likeEscape($q) . '%';
            $parts = ["order_no LIKE ? ESCAPE '\\'", "customer_name LIKE ? ESCAPE '\\'", "email LIKE ? ESCAPE '\\'"];
            array_push($params, '%' . Database::likeEscape(strtoupper($q)) . '%', $like, $like);
            $digits = preg_replace('/\D+/', '', $q) ?? '';
            if (strlen($digits) >= 4) {
                // phones are stored as 0XXXXXXXXXX; match the last digits the admin typed
                $parts[] = "phone LIKE ? ESCAPE '\\'";
                $params[] = '%' . Database::likeEscape(ltrim(preg_replace('/^(90|0)/', '', $digits) ?? $digits, '0')) . '%';
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        $sqlWhere = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) Database::fetchValue('SELECT COUNT(*) FROM orders' . $sqlWhere, $params);
        $rows = Database::fetchAll(
            'SELECT * FROM orders' . $sqlWhere . ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );
        return [array_map(self::decorate(...), $rows), $total];
    }

    /** Typed order row: amounts as floats, kuruş helpers, decoded payload. */
    public static function decorate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['product_id'] = (int) $row['product_id'];
        $row['quantity'] = (int) $row['quantity'];
        foreach (['unit_price', 'shipping_fee', 'total'] as $column) {
            $row[$column] = (float) $row[$column];
        }
        $row['total_kurus'] = Shop::kurus($row['total']);
        $row['kvkk_consent'] = (int) ($row['kvkk_consent'] ?? 0) === 1;
        $payload = $row['payment_payload'] ?? null;
        $decoded = is_string($payload) && $payload !== '' ? json_decode($payload, true) : null;
        $row['payload'] = is_array($decoded) ? $decoded : [];
        return $row;
    }
}
