<?php

use Design\Http;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    public function test_str_returns_strings_as_is(): void
    {
        $this->assertSame(' Олег ', Http::str(['name' => ' Олег '], 'name'));
        $this->assertSame('', Http::str(['name' => ''], 'name'));
    }

    /** name[]=x, відсутнє поле, null чи число з JSON — порожній рядок, без warning і без «Array» */
    public function test_str_non_string_is_empty(): void
    {
        $src = ['a' => ['x'], 'b' => [], 'n' => null, 'i' => 5];
        foreach (['a', 'b', 'n', 'i', 'missing'] as $key) $this->assertSame('', Http::str($src, $key), $key);
    }
}
