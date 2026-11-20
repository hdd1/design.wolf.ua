# Майстерня дизайну — этап 5 «Купівля». План реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** клиент покупает макеты с баланса wolf.ua (дизайнер получает гонорар), скачивает их через i.fastcdn или копирует в облако; доработка после покупки, отмена заказа, жалоба, «Заробіток», факты о клиенте, крон «срок прошёл»; субаккаунты на design ничего не могут.

**Architecture:** продолжение этапов 1–4: логика в `helpers/Design/` (новые `Purchases`, `CloudCopy`, `Rework`, `Complaints`, `Earnings`), страницы `pages/*.php` + `views/*.php`, JS — части `workspace.js`. Деньги — одна транзакция PDO с условным `UPDATE customers`. Копия в облако и крон срока — тонкие обёртки в `wolf.ua/cron/`, логика в design.

**Tech Stack:** PHP 8.1, MySQL через `Design\Sql`, Bootstrap 5.3.3, Font Awesome 5 Pro, vanilla JS, PHPUnit 9.6; общие `\FileGlobal` (cdn.wolf.ua), `\CalcApiGlobal` через `Design\Notify`.

**Spec:** `docs/superpowers/specs/2026-11-20-stage5-purchase-design.md` (утверждён владельцем 2026-11-20).

Репозиторий: `/home/connect/design.wolf.ua`, ветка `feature/stage5` (от master 26e12a0). Обёртки кронов — `/home/connect/wolf.ua`, отдельная ветка `feature/design-stage5` от `origin/feature/shulaev`, только свои файлы (как `feature/design-stage3`).

## Global Constraints

- Деньги — целые гривны. Списание только `UPDATE customers SET inv_sum = inv_sum - :p WHERE id = :c AND inv_sum + credit >= :p` внутри транзакции; никаких «прочитать баланс → записать число». `credit_ra`, `temp_credit_ra` не учитываются.
- Строки `transaction`: `orderstatus = 'COMPLETE'`, `order_id = NULL`, `type_id = 0`, `user_id = 0`, `created_ts = time()`; клиент `payment_done = -P`, `paymethod = '[design] Оплата замовлення №{N}'`; дизайнер `+D`, `'[design] Гонорар за замовлення №{N}'`. Для суммы 0 строк нет.
- Овердрафт — как `Customer::overDraft()` wolf.ua: блок, если `inv_sum < 0` и `overdraft_start + overdraft_days` дней < сегодня — «Перевищено термін погашення заборгованості на N днів»; при первом уходе в минус — `overdraft_start = CURDATE()`, `overdraft_days = 30` если NULL.
- Субаккаунт (`customers.parent_id <> 0`) на design ничего не может; баннер на всех страницах, кроме главной.
- Купленный макет (`design_files.purchase_id IS NOT NULL`) — неизменяемый: ни замены, ни переименования, ни удаления. Ссылка `\FileGlobal::encode($path, "Дизайн №{N} — {label}.{ext}", true)` строится только для купленных; для некупленных — нигде (HTML, JSON, `cloud-links/cf`).
- Макеты — папки 0755, файлы 0644 (закрытые права этапа 4 убираются).
- `/mnt/cloud` трогает только фоновое задание (крон), каждая операция под тайм-аутом; HTTP-запросы design к `/mnt/cloud` не обращаются.
- Уведомления — `Notify::send` (`customer_id` или `user_id`, `msg`, `msgSMS`), после ответа браузеру (`Http::respond($after)`); куратор — `customers.curator_id`; «вам» — `user_id = 1`.
- Тексты — украинские; вывод экранирован; изменяющие POST — CSRF; AJAX — `Http::json`.
- Изменения БД — `docs/sql/stage5.sql`, выполняет владелец (исполнитель останавливается).
- Тесты: клиент 29, дизайнер 25. Тест, трогающий деньги, запоминает и в `tearDown` восстанавливает у 29 и 25 `inv_sum`, `credit`, `parent_id`, `overdraft_start`, `overdraft_days` и удаляет свои строки `transaction` (`id >` max до теста, `paymethod LIKE '[design]%'`). Ничего не оставлять в `cloud-links/ci`, `cloud-links/cf`, `/mnt/cloud` (на dev — локальная папка), `tmp_uppy`.
- Новые файлы — `connect:connect`, `chmod 777`. Только локальные коммиты.

## Review Focus

