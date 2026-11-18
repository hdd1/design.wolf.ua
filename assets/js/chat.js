// Майстерня дизайну: листування по пропозиції (views/partials/chat.php) — відправка без перезавантаження і опитування раз на 15 с
(function () {
  var POLL_MS = 15000;
  var MONTHS = ['січня', 'лютого', 'березня', 'квітня', 'травня', 'червня', 'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня'];
  var URL_RE = /https?:\/\/[^\s<>"']+/gi;
  var URL_TAIL = /[.,;:!?)\]}»…]+$/;

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }

  function icon(cls) { return el('i', 'fal ' + cls); }

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  function today() {
    var d = new Date();
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }

  // '2026-11-18' → «Сьогодні» / «18 листопада»
  function dayLabel(day) {
    if (day === today()) return 'Сьогодні';
    return +day.slice(8, 10) + ' ' + MONTHS[+day.slice(5, 7) - 1];
  }

  function initials(name) {
    return name.trim().split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('');
  }

  // Текст з клікабельними http(s)-посиланнями — лише текстові вузли, без innerHTML (як Chat::html на сервері)
  function richText(box, text) {
    var pos = 0, m;
    URL_RE.lastIndex = 0;
    while ((m = URL_RE.exec(text)) !== null) {
      var url = m[0].replace(URL_TAIL, '');
      if (!/^https?:\/\/./i.test(url)) continue;
      box.appendChild(document.createTextNode(text.slice(pos, m.index)));
      var a = el('a', '', url);
      a.href = url;
      a.target = '_blank';
      a.rel = 'noopener nofollow ugc';
      box.appendChild(a);
      pos = m.index + url.length;
    }
    box.appendChild(document.createTextNode(text.slice(pos)));
    return box;
  }

  function httpError(status) {
    if (status === 401) return 'Ви вийшли з акаунта wolf.ua — увійдіть знову';
    if (status === 0) return 'Немає звʼязку з сервером — спробуйте ще раз';
    return 'Не вдалося надіслати — спробуйте ще раз';
  }

  function init(root) {
    if (root.dataset.chatReady) return;
    root.dataset.chatReady = '1';
    var offerId = root.dataset.chat;
    var url = '/offers/' + offerId + '/messages';
    var csrf = root.dataset.csrf;
    var after = +root.dataset.after || 0;
    var writable = root.dataset.write === '1';
    var body = root.querySelector('[data-chat-body]');
    var list = root.querySelector('[data-chat-list]');
    var form = root.querySelector('[data-chat-form]');
    var input = root.querySelector('[data-chat-input]');
    var closed = root.querySelector('[data-chat-closed]');
    var errorBox = root.querySelector('[data-chat-error]');
    var timer = null, polling = false, stopped = false;

    function nearBottom() { return body.scrollHeight - body.scrollTop - body.clientHeight < 80; }
    function toBottom() { body.scrollTop = body.scrollHeight; }

    function showError(msg) {
      errorBox.textContent = msg || '';
      errorBox.hidden = !msg;
    }

    function setWritable(on) {
      writable = on;
      form.hidden = !on;
      closed.hidden = on;
      if (!on) stop();
    }

    function dropEmpty() {
      var empty = list.querySelector('[data-chat-empty]');
      if (empty) empty.remove();
    }

    // Розділювач дня перед повідомленням, якщо день змінився
    function daySeparator(day) {
      var days = list.querySelectorAll('[data-day]');
      if (days.length && days[days.length - 1].dataset.day === day) return;
      var sep = el('div', 'chat-day');
      sep.dataset.day = day;
      sep.appendChild(el('span', '', dayLabel(day)));
      list.appendChild(sep);
    }

    function build(m) {
      var item;
      if (m.system) {
        item = richText(el('div', 'chat-sys'), m.text);
      } else {
        item = el('div', m.mine ? 'chat-msg mine' : 'chat-msg');
        var bubble = el('div', 'bubble');
        var meta = el('div', 'meta');
        if (!m.mine) {
          item.appendChild(el('span', 'avatar avatar-sm', initials(m.author)));
          meta.appendChild(el('b', '', m.author));
          meta.appendChild(document.createTextNode(' '));
        }
        meta.appendChild(document.createTextNode(m.time.slice(11, 16)));
        var text = richText(el('div', 'text'), m.text);
        if (m.mine) { bubble.appendChild(text); bubble.appendChild(meta); } else { bubble.appendChild(meta); bubble.appendChild(text); }
        item.appendChild(bubble);
      }
      item.dataset.id = m.id;
      return item;
    }

    // Додає повідомлення в кінець; вже показане (своє, щойно надіслане) — переносить, щоб порядок ішов за id
    function append(m) {
      dropEmpty();
      var item = list.querySelector('[data-id="' + m.id + '"]') || build(m);
      daySeparator(m.time.slice(0, 10));
      list.appendChild(item);
    }

    // Ненадіслані лишаються останніми
    function pendingToEnd() {
      list.querySelectorAll('[data-pending]').forEach(function (p) { list.appendChild(p); });
    }

    function request(method, data, done) {
      var xhr = new XMLHttpRequest();
      xhr.open(method, method === 'GET' ? url + '?after=' + after : url);
      xhr.onload = function () {
        var json = null;
        try { json = JSON.parse(xhr.responseText); } catch (e) { /* не JSON: 502 від nginx тощо */ }
        done(xhr.status, json);
      };
      xhr.onerror = function () { done(0, null); };
      if (data) {
        var fd = new FormData();
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        xhr.send(fd);
      } else {
        xhr.send();
      }
    }

    function poll() {
      if (!root.isConnected) { stop(); return; }   // панель листування прибрали зі сторінки
      if (polling || stopped || document.hidden) return;
      polling = true;
      clearTimeout(timer);
      request('GET', null, function (status, json) {
        polling = false;
        if (status === 404 || status === 401) { stop(); return; }
        if (status === 200 && json && json.messages) {
          var stick = nearBottom();
          json.messages.forEach(function (m) {
            append(m);
            if (m.id > after) after = m.id;
          });
          if (json.messages.length) { pendingToEnd(); if (stick) toBottom(); }
          if (!json.write) { setWritable(false); return; }
        }
        schedule();
      });
    }

    function schedule() {
      clearTimeout(timer);
      if (!stopped && writable) timer = setTimeout(poll, POLL_MS);
    }

    function stop() {
      stopped = true;
      clearTimeout(timer);
    }

    function pendingItem(text) {
      dropEmpty();
      daySeparator(today());
      var item = el('div', 'chat-msg mine is-pending');
      item.dataset.pending = '';
      var bubble = el('div', 'bubble');
      bubble.appendChild(richText(el('div', 'text'), text));
      bubble.appendChild(el('div', 'meta', '…'));
      item.appendChild(bubble);
      list.appendChild(item);
      toBottom();
      return item;
    }

    // Мережа впала — бульбашка лишається з «Не надіслано · Повторити»
    function failed(item, text) {
      item.classList.remove('is-pending');
      item.classList.add('is-failed');
      var meta = item.querySelector('.meta');
      meta.textContent = '';
      meta.appendChild(icon('fa-exclamation-circle'));
      meta.appendChild(el('span', 'fail', ' Не надіслано · '));
      var retry = el('button', 'retry', 'Повторити');
      retry.type = 'button';
      retry.addEventListener('click', function () {
        item.classList.remove('is-failed');
        item.classList.add('is-pending');
        meta.textContent = '…';
        deliver(item, text);
      });
      meta.appendChild(retry);
    }

    function deliver(item, text) {
      request('POST', { text: text, csrf: csrf }, function (status, json) {
        if (status === 200 && json && json.message) {
          item.remove();
          append(json.message);
          toBottom();
          poll();
          return;
        }
        if (status === 0 || status >= 500) { failed(item, text); return; }
        // Відмова сервера (порожнє, задовге, сесія, листування закрите): текст — назад у поле
        item.remove();
        if (input.value.trim() === '') input.value = text;
        showError(json && json.error ? json.error : httpError(status));
        if (json && json.error === 'Листування закрите') setWritable(false);
      });
    }

    function autosize() {
      input.style.height = 'auto';
      input.style.height = Math.min(input.scrollHeight, 160) + 'px';
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = input.value.trim();
      if (text === '') { input.focus(); return; }
      showError('');
      input.value = '';
      autosize();
      deliver(pendingItem(text), text);
    });

    input.addEventListener('input', autosize);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
      }
    });

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) poll();
    });

    toBottom();
    if (writable) schedule(); else stop();
  }

  document.querySelectorAll('[data-chat]').forEach(init);
  // Для листування, вставленого на сторінку пізніше (бокова панель)
  window.DesignChat = { init: init };
})();
