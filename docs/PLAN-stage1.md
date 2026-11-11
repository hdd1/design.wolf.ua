# Майстерня дизайну (design.wolf.ua) — дорожная карта и план этапа 1

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Этап 1 — вошедший клиент wolf.ua выбирает продукт дизайна, заполняет бриф, сохраняет черновик и публикует его, видит список своих заказов.

**Architecture:** Новый отдельный проект `/home/connect/design.wolf.ua` по образцу calendar v2: `index.php` → `Design\Router` → `pages/*.php` → `views/*.php`. Свой namespace `Design\` в `helpers/Design/`, общие хелперы cdn.wolf.ua (`CdnConfig`) через автозагрузку. Данные — свои таблицы `design_*` в общей БД `w2p` через PDO (`Design\Sql`). Вход — кука `wolfid`, как `Cal2\Auth`.

**Tech Stack:** PHP 8.1, PDO MySQL 8.0, PHPUnit 9.6, nginx + php8.1-fpm на dev, Bootstrap 5 (ванильный, свои переопределения) + Font Awesome 5 Pro, без React/Vue.

**Spec:** `/home/dev-ai/notes/design-wolf-ua-concept.md` (v2.1) и `/home/dev-ai/notes/design-wolf-ua-structure.md`.

## Дорожная карта

Каждый этап получает свой план и сам по себе даёт рабочий результат.

| Этап | Что появляется | Зависит от |
|---|---|---|
| **1. Каркас и бриф** (этот план) | Проект, вход, продукты, бриф: черновик → публикация, «Мої замовлення» | — |
| 2. Дизайнеры | Заявка с портфолио, одобрение (пока CLI-скриптом), список дизайнеров для вошедших | 1 |
| 3. Предложения | Лента брифов для дизайнеров, «Запропонувати себе» (D, k, P, дата), отзыв, переписка по предложению, выбор, режим «одразу обрати» (да/нет), уведомления `sms/add`, крон «24 ч без предложений» | 2 |
| 4. Рабочее пространство | Лента с превью (`/mnt/work`), ответы, скрытие превью, загрузка макетов (архив на `/mnt/cloud`), «ще не закінчили» | 3 |
| 5. Покупка | «Купую» с баланса: атомарное движение денег, `design_purchases`, макеты в облако клиента, доплата, жалоба | 4 + результат проверки симлинков на проде |
| 6. WayForPay и счёт | Оплата картой, счёт ФОП/ТОВ | 5 + бухгалтер |
| 7. Админка | В adminka: продукты и брифы, заявки дизайнеров, просмотр заказов и переписок, жалобы, настройки | по мере надобности |
| Потом | Менеджеры (`_am`), фильтры каталога, отзывы и рейтинг, пометки на картинке, «дизайнер молчит» | — |

## Global Constraints

- Таблицы — только с префиксом `design_`, `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`, комментарии на украинском.
- Код wolf.ua не меняем. Из общих таблиц на этапе 1 только читаем `customers` (`id`, `customer_pass`, имя).
- Изменения БД — SQL-файлом в `docs/sql/`; на dev и проде его запускает владелец.
- Все тексты интерфейса — на украинском. Название в шапке — «Майстерня дизайну».
- Деньги (с этапа 3) — целые гривны: `transaction.payment_done` — `decimal(7,0)`.
- Запросы к БД — только через `Design\Sql` (PDO, подготовленные запросы). cdn `Db::insert` не использовать: он ломается на апострофе («м'ята»).
- Каждый изменяющий POST — с CSRF-токеном.
- Гость не видит ничего, кроме главной: остальные страницы требуют входа.
- Git: коммиты в локальный репозиторий, пуш — только по команде владельца.

## Review Focus

1. **Чужой заказ по прямой ссылке** `/orders/{id}/` → 404, как будто заказа нет (не 403: не выдаём, что номер существует). Тест — в задаче 4 (`test_get_foreign_order_is_null`) и задаче 5 (`test_foreign_order_page_is_404`).
2. **Апостроф и HTML в ответах брифа** («м'ята <b>жирно</b>») сохраняются как есть и показываются экранированными. Тест — задача 4 (`test_answers_keep_apostrophe_and_html`) и задача 5 (`test_order_page_escapes_html`).
3. **Владелец поменял вопросы продукта после создания брифа** → старый бриф показывает старые вопросы (снимок). Тест — задача 4 (`test_snapshot_survives_question_change`).
4. **Двойное нажатие «Опублікувати»** → второй вызов не ошибка и ничего не меняет (`published_at` прежний). Тест — задача 4 (`test_publish_twice_is_noop`). Кнопки формы блокируются на отправке (задача 5).
5. **Скрытый или несуществующий продукт** в адресе формы или в POST → 404 / отказ, черновик не создаётся. Тест — задача 3 (`test_hidden_product_is_null`) и задача 5 (`test_hidden_product_form_is_404`).

---

### Task 1: Каркас проекта

**Files:**
- Create: `/home/connect/design.wolf.ua/` — `index.php`, `composer.json`, `phpunit.xml`, `.gitignore`, `CLAUDE.md`
- Create: `helpers/Design/autoload.php`, `bootstrap.php`, `Config.php`, `Sql.php`, `Http.php`, `View.php`, `Router.php`, `Auth.php`, `Csrf.php`
- Create: `views/layout.php`, `views/404.php`, `views/home.php`, `pages/home.php`
- Test: `tests/bootstrap.php`, `tests/Design/AuthTest.php`, `tests/Design/RouterTest.php`, `tests/Design/CsrfTest.php`

**Interfaces:**
- Produces:
  - `Design\Sql::all(string $sql, array $params = []): array`, `one(): ?array`, `value(): mixed`, `exec(): int`, `insert(string $table, array $row): int`, `pdo(): \PDO` — как `Cal2\Sql`.
  - `Design\Auth::customerId(?string $wolfid, string $userAgent, callable $passwordOf): int`, `Auth::current(): int` — как `Cal2\Auth`.
  - `Design\Http::json(array $data, int $code = 200): never`, `redirect(string $url, int $code = 302): never`, `notFound(): never`, `noStore(): void`.
  - `Design\View::render(string $name, array $vars = []): void`, `fetch(): string`, `e(?string $s): string`, `asset(string $path): string`.
  - `Design\Router::match(string $method, string $path): ?array{page:string, params:array<string,string>}`.
  - `Design\Csrf::token(int $customerId, ?int $hourTs = null): string`, `Csrf::check(int $customerId, string $token): bool`.
  - `Design\Config::storageRoot(): string` (env `DESIGN_STORAGE_ROOT`, по умолчанию `/home/connect/design_storage`), `Config::csrfSecret(): string` (env `DESIGN_CSRF_SECRET` или файл `csrf.secret` в хранилище — как `Cal2\Config::csrfSecret`), `Config::loginUrl(string $back): string`.

- [ ] **Step 1: Создать папку и git**

```bash
mkdir -p /home/connect/design.wolf.ua && cd /home/connect/design.wolf.ua && git init -b master
git remote add origin git@github.com:hdd1/design.wolf.ua.git
```
`CLAUDE.md` — та же ссылка на `wiki/wolf-ua/AGENTS.md`, что у calendar. `.gitignore`: `vendor/`, `.phpunit.result.cache`. `composer.json` и `phpunit.xml` — как у calendar, testsuite `Design` → `tests/Design`.

- [ ] **Step 2: Написать падающие тесты**

`AuthTest` — те же 4 теста, что `calendar.wolf.ua/tests/Cal2/AuthTest.php`, с `Design\Auth`.

```php
final class RouterTest extends TestCase
{
    public function test_routes(): void
    {
        $this->assertSame('home', Router::match('GET', '/')['page']);
        $this->assertSame('brief-products', Router::match('GET', '/brief/')['page']);
        $this->assertSame(['page' => 'brief-form', 'params' => ['product' => '12']], Router::match('GET', '/brief/12/'));
        $this->assertSame('brief-create', Router::match('POST', '/brief')['page']);
        $this->assertSame('orders', Router::match('GET', '/orders/')['page']);
        $this->assertSame(['page' => 'order', 'params' => ['id' => '345']], Router::match('GET', '/orders/345/'));
        $this->assertSame('order-update', Router::match('POST', '/orders/345')['page']);
    }

