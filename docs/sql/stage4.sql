-- Майстерня дизайну (design.wolf.ua), етап 4: робочий простір — превʼю, макети для друку, ліміти.
-- Запускає власник на dev і на проді (база w2p). Повторний запуск безпечний.

CREATE TABLE IF NOT EXISTS `design_previews` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL COMMENT 'design_orders.id',
  `offer_id` int unsigned NOT NULL COMMENT 'design_offers.id — листування, куди надіслано превʼю',
  `num` int unsigned NOT NULL COMMENT 'Номер версії vN, наскрізний по замовленню',
  `based_on_id` int unsigned DEFAULT NULL COMMENT 'design_previews.id — «на основі vK»',
  `message_id` int unsigned DEFAULT NULL COMMENT 'design_messages.id — повідомлення, яким надіслано',
  `pages` smallint unsigned NOT NULL DEFAULT '1' COMMENT '1 для картинки, N для PDF',
  `source_type` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'image' COMMENT 'image | pdf',
  `hidden_at` datetime DEFAULT NULL COMMENT 'Коли дизайнер приховав (файли видалено з диска)',
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_num` (`order_id`, `num`),
  KEY `idx_offer_id` (`offer_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Майстерня дизайну: версії превʼю (з водяним знаком)';

CREATE TABLE IF NOT EXISTS `design_files` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL COMMENT 'design_orders.id',
  `label` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Підпис; за замовчуванням імʼя файла без розширення',
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Імʼя файла, яке завантажив дизайнер',
  `file` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Імʼя на диску в layouts/{orderId}/',
  `size` bigint unsigned NOT NULL DEFAULT '0' COMMENT 'Байти',
  `md5` char(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '1 завантажено, 2 замінено, 3 видалено',
  `purchase_id` int unsigned DEFAULT NULL COMMENT 'Покупка (етап 5)',
  `cloud_id` int unsigned DEFAULT NULL COMMENT 'Файл у хмарі після купівлі (етап 5)',
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_status` (`order_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Майстерня дизайну: макети для друку (до купівлі лежать на локальному диску)';

-- Ліміти: превʼю (сторінок PDF, МБ), макети (МБ на файл, файлів і МБ усього на замовлення)
INSERT IGNORE INTO `design_settings` (`key`, `value`) VALUES ('preview_max_pages', '30');
INSERT IGNORE INTO `design_settings` (`key`, `value`) VALUES ('preview_max_mb', '50');
INSERT IGNORE INTO `design_settings` (`key`, `value`) VALUES ('layout_max_mb', '1024');
INSERT IGNORE INTO `design_settings` (`key`, `value`) VALUES ('layout_max_files', '20');
INSERT IGNORE INTO `design_settings` (`key`, `value`) VALUES ('layout_max_total_mb', '5120');
