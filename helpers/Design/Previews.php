<?php

namespace Design;

/**
 * Прев'ю замовлення (design_previews): версії vN з водяним знаком, які обраний дизайнер надсилає в листування обраної пропозиції.
 * Прев'ю = повідомлення дизайнера з preview_id (текст — підпис). Картинки — Paths::previews({orderId})/{id}-p{n}-view|card.webp,
 * віддає їх i.fastcdn (ImageGlobal::encode). Приховане прев'ю: рядок і підпис лишаються, файли видалено з диска.
 * Замок у транзакціях — рядок замовлення (як в Offers)
 */
final class Previews
{
    private const ERR_CLOSED = "Додавати й приховувати прев'ю може лише обраний дизайнер замовлення в роботі";
    private const ERR_NOT_FOUND = "Прев'ю не знайдено";
    private const ERR_BASED_ON = "Версію, на основі якої зроблено прев'ю, не знайдено";

    /**
     * Обрана пропозиція, якщо дизайнер може працювати з прев'ю замовлення: замовлення «В роботі», його chosen_offer_id —
     * пропозиція цього дизайнера в статусі «обрано», дизайнер схвалений. Інакше null
     * @return array|null пропозиція у вигляді Offers::get
     */
    public static function canWork(int $orderId, int $designerId): ?array
    {
        $chosen = Sql::value('SELECT chosen_offer_id FROM design_orders WHERE id = ? AND status = ?', [$orderId, OrderStatus::IN_WORK]);
        if ($chosen === null) return null;
        $offer = Offers::get((int)$chosen);
        if ($offer === null || $offer['designer_id'] !== $designerId || $offer['status'] !== OfferStatus::CHOSEN
            || $offer['order_status'] !== OrderStatus::IN_WORK) {
            return null;
        }
        return Designers::status($designerId) === DesignerStatus::APPROVED ? $offer : null;
    }

    /**
     * Нова версія прев'ю з файлу $tmp (склеєне завантаження). Одна транзакція з замком рядка замовлення:
     * num = MAX + 1, рядок прев'ю, повідомлення дизайнера (text = підпис, може бути порожнім; preview_id), картинки
     * (PreviewImages::make — у тій самій транзакції: рендер PDF тримає замок замовлення до ~40 с, дизайнер у замовленні один).
     * Будь-яка помилка — відкат і видалення картинок цього прев'ю. Після коммиту — сповіщення клієнту (правило «перше непрочитане»).
     * $tmp видаляється в будь-якому разі. basedOn — id неприхованої версії цього ж замовлення
     * @return array{id:int, num:int, message_id:int}
     * @throws DesignerException доступ, підпис, «на основі», файл (текст для користувача)
     */
    public static function add(int $orderId, int $designerId, string $tmp, string $caption, ?int $basedOn): array
    {
        try {
            $pdo = Sql::pdo();
            $id = 0;
            $dir = '';
            $pdo->beginTransaction();
            try {
                Sql::one('SELECT id FROM design_orders WHERE id = ? FOR UPDATE', [$orderId]);
                $offer = self::canWork($orderId, $designerId);
                if ($offer === null) throw new DesignerException(self::ERR_CLOSED);
                if ($basedOn !== null && Sql::value('SELECT id FROM design_previews WHERE id = ? AND order_id = ? AND hidden_at IS NULL',
                        [$basedOn, $orderId]) === null) {
                    throw new DesignerException(self::ERR_BASED_ON);
                }
                $num = (int)Sql::value('SELECT COALESCE(MAX(num), 0) + 1 FROM design_previews WHERE order_id = ?', [$orderId]);
                $id = Sql::insert('design_previews', [
                    'order_id' => $orderId,
                    'offer_id' => $offer['id'],
                    'num' => $num,
                    'based_on_id' => $basedOn,
                ]);
                $messageId = Messages::preview($offer['id'], $designerId, $id, $caption);
                Sql::exec('UPDATE design_previews SET message_id = ? WHERE id = ?', [$messageId, $id]);
                $dir = Paths::previews($orderId);
                $made = PreviewImages::make($tmp, $dir, $id);
                Sql::exec('UPDATE design_previews SET pages = ?, source_type = ? WHERE id = ?', [$made['pages'], $made['type'], $id]);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($id > 0 && $dir !== '') self::removeFiles($dir, $id);
                throw $e;
            }
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }

        // Сповіщення — після запису: його збій не скасовує вже надіслане прев'ю
        try {
            Messages::notifyNew($offer['id'], Messages::ROLE_CLIENT, $offer['customer_id'],
                Designers::get($designerId)['name'] ?? 'дизайнера', $orderId);
        } catch (\Throwable $e) {
            error_log("design: сповіщення про прев'ю $id замовлення $orderId: " . $e);
        }
        return ['id' => $id, 'num' => $num, 'message_id' => $messageId];
    }

