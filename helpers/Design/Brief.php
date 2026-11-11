<?php

namespace Design;

/** Знімок брифу: питання разом із відповідями, щоб старі брифи читалися після правок питань */
final class Brief
{
    public const MAX_ANSWER = 5000;

    /**
     * @param list<array{q:string}> $questions
     * @param array<int, mixed> $answers у тому ж порядку, що й питання
     * @return list<array{q:string, a:string}>
     */
    public static function snapshot(array $questions, array $answers): array
    {
        $answers = array_values($answers);
        if (count($answers) !== count($questions)) {
            throw new BriefException('Форма застаріла, оновіть сторінку');
        }
        $out = [];
        $filled = false;
        foreach (array_values($questions) as $i => $q) {
            $a = trim(is_string($answers[$i]) ? $answers[$i] : '');
            if (mb_strlen($a) > self::MAX_ANSWER) throw new BriefException('Відповідь занадто довга');
            if ($a !== '') $filled = true;
            $out[] = ['q' => (string)$q['q'], 'a' => $a];
        }
        if (!$filled) throw new BriefException('Заповніть хоча б одну відповідь');
        return $out;
    }
}