    public function test_unknown_and_wrong_method_are_null(): void
    {
        $this->assertNull(Router::match('GET', '/brief/abc/'));
        $this->assertNull(Router::match('POST', '/orders/'));
        $this->assertNull(Router::match('GET', '/orders/1234567890/'));
    }
}

final class CsrfTest extends TestCase
{
    public function test_token_is_per_customer_and_accepts_previous_hour(): void
    {
        $this->assertTrue(Csrf::check(7, Csrf::token(7)));
        $this->assertTrue(Csrf::check(7, Csrf::token(7, time() - 3600)));
        $this->assertFalse(Csrf::check(8, Csrf::token(7)));
        $this->assertFalse(Csrf::check(7, Csrf::token(7, time() - 7200)));
        $this->assertFalse(Csrf::check(7, ''));
    }
}
```

- [ ] **Step 3: Запустить — должны упасть**

Run: `cd /home/connect/design.wolf.ua && composer install && vendor/bin/phpunit`
Expected: FAIL — классов `Design\…` нет.

- [ ] **Step 4: Реализовать хелперы**

`Sql`, `Http`, `View`, `Auth`, `autoload`, `bootstrap` — перенести из `calendar.wolf.ua/helpers/Cal2/` с заменой namespace и констант (`DESIGN_CDN_HELPERS`), без кэш-логики Cloudflare: все страницы design — `Http::noStore()`. `Router::ROUTES` — 7 маршрутов из теста, `id` и `product` — `\d{1,9}`. `Csrf` — HMAC `sha256` от `"$customerId|" . date('Y-m-d H', $hourTs)` на `Config::csrfSecret()`. `Config::loginUrl($back)` — пока `'https://wolf.ua/'` (❓ адрес входа wolf.ua с возвратом — уточнить у владельца, см. «Вопросы»). `views/layout.php` — Bootstrap 5 + Font Awesome 5 Pro, шапка «Майстерня дизайну», имя вошедшего клиента. `pages/home.php` — главная с кнопкой «Створити бриф» → `/brief/`. `index.php` — как у calendar.

- [ ] **Step 5: Запустить — проходят**

Run: `vendor/bin/phpunit`
Expected: PASS (AuthTest 4, RouterTest 2, CsrfTest 1).

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "Каркас design.wolf.ua: роутер, вход по wolfid, CSRF, шаблоны"
```

