<?php

namespace Design;

/** Статуси пропозиції дизайнера (design_offers.status) */
final class OfferStatus
{
    public const INVITED = 1;       // запрошено (режим 2)
    public const DECLINED = 2;      // дизайнер відмовився
    public const SENT = 3;          // надіслано
    public const CHOSEN = 4;        // обрано клієнтом
    public const OTHER_CHOSEN = 5;  // клієнт обрав іншого
    public const WITHDRAWN = 6;     // відкликано дизайнером

    private const LABELS = [
        self::INVITED => 'Запрошено',
        self::DECLINED => 'Відмовився',
        self::SENT => 'Надіслано',
        self::CHOSEN => 'Обрано',
        self::OTHER_CHOSEN => 'Обрано іншого',
        self::WITHDRAWN => 'Відкликано',
    ];

    public static function label(int $s): string
    {
        return self::LABELS[$s] ?? '';
    }
}
