# Майстерня дизайну — этап 4 «Робочий простір». План реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** после выбора дизайнера — лента работы с превью (JPG/PNG/PDF → страницы, водяной знак), комментарии с цитатой, просмотр и сравнение версий, скрытие превью; загрузка больших макетов частями с подписью, заменой и удалением; клиент видит список макетов.

**Architecture:** продолжение этапов 1–3: логика в `helpers/Design/` (новые `Paths`, `ChunkUpload`, `PreviewImages`, `Previews`, `Layouts`), страницы `pages/*.php` + `views/*.php`, JS `assets/js/chunk-upload.js`, расширение `chat.js`. Сервер загрузки — общий `\UppyChunkGlobal` (cdn.wolf.ua). Превью отдаются через i.fastcdn (`\ImageGlobal::encode`), макеты — никак (закрыты до этапа 5).

**Tech Stack:** PHP 8.1, MySQL через `Design\Sql`, Imagick 3.7 / ImageMagick 6.9 + Ghostscript, Bootstrap 5.3.3, Font Awesome 5 Pro, vanilla JS, PHPUnit 9.6.

**Spec:** `docs/superpowers/specs/2026-11-19-stage4-workspace-design.md` (утверждён владельцем 2026-11-19).

Репозиторий: `/home/connect/design.wolf.ua`, ветка `feature/stage4`.

## Global Constraints

- Файлы design — только в `Paths::root()` = env `DESIGN_WORK_ROOT` или `/mnt/work/source.wolf.ua/design`: `previews/{orderId}/` (папки 0755, файлы 0644) и `layouts/{orderId}/` (папки 0700, файлы 0600). Права — явным `mkdir`/`chmod` (umask php-fpm 022).
- Превью на диске — только WebP с водяным знаком, качество 90; исходник превью не сохраняется. Через i.fastcdn — только `*-view.webp` / `*-card.webp` превью; макеты не кодируются никогда.
- Загрузка частями — только через `\UppyChunkGlobal::upload()` (`/mnt/work/tmp/tmp_uppy/`); путь к файлу от браузера не принимаем никогда; доступ и CSRF — на каждую часть.
- Настройки (`design_settings`): `preview_max_pages = 30`, `preview_max_mb = 50`, `layout_max_mb = 1024`, `layout_max_files = 20`, `layout_max_total_mb = 5120`.
- Работать с превью и макетами может только выбранный дизайнер замовлення `IN_WORK` (`chosen_offer_id`, пропозиция `CHOSEN`, дизайнер `APPROVED`); клиент — только читать список макетов и комментировать.
- Уведомления — только через `Messages::notifyNew` (правило «первое непрочитанное»); превью = сообщение с `preview_id`; действия с макетами — системные сообщения `data.event` `layout_add` / `layout_replace` / `layout_delete`.
- Тексты — украинские; весь вывод экранирован; изменяющие POST — CSRF; AJAX — `Http::json`; поля — `Http::str`.
- Изменения БД — `docs/sql/stage4.sql`, выполняет владелец (исполнитель останавливается).
- Тесты: клиент 29, дизайнер 25 (как на этапе 3, те же правила пропуска и уборки); `DESIGN_WORK_ROOT` в тестах — временная папка; ничего нового не оставлять в `/home/connect/cloud-links/ci` (`tests/CdnLinksGuard.php`) и в `/mnt/work/tmp/tmp_uppy`.
- Новые файлы — `connect:connect`, `chmod 777`. Только локальные коммиты.

## Review Focus

1. **Чужой заказ и чужая роль**: клиент шлёт части как дизайнер, другой дизайнер, дизайнер не выбранного или не `IN_WORK` заказа, заблокированный дизайнер → отказ на первой же части, на диске ничего. Тест — задача 2 и 5 (`test_upload_access_matrix`).
2. **Подделка имени и пути**: `fileId` с `../`, имя файла с `../` или `<script>`, `purpose` не из списка → отказ или безопасное имя; путь собранного файла никогда не берётся из запроса. Тест — задача 2.
3. **PDF-бомба и битые файлы**: PDF на 31 страницу, PDF 0 страниц, «PDF», который на деле текст, картинка 15000 px → понятная ошибка, ничего не создано (ни строки, ни файлов). Тест — задача 3.
4. **Лимиты макетов**: 21-й файл, сумма > 5 ГБ, файл > 1 ГБ (заявленный размер на первой части врёт меньше — проверка после склейки тоже) → отказ, временные файлы удалены. Тест — задача 5.
5. **Закрытость**: у макета нет ссылки i.fastcdn; файлы макетов 0600, папки 0700; скрытое превью — файлы удалены, в JSON/HTML нет его ссылок. Тест — задачи 4 и 5.

