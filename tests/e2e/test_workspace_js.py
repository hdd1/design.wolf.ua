"""
JS робочого простору без сервера: справжні partials/chat (з робочим простором) і partials/layouts-panel, відрендерені PHP,
і справжні assets/js/chat.js, chunk-upload.js, workspace-layouts.js, workspace.js у Chromium; запити сторінки перехоплює
page.route (адреса https://ws.test/ вигадана — у мережу нічого не йде, БД лише читає Settings).
Перевіряє: (1) остаточну помилку останньої частини ({final:true}) chunk-upload.js не повторює, звичайну 5xx — повторює;
(2) поки завантажується заміна макета, «Замінити», «Видалити» й олівець цього макета вимкнені (і після перемальовування панелі);
(3) системне повідомлення з адресою в тексті chat.js показує текстом, без посилання;
(4) «Купую» (справжні partials/workspace і pay-modal, workspace-buy.js, bootstrap): вікно перечитує суму, подвійний натиск
«Оплатити» — один запит, успіх — «Завантажити» в панелі, «чий хід» і статус оновлено, «Купую» зникла; відмова — текст
і свіжий вміст вікна;
(5) «Скопіювати в хмару» купленого макета: подвійний натиск — один запит, «Копіюємо…» з опитуванням панелі, поки макет у
черзі, → «У хмарі ✓» (опитування зупинилось); помилка — «Не вдалося, спробуйте ще», натиск — повторний запит;
(6) доопрацювання: жива підказка доплати у формі «Ще не закінчили»; «Відмовитися» з підтвердженням (скасоване — нічого не
шле і форму не блокує); stage з опитування змінився — сторінка перечитується, але не поки в полі чату є текст.
Запуск: python3 -m pytest tests/e2e/test_workspace_js.py -q
"""
import json, os, re, subprocess

from playwright.sync_api import sync_playwright

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
BASE = 'https://ws.test'

RENDER = r'''
require "helpers/Design/bootstrap.php";
use Design\View;
$panel = ["layouts" => [["id" => 7, "label" => "Візитка", "original_name" => "Візитка.pdf", "size" => 1000,
    "size_text" => "1000 Б", "ext" => "pdf", "purchased" => false, "download" => ""]], "count" => 1, "rev" => "r1"];
echo View::fetch("partials/chat", ["offerId" => 1, "title" => "Клієнт", "subtitle" => "", "note" => null, "csrf" => "x",
    "messages" => [], "write" => true, "footer" => null,
    "workspace" => ["orderId" => 1, "upload" => true, "hide" => true, "chunk" => 1048576]]);
echo View::fetch("partials/layouts-panel", ["orderId" => 1, "panel" => $panel, "edit" => true, "csrf" => "x", "chunk" => 1048576]);
'''


# Робочий простір клієнта замовлення «В роботі» з одним некупленим макетом і вікном оплати (баланс 5 000, сума 1 200)
RENDER_BUY = r'''
require "helpers/Design/bootstrap.php";
use Design\View;
$panel = ["layouts" => [["id" => 7, "label" => "Візитка", "original_name" => "Візитка.pdf", "size" => 1000,
    "size_text" => "1000 Б", "ext" => "pdf", "purchased" => false, "download" => ""]], "count" => 1, "rev" => "r1"];
$chat = ["offerId" => 1, "title" => "Дизайнер", "subtitle" => "", "note" => null, "csrf" => "x", "messages" => [], "write" => true,
    "footer" => null, "workspace" => ["orderId" => 1, "upload" => false, "hide" => false, "chunk" => 1048576]];
echo '<span class="balance"><span data-balance>5 000 грн</span></span>';
echo View::fetch("partials/workspace", ["role" => "client", "order" => ["id" => 1, "title" => "Візитка", "product_name" => "Візитка",
    "status" => 4, "answers" => []], "offer" => ["price_designer" => 1000, "price_client" => 1200, "deadline" => null],
    "back" => ["href" => "/orders/", "label" => "Мої замовлення"], "who" => "Дизайнер Петро", "whoName" => "Петро",
    "turn" => ["mine" => true, "text" => "Ваш хід: перевірте макети і натисніть «Купую»", "sub" => "", "icon" => "fa-file-check"],
    "chat" => $chat, "panel" => $panel, "edit" => false, "csrf" => "x", "chunk" => 1048576,
    "quote" => ["amount_client" => 1200, "amount_designer" => 1000, "price_client" => 1200, "price_designer" => 1000, "balance" => 5000,
        "credit" => 0, "after" => 3800, "can" => true, "reason" => "", "new_layouts" => 1]]);
'''


