// Майстерня дизайну: «Купую» у шапці робочого простору клієнта і вікно «Оплата: звідки?» (views/partials/pay-modal.php).
// - «Купую» відкриває вікно й одразу перечитує вкладку «З балансу» (GET /orders/{id}/buy → {quote, html}): баланс, сума й нові
//   макети могли змінитися після відкриття сторінки. 404 — замовлення вже не купити (куплено в іншій вкладці): сторінка оновлюється.
// - «Оплатити» / «Відкрити макети» → POST /orders/{id}/buy (amount — сума, яку клієнт бачить, csrf). Поки запит іде, кнопка
//   вимкнена і другий натиск нічого не шле (подвійне списання сервер однаково не допустить). Успіх — вікно закривається,
//   панель «Макети» перемальовується з відповіді («Завантажити»), «чий хід» і статус у шапці оновлюються, «Купую» зникає,
//   баланс у шапці (і stage для опитування — сторінку не перечитуємо), на телефоні відкривається вкладка «Макети».
//   Відмова (400) — текст і свіжий вміст вкладки з відповіді;
//   відмова без вмісту (купити вже не можна: куплено в іншій вкладці, застаріла сесія) — текст, «Оплатити» лишається вимкненою,
//   панель перечитується, а закриття вікна перезавантажує сторінку. Збій мережі / 5xx — «Оплатити» знову доступна.
// - «Купую» вимкнена, поки немає некуплених макетів: стежить за подією 'ws:layouts' панелі (workspace-layouts.js).
// HTML вкладки — лише з сервера (partials/pay-balance.php, екранований); текст помилок — через textContent.
(function () {
  var ws = window.DesignWs;
  var buyBtn = document.querySelector('[data-ws-buy]');
  var modalEl = document.querySelector('[data-pay]');
  if (!ws || !buyBtn || !modalEl) return;
  var orderId = modalEl.dataset.order;
  var body = modalEl.querySelector('[data-pay-body]');
  var errorBox = modalEl.querySelector('[data-pay-error]');
  var hint = document.querySelector('[data-ws-buy-hint]');
  var loading = false, paying = false, bought = false, stale = false;

  function modal() { return window.bootstrap.Modal.getOrCreateInstance(modalEl); }

  function error(msg) {
    errorBox.textContent = msg || '';
    errorBox.hidden = !msg;
  }

  function setLoading(on) {
    loading = on;
    body.classList.toggle('is-loading', on);
    body.setAttribute('aria-busy', on ? 'true' : 'false');
  }

  // Свіжий вміст вкладки «З балансу»
  function refresh() {
    setLoading(true);
    ws.request('GET', '/orders/' + orderId + '/buy', null, function (status, json) {
      setLoading(false);
      if (paying) return;
      if (status === 200 && json && json.html) { body.innerHTML = json.html; return; }
      if (status === 404) { window.location.reload(); return; }
      error(ws.failText(status, json, 'Не вдалося оновити суму — спробуйте ще раз'));
    });
  }

  buyBtn.addEventListener('click', function () {
    if (buyBtn.disabled || bought) return;
    error('');
    modal().show();
    refresh();
  });

  // Після купівлі: шапка й панель — з відповіді, без перезавантаження
  function apply(json) {
    bought = true;
    var panel = ws.panel(orderId);
    if (json.done || !panel) { window.location.reload(); return; }
    panel.render(json);
    var status = document.querySelector('[data-ws-status]');
    if (status && json.status_html) status.innerHTML = json.status_html;
    var balance = document.querySelector('[data-balance]');
    if (balance && json.balance_text) balance.textContent = json.balance_text;
    // Новий стан уже на сторінці — опитування не перечитує її (workspace.js)
    var turnBox = document.querySelector('[data-ws-turn][data-ws-stage]');
    if (turnBox && json.stage) turnBox.dataset.wsStage = json.stage;
    var cta = buyBtn.closest('.ws-cta');
    if (cta) cta.remove(); else buyBtn.remove();
    var tabIcon = document.querySelector('[data-ws-tab-icon]');
    if (tabIcon) tabIcon.className = 'fal fa-folder';
    var tab = document.querySelector('[data-ws-tab="layouts"]');
    if (tab && window.getComputedStyle(tab.parentNode).display !== 'none') tab.click();
    var root = document.querySelector('[data-chat][data-order="' + orderId + '"]');
    if (root && window.DesignChat) window.DesignChat.poll(root);   // системне повідомлення «Клієнт купив макети…»
  }

  body.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-pay-submit]');
    if (!btn || btn.disabled || paying || loading) return;
    paying = true;
    btn.disabled = true;
    var label = btn.innerHTML;
    btn.textContent = btn.dataset.amount === '0' ? 'Відкриваємо…' : 'Оплачуємо…';
    error('');
    ws.request('POST', '/orders/' + orderId + '/buy', { amount: btn.dataset.amount, csrf: modalEl.dataset.csrf }, function (status, json) {
      paying = false;
      if (status === 200 && json && json.ok) {
        modal().hide();
        apply(json);
        return;
      }
      error(ws.failText(status, json, 'Не вдалося оплатити — спробуйте ще раз'));
      btn.innerHTML = label;
      if (json && json.html) { body.innerHTML = json.html; return; }   // свіжа сума / причина
      if (status >= 400 && status < 500) {
        // Купити вже не можна — повтор безглуздий: панель перечитуємо, сторінку — після закриття вікна
        stale = true;
        var panel = ws.panel(orderId);
        if (panel) panel.refresh();
        return;
      }
      btn.disabled = false;
    });
  });

  modalEl.addEventListener('hidden.bs.modal', function () { if (stale) window.location.reload(); });

  // «Купую» — лише коли є некуплені макети (дизайнер завантажив новий, макет видалено — панель перемальовується опитуванням)
  document.addEventListener('ws:layouts', function (e) {
    if (bought || !e.detail || String(e.detail.order) !== String(orderId)) return;
    var fresh = e.detail.layouts.some(function (l) { return !l.purchased; });
    buyBtn.disabled = !fresh;
    if (hint) hint.hidden = fresh;
  });
})();