---

### Task 1: SQL этапа 4, пути, настройки

**Files:**
- Create: `docs/sql/stage4.sql`, `helpers/Design/Paths.php`
- Modify: `helpers/Design/Settings.php`, `tests/Design/SchemaTest.php`
- Test: `tests/Design/PathsTest.php`, `tests/Design/SettingsTest.php`

**Interfaces:**
- Produces:
  - `design_previews` (`id`, `order_id`, `offer_id`, `num`, `based_on_id` NULL, `message_id` NULL, `pages` smallint, `source_type` varchar(8) `image|pdf`, `hidden_at` NULL, `created`; UNIQUE `(order_id, num)`), `design_files` (`id`, `order_id`, `label` varchar(120), `original_name` varchar(255), `file` varchar(64), `size` bigint, `md5` char(32), `status` tinyint 1/2/3, `purchase_id` NULL, `cloud_id` NULL, `created`; индекс `(order_id, status)`); пять настроек (`INSERT IGNORE`). Повторный запуск безопасен.
  - `Paths::root(): string` (env `DESIGN_WORK_ROOT` или `/mnt/work/source.wolf.ua/design`), `Paths::previews(int $orderId): string` (создаёт 0755), `Paths::layouts(int $orderId): string` (создаёт 0700) — `mkdir` + явный `chmod`, родители `root/previews`, `root/layouts` тоже с нужными правами.
  - `Settings::previewMaxPages(): int` (30), `previewMaxBytes(): int` (50 МБ), `layoutMaxBytes(): int` (1024 МБ), `layoutMaxFiles(): int` (20), `layoutMaxTotalBytes(): int` (5120 МБ).

- [ ] **Step 1: Тесты** — `SchemaTest::test_stage4_tables`; `PathsTest` (env-путь, права 0755/0700 при umask 022, повторный вызов не меняет права существующей папки); `SettingsTest` — умолчания и значения из БД.
- [ ] **Step 2: Падают.** **Step 3: Написать.** Скопировать SQL в `/home/dev-ai/notes/design-stage4.sql`, **остановиться и попросить владельца выполнить на dev**.
- [ ] **Step 4: После SQL — проходят.** **Step 5: Commit** — «SQL етапу 4, шляхи і налаштування».

### Task 2: Приём частей

**Files:**
- Create: `helpers/Design/ChunkUpload.php`, `assets/js/chunk-upload.js`
- Test: `tests/Design/ChunkUploadTest.php`

**Interfaces:**
- Consumes: `\UppyChunkGlobal::upload(array $data): array{done:bool, file?:string}` (`/home/connect/cdn.wolf.ua/helpers/UppyChunkGlobal.php`; при плохих данных сам пишет JSON и `exit` — поэтому всё проверяем ДО вызова).
- Produces:
  - `ChunkUpload::receive(array $post, array $files, string $scope, int $maxBytes): array{done:bool, path?:string, name?:string, size?:int}` — проверяет `fileId` (`^[A-Za-z0-9_-]{8,64}$`), `chunkIndex`/`totalChunks` (целые, `totalChunks ≤ ceil(maxBytes / 5 МБ) + 1`), заявленный `totalSize ≤ maxBytes` (на каждой части), размер части ≤ 5 МБ + запас, наличие файла части; подменяет `fileId` на `sha1($scope . '|' . fileId)` (scope = `"{customerId}:{orderId}:{purpose}"`), чтобы чужие части не смешивались; вызывает `UppyChunkGlobal::upload`; после склейки проверяет фактический размер (`> maxBytes` → удалить, `DesignerException` «Файл завеликий…»). `name` — исходное имя без пути (`basename`, обрезка до 255, управляющие символы убраны). Ошибки — `DesignerException`.
  - `chunk-upload.js`: `DesignUpload.send(file, {url, csrf, fields, onProgress}) → Promise<json>` — части по 5 МБ последовательно, `fileId` случайный, повтор части 3 раза при сетевой ошибке/5xx, отмена через `AbortController`; последний ответ JSON страницы.