def page_html(render: str = RENDER, scripts=('chat.js', 'chunk-upload.js', 'workspace-layouts.js', 'workspace.js'), head: str = '') -> str:
    body = subprocess.check_output(['php', '-r', render], cwd=ROOT, text=True)
    tags = ''.join('<script src="/assets/%s"></script>' % (s if '/' in s else 'js/' + s) for s in scripts)
    return '<!doctype html><html><head><meta charset="utf-8">%s</head><body>%s%s</body></html>' % (head, body, tags)


class Site:
    """Перехоплені запити сторінки: upload — функція (route, request) тесту; решта — заготовлені відповіді"""

    def __init__(self, page, html=None):
        self.html = html or page_html()
        self.upload = None
        self.buy = None
        self.cloud = None
        self.layout_gets = 0
        self.quotes = 0
        self.uploads = []
        self.layouts = [{'id': 7, 'label': 'Візитка', 'original_name': 'Візитка.pdf', 'size': 1000, 'size_text': '1000 Б', 'ext': 'pdf'}]
        self.messages = []
        self.reworks = []
        page.route(BASE + '/**', self.handle)

    def json(self, route, data, status=200):
        route.fulfill(status=status, content_type='application/json', body=json.dumps(data, ensure_ascii=False))

    def handle(self, route, request):
        path = request.url[len(BASE):].split('?')[0]
        if path == '/':
            route.fulfill(content_type='text/html; charset=utf-8', body=self.html)
        elif path.startswith('/assets/'):
            with open(os.path.join(ROOT, path.lstrip('/')), encoding='utf-8') as f:
                route.fulfill(content_type='text/css' if path.endswith('.css') else 'application/javascript', body=f.read())
        elif path == '/orders/1/upload':
            self.uploads.append(request.post_data_buffer or b'')
            self.upload(route, request)
        elif path == '/orders/1/buy' and request.method == 'GET':
            self.quotes += 1
            self.json(route, {'quote': {}, 'html': '<p data-fresh>Свіжа сума</p>'
                              '<button type="button" class="btn" data-pay-submit data-amount="1200">Оплатити</button>'})
        elif path == '/orders/1/buy':
            self.buy(route, request)
        elif path == '/orders/1/rework' and request.method == 'POST':
            self.reworks.append(request.post_data or '')
            route.fulfill(content_type='text/html; charset=utf-8', body=self.html)
        elif path == '/orders/1/layouts/7/cloud' and request.method == 'POST':
            self.cloud(route, request)
        elif path == '/orders/1/layouts':
            self.layout_gets += 1
            self.json(route, {'layouts': self.layouts, 'count': len(self.layouts), 'rev': 'r%d' % len(self.uploads)})
        elif path == '/offers/1/messages':
            msgs, self.messages = self.messages, []
            self.json(route, {'messages': msgs, 'write': True, 'workspace': None})
        else:
            route.fulfill(status=404, body='')


def run(test, html=None):
    with sync_playwright() as p:
        b = p.chromium.launch()
        try:
            pg = b.new_page()
            site = Site(pg, html)
            pg.goto(BASE + '/')
            test(pg, site)
        finally:
            b.close()


