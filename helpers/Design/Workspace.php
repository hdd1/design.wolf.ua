<?php

namespace Design;

/**
 * Робочий простір обраної пропозиції (етап 4, макети 6.5): плашка «чий хід» для сторони, список макетів для панелі
 * «Макети для друку» і те, що несе опитування листування, щоб зміни доходили до співрозмовника без перезавантаження:
 * «чий хід», версія списку макетів (змінилась — панель перечитує GET /orders/{id}/layouts) і приховані прев'ю.
 * Адрес макетів тут немає й бути не може: до купівлі макети не віддаються
 */
final class Workspace
{
    /**
     * Хто дивиться робочий простір замовлення: клієнт замовлення або дизайнер обраної пропозиції (як Offers::access), інакше null
     * @return array{offer:array, role:int, write:bool}|null
     */
    public static function access(int $orderId, int $customerId): ?array
    {
        $chosen = Sql::value('SELECT chosen_offer_id FROM design_orders WHERE id = ?', [$orderId]);
        if ($chosen === null) return null;
        $access = Offers::access((int)$chosen, $customerId);
        return $access !== null && $access['offer']['status'] === OfferStatus::CHOSEN ? $access : null;
    }

    /**
     * Що сповістити клієнту після дії обраного дизайнера (прев'ю, макет): Previews::add і Layouts::add / replace / remove
     * самі не сповіщають, а повертають це — сповіщення через api2 (спільний CalcApiGlobal без тайм-ауту) може «висіти»,
     * тож сторінка шле його вже після відповіді браузеру (Http::respond → notifyClient)
     * @param array $offer обрана пропозиція (Offers::get)
     * @return array{offer_id:int, customer_id:int, designer_id:int, order_id:int}
     */
    public static function notice(array $offer, int $designerId): array
    {
        return ['offer_id' => $offer['id'], 'customer_id' => $offer['customer_id'], 'designer_id' => $designerId,
            'order_id' => $offer['order_id']];
    }

    /**
     * Сповістити клієнта за notice() — правило «перше непрочитане» (Messages::notifyNew). Викликати після коммиту дії;
     * збій — лише в журнал: дію вже виконано
     * @param array{offer_id:int, customer_id:int, designer_id:int, order_id:int} $notice
     */
    public static function notifyClient(array $notice): void
    {
        try {
            Messages::notifyNew($notice['offer_id'], Messages::ROLE_CLIENT, $notice['customer_id'],
                Designers::get($notice['designer_id'])['name'] ?? 'дизайнера', $notice['order_id']);
        } catch (\Throwable $e) {
            error_log("design: сповіщення клієнту замовлення {$notice['order_id']}: " . $e);
        }
    }

    /**
     * Плашка «чий хід» над стрічкою для сторони role (Messages::ROLE_CLIENT / ROLE_DESIGNER) обраної пропозиції offer (Offers::get).
     * Клієнт — OrderStatus::turn з фактичною кількістю макетів; дизайнер — OrderStatus::designerTurn
     * @return array{mine:bool, text:string, sub:string, icon:string}
     */
    public static function turn(array $offer, int $role): array
    {
        $orderId = $offer['order_id'];
        $layouts = Layouts::count($orderId);
        if ($role === Messages::ROLE_DESIGNER) {
            [$client, $designer] = Messages::lastIds($offer['id']);
            $t = OrderStatus::designerTurn($client, $designer, Previews::visibleCount($orderId), $layouts);
            return $t + ['sub' => '', 'icon' => $t['mine'] ? 'fa-hand-paper' : 'fa-hourglass-half'];
        }
        $t = OrderStatus::turn($offer['order_status'], 0, $layouts);
        $sub = '';
        if ($offer['order_status'] === OrderStatus::IN_WORK) {
            $sub = $layouts > 0
                ? 'Дизайнер завантажив ' . View::plural($layouts, 'макет', 'макети', 'макетів') . '. Файли відкриються після покупки'
                : ($offer['deadline'] ? 'Термін — до ' . View::date($offer['deadline']) . '. ' : '') . 'Пишіть у чат, якщо щось змінилось';
        }
        $done = $t['mine'] || ($offer['order_status'] === OrderStatus::IN_WORK && $layouts > 0);
        return $t + ['sub' => $sub, 'icon' => $done ? 'fa-file-check' : 'fa-pencil-ruler'];
    }

    /**
     * Активні макети замовлення для панелі: підпис, ім'я файла, розмір (size_text — «2,4 МБ»), розширення; версія списку
     * @return array{layouts:list<array{id:int, label:string, original_name:string, size:int, size_text:string, ext:string}>, count:int, rev:string}
     */
    public static function layouts(int $orderId): array
    {
        $list = Layouts::forOrder($orderId);
        return [
            'layouts' => array_map(static fn(array $l): array => [
                'id' => $l['id'],
                'label' => $l['label'],
                'original_name' => $l['original_name'],
                'size' => $l['size'],
                'size_text' => View::bytes($l['size']),
                'ext' => $l['ext'],
            ], $list),
            'count' => count($list),
            'rev' => Layouts::rev($orderId, $list),
        ];
    }

    /**
     * Додаток до відповіді опитування листування обраної пропозиції
     * @return array{turn:array, layouts_rev:string, hidden_previews:list<int>}
     */
    public static function poll(array $offer, int $role): array
    {
        return [
            'turn' => self::turn($offer, $role),
            'layouts_rev' => Layouts::rev($offer['order_id']),
            'hidden_previews' => Previews::hiddenIds($offer['order_id']),
        ];
    }
}
