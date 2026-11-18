# Майстерня дизайну — этап 3 «Пропозиції». План реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** схваленные дизайнеры видят ленту брифов и предлагают себя с ценой и сроком, клиент переписывается с каждым и выбирает одного; работает режим «Одразу обрати»; уведомления и крон «сутки без предложений».

**Architecture:** продолжение этапов 1–2: логика в `helpers/Design/` (новые `Price`, `Settings`, `OfferStatus`, `Messages`, `Offers`), страницы `pages/*.php` + `views/*.php`, маршруты в `Router::ROUTES`. Переписка — AJAX-отправка и опрос раз в 15–20 с (`assets/js/chat.js`). Крон — тонкая обёртка `wolf.ua/cron/design_no_offers.php`, логика в `Design\Offers`.

**Tech Stack:** PHP 8.1, MySQL через `Design\Sql`, Bootstrap 5.3.3, Font Awesome 5 Pro, vanilla JS, PHPUnit 9.6.

**Spec:** `docs/superpowers/specs/2026-11-18-stage3-offers-design.md` (утверждён владельцем 2026-11-18).

Репозиторий: `/home/connect/design.wolf.ua`, ветка `feature/stage3`. wolf.ua: `/home/connect/wolf.ua`, ветка `feature/shulaev` (только файл крона).

## Global Constraints

- Таблицы — `design_*`, `utf8mb4_unicode_ci`, комментарии на украинском. SQL — `docs/sql/stage3.sql`, повторный запуск безопасен; выполняет владелец (исполнитель останавливается и просит).
- `customers` — только чтение. Код wolf.ua не меняем, кроме одного файла `wolf.ua/cron/design_no_offers.php` (в рабочей копии wolf.ua чужие правки — коммитить только свой файл по имени).
- Тексты сайта — украинские. Деньги — целые гривны.
- Цена: P = D·(1 + k/100), вверх до `price_round`; k = `design_settings.markup_percent` (20), `price_round` = 10; снимок D, k, P в предложении.
- Уведомления — только `Design\Notify::send` (→ `CalcApiGlobal::post('sms/add')`). У каждого: `msg` — полный текст со ссылкой и временем `d.m H:i`; `msgSMS` — короткий украинский без имён: «Майстерня дизайну: … по №N. design.wolf.ua».
- Дизайнер видит клиента только как «Тарас Г.» (`Customers::header()['short']`), без контактов. Клиент видит дизайнера по `design_designers.name`. Рейтинга и числа заказов нет. Галочек «прочитано» нет.
- До выбора — только текст в переписке. Все переходы статусов атомарные (`UPDATE … WHERE status …`), выбор — в транзакции.
- Каждый изменяющий POST — CSRF; AJAX-ответы — `Http::json`; текстовые поля — через `Http::str`; весь вывод экранирован.
- Тесты: клиент — `DESIGN_TEST_CUSTOMER_ID` (29), дизайнер — `DESIGN_TEST_DESIGNER_ID` (25). Пишущие HTTP-тесты пропускаются, если у 25 уже есть свои данные дизайнера; чистят только созданное. Фиктивные id для юнит-тестов — 999999701+.
- Новые файлы в `/home/connect` — `connect:connect`, `chmod 777`. Только локальные коммиты.

## Review Focus

1. **Гонка выбора**: два «Обрати» в двух вкладках или «Обрати» одновременно с «Відкликати» → ровно одно предложение «обрано», остальные «обрано іншого», отозванное не выбирается. Тест — задача 3 (`test_choose_twice_and_withdrawn_not_choosable`).
2. **Чужая переписка по прямой ссылке**: другой дизайнер или другой клиент открывает `/offers/{id}/messages` → 404 и для чтения, и для записи. Тест — задача 5 (`test_foreign_offer_messages_404`).
3. **Цена на границах округления и смена наценки**: 833 → 1000, 850 → 1020, 1 → 10; после смены `markup_percent` старое предложение не меняется, а изменённая цена считается по новому k. Тест — задачи 1 и 3.
4. **Спам уведомлений**: пять сообщений подряд → одно уведомление; получатель открыл переписку → следующее снова уведомляет; получатель сейчас на странице (опрос < 45 с) → не уведомляет. Тест — задача 2 (`test_first_unread_rule`).
5. **Недопустимый исполнитель**: свой бриф в ленте, предложение на свой заказ, заблокированный дизайнер предлагает / приглашается / выбирается → отказ. Тесты — задачи 3 и 4.

