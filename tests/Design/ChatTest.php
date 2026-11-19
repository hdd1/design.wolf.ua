<?php

use Design\Chat;
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
}