    /**
     * Приховати прев'ю (лише обраний дизайнер замовлення в роботі): hidden_at = NOW(), картинки видаляються з диска,
     * рядок і підпис лишаються. Повтор нічого не змінює (лише добирає файли, якщо попередній раз їх не вдалося видалити)
     * @throws DesignerException прев'ю не знайдено або дизайнер не може з ним працювати
     */
    public static function hide(int $previewId, int $designerId): void
    {
        $orderId = Sql::value('SELECT order_id FROM design_previews WHERE id = ?', [$previewId]);
        if ($orderId === null) throw new DesignerException(self::ERR_NOT_FOUND);
        $orderId = (int)$orderId;

        $pdo = Sql::pdo();
        $pdo->beginTransaction();
        try {
            Sql::one('SELECT id FROM design_orders WHERE id = ? FOR UPDATE', [$orderId]);
            if (self::canWork($orderId, $designerId) === null) throw new DesignerException(self::ERR_CLOSED);
            Sql::exec('UPDATE design_previews SET hidden_at = NOW() WHERE id = ? AND hidden_at IS NULL', [$previewId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        // Файли — після коммиту: якщо коммит не вдався, прев'ю лишається видимим і з картинками
        self::removeFiles(self::dir($orderId), $previewId);
    }

    /**
     * Прев'ю замовлення за номером, ключ — id. urls — по сторінці {view, card}: адреси i.fastcdn лише для наявних файлів
     * (немає файлу — ''); у прихованих — порожній список. based_on_num — номер версії «на основі», caption — текст повідомлення
     * @return array<int, array{id:int, num:int, pages:int, hidden:bool, based_on_num:?int, caption:string, urls:list<array{view:string, card:string}>}>
     */
    public static function forOrder(int $orderId): array
    {
        $rows = Sql::all(
            'SELECT p.id, p.num, p.pages, p.hidden_at, b.num AS based_on_num, m.text AS caption
             FROM design_previews p
             LEFT JOIN design_previews b ON b.id = p.based_on_id
             LEFT JOIN design_messages m ON m.id = p.message_id
             WHERE p.order_id = ? ORDER BY p.num',
            [$orderId]
        );
        $dir = self::dir($orderId);
        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $hidden = $r['hidden_at'] !== null;
            $urls = [];
            for ($n = 1; !$hidden && $n <= (int)$r['pages']; $n++) {
                $urls[] = ['view' => self::url("$dir/$id-p$n-view.webp"), 'card' => self::url("$dir/$id-p$n-card.webp")];
            }
            $out[$id] = [
                'id' => $id,
                'num' => (int)$r['num'],
                'pages' => (int)$r['pages'],
                'hidden' => $hidden,
                'based_on_num' => $r['based_on_num'] === null ? null : (int)$r['based_on_num'],
                'caption' => (string)$r['caption'],
                'urls' => $urls,
            ];
        }
        return $out;
    }

    /** Папка прев'ю замовлення без створення: читання й прибирання не повинні залишати слідів */
    private static function dir(int $orderId): string
    {
        return Paths::root() . '/previews/' . $orderId;
    }

    /** Адреса i.fastcdn для наявного WebP прев'ю; немає файлу — '' (encode підставив би чужу заглушку) */
    private static function url(string $file): string
    {
        if (!is_file($file)) return '';
        require_once '/home/connect/cdn.wolf.ua/helpers/ImageGlobal.php';
        return \ImageGlobal::encode($file);
    }

    /** Видаляє всі картинки прев'ю: {id}-p{n}-view.webp і -card.webp */
    private static function removeFiles(string $dir, int $previewId): void
    {
        foreach (glob("$dir/$previewId-p*-{view,card}.webp", GLOB_BRACE) ?: [] as $f) {
            if (preg_match('~/' . $previewId . '-p\d+-(?:view|card)\.webp$~', $f)) @unlink($f);
        }
    }
}