SEND = '''async (o) => {
  const file = new File(['0123456789ABCDE'], 'a.pdf');
  try { await window.DesignUpload.send(file, {url: '/orders/1/upload', csrf: 'x', chunk: 10, fields: {purpose: 'layout'}});
        return 'ok'; }
  catch (e) { return e.status + ' ' + e.message; }
}'''


def test_final_error_on_last_part_not_retried():
    def test(pg, site):
        def upload(route, request):
            last = b'name="meta[chunkIndex]"\r\n\r\n1' in (request.post_data_buffer or b'')
            if not last:
                site.json(route, {'done': False})
            else:
                site.json(route, {'error': 'Не вдалося обробити файл — завантажте його ще раз', 'final': True}, 500)
        site.upload = upload
        assert pg.evaluate(SEND) == '500 Не вдалося обробити файл — завантажте його ще раз'
        assert len(site.uploads) == 2, 'остання частина — один раз, без повторів'

        # Звичайна 5xx (502 від nginx, 503 «Сервер зайнятий» до склеювання) — як і раніше, до 3 повторів
        site.uploads.clear()
        def flaky(route, request):
            last = b'name="meta[chunkIndex]"\r\n\r\n1' in (request.post_data_buffer or b'')
            if not last:
                site.json(route, {'done': False})
            else:
                site.json(route, {'error': 'Сервер зайнятий — спробуйте ще раз'}, 503)
        site.upload = flaky
        assert pg.evaluate(SEND) == '503 Сервер зайнятий — спробуйте ще раз'
        assert len(site.uploads) == 1 + 4, 'перша частина + остання 1 раз і 3 повтори'
    run(test)


def buttons(pg, layout_id):
    return pg.eval_on_selector_all('.lp-list .lp-item[data-lp-id="%d"] button' % layout_id, 'bs => bs.map(b => b.disabled)')


def test_replace_locks_layout_while_uploading():
    def test(pg, site):
        held = []
        site.upload = lambda route, request: held.append(route)
        assert buttons(pg, 7) == [False, False, False]   # олівець, «Замінити», «Видалити»

        with pg.expect_file_chooser() as fc:
            pg.click('.lp-item[data-lp-id="7"] [data-lp-replace]')
        fc.value.set_files(files=[{'name': 'Візитка v2.pdf', 'mimeType': 'application/pdf', 'buffer': b'%PDF-1.4 new'}])
        pg.wait_for_function('() => document.querySelector("[data-lp-uploads] .is-uploading") !== null')
        assert buttons(pg, 7) == [True, True, True], 'під час заміни — усе вимкнено'

        # Панель перемалювалась (опитування побачило зміну) — новий пункт однаково вимкнений
        item = '.lp-list .lp-item[data-lp-id="7"]'
        pg.eval_on_selector(item, 'li => { li.dataset.old = "1"; }')
        pg.evaluate('''() => document.querySelector('[data-chat]').dispatchEvent(new CustomEvent('chat:poll',
            {detail: {workspace: {layouts_rev: 'інша', hidden_previews: []}}}))''')
        pg.wait_for_function('s => !document.querySelector(s).dataset.old', arg=item)
        assert buttons(pg, 7) == [True, True, True], 'після перемальовування теж вимкнено'

        # Помилка заміни — пункт знову доступний
        for _ in range(50):
            if held:
                break
            pg.wait_for_timeout(100)
        assert len(held) == 1, 'одна частина заміни'
        site.json(held.pop(), {'error': 'Макет не знайдено: його вже замінено або видалено', 'final': True}, 422)
        pg.wait_for_function('() => document.querySelector("[data-lp-uploads] .is-error") !== null')
        pg.wait_for_timeout(300)
        assert buttons(pg, 7) == [False, False, False], 'після завершення заміни — знову доступно'
    run(test)


