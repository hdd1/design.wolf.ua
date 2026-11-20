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
    private const ERR_BASED_ON = "Версію, на основі якої зроблено прев'ю, не знайдено";
    /** Дерево симлінків i.fastcdn (ImageGlobal::$symlinkRoot) */
    private const CDN_LINKS = '/home/connect/cloud-links/ci/';

    /**
     * Тести: викликається перед рендером (поза транзакцією) — щоб перевірити, що замок замовлення в цей час вільний
     * @internal
     * @var (\Closure(int): void)|null
     */
    public static ?\Closure $beforeRender = null;

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
     * Нова версія прев'ю з файлу $tmp (склеєне завантаження).
     * 1) Без замка: доступ (canWork), «на основі», підпис — дешева відмова до рендеру.
     * 2) Рендер (PreviewImages::make, PDF — до ~40 с) поза транзакцією — у тимчасові імена tmp-<16 hex>-p{n}-*.webp у папці замовлення.
     * 3) Коротка транзакція з замком рядка замовлення: повторна перевірка доступу й «на основі», num = MAX + 1, рядок прев'ю,
     *    повідомлення дизайнера (text = підпис, може бути порожнім; preview_id), перейменування файлів у {id}-p{n}-*.webp, коммит.
     * Будь-яка помилка — відкат і видалення і тимчасових, і вже перейменованих файлів. $tmp видаляється в будь-якому разі.
     * Сповіщення клієнту тут не шлеться: notice — що сповістити (Workspace::notifyClient) після відповіді браузеру.
     * basedOn — id неприхованої версії цього ж замовлення
     * @return array{id:int, num:int, message_id:int, notice:array}
     * @throws DesignerException доступ, підпис, «на основі», файл (текст для користувача)
     */
    public static function add(int $orderId, int $designerId, string $tmp, string $caption, ?int $basedOn): array
    {
        $base = 'tmp-' . bin2hex(random_bytes(8));
        $dir = '';
        $id = 0;
        try {
            $caption = Messages::caption($caption);
            self::check($orderId, $designerId, $basedOn);

            $dir = Paths::previews($orderId);
            if (self::$beforeRender !== null) (self::$beforeRender)($orderId);
            $made = PreviewImages::make($tmp, $dir, $base);

            $pdo = Sql::pdo();
            $pdo->beginTransaction();
            try {
                Sql::one('SELECT id FROM design_orders WHERE id = ? FOR UPDATE', [$orderId]);
                $offer = self::check($orderId, $designerId, $basedOn);
                $num = (int)Sql::value('SELECT COALESCE(MAX(num), 0) + 1 FROM design_previews WHERE order_id = ?', [$orderId]);
                $id = Sql::insert('design_previews', [
                    'order_id' => $orderId,
                    'offer_id' => $offer['id'],
                    'num' => $num,
                    'based_on_id' => $basedOn,
                    'pages' => $made['pages'],
                    'source_type' => $made['type'],
                ]);
                $messageId = Messages::preview($offer['id'], $designerId, $id, $caption);
                Sql::exec('UPDATE design_previews SET message_id = ? WHERE id = ?', [$messageId, $id]);
                for ($n = 1; $n <= $made['pages']; $n++) {
                    foreach (['view', 'card'] as $size) {
                        if (!@rename("$dir/$base-p$n-$size.webp", "$dir/$id-p$n-$size.webp")) {
                            throw new \RuntimeException("Не вдалося перейменувати картинку прев'ю $base-p$n-$size → $id");
                        }
                    }
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($id > 0) self::removeFiles($dir, $id);
                throw $e;
            }
        } catch (\Throwable $e) {
            if ($dir !== '') self::removeFiles($dir, $base);
            throw $e;
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }

        return ['id' => $id, 'num' => $num, 'message_id' => $messageId, 'notice' => Workspace::notice($offer, $designerId)];
    }

    /**
     * Доступ і «на основі» для add: обрана пропозиція або DesignerException
     * @return array пропозиція у вигляді Offers::get
     */
    private static function check(int $orderId, int $designerId, ?int $basedOn): array
    {
        $offer = self::canWork($orderId, $designerId);
        if ($offer === null) throw new DesignerException(self::ERR_CLOSED);
        if ($basedOn !== null && Sql::value('SELECT id FROM design_previews WHERE id = ? AND order_id = ? AND hidden_at IS NULL',
                [$basedOn, $orderId]) === null) {
            throw new DesignerException(self::ERR_BASED_ON);
        }
        return $offer;
    }

    /**
     * Приховати прев'ю (лише обраний дизайнер замовлення в роботі): hidden_at = NOW(), картинки видаляються з диска
     * разом із їхніми симлінками i.fastcdn (інакше биті посилання копились би в cloud-links), рядок і підпис лишаються.
     * Повтор нічого не змінює (лише добирає файли, якщо попередній раз їх не вдалося видалити).
     * Немає прев'ю і немає доступу — та сама відповідь (id не перебрати)
     * @throws DesignerException
     */
    public static function hide(int $previewId, int $designerId): void
    {
        $orderId = Sql::value('SELECT order_id FROM design_previews WHERE id = ?', [$previewId]);
        if ($orderId === null) throw new DesignerException(self::ERR_CLOSED);
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
        self::removeFiles(self::dir($orderId), $previewId, true);
    }

    /**
     * Прев'ю замовлення за номером, ключ — id. urls — по сторінці {view, card}: адреси i.fastcdn лише для наявних файлів
     * (немає файлу — ''); у прихованих — порожній список. based_on_num — номер версії «на основі», caption — текст повідомлення
     * @return array<int, array{id:int, num:int, pages:int, hidden:bool, based_on_num:?int, caption:string, urls:list<array{view:string, card:string}>}>
     */
    public static function forOrder(int $orderId): array
    {
        return self::load($orderId, null);
    }

    /**
     * Лише вказані прев'ю замовлення (стрічка листування: адреси будуються тільки для потрібних), у вигляді forOrder;
     * чужі й відсутні id пропускаються
     * @param list<int> $ids
     */
    public static function byIds(int $orderId, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        return $ids === [] ? [] : self::load($orderId, $ids);
    }

    /** Кількість неприхованих прев'ю замовлення (для «чий хід» дизайнера) */
    public static function visibleCount(int $orderId): int
    {
        return (int)Sql::value('SELECT COUNT(*) FROM design_previews WHERE order_id = ? AND hidden_at IS NULL', [$orderId]);
    }

    /**
     * id прихованих прев'ю замовлення: опитування листування несе їх, щоб у співрозмовника картки ховались без перезавантаження
     * @return list<int>
     */
    public static function hiddenIds(int $orderId): array
    {
        return array_map('intval', array_column(
            Sql::all('SELECT id FROM design_previews WHERE order_id = ? AND hidden_at IS NOT NULL ORDER BY id', [$orderId]), 'id'));
    }

    /** @param list<int>|null $ids null — усі прев'ю замовлення */
    private static function load(int $orderId, ?array $ids): array
    {
        $rows = Sql::all(
            'SELECT p.id, p.num, p.pages, p.hidden_at, b.num AS based_on_num, m.text AS caption
             FROM design_previews p
             LEFT JOIN design_previews b ON b.id = p.based_on_id
             LEFT JOIN design_messages m ON m.id = p.message_id
             WHERE p.order_id = ?' . ($ids === null ? '' : ' AND p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')
            . ' ORDER BY p.num',
            array_merge([$orderId], $ids ?? [])
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

    /**
     * Видаляє всі картинки прев'ю з основою імен $base (id або тимчасова tmp-…): {base}-p{n}-view.webp і -card.webp;
     * $links — і симлінки i.fastcdn на них (ті, що створив url → ImageGlobal::encode)
     */
    private static function removeFiles(string $dir, int|string $base, bool $links = false): void
    {
        foreach (glob("$dir/$base-p*-{view,card}.webp", GLOB_BRACE) ?: [] as $f) {
            if (!preg_match('~/' . preg_quote((string)$base, '~') . '-p\d+-(?:view|card)\.webp$~', $f)) continue;
            if ($links) self::unlinkCdn($f);
            @unlink($f);
        }
    }

    /**
     * Симлінк i.fastcdn на файл прев'ю $file: той самий шлях, що рахує ImageGlobal::encode (sha256 шляху з сіллю, шарди
     * xx/yy/zz, розширення). Видаляється, лише якщо це справді симлінк саме на $file — інших посилань не чіпаємо
     */
    private static function unlinkCdn(string $file): void
    {
        require_once '/home/connect/cdn.wolf.ua/helpers/ImageGlobal.php';
        $hash = hash('sha256', $file . \CdnConfig::get('image_link_salt'));
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $link = self::CDN_LINKS . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . substr($hash, 4, 2) . '/'
            . substr($hash, 6) . ($ext === '' ? '' : '.' . $ext);
        if (is_link($link) && readlink($link) === $file && !@unlink($link)) {
            error_log("design: не вдалося видалити посилання i.fastcdn $link");
        }
    }
}
