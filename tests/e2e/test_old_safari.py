"""
Браузер без SubmitEvent.submitter (Safari < 15.4): кнопки форм мають працювати.
Запуск: DESIGN_TEST_CUSTOMER_ID=29 python3 -m pytest tests/e2e -q
"""
import hashlib, hmac, os, subprocess

from playwright.sync_api import sync_playwright

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
UA = 'DesignE2E/1'
NO_SUBMITTER = "Object.defineProperty(SubmitEvent.prototype, 'submitter', {get: () => undefined});"


def php(code: str) -> str:
    return subprocess.check_output(['php', '-r', 'require "helpers/Design/bootstrap.php"; ' + code], cwd=ROOT, text=True).strip()


def test_publish_and_next_without_submitter():
    me = int(os.environ['DESIGN_TEST_CUSTOMER_ID'])
    pw = php('echo Design\\Sql::value("SELECT customer_pass FROM customers WHERE id = ?", [%d]);' % me)
    cookie = '%d.%s' % (me, hmac.new(str(me).encode(), (pw + UA).encode(), hashlib.md5).hexdigest())
    pid = php('echo Design\\Products::visible()[0]["id"];')
    ids = []
    try:
        with sync_playwright() as p:
            b = p.chromium.launch(args=['--host-resolver-rules=MAP dev.design.wolf.ua 127.0.0.1'])
            ctx = b.new_context(ignore_https_errors=True, user_agent=UA)
            ctx.add_init_script(NO_SUBMITTER)
            ctx.add_cookies([{'name': 'wolfid', 'value': cookie, 'domain': 'dev.design.wolf.ua', 'path': '/'}])
            pg = ctx.new_page()

            # Крок 2: «Далі» веде на крок 3, а не зберігає чернетку
            pg.goto('https://dev.design.wolf.ua/brief/%s/' % pid)
            pg.fill('textarea[name="answers[]"] >> nth=0', 'Тест e2e')
            pg.click('button[value="next"]')
            pg.wait_for_url('**/mode/')
            oid = int(pg.url.rstrip('/').split('/')[-2])
            ids.append(oid)

            # Крок 3: «Опублікувати бриф» публікує
            pg.click('button[value="publish"]')
            pg.wait_for_url('**/orders/%d/' % oid)
            assert php('echo Design\\Orders::get(%d, %d)["status"];' % (oid, me)) == '2'
            b.close()
    finally:
        for i in ids:
            php('Design\\Sql::exec("DELETE FROM design_orders WHERE id = ? AND customer_id = ?", [%d, %d]);' % (i, me))
