<?php
use Design\BriefException;
use Design\BriefPage;
use Design\Csrf;
use Design\Guard;
use Design\Http;
use Design\Orders;
use Design\Products;

$me = Guard::customer();
$fresh = Guard::csrfOk($me);

$product = Products::get((int)($_POST['product_id'] ?? 0));
if ($product === null) Http::notFound();
[$answers, $title] = BriefPage::posted();

try {
    if (!$fresh) throw new BriefException(Guard::STALE);
    $id = Orders::create($me, $product['id'], $answers, $title);
} catch (BriefException $e) {
    BriefPage::render([
        'productName' => $product['name'],
        'productId' => $product['id'],
        'postTo' => '/brief',
        'title' => $title,
        'fields' => BriefPage::fields($product['questions'], $answers),
        'csrf' => Csrf::token($me),
        'error' => $e->getMessage(),
    ]);
    exit;
}
Http::redirect(($_POST['action'] ?? '') === 'next' ? "/orders/$id/mode/" : "/orders/$id/?saved=1", 302);
