// Майстерня дизайну: листування по пропозиції (views/partials/chat.php) — відправка без перезавантаження і опитування раз на 15 с.
// Робочий простір (data-order): картки прев'ю, цитати, відповідь на повідомлення / коментар до прев'ю (reply_to).
// Для assets/js/workspace.js: подія 'chat:message' на секції (detail — нове повідомлення з опитування чи відправки) і
// window.DesignChat.append / replace / reply / clearQuoteThumbs.
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

  // Як View::plural: «3 сторінки»
  function plural(n, one, few, many) {
    var d = n % 10, h = n % 100;
    return n + ' ' + (d === 1 && h !== 11 ? one : (d >= 2 && d <= 4 && (h < 12 || h > 14) ? few : many));
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

  // Кнопка, що ставить цитату над полем вводу (як $replyAttrs у chat.php)
  function replyButton(cls, id, title, text, thumb) {
    var b = el('button', cls);
    b.type = 'button';
    b.dataset.reply = id;
    b.dataset.quoteTitle = title;
    b.dataset.quoteText = text;
    if (thumb) b.dataset.quoteThumb = thumb;
    return b;
  }

  // Цитата над текстом відповіді: r — {id, author, text, preview_num, thumb}
  function quote(r) {
    var q = el('div', 'quote');
    q.dataset.quoteTo = r.id;
    if (r.thumb) {
      var img = el('img', 'quote-thumb');
      img.src = r.thumb;
      img.alt = '';
      img.loading = 'lazy';
      q.appendChild(img);
    }
    var body = el('div', 'quote-body');
    body.appendChild(el('b', '', r.preview_num !== null && r.preview_num !== undefined ? 'Коментар до v' + r.preview_num : r.author));
    if (r.text) body.appendChild(el('span', '', r.text));
    q.appendChild(body);
    return q;
  }

  function init(root) {
    if (root.dataset.chatReady) return;
    root.dataset.chatReady = '1';
    var offerId = root.dataset.chat;
    var url = '/offers/' + offerId + '/messages';
    var csrf = root.dataset.csrf;
    var after = +root.dataset.after || 0;
    var writable = root.dataset.write === '1';
    var replyOn = root.dataset.replyOn === '1';
    var body = root.querySelector('[data-chat-body]');
    var list = root.querySelector('[data-chat-list]');
    var form = root.querySelector('[data-chat-form]');
    var input = root.querySelector('[data-chat-input]');
    var closed = root.querySelector('[data-chat-closed]');
    var errorBox = root.querySelector('[data-chat-error]');
    var quoteBar = root.querySelector('[data-chat-quote]');
    var timer = null, polling = false, stopped = false;
    var replyTo = null;   // {id, title, text, thumb} — цитата над полем вводу

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
      if (!on) {
        setReply(null);
        replyOn = false;
        list.querySelectorAll('[data-reply]').forEach(function (b) { b.remove(); });
        stop();
      }
    }

    // Цитата над полем вводу (null — прибрати)
    function setReply(q) {
      if (!quoteBar) return;
      replyTo = q;
      quoteBar.hidden = !q;
      if (!q) return;
      quoteBar.querySelector('[data-chat-quote-title]').textContent = q.title;
      quoteBar.querySelector('[data-chat-quote-text]').textContent = q.text || '';
      var thumb = quoteBar.querySelector('[data-chat-quote-thumb]');
      thumb.hidden = !q.thumb;
      if (q.thumb) thumb.src = q.thumb; else thumb.removeAttribute('src');
      input.focus();
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

    // Картка прев'ю (як у chat.php): перша сторінка, vN, «N сторінок», підпис, «на основі vK», «Коментувати», «Відкрити»
    function previewItem(m) {
      var p = m.preview;
      var card = (p.urls[0] && p.urls[0].card) || '';
      var item = el('div', 'chat-msg is-preview' + (m.mine ? ' mine' : ''));
      if (!m.mine) item.appendChild(el('span', 'avatar avatar-sm', initials(m.author)));
      var box = el('div', 'pv-card' + (p.hidden ? ' is-hidden' : ''));
      box.dataset.preview = p.id;
      if (p.hidden) {
        var gone = el('div', 'pv-hidden');
        gone.appendChild(icon('fa-eye-slash'));
        gone.appendChild(el('span', '', 'Прев\'ю приховано дизайнером · v' + p.num));
        box.appendChild(gone);
      } else {
        var open = el('button', 'pv-img');
        open.type = 'button';
        open.dataset.pvOpen = p.id;
        open.setAttribute('aria-label', 'Відкрити v' + p.num);
        if (card) {
          var img = el('img');
          img.src = card;
          img.alt = '';
          img.loading = 'lazy';
          open.appendChild(img);
        } else {
          open.appendChild(icon('fa-image'));
        }
        open.appendChild(el('span', 'pv-num', 'v' + p.num));
        if (p.pages > 1) open.appendChild(el('span', 'pv-pages', plural(p.pages, 'сторінка', 'сторінки', 'сторінок')));
        box.appendChild(open);
      }
      var pb = el('div', 'pv-body');
      var meta = el('div', 'meta');
      meta.appendChild(el('b', '', m.mine ? 'Ви' : m.author));
      meta.appendChild(document.createTextNode(' ' + m.time.slice(11, 16)));
      if (p.based_on_num) {
        meta.appendChild(document.createTextNode(' '));
        var based = el('span', 'pv-based');
        based.appendChild(icon('fa-code-branch'));
        based.appendChild(document.createTextNode(' на основі v' + p.based_on_num));
        meta.appendChild(based);
      }
      pb.appendChild(meta);
      if (m.text) pb.appendChild(richText(el('div', 'text'), m.text));
      if (!p.hidden) {
        var acts = el('div', 'pv-acts');
        if (replyOn) {
          var c = replyButton('pv-act', m.id, 'Коментар до v' + p.num, m.text, card);
          c.appendChild(icon('fa-comment-alt-lines'));
          c.appendChild(document.createTextNode(' Коментувати'));
          acts.appendChild(c);
        }
        var o = el('button', 'pv-act');
        o.type = 'button';
        o.dataset.pvOpen = p.id;
        o.appendChild(icon('fa-expand-alt'));
        o.appendChild(document.createTextNode(' Відкрити'));
        acts.appendChild(o);
        pb.appendChild(acts);
      }
      box.appendChild(pb);
      item.appendChild(box);
      return item;
    }

    function msgReply(m) {
      var b = replyButton('msg-reply', m.id, m.mine ? 'Ви' : m.author, m.text, '');
      b.setAttribute('aria-label', 'Відповісти');
      b.appendChild(icon('fa-reply'));
      return b;
    }

    function build(m) {
      var item;
      if (m.system) {
        item = richText(el('div', 'chat-sys'), m.text);
      } else if (m.preview) {
        item = previewItem(m);
      } else {
        item = el('div', m.mine ? 'chat-msg mine' : 'chat-msg');
        var bubble = el('div', 'bubble');
        var meta = el('div', 'meta');
        if (!m.mine) {
          item.appendChild(el('span', 'avatar avatar-sm', initials(m.author)));
          meta.appendChild(el('b', '', m.author));
          meta.appendChild(document.createTextNode(' '));
        } else if (replyOn) {
          meta.appendChild(msgReply(m));
          meta.appendChild(document.createTextNode(' '));
        }
        meta.appendChild(document.createTextNode(m.time.slice(11, 16)));
        if (!m.mine && replyOn) {
          meta.appendChild(document.createTextNode(' '));
          meta.appendChild(msgReply(m));
        }
        var text = richText(el('div', 'text'), m.text);
        if (m.mine) {
          if (m.reply) bubble.appendChild(quote(m.reply));
          bubble.appendChild(text);
          bubble.appendChild(meta);
        } else {
          bubble.appendChild(meta);
          if (m.reply) bubble.appendChild(quote(m.reply));
          bubble.appendChild(text);
        }
        item.appendChild(bubble);
      }
      item.dataset.id = m.id;
      return item;
    }

    // Додає повідомлення в кінець; вже показане (своє, щойно надіслане) — переносить, щоб порядок ішов за id.
    // Нове для сторінки — подія 'chat:message' (workspace.js збирає з неї прев'ю для перегляду).
    // after не рухає: його рухає лише poll() — інакше повідомлення співрозмовника, що прийшло між опитуваннями перед моїм,
    // опинилось би позаду after і не з'явилось би до перезавантаження. Своє повернеться в опитуванні й знайдеться за data-id
    function append(m) {
      dropEmpty();
      var item = list.querySelector('[data-id="' + m.id + '"]');
      var fresh = !item;
      if (fresh) item = build(m);
      daySeparator(m.time.slice(0, 10));
      list.appendChild(item);
      if (fresh) root.dispatchEvent(new CustomEvent('chat:message', { detail: m }));
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

    function pendingItem(text, q) {
      dropEmpty();
      daySeparator(today());
      var item = el('div', 'chat-msg mine is-pending');
      item.dataset.pending = '';
      var bubble = el('div', 'bubble');
      if (q) bubble.appendChild(quote({ id: q.id, author: q.title, text: q.text, preview_num: null, thumb: q.thumb }));
      bubble.appendChild(richText(el('div', 'text'), text));
      bubble.appendChild(el('div', 'meta', '…'));
      item.appendChild(bubble);
      list.appendChild(item);
      toBottom();
      return item;
    }

    // Мережа впала — бульбашка лишається з «Не надіслано · Повторити»
    function failed(item, text, q) {
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
        deliver(item, text, q);
      });
      meta.appendChild(retry);
    }

    function deliver(item, text, q) {
      var data = { text: text, csrf: csrf };
      if (q) data.reply_to = q.id;
      request('POST', data, function (status, json) {
        if (status === 200 && json && json.message) {
          item.remove();
          append(json.message);
          toBottom();
          poll();
          return;
        }
        if (status === 0 || status >= 500) { failed(item, text, q); return; }
        // Відмова сервера (порожнє, задовге, сесія, листування закрите): текст і цитата — назад у поле
        item.remove();
        if (input.value.trim() === '') input.value = text;
        if (q && !replyTo) setReply(q);
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
      var q = replyTo;
      setReply(null);
      deliver(pendingItem(text, q), text, q);
    });

    input.addEventListener('input', autosize);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
      }
      if (e.key === 'Escape' && replyTo) setReply(null);
    });

    // «Коментувати» / «Відповісти» — цитата над полем вводу
    list.addEventListener('click', function (e) {
      var b = e.target.closest('[data-reply]');
      if (!b || !replyOn || !writable) return;
      setReply({ id: +b.dataset.reply, title: b.dataset.quoteTitle, text: b.dataset.quoteText || '', thumb: b.dataset.quoteThumb || '' });
    });
    if (quoteBar) {
      quoteBar.querySelector('[data-chat-quote-close]').addEventListener('click', function () { setReply(null); input.focus(); });
    }

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) poll();
    });

    root._chat = {
      // Своє повідомлення ззовні (прев'ю з workspace.js); опитування одразу добирає те, що прийшло перед ним
      append: function (m) { append(m); pendingToEnd(); toBottom(); poll(); },
      // Перебудувати показане повідомлення (приховане прев'ю)
      replace: function (m) {
        var old = list.querySelector('[data-id="' + m.id + '"]');
        if (old) old.replaceWith(build(m));
        // Цитати прихованого прев'ю — без мініатюри
        if (m.preview && m.preview.hidden) {
          list.querySelectorAll('.quote[data-quote-to="' + m.id + '"] .quote-thumb').forEach(function (i) { i.remove(); });
          if (replyTo && replyTo.id === m.id) setReply(null);
        }
      },
      reply: function (q) { if (replyOn && writable) setReply(q); },
      canReply: function () { return replyOn && writable; },
      poll: poll
    };

    toBottom();
    if (writable) schedule(); else stop();
  }

  document.querySelectorAll('[data-chat]').forEach(init);
  // Для листування, вставленого на сторінку пізніше (бокова панель)
  window.DesignChat = {
    init: init,
    append: function (root, m) { root._chat && root._chat.append(m); },
    replace: function (root, m) { root._chat && root._chat.replace(m); },
    reply: function (root, q) { root._chat && root._chat.reply(q); },
    canReply: function (root) { return !!(root._chat && root._chat.canReply()); },
    poll: function (root) { root._chat && root._chat.poll(); }
  };
})();
