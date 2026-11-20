<?php

namespace Design;

/**
 * Робочий простір обраної пропозиції (етап 4, макети 6.5): плашка «чий хід» для сторони, список макетів для панелі
 * «Макети для друку» і те, що несе опитування листування, щоб зміни доходили до співрозмовника без перезавантаження:
 * «чий хід», версія списку макетів (змінилась — панель перечитує GET /orders/{id}/layouts) і приховані прев'ю.
 * Адреса макета («Завантажити», i.fastcdn cf) і стан копії в хмару — лише купленому й лише клієнту (layouts($orderId, true));
 * некупленим — ніде
 */
final class Workspace
{
    /** Статуси замовлення, у яких сторінки показують робочий простір (після купівлі — теж: куплені макети, листування) */
    public const STATUSES = [OrderStatus::IN_WORK, OrderStatus::PURCHASED, OrderStatus::REWORK];

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
     * Клієнт — OrderStatus::turn, дизайнер — OrderStatus::designerTurn; «В роботі» і «Доопрацювання» — з кількістю нових (ще не
     * куплених) макетів, «Куплено» — з пропозицією доопрацювання без відповіді (Rework::pending: сума доплати й дата в sub).
     * $order — Rework::order (немає — читається тут)
     * @return array{mine:bool, text:string, sub:string, icon:string}
     */
    public static function turn(array $offer, int $role, ?array $order = null): array
    {
        $orderId = $offer['order_id'];
        $status = $offer['order_status'];
        $order ??= Rework::order($orderId);
        $pending = $order !== null && $order['status'] === $status ? Rework::pending($order) : null;
        $layouts = in_array($status, [OrderStatus::IN_WORK, OrderStatus::REWORK], true) ? Layouts::countNew($orderId) : 0;
        $until = $pending !== null ? View::date($pending['deadline']) : '';
        if ($role === Messages::ROLE_DESIGNER) {
            [$client, $designer] = Messages::lastIds($offer['id']);
            $t = OrderStatus::designerTurn($client, $designer, Previews::visibleCount($orderId), $layouts, $status, $pending !== null);
            $sub = $pending !== null && !$t['mine']
                ? 'Ви запропонували доопрацювання: ' . View::price($pending['price_designer']) . ' на руки, до ' . $until
                    . ($pending['extra'] > 0 ? ' · клієнт доплатить +' . View::price($pending['extra']) : ' · для клієнта без доплати')
                : '';
            $icon = match (true) {
                $t['mine'] => 'fa-hand-paper',
                $status === OrderStatus::PURCHASED && $pending === null => 'fa-check-circle',
                default => 'fa-hourglass-half',
            };
            return $t + ['sub' => $sub, 'icon' => $icon];
        }
        $t = OrderStatus::turn($status, 0, $layouts, $pending !== null);
        $sub = '';
        if ($status === OrderStatus::IN_WORK || $status === OrderStatus::REWORK) {
            $sub = $layouts > 0
                ? 'Дизайнер завантажив ' . View::plural($layouts, ($status === OrderStatus::REWORK ? 'новий ' : '') . 'макет',
                    ($status === OrderStatus::REWORK ? 'нові ' : '') . 'макети', ($status === OrderStatus::REWORK ? 'нових ' : '') . 'макетів')
                    . '. Файли відкриються після покупки'
                : ($offer['deadline'] ? 'Термін — до ' . View::date($offer['deadline']) . '. ' : '') . 'Пишіть у чат, якщо щось змінилось';
        } elseif ($pending !== null) {
            $sub = 'Дизайнер пропонує доопрацювання: ' . ($pending['extra'] > 0 ? '+' . View::price($pending['extra']) : 'без доплати')
                . ', до ' . date('d.m.Y', strtotime($pending['deadline']));
        } elseif ($status === OrderStatus::PURCHASED) {
            $sub = 'Файли — у панелі «Макети»: «Завантажити»';
        }
        $icon = match (true) {
            $pending !== null => 'fa-hand-paper',
            $status === OrderStatus::PURCHASED => 'fa-check-circle',
            $t['mine'] => 'fa-file-check',
            default => 'fa-pencil-ruler',
        };
        return $t + ['sub' => $sub, 'icon' => $icon];
    }