1. **Двойная покупка**: двойной клик, два параллельных запроса, две вкладки → ровно одно списание, одна пара строк `transaction`, одна `design_purchases`. Тест — задача 3 (`test_concurrent_buy_debits_once`, два соединения PDO).
2. **Цена изменилась** между открытием окна и «Оплатити» (принята доработка, другая сумма) → «Ціна змінилася», ничего не списано. Тест — задача 3.
3. **Границы денег**: баланс ровно равен сумме; хватает только с `credit`; `credit_ra > 0` при нуле на балансе; просроченный овердрафт; сумма 0 → без строк `transaction`. Тест — задача 3.
4. **Утечка некупленного**: в HTML/JSON дизайнера и клиента нет `/cf/` для некупленных; замена/удаление/переименование купленного → отказ сервера. Тест — задача 4.
5. **Сбой копии в облако**: файла нет, `/mnt/cloud` недоступен, повторный запуск крона, повторное нажатие → статус «помилка» с повтором, без дубля в `customers_cloud`, без полуфайла в облаке. Тест — задача 5.

---

### Task 1: SQL этапа 5 и открытые права макетов

**Files:**
- Create: `docs/sql/stage5.sql`
- Modify: `helpers/Design/Paths.php`, `helpers/Design/Layouts.php` (0700/0600 → 0755/0644, `ensureDir`-проверка 0700 убирается), `tests/Design/SchemaTest.php`, `tests/Design/PathsTest.php`, `tests/Design/LayoutsTest.php`

**Interfaces:**
- Produces:
  - `design_purchases` (`id`, `order_id`, `offer_id`, `payer_id`, `price_client`, `price_designer` int, `markup_percent` decimal(5,2), `amount_client`, `amount_designer` int, `method` tinyint (1 баланс), `status` tinyint (1 ждёт, 2 проведена, 3 отменена), `transaction_client_id`, `transaction_designer_id` int NULL, `created`, `paid_at` NULL; индекс `(order_id)`).
  - `design_complaints` (`id`, `order_id`, `purchase_id`, `text` text, `status` tinyint 1/2/3, `resolution` text NULL, `resolved_by` int NULL, `created`, `resolved_at` NULL; индекс `(order_id)`).
  - `design_orders` + `cancel_reason` text NULL, `cancelled_at` datetime NULL, `rework_price_designer` int NULL, `rework_price_client` int NULL, `rework_deadline` date NULL, `rework_proposed_at` datetime NULL.
  - `design_files` + `cloud_status` tinyint NOT NULL DEFAULT 0 (0 нет, 1 в очереди, 2 в облаке, 3 ошибка), `cloud_error` varchar(255) NULL; индекс `(cloud_status)`.
  - Повторный запуск безопасен: таблицы `IF NOT EXISTS`, колонки — через проверку `information_schema` + `PREPARE` (как `feed_seen_at` в `docs/sql/stage3.sql`; MySQL 8.0 не знает `ADD COLUMN IF NOT EXISTS`).
  - `Paths::layouts()` создаёт 0755; `Layouts` пишет файлы 0644.

- [ ] **Step 1: Тесты** — `SchemaTest::test_stage5_tables`; `PathsTest` — `layouts` 0755; `LayoutsTest::test_add_moves_file_public_perms` (0644, ссылок всё ещё нет) вместо `…private_perms`.
- [ ] **Step 2: Падают.** **Step 3: Написать.** Скопировать SQL в `/home/dev-ai/notes/design-stage5.sql`, **остановиться и попросить владельца выполнить на dev**. В отчёт — команда для уже существующих папок: `find /mnt/work/source.wolf.ua/design/layouts -type d -exec chmod 755 {} + ; find … -type f -exec chmod 644 {} +`.
- [ ] **Step 4: После SQL — проходят.** **Step 5: Commit** — «SQL етапу 5, відкриті права макетів».

### Task 2: Субаккаунты

**Files:**
- Create: `views/sub-account.php`
- Modify: `helpers/Design/Customers.php`, `helpers/Design/Guard.php`, `tests/Design/CustomersTest.php` (главная не меняется)
- Test: `tests/Design/SubAccountHttpTest.php`

**Interfaces:**
- Produces:
  - `Customers::isSub(int $id): bool` — `parent_id <> 0`.
  - `Guard::customer()` для субаккаунта: GET — код 403, страница `sub-account` («Вибачте, з субакаунта замовити дизайн не можна — потрібен основний акаунт wolf.ua» + ссылка на wolf.ua); POST/AJAX — `Http::json(['error' => …], 403)`. Все страницы, кроме главной, уже зовут `Guard::customer()` — проверить, что ни одна не обходит.

