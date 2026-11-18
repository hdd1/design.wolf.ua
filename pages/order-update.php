<?php
/**
 * Дії з замовленням клієнта: POST /orders/{id}.
 * Чернетка: action = draft | next (зберегти бриф) | publish (режим 1). Вибір дизайнера: invite (designer_id; з чернетки —
 * публікація в режимі 2, після відмови — «Запросити іншого»), collect (режим 2 → збір пропозицій), choose (offer_id і
 * expected_price — ціна, яку клієнт бачив). Успіх — на сторінку замовлення з тостом; помилка вибору — та сама сторінка
 * (invite — сторінка вибору дизайнера) з текстом помилки (застарілий CSRF — 403, інше — 422)
 * @var array $params
 */
use Design\BriefException;
use Design\BriefPage;
use Design\Csrf;
use Design\DesignerException;
use Design\Designers;
use Design\DesignerStatus;
use Design\Guard;
use Design\Http;
use Design\Offers;
use Design\Orders;
use Design\OrderStatus;
use Design\Products;
use Design\View;

$me = Guard::customer();
$fresh = Guard::csrfOk($me);
$order = Orders::get((int)$params['id'], $me);
if ($order === null) Http::notFound();
$id = $order['id'];
$action = Http::str($_POST, 'action');

$picks = ['invite' => 'invited', 'collect' => 'collect', 'choose' => 'chosen'];
if (isset($picks[$action])) {
    try {
        if (!$fresh) throw new DesignerException('Сесія застаріла: нічого не змінено. Натисніть ще раз.');
        match ($action) {
            'invite' => Offers::invite($id, $me, (int)Http::str($_POST, 'designer_id')),
            'collect' => Offers::collect($id, $me),
            'choose' => Offers::choose($id, $me, (int)Http::str($_POST, 'offer_id'), (int)Http::str($_POST, 'expected_price')),
        };
    } catch (DesignerException $e) {
        if ($fresh) http_response_code(422);
        $posted = ['error' => $e->getMessage()];
        require __DIR__ . ($action === 'invite' ? '/order-pick.php' : '/order.php');
        exit;
    }
    Http::redirect("/orders/$id/?done=" . $picks[$action], 302);
}

// Чернетку вже опубліковано (стара вкладка, «Назад»): ведемо на сторінку замовлення
if ($order['status'] !== OrderStatus::DRAFT && $action !== 'publish') Http::redirect("/orders/$id/", 302);

if ($action === 'publish') {
    if (!$fresh) {
        $canPick = Designers::catalog('', 1)['total'] - (Designers::status($me) === DesignerStatus::APPROVED ? 1 : 0) > 0;
        View::render('layout', ['title' => 'Як обрати дизайнера', 'active' => 'brief',
            'body' => View::fetch('brief-mode', ['orderId' => $id, 'csrf' => Csrf::token($me), 'canPick' => $canPick, 'error' => Guard::STALE])]);
        exit;
    }
    try {
        Orders::publish($id, $me);
    } catch (BriefException $e) {
        View::render('message', ['title' => 'Не вдалося опублікувати', 'text' => $e->getMessage()]);
        exit;
    }
    Http::redirect("/orders/$id/", 302);
}

[$answers, $title] = BriefPage::posted();
try {
    if (!$fresh) throw new BriefException(Guard::STALE);
    Orders::updateDraft($id, $me, $answers, $title);
} catch (BriefException $e) {
    $fields = BriefPage::fromSnapshot($order['answers'], Products::get($order['product_id']));
    foreach ($fields as $i => &$f) $f['a'] = $answers[$i] ?? '';
    unset($f);
    BriefPage::render([
        'productName' => $order['product_name'],
        'productId' => null,
        'postTo' => "/orders/$id",
        'title' => $title,
        'fields' => $fields,
        'csrf' => Csrf::token($me),
        'error' => $e->getMessage(),
    ]);
    exit;
}
Http::redirect($action === 'next' ? "/orders/$id/mode/" : "/orders/$id/?saved=1", 302);
