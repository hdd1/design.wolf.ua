<?php

namespace Design;

/** Сторінки брифу: поля форми і рендер кроку 2 (новий бриф і чернетка однаково) */
final class BriefPage
{
    /**
     * Поля форми: питання з продукту + відповіді.
     * @param list<array{q:string,note:string,hint:string}> $questions
     * @return list<array{q:string,note:string,hint:string,a:string}>
     */
    public static function fields(array $questions, array $answers): array
    {
        $answers = array_values($answers);
        $out = [];
        foreach (array_values($questions) as $i => $q) {
            $out[] = $q + ['a' => is_string($answers[$i] ?? null) ? $answers[$i] : ''];
        }
        return $out;
    }

    /**
     * Поля чернетки: питання зі знімка; підказки — з продукту, якщо питання не змінилося.
     * @param list<array{q:string,a:string}> $snapshot
     */
    public static function fromSnapshot(array $snapshot, ?array $product): array
    {
        $byQ = [];
        foreach ($product['questions'] ?? [] as $q) $byQ[$q['q']] = $q;
        return array_map(static fn(array $p): array => [
            'q' => $p['q'],
            'note' => $byQ[$p['q']]['note'] ?? '',
            'hint' => $byQ[$p['q']]['hint'] ?? '',
            'a' => $p['a'],
        ], $snapshot);
    }

    /** Відповіді й назва з POST */
    public static function posted(): array
    {
        $answers = is_array($_POST['answers'] ?? null) ? array_values($_POST['answers']) : [];
        $answers = array_map(static fn($a): string => is_string($a) ? $a : '', $answers);
        return [$answers, is_string($_POST['title'] ?? null) ? $_POST['title'] : ''];
    }

    public static function render(array $vars, ?string $toast = null): void
    {
        View::render('layout', [
            'title' => 'Бриф · ' . $vars['productName'],
            'active' => 'brief',
            'toast' => $toast,
            'body' => View::fetch('brief-form', $vars + ['error' => null, 'savedAt' => null]),
        ]);
    }
}