- [ ] **Step 1: Тесты** — `CustomersTest::test_is_sub`; HTTP: временно `parent_id = 25` у клиента 29 (восстановить в `tearDown`) → `/orders/`, `/brief/`, `/designer/`, `/feed/` дают 403 с баннером; `POST /brief` → 403 JSON/страница, ничего не создано; `/` — 200.
- [ ] **Step 2–4.** **Step 5: Commit** — «Субакаунти: банер, дії заборонені».

### Task 3: Покупка — модель денег

**Files:**
- Create: `helpers/Design/Purchases.php`
- Test: `tests/Design/PurchasesTest.php`

**Interfaces:**
- Consumes: `Orders::find`, `Offers::get`, `Layouts` (`ACTIVE`), `Messages::system`, `Notify::send`.
- Produces:
  - `Purchases::quote(int $orderId, int $customerId): ?array{amount_client:int, amount_designer:int, price_client:int, price_designer:int, balance:int, credit:int, after:int, can:bool, reason:string, new_layouts:int}` — null, если не владелец заказа или статус не `IN_WORK`/`REWORK`. `reason`: «Немає макетів для купівлі», «Не вистачає N грн», текст овердрафта; `can` — false при любой причине.
  - `Purchases::buy(int $orderId, int $customerId, int $expectedAmount): array{purchase_id:int, amount_client:int, amount_designer:int, designer_id:int}` — одна транзакция по спеке (раздел «Проведення», шаги 1–7): `SELECT … FOR UPDATE` заказа, проверки, условный `UPDATE` клиента (0 строк → «Не вистачає N грн»), овердрафт под той же транзакцией (строка клиента `FOR UPDATE` до проверки), `+D` дизайнеру, строки `transaction`, `design_purchases` (status 2, method 1, `payer_id` = клиент), `design_files.purchase_id` всем `ACTIVE` с `purchase_id IS NULL`, `design_orders.paid_client/paid_designer/purchased_at/status = PURCHASED`, `rework_*` = NULL, системное сообщение «Клієнт купив макети за P грн» (`data.event = purchase`). `expectedAmount ≠ amount_client` → `DesignerException('Ціна змінилася — перевірте суму')`. Ошибки — `DesignerException`, откат.
  - `Purchases::notifyDesigner(array $result, int $orderId): void` — «Клієнт купив макети №N, +D грн» (вызывать после ответа).
  - `Purchases::forDesigner(int $designerId): list<array{paid_at, order_id, title, amount_designer}>` — для «Заробіток» (status 2, `amount_designer > 0`), по убыванию.

- [ ] **Step 1: Тесты** (на БД dev, заказ+пропозиция `CHOSEN` создаются тестом, деньги 29/25 восстанавливаются): `test_quote_enough_and_short`, `test_buy_moves_money_and_writes_rows` (суммы, `paymethod`, `order_id IS NULL`, `COMPLETE`, баланс клиента −P, дизайнера +D, `purchase_id` у макетов, статус 6), `test_credit_allows_negative_and_starts_overdraft`, `test_credit_ra_ignored`, `test_expired_overdraft_blocks`, `test_exact_balance_ok`, `test_price_changed_rejected_nothing_moved`, `test_no_new_layouts_rejected`, `test_zero_amount_no_transactions` (rework-сценарий: `paid_*` = цене), `test_concurrent_buy_debits_once` (второе соединение PDO держит `FOR UPDATE`, затем второй `buy` → «уже куплено»/«немає макетів»), `test_only_owner`.
- [ ] **Step 2–4.** **Step 5: Commit** — «Купівля: гроші одною транзакцією».

### Task 4: «Купую» на странице и купленные макеты

**Files:**
- Create: `pages/order-buy.php` (`POST /orders/{id}/buy`: `amount`, `csrf`), `views/partials/pay-modal.php`, `assets/js/workspace-layouts.js` (вынести из `workspace.js` всё про макеты — `workspace.js` 857 строк, сначала разделить без смены поведения), `assets/js/workspace-buy.js`
- Modify: `helpers/Design/Layouts.php` (запрет изменений купленных: «Куплений макет змінити не можна»; `forOrder` + `purchased:bool`), `helpers/Design/Workspace.php` (`layouts()` — `download` URL только для купленных и только клиенту), `helpers/Design/OrderStatus.php` (`turn`/`designerTurn` для `PURCHASED`: клиент «Макети ваші», дизайнер «Готово»; убрать «Купівля — незабаром»), `views/partials/layouts-panel.php`, `views/order.php`, `views/feed-order.php`, `helpers/Design/Router.php`, `assets/css/app.css`
- Test: `tests/Design/BuyHttpTest.php`, `tests/Design/LayoutsTest.php`, `tests/Design/OrderStatusTest.php`, `tests/e2e/test_workspace_js.py` (после разделения JS — тот же прогон зелёный)

