"""
JS робочого простору без сервера: справжні partials/chat (з робочим простором) і partials/layouts-panel, відрендерені PHP,
і справжні assets/js/chat.js, chunk-upload.js, workspace.js у Chromium; запити сторінки перехоплює page.route (адреса
https://ws.test/ вигадана — у мережу нічого не йде, БД лише читає Settings).
Перевіряє: (1) остаточну помилку останньої частини ({final:true}) chunk-upload.js не повторює, звичайну 5xx — повторює;
(2) поки завантажується заміна макета, «Замінити», «Видалити» й олівець цього макета вимкнені (і після перемальовування панелі);
(3) системне повідомлення з адресою в тексті chat.js показує текстом, без посилання.
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
    "size_text" => "1000 Б", "ext" => "pdf"]], "count" => 1, "rev" => "r1"];
echo View::fetch("partials/chat", ["offerId" => 1, "title" => "Клієнт", "subtitle" => "", "note" => null, "csrf" => "x",
    "messages" => [], "write" => true, "footer" => null,
    "workspace" => ["orderId" => 1, "upload" => true, "hide" => true, "chunk" => 1048576]]);
echo View::fetch("partials/layouts-panel", ["orderId" => 1, "panel" => $panel, "edit" => true, "csrf" => "x", "chunk" => 1048576]);
'''


def page_html() -> str:
    body = subprocess.check_output(['php', '-r', RENDER], cwd=ROOT, text=True)
    scripts = ''.join('<script src="/assets/js/%s"></script>' % s for s in ('chat.js', 'chunk-upload.js', 'workspace.js'))
    return '<!doctype html><html><head><meta charset="utf-8"></head><body>%s%s</body></html>' % (body, scripts)


class Site:
    """Перехоплені запити сторінки: upload — функція (route, request) тесту; решта — заготовлені відповіді"""

    def __init__(self, page):
        self.html = page_html()
        self.upload = None
        self.uploads = []
        self.layouts = [{'id': 7, 'label': 'Візитка', 'original_name': 'Візитка.pdf', 'size': 1000, 'size_text': '1000 Б', 'ext': 'pdf'}]
        self.messages = []
        page.route(BASE + '/**', self.handle)

    def json(self, route, data, status=200):
        route.fulfill(status=status, content_type='application/json', body=json.dumps(data, ensure_ascii=False))

    def handle(self, route, request):
        path = request.url[len(BASE):].split('?')[0]
        if path == '/':
            route.fulfill(content_type='text/html; charset=utf-8', body=self.html)
        elif path.startswith('/assets/js/'):
            with open(os.path.join(ROOT, path.lstrip('/')), encoding='utf-8') as f:
                route.fulfill(content_type='application/javascript', body=f.read())
        elif path == '/orders/1/upload':
            self.uploads.append(request.post_data_buffer or b'')
            self.upload(route, request)
        elif path == '/orders/1/layouts':
            self.json(route, {'layouts': self.layouts, 'count': len(self.layouts), 'rev': 'r%d' % len(self.uploads)})
        elif path == '/offers/1/messages':
            msgs, self.messages = self.messages, []
            self.json(route, {'messages': msgs, 'write': True, 'workspace': None})
        else:
            route.fulfill(status=404, body='')


def run(test):
    with sync_playwright() as p:
        b = p.chromium.launch()
        try:
            pg = b.new_page()
            site = Site(pg)
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
