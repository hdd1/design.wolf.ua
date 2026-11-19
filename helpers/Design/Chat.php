<?php

namespace Design;

/** Листування для того, хто дивиться: повідомлення у вигляді для JSON і першого рендеру, текст з посиланнями */
final class Chat
{
    /** Скільки повідомлень віддає одне опитування */
    public const POLL_LIMIT = 100;
    /** Скільки символів тексту показує цитата відповіді */
    public const QUOTE_LEN = 120;

    /** http(s)-адреса до пробілу, лапок чи кутових дужок; розділові знаки в кінці — не частина адреси */
    private const URL = '~https?://[^\s<>"\']+~iu';
    private const URL_TAIL = '~[.,;:!?)\]}»…]+$~u';

    /**
     * Повідомлення пропозиції так, як їх бачить сторона з Offers::access.
     * author — від клієнта: коротке імʼя «Тарас Г.»; від дизайнера: design_designers.name; системні — ''. mine — відносно глядача.
     * preview — у повідомлення, яким надіслано прев'ю: елемент Previews::forOrder (text — його підпис); інакше null.
     * reply — у відповіді: цитата {id, author, text до QUOTE_LEN символів, preview_num — vN, якщо цитується прев'ю,
     * thumb — мініатюра першої сторінки неприхованого прев'ю}; інакше null. Тексти — як є: екранує той, хто виводить
     * @param array{offer:array, role:int, write:bool} $access
     * @return list<array{id:int, mine:bool, system:bool, author:string, text:string, time:string, preview:?array, reply:?array{id:int, author:string, text:string, preview_num:?int, thumb:?string}}> time — 'Y-m-d H:i'
     */
    public static function messages(array $access, int $after = 0, int $limit = 0): array
    {
        $offer = $access['offer'];
        $names = [];
        $name = static function (int $role) use ($offer, &$names): string {
            if ($role === Messages::ROLE_SYSTEM) return '';
            return $names[$role] ??= $role === Messages::ROLE_CLIENT
                ? (Customers::header($offer['customer_id'])['short'] ?? 'Клієнт')
                : (Designers::get($offer['designer_id'])['name'] ?? 'Дизайнер');
        };
        $list = Messages::list($offer['id'], $after, $limit);
        $quoted = Messages::byIds($offer['id'], array_filter(array_column($list, 'reply_to_id')));
        // Прев'ю замовлення (з адресами картинок) — лише якщо вони потрібні цій порції
        $previews = array_filter(array_column(array_merge($list, $quoted), 'preview_id')) === []
            ? [] : Previews::forOrder($offer['order_id']);

        return array_map(static function (array $m) use ($access, $name, $quoted, $previews): array {
            $q = $m['reply_to_id'] === null ? null : ($quoted[$m['reply_to_id']] ?? null);
            $qPreview = $q === null || $q['preview_id'] === null ? null : ($previews[$q['preview_id']] ?? null);
            return [
                'id' => $m['id'],
                'mine' => $m['role'] === $access['role'],
                'system' => $m['role'] === Messages::ROLE_SYSTEM,
                'author' => $name($m['role']),
                'text' => $m['text'],
                'time' => substr($m['created'], 0, 16),
                'preview' => $m['preview_id'] === null ? null : ($previews[$m['preview_id']] ?? null),
                'reply' => $q === null ? null : [
                    'id' => $q['id'],
                    'author' => $name($q['role']),
                    'text' => self::quote($q['text']),
                    'preview_num' => $qPreview['num'] ?? null,
                    'thumb' => ($qPreview['urls'][0]['card'] ?? '') ?: null,
                ],
            ];
        }, $list);
    }

    /** Текст цитати: не довший за QUOTE_LEN символів (обрізаний — з «…» в кінці) */
    private static function quote(string $text): string
    {
        return mb_strlen($text) <= self::QUOTE_LEN ? $text : rtrim(mb_substr($text, 0, self::QUOTE_LEN - 1)) . '…';
    }

    /** Текст повідомлення в HTML: усе екрановано, http(s)-адреси — посилання в новій вкладці з rel="noopener nofollow ugc" */
    public static function html(string $text): string
    {
        $out = '';
        $pos = 0;
        preg_match_all(self::URL, $text, $mm, PREG_OFFSET_CAPTURE);
        foreach ($mm[0] as [$url, $at]) {
            $url = preg_replace(self::URL_TAIL, '', $url);
            if (!preg_match('~^https?://.~i', $url)) continue;
            $href = View::e($url);
            $out .= View::e(substr($text, $pos, $at - $pos))
                . '<a href="' . $href . '" target="_blank" rel="noopener nofollow ugc">' . $href . '</a>';
            $pos = $at + strlen($url);
        }
        return $out . View::e(substr($text, $pos));
    }

    /** Розділювач дня: «Сьогодні» або «18 листопада» */
    public static function day(string $time): string
    {
        return substr($time, 0, 10) === date('Y-m-d') ? 'Сьогодні' : View::date($time);
    }

    /** Ініціали для кружечка: перші літери двох слів імені */
    public static function initials(string $name): string
    {
        $words = array_slice(preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY), 0, 2);
        return implode('', array_map(static fn(string $w): string => mb_strtoupper(mb_substr($w, 0, 1)), $words));
    }
}
