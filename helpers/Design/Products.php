<?php

namespace Design;

/** Продукти дизайну (design_products) і питання їхніх брифів */
final class Products
{
    /** @return list<array{id:int, name:string}> видимі, за pos */
    public static function visible(): array
    {
        $rows = Sql::all('SELECT id, name FROM design_products WHERE shw = 1 ORDER BY pos, id');
        return array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name']], $rows);
    }

    /** @return array{id:int, name:string, questions:list<array{q:string, hint:string}>}|null прихований або неіснуючий — null */
    public static function get(int $id): ?array
    {
        $r = Sql::one('SELECT id, name, brief FROM design_products WHERE id = ? AND shw = 1', [$id]);
        if ($r === null) return null;
        return ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'questions' => self::questions($r['brief'])];
    }

    /** @return list<array{q:string, hint:string}> елементи без «q» пропускаються, битий JSON — порожній список */
    public static function questions(?string $json): array
    {
        $items = json_decode((string)$json, true);
        if (!is_array($items)) return [];
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it) || !isset($it['q']) || !is_string($it['q']) || trim($it['q']) === '') continue;
            $out[] = ['q' => $it['q'], 'hint' => is_string($it['hint'] ?? null) ? $it['hint'] : ''];
        }
        return $out;
    }
}