---

### Task 1: SQL этапа 3, цена, настройки, статусы предложения

**Files:**
- Create: `docs/sql/stage3.sql`, `helpers/Design/Price.php`, `helpers/Design/Settings.php`, `helpers/Design/OfferStatus.php`
- Modify: `tests/Design/SchemaTest.php`
- Test: `tests/Design/PriceTest.php`, `tests/Design/SettingsTest.php`

**Interfaces:**
- Produces:
  - `design_offers` (поля `STRUCTURE.md` + `client_seen_at`, `designer_seen_at`, `client_notified_at`, `designer_notified_at`; UNIQUE `(order_id, designer_id)`, индексы `(order_id, status)`, `(designer_id, status)`), `design_messages` (поля `STRUCTURE.md`, индекс `(offer_id, id)`), `design_designers.feed_seen_at` datetime NULL (ADD COLUMN — второй запуск даст «Duplicate column», в комментарии файла так и написать или проверять через information_schema), настройки `markup_percent = '20'`, `price_round = '10'` (`INSERT IGNORE`).
  - `Price::client(int $designer, float $markupPercent, int $round): int` — без плавающей ошибки (целочисленно, через копейки/сотые).
  - `Settings::get(string $key, string $default = ''): string`, `Settings::markupPercent(): float`, `Settings::priceRound(): int` (≥1, по умолчанию 10), `Settings::noOffersHours(): int` (24), `Settings::notifyUserId(): int` — кэш на запрос.
  - `OfferStatus::INVITED = 1, DECLINED = 2, SENT = 3, CHOSEN = 4, OTHER_CHOSEN = 5, WITHDRAWN = 6`; `label(int): string` — «Запрошено», «Відмовився», «Надіслано», «Обрано», «Обрано іншого», «Відкликано».

- [ ] **Step 1: Тесты.** `PriceTest::test_rounding` — `(850,20,10)→1020`, `(1000,20,10)→1200`, `(833,20,10)→1000`, `(1,20,10)→10`, `(1000,0,10)→1000`, `(995,20,1)→1194`, `(1000,17.5,10)→1180`. `SettingsTest` — значения из БД и умолчания для отсутствующего ключа. `SchemaTest::test_stage3_tables` — utf8mb4 у двух таблиц, колонка `feed_seen_at`, настройки `20` и `10`.
- [ ] **Step 2: Запустить — падают.**
- [ ] **Step 3: Написать SQL и классы.** Скопировать SQL в `/home/dev-ai/notes/design-stage3.sql`. **Остановиться и попросить владельца выполнить на dev** (или контроллер выполнит с его разрешения).
- [ ] **Step 4: После SQL — тесты проходят** (`DESIGN_TEST_CUSTOMER_ID=29 vendor/bin/phpunit`).
- [ ] **Step 5: Commit** — «SQL етапу 3, ціна і налаштування».

### Task 2: Сообщения и правило «первое непрочитанное»

**Files:**
- Create: `helpers/Design/Messages.php`
- Test: `tests/Design/MessagesTest.php` (строки `design_offers` вставляет сам тест)

