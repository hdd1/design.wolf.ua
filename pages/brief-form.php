<?php
/** @var array $params */
use Design\BriefPage;
use Design\Csrf;
use Design\Guard;
use Design\Http;
use Design\Products;

$me = Guard::customer();
$product = Products::get((int)$params['product']);
if ($product === null) Http::notFound();

BriefPage::render([
    'productName' => $product['name'],
    'productId' => $product['id'],
    'postTo' => '/brief',
    'title' => '',
    'fields' => BriefPage::fields($product['questions'], []),
    'csrf' => Csrf::token($me),
]);
