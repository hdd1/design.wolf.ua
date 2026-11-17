<?php

namespace Design;

/** Статуси дизайнера (design_designers.status) */
final class DesignerStatus
{
    public const DRAFT = 0;
    public const PENDING = 1;
    public const APPROVED = 2;
    public const REJECTED = 3;
    public const BLOCKED = 4;

    private const LABELS = [
        self::DRAFT => 'Чернетка',
        self::PENDING => 'На розгляді',
        self::APPROVED => 'Схвалено',
        self::REJECTED => 'Відхилено',
        self::BLOCKED => 'Заблоковано',
    ];

    public static function label(int $s): string
    {
        return self::LABELS[$s] ?? '';
    }
}
