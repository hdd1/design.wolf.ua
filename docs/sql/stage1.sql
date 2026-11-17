-- Майстерня дизайну (design.wolf.ua), етап 1: налаштування, продукти дизайну, замовлення (бриф).
-- Запускає власник на dev і на проді (база w2p). Повторний запуск безпечний.

CREATE TABLE IF NOT EXISTS `design_settings` (
  `key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Майстерня дизайну: налаштування, які змінюють руками';

CREATE TABLE IF NOT EXISTS `design_products` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `brief` json NOT NULL COMMENT 'Питання брифу: [{"q": "питання", "hint": "приклад відповіді"}]',
  `pos` int NOT NULL DEFAULT '0' COMMENT 'Порядок; «Інше» — найбільший',
  `shw` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `idx_shw_pos` (`shw`, `pos`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Майстерня дизайну: продукти дизайну (не повʼязані з n_products)';

CREATE TABLE IF NOT EXISTS `design_orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL COMMENT 'customers.id — клієнт',
  `product_id` int unsigned NOT NULL COMMENT 'design_products.id',
  `mode` tinyint NOT NULL DEFAULT '1' COMMENT '1 збирати пропозиції, 2 одразу обрати дизайнера',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1 чернетка, 2 збір пропозицій, 3 очікує відповіді дизайнера, 4 в роботі, 5 очікує оплати рахунку, 6 куплено, 7 доопрацювання, 8 скасовано',
  `answers` json NOT NULL COMMENT 'Знімок брифу: [{"q": "питання", "a": "відповідь"}]',
  `chosen_offer_id` int unsigned DEFAULT NULL COMMENT 'design_offers.id — обрана пропозиція (етап 3)',
  `paid_client` int NOT NULL DEFAULT '0' COMMENT 'Вже сплачено клієнтом, грн (етап 5)',
  `paid_designer` int NOT NULL DEFAULT '0' COMMENT 'Вже нараховано дизайнеру, грн (етап 5)',
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `published_at` datetime DEFAULT NULL,
  `chosen_at` datetime DEFAULT NULL,
  `purchased_at` datetime DEFAULT NULL,
  `notified_no_offers_at` datetime DEFAULT NULL COMMENT 'Куратору надіслано «немає пропозицій»',
  `notified_deadline_at` datetime DEFAULT NULL COMMENT 'Куратору надіслано «термін минув»',
  PRIMARY KEY (`id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Майстерня дизайну: замовлення (бриф і замовлення — одна сутність)';

INSERT IGNORE INTO `design_settings` (`key`, `value`) VALUES ('no_offers_hours', '24');

-- Продукти: тексти питань — чернетка, власник поправить. Вставляються тільки в порожню таблицю.
INSERT INTO `design_products` (`name`, `brief`, `pos`, `shw`)
SELECT * FROM (
  SELECT 'Візитка' AS name, '[{"q":"Розмір","hint":"90×50 мм"},{"q":"Скільки сторін","hint":"дві"},{"q":"Скільки варіантів (людей)","hint":"3 візитки на різних співробітників"},{"q":"Текст","hint":"ПІБ, посада, телефон, email, адреса"},{"q":"Побажання до стилю, посилання на матеріали","hint":"логотип тут: https://drive.wolf.ua/…"}]' AS brief, 10 AS pos, 1 AS shw
  UNION ALL SELECT 'Флаєр', '[{"q":"Розмір","hint":"А5"},{"q":"Скільки сторін","hint":"одна"},{"q":"Текст і зображення","hint":"заголовок, акція, контакти; фото товару за посиланням"},{"q":"Побажання до стилю, посилання на матеріали","hint":"яскраво, у кольорах логотипу"}]', 20, 1
  UNION ALL SELECT 'Буклет', '[{"q":"Розмір у розгорнутому вигляді","hint":"А4"},{"q":"Скільки згинів і як","hint":"2 згини, євробуклет"},{"q":"Текст і зображення","hint":"текст по розділах, фото за посиланням"},{"q":"Побажання до стилю, посилання на матеріали","hint":"строго, як на нашому сайті"}]', 30, 1
  UNION ALL SELECT 'Брошура на скобі', '[{"q":"Формат","hint":"А5"},{"q":"Кількість сторінок","hint":"16"},{"q":"Матеріали","hint":"текст і фото за посиланням"},{"q":"Побажання до стилю, посилання на матеріали","hint":"каталог продукції, багато фото"}]', 40, 1
  UNION ALL SELECT 'Логотип', '[{"q":"Назва і чим займаєтесь","hint":"кавʼярня «Ранок», кава з собою"},{"q":"Що подобається / не подобається","hint":"подобається мінімалізм, не подобаються градієнти"},{"q":"Де використовуватиметься","hint":"вивіска, стакани, Instagram"},{"q":"Посилання на приклади","hint":"https://…"}]', 50, 1
  UNION ALL SELECT 'Інше', '[{"q":"Опишіть задачу","hint":"що потрібно зробити, розміри, кількість, посилання на матеріали"}]', 1000, 1
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `design_products`);
