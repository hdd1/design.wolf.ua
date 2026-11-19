<?php

use Design\Chat;
use Design\DesignerStatus;
use Design\Messages;
use Design\Notify;
use Design\Offers;
use Design\OfferStatus;
use Design\OrderStatus;
use Design\Previews;
use Design\Products;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/** Текст повідомлення в HTML: екранування і клікабельні http(s)-посилання (те саме робить chat.js через textContent) */
final class ChatTest extends TestCase
{
    public function test_plain_text_escaped(): void
    {
        $this->assertSame('&lt;b&gt;жирний&lt;/b&gt; &amp; &quot;лапки&quot; &#039;x&#039;', Chat::html('<b>жирний</b> & "лапки" \'x\''));
        $this->assertSame("рядок 1\nрядок 2", Chat::html("рядок 1\nрядок 2"));
    }

    public function test_links_clickable_with_rel(): void
    {
        $a = static fn(string $u): string => '<a href="' . $u . '" target="_blank" rel="noopener nofollow ugc">' . $u . '</a>';
        $this->assertSame('Макет: ' . $a('https://drive.google.com/x?a=1&amp;b=2') . ' — гляньте',
            Chat::html('Макет: https://drive.google.com/x?a=1&b=2 — гляньте'));
        $this->assertSame('(' . $a('http://behance.net/petro') . ').', Chat::html('(http://behance.net/petro).'));
        $this->assertSame('«' . $a('https://a.ua/b') . '»', Chat::html('«https://a.ua/b»'));
        $this->assertSame($a('https://a.ua/') . '&lt;img&gt;', Chat::html('https://a.ua/<img>'));
        $this->assertSame('Див. ' . $a('https://uk.wikipedia.org/wiki/Київ_р') . '…', Chat::html('Див. https://uk.wikipedia.org/wiki/Київ_р…'), 'кирилиця в кінці не обрізається');
    }

    public function test_only_http_links(): void
    {
        $this->assertSame('javascript:alert(1) ftp://x.ua www.wolf.ua https://', Chat::html('javascript:alert(1) ftp://x.ua www.wolf.ua https://'));
    }

    public function test_quotes_not_part_of_link(): void
    {
        $this->assertSame('&quot;<a href="https://a.ua" target="_blank" rel="noopener nofollow ugc">https://a.ua</a>&quot;', Chat::html('"https://a.ua"'));
    }

    private const CLIENT = 999999811;
    private const DESIGNER = 999999812;

    private function cleanupDb(): void
    {
        $in = self::CLIENT . ',' . self::DESIGNER;
        Sql::exec("DELETE p FROM design_previews p JOIN design_orders d ON d.id = p.order_id WHERE d.customer_id IN ($in)");
        Sql::exec("DELETE m FROM design_messages m JOIN design_offers o ON o.id = m.offer_id JOIN design_orders d ON d.id = o.order_id WHERE d.customer_id IN ($in)");
        Sql::exec("DELETE o FROM design_offers o JOIN design_orders d ON d.id = o.order_id WHERE d.customer_id IN ($in)");
        Sql::exec("DELETE FROM design_orders WHERE customer_id IN ($in)");
        Sql::exec("DELETE FROM design_designers WHERE customer_id IN ($in)");
    }

