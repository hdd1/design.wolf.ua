<?php
/**
 * Сторінка замовлення клієнта: GET /orders/{id}/. Чернетка — форма брифу; опубліковане — бриф, пропозиції з листуванням
 * і вибором (макет 6.4), стани режиму 2 «чекаємо відповіді дизайнера» / «дизайнер відмовився».
 * ?chat={id пропозиції} — відкрите листування (на телефоні — окремий екран), ?chat=0 — без бокової панелі; ?done=… — тост.
 * Після помилки POST сторінку рендерить pages/order-update.php з $posted
 * @var array $params
 * @var ?array{error:string} $posted
 */
use Design\BriefPage;
use Design\Chat;
use Design\Csrf;
use Design\Guard;
use Design\Http;
use Design\Messages;
use Design\Offers;
use Design\OfferStatus;
use Design\Orders;
use Design\OrderStatus;
use Design\Products;
use Design\View;

$me = Guard::customer();
$order = Orders::get((int)$params['id'], $me);
if ($order === null) Http::notFound();

if ($order['status'] === OrderStatus::DRAFT) {
    $saved = isset($_GET['saved']);
    BriefPage::render([
        'productName' => $order['product_name'],
        'productId' => null,
        'postTo' => '/orders/' . $order['id'],
        'title' => $order['title'] !== $order['product_name'] ? $order['title'] : '',
        'fields' => BriefPage::fromSnapshot($order['answers'], Products::get($order['product_id'])),
        'csrf' => Csrf::token($me),
        'savedAt' => $saved ? date('H:i') : null,
    ], $saved ? 'Чернетку збережено. Її видно в «Мої замовлення»' : null);
    exit;
}

$id = $order['id'];
$posted ??= null;
$csrf = Csrf::token($me);
$mode2 = Offers::mode2State($id, $me);
$state = $mode2['state'] ?? null;

// Відмова від запрошення режиму 2 — не пропозиція: у списку її немає
$cards = array_values(array_filter(Offers::forOrder($id, $me), static fn(array $c): bool => $c['status'] !== OfferStatus::DECLINED));
$chosen = null;
foreach ($cards as $c) if ($c['status'] === OfferStatus::CHOSEN) $chosen = $c;
$choosable = static fn(array $c): bool => $c['status'] === OfferStatus::SENT && $c['available']
    && $order['status'] === OrderStatus::COLLECTING && $chosen === null;
$open = count(array_filter($cards, $choosable));
// Список пропозицій — скрізь, крім «чекаємо відповіді» і «дизайнер відмовився» режиму 2
$list = $state !== 'waiting' && $state !== 'declined';

// Відкрите листування: ?chat= (лише пропозиція зі списку цього замовлення; 0 — закрите), інакше обраний дизайнер
// або перша пропозиція, яку можна обрати
$byId = array_column($cards, null, 'id');
$asked = Http::str($_GET, 'chat');
$explicit = $list && preg_match('/^\d{1,9}$/', $asked) === 1;
$sel = $explicit ? (int)$asked : 0;
if ($list && !isset($byId[$sel]) && !($explicit && $sel === 0)) {
    $explicit = false;
    $sel = $chosen['id'] ?? (array_values(array_filter($cards, $choosable))[0]['id'] ?? 0);
}

$chat = null;
if (isset($byId[$sel])) {
    $card = $byId[$sel];
    $access = Offers::access($sel, $me);
    $messages = Chat::messages($access);
    Messages::seen($sel, Messages::ROLE_CLIENT);
    $footer = null;
    if ($choosable($card)) {
        $footer = '<button type="button" class="btn btn-outline-blue w-100" data-bs-toggle="modal" data-bs-target="#choose-' . $sel . '">'
            . 'Обрати за ' . View::e(View::price($card['price_client'])) . '</button>';
    } elseif ($chosen !== null && $card['status'] !== OfferStatus::CHOSEN) {
        $footer = '<p class="chat-other"><i class="fal fa-user-check"></i> Вже обрано іншого дизайнера</p>';
    }
    $chat = [
        'offerId' => $sel,
        'title' => $card['designer_name'] !== '' ? $card['designer_name'] : 'Дизайнер',
        'subtitle' => $card['price_client'] > 0
            ? View::price($card['price_client']) . ($card['deadline'] ? ' · до ' . View::date($card['deadline']) : '') : '',
        'note' => $card['status'] === OfferStatus::CHOSEN ? null : 'До вибору — лише текстові повідомлення. Файли і прев\'ю — після вибору дизайнера',
        'csrf' => $csrf,
        'messages' => $messages,
        'write' => $access['write'],
        'footer' => $footer,
    ];
}