**Interfaces:**
- Consumes: `Notify::send`, `Settings`, `Config::siteUrl`.
- Produces:
  - `Messages::ROLE_CLIENT = 1, ROLE_DESIGNER = 2, ROLE_SYSTEM = 3`, `TEXT_MAX = 2000`.
  - `Messages::add(int $offerId, int $authorId, int $role, string $text): int` — trim, пусто → `DesignerException('Напишіть повідомлення')`, длиннее — «Повідомлення — не більше 2000 символів». Права не проверяет.
  - `Messages::system(int $offerId, string $text, array $data = []): int` — `author_id = 0`, роль 3, `data` json.
  - `Messages::list(int $offerId, int $afterId = 0): list<array{id:int, role:int, author_id:int, text:string, data:?array, created:string}>`, `Messages::count(int $offerId): int` (без системных).
  - `Messages::seen(int $offerId, int $role): void` — `client_seen_at` / `designer_seen_at` = NOW().
  - `Messages::notifyNew(int $offerId, int $toRole, int $toId, string $fromName, int $orderId): bool` — шлёт «Майстерня дизайну: нове повідомлення від {fromName} по №{orderId}. {url} ({d.m H:i})» / SMS «Майстерня дизайну: нове повідомлення по №{orderId}. design.wolf.ua» (клиенту `customer_id`, дизайнеру тоже `customer_id`), только если `notified_at IS NULL OR seen_at >= notified_at`, **и** `seen_at IS NULL OR seen_at < NOW() - INTERVAL 45 SECOND`; затем `notified_at = NOW()`. Ссылка: клиенту `{siteUrl}/orders/{orderId}/`, дизайнеру `{siteUrl}/feed/{orderId}/`. Возвращает, ушло ли.

- [ ] **Step 1: Тесты** — `test_add_validates_and_keeps_text` (апостроф и `<b>` как есть), `test_list_after_id_and_count_skips_system`, `test_first_unread_rule` (5 подряд → 1 в `Notify::$sent`; `seen` с временем после уведомления → следующее уходит; `seen_at` = сейчас → не уходит; время в БД двигать `UPDATE … SET seen_at = NOW() - INTERVAL 60 SECOND`).
- [ ] **Step 2: Запустить — падают.** **Step 3: Реализовать.** **Step 4: Проходят.**
- [ ] **Step 5: Commit** — «Повідомлення і сповіщення про перше непрочитане».

### Task 3: Предложения — режим 1, выбор, лента

**Files:**
- Create: `helpers/Design/Offers.php`
- Modify: `helpers/Design/OrderStatus.php` (если `turn` нужен счёт активных предложений — сигнатура не меняется)
- Test: `tests/Design/OffersTest.php` (клиент и дизайнеры — фиктивные 999999701+, заказы вставляет тест)

**Interfaces:**
- Consumes: `OfferStatus`, `Price`, `Settings`, `Messages`, `Notify`, `Designers::get`, `Customers::header`, `DesignerStatus`, `OrderStatus`.
- Produces:
  - `Offers::TEXT_MAX = 2000`.
  - `Offers::propose(int $orderId, int $designerId, string $text, int $price, string $deadline): int` — дизайнер `APPROVED` («Лише схвалені дизайнери можуть пропонувати»), не свой заказ, заказ `COLLECTING` и `mode = 1`; строки нет или она `DECLINED`/`WITHDRAWN` → `SENT` со снимком D, k, P; повторное предложение при `SENT` — «Ви вже запропонували — змініть пропозицію». Ошибки: «Опишіть, що зробите», «Вкажіть суму від 1 грн», «Дата — не раніше сьогодні» (формат `Y-m-d`). Уведомление клиенту: «Майстерня дизайну: нова пропозиція від {designer name} по замовленню №{id} — {P} грн. {siteUrl}/orders/{id}/ ({d.m H:i})» / «Майстерня дизайну: нова пропозиція по №{id}. design.wolf.ua».
  - `Offers::update(int $offerId, int $designerId, string $text, int $price, string $deadline): void` — только `SENT` (до выбора); новая P по текущему k; при смене цены — `Messages::system('Ціну змінено з {старa P} на {нова P} грн', ['event'=>'price','from'=>…,'to'=>…])`, срока — «Термін змінено з {d} на {d}» (`event`=`deadline`).
  - `Offers::withdraw(int $offerId, int $designerId): void` — `SENT` → `WITHDRAWN` (режим 2 — задача 4 дополняет).
  - `Offers::get(int $offerId): ?array` (строка + `order_id`, `customer_id` заказа).
  - `Offers::access(int $offerId, int $customerId): ?array{offer:array, role:int, write:bool}` — клиент заказа или дизайнер предложения, иначе null; `write` — предложение `INVITED`/`SENT`/`CHOSEN`, заказ не `CANCELLED`, а после выбора — только `CHOSEN`.
  - `Offers::forOrder(int $orderId, int $customerId): list<array{id, status, designer_id, designer_name, designer_photo, available:bool, text, price_client, deadline, messages:int, price_changed:bool, updated}>` — для клиента, без `INVITED`-до-ответа скрытых полей; `available` = дизайнер `APPROVED`.
  - `Offers::choose(int $orderId, int $customerId, int $offerId): void` — транзакция: заказ `COLLECTING` своего клиента, предложение `SENT`, дизайнер `APPROVED` («Дизайнер недоступний»); заказ → `IN_WORK`, `chosen_offer_id`, `chosen_at`; предложение → `CHOSEN`; остальные `SENT` → `OTHER_CHOSEN`. Повтор — «Дизайнера вже обрано». Уведомление выбранному: «Майстерня дизайну: клієнт обрав вашу пропозицію по №{id}. {siteUrl}/feed/{id}/ ({d.m H:i})» / «Майстерня дизайну: вас обрали виконавцем №{id}. design.wolf.ua».
  - `Offers::feed(int $designerId, int $page): array{items:list<array{order_id, product_name, title, published_at, preview:list<array{q,a}>, offers:int, mine:?array, chosen_other:bool, is_new:bool}>, total:int, pages:int}` — заказы `COLLECTING` режима 1 (не свои) плюс заказы, где у этого дизайнера `OTHER_CHOSEN`; новые сверху по `published_at`; `preview` — первые 2 непустые ответа; `offers` — число `SENT`+`CHOSEN`; `is_new` — `published_at > feed_seen_at`; `PER_PAGE = 24`.
  - `Offers::feedNewCount(int $designerId): int`, `Offers::markFeedSeen(int $designerId): void`.
  - `Offers::mine(int $designerId): array{offers:list<…>, work:list<…>}` — для «Мої пропозиції»: вкладка «Пропозиції» (`INVITED`, `SENT`, `DECLINED`, `WITHDRAWN`, `OTHER_CHOSEN`), «В роботі» (`CHOSEN`).