**Interfaces:**
- Consumes: `Purchases::quote/buy/notifyDesigner`, `\FileGlobal::encode`.
- Produces:
  - Окно «Оплата: звідки?» (как `wolf.ua/pages/fns/ordersPaymentBlock.php`, по-украински): вкладки «З балансу» (На балансі · Ваш кредит (если > 0) · Сума · Залишок після оплати (красным < 0) · «Оплатити»; при `can=false` — `reason`, кнопка выключена), «Visa/Mastercard» и «Виставити рахунок» — неактивны, «Незабаром». Сумма 0 → кнопка «Відкрити макети».
  - `POST /orders/{id}/buy` → `Http::respond(['ok' => true, 'layouts' => …, 'turn' => …], 200, after: notifyDesigner)`; ошибки 4xx `{error}`.
  - В панели макетов: купленные — «Завантажити» (ссылка `FileGlobal`, имя «Дизайн №N — {label}.{ext}») и место под «Скопіювати в хмару» (задача 5); у дизайнера на купленных нет «Замінити/Видалити/✎».

- [ ] **Step 1: Тесты** — `LayoutsTest::test_purchased_is_immutable` (replace/remove/rename → исключение); `OrderStatusTest` — тексты `PURCHASED`; HTTP: `test_buy_from_balance_opens_layouts`, `test_buy_short_balance_400`, `test_no_cf_link_for_unpurchased` (HTML и JSON клиента и дизайнера до и после частичной покупки), `test_designer_cannot_touch_purchased`, `test_buy_twice_second_fails`, `test_download_link_has_name`; `cloud-links/cf` убирается тестом.
- [ ] **Step 2–4:** скриншоты окна и панели 1440/390 в `/home/dev-ai/notes/design-wolf-ua-screens/stage5/`. **Step 5: Commit** — «Купую: вікно оплати, куплені макети».

### Task 5: Копия в облако

**Files:**
- Create: `helpers/Design/CloudCopy.php`, `pages/order-cloud.php` (`POST /orders/{id}/layouts/{fileId}/cloud`), `/home/connect/wolf.ua/cron/design_cloud_copy.php` (обёртка как `design_no_offers.php`, ветка wolf.ua `feature/design-stage5`)
- Modify: `helpers/Design/Workspace.php` (`cloud_status` в `layouts()`), `views/partials/layouts-panel.php`, `assets/js/workspace-layouts.js`, `helpers/Design/Router.php`
- Test: `tests/Design/CloudCopyTest.php`

**Interfaces:**
- Produces:
  - `CloudCopy::request(int $fileId, int $customerId): int` — только клиент заказа, только купленный макет; `cloud_status` 0/3 → 1 (2 → без изменений); возвращает статус.
  - `CloudCopy::run(int $limit = 5): int` — берёт `cloud_status = 1` (захват строки условным `UPDATE`), копирует: имя `hash_hmac('sha256', md5_file, customer_id)` + `.ext`, путь `self::cloudRoot() . cloudPath(name)` (`f/xx/yy/zz/остаток`, как `Path::cloudPath(…, 1)` wolf.ua — своя копия функции), копия во временное имя рядом + `rename`; строка `customers_cloud` (`type_id = 1`, `original_name` = «Дизайн №N — {label}.{ext}», `file`, `file_size`, превью — см. ниже) или существующая по `(customer_id, file)`; `cloud_id`, статус 2. Ошибка → статус 3, `cloud_error`, временный файл удалён. Каждая операция с облаком — под `set_time_limit`/проверкой времени, не больше `limit` файлов за прогон.
  - `CloudCopy::cloudRoot(): string` — env `DESIGN_CLOUD_ROOT` или `/mnt/cloud/`.
  - Превью облака: исполнитель читает api2 `Site/Cloud/CloudController.php` (`updatePreview`) и пишет строку так, чтобы крон не гнал большой файл в Киев (например, `is_preview = 10`), и проверяет, как хмара wolf.ua (`customer-cloud*.php`) показывает такую строку; решение — в отчёт.
  - Кнопка: «Скопіювати в хмару» → «Копіюємо…» (опрос раз в 10 с) → «У хмарі ✓» / «Не вдалося, спробуйте ще».