- [ ] **Step 1: Тесты** (юнит, без HTTP; части кладутся во временные файлы и подставляются в `$_FILES`-подобный массив, `UppyChunkGlobal` работает на настоящем `/mnt/work/tmp/tmp_uppy` dev — тест удаляет всё созданное): `test_assembles_and_returns_path`, `test_rejects_bad_file_id_and_counts`, `test_rejects_declared_too_big`, `test_rejects_actual_too_big_after_assembly`, `test_scope_isolates_same_file_id`, `test_name_is_sanitized`.
- [ ] **Step 2–4:** падают → реализовать → проходят. **Step 5: Commit** — «Прийом файлів частинами».

### Task 3: Картинки превью — страницы PDF и водяной знак

**Files:**
- Create: `helpers/Design/PreviewImages.php`, `helpers/Design/fonts/` (TTF с кириллицей под OFL, напр. Manrope Regular — шрифт для знака; системные шрифты прода не гарантированы)
- Modify: `helpers/Design/Images.php` (сделать нужные приватные шаги переиспользуемыми: проверку, `toSrgb`, `orient`, `writeWebp` с качеством параметром — без смены поведения портфолио)
- Test: `tests/Design/PreviewImagesTest.php`

**Interfaces:**
- Produces:
  - `PreviewImages::make(string $src, string $dir, int $previewId): array{pages:int, type:string}` — тип по содержимому: JPG/PNG (проверки как у `Images`) или PDF (`%PDF-` + Imagick/gs); PDF: число страниц через ping, `> Settings::previewMaxPages()` → «Забагато сторінок: N. Максимум — 30. Розбийте на кілька прев'ю», 0 → «Файл пошкоджений…»; рендер постранично (`setResolution` ≈ 120 dpi до чтения, по одной странице, освобождать память); каждую страницу: sRGB, без метаданых, `{previewId}-p{n}-view.webp` (вписать в 2000×2000) и `-card.webp` (вписать в 800×800), качество 90, **водяной знак** на обоих: повторяющийся полупрозрачный текст «Майстерня дизайну» под углом по всей площади (шаг и размер — от размера картинки). Файлы 0644. При ошибке — удалить созданное.
  - `PreviewImages::WATERMARK = 'Майстерня дизайну'`.

- [ ] **Step 1: Тесты** — JPG и PNG → 1 страница, оба размера, есть знак (пиксели в «пустой» белой картинке после знака не все белые и меняются по площади); PDF 3 страницы (генерировать через Imagick) → 3×2 файла; `test_rejects_31_pages`, `test_rejects_fake_pdf`, `test_cmyk_pdf_to_srgb`; права 0644; **замер**: PDF 30 страниц A3 и JPG 8000×6000 — время в отчёт (цель < 25 с; если больше — снизить dpi и записать).
- [ ] **Step 2–4.** **Step 5: Commit** — «Прев'ю: сторінки PDF і водяний знак».

### Task 4: Превью — модель и сообщения

**Files:**
- Create: `helpers/Design/Previews.php`
- Modify: `helpers/Design/Messages.php` (`add`: параметр `?int $replyTo = null`, проверка «то же листування»; `list` отдаёт `preview_id`, `reply_to_id`), `helpers/Design/Chat.php` (элементы ленты: превью и цитата)
- Test: `tests/Design/PreviewsTest.php`, `tests/Design/MessagesTest.php`, `tests/Design/ChatTest.php`

**Interfaces:**
- Consumes: `PreviewImages::make`, `Paths::previews`, `Offers` (выбранная пропозиция), `Messages::add/notifyNew`.
- Produces:
  - `Previews::canWork(int $orderId, int $designerId): ?array` — заказ `IN_WORK`, его `chosen_offer_id` принадлежит дизайнеру, дизайнер `APPROVED`; иначе null.
  - `Previews::add(int $orderId, int $designerId, string $tmp, string $caption, ?int $basedOn): array{id:int, num:int, message_id:int}` — в транзакции с `FOR UPDATE` строки заказа: `num = MAX+1`, строка превью, сообщение дизайнера (`text` = подпись, может быть пустой, `preview_id`), затем `PreviewImages::make`; при ошибке — откат и удаление файлов; `based_on` — только неприхованная версия этого заказа. После коммита — `notifyNew` клиенту, `tmp` удаляется в любом случае.
  - `Previews::hide(int $previewId, int $designerId): void` — `hidden_at = NOW()`, файлы удаляются; повтор — no-op.
  - `Previews::forOrder(int $orderId): array<int, array{id, num, pages, hidden:bool, based_on_num:?int, caption:string, urls:list<array{view:string, card:string}>}>` — `urls` пусты для скрытых; URL — `\ImageGlobal::encode` только для существующих `*.webp`.
  - `Chat::messages` — у сообщений с превью поле `preview` (из `forOrder`), у ответов — `reply` `{id, author, text (до 120 символов), preview_num:?int, thumb:?string}`.