### Task 2: SQL этапа 1 и dev-окружение

**Files:**
- Create: `docs/sql/stage1.sql`
- Create: `/etc/nginx/sites-available/dev.design.wolf.ua.conf` (+ симлинк в `sites-enabled`)
- Test: `tests/Design/SchemaTest.php` (`@group db`)

**Interfaces:**
- Produces: таблицы `design_settings`, `design_products`, `design_orders` (поля — как в `design-wolf-ua-structure.md` раздел 1, `design_orders` — целиком, со всеми полями будущих этапов); dev-адрес `https://dev.design.wolf.ua`.

- [ ] **Step 1: Написать падающий тест**

```php
/** @group db */
final class SchemaTest extends TestCase
{
    public function test_tables_exist_with_utf8mb4(): void
    {
        foreach (['design_settings', 'design_products', 'design_orders'] as $t) {
            $this->assertStringStartsWith('utf8mb4', (string)Sql::value(
                'SELECT table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]));
        }
    }

    public function test_other_product_is_last_and_settings_seeded(): void
    {
        $last = Sql::one('SELECT name FROM design_products WHERE shw = 1 ORDER BY pos DESC, id DESC LIMIT 1');
        $this->assertSame('Інше', $last['name']);
        $this->assertSame('24', Sql::value("SELECT value FROM design_settings WHERE `key` = 'no_offers_hours'"));
    }
}
```