def test_system_message_without_links():
    def test(pg, site):
        site.messages = [
            {'id': 5, 'mine': False, 'system': True, 'author': '', 'text': 'Завантажено макет «https://evil.example/pay» · 2 МБ',
             'time': '2026-11-20 10:00', 'preview': None, 'reply': None},
            {'id': 6, 'mine': False, 'system': False, 'author': 'Тарас Г.', 'text': 'Гляньте https://a.ua/x',
             'time': '2026-11-20 10:01', 'preview': None, 'reply': None},
        ]
        pg.evaluate("() => window.DesignChat.poll(document.querySelector('[data-chat]'))")
        pg.wait_for_selector('.chat-sys')
        assert pg.inner_text('.chat-sys') == 'Завантажено макет «https://evil.example/pay» · 2 МБ'
        assert pg.eval_on_selector_all('.chat-sys a', 'a => a.length') == 0
        assert pg.get_attribute('.chat-msg[data-id="6"] .text a', 'href') == 'https://a.ua/x'
    run(test)


BUY_HTML = None


def buy_html() -> str:
    global BUY_HTML
    if BUY_HTML is None:
        BUY_HTML = page_html(RENDER_BUY, ('vendor/bootstrap-5.3.3/js/bootstrap.bundle.min.js', 'chat.js', 'workspace-layouts.js',
                                          'workspace.js', 'workspace-buy.js'))
    return BUY_HTML


def test_buy_double_click_one_request_then_panel():
    def test(pg, site):
        held = []
        site.buy = lambda route, request: held.append((route, request))
        assert pg.is_enabled('[data-ws-buy]')
        pg.click('[data-ws-buy]')
        pg.wait_for_selector('[data-pay-body] [data-fresh]')          # вікно перечитало вміст
        assert site.quotes == 1
        pg.wait_for_selector('[data-pay].show')
        pg.dblclick('[data-pay-body] [data-pay-submit]')
        pg.click('[data-pay-body] [data-pay-submit]', force=True, no_wait_after=True)
        pg.wait_for_timeout(400)
        assert len(held) == 1, 'подвійний натиск — один запит'
        route, request = held.pop()
        assert 'name="amount"\r\n\r\n1200' in (request.post_data or '')
        assert pg.inner_text('[data-pay-body] [data-pay-submit]') == 'Оплачуємо…'
        site.json(route, {'ok': True, 'count': 1, 'rev': 'r2', 'layouts': [
            {'id': 7, 'label': 'Візитка', 'original_name': 'Візитка.pdf', 'size': 1000, 'size_text': '1000 Б', 'ext': 'pdf',
             'purchased': True, 'download': 'https://dev.i.fastcdn.kiev.ua/cf/aa/bb/cc/dd.pdf?name=x&download=1'}],
            'turn': {'mine': False, 'text': 'Макети ваші', 'sub': '', 'icon': 'fa-check-circle'},
            'status_html': '<span class="status s6"><i class="fal fa-check-circle"></i>Куплено</span>',
            'balance_text': '3 800 грн'})
        pg.wait_for_selector('[data-pay]', state='hidden')
        assert pg.get_attribute('.lp-item[data-lp-id="7"] .lp-download', 'href').startswith('https://dev.i.fastcdn.kiev.ua/cf/')
        assert pg.eval_on_selector_all('.lp-item[data-lp-id="7"] [data-lp-cloud]', 'e => e.length') == 1
        assert pg.inner_text('[data-ws-turn-text]') == 'Макети ваші'
        assert pg.inner_text('[data-ws-status]') == 'Куплено'
        assert pg.inner_text('[data-balance]') == '3 800 грн', 'баланс у шапці'
        assert pg.eval_on_selector_all('[data-ws-buy]', 'e => e.length') == 0
        assert pg.is_hidden('[data-lp-lock]')
    run(test, buy_html())