- [ ] **Step 1: Тесты** — `test_propose_snapshot_and_notify`, `test_propose_rules` (не approved, свой заказ, заказ не `COLLECTING`, пустой текст, 0 грн, вчерашняя дата), `test_update_price_change_makes_system_message_and_uses_current_markup`, `test_withdraw_and_repropose_same_row`, `test_choose_twice_and_withdrawn_not_choosable`, `test_choose_blocked_designer_refused`, `test_feed_excludes_own_and_mode2_and_shows_other_chosen`, `test_feed_new_count_and_seen`, `test_access_matrix`, `test_mine_tabs`.
- [ ] **Step 2: Запустить — падают.** **Step 3: Реализовать.** **Step 4: Проходят.**
- [ ] **Step 5: Commit** — «Пропозиції: режим 1, вибір, стрічка».

### Task 4: Режим «Одразу обрати»

**Files:**
- Modify: `helpers/Design/Offers.php`, `helpers/Design/Orders.php` (публикация в режиме 2)
- Test: `tests/Design/OffersMode2Test.php`

**Interfaces:**
- Consumes: всё из задачи 3, `Orders::publish`.
- Produces:
  - `Offers::invite(int $orderId, int $customerId, int $designerId): void` — заказ своего клиента в `DRAFT` (публикация с выбором: `status = WAITING_DESIGNER`, `mode = 2`, `published_at = NOW()`) или `COLLECTING` режима 2 без активного (`INVITED`/`SENT`) предложения («Запросити іншого»); дизайнер `APPROVED`, не сам клиент; строка новая или из `DECLINED`/`WITHDRAWN` → `INVITED`. Уведомление дизайнеру: «Майстерня дизайну: клієнт {Тарас Г.} обрав вас для замовлення №{id}. Береш? {siteUrl}/feed/{id}/ ({d.m H:i})» / «Майстерня дизайну: вас обрали для №{id}. design.wolf.ua».
  - `Offers::accept(int $offerId, int $designerId, string $text, int $price, string $deadline): void` — `INVITED` → `SENT` (те же проверки и снимок, что `propose`), заказ `WAITING_DESIGNER` → `COLLECTING`; уведомление клиенту как о новом предложении.
  - `Offers::decline(int $offerId, int $designerId, string $reason): void` — `INVITED` → `DECLINED`, заказ → `COLLECTING` (`mode` остаётся 2); непустая причина — сообщение дизайнера в переписке. Уведомления: клиенту «Майстерня дизайну: {designer} не може взяти №{id}. Оберіть іншого дизайнера: {siteUrl}/orders/{id}/ ({d.m H:i})» / «Майстерня дизайну: дизайнер відмовився від №{id}. design.wolf.ua»; куратору клиента (`customers.curator_id`, `user_id`; 0 → только `error_log`) — «Майстерня дизайну: дизайнер {designer} відмовився від №{id} (клієнт {полное имя}, id {customer_id}) ({d.m H:i})».
  - `withdraw` в режиме 2 (предложение было из приглашения, заказ `mode = 2`) — те же уведомления, что `decline`.
  - `Offers::collect(int $orderId, int $customerId): void` — заказ `COLLECTING`, `mode = 2`, без активного предложения → `mode = 1`, `published_at = NOW()`, `notified_no_offers_at = NULL`.
  - `Offers::mode2State(int $orderId, int $customerId): ?array{state:'waiting'|'declined'|'offer', offer:?array}` — для экрана клиента; приглашение заблокированного дизайнера → `declined`.