- [ ] **Step 2: Запустить — падает**

Run: `vendor/bin/phpunit --group db`
Expected: FAIL — таблиц нет.

- [ ] **Step 3: Написать `docs/sql/stage1.sql`**

`CREATE TABLE IF NOT EXISTS` для трёх таблиц. Сиды `design_products` (черновик текстов, владелец поправит):

| pos | name | brief (q → hint) |
|---|---|---|
| 10 | Візитка | Розмір → «90×50 мм»; Скільки сторін → «дві»; Скільки варіантів (людей) → «3 візитки на різних співробітників»; Текст → «ПІБ, посада, телефон…»; Побажання до стилю, посилання на матеріали → «логотип тут: …» |
| 20 | Флаєр | Розмір → «А5»; Сторони → «одна»; Текст і зображення → …; Побажання, посилання → … |
| 30 | Буклет | Розмір у розгорнутому вигляді → «А4»; Скільки згинів і як → «2 згини, євробуклет»; Текст і зображення → …; Побажання, посилання → … |
| 40 | Брошура на скобі | Формат → «А5»; Кількість сторінок → «16»; Матеріали → …; Побажання, посилання → … |
| 50 | Логотип | Назва і чим займаєтесь → …; Що подобається / не подобається → …; Де використовуватиметься → «вивіска, візитки, Instagram»; Посилання на приклади → … |
| 1000 | Інше | Опишіть задачу → «що потрібно зробити, розміри, кількість, посилання на матеріали» |

`design_settings`: `no_offers_hours = 24`. `markup_percent` не сеем — понадобится на этапе 3.

- [ ] **Step 4: Владелец запускает SQL на dev**

Попросить владельца выполнить `docs/sql/stage1.sql` на dev-базе `w2p`. Сам не запускать.

- [ ] **Step 5: dev-vhost**

Конфиг — копия `dev.calendar.wolf.ua.conf` с `server_name dev.design.wolf.ua`, `root /home/connect/design.wolf.ua`, `deny` на `^/(tests|vendor|docs|helpers|views|pages)/`, без `CAL2_*` и без расширенного `upload_max_filesize`. `nginx -t && systemctl reload nginx`. DNS `dev.design.wolf.ua` в Cloudflare — владелец (как у `dev.calendar`). Папка хранилища: `mkdir -p /home/connect/design_storage && chgrp www-data … && chmod 2770 …`.

- [ ] **Step 6: Запустить — проходит**

Run: `vendor/bin/phpunit --group db`
Expected: PASS. И `curl -sk --resolve dev.design.wolf.ua:443:127.0.0.1 https://dev.design.wolf.ua/ | grep -c 'Майстерня дизайну'` → `1`.

- [ ] **Step 7: Commit**

```bash
git add docs/sql/stage1.sql tests/Design/SchemaTest.php && git commit -m "SQL этапа 1: design_settings, design_products, design_orders"
```

### Task 3: Продукты дизайна

**Files:**
- Create: `helpers/Design/Products.php`
- Test: `tests/Design/ProductsTest.php` (`@group db`)

**Interfaces:**
- Produces:
  - `Products::visible(): array` — `[['id' => int, 'name' => string], …]`, `shw = 1`, по `pos, id`.
  - `Products::get(int $id): ?array` — `['id' => int, 'name' => string, 'questions' => list<array{q:string, hint:string}>]` или `null`, если нет или `shw = 0`.
  - `Products::questions(?string $json): array` — разбор `brief`; битый JSON или элемент без `q` → такой элемент пропускается, `hint` по умолчанию `''`.