def test_buy_refusal_shows_error_and_fresh_window():
    def test(pg, site):
        site.buy = lambda route, request: site.json(route, {'error': 'Ціна змінилася — перевірте суму',
            'html': '<p data-new>Сума 1 500 грн</p><button type="button" data-pay-submit data-amount="1500">Оплатити</button>'}, 400)
        pg.click('[data-ws-buy]')
        pg.wait_for_selector('[data-pay-body] [data-fresh]')
        pg.click('[data-pay-body] [data-pay-submit]')
        pg.wait_for_selector('[data-pay-body] [data-new]')
        assert pg.inner_text('[data-pay-error]') == 'Ціна змінилася — перевірте суму'
        assert pg.get_attribute('[data-pay-body] [data-pay-submit]', 'data-amount') == '1500'
        assert pg.is_enabled('[data-pay-body] [data-pay-submit]')
        assert pg.eval_on_selector_all('[data-ws-buy]', 'e => e.length') == 1, '«Купую» лишається'

        # Панель без некуплених макетів (видалено опитуванням) — «Купую» вимкнена, з підказкою
        pg.evaluate("""() => window.DesignWs.panel('1').render({layouts: [], count: 0, rev: 'r9'})""")
        assert pg.is_disabled('[data-ws-buy]')
        assert pg.is_visible('[data-ws-buy-hint]')
    run(test, buy_html())


def test_buy_refusal_without_window_no_retry_reload_on_close():
    """400 без html («Макети вже куплено» в іншій вкладці): текст, «Оплатити» не вмикається, закриття вікна — перезавантаження"""
    def test(pg, site):
        site.buy = lambda route, request: site.json(route, {'error': 'Макети вже куплено'}, 400)
        pg.click('[data-ws-buy]')
        pg.wait_for_selector('[data-pay-body] [data-fresh]')
        pg.wait_for_selector('[data-pay].show')
        pg.click('[data-pay-body] [data-pay-submit]')
        pg.wait_for_function('() => !document.querySelector("[data-pay-error]").hidden')
        assert pg.inner_text('[data-pay-error]') == 'Макети вже куплено'
        pg.wait_for_timeout(200)
        assert pg.is_disabled('[data-pay-body] [data-pay-submit]'), 'повтор безглуздий'
        pg.evaluate('() => { window.__stay = 1; }')
        with pg.expect_navigation():
            pg.click('[data-pay] [data-bs-dismiss="modal"]')
        assert pg.evaluate('() => window.__stay') is None, 'сторінку перезавантажено'
    run(test, buy_html())


def cloud_layout(status):
    return {'id': 7, 'label': 'Візитка', 'original_name': 'Візитка.pdf', 'size': 1000, 'size_text': '1000 Б', 'ext': 'pdf',
            'purchased': True, 'download': 'https://dev.i.fastcdn.kiev.ua/cf/aa/bb/cc/dd.pdf?name=x&download=1', 'cloud_status': status}


def cloud_html() -> str:
    # Клієнт, куплений макет (cloud_status 0); опитування копій — раз на 300 мс замість 10 с
    render = RENDER_BUY.replace('"purchased" => false, "download" => ""',
                                '"purchased" => true, "download" => "https://dev.i.fastcdn.kiev.ua/cf/aa/bb/cc/dd.pdf", "cloud_status" => 0')
    html = page_html(render, ('vendor/bootstrap-5.3.3/js/bootstrap.bundle.min.js', 'chat.js', 'workspace-layouts.js',
                              'workspace.js', 'workspace-buy.js'))
    return html.replace('data-layouts ', 'data-layouts data-cloud-poll="300" ', 1)