// Режим 2, відмова: причина — останнє повідомлення дизайнера в листуванні цього запрошення
$reason = null;
$refused = $state === 'declined' ? $mode2['offer'] : null;
if ($refused !== null && $refused['status'] === OfferStatus::DECLINED) {
    foreach (array_reverse(Messages::list($refused['id'])) as $m) {
        if ($m['role'] === Messages::ROLE_DESIGNER) {
            $reason = $m;
            break;
        }
    }
}

// Плашка «чий хід»
$alert = match (true) {
    $state === 'waiting' => ['mine' => false, 'icon' => 'fa-hourglass-half', 'title' => 'Чекаємо відповіді дизайнера',
        'sub' => 'Дизайнер отримав запрошення і сповіщення. Зазвичай відповідають за кілька годин'],
    $state === 'declined' => ['mine' => true, 'icon' => 'fa-hand-paper', 'title' => 'Ваш хід: оберіть, як продовжити',
        'sub' => ($refused !== null && $refused['designer_name'] !== '' ? $refused['designer_name'] : 'Дизайнер') . ' не може взяти замовлення'],
    $open > 0 => ['mine' => true, 'icon' => 'fa-hand-paper', 'title' => 'Ваш хід: оберіть дизайнера',
        'sub' => $state === 'offer' ? 'Дизайнер назвав ціну і термін — підтвердіть вибір'
            : $open . ' ' . OrderStatus::plural($open, 'пропозиція', 'пропозиції', 'пропозицій') . '. Порівняйте ціну, термін і портфоліо'],
    $order['status'] === OrderStatus::COLLECTING => ['mine' => false, 'icon' => 'fa-hourglass-half', 'title' => 'Чекаємо пропозицій від дизайнерів',
        'sub' => 'Опубліковано ' . View::when($order['published_at'])],
    default => ['mine' => false, 'icon' => 'fa-pencil-ruler', 'title' => OrderStatus::turn($order['status'])['text'],
        'sub' => $chosen === null ? '' : ($chosen['designer_name'] !== '' ? $chosen['designer_name'] . ' · ' : '')
            . View::price($chosen['price_client']) . ($chosen['deadline'] ? ' · до ' . View::date($chosen['deadline']) : '')],
};

$toasts = [
    'chosen' => 'Дизайнера обрано — він отримав сповіщення',
    'invited' => 'Запрошення надіслано — дизайнер отримав сповіщення',
    'collect' => 'Бриф опубліковано — дизайнери вже бачать його',
];

View::render('layout', [
    'title' => $order['title'],
    'active' => 'orders',
    'scripts' => $chat !== null ? ['/assets/js/chat.js'] : [],
    'toast' => $posted === null ? ($toasts[Http::str($_GET, 'done')] ?? null) : null,
    'body' => View::fetch('order', [
        'order' => $order,
        'state' => $state,
        'alert' => $alert,
        'error' => $posted['error'] ?? null,
        'list' => $list,
        'cards' => $cards,
        'chosen' => $chosen,
        'choosable' => $choosable,
        'waiting' => $state === 'waiting' ? $mode2['offer'] : null,
        'refused' => $refused,
        'reason' => $reason,
        'sel' => $sel,
        'chat' => $chat,
        'chatOpen' => $explicit && $chat !== null,
        'csrf' => $csrf,
    ]),
]);
