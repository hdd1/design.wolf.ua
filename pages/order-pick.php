<?php
/**
 * Вибір дизайнера для режиму «Одразу обрати»: GET /orders/{id}/pick/ — з кроку 3 брифу (чернетка) або «Обрати іншого»
 * після відмови дизайнера (режим 2 без активної пропозиції). Каталог схвалених без самого клієнта, пошук за імʼям, сторінки.
 * Вибір іде POST /orders/{id} action=invite. В інших станах — на сторінку замовлення.
 * Після помилки запрошення сторінку рендерить pages/order-update.php з $posted
 * @var array $params
 * @var ?array{error:string} $posted
 */
use Design\Csrf;
use Design\Designers;
use Design\Guard;
use Design\Http;
use Design\Offers;
use Design\Orders;
use Design\OrderStatus;
use Design\View;

$me = Guard::customer();
$order = Orders::get((int)$params['id'], $me);
if ($order === null) Http::notFound();
$id = $order['id'];
$posted ??= null;
$draft = $order['status'] === OrderStatus::DRAFT;

if (!$draft && (Offers::mode2State($id, $me)['state'] ?? null) !== 'declined') {
    // Запрошення не вдалося, а вибирати вже нема з чого (дизайнер щойно погодився, бриф уже у зборі) — помилка на сторінці замовлення
    if ($posted !== null) {
        require __DIR__ . '/order.php';
        exit;
    }
    Http::redirect("/orders/$id/", 302);
}

$q = trim(mb_substr(Http::str($_GET, 'q'), 0, Designers::NAME_MAX));
$page = max(1, min(1000, (int)Http::str($_GET, 'page')));
$list = Designers::catalog($q, $page);
if ($page > $list['pages']) {
    $page = $list['pages'];
    $list = Designers::catalog($q, $page);
}
// Клієнт, який сам дизайнер, себе не обирає
$items = array_values(array_filter($list['items'], static fn(array $d): bool => $d['customer_id'] !== $me));

View::render('layout', [
    'title' => $draft ? 'Оберіть дизайнера' : 'Оберіть іншого дизайнера',
    'active' => $draft ? 'brief' : 'orders',
    'body' => View::fetch('order-pick', [
        'orderId' => $id,
        'draft' => $draft,
        'q' => $q,
        'page' => $page,
        'pages' => $list['pages'],
        'items' => $items,
        'picked' => $posted !== null ? (int)Http::str($_POST, 'designer_id') : 0,
        'error' => $posted['error'] ?? null,
        'csrf' => Csrf::token($me),
    ]),
]);