- [ ] **Step 1: Написать падающий тест**

```php
/** @group db */
final class ProductsTest extends TestCase
{
    private static int $hidden;

    public static function setUpBeforeClass(): void
    {
        self::$hidden = Sql::insert('design_products', ['name' => 'Тест прихований', 'brief' => '[]', 'pos' => 1, 'shw' => 0]);
    }

    public static function tearDownAfterClass(): void
    {
        Sql::exec('DELETE FROM design_products WHERE id = ?', [self::$hidden]);
    }

    public function test_visible_ends_with_other(): void
    {
        $names = array_column(Products::visible(), 'name');
        $this->assertSame('Інше', end($names));
        $this->assertNotContains('Тест прихований', $names);
    }

    public function test_hidden_product_is_null(): void   // Review Focus 5
    {
        $this->assertNull(Products::get(self::$hidden));
        $this->assertNull(Products::get(999999999));
    }

    public function test_questions_parsing(): void
    {
        $this->assertSame([['q' => 'Розмір', 'hint' => '90×50']],
            Products::questions('[{"q":"Розмір","hint":"90×50"},{"hint":"без питання"}]'));
        $this->assertSame([['q' => 'Текст', 'hint' => '']], Products::questions('[{"q":"Текст"}]'));
        $this->assertSame([], Products::questions('не json'));
        $this->assertSame([], Products::questions(null));
    }
}
```

- [ ] **Step 2: Запустить — падает.** Run: `vendor/bin/phpunit tests/Design/ProductsTest.php` Expected: FAIL — нет `Design\Products`.
- [ ] **Step 3: Реализовать `Design\Products`** с сигнатурами из Interfaces.
- [ ] **Step 4: Запустить — проходит.** Run: то же. Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -am "Продукти дизайну"` (с новыми файлами через `git add`).

### Task 4: Бриф и заказ

**Files:**
- Create: `helpers/Design/Brief.php`, `helpers/Design/BriefException.php`, `helpers/Design/Orders.php`, `helpers/Design/OrderStatus.php`
- Test: `tests/Design/BriefTest.php`, `tests/Design/OrdersTest.php` (`@group db`)

**Interfaces:**
- Consumes: `Products::get(int $id): ?array` (задача 3).
- Produces:
  - `OrderStatus` — константы `DRAFT = 1, COLLECTING = 2, WAITING_DESIGNER = 3, IN_WORK = 4, AWAITING_INVOICE = 5, PURCHASED = 6, REWORK = 7, CANCELLED = 8` и `OrderStatus::label(int $s): string` (украинские подписи: «Чернетка», «Збір пропозицій», «Очікує відповіді дизайнера», «В роботі», «Очікує оплати рахунку», «Куплено», «Доопрацювання», «Скасовано»).
  - `Brief::snapshot(array $questions, array $answers): array` → `list<array{q:string, a:string}>`. Ответы обрезаются `trim`. Бросает `BriefException` с текстом для клиента: число ответов ≠ числу вопросов → «Форма застаріла, оновіть сторінку»; все пустые → «Заповніть хоча б одну відповідь»; ответ длиннее 5000 символов (`mb_strlen`) → «Відповідь занадто довга».
  - `Orders::create(int $customerId, int $productId, array $answers): int` — черновик, `mode = 1`, `status = DRAFT`, `answers` = JSON снимка. Неизвестный или скрытый продукт → `BriefException('Продукт недоступний')`.
  - `Orders::updateDraft(int $orderId, int $customerId, array $answers): void` — только `DRAFT` и только владелец, иначе `BriefException('Замовлення не можна змінити')`. Вопросы берутся из сохранённого снимка, а не из продукта.
  - `Orders::publish(int $orderId, int $customerId): void` — `DRAFT` → `COLLECTING`, `published_at = NOW()`. Уже `COLLECTING` — ничего не делать. Чужой или другой статус → `BriefException('Замовлення не можна опублікувати')`.
  - `Orders::get(int $orderId, int $customerId): ?array` — `['id', 'status', 'product_name', 'answers' => list<array{q,a}>, 'created', 'published_at']`; чужой → `null`.
  - `Orders::forCustomer(int $customerId): array` — то же без `answers`, новые сверху.
  - Во всех возвращаемых массивах `id` и `status` — `int` (приводить явно: тесты сравнивают через `assertSame`). То же для `id` в `Products::visible/get`.

- [ ] **Step 1: Написать падающие тесты**

```php
final class BriefTest extends TestCase
{
    private const Q = [['q' => 'Розмір', 'hint' => ''], ['q' => 'Текст', 'hint' => '']];