- [ ] **Step 1: Тесты** — `test_add_numbers_and_message`, `test_only_chosen_designer_in_work` (клиент, другой дизайнер, не `IN_WORK`, заблокированный), `test_failed_render_leaves_nothing`, `test_based_on_must_be_visible_same_order`, `test_hide_deletes_files_keeps_caption`, `test_reply_to_same_offer_only`, `test_chat_payload_has_preview_and_quote`, `test_notify_client_first_unread`.
- [ ] **Step 2–4.** **Step 5: Commit** — «Прев'ю: модель, підпис, «на основі», коментар із цитатою».

### Task 5: Макеты — модель

**Files:**
- Create: `helpers/Design/Layouts.php`
- Test: `tests/Design/LayoutsTest.php`

**Interfaces:**
- Consumes: `Paths::layouts`, `Previews::canWork`, `Settings`, `Messages::system/notifyNew`.
- Produces:
  - `Layouts::add(int $orderId, int $designerId, string $tmp, string $originalName): array{id:int, label:string, size:int}` — лимиты числа и суммы активных (`status = 1`) под `FOR UPDATE` заказа; `label` — имя без расширения (до 120); `rename(tmp → layouts/{orderId}/{id}.{ext})`, `chmod 0600`, `md5`; системное сообщение «Завантажено макет «{label}» · {размер}» (`layout_add`, `file_id`) + `notifyNew` клиенту.
  - `Layouts::replace(int $fileId, int $designerId, string $tmp, string $originalName): array{...}` — старый → `status = 2`, его файл удаляется, новый с тем же `label`; сообщение «Макет «{label}» замінено» (`layout_replace`).
  - `Layouts::remove(int $fileId, int $designerId): void` — `status = 3`, файл удаляется; «Макет «{label}» видалено» (`layout_delete`).
  - `Layouts::rename(int $fileId, int $designerId, string $label): void` — 1–120 символов, без сообщения.
  - `Layouts::forOrder(int $orderId): list<array{id, label, original_name, size, ext, created}>` — только `status = 1`; без путей и ссылок.
  - `Layouts::count(int $orderId): int` — для «чей ход».
  - Ошибки: «Макетів уже 20 — це максимум», «Разом не більше 5 ГБ на замовлення», доступ — «Макети може завантажувати лише обраний дизайнер».

- [ ] **Step 1: Тесты** — `test_add_moves_file_private_perms` (0600/0700, нет ссылок), `test_limits_count_and_total`, `test_replace_and_remove_delete_files_and_log`, `test_rename_rules`, `test_upload_access_matrix`, `test_for_order_hides_replaced_deleted`.
- [ ] **Step 2–4.** **Step 5: Commit** — «Макети: модель, ліміти, слід у стрічці».

### Task 6: Лента работы — превью, ответы, просмотр

**Files:**
- Create: `pages/order-upload.php` (приём частей: `purpose=preview|layout|replace`), `views/partials/preview-viewer.php`, `assets/js/workspace.js`
- Modify: `helpers/Design/Router.php`, `pages/offer-message-add.php` (`reply_to`), `views/partials/chat.php`, `assets/js/chat.js`, `assets/css/app.css`, `pages/order.php`, `pages/feed-order.php`, `tests/Design/RouterTest.php`
- Test: `tests/Design/WorkspaceHttpTest.php`