def test_cloud_copy_button_states():
    def test(pg, site):
        cloud = '.lp-item[data-lp-id="7"] [data-lp-cloud]'
        held = []
        site.cloud = lambda route, request: held.append((route, request))
        assert pg.inner_text(cloud).strip() == 'Скопіювати в хмару'
        pg.dblclick(cloud + ' [data-lp-cloud-go]')
        pg.wait_for_timeout(300)
        assert len(held) == 1, 'подвійний натиск — один запит'
        route, request = held.pop()
        assert 'name="csrf"\r\n\r\nx' in (request.post_data or '')
        site.layouts = [cloud_layout(1)]
        site.json(route, {'layouts': [cloud_layout(1)], 'count': 1, 'rev': 'r2'})
        pg.wait_for_function('s => document.querySelector(s).dataset.lpCloud === "1"', arg=cloud)
        assert pg.inner_text(cloud).strip() == 'Копіюємо…'

        # У черзі — панель перечитується; крон скопіював — «У хмарі ✓», опитування зупинилось
        gets = site.layout_gets
        pg.wait_for_timeout(900)
        assert site.layout_gets >= gets + 2, 'опитування, поки макет у черзі'
        site.layouts = [cloud_layout(2)]
        pg.wait_for_function('s => document.querySelector(s).dataset.lpCloud === "2"', arg=cloud)
        assert pg.inner_text(cloud).strip() == 'У хмарі ✓'
        assert pg.eval_on_selector_all(cloud + ' button', 'b => b.length') == 0
        gets = site.layout_gets
        pg.wait_for_timeout(900)
        assert site.layout_gets == gets, 'у хмарі — опитування зупинилось'

        # Помилка копіювання — «Не вдалося, спробуйте ще»; натиск — новий запит у чергу
        pg.evaluate("() => window.DesignWs.panel('1').render({layouts: [%s], count: 1, rev: 'r3'})" % json.dumps(cloud_layout(3)))
        assert pg.inner_text(cloud).strip() == 'Не вдалося, спробуйте ще'
        pg.click(cloud + ' [data-lp-cloud-go]')
        pg.wait_for_timeout(300)
        assert len(held) == 1, 'повтор після помилки'
        route, _ = held.pop()
        site.json(route, {'error': 'Сесія застаріла — оновіть сторінку'}, 403)
        pg.wait_for_function('() => !document.querySelector("[data-lp-error]").hidden')
        assert pg.inner_text('[data-lp-error]') == 'Сесія застаріла — оновіть сторінку'
        assert pg.is_enabled(cloud + ' [data-lp-cloud-go]'), 'після відмови можна натиснути знову'
    run(test, cloud_html())


# Робочий простір купленого замовлення: дизайнер з відкритою формою «Ще не закінчили» / клієнт з пропозицією без відповіді
RENDER_REWORK = r"""
require "helpers/Design/bootstrap.php";
use Design\View;
$designer = getenv("WS_ROLE") === "designer";
$panel = ["layouts" => [["id" => 7, "label" => "Візитка", "original_name" => "Візитка.pdf", "size" => 1000,
    "size_text" => "1000 Б", "ext" => "pdf", "purchased" => true, "download" => "", "cloud_status" => 0]], "count" => 1, "rev" => "r1"];
$chat = ["offerId" => 1, "title" => "Х", "subtitle" => "", "note" => null, "csrf" => "x", "messages" => [], "write" => true,
    "footer" => null, "workspace" => ["orderId" => 1, "upload" => false, "hide" => false, "chunk" => 1048576]];
$pending = $designer ? null : ["price_client" => 1560, "extra" => 360, "deadline" => "2026-12-01", "price_designer" => 1300];
echo View::fetch("partials/workspace", ["role" => $designer ? "designer" : "client", "order" => ["id" => 1, "title" => "Візитка",
    "product_name" => "Візитка", "status" => 6, "answers" => []], "offer" => ["price_designer" => 1000, "price_client" => 1200, "deadline" => null],
    "back" => ["href" => "/", "label" => "Назад"], "who" => "Х", "whoName" => "Х",
    "turn" => ["mine" => !$designer, "text" => $designer ? "Готово" : "Чекає вашої відповіді", "sub" => "", "icon" => "fa-hand-paper"],
    "chat" => $chat, "panel" => $panel, "edit" => false, "csrf" => "x", "chunk" => 1048576, "quote" => null,
    "rework" => ["pending" => $pending, "propose" => $designer, "answer" => !$designer, "cancel" => false, "paid_client" => 1200,
        "paid_designer" => 1000, "k" => 20.0, "round" => 10, "stage" => $designer ? "6" : "6p", "form" => $designer,
        "values" => ["price" => "1000", "deadline" => ""]]]);
"""