    public function test_snapshot_pairs_and_trims(): void
    {
        $this->assertSame([['q' => 'Розмір', 'a' => 'А5'], ['q' => 'Текст', 'a' => '']],
            Brief::snapshot(self::Q, [' А5 ', '']));
    }

    public function test_rejects_stale_empty_and_too_long(): void
    {
        foreach ([['А5'], ['', '  '], [str_repeat('я', 5001), '']] as $answers) {
            try { Brief::snapshot(self::Q, $answers); $this->fail(); } catch (BriefException) { $this->addToAssertionCount(1); }
        }
    }
}

/** @group db */
final class OrdersTest extends TestCase
{
    private const ME = 999999001;
    private const OTHER = 999999002;

    public static function tearDownAfterClass(): void
    {
        Sql::exec('DELETE FROM design_orders WHERE customer_id IN (?, ?)', [self::ME, self::OTHER]);
    }

    private function product(): int { return Products::visible()[0]['id']; }

    private function answers(string $first): array
    {
        return array_merge([$first], array_fill(0, count(Products::get($this->product())['questions']) - 1, ''));
    }

    public function test_create_publish_and_list(): void
    {
        $id = Orders::create(self::ME, $this->product(), $this->answers('А5'));
        $this->assertSame(OrderStatus::DRAFT, Orders::get($id, self::ME)['status']);
        Orders::publish($id, self::ME);
        $this->assertSame(OrderStatus::COLLECTING, Orders::get($id, self::ME)['status']);
        $this->assertContains($id, array_column(Orders::forCustomer(self::ME), 'id'));
    }

    public function test_get_foreign_order_is_null(): void   // Review Focus 1
    {
        $id = Orders::create(self::ME, $this->product(), $this->answers('А5'));
        $this->assertNull(Orders::get($id, self::OTHER));
        $this->expectException(BriefException::class);
        Orders::publish($id, self::OTHER);
    }

    public function test_answers_keep_apostrophe_and_html(): void   // Review Focus 2
    {
        $id = Orders::create(self::ME, $this->product(), $this->answers("м'ята <b>жирно</b>"));
        $this->assertSame("м'ята <b>жирно</b>", Orders::get($id, self::ME)['answers'][0]['a']);
    }

    public function test_snapshot_survives_question_change(): void   // Review Focus 3
    {
        $pid = Sql::insert('design_products', ['name' => 'Тест', 'brief' => '[{"q":"Старе питання"}]', 'pos' => 2, 'shw' => 1]);
        $id = Orders::create(self::ME, $pid, ['відповідь']);
        Sql::exec('UPDATE design_products SET brief = ? WHERE id = ?', ['[{"q":"Нове питання"}]', $pid]);
        Orders::updateDraft($id, self::ME, ['інша відповідь']);
        $this->assertSame([['q' => 'Старе питання', 'a' => 'інша відповідь']], Orders::get($id, self::ME)['answers']);
        Sql::exec('DELETE FROM design_products WHERE id = ?', [$pid]);
    }