- [ ] **Step 1: Тесты** — `test_publish_with_designer_invites`, `test_accept_moves_to_collecting_and_notifies`, `test_decline_with_reason_notifies_client_and_curator`, `test_withdraw_after_accept_acts_like_decline`, `test_reinvite_declined_designer`, `test_collect_republishes`, `test_invite_blocked_or_self_refused`, `test_blocked_invited_looks_declined`.
- [ ] **Step 2: Запустить — падают.** **Step 3: Реализовать.** **Step 4: Проходят** (весь набор).
- [ ] **Step 5: Commit** — «Режим «Одразу обрати»».

### Task 5: Переписка — эндпоинты и виджет

**Files:**
- Create: `pages/offer-messages.php`, `pages/offer-message-add.php`, `views/partials/chat.php`, `assets/js/chat.js`
- Modify: `helpers/Design/Router.php`, `tests/Design/RouterTest.php`, `assets/css/app.css`
- Test: `tests/Design/ChatHttpTest.php`

**Interfaces:**
- Consumes: `Offers::access`, `Messages::*`, `Customers::header`, `Designers::get`.
- Produces:
  - `GET /offers/{id}/messages?after=N` → JSON `{messages:[{id, mine:bool, system:bool, author, text, time}], write:bool}`; отмечает `seen` своей роли; нет доступа → 404.
  - `POST /offers/{id}/messages` (`text`, `csrf`) → `{message:{…}}` или `{error}` 422; нет права записи → 403 «Листування закрите»; после записи — `Messages::seen` своей роли и `Messages::notifyNew` собеседнику.
  - `views/partials/chat.php` — `['offerId'=>int, 'title'=>string, 'subtitle'=>string, 'note'=>?string, 'csrf'=>string, 'messages'=>list, 'write'=>bool, 'footer'=>?string]` — серверный первый рендер + `data-*` для `chat.js`.
  - `chat.js`: отправка без перезагрузки, опрос каждые 15 с, пауза при `document.hidden`, автопрокрутка вниз, ссылки http(s) кликабельны (`rel="noopener nofollow ugc"`, текст только через `textContent`), разделители «Сьогодні» / дата. Без галочек «прочитано».

- [ ] **Step 1: Тесты** — `RouterTest::test_chat_routes`; `ChatHttpTest` (клиент 29, дизайнер 25, заказ и предложение создаёт тест): `test_both_sides_read_and_write`, `test_foreign_offer_messages_404` (третий — фиктивная кука невозможна, поэтому: 29 читает предложение чужого заказа, 25 — чужое предложение), `test_closed_offer_is_read_only` (`OTHER_CHOSEN` → 403 на запись), `test_poll_after_id`, `test_html_escaped_in_json`.
- [ ] **Step 2–4:** падают → реализовать → проходят.
- [ ] **Step 5: Commit** — «Листування: відправка і опитування».

### Task 6: Страницы дизайнера

