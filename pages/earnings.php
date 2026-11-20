<?php
/** «Заробіток» схваленого дизайнера: GET /earnings/ — проведені купівлі його замовлень (гонорар «на руки») і сума за весь час. Виведення коштів — через менеджера */
use Design\Designers;
use Design\DesignerStatus;
use Design\Guard;
use Design\Http;
use Design\Purchases;
use Design\View;

$me = Guard::customer();
if (Designers::status($me) !== DesignerStatus::APPROVED) Http::notFound();
$rows = Purchases::forDesigner($me);

View::render('layout', [
    'title' => 'Заробіток',
    'active' => 'earnings',
    'body' => View::fetch('earnings', ['rows' => $rows, 'total' => array_sum(array_column($rows, 'amount_designer'))]),
]);
