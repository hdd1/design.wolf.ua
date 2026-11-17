<?php
use Design\Csrf;
use Design\Designers;
use Design\DesignerStatus;
use Design\Guard;
use Design\Portfolio;
use Design\View;

$me = Guard::customer();
$d = Designers::start($me);
$page = ['active' => 'designer', 'scripts' => ['/assets/js/designer.js']];

switch ($d['status']) {
    case DesignerStatus::PENDING:
    case DesignerStatus::BLOCKED:
        $state = $d['status'] === DesignerStatus::PENDING ? 'pending' : 'blocked';
        View::render('layout', ['title' => 'Заявка дизайнера', 'active' => 'designer',
            'body' => View::fetch('designer-state', ['state' => $state, 'd' => $d])]);
        break;
    case DesignerStatus::APPROVED:
        if (isset($_GET['approved'])) {
            View::render('layout', ['title' => 'Заявку схвалено', 'active' => 'designer',
                'body' => View::fetch('designer-state', ['state' => 'approved', 'd' => $d])]);
            break;
        }
        $page['scripts'] = ['/assets/vendor/sortablejs-1.15.6/Sortable.min.js', '/assets/js/designer.js'];
        View::render('layout', $page + ['title' => 'Мій профіль',
            'toast' => isset($_GET['saved']) ? 'Зміни збережено' : null,
            'body' => View::fetch('designer-profile', ['d' => $d, 'works' => Portfolio::all($me), 'csrf' => Csrf::token($me)])]);
        break;
    default:   // чернетка або відхилена — форма заявки
        View::render('layout', $page + ['title' => 'Заявка дизайнера',
            'toast' => isset($_GET['saved']) ? 'Чернетку заявки збережено' : null,
            'body' => View::fetch('designer-apply', ['d' => $d, 'works' => Portfolio::all($me), 'csrf' => Csrf::token($me)])]);
}