    /**
     * Стрічка з прев'ю й цитатами: у повідомлення-прев'ю — preview (з Previews::forOrder), у відповіді — reply.
     * Тексти сирі (екранує chat.js через textContent), JSON без екранування HTML не ламається.
     * @group db
     */
    public function test_chat_payload_has_preview_and_quote(): void
    {
        $links = CdnLinksGuard::snapshot();
        $oldRoot = getenv('DESIGN_WORK_ROOT');
        $root = sys_get_temp_dir() . '/design-chat-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        putenv('DESIGN_WORK_ROOT=' . $root);
        $this->cleanupDb();
        Notify::$sent = [];
        try {
            Sql::insert('design_designers', ['customer_id' => self::DESIGNER, 'status' => DesignerStatus::APPROVED, 'name' => 'Петро Дизайнер']);
            $orderId = Sql::insert('design_orders', [
                'customer_id' => self::CLIENT, 'product_id' => Products::visible()[0]['id'], 'status' => OrderStatus::IN_WORK, 'answers' => '[]',
            ]);
            $offerId = Sql::insert('design_offers', ['order_id' => $orderId, 'designer_id' => self::DESIGNER, 'status' => OfferStatus::CHOSEN]);
            Sql::exec('UPDATE design_orders SET chosen_offer_id = ? WHERE id = ?', [$offerId, $orderId]);

            $upload = static function () use ($root): string {
                $im = new \Imagick();
                $im->newImage(200, 120, new \ImagickPixel('#224488'));
                $im->setImageFormat('jpeg');
                $path = $root . '/up-' . bin2hex(random_bytes(4)) . '.jpg';
                $im->writeImage($path);
                $im->clear();
                return $path;
            };
            $caption = 'Темний фон </script><b>"x"</b> ' . str_repeat('довгий підпис ', 20);
            $v1 = Previews::add($orderId, self::DESIGNER, $upload(), $caption, null);
            $v2 = Previews::add($orderId, self::DESIGNER, $upload(), '', $v1['id']);
            $text = Messages::add($offerId, self::DESIGNER, Messages::ROLE_DESIGNER, 'Звичайне повідомлення');
            $onV1 = Messages::add($offerId, self::CLIENT, Messages::ROLE_CLIENT, 'Коментар до v1', [], $v1['message_id']);
            $onV2 = Messages::add($offerId, self::CLIENT, Messages::ROLE_CLIENT, 'Коментар до v2', [], $v2['message_id']);
            $onText = Messages::add($offerId, self::DESIGNER, Messages::ROLE_DESIGNER, 'Відповідь на текст', [], $onV1);

            $access = Offers::access($offerId, self::CLIENT);
            $feed = array_column(Chat::messages($access), null, 'id');
            $this->assertSame([$v1['message_id'], $v2['message_id'], $text, $onV1, $onV2, $onText], array_keys($feed));

            // повідомлення-прев'ю: preview — елемент forOrder, text — підпис як є
            $p1 = $feed[$v1['message_id']];
            $this->assertSame(trim($caption), $p1['text']);
            $this->assertSame(Previews::forOrder($orderId)[$v1['id']], $p1['preview']);
            $this->assertSame(1, $p1['preview']['num']);
            $this->assertFalse($p1['preview']['hidden']);
            $this->assertMatchesRegularExpression('~^https://\S+\.webp$~', $p1['preview']['urls'][0]['card']);
            $this->assertNull($p1['reply']);
            $this->assertSame(1, $feed[$v2['message_id']]['preview']['based_on_num']);
            $this->assertSame('Петро Дизайнер', $p1['author']);
            $this->assertFalse($p1['mine']);

            // звичайне повідомлення — без прев'ю і цитати
            $this->assertNull($feed[$text]['preview']);
            $this->assertNull($feed[$text]['reply']);

            // цитата прев'ю: vN, мініатюра першої сторінки, текст підпису до 120 символів
            $q1 = $feed[$onV1]['reply'];
            $this->assertSame(['id', 'author', 'text', 'preview_num', 'thumb'], array_keys($q1));
            $this->assertSame($v1['message_id'], $q1['id']);
            $this->assertSame('Петро Дизайнер', $q1['author']);
            $this->assertSame(Chat::QUOTE_LEN, mb_strlen($q1['text']));
            $this->assertStringStartsWith('Темний фон </script><b>"x"</b> довгий', $q1['text']);
            $this->assertStringEndsWith('…', $q1['text']);
            $this->assertSame(1, $q1['preview_num']);
            $this->assertSame($p1['preview']['urls'][0]['card'], $q1['thumb']);
            $this->assertSame(['id' => $v2['message_id'], 'author' => 'Петро Дизайнер', 'text' => '', 'preview_num' => 2,
                'thumb' => $feed[$v2['message_id']]['preview']['urls'][0]['card']], $feed[$onV2]['reply']);
            $this->assertTrue($feed[$onV1]['mine']);

            // цитата звичайного повідомлення: без номера й мініатюри; автор — клієнт (без рядка customers — «Клієнт»)
            $this->assertSame(['id' => $onV1, 'author' => 'Клієнт', 'text' => 'Коментар до v1', 'preview_num' => null, 'thumb' => null],
                $feed[$onText]['reply']);

            // опитування після прев'ю: цитоване повідомлення поза порцією все одно цитується
            $poll = Chat::messages($access, $onV1, Chat::POLL_LIMIT);
            $this->assertSame([$onV2, $onText], array_column($poll, 'id'));
            $this->assertSame(2, $poll[0]['reply']['preview_num']);
            $this->assertSame($onV1, $poll[1]['reply']['id']);

            // приховане прев'ю: без адрес, цитата без мініатюри, номер і підпис лишаються
            Previews::hide($v1['id'], self::DESIGNER);
            $feed = array_column(Chat::messages($access), null, 'id');
            $this->assertTrue($feed[$v1['message_id']]['preview']['hidden']);
            $this->assertSame([], $feed[$v1['message_id']]['preview']['urls']);
            $this->assertSame(trim($caption), $feed[$v1['message_id']]['text']);
            $this->assertSame(1, $feed[$onV1]['reply']['preview_num']);
            $this->assertNull($feed[$onV1]['reply']['thumb']);
            $this->assertNotNull($feed[$onV2]['reply']['thumb'], 'v2 не приховане');

            // JSON (як Http::json): сирий текст переживає кодування без змін
            $json = json_encode(['messages' => array_values($feed)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->assertNotFalse($json);
            $this->assertSame(trim($caption), json_decode($json, true)['messages'][0]['text']);
        } finally {
            putenv($oldRoot === false ? 'DESIGN_WORK_ROOT' : 'DESIGN_WORK_ROOT=' . $oldRoot);
            exec('rm -rf ' . escapeshellarg($root));
            $this->cleanupDb();
            CdnLinksGuard::cleanup($links);
            Notify::$sent = [];
        }
    }
}