def rework_html(role: str) -> str:
    os.environ['WS_ROLE'] = role
    try:
        return page_html(RENDER_REWORK, ('app.js', 'chat.js', 'workspace-layouts.js', 'workspace.js'))
    finally:
        del os.environ['WS_ROLE']


NB = ' '


def test_rework_form_live_hint():
    def test(pg, site):
        hint = '[data-ws-rework] [data-rework-client]'
        assert pg.inner_text(hint) == 'Для клієнта — без доплати', 'та сама сума — без доплати'
        pg.fill('[data-rework-price]', '1 300')
        assert pg.inner_text(hint) == 'Клієнт доплатить +360' + NB + 'грн', '1 300·1,2 = 1 560 − 1 200'
        pg.fill('[data-rework-price]', '1005')
        assert pg.inner_text(hint) == 'Клієнт доплатить +10' + NB + 'грн', '1 206 → 1 210 (крок 10)'
        pg.fill('[data-rework-price]', '900')
        assert pg.inner_text(hint) == 'Не менше вже нарахованих 1' + NB + '000' + NB + 'грн'
        pg.fill('[data-rework-price]', '')
        assert pg.inner_text(hint) == 'Вкажіть суму — покажемо доплату клієнта'
        # Відкрита форма — опитування зі зміненим stage сторінку не перечитує
        pg.evaluate('() => { window.__stay = 1; }')
        pg.evaluate("""() => document.querySelector('[data-chat]').dispatchEvent(new CustomEvent('chat:poll',
            {detail: {workspace: {stage: '6p', turn: {mine: false, text: 'Чекаємо відповіді клієнта', sub: '', icon: 'fa-hourglass-half'},
             layouts_rev: 'r1', hidden_previews: []}}}))""")
        pg.wait_for_timeout(300)
        assert pg.evaluate('() => window.__stay') == 1
    run(test, rework_html('designer'))


def test_rework_decline_confirm_and_stage_reload():
    def test(pg, site):
        assert pg.is_visible('form[action="/orders/1/rework"] button:has-text("Прийняти")')
        decline = 'form[action="/orders/1/rework"]:has(input[value="decline"]) button'
        pg.once('dialog', lambda d: d.dismiss())
        pg.click(decline)
        pg.wait_for_timeout(300)
        assert site.reworks == [], 'скасоване підтвердження — нічого не надіслано'
        assert pg.get_attribute(decline, 'aria-disabled') is None, 'і форма не заблокована'
        pg.once('dialog', lambda d: d.accept())
        with pg.expect_navigation():
            pg.click(decline)
        assert len(site.reworks) == 1 and 'action=decline' in site.reworks[0]

        # Співрозмовник змінив стан: у полі чату текст — не перечитуємо; поле порожнє — перечитуємо
        poll = """() => document.querySelector('[data-chat]').dispatchEvent(new CustomEvent('chat:poll',
            {detail: {workspace: {stage: '7', turn: {mine: false, text: 'Дизайнер працює над змінами', sub: '', icon: 'fa-pencil-ruler'},
             layouts_rev: 'r1', hidden_previews: []}}}))"""
        pg.evaluate('() => { window.__stay = 1; }')
        pg.fill('[data-chat-input]', 'Ще одне')
        pg.evaluate(poll)
        pg.wait_for_timeout(300)
        assert pg.evaluate('() => window.__stay') == 1, 'чернетку в чаті не губимо'
        pg.evaluate(poll.replace("stage: '7'", "stage: '6p'"))
        pg.fill('[data-chat-input]', '')
        pg.wait_for_timeout(200)
        assert pg.evaluate('() => window.__stay') == 1, 'той самий stage — нічого'
        with pg.expect_navigation():
            pg.evaluate(poll)
        assert pg.evaluate('() => window.__stay') is None, 'сторінку перезавантажено'
    run(test, rework_html('client'))