    /**
     * Доопрацювання для шапки робочого простору (views/partials/workspace.php): pending — пропозиція без відповіді (Rework::pending);
     * propose — дизайнер може натиснути «Ще не закінчили» (обраний схвалений дизайнер, «Куплено», пропозиції немає);
     * answer — клієнт може «Прийняти» / «Відмовитися» (token — Rework::token пропозиції для цих форм); cancel — клієнт може
     * «Скасувати доопрацювання»;
     * paid_* — уже сплачене / нараховане, k — націнка обраної пропозиції, round — крок округлення (форма і її жива підказка);
     * stage — стан для опитування (змінився — сторінка перечитується)
     * @return array{pending:?array, token:string, propose:bool, answer:bool, cancel:bool, paid_client:int, paid_designer:int, k:float, round:int, stage:string}
     */
    public static function rework(array $offer, int $role, ?array $order = null): array
    {
        $order ??= Rework::order($offer['order_id']);
        $status = $order['status'] ?? $offer['order_status'];
        $pending = $order !== null ? Rework::pending($order) : null;
        $designer = $role === Messages::ROLE_DESIGNER;
        return [
            'pending' => $pending,
            'token' => $order !== null ? Rework::token($order) : '',
            'propose' => $designer && $status === OrderStatus::PURCHASED && $pending === null
                && Designers::status($offer['designer_id']) === DesignerStatus::APPROVED,
            'answer' => !$designer && $pending !== null,
            'cancel' => !$designer && $status === OrderStatus::REWORK,
            'paid_client' => $order['paid_client'] ?? 0,
            'paid_designer' => $order['paid_designer'] ?? 0,
            'k' => $offer['markup_percent'] ?? 0.0,
            'round' => Settings::priceRound(),
            'stage' => self::stage($status, $pending !== null),
        ];
    }

    /** Стан замовлення для опитування: статус і «є пропозиція доопрацювання» («6», «6p», «7») */
    public static function stage(int $status, bool $pending): string
    {
        return $status . ($pending ? 'p' : '');
    }

    /**
     * Активні макети замовлення для панелі: підпис, ім'я файла, розмір (size_text — «2,4 МБ»), розширення, чи куплено; версія списку.
     * download — «Завантажити» (FileGlobal::encode: симлінк у cloud-links/cf і адреса i.fastcdn з іменем «Дизайн №N — {підпис}.{розш.}»)
     * лише для купленого макета і лише коли дивиться клієнт ($client); інакше '' і симлінк не створюється. cloud_status — стан
     * копії в хмару (CloudCopy::statuses) теж лише купленому й клієнту, інакше 0
     * @return array{layouts:list<array{id:int, label:string, original_name:string, size:int, size_text:string, ext:string, purchased:bool, download:string, cloud_status:int}>, count:int, rev:string}
     */
    public static function layouts(int $orderId, bool $client): array
    {
        $list = Layouts::forOrder($orderId);
        $cloud = $client ? CloudCopy::statuses($orderId) : [];
        return [
            'layouts' => array_map(static fn(array $l): array => [
                'id' => $l['id'],
                'label' => $l['label'],
                'original_name' => $l['original_name'],
                'size' => $l['size'],
                'size_text' => View::bytes($l['size']),
                'ext' => $l['ext'],
                'purchased' => $l['purchased'],
                'download' => $client && $l['purchased'] ? self::download($orderId, $l) : '',
                // «Скопіювати в хмару» — лише клієнту й лише купленим: 0 немає, 1 у черзі, 2 у хмарі, 3 помилка (CloudCopy)
                'cloud_status' => $client && $l['purchased'] ? ($cloud[$l['id']] ?? CloudCopy::NONE) : CloudCopy::NONE,
            ], $list),
            'count' => count($list),
            'rev' => Layouts::rev($orderId, $list),
        ];
    }

    /**
     * Адреса «Завантажити» купленого макета ('' — файла на диску немає): симлінк і адресу будує FileGlobal::encode, а ім'я
     * файла додаємо самі через rawurlencode. encode кодує name через http_build_query (пробіл → «+»), а nginx i.fastcdn ставить
     * його як є в filename*=UTF-8''… — браузер розкодовує там лише %XX, і файл зберігся б як «Дизайн+№12+—+…»
     */
    private static function download(int $orderId, array $layout): string
    {
        $url = \FileGlobal::encode(Layouts::path($orderId, $layout), '', true);
        if ($url === '') return '';
        $name = 'Дизайн №' . $orderId . ' — ' . $layout['label'] . ($layout['ext'] === '' ? '' : '.' . $layout['ext']);
        return $url . '&name=' . rawurlencode($name);
    }

    /**
     * Додаток до відповіді опитування листування обраної пропозиції; stage — стан замовлення (статус, пропозиція доопрацювання):
     * змінився — сторінка перечитується (кнопки доопрацювання, «Купую», завантаження макетів залежать від нього)
     * @return array{turn:array, stage:string, layouts_rev:string, hidden_previews:list<int>}
     */
    public static function poll(array $offer, int $role): array
    {
        $order = Rework::order($offer['order_id']);
        $status = $order['status'] ?? $offer['order_status'];
        return [
            'turn' => self::turn(['order_status' => $status] + $offer, $role, $order),
            'stage' => self::stage($status, $order !== null && Rework::pending($order) !== null),
            'layouts_rev' => Layouts::rev($offer['order_id']),
            'hidden_previews' => Previews::hiddenIds($offer['order_id']),
        ];
    }
}
