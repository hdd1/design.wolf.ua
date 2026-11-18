# Майстерня дизайну — этап 2 «Дизайнеры». План реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** клиент wolf.ua подаёт заявку в дизайнеры с портфолио, заявку рассматривают в adminka, одобренный дизайнер ведёт профиль, вошедшие клиенты видят каталог дизайнеров.

**Architecture:** продолжение этапа 1 — PHP 8.1 без фреймворка, классы `Design\*` в `helpers/Design/`, страницы `pages/*.php` + шаблоны `views/*.php`, маршруты в `Router::ROUTES`. Картинки — в `Config::storageRoot()/portfolio/<designer_id>/`, уменьшенные WebP делает Imagick, отдаёт PHP-маршрут `/media/…` с проверкой доступа. Рассмотрение — страница в adminka (ветка `feature/shulaev`), логика — в тех же `Design\*`, adminka подключает их с диска, как календарь (`layout/cal2-common.php`, `pages/fns/cal2Controller.php`).

**Tech Stack:** PHP 8.1, MySQL (PDO через `Design\Sql`), Imagick 3.7 (ImageMagick 6.9, WebP), Bootstrap 5.3.3, Font Awesome 5 Pro, SortableJS 1.15.6 (локально в `assets/vendor/`), PHPUnit 9.6; adminka — Bootstrap 3 + jQuery.

**Spec:** `docs/superpowers/specs/2026-11-17-stage2-designers-design.md` (утверждён владельцем 2026-11-17).

Репозиторий: `/home/connect/design.wolf.ua`, ветка `feature/stage2` (от `master`). adminka: `/home/connect/adminka.wolf.ua`, ветка `feature/shulaev`.

## Global Constraints

- Таблицы — только `design_*`, `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`, комментарии на украинском.
- Код wolf.ua не меняем. `customers` — только чтение.
- Изменения БД — файлом `docs/sql/stage2.sql`; на dev и проде его запускает владелец. Исполнитель SQL сам не выполняет — останавливается и просит владельца.
- Тексты сайта — на украинском, adminka — на русском (как остальная adminka).
- Запросы к БД — только через `Design\Sql`.
- Каждый изменяющий POST — с CSRF (`Csrf::token($me)` / `Guard::csrfOk($me)`); AJAX-ответы — `Http::json`.
- Гость видит только главную; всё остальное — `Guard::customer()`.
- Города у дизайнера нет нигде. «Наших» дизайнеров никак не выделяем. Рейтинг и число заказов не показываем.
- Новые файлы в `/home/connect` — `chown connect:connect`, `chmod 777` (иначе FTP владельца получает 553).
- adminka: коммитить только свои файлы по имени (в рабочей копии чужие правки: `pages/fns/_addManager.php`, `pages/tribunal-my.php`, `tribunal-ai/CLAUDE.md`, удалённые `vendor/gufy/*`). Своих проверок прав в adminka не делаем — доступ ограничивает владелец системой доступов (`Page::verifyAccess`).
- Git: только локальные коммиты, пуш — по команде владельца.
- Тесты не шлют настоящих сообщений: в `phpunit.xml` `DESIGN_NOTIFY=off`.

## Review Focus

