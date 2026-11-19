<?php

use Design\OfferStatus as S;
use PHPUnit\Framework\TestCase;

final class OfferStatusTest extends TestCase
{
    public function test_labels(): void
    {
        $this->assertSame([1, 2, 3, 4, 5, 6], [S::INVITED, S::DECLINED, S::SENT, S::CHOSEN, S::OTHER_CHOSEN, S::WITHDRAWN]);
        $this->assertSame('Запрошено', S::label(S::INVITED));
        $this->assertSame('Відмовився', S::label(S::DECLINED));
        $this->assertSame('Надіслано', S::label(S::SENT));
        $this->assertSame('Обрано', S::label(S::CHOSEN));
        $this->assertSame('Обрано іншого', S::label(S::OTHER_CHOSEN));
        $this->assertSame('Відкликано', S::label(S::WITHDRAWN));
        $this->assertSame('', S::label(99));
    }
}
