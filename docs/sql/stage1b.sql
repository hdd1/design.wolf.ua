-- Майстерня дизайну, етап 1 (доповнення за макетами Pencil): опис і фото продукту, назва замовлення, питання візитки.
-- Запускати один раз після stage1.sql (ADD COLUMN повторно впаде з «Duplicate column»).

ALTER TABLE `design_products`
  ADD COLUMN `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Короткий підпис на картці' AFTER `name`,
  ADD COLUMN `image` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Фото картки: assets/img/products/<image>; порожньо — картка без фото' AFTER `description`,
  MODIFY `brief` json NOT NULL COMMENT 'Питання брифу: [{"q": "питання", "note": "підказка під питанням", "hint": "приклад у полі"}]';

ALTER TABLE `design_orders`
  ADD COLUMN `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Назва від клієнта; порожньо — назва продукту' AFTER `mode`;

UPDATE `design_products` SET `description` = '90×50 мм, одна чи дві сторони', `image` = 'vizytka.webp',
  `brief` = '[{"q":"Що має бути на візитці","note":"Імʼя, посада, телефони, адреса, соцмережі","hint":"Кавʼярня «Зерно». Олена Мельник — власниця. +380 67 123 45 67, вул. Соборна, 14, Полтава"},{"q":"Розмір","note":"Якщо не знаєте — залиште стандартний","hint":"90×50 мм"},{"q":"Одна чи дві сторони","note":"Що на якій стороні","hint":"Дві: на лицьовій — назва, на звороті — контакти"},{"q":"Стиль і кольори","note":"Що подобається, а що ні","hint":"Як наша вивіска: темно-коричневий і крафт"},{"q":"Матеріали","note":"Посилання на логотип, фото, Instagram — просто текстом","hint":"instagram.com/… — там фото вивіски"},{"q":"Коли потрібно","note":"Бажана дата готового макета","hint":"Наприклад: до 20 листопада"}]'
  WHERE `name` = 'Візитка';
UPDATE `design_products` SET `description` = 'A5 чи A6, для роздачі й пошти', `image` = 'flaer.webp' WHERE `name` = 'Флаєр';
UPDATE `design_products` SET `description` = 'Складений у 2 чи 3 частини', `image` = 'buklet.webp' WHERE `name` = 'Буклет';
UPDATE `design_products` SET `description` = 'Каталог, меню, від 8 сторінок', `image` = 'broshura.webp' WHERE `name` = 'Брошура на скобі';
UPDATE `design_products` SET `description` = 'Знак і написання для вивіски, пакування', `image` = 'logotyp.webp' WHERE `name` = 'Логотип';
UPDATE `design_products` SET `description` = 'Меню, етикетка, наліпка, плакат — опишіть своїми словами' WHERE `name` = 'Інше';