**Files:**
- Create: `pages/feed.php`, `pages/feed-order.php`, `pages/feed-offer.php`, `pages/my-offers.php`, `views/feed.php`, `views/feed-order.php`, `views/partials/offer-form.php`, `views/my-offers.php`, `assets/js/offer-form.js`
- Modify: `helpers/Design/Router.php`, `views/layout.php`, `assets/css/app.css`, `tests/Design/RouterTest.php`
- Test: `tests/Design/DesignerOffersHttpTest.php`

**Interfaces:**
- Consumes: `Offers::feed/feedNewCount/markFeedSeen/propose/update/withdraw/accept/decline/mine/access`, `Orders` (снимок брифа), chat partial (задача 5).
- Produces маршруты: `GET /feed/` (лента, ставит `feed_seen_at`), `GET /feed/{orderId}/` (полный бриф + своё предложение/приглашение + переписка), `POST /feed/{orderId}` (`action=propose|update|withdraw|accept|decline`), `GET /my-offers/` (вкладки «Пропозиції» / «В роботі», `?tab=work`). Не `APPROVED` → 404 на всех.
- Шапка для `APPROVED`: «Стрічка брифів» с кружком `feedNewCount` (> 0), «Мої пропозиції», «Мої замовлення», «Мій профіль»; для остальных — как сейчас.
- Форма предложения (макет 6.7): «Що зробите», «Сума вам на руки» с живым «Клієнт побачить N грн» (k и шаг округления — в `data-*`), «Готово до» (`<input type="date" min=today>`). Приглашение (макет 6.7 «Запрошення»): клиент «Тарас Г.», бриф, «Беру» (та же форма) / «Відмовитися» (необязательная причина).

- [ ] **Step 1: Тесты** — маршруты; HTTP (25 — дизайнер, 29 — клиент): `test_feed_requires_approved`, `test_feed_counter_and_seen`, `test_propose_update_withdraw_flow` (системное сообщение о цене видно в переписке), `test_invite_accept_and_decline`, `test_client_shown_as_short_name` (полной фамилии и телефона нет в HTML), `test_my_offers_tabs`.
- [ ] **Step 2–4:** падают → реализовать по макетам 6.7, 6.9 (1440 и 390) → проходят; скриншоты в `/home/dev-ai/notes/design-wolf-ua-screens/stage3/`.
- [ ] **Step 5: Commit** — «Сторінки дизайнера: стрічка, пропозиція, запрошення, мої пропозиції».

### Task 7: Страницы клиента

**Files:**
- Create: `pages/order-pick.php`, `views/order-offers.php`, `views/order-pick.php`
- Modify: `pages/order.php`, `views/order.php`, `pages/order-update.php`, `pages/order-mode.php`, `views/brief-mode.php`, `pages/orders.php`, `helpers/Design/Orders.php` (`forCustomer` — счёт предложений для «чей ход»), `helpers/Design/Router.php`, `tests/Design/RouterTest.php`, `assets/css/app.css`
- Test: `tests/Design/ClientOffersHttpTest.php`

**Interfaces:**
- Consumes: `Offers::forOrder/choose/invite/collect/mode2State`, chat partial.
- Produces:
  - `GET /orders/{id}/` для `COLLECTING`/`WAITING_DESIGNER`/`IN_WORK` (макет 6.4): «Ваш хід…», бриф свёрнут, «Пропозиції · N» с карточками, переписка — боковая панель (на 390 — отдельный экран), «Обрати {імʼя} за {P} грн» → подтверждение (макет «Підтвердження вибору»). Недоступный дизайнер — серая карточка «Дизайнер недоступний», без «Обрати». После выбора — выбранный дизайнер и переписка с ним; статус «В роботі».
  - Режим 2 — состояния «чекаємо відповіді дизайнера» и «дизайнер відмовився» (макеты 6.4) с причиной из переписки и кнопками «Запросити іншого дизайнера» / «Збирати пропозиції».
  - `GET /orders/{id}/pick/` — выбор дизайнера (карточки каталога с «Обрати»), с шага 3 брифа и из «Запросити іншого»; `POST /orders/{id}` `action=invite` (`designer_id`), `action=collect`, `action=choose` (`offer_id`).
  - Шаг 3 брифа: карточка «Одразу обрати дизайнера» активна, ведёт на `/orders/{id}/pick/`; «Опублікувати бриф» — как раньше (режим 1).
  - «Мої замовлення»: «чей ход» с числом предложений (`OrderStatus::turn($status, $offers)`).