- [ ] **Step 1: Тесты** (`DESIGN_CLOUD_ROOT` — временная папка): `test_request_only_owner_purchased`, `test_run_copies_and_inserts_row`, `test_rerun_and_second_request_no_duplicate`, `test_missing_source_marks_error_then_retry`, `test_unwritable_cloud_marks_error_no_partial_file`, `test_file_size_and_name`.
- [ ] **Step 2–4:** прогон обёртки на dev вручную (`php /home/connect/wolf.ua/cron/design_cloud_copy.php`). **Step 5: Commit** design — «Копія макета в хмару»; wolf.ua — файл обёртки отдельным коммитом в `feature/design-stage5`.

### Task 6: Доработка

**Files:**
- Create: `helpers/Design/Rework.php`, `pages/order-rework.php` (`POST /orders/{id}/rework`: `action=propose|accept|decline|cancel`)
- Modify: `helpers/Design/OrderStatus.php` (тексты: «Чекає вашої відповіді» / «Чекаємо відповіді клієнта»; `REWORK` как `IN_WORK`), `helpers/Design/Previews.php` и `Layouts.php` (работа дизайнера разрешена и в `REWORK`), `helpers/Design/Purchases.php` (`REWORK` уже поддержан), `views/order.php`, `views/feed-order.php`, `assets/js/workspace.js`, `helpers/Design/Router.php`
- Test: `tests/Design/ReworkTest.php`, `tests/Design/ReworkHttpTest.php`

**Interfaces:**
- Produces:
  - `Rework::propose(int $orderId, int $designerId, int $priceDesigner, string $deadline): void` — только выбранный дизайнер, статус `PURCHASED`; `priceDesigner ≥ paid_designer`, дата ≥ сегодня; `P' = max(Price::client(D', offer.markup_percent, Settings::priceRound()), paid_client)`; пишет `rework_*`, системное сообщение «Дизайнер пропонує доопрацювання: +X грн, до {d.m.Y}» (`data.event = rework_propose`), уведомление клиенту.
  - `Rework::accept(int $orderId, int $customerId): void` — статус `REWORK`; цены и `deadline` выбранной пропозиции = `rework_*` (сообщение «Ціну змінено з X на Y грн»); `rework_*` = NULL; `notified_deadline_at` = NULL; уведомление дизайнеру.
  - `Rework::decline(int $orderId, int $customerId): void` — `rework_*` = NULL, статус остаётся; сообщение; уведомление дизайнеру.
  - `Rework::cancel(int $orderId, int $customerId): void` — из `REWORK` → `PURCHASED`; цены пропозиции = `paid_client`/`paid_designer`, дата прежняя; некупленные новые макеты остаются закрытыми; уведомление дизайнеру.
  - `Rework::pending(array $order): ?array{price_client:int, extra:int, deadline:string}`.

- [ ] **Step 1: Тесты** — `test_propose_rules` (не тот дизайнер, не `PURCHASED`, цена ниже оплаченной, прошлая дата), `test_accept_updates_offer_and_status`, `test_decline_resets`, `test_cancel_rework_reverts_prices`, `test_buy_in_rework_charges_difference` (через `Purchases`), `test_free_rework_zero_amount_opens_layouts`; HTTP: кнопки у нужных ролей, тексты «чей ход».
- [ ] **Step 2–4:** скриншоты. **Step 5: Commit** — «Доопрацювання: пропозиція, прийняти, відмовитися».

### Task 7: Отмена заказа и жалоба

**Files:**
- Create: `helpers/Design/Complaints.php`, `pages/order-cancel.php` (`POST /orders/{id}/cancel`: `reason`), `pages/order-complaint.php` (`POST /orders/{id}/complaint`: `text`)
- Modify: `helpers/Design/Orders.php` (+ `cancel`), `helpers/Design/Previews.php` / `Layouts.php` (удаление файлов заказа с диска, включая симлинки i.fastcdn превью), `helpers/Design/OrderStatus.php` («Скасовано: {причина}»), `views/order.php`, `helpers/Design/Router.php`
- Test: `tests/Design/CancelComplaintTest.php`, `tests/Design/CancelComplaintHttpTest.php`

