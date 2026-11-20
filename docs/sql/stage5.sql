-- Майстерня дизайну (design.wolf.ua), етап 5: купівля — покупки, скарги, скасування, доопрацювання, копія в хмару.
-- Запускає власник на dev і на проді (база w2p). Повторний запуск безпечний.

CREATE TABLE IF NOT EXISTS `design_purchases` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL COMMENT 'design_orders.id',
  `offer_id` int unsigned NOT NULL COMMENT 'design_offers.id — обрана пропозиція',
  `payer_id` int NOT NULL COMMENT 'customers.id — хто платив (клієнт; субаккаунт платити не може)',
  `price_client` int NOT NULL DEFAULT '0' COMMENT 'P — повна ціна для клієнта на момент купівлі, грн',
  `price_designer` int NOT NULL DEFAULT '0' COMMENT 'D — повний гонорар дизайнера на момент купівлі, грн',
  `markup_percent` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT 'k — націнка на момент купівлі, %',
  `amount_client` int NOT NULL DEFAULT '0' COMMENT 'Списано з клієнта цією покупкою (P мінус вже сплачене), грн',
  `amount_designer` int NOT NULL DEFAULT '0' COMMENT 'Нараховано дизайнеру цією покупкою (D мінус вже нараховане), грн',
  `method` tinyint NOT NULL DEFAULT '1' COMMENT '1 баланс',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1 очікує, 2 проведена, 3 скасована',
  `transaction_client_id` int DEFAULT NULL COMMENT 'transaction.id — списання з клієнта (NULL для суми 0)',
  `transaction_designer_id` int DEFAULT NULL COMMENT 'transaction.id — нарахування дизайнеру (NULL для суми 0)',
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_at` datetime DEFAULT NULL COMMENT 'Коли проведена',
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Майстерня дизайну: покупки макетів (одна покупка — одна пара рухів по балансу)';

CREATE TABLE IF NOT EXISTS `design_complaints` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL COMMENT 'design_orders.id',
  `purchase_id` int unsigned NOT NULL COMMENT 'design_purchases.id — остання покупка замовлення',
  `text` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Скарга клієнта',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1 нова, 2 у роботі, 3 розглянута',
  `resolution` text COLLATE utf8mb4_unicode_ci COMMENT 'Рішення',
  `resolved_by` int DEFAULT NULL COMMENT 'users.id — хто розглянув',
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Майстерня дизайну: скарги після купівлі (розбирають вручну)';

-- design_orders: скасування і пропозиція доопрацювання.
-- ADD COLUMN не має IF NOT EXISTS у MySQL 8.0 — перевірка через information_schema, повторний запуск нічого не робить.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'design_orders' AND column_name = 'cancel_reason') = 0,
  "ALTER TABLE `design_orders` ADD COLUMN `cancel_reason` text COLLATE utf8mb4_unicode_ci COMMENT 'Причина скасування'",
  'SELECT 1');
PREPARE ddl_stmt FROM @ddl;
EXECUTE ddl_stmt;
DEALLOCATE PREPARE ddl_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'design_orders' AND column_name = 'cancelled_at') = 0,
  "ALTER TABLE `design_orders` ADD COLUMN `cancelled_at` datetime DEFAULT NULL COMMENT 'Коли скасовано'",
  'SELECT 1');
PREPARE ddl_stmt FROM @ddl;
EXECUTE ddl_stmt;
DEALLOCATE PREPARE ddl_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'design_orders' AND column_name = 'rework_price_designer') = 0,
  "ALTER TABLE `design_orders` ADD COLUMN `rework_price_designer` int DEFAULT NULL COMMENT 'Доопрацювання: нова D, грн'",
  'SELECT 1');
PREPARE ddl_stmt FROM @ddl;
EXECUTE ddl_stmt;
DEALLOCATE PREPARE ddl_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'design_orders' AND column_name = 'rework_price_client') = 0,
  "ALTER TABLE `design_orders` ADD COLUMN `rework_price_client` int DEFAULT NULL COMMENT 'Доопрацювання: нова P, грн'",
  'SELECT 1');
PREPARE ddl_stmt FROM @ddl;
EXECUTE ddl_stmt;
DEALLOCATE PREPARE ddl_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'design_orders' AND column_name = 'rework_deadline') = 0,
  "ALTER TABLE `design_orders` ADD COLUMN `rework_deadline` date DEFAULT NULL COMMENT 'Доопрацювання: готово до'",
  'SELECT 1');
PREPARE ddl_stmt FROM @ddl;
EXECUTE ddl_stmt;
DEALLOCATE PREPARE ddl_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'design_orders' AND column_name = 'rework_proposed_at') = 0,
  "ALTER TABLE `design_orders` ADD COLUMN `rework_proposed_at` datetime DEFAULT NULL COMMENT 'Коли дизайнер запропонував доопрацювання'",
  'SELECT 1');
PREPARE ddl_stmt FROM @ddl;
EXECUTE ddl_stmt;
DEALLOCATE PREPARE ddl_stmt;

-- design_files: копія купленого макета в хмару (фонове завдання).
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'design_files' AND column_name = 'cloud_status') = 0,
  "ALTER TABLE `design_files` ADD COLUMN `cloud_status` tinyint NOT NULL DEFAULT '0' COMMENT '0 немає, 1 у черзі, 2 у хмарі, 3 помилка'",
  'SELECT 1');
PREPARE ddl_stmt FROM @ddl;
EXECUTE ddl_stmt;
DEALLOCATE PREPARE ddl_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'design_files' AND column_name = 'cloud_error') = 0,
  "ALTER TABLE `design_files` ADD COLUMN `cloud_error` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Помилка останнього копіювання в хмару'",
  'SELECT 1');
PREPARE ddl_stmt FROM @ddl;
EXECUTE ddl_stmt;
DEALLOCATE PREPARE ddl_stmt;

SET @ddl = IF((SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'design_files' AND index_name = 'idx_cloud_status') = 0,
  "ALTER TABLE `design_files` ADD KEY `idx_cloud_status` (`cloud_status`)",
  'SELECT 1');
PREPARE ddl_stmt FROM @ddl;
EXECUTE ddl_stmt;
DEALLOCATE PREPARE ddl_stmt;