- [ ] **Step 1: Тесты** — маршруты; HTTP: `test_order_shows_offers_without_rating`, `test_choose_flow` (29 выбирает предложение 25), `test_mode2_pick_invite_wait_decline_collect`, `test_unavailable_designer_not_choosable`, `test_foreign_order_404`.
- [ ] **Step 2–4:** падают → реализовать по макетам 6.4 (1440 и 390) → проходят; скриншоты в `stage3/`.
- [ ] **Step 5: Commit** — «Сторінки клієнта: пропозиції, вибір, режим «Одразу обрати»».

### Task 8: Крон «бриф без предложений»

**Files:**
- Modify: `helpers/Design/Offers.php`, `helpers/Design/Config.php`, `helpers/Design/Notify.php`
- Create: `/home/connect/wolf.ua/cron/design_no_offers.php` (репо wolf.ua)
- Test: `tests/Design/NoOffersCronTest.php`, `tests/Design/ConfigTest.php`

**Interfaces:**
- Produces:
  - `Offers::notifyNoOffers(): int` — заказы `COLLECTING`, `mode = 1`, `published_at < NOW() - INTERVAL {Settings::noOffersHours()} HOUR`, без `SENT`/`CHOSEN`, `notified_no_offers_at IS NULL` → куратору клиента «Майстерня дизайну: бриф №{id} ({продукт}) клієнта {полное имя} (id {customer_id}) добу без пропозицій ({d.m H:i})» / «Майстерня дизайну: бриф №{id} без пропозицій. design.wolf.ua»; `notified_no_offers_at = NOW()` (и при curator 0 — чтобы не повторять; `error_log`). Возвращает число обработанных.
  - `Config::isDev()` из CLI (нет `HTTP_HOST`): `true`, если существует `/home/dev-ai` (маркер dev-сервера; на проде — `/home/prod-ai`). В вебе — как сейчас.
  - `Notify::send` в CLI на dev подставляет `$_SERVER['HTTP_HOST'] = 'dev.design.wolf.ua'` на время вызова, чтобы `CalcApiGlobal` шёл в dev api2.
  - `wolf.ua/cron/design_no_offers.php`: `require '/home/connect/design.wolf.ua/helpers/Design/bootstrap.php'; echo Design\Offers::notifyNoOffers();` — без `cron_config.php` (не смешивать автозагрузчики), с одной строкой-комментарием «Майстерня дизайну: бриф добу без пропозицій → куратору. Логіка — design.wolf.ua». Расписание (раз в час) владелец ставит в adminka → `cron`.

- [ ] **Step 1: Тесты** — `test_only_old_unanswered_mode1_once`, `test_curator_zero_logged_not_sent`, `ConfigTest::test_cli_dev_marker`.
- [ ] **Step 2–4:** падают → реализовать → проходят; `php /home/connect/wolf.ua/cron/design_no_offers.php` на dev с `DESIGN_NOTIFY=off` выводит число.
- [ ] **Step 5: Commit** — design: «Крон: бриф без пропозицій»; wolf.ua: `git add cron/design_no_offers.php` только этот файл, «Майстерня дизайну: крон брифів без пропозицій».

### Task 9: Финальная проверка

- [ ] `docs/STRUCTURE.md` — таблицы `design_offers`, `design_messages`, `feed_seen_at`, статусы предложения, правило уведомлений.
- [ ] Полный `vendor/bin/phpunit` → PASS; скриншоты всех новых экранов 1440 и 390 в `/home/dev-ai/notes/design-wolf-ua-screens/stage3/`, сверка с макетами 6.4, 6.7, 6.9 (без рейтинга, без галочек).
- [ ] Финальное ревью ветки `feature/stage3` и коммита wolf.ua.
- [ ] Текст PR `/home/dev-ai/notes/design-stage3-pr.md` и порядок выкатки: `stage3.sql` на проде → design → wolf.ua (крон) → владелец ставит расписание крона в adminka.