1. **Чужая работа или профиль по прямой ссылке** — `/media/<id>/<work>-view` не одобренного дизайнера, скрытая работа, гость → 404; сам дизайнер видит всё своё. Тест — задача 5 (`test_media_access_matrix`).
2. **Файл, который только называется картинкой** (текст в `.jpg`, GIF, PDF, 48 МБ, 15 000 px по стороне) → понятная ошибка в строке файла, запись в `design_portfolio` не остаётся, на диске ничего. Тест — задача 2 (`test_rejects_*`) и задача 4 (`test_failed_store_leaves_no_row`).
3. **CMYK JPEG и фото с поворотом по EXIF** — превью в sRGB и стоит правильно. Тест — задача 2 (`test_cmyk_becomes_srgb`, `test_exif_orientation_applied`).
4. **Двойное «Надіслати заявку» / «Схвалити» двумя сотрудниками** — второй вызов не ошибка для дизайнера (submit — no-op) и честная ошибка для второго сотрудника («Статус заявки вже змінився»), SMS — одно. Тест — задача 3 (`test_submit_twice_sends_one_notice`, `test_review_race`).
5. **Апостроф и HTML в имени, «про себе», комментарии отказа** («Олег О'Ніл <b>») — сохраняются как есть, выводятся экранированными на сайте и в adminka. Тест — задача 3 (`test_text_kept_verbatim`) и задача 7 (`test_public_profile_escapes_html`).

---

### Task 1: SQL этапа 2, статусы, настройки

**Files:**
- Create: `docs/sql/stage2.sql`, `helpers/Design/DesignerStatus.php`, `helpers/Design/DesignerException.php`
- Modify: `tests/Design/SchemaTest.php`, `phpunit.xml` (env `DESIGN_NOTIFY=off`)
- Test: `tests/Design/SchemaTest.php`

**Interfaces:**
- Produces:
  - таблицы `design_designers`, `design_portfolio` (поля — спека, раздел «Дані»), настройка `applications_notify_user_id = '1'`.
  - `Design\DesignerStatus::DRAFT = 0, PENDING = 1, APPROVED = 2, REJECTED = 3, BLOCKED = 4`; `label(int): string` — «Чернетка», «На розгляді», «Схвалено», «Відхилено», «Заблоковано».
  - `Design\DesignerException extends \RuntimeException` — сообщение показывается пользователю как есть.

- [ ] **Step 1: Тест схемы**

```php
public function test_stage2_tables_and_setting(): void
{
    foreach (['design_designers', 'design_portfolio'] as $t) { /* utf8mb4, как test_tables_exist_with_utf8mb4 */ }
    $this->assertSame('1', Sql::value("SELECT value FROM design_settings WHERE `key` = 'applications_notify_user_id'"));
    $this->assertSame('5.00', Sql::value("SELECT column_default FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'design_designers' AND column_name = 'rating'"));
}
```

- [ ] **Step 2: Запустить — падает** — `vendor/bin/phpunit --filter SchemaTest` → FAIL (таблиц нет).

- [ ] **Step 3: Написать `docs/sql/stage2.sql`**

Повторный запуск безопасен (`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`). `design_designers`: PK `customer_id int`, `status tinyint NOT NULL DEFAULT 0`, `name varchar(80)`, `about text`, `link varchar(255) DEFAULT ''`, `photo varchar(32) DEFAULT ''`, `rating decimal(3,2) NOT NULL DEFAULT 5.00`, `reject_comment text`, `created datetime DEFAULT CURRENT_TIMESTAMP`, `submitted_at`, `reviewed_at` datetime NULL, `reviewed_by int NULL`; индекс `(status, submitted_at)`. `design_portfolio`: `id int unsigned AI`, `designer_id int`, `title varchar(120) DEFAULT ''`, `file varchar(32)` (имя оригинала, напр. `17.jpg`), `width`, `height` int, `pos int DEFAULT 0`, `shw tinyint(1) DEFAULT 1`, `created`; индекс `(designer_id, shw, pos)`. `DesignerStatus`, `DesignerException`; в `phpunit.xml` `<env name="DESIGN_NOTIFY" value="off"/>`.

- [ ] **Step 4: Попросить владельца выполнить `docs/sql/stage2.sql` на dev.** Скопировать в `/home/dev-ai/notes/design-stage2.sql`, дождаться подтверждения.

- [ ] **Step 5: Запустить — проходит** — `vendor/bin/phpunit --filter SchemaTest` → PASS.

- [ ] **Step 6: Commit** — `git add docs/sql/stage2.sql helpers/Design/DesignerStatus.php helpers/Design/DesignerException.php tests/Design/SchemaTest.php phpunit.xml && git commit -m "SQL етапу 2: design_designers, design_portfolio"`

### Task 2: Картинки — проверка и уменьшенные копии

**Files:**
- Create: `helpers/Design/Images.php`
- Test: `tests/Design/ImagesTest.php` (картинки генерирует сам тест через Imagick во временной папке)

**Interfaces:**
- Produces:
  - `Images::MAX_BYTES = 20 * 1024 * 1024`, `Images::MAX_SIDE = 12000`.
  - `Images::store(string $src, string $dir, string $base): array{width:int, height:int, file:string}` — проверяет, кладёт оригинал как есть в `$dir/$base.jpg|png` (`file` — это имя), делает `$dir/$base-card.webp` (обрезка по центру 600×480) и `$dir/$base-view.webp` (вписать в 2000×2000, не увеличивать). `width/height` — оригинала после поворота по EXIF.
  - `Images::avatar(string $src, string $dir): string` — те же проверки, `avatar-<8 hex>.webp` 320×320 по центру; возвращает имя файла.
  - Ошибки — `DesignerException`: «Файл завеликий: 48 МБ. Максимум — 20 МБ», «Підходять лише JPG або PNG», «Файл пошкоджений або це не картинка», «Зображення завелике: до 12 000 px по стороні».

- [ ] **Step 1: Тесты**

`test_png_and_jpg_make_card_and_view` (3000×2000 JPG → `card` 600×480, `view` 2000×1333, оригинал байт-в-байт); `test_small_image_not_upscaled` (800×600 → view 800×600); `test_rejects_text_named_jpg`, `test_rejects_gif`, `test_rejects_too_big_bytes` (файл > MAX_BYTES — проверка размера до Imagick), `test_rejects_too_many_pixels` (15000×10 PNG); после каждого отказа папка `$dir` пуста; `test_cmyk_becomes_srgb` (CMYK JPEG → `getImageColorspace()` у webp = `COLORSPACE_SRGB`); `test_exif_orientation_applied` (JPEG 400×200 с `ORIENTATION_RIGHTTOP` → view 200×400); `test_metadata_stripped` (в webp нет EXIF); `test_avatar_square`.

- [ ] **Step 2: Запустить — падают** (`Images` нет).

- [ ] **Step 3: Реализовать `Images`.** Размер — `filesize()` до открытия; `pingImage` → формат `JPEG|PNG` и размеры до полной загрузки; лимиты `Imagick::setResourceLimit` (память 256 МБ, map 512 МБ) — 20-мегабайтный JPEG не должен уронить fpm с `memory_limit` 512M. Порядок для копий: `autoOrient` (Imagick 3.7 — `autoOrientImage` или ручной `ORIENTATION` → `rotateImage`), CMYK → `transformImageColorspace(COLORSPACE_SRGB)` (если есть встроенный ICC — сначала профиль, затем sRGB), `stripImage`, WebP quality 82. При любой ошибке — удалить уже записанные `$base*`.

- [ ] **Step 4: Запустить — проходят** — `vendor/bin/phpunit --filter ImagesTest` → PASS.

- [ ] **Step 5: Commit** — `git commit -m "Картинки портфоліо: перевірка, WebP, CMYK і EXIF"` (только `Images.php`, `ImagesTest.php`).

### Task 3: Дизайнеры — заявка, рассмотрение, уведомления

**Files:**
- Create: `helpers/Design/Designers.php`, `helpers/Design/Notify.php`
- Modify: `helpers/Design/Config.php` (+`siteUrl()`)
- Test: `tests/Design/DesignersTest.php` (клиенты — фиктивные `customer_id` 999999101…; tearDown удаляет их строки)

**Interfaces:**
- Consumes: `DesignerStatus`, `DesignerException`, `Customers::header()`. Работы считает сам (`SELECT COUNT(*) FROM design_portfolio`), тест вставляет строки `design_portfolio` напрямую — `Portfolio` появится в задаче 4.
- Produces:
  - `Config::siteUrl(): string` — `https://dev.design.wolf.ua` если `HTTP_HOST` начинается с `dev.`, иначе `https://design.wolf.ua`.
  - `Notify::send(array $params): void` — `CalcApiGlobal::post('sms/add', $params)`; при `getenv('DESIGN_NOTIFY') === 'off'` только дописывает в `Notify::$sent` (public static array). Сбой api2 не ломает действие (лог `error_log`).
  - `Designers::NAME_MAX = 80, ABOUT_MAX = 2000, COMMENT_MAX = 1000, APPLY_MIN = 3, APPLY_MAX = 10`.
  - `Designers::get(int $customerId): ?array` — строка `design_designers` с `status` int.
  - `Designers::status(int $customerId): ?int` — null, если не дизайнер.
  - `Designers::start(int $customerId): array` — существующая строка или новая `DRAFT` с `name` из `Customers::header()` (заглушку «Клієнт» не подставлять — пустое имя).
  - `Designers::saveProfile(int $customerId, string $name, string $about, string $link): void` — можно в `DRAFT`, `REJECTED`, `APPROVED`; иначе «Профіль зараз не можна змінити». Ссылка: пусто — ок; без схемы — дописать `https://`; только http/https и валидный URL, иначе «Посилання має бути адресою сайту, напр. behance.net/імʼя».
  - `Designers::submit(int $customerId): void` — из `DRAFT`/`REJECTED`: имя и «про себе» не пустые («Вкажіть імʼя», «Розкажіть про себе»), работ от `APPLY_MIN` до `APPLY_MAX` («Додайте щонайменше 3 роботи», «У заявці — не більше 10 робіт»); → `PENDING`, `submitted_at = NOW()`, `reject_comment = ''`, `Notify` владельцу. Из `PENDING` — ничего не делает. Иначе — «Заявку зараз не можна надіслати».
  - `Designers::approve(int $customerId, int $userId): void` (`PENDING`→`APPROVED`), `reject(int $customerId, int $userId, string $comment)` (`PENDING`→`REJECTED`, пустой комментарий — «Напишіть коментар для дизайнера»), `block(int $customerId, int $userId)` (любой, кроме `BLOCKED`), `unblock(int $customerId, int $userId)` (`BLOCKED`→`APPROVED`). Атомарно: `UPDATE … WHERE status IN (…)`; 0 строк — «Статус заявки вже змінився — оновіть сторінку». Пишут `reviewed_at`, `reviewed_by`. `approve`/`reject` шлют дизайнеру.
  - `Designers::forAdmin(int $status): list<array>` — без `DRAFT`, с числом работ, новые сверху по `submitted_at`.
  - `Designers::catalog(string $q, int $page): array{items:list<array{customer_id:int,name:string,about:string,photo:string,works:list<int>}>, total:int, pages:int}` — только `APPROVED`; `PER_PAGE = 24`; поиск `name LIKE` с экранированием `%`/`_`; порядок `MD5(CONCAT(customer_id, CURDATE()))` (все равны, порядок меняется раз в день); `works` — id первых 3 видимых работ.
  - `Designers::publicProfile(int $customerId): ?array` — только `APPROVED`, с видимыми работами; иначе null.

Тексты сообщений (`msg`; `msgSMS` = тот же текст):
- владельцу, `user_id` = `design_settings.applications_notify_user_id`: «Майстерня дизайну: нова заявка дизайнера — {name}, {d.m H:i}. Розглянути: https://{dev.}adminka.wolf.ua/design-applications/»
- дизайнеру, `customer_id`: «Майстерня дизайну: вашу заявку дизайнера схвалено ({d.m H:i}). Заповніть профіль: {siteUrl}/designer/?approved=1» и «Майстерня дизайну: заявку дизайнера відхилено ({d.m H:i}). Коментар і що виправити: {siteUrl}/designer/»

Время в тексте — обязательно: api2 отбрасывает повтор того же текста тому же адресату.

- [ ] **Step 1: Тесты** — `test_start_prefills_name_from_customer` (на dev клиент 29), `test_submit_requires_about_and_3_to_10_works`, `test_submit_twice_sends_one_notice`, `test_reject_needs_comment_and_resubmit_clears_it`, `test_review_race` (второй `approve` → исключение «Статус заявки вже змінився…»), `test_block_from_any_status_and_blocked_cannot_submit_or_edit`, `test_unblock_returns_to_approved`, `test_link_normalized_and_javascript_rejected`, `test_text_kept_verbatim` («Олег О'Ніл <b>»), `test_catalog_only_approved_search_escapes_wildcards` (поиск `%` не находит всех), `test_public_profile_null_unless_approved`.

- [ ] **Step 2: Запустить — падают.**

- [ ] **Step 3: Реализовать `Config::siteUrl`, `Notify`, `Designers`.**

- [ ] **Step 4: Запустить — проходят** — `vendor/bin/phpunit --filter DesignersTest` → PASS.

- [ ] **Step 5: Commit** — `git commit -m "Дизайнери: заявка, розгляд, сповіщення"`.

### Task 4: Портфолио

**Files:**
- Create: `helpers/Design/Portfolio.php`
- Test: `tests/Design/PortfolioTest.php` (хранилище — временная папка через `putenv('DESIGN_STORAGE_ROOT=…')`)

**Interfaces:**
- Consumes: `Images::store`, `Designers::get`, `DesignerStatus`.
- Produces:
  - `Portfolio::MAX = 30, TITLE_MAX = 120` (лимит заявки — `Designers::APPLY_MAX`).
  - `Portfolio::dir(int $designerId): string` — `Config::storageRoot() . "/portfolio/$designerId"` (создаёт `0770`).
  - `Portfolio::add(int $designerId, string $tmp): array{id:int}` — можно в `DRAFT`, `REJECTED` (лимит `Designers::APPLY_MAX`) и `APPROVED` (лимит `MAX`); «Уже {N} робіт — це максимум»; в `PENDING`/`BLOCKED` — «Зараз роботи не можна змінювати». Сначала строка (ради `id`, `pos` = max+1), затем `Images::store(…, (string)$id)`; при исключении строку удалить и пробросить.
  - `Portfolio::all(int $designerId): list<array{id:int,title:string,shw:bool,pos:int,width:int,height:int}>`, `visible(int $designerId): list<…>` (только `shw`), `count(int $designerId): int`.
  - `Portfolio::remove(int $designerId, int $workId)`, `setShw(int $designerId, int $workId, bool $on)`, `setTitle(int $designerId, int $workId, string $title)`, `reorder(int $designerId, list<int> $ids)` — те же ограничения по статусу; чужой `workId` — «Роботу не знайдено»; `reorder` принимает только полный набор id дизайнера.

- [ ] **Step 1: Тесты** — `test_add_stores_files_and_row`, `test_failed_store_leaves_no_row`, `test_apply_limit_10_and_approved_limit_30`, `test_pending_and_blocked_are_read_only`, `test_remove_deletes_files`, `test_foreign_work_rejected`, `test_reorder_needs_full_set`, `test_visible_skips_hidden`.

- [ ] **Step 2: Запустить — падают.**

- [ ] **Step 3: Реализовать `Portfolio`.**

- [ ] **Step 4: Запустить весь набор** — `vendor/bin/phpunit` → PASS.

- [ ] **Step 5: Commit** — `git commit -m "Портфоліо дизайнера"`.

### Task 5: Отдача картинок `/media`

**Files:**
- Create: `helpers/Design/Media.php`, `pages/media.php`
- Modify: `helpers/Design/Router.php`, `tests/Design/RouterTest.php`
- Test: `tests/Design/MediaTest.php`

**Interfaces:**
- Consumes: `Designers::get`, `Portfolio::dir`.
- Produces:
  - маршрут `GET /media/{designer}/{name}` → `media`, `name` = `{workId}-card` | `{workId}-view` | `avatar-{8 hex}` (без расширения — nginx-локация статики `\.(jpg|png…)$` его не перехватит).
  - `Media::url(int $designerId, string $name): string` — относительный `/media/…`.
  - `Media::signedUrl(int $designerId, string $name, int $ttl = 3600): string` — абсолютный (`Config::siteUrl()`), `?exp=&sig=`, `sig = hash_hmac('sha256', "$designerId/$name/$exp", 'media|' . Config::csrfSecret())`. Для adminka.
  - `Media::canView(int $viewer, int $designerId, string $name, array $query): bool` — владелец — всегда; верная непросроченная подпись — да; вошедший + дизайнер `APPROVED` + (аватар или работа `shw = 1`) — да; иначе нет.
  - `pages/media.php` — нет доступа или файла → `Http::notFound()`; иначе `Content-Type: image/webp`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, max-age=86400` (вместо `noStore`), `readfile`.

- [ ] **Step 1: Тесты** — `RouterTest::test_media_route` (и `/media/1/2-orig` → null); `MediaTest::test_media_access_matrix`: владелец/гость/другой клиент × статус `PENDING`/`APPROVED`/`BLOCKED` × работа видимая/скрытая; `test_signed_url_valid_expired_tampered`.

- [ ] **Step 2: Запустить — падают.**

- [ ] **Step 3: Реализовать.** В `index.php` `Http::noStore()` вызывается до страницы — в `pages/media.php` заголовок кэша перезаписать.

- [ ] **Step 4: Запустить — проходят.**

- [ ] **Step 5: Commit** — `git commit -m "Роздача картинок портфоліо з перевіркою доступу"`.

### Task 6: Страницы дизайнера — заявка и «Мій профіль»

**Files:**
- Create: `pages/designer.php`, `pages/designer-save.php`, `pages/designer-work-add.php`, `pages/designer-work.php`, `pages/designer-works-order.php`, `pages/designer-photo.php`; `views/designer-apply.php`, `views/designer-state.php`, `views/designer-profile.php`, `views/partials/works-editor.php`; `assets/js/designer.js`; `assets/vendor/sortablejs-1.15.6/Sortable.min.js`
- Modify: `helpers/Design/Router.php`, `views/layout.php`, `assets/css/app.css`, `tests/Design/RouterTest.php`, `tests/Design/PagesHttpTest.php`

**Interfaces:**
- Consumes: `Designers::*`, `Portfolio::*`, `Images::avatar`, `Media::url`.
- Produces маршруты:
  - `GET /designer/` → `designer`: `DRAFT`/`REJECTED` — форма заявки (макет 6.9 «Заявка дизайнера»; у `REJECTED` сверху комментарий, кнопка «Оновити і подати знову»); `PENDING` — «на розгляді»; `BLOCKED` — «заблоковано», без кнопок; `APPROVED` — «Мій профіль» (макет 6.9), а с `?approved=1` — карточка «схвалено» с кнопкой «Заповнити профіль» → `/designer/`. Первый заход — `Designers::start()`.
  - `POST /designer` → `designer-save`: `action=save|submit`, поля `name`, `about`, `link`; ошибка — та же форма с введённым и текстом ошибки; успех — редирект на `/designer/`.
  - `POST /designer/works` → `designer-work-add` (multipart `file`, `csrf`) → JSON `{id, card, view, count}` или `{error}` с кодом 422. Файл больше лимита сервера (`UPLOAD_ERR_INI_SIZE`) — та же фраза «Файл завеликий…».
  - `POST /designer/works/{id}` → `designer-work`: `action=delete|shw|title` → JSON.
  - `POST /designer/works/order` → `designer-works-order`: `ids[]` → JSON.
  - `POST /designer/photo` → `designer-photo` → JSON `{photo}`.
- Шапка (`layout.php`): пункт «Дизайнери» → `/designers/` (активный, ключ `designers`); последний пункт — «Мій профіль» для `APPROVED`, иначе «Стати дизайнером» → `/designer/` (ключ `designer`).

- [ ] **Step 1: Тесты** — `RouterTest::test_designer_routes` (все маршруты выше, `/designer/works/order` не путается с `/designer/works/{id}`); `PagesHttpTest`: `test_designer_page_requires_login` (401), `test_apply_form_prefilled_and_has_no_city`, `test_upload_rejects_text_file` (422 и сообщение), `test_upload_ok_returns_media_urls` (тестовый клиент — `DESIGN_TEST_CUSTOMER_ID`; tearDown удаляет его `design_designers`/`design_portfolio` и папку); `submit` по HTTP не вызывать — он шлёт `sms/add` (покрыт в задаче 3).

- [ ] **Step 2: Запустить — падают.**

- [ ] **Step 3: Реализовать страницы и шаблоны по макетам 6.9 (1440 и 390).** `designer.js`: загрузка каждого файла своим `XMLHttpRequest` с `upload.onprogress` («72%»), до 3 параллельно; ошибка — красная строка файла с корзиной (макет); счётчик «Роботи для портфоліо · N з 10»; кнопка «Надіслати заявку» неактивна, пока работ < 3. В профиле — SortableJS (`handle`), после перестановки `POST /designer/works/order`; глаз — `shw`; корзина — подтверждение и `delete`; название — правка по месту, сохранение по blur. CSRF — из `data-csrf` у формы. «Як бачать клієнти» → `/designers/{me}/` (только у `APPROVED`).

- [ ] **Step 4: Запустить** — `vendor/bin/phpunit` → PASS; глазами на `https://dev.design.wolf.ua/designer/` под клиентом 29 (desktop 1440 и 390): загрузка, ошибка большого файла, перестановка, скрытие.

- [ ] **Step 5: Commit** — `git commit -m "Сторінки дизайнера: заявка і «Мій профіль»"` (`chown connect:connect`, `chmod 777` новых файлов до коммита).

### Task 7: Каталог и профиль для клиентов

**Files:**
- Create: `pages/designers.php`, `pages/designer-public.php`, `views/designers.php`, `views/designer-public.php`
- Modify: `helpers/Design/Router.php`, `tests/Design/RouterTest.php`, `tests/Design/PagesHttpTest.php`, `assets/css/app.css`

**Interfaces:**
- Consumes: `Designers::catalog`, `Designers::publicProfile`, `Media::url`.
- Produces: `GET /designers/` → `designers` (`?q=`, `?page=`), `GET /designers/{id}/` → `designer-public`.

- [ ] **Step 1: Тесты** — `RouterTest::test_catalog_routes`; `PagesHttpTest`: `test_catalog_requires_login`, `test_catalog_shows_approved_only`, `test_unapproved_profile_is_404`, `test_public_profile_escapes_html`.

- [ ] **Step 2: Запустить — падают.**

- [ ] **Step 3: Реализовать по макету 6.8** (каталог, профиль, увеличение работы — Bootstrap modal с `-view`). Без рейтинга и «N замовлень». Пустой результат поиска — «Нікого не знайшли. Спробуйте інше імʼя». Аватар — фото или инициалы из `name`. Кнопок «Замовити у…» из макета профиля нет (это этап 3).

- [ ] **Step 4: Запустить** — `vendor/bin/phpunit` → PASS; глазами 1440 и 390.

- [ ] **Step 5: Commit** — `git commit -m "Каталог і профіль дизайнера для клієнтів"`.

### Task 8: adminka — «Майстерня дизайну → Заявки дизайнерів»

**Files (в `/home/connect/adminka.wolf.ua`, ветка `feature/shulaev`):**
- Create: `layout/design-common.php`, `pages/design-applications.php`, `pages/fns/designController.php`
- Modify: `layout/top-foot/sidebar.php` (новая группа перед «Бухгалтерия»)

**Interfaces:**
- Consumes: `Designers::forAdmin/get/approve/reject/block/unblock`, `Portfolio::all`, `Media::signedUrl`, `DesignerStatus::label`, `DesignerException`.
- Produces:
  - `layout/design-common.php` — `require_once '/home/connect/design.wolf.ua/helpers/Design/autoload.php'`, `CdnConfig` (как `cal2-common.php`); JS `designPost(data, ok)` → `action: 'designController'`, ошибка — `alert(msg)`.
  - `pages/fns/designController.php` — `subAction`: `approve`, `reject` (`comment`), `block`, `unblock`; `id` — `customer_id`; `$user->id` — в `reviewed_by`; ответ `{res: true}` / `{res: false, msg}` (`DesignerException` → `msg`).
  - Меню: `sideBarGroup('Майстерня дизайну', 'far fa-paint-brush')` → `sideBarMenu('Заявки дизайнерів', '', 'design-applications')`.

- [ ] **Step 1: Страница.** Вкладки статусов «На розгляді» (по умолчанию) / «Схвалені» / «Відхилені» / «Заблоковані» с количеством; таблица: имя, дата подачи, работ, статус. `?id=` — карточка: имя, «про себе», ссылка (`rel="noopener nofollow"`), работы — `<img src="<?= Media::signedUrl(…, "$id-view") ?>">` в сетке, комментарий прошлого отказа. Кнопки по статусу: `PENDING` — «Схвалити», «Відхилити…» (модалка с обязательным комментарием, подпись «Коментар побачить дизайнер»); `APPROVED` — «Заблокувати»; `BLOCKED` — «Розблокувати»; `REJECTED`/`PENDING` — тоже «Заблокувати». Всё выводимое — через `htmlspecialchars`.

- [ ] **Step 2: Проверить на dev** — `https://dev.adminka.wolf.ua/design-applications/`: заявка тестового дизайнера (созданная по задаче 6) видна, картинки грузятся (подпись читается из `csrf.secret` — у fpm adminka должны быть права на `design_storage`; если нет — остановиться и сообщить владельцу, не менять права самому), одобрение/отказ/блок меняют статус, в `n_smser` на dev появляются сообщения (`SELECT … ORDER BY id DESC LIMIT 3`).

- [ ] **Step 3: Commit в adminka** — только свои файлы по имени:

```bash
cd /home/connect/adminka.wolf.ua && git add layout/design-common.php pages/design-applications.php pages/fns/designController.php layout/top-foot/sidebar.php
git commit -m "Майстерня дизайну: заявки дизайнерів"
```

Перед `git add layout/top-foot/sidebar.php` — `git diff layout/top-foot/sidebar.php`: в нём только наша группа.

### Task 9: Финальная проверка и выкатка

**Files:**
- Modify: `docs/STRUCTURE.md` (раздел «Дизайнери» — как в спеке, без `city`), `CLAUDE.md` при необходимости
- Create: `/home/dev-ai/notes/design-stage2-pr.md` (текст PR), `/home/dev-ai/notes/design-stage2.sql` (копия для владельца)

- [ ] **Step 1:** `vendor/bin/phpunit` (все группы) → PASS; `php -l` по всем изменённым файлам adminka.
- [ ] **Step 2:** скриншоты 1440 и 390 всех новых экранов → `/home/dev-ai/notes/design-wolf-ua-screens/stage2/`, сверка с макетами 6.8/6.9 (город убран).
- [ ] **Step 3:** ревью всей ветки `feature/stage2` и коммита adminka свежим ревьюером.
- [ ] **Step 4:** текст PR и порядок выкатки для владельца: `stage2.sql` на проде → merge `feature/stage2` → adminka (`feature/shulaev`) → в системе доступов adminka открыть `design-applications` нужным сотрудникам. Пуш — только по команде.