    public function test_publish_twice_is_noop(): void   // Review Focus 4
    {
        $id = Orders::create(self::ME, $this->product(), $this->answers('А5'));
        Orders::publish($id, self::ME);
        $first = Orders::get($id, self::ME)['published_at'];
        Orders::publish($id, self::ME);
        $this->assertSame($first, Orders::get($id, self::ME)['published_at']);
    }

    public function test_published_order_cannot_be_edited(): void
    {
        $id = Orders::create(self::ME, $this->product(), $this->answers('А5'));
        Orders::publish($id, self::ME);
        $this->expectException(BriefException::class);
        Orders::updateDraft($id, self::ME, $this->answers('А4'));
    }
}
```

- [ ] **Step 2: Запустить — падают.** Run: `vendor/bin/phpunit tests/Design/BriefTest.php tests/Design/OrdersTest.php` Expected: FAIL — классов нет.
- [ ] **Step 3: Реализовать `Brief`, `BriefException`, `OrderStatus`, `Orders`** с сигнатурами из Interfaces. JSON — `json_encode(…, JSON_UNESCAPED_UNICODE)`. `publish` — один `UPDATE … SET status = 2, published_at = NOW() WHERE id = ? AND customer_id = ? AND status = 1`; если затронуто 0 строк, перечитать заказ: свой и `COLLECTING` — молча выйти, иначе исключение.
- [ ] **Step 4: Запустить — проходят.** Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "Бриф і замовлення: чернетка, публікація, знімок питань"`.

### Task 5: Страницы брифа и заказов

**Files:**
- Create: `pages/brief-products.php`, `pages/brief-form.php`, `pages/brief-create.php`, `pages/orders.php`, `pages/order.php`, `pages/order-update.php`, `helpers/Design/Guard.php`
- Create: `views/brief-products.php`, `views/brief-form.php`, `views/orders.php`, `views/order.php`, `views/login-required.php`, `assets/design.css`, `assets/design.js`
- Test: `tests/Design/PagesHttpTest.php` (`@group http`)

**Interfaces:**
- Consumes: `Auth::current()`, `Csrf::token/check`, `Products::visible/get`, `Orders::*`, `Brief` (задачи 1, 3, 4).
- Produces: `Guard::customer(): int` — id вошедшего; гость → `views/login-required.php` (текст «Щоб створити бриф, увійдіть на wolf.ua» и ссылка `Config::loginUrl($текущий адрес)`) с кодом 401 и `exit`.

Поведение страниц:
- `GET /brief/` — карточки `Products::visible()`, «Інше» последним.
- `GET /brief/{product}/` — форма: для каждого вопроса подпись `q` и `<textarea placeholder="hint">`; скрытый/нет продукта → `Http::notFound()`. Две кнопки: «Зберегти чернетку» и «Опублікувати».
- `POST /brief` — поля `product_id`, `answers[]`, `csrf`, `action` (`draft`|`publish`). Неверный CSRF → 403. `BriefException` → форма снова, с сообщением и уже введёнными ответами. Успех → redirect `/orders/{id}/`.
- `GET /orders/` — таблица: №, продукт, статус (`OrderStatus::label`), дата.
- `GET /orders/{id}/` — чужой/нет → 404. Черновик — та же форма по снимку, `POST /orders/{id}` (`action` `draft`|`publish`). Опубликованный — вопросы и ответы только для чтения; ответы — через `View::e` и `nl2br`.
- `assets/design.js`: на `submit` формы — `disabled` всем кнопкам этой формы.

- [ ] **Step 1: Написать падающий HTTP-тест**

Как `calendar.wolf.ua/tests/Cal2/ShowcaseHttpTest.php`: `fetch(string $path, string $cookie = '', ?array $post = null)` через `https://dev.design.wolf.ua` с `CURLOPT_RESOLVE` на 127.0.0.1. Тестовый клиент — `getenv('DESIGN_TEST_CUSTOMER_ID')`: кука `wolfid` строится из его `customer_pass` и User-Agent запроса (`DesignTest/1`).

