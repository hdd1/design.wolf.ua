-- Майстерня дизайну (design.wolf.ua), етап 2: дизайнери — профіль, заявка, портфоліо.
-- Запускає власник на dev і на проді (база w2p). Повторний запуск безпечний.

CREATE TABLE IF NOT EXISTS `design_designers` (
  `customer_id` int NOT NULL COMMENT 'customers.id — дизайнер є клієнтом wolf.ua',
  `status` tinyint NOT NULL DEFAULT '0' COMMENT '0 чернетка, 1 на розгляді, 2 схвалено, 3 відхилено, 4 заблоковано',
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Імʼя в профілі (customers не змінюємо)',
  `about` text COLLATE utf8mb4_unicode_ci COMMENT 'Про себе',
  `link` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Behance / Instagram / сайт, необовʼязково',
  `photo` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Файл аватара; порожньо — ініціали',
  `rating` decimal(3,2) NOT NULL DEFAULT '5.00' COMMENT 'Поки не показуємо',
  `reject_comment` text COLLATE utf8mb4_unicode_ci COMMENT 'Коментар відхилення, його бачить дизайнер',
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `submitted_at` datetime DEFAULT NULL COMMENT 'Коли подано заявку',
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL COMMENT 'users.id того, хто розглянув',
  PRIMARY KEY (`customer_id`),
  KEY `idx_status_submitted` (`status`, `submitted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Майстерня дизайну: дизайнери (заявка і профіль)';

CREATE TABLE IF NOT EXISTS `design_portfolio` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `designer_id` int NOT NULL COMMENT 'design_designers.customer_id',
  `title` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Необовʼязкова назва; задається в «Мій профіль»',
  `file` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Імʼя оригіналу, напр. 17.jpg',
  `width` int NOT NULL DEFAULT '0',
  `height` int NOT NULL DEFAULT '0',
  `pos` int NOT NULL DEFAULT '0',
  `shw` tinyint(1) NOT NULL DEFAULT '1',
  `created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_designer_shw_pos` (`designer_id`, `shw`, `pos`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Майстерня дизайну: роботи портфоліо дизайнера';

-- Куратор заявок дизайнерів: users.id, якому йде сповіщення про нову заявку
INSERT IGNORE INTO `design_settings` (`key`, `value`) VALUES ('applications_notify_user_id', '1');
