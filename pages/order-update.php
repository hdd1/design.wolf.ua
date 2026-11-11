<?php
/** @var array $params */
use Design\BriefException;
use Design\BriefPage;
use Design\Csrf;
use Design\Guard;
use Design\Http;
use Design\Orders;
use Design\OrderStatus;
use Design\Products;
use Design\View;

$me = Guard::customer();
Guard::csrf($me);
$order = Orders::get((int)$params['id'], $me);
if ($order === null) Http::notFound();
$id = $order['id'];
$action = (string)($_POST['action'] ?? '');

if ($action === 'publish') {
    try {
        Orders::publish($id, $me);
    } catch (BriefException $e) {
        View::render('message', ['title' => 'Не вдалося опублікувати', 'text' => $e->getMessage()]);
        exit;
    }
    Http::redirect("/orders/$id/", 302);
}

if ($order['status'] !== OrderStatus::DRAFT) Http::notFound();
[$answers, $title] = BriefPage::posted();
try {
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
