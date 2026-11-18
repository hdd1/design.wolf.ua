-- Майстерня дизайну (design.wolf.ua), етап 3: пропозиції дизайнерів і листування до вибору.
-- Запускає власник на dev і на проді (база w2p). Повторний запуск безпечний.

CREATE TABLE IF NOT EXISTS `design_offers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL COMMENT 'design_orders.id',
  `designer_id` int NOT NULL COMMENT 'design_designers.customer_id',
  `status` tinyint NOT NULL DEFAULT '3' COMMENT '1 запрошено (режим 2), 2 відмовився, 3 надіслано, 4 обрано, 5 обрано іншого, 6 відкликано дизайнером',
  `text` text COLLATE utf8mb4_unicode_ci COMMENT 'Що зробить дизайнер',
  `price_designer` int NOT NULL DEFAULT '0' COMMENT 'D — сума дизайнеру «на руки», грн',
  `markup_percent` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT 'k — націнка на момент пропозиції, %',
  `price_client` int NOT NULL DEFAULT '0' COMMENT 'P — ціна для клієнта: D·(1+k/100), вгору до price_round',
  `deadline` date DEFAULT NULL COMMENT 'Готово до',
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `client_seen_at` datetime DEFAULT NULL COMMENT 'Коли клієнт востаннє відкривав листування',
  `designer_seen_at` datetime DEFAULT NULL COMMENT 'Коли дизайнер востаннє відкривав листування',
  `client_notified_at` datetime DEFAULT NULL COMMENT 'Коли клієнту востаннє слали «нове повідомлення»',
  `designer_notified_at` datetime DEFAULT NULL COMMENT 'Коли дизайнеру востаннє слали «нове повідомлення»',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_designer` (`order_id`, `designer_id`),
  KEY `idx_order_status` (`order_id`, `status`),
  KEY `idx_designer_status` (`designer_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Майстерня дизайну: пропозиції дизайнерів (одна пара замовлення+дизайнер — один рядок)';

CREATE TABLE IF NOT EXISTS `design_messages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `offer_id` int unsigned NOT NULL COMMENT 'design_offers.id — чиє листування',
  `author_id` int NOT NULL DEFAULT '0' COMMENT 'customers.id (клієнт або дизайнер), 0 — система',
  `author_role` tinyint NOT NULL COMMENT '1 клієнт, 2 дизайнер, 3 система',
  `text` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Не редагується й не видаляється',
  `preview_id` int unsigned DEFAULT NULL COMMENT 'До якого превʼю (етап 4)',
  `reply_to_id` int unsigned DEFAULT NULL COMMENT 'Відповідь на повідомлення',
  `data` json DEFAULT NULL COMMENT 'Для системних: {"event":"price","from":800,"to":1000}',
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_offer_id` (`offer_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Майстерня дизайну: повідомлення в листуванні по пропозиції';

-- Лічильник нових брифів у меню дизайнера. ADD COLUMN не має IF NOT EXISTS у MySQL 8.0 —
-- перевірка через information_schema, повторний запуск нічого не робить.
SET @has_feed_seen = (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'design_designers' AND column_name = 'feed_seen_at');
SET @ddl = IF(@has_feed_seen = 0,
  "ALTER TABLE `design_designers` ADD COLUMN `feed_seen_at` datetime DEFAULT NULL COMMENT 'Коли дизайнер востаннє відкривав стрічку брифів' AFTER `reviewed_by`",
  'SELECT 1');
PREPARE ddl_stmt FROM @ddl;
EXECUTE ddl_stmt;
DEALLOCATE PREPARE ddl_stmt;

-- Націнка на ціну дизайнера (%) і крок округлення ціни для клієнта (грн)
INSERT IGNORE INTO `design_settings` (`key`, `value`) VALUES ('markup_percent', '20');
INSERT IGNORE INTO `design_settings` (`key`, `value`) VALUES ('price_round', '10');
