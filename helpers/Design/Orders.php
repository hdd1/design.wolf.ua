<?php

namespace Design;

/** Замовлення дизайну (design_orders): бриф і замовлення — одна сутність */
final class Orders
{
    public const MAX_TITLE = 255;

    public static function create(int $customerId, int $productId, array $answers, string $title = ''): int
    {
        $product = Products::get($productId);
        if ($product === null) throw new BriefException('Продукт недоступний');

        return Sql::insert('design_orders', [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'mode' => 1,
            'title' => self::title($title),
            'status' => OrderStatus::DRAFT,
            'answers' => self::json(Brief::snapshot($product['questions'], $answers)),
        ]);
    }

    /** Питання — зі збереженого знімка, а не з продукту */
    public static function updateDraft(int $orderId, int $customerId, array $answers, string $title = ''): void
    {
        $order = self::get($orderId, $customerId);
        if ($order === null || $order['status'] !== OrderStatus::DRAFT) {
            throw new BriefException('Замовлення не можна змінити');
        }
        $snapshot = Brief::snapshot($order['answers'], $answers);
        Sql::exec('UPDATE design_orders SET answers = ?, title = ? WHERE id = ? AND customer_id = ? AND status = ?',
            [self::json($snapshot), self::title($title), $orderId, $customerId, OrderStatus::DRAFT]);
    }

    /** Чернетка → збір пропозицій. Повторна публікація нічого не змінює */
    public static function publish(int $orderId, int $customerId): void
    {
        $n = Sql::exec('UPDATE design_orders SET status = ?, published_at = NOW() WHERE id = ? AND customer_id = ? AND status = ?',
            [OrderStatus::COLLECTING, $orderId, $customerId, OrderStatus::DRAFT]);
        if ($n > 0) return;
        $order = self::get($orderId, $customerId);
        if ($order !== null && $order['status'] === OrderStatus::COLLECTING) return;
        throw new BriefException('Замовлення не можна опублікувати');
    }

    /** @return array{id:int, status:int, mode:int, product_id:int, product_name:string, title:string, answers:list<array{q:string,a:string}>, created:string, published_at:?string}|null чуже — null */
    public static function get(int $orderId, int $customerId): ?array
    {
        $r = Sql::one(
            'SELECT o.id, o.status, o.mode, o.title, o.product_id, p.name AS product_name, o.answers, o.created, o.published_at
             FROM design_orders o JOIN design_products p ON p.id = o.product_id
             WHERE o.id = ? AND o.customer_id = ?', [$orderId, $customerId]);
        if ($r === null) return null;
        $row = self::row($r);
        // Колонка JSON у MySQL сама переставляє ключі — повертаємо пари у вигляді {q, a}
        $answers = json_decode((string)$r['answers'], true);
        $row['answers'] = array_map(
            static fn($p): array => ['q' => (string)($p['q'] ?? ''), 'a' => (string)($p['a'] ?? '')],
            is_array($answers) ? $answers : []
        );
        return $row;
    }

    /** @return list<array{id:int, status:int, mode:int, product_id:int, product_name:string, title:string, created:string, published_at:?string}> нові зверху */
    public static function forCustomer(int $customerId): array
    {
        $rows = Sql::all(
            'SELECT o.id, o.status, o.mode, o.title, o.product_id, p.name AS product_name, o.created, o.published_at
             FROM design_orders o JOIN design_products p ON p.id = o.product_id
             WHERE o.customer_id = ? ORDER BY o.id DESC', [$customerId]);
        return array_map([self::class, 'row'], $rows);
    }

    private static function row(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'status' => (int)$r['status'],
            'mode' => (int)$r['mode'],
            'product_id' => (int)$r['product_id'],
            'product_name' => (string)$r['product_name'],
            'title' => (string)$r['title'] !== '' ? (string)$r['title'] : (string)$r['product_name'],
            'created' => (string)$r['created'],
            'published_at' => $r['published_at'] === null ? null : (string)$r['published_at'],
        ];
    }

    private static function title(string $title): string
    {
        $title = trim($title);
        if (mb_strlen($title) > self::MAX_TITLE) throw new BriefException('Назва занадто довга');
        return $title;
    }

    private static function json(array $snapshot): string
    {
        return json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