**Interfaces:**
- Produces:
  - `POST /orders/{id}/upload` — поля части (`fileId`, `chunkIndex`, `totalChunks`, `totalSize`, `originalName`), `purpose`, `csrf`; для `preview` на последней части — `caption`, `based_on`; для `replace` — `file_id`. Ответ: промежуточно `{done:false}`, в конце `{done:true, preview|layout}`; ошибки 4xx `{error}`. Доступ (`Previews::canWork`) и CSRF — на каждую часть.
  - `POST /orders/{id}/previews/{previewId}` `action=hide` → JSON.
  - Чат: превью-карточка (первая страница, `vN`, «N сторінок», подпись, «на основі vK», «Коментувати», «Відкрити»; скрытое — заглушка «Прев'ю приховано дизайнером · vN» с подписью); цитата над ответом; у дизайнера в поле ввода — кнопка «додати прев'ю» (файл + подпись + «на основі», прогресс через `chunk-upload.js`); «Коментувати» ставит цитату над полем.
  - Просмотр (макеты 6.5 «Перегляд прев'ю»): модалка на весь экран, листание страниц, «Порівняння версій» (две версии, выбор vN, на 390 — по очереди), «Коментувати vN», у дизайнера «Приховати».
  - Опрос `chat.js` догружает новые превью и цитаты (формат из `Chat::messages`).

- [ ] **Step 1: Тесты** — маршруты; HTTP (29 клиент, 25 дизайнер, выбранная пропозиция `IN_WORK` создаётся тестом): `test_designer_uploads_preview_in_chunks` (2 части), `test_client_cannot_upload`, `test_preview_card_and_quote_render`, `test_hide_preview`, `test_reply_to`, `test_hidden_preview_urls_absent`.
- [ ] **Step 2–4:** по макетам 6.5 (1440 и 390); скриншоты в `/home/dev-ai/notes/design-wolf-ua-screens/stage4/`. **Step 5: Commit** — «Робочий простір: прев'ю, відповіді, перегляд».

### Task 7: Макеты на странице, шапка заказа, «чей ход»

**Files:**
- Create: `views/partials/layouts-panel.php`, `pages/order-layout.php` (`POST /orders/{id}/layouts/{fileId}` `action=rename|delete`)
- Modify: `pages/order.php`, `pages/feed-order.php`, `views/order.php`, `views/feed-order.php`, `helpers/Design/OrderStatus.php` (+ `designerTurn(...)`), `helpers/Design/Router.php`, `assets/js/workspace.js`, `assets/css/app.css`, `pages/orders.php`, `pages/my-offers.php`
- Test: `tests/Design/LayoutsHttpTest.php`, `tests/Design/OrderStatusTest.php`

**Interfaces:**
- Consumes: `Layouts::*`, `Previews::forOrder`, `/orders/{id}/upload` (`purpose=layout|replace`).
- Produces:
  - Панель «Макети для друку» (макет 6.5): у дизайнера — перетаскивание/«Обрати файли», несколько файлов параллельно по одному запросу на часть, прогресс и «Скасувати», подпись с карандашом, «Замінити», «Видалити» (подтверждение); подсказка «До покупки можна замінити чи видалити файл. Клієнт бачить лише назву, розмір і підпис.»; у клиента — список (подпись, имя, размер) и «Купую» неактивна с «Незабаром».
  - Шапка заказа у дизайнера: «Ви отримаєте D грн · клієнт побачить P грн», «Термін до …», «Завантажити макети»; у клиента — статус и «Купую» «Незабаром».
  - `OrderStatus::designerTurn(int $lastClientMsgId, int $lastDesignerMsgId, int $previews, int $layouts): array{mine:bool, text:string}` — правила спеки (раздел «Чий хід» для дизайнера), тексты дословно. Клиентский `turn` получает фактическое число макетов. «Мої замовлення» / «Мої пропозиції» используют их же.

- [ ] **Step 1: Тесты** — `OrderStatusTest::test_designer_turn_rules`; HTTP: `test_upload_layout_and_client_sees_list`, `test_rename_replace_delete`, `test_layout_has_no_public_link` (в HTML/JSON нет `fastcdn` для макетов), `test_limits_messages`, `test_turn_texts`.
- [ ] **Step 2–4:** по макетам 6.5; скриншоты. **Step 5: Commit** — «Макети на сторінці, шапка замовлення, чий хід».

### Task 8: Финальная проверка

- [ ] `docs/STRUCTURE.md` — `design_previews`, `design_files`, пути и права, события `layout_*`, правило «чей ход» дизайнера.
- [ ] Полный `vendor/bin/phpunit` → PASS; замеры времени из задач 2–3 в отчёте; ничего нового в `cloud-links/ci` и `tmp_uppy`.
- [ ] Скриншоты 1440/390 всех новых состояний, сверка с макетами 6.5.
- [ ] Финальное ревью ветки; текст PR `/home/dev-ai/notes/design-stage4-pr.md`; порядок выкатки: `stage4.sql` → design; на проде папку `/mnt/work/source.wolf.ua/design` создаст php-fpm (проверить права после первого превью).