```php
/** @group http */
final class PagesHttpTest extends TestCase
{
    public function test_guest_sees_login_required(): void
    {
        [$code, , $body] = self::fetch('/brief/');
        $this->assertSame(401, $code);
        $this->assertStringContainsString('увійдіть на wolf.ua', $body);
    }

    public function test_brief_list_and_form(): void
    {
        [$code, , $body] = self::fetch('/brief/', self::cookie());
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Інше', $body);
    }

    public function test_hidden_product_form_is_404(): void      // Review Focus 5
    {
        $this->assertSame(404, self::fetch('/brief/999999999/', self::cookie())[0]);
    }

    public function test_create_without_csrf_is_403(): void
    {
        $this->assertSame(403, self::fetch('/brief', self::cookie(), ['product_id' => 1, 'answers' => ['x'], 'action' => 'draft'])[0]);
    }

    /** Ответы по числу вопросов первого продукта: первый — $first, остальные пустые */
    private static function answers(string $first): array
    {
        $pid = Products::visible()[0]['id'];
        return [$pid, array_merge([$first], array_fill(0, count(Products::get($pid)['questions']) - 1, ''))];
    }

    public function test_foreign_order_page_is_404(): void         // Review Focus 1
    {
        [$pid, $a] = self::answers('А5');
        $foreign = Orders::create(999999002, $pid, $a);
        $this->assertSame(404, self::fetch("/orders/$foreign/", self::cookie())[0]);
    }

    public function test_order_page_escapes_html(): void           // Review Focus 2
    {
        [$pid, $a] = self::answers('<script>alert(1)</script>');
        $id = Orders::create((int)getenv('DESIGN_TEST_CUSTOMER_ID'), $pid, $a);
        Orders::publish($id, (int)getenv('DESIGN_TEST_CUSTOMER_ID'));
        [$code, , $body] = self::fetch("/orders/$id/", self::cookie());
        $this->assertSame(200, $code);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        $this->assertStringNotContainsString('<script>alert(1)', $body);
    }

    public static function tearDownAfterClass(): void
    {
        Sql::exec('DELETE FROM design_orders WHERE customer_id IN (?, ?)', [(int)getenv('DESIGN_TEST_CUSTOMER_ID'), 999999002]);
    }
}
```

- [ ] **Step 2: Запустить — падают.** Run: `DESIGN_TEST_CUSTOMER_ID=<id> vendor/bin/phpunit --group http` Expected: FAIL (404 на маршрутах без страниц).
- [ ] **Step 3: Реализовать страницы, шаблоны, `Guard`, assets** по поведению выше. Вёрстка — Bootstrap 5, без своих фреймворков.
- [ ] **Step 4: Запустить — проходят.** Run: `DESIGN_TEST_CUSTOMER_ID=<id> vendor/bin/phpunit` (все группы). Expected: PASS.
- [ ] **Step 5: Ручная проверка в браузере** — войти на dev.wolf.ua тестовым клиентом, открыть `https://dev.design.wolf.ua/brief/`, создать черновик с апострофом, опубликовать, двойной клик по «Опублікувати» не создаёт дубль.
- [ ] **Step 6: Commit** — `git commit -m "Сторінки брифу і моїх замовлень"`.

## Вопросы до старта (к владельцу)

1. **Адрес входа wolf.ua с возвратом.** Куда вести гостя, чтобы после входа он вернулся на design? Пока `Config::loginUrl()` ведёт на главную wolf.ua.
2. **Тестовый клиент на dev** — какой `customers.id` использовать для HTTP-тестов.
3. **DNS `dev.design.wolf.ua`** в Cloudflare — завести так же, как `dev.calendar.wolf.ua`.
4. **Репозиторий** `hdd1/design.wolf.ua` — создать пустым на GitHub к первому пушу.
5. **Тексты вопросов** продуктов в сидах — черновик; поправить до запуска или потом в админке.
