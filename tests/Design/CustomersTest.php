<?php

use Design\Customers;
use Design\OrderStatus;
use Design\Products;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/** @group db */
final class CustomersTest extends TestCase
{
    public function test_header_of_customer_29(): void
    {
        $h = Customers::header(29);
        $this->assertNotSame('', $h['name']);
        $this->assertMatchesRegularExpression('/^\p{Lu}{1,2}$/u', $h['initials']);
        $this->assertIsInt($h['balance']);
        $this->assertStringStartsWith(mb_substr($h['name'], 0, 1), $h['short']);
    }

    /** Баланс у шапці — вниз до гривні, як вікно оплати (Purchases::quote): 799,50 → 799 */
    public function test_header_balance_floors(): void
    {
        $orig = Sql::value('SELECT inv_sum FROM customers WHERE id = 29');
        try {
            foreach (['799.50' => 799, '799.99' => 799, '800.00' => 800, '0.40' => 0] as $sum => $expected) {
                Sql::exec('UPDATE customers SET inv_sum = ? WHERE id = 29', [$sum]);
                $this->assertSame($expected, Customers::header(29)['balance'], $sum);
            }
        } finally {
            Sql::exec('UPDATE customers SET inv_sum = ? WHERE id = 29', [$orig]);
        }
    }

    public function test_header_of_unknown_is_null(): void
    {
        $this->assertNull(Customers::header(999999999));
    }

    public function test_is_sub(): void
    {
        $this->assertFalse(Customers::isSub(999999999), 'немає такого клієнта — не субакаунт');
        $orig = Sql::value('SELECT parent_id FROM customers WHERE id = 29');
        try {
            Sql::exec('UPDATE customers SET parent_id = 25 WHERE id = 29');
            $this->assertTrue(Customers::isSub(29));
            Sql::exec('UPDATE customers SET parent_id = 0 WHERE id = 29');
            $this->assertFalse(Customers::isSub(29));
        } finally {
            Sql::exec('UPDATE customers SET parent_id = ? WHERE id = 29', [$orig]);
        }
    }

    public function test_facts_line(): void
    {
        $this->assertSame('Новий клієнт', Customers::factsLine(['orders' => 0, 'bought' => 0, 'cancelled' => 0]));
        $this->assertSame('Новий клієнт', Customers::factsLine(['orders' => 1, 'bought' => 0, 'cancelled' => 0]));
        $this->assertSame('Замовлень: 2 · куплено: 0 · скасовано: 1', Customers::factsLine(['orders' => 2, 'bought' => 0, 'cancelled' => 1]));
        $this->assertSame('Замовлень: 12 · куплено: 9 · скасовано: 0', Customers::factsLine(['orders' => 12, 'bought' => 9, 'cancelled' => 0]));
    }

    /** Факти для брифу: опубліковані (без чернеток), мали проведену купівлю, скасовані. Клієнт без рядка в customers — порожній набір */
    public function test_facts(): void
    {
        $nobody = 999999751;
        $created = [];
        try {
            $this->assertSame(['orders' => 0, 'bought' => 0, 'cancelled' => 0], Customers::facts($nobody));

            // Опубліковане замовлення має published_at; чернетка (і скасована чернетка) — NULL
            $mk = static function (int $status) use ($nobody, &$created): int {
                return $created[] = Sql::insert('design_orders', [
                    'customer_id' => $nobody, 'product_id' => Products::visible()[0]['id'], 'mode' => 1, 'status' => $status,
                    'answers' => json_encode([['q' => 'Розмір', 'a' => '90×50']], JSON_UNESCAPED_UNICODE),
                    'published_at' => $status === OrderStatus::DRAFT ? null : date('Y-m-d H:i:s'),
                ]);
            };
            $mk(OrderStatus::DRAFT);                       // чернетка — не рахується
            $this->assertSame(['orders' => 0, 'bought' => 0, 'cancelled' => 0], Customers::facts($nobody));

            $mk(OrderStatus::COLLECTING);
            $this->assertSame(['orders' => 1, 'bought' => 0, 'cancelled' => 0], Customers::facts($nobody));

            $bought = $mk(OrderStatus::PURCHASED);
            // дві проведені купівлі одного замовлення і одна непроведена — замовлення рахується один раз
            foreach ([2, 2, 1] as $st) {
                Sql::insert('design_purchases', ['order_id' => $bought, 'offer_id' => 0, 'payer_id' => $nobody, 'status' => $st]);
            }
            $pending = $mk(OrderStatus::IN_WORK);          // лише непроведена купівля — не «куплено»
            Sql::insert('design_purchases', ['order_id' => $pending, 'offer_id' => 0, 'payer_id' => $nobody, 'status' => 1]);
            $mk(OrderStatus::CANCELLED);
            $mk(OrderStatus::CANCELLED);

            $this->assertSame(['orders' => 5, 'bought' => 1, 'cancelled' => 2], Customers::facts($nobody));

            // Скасована чернетка (CANCELLABLE містить чернетку: статус 8, published_at NULL) — не опублікована, не рахується ні в N, ні в K
            $draft = $mk(OrderStatus::DRAFT);
            Sql::exec('UPDATE design_orders SET status = ?, cancel_reason = ?, cancelled_at = NOW() WHERE id = ?', [OrderStatus::CANCELLED, 'Не треба', $draft]);
            $this->assertNull(Sql::value('SELECT published_at FROM design_orders WHERE id = ?', [$draft]));
            $this->assertSame(['orders' => 5, 'bought' => 1, 'cancelled' => 2], Customers::facts($nobody), 'скасована чернетка не рахується');
            $this->assertSame(['orders' => 0, 'bought' => 0, 'cancelled' => 0], Customers::facts(999999752), 'чужі замовлення не рахуються');
        } finally {
            if ($created) {
                $in = implode(',', $created);
                Sql::exec("DELETE FROM design_purchases WHERE order_id IN ($in)");
                Sql::exec("DELETE FROM design_orders WHERE id IN ($in)");
            }
        }
    }

    /** Одне опубліковане замовлення і скасована чернетка — N = 1: «Новий клієнт» (чернетка не робить клієнта «постійним») */
    public function test_cancelled_draft_keeps_client_new(): void
    {
        $nobody = 999999753;
        $created = [];
        try {
            foreach ([[OrderStatus::COLLECTING, date('Y-m-d H:i:s')], [OrderStatus::CANCELLED, null]] as [$status, $published]) {
                $created[] = Sql::insert('design_orders', [
                    'customer_id' => $nobody, 'product_id' => Products::visible()[0]['id'], 'mode' => 1, 'status' => $status,
                    'answers' => json_encode([['q' => 'Розмір', 'a' => '90×50']], JSON_UNESCAPED_UNICODE), 'published_at' => $published,
                ]);
            }
            $facts = Customers::facts($nobody);
            $this->assertSame(['orders' => 1, 'bought' => 0, 'cancelled' => 0], $facts);
            $this->assertSame('Новий клієнт', Customers::factsLine($facts));
        } finally {
            if ($created) Sql::exec('DELETE FROM design_orders WHERE id IN (' . implode(',', $created) . ')');
        }
    }
}
