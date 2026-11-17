<?php
use Design\Csrf;
use Design\DesignerException;
use Design\Designers;
use Design\DesignerStatus;
use Design\Guard;
use Design\Http;
use Design\Portfolio;
use Design\View;

$me = Guard::customer();
$fresh = Guard::csrfOk($me);
$d = Designers::get($me);
if ($d === null) Http::redirect('/designer/', 302);

$action = ($_POST['action'] ?? '') === 'submit' ? 'submit' : 'save';
$posted = [
    'name' => (string)($_POST['name'] ?? ''),
    'about' => (string)($_POST['about'] ?? ''),
    'link' => (string)($_POST['link'] ?? ''),
];

try {
    if (!$fresh) throw new DesignerException(Guard::STALE);
    Designers::saveProfile($me, $posted['name'], $posted['about'], $posted['link']);
    if ($action === 'submit') Designers::submit($me);
} catch (DesignerException $e) {
    $d = Designers::get($me);
    // Статус устиг змінитися (подали в іншій вкладці, заблокували) — показуємо сторінку стану
    if (!in_array($d['status'], Designers::EDITABLE, true)) {
        Http::redirect('/designer/', 302);
    }
    $profile = $d['status'] === DesignerStatus::APPROVED;
    $scripts = $profile ? ['/assets/vendor/sortablejs-1.15.6/Sortable.min.js', '/assets/js/designer.js'] : ['/assets/js/designer.js'];
    View::render('layout', [
        'title' => $profile ? 'Мій профіль' : 'Заявка дизайнера',
        'active' => 'designer',
        'scripts' => $scripts,
        'body' => View::fetch($profile ? 'designer-profile' : 'designer-apply', [
            'd' => $posted + $d,
            'works' => Portfolio::all($me),
            'csrf' => Csrf::token($me),
            'error' => $e->getMessage(),
        ]),
    ]);
    exit;
}
Http::redirect($action === 'submit' ? '/designer/' : '/designer/?saved=1', 302);