**Interfaces:**
- Produces:
  - `Orders::cancel(int $orderId, int $customerId, string $reason): void` — статусы `DRAFT`…`IN_WORK` (1–4) и нет ни одной проведённой покупки; причина обязательна, 1–2000 символов («Вкажіть причину»); статус 8, `cancel_reason`, `cancelled_at`; статусы пропозиций не меняются, заказ и переписки — только для чтения; файлы превью и некупленных макетов удаляются с диска, строки остаются; уведомления: обранному/запрошенному дизайнеру и всем с `SENT` — «Замовлення №N скасовано клієнтом», куратору — с причиной.
  - `Complaints::add(int $orderId, int $customerId, string $text): int` — статусы `PURCHASED`/`REWORK`, текст 1–2000; `purchase_id` — последняя проведённая; уведомления куратору и `user_id = 1` с текстом; повторные жалобы разрешены.
  - `Complaints::lastForOrder(int $orderId): ?array{created:string}` — для «Скаргу надіслано {дата}».

- [ ] **Step 1: Тесты** — `test_cancel_statuses_and_reason`, `test_cancel_deletes_files_keeps_rows`, `test_cancel_after_purchase_refused`, `test_cancel_notifies_designers_and_curator`, `test_cancelled_order_read_only` (сообщения, превью, макеты, покупка → отказ), `test_complaint_only_after_purchase`, `test_complaint_notifies_curator_and_user1`.
- [ ] **Step 2–4.** **Step 5: Commit** — «Скасування замовлення і скарга».

### Task 8: «Заробіток», факты о клиенте, крон срока

**Files:**
- Create: `pages/earnings.php`, `views/earnings.php`, `/home/connect/wolf.ua/cron/design_deadline.php` (обёртка, ветка wolf.ua `feature/design-stage5`)
- Modify: `helpers/Design/Router.php` (`GET /earnings/`), `views/layout.php` (пункт меню дизайнера «Заробіток»), `helpers/Design/Customers.php` (+ `facts`), `views/feed.php`, `views/feed-order.php`, `helpers/Design/Offers.php` (+ `notifyDeadline`)
- Test: `tests/Design/EarningsHttpTest.php`, `tests/Design/CustomersTest.php`, `tests/Design/DeadlineCronTest.php`

**Interfaces:**
- Consumes: `Purchases::forDesigner`.
- Produces:
  - `/earnings/` — только одобренный дизайнер: таблица (дата, «№N», название, сумма) и «Разом: N грн»; текст «Для виведення коштів зверніться до менеджера».
  - `Customers::facts(int $customerId): array{orders:int, bought:int, cancelled:int}` — опубликованные (статус ≠ `DRAFT`), с ≥ 1 проведённой покупкой, `CANCELLED`. В брифе: «Замовлень: N · куплено: M · скасовано: K» или «Новий клієнт» при N ≤ 1 (только текущий бриф).
  - `Offers::notifyDeadline(): int` — `IN_WORK`/`REWORK`, `deadline` выбранной пропозиции < CURDATE(), `notified_deadline_at IS NULL` → захват отметки условным `UPDATE`, куратору «Майстерня дизайну: №N — термін минув, макети не куплено (клієнт …)». Как `notifyNoOffers`.

- [ ] **Step 1: Тесты** — `EarningsHttpTest` (сумма, чужие не видны, клиент → 403/редирект), `CustomersTest::test_facts`, `DeadlineCronTest` (один раз, повтор — 0, после принятой доработки — снова).
- [ ] **Step 2–4:** обёртку на dev запустить вручную. **Step 5: Commit** design — «Заробіток, факти про клієнта, крон терміну»; wolf.ua — обёртка в `feature/design-stage5`.

### Task 9: Финальная проверка

- [ ] `docs/STRUCTURE.md` — новые таблицы/колонки, события `purchase`, `rework_*`, правило купленных макетов, субаккаунты.
- [ ] Полный `vendor/bin/phpunit` → PASS; балансы 29/25 и `transaction` после тестов — как до них (сверить `inv_sum` с суммой `transaction`).
- [ ] Скриншоты 1440/390 всех новых состояний.
- [ ] Финальное ревью ветки; текст PR `/home/dev-ai/notes/design-stage5-pr.md`; порядок выкатки: `stage5.sql` → chmod папок макетов (если есть) → design → wolf.ua (`feature/design-stage5`) → в adminka «Cron»: `design_cloud_copy.php` раз в минуту, `design_deadline.php` раз в час.
