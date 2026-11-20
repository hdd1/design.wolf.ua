// Майстерня дизайну: робочий простір у листуванні обраного дизайнера (views/partials/chat.php з data-order).
// - Перегляд прев'ю (views/partials/preview-viewer.php, макети 6.5 «Перегляд прев'ю»): одна версія з гортанням сторінок і версій,
//   стрічка версій, «Порівняння версій» (дві поруч; на телефоні — одна під одною), «Коментувати vN», у дизайнера «Приховати».
// - Дизайнер: «додати прев'ю» біля поля вводу — файл, підпис, «на основі vK», прогрес; частини — DesignUpload (chunk-upload.js),
//   по одному прев'ю за раз.
// - Панель «Макети для друку» (views/partials/layouts-panel.php, макети 6.5): у дизайнера — перетягування / «Обрати файли»,
//   кілька файлів паралельно (до 3 одночасно, решта чекає), у кожного прогрес і «Скасувати»; підпис олівцем, «Замінити»,
//   «Видалити»; у клієнта — лише список. Посилань на файли макетів немає ніде.
// - Шапка (views/partials/workspace.php): «чий хід», вкладки «Чат / Макети» на телефоні, «Завантажити макети» веде до панелі.
// Дані — data-previews секції (перший рендер), подія 'chat:message' з chat.js (нові прев'ю з опитування чи відправки) і
// 'chat:poll' (workspace: «чий хід»; layouts_rev — змінилась, то панель перечитує GET /orders/{id}/layouts; hidden_previews —
// прев'ю, які співрозмовник приховав: картки стають заглушками без перезавантаження).
// Увесь текст користувачів — лише через textContent.
(function () {
  var MONTHS = ['січня', 'лютого', 'березня', 'квітня', 'травня', 'червня', 'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня'];
  var PREVIEW_EXT = /\.(jpe?g|png|tiff?|pdf)$/i;

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }

  function icon(cls) { return el('i', 'fal ' + cls); }

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  // '2026-11-20 11:48' → «сьогодні 11:48» / «18 листопада, 11:48»
  function when(time) {
    var d = new Date();
    var day = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    var hm = time.slice(11, 16);
    if (time.slice(0, 10) === day) return 'сьогодні ' + hm;
    return +time.slice(8, 10) + ' ' + MONTHS[+time.slice(5, 7) - 1] + ', ' + hm;
  }

  // Як View::bytes: «512 Б», «850 КБ», «2,4 МБ», «1,1 ГБ»
  function bytes(n) {
    if (n < 1024) return Math.max(0, n) + ' Б';
    if (n < 1048576) return Math.max(1, Math.round(n / 1024)) + ' КБ';
    if (n < 1073741824) return (n / 1048576).toFixed(1).replace('.', ',').replace(/,0$/, '') + ' МБ';
    return (n / 1073741824).toFixed(1).replace('.', ',').replace(/,0$/, '') + ' ГБ';
  }

  function iconButton(cls, iconCls, label) {
    var b = el('button', cls);
    b.type = 'button';
    b.setAttribute('aria-label', label);
    b.title = label;
    b.appendChild(icon(iconCls));
    return b;
  }

  // XHR → done(status, json); status 0 — немає звʼязку
  function request(method, url, data, done) {
    var xhr = new XMLHttpRequest();
    xhr.open(method, url);
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

  function failText(status, json, fallback) {
    if (json && json.error) return json.error;
    if (status === 401) return 'Ви вийшли з акаунта wolf.ua — увійдіть знову';
    if (status === 0) return 'Немає звʼязку з сервером — спробуйте ще раз';
    return fallback;
  }

  // Плашка «чий хід» у шапці: t — {mine, text, sub, icon} (Workspace::turn)
  function setTurn(t) {
    var box = document.querySelector('[data-ws-turn]');
    if (!box || !t) return;
    box.classList.toggle('turn', !!t.mine);
    box.classList.toggle('info', !t.mine);
    box.querySelector('[data-ws-turn-icon]').className = 'fal ' + t.icon;
    box.querySelector('[data-ws-turn-text]').textContent = t.text;
    var sub = box.querySelector('[data-ws-turn-sub]');
    sub.textContent = t.sub || '';
    sub.hidden = !t.sub;
  }

  // Вкладки «Чат / Макети» (на телефоні; на десктопі обидві колонки видно завжди)
  function wsTabs() {
    var bar = document.querySelector('[data-ws-tabs]');
    var body = document.querySelector('[data-ws-body]');
    if (!bar || !body) return null;
    function show(tab) {
      body.dataset.tab = tab;
      bar.querySelectorAll('[data-ws-tab]').forEach(function (b) {
        var on = b.dataset.wsTab === tab;
        b.classList.toggle('active', on);
        b.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      // Схована стрічка не мала висоти — після показу знову до останнього повідомлення
      var chatBody = tab === 'chat' && body.querySelector('[data-chat-body]');
      if (chatBody) chatBody.scrollTop = chatBody.scrollHeight;
    }
    bar.addEventListener('click', function (e) {
      var b = e.target.closest('[data-ws-tab]');
      if (b) show(b.dataset.wsTab);
    });
    return { show: show };
  }

  // ---------- Панель «Макети для друку» ----------
  // panel — [data-layouts]; root — листування (DesignChat.poll добирає системне повідомлення про макет). Повертає {refresh, rev, pick}
  function layoutsPanel(panel, root) {
    var orderId = panel.dataset.order;
    var edit = panel.dataset.edit === '1' && !!window.DesignUpload;
    var list = panel.querySelector('[data-lp-list]');
    var errorBox = panel.querySelector('[data-lp-error]');
    var loading = false, again = false, renaming = null;
    // id макетів, заміна яких ще завантажується: у їхніх пунктах «Замінити», «Видалити» й олівець вимкнено
    var replacing = {};

    function error(msg) {
      if (!errorBox) { if (msg) window.alert(msg); return; }
      errorBox.textContent = msg || '';
      errorBox.hidden = !msg;
    }

    function extLabel(ext) { return ext ? ext.slice(0, 4).toUpperCase() : 'ФАЙЛ'; }

    // Пункт списку (як у layouts-panel.php)
    function item(l) {
      var li = el('li', 'lp-item');
      li.dataset.lpId = l.id;
      li.appendChild(el('span', 'lp-ext', extLabel(l.ext)));
      var info = el('div', 'lp-info');
      var label = el('div', 'lp-label');
      var text = el('span', '', l.label);
      text.dataset.lpLabel = '';
      label.appendChild(text);
      if (edit) {
        var pen = iconButton('lp-pencil', 'fa-pen', 'Змінити підпис');
        pen.dataset.lpRename = '';
        label.appendChild(pen);
      }
      info.appendChild(label);
      info.appendChild(el('div', 'lp-meta', l.original_name + ' · ' + l.size_text));
      li.appendChild(info);
      if (edit) {
        var rep = iconButton('icon-btn lp-act', 'fa-sync-alt', 'Замінити');
        rep.dataset.lpReplace = '';
        li.appendChild(rep);
        var del = iconButton('icon-btn del', 'fa-trash-alt', 'Видалити');
        del.dataset.lpDelete = '';
        li.appendChild(del);
        if (replacing[l.id]) lockItem(li, true);
      } else {
        var lock = icon('fa-lock-alt lp-locked');
        lock.setAttribute('aria-hidden', 'true');
        li.appendChild(lock);
      }
      return li;
    }

    // Пункт макета, який зараз замінюється: кнопки вимкнено, щоб друга заміна / видалення не зустрілись із першою
    function lockItem(li, on) {
      li.classList.toggle('is-replacing', on);
      li.querySelectorAll('button').forEach(function (b) { b.disabled = on; });
    }

    function setReplacing(id, on) {
      if (on) replacing[id] = (replacing[id] || 0) + 1;
      else if (replacing[id] > 1) replacing[id]--;
      else delete replacing[id];
      var li = list.querySelector('.lp-item[data-lp-id="' + id + '"]');
      if (li && !li.classList.contains('is-busy')) lockItem(li, !!replacing[id]);
    }

    // data — {layouts, count, rev, turn} (Workspace::layouts + turn)
    function render(data) {
      if (!data || !data.layouts) return;
      list.textContent = '';
      data.layouts.forEach(function (l) { list.appendChild(item(l)); });
      panel.dataset.rev = data.rev;
      document.querySelectorAll('[data-lp-count]').forEach(function (n) { n.textContent = data.count; });
      document.querySelectorAll('[data-lp-count-wrap]').forEach(function (w) { w.hidden = !data.count; });
      var empty = panel.querySelector('[data-lp-empty]');
      if (empty) empty.hidden = data.count > 0;
      if (data.turn) setTurn(data.turn);
    }

    // Перечитати панель; поки підпис редагується — після нього
    function refresh() {
      if (loading || renaming) { again = true; return; }
      loading = true;
      request('GET', '/orders/' + orderId + '/layouts', null, function (status, json) {
        loading = false;
        if (status === 200 && json && json.layouts) render(json);
        if (again) { again = false; refresh(); }
      });
    }

    // Дія з макетом (rename | delete) → панель з відповіді, стрічка добирає системне повідомлення; done(ok)
    function act(li, data, done) {
      li.classList.add('is-busy');
      li.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
      error('');
      data.csrf = panel.dataset.csrf;
      request('POST', '/orders/' + orderId + '/layouts/' + li.dataset.lpId, data, function (status, json) {
        // {done:true} без панелі — дію виконано, панель не зібралась: перечитуємо
        var ok = status === 200 && !!json && !!(json.layouts || json.done);
        if (done) done(ok);
        if (ok) {
          if (json.layouts) render(json); else refresh();
          window.DesignChat.poll(root);
          return;
        }
        li.classList.remove('is-busy');
        lockItem(li, !!replacing[li.dataset.lpId]);
        error(failText(status, json, 'Не вдалося змінити макет — спробуйте ще раз'));
        if (status === 422) refresh();   // макет уже замінено чи видалено в іншій вкладці
      });
    }

    function startRename(li) {
      if (renaming) return;
      var text = li.querySelector('[data-lp-label]');
      var old = text.textContent;
      var input = el('input', 'lp-rename');
      input.type = 'text';
      input.value = old;
      input.maxLength = +panel.dataset.labelMax || 120;
      input.setAttribute('aria-label', 'Підпис макета');
      var label = li.querySelector('.lp-label');
      label.hidden = true;
      label.parentNode.insertBefore(input, label);
      renaming = input;
      input.focus();
      input.select();
      var finished = false;
      function finish(save) {
        if (finished) return;
        finished = true;
        var value = input.value.trim();
        input.remove();
        label.hidden = false;
        if (!save || value === '' || value === old) { renaming = null; if (again) { again = false; refresh(); } return; }
        text.textContent = value;
        act(li, { action: 'rename', label: value }, function (ok) {
          if (!ok) text.textContent = old;
          renaming = null;
          if (again) { again = false; refresh(); }
        });
      }
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); finish(true); }
        if (e.key === 'Escape') { e.preventDefault(); finish(false); }
      });
      input.addEventListener('blur', function () { finish(true); });
    }

    list.addEventListener('click', function (e) {
      var li = e.target.closest('.lp-item');
      if (!li || !edit || replacing[li.dataset.lpId]) return;
      if (e.target.closest('[data-lp-rename]')) startRename(li);
      else if (e.target.closest('[data-lp-replace]')) {
        replaceId = li.dataset.lpId;
        replaceLabel = li.querySelector('[data-lp-label]').textContent;
        replaceInput.click();
      } else if (e.target.closest('[data-lp-delete]')) {
        var name = li.querySelector('[data-lp-label]').textContent;
        if (window.confirm('Видалити макет «' + name + '»? Клієнт побачить у стрічці, що його видалено.')) act(li, { action: 'delete' });
      }
    });

    // ---------- Дизайнер: завантаження ----------
    var PARALLEL = 3;
    var queue = [], running = 0, replaceId = null, replaceLabel = '';
    var uploads = panel.querySelector('[data-lp-uploads]');
    var fileInput = panel.querySelector('[data-lp-file]');
    var replaceInput = panel.querySelector('[data-lp-replace-file]');
    var pickBtn = panel.querySelector('[data-lp-pick]');
    var drop = panel.querySelector('[data-lp-drop]');
    var max = +panel.dataset.max || 0;

    // Рядок завантаження: підпис (ім'я без розширення), «1,2 МБ з 1,9 МБ», смужка, %, «Скасувати»
    function uploadRow(file, fileId, label) {
      var m = /^(.*)\.([a-z0-9]{1,10})$/i.exec(file.name);
      var row = { file: file, fileId: fileId, controller: null, done: false };
      var li = el('li', 'lp-item is-uploading');
      li.appendChild(el('span', 'lp-ext', extLabel(m ? m[2] : '')));
      var info = el('div', 'lp-info');
      info.appendChild(el('div', 'lp-label', fileId ? 'Заміна «' + label + '»' : ((m ? m[1] : file.name) || 'Макет')));
      var meta = el('div', 'lp-meta', file.name + ' · очікує');
      info.appendChild(meta);
      var barRow = el('div', 'lp-bar-row');
      var bar = el('div', 'lp-bar');
      var fill = el('span');
      bar.appendChild(fill);
      barRow.appendChild(bar);
      var pct = el('span', 'lp-pct', '0%');
      barRow.appendChild(pct);
      info.appendChild(barRow);
      li.appendChild(info);
      var cancel = iconButton('icon-btn lp-cancel', 'fa-times', 'Скасувати');
      li.appendChild(cancel);
      uploads.appendChild(li);
      row.li = li;
      row.progress = function (sent, total) {
        var p = total ? Math.round(sent * 100 / total) : 0;
        fill.style.width = p + '%';
        pct.textContent = p + '%';
        meta.textContent = p < 100 ? bytes(sent) + ' з ' + bytes(total) : file.name + ' · зберігаємо…';
      };
      row.fail = function (msg) {
        row.done = true;
        li.classList.remove('is-uploading');
        li.classList.add('is-error');
        barRow.remove();
        meta.textContent = file.name + ' · ' + bytes(file.size);
        info.appendChild(el('div', 'lp-err', msg));
        cancel.setAttribute('aria-label', 'Прибрати');
        cancel.title = 'Прибрати';
      };
      cancel.addEventListener('click', function () {
        if (row.controller) { row.controller.abort(); return; }   // reject AbortError → рядок прибирається
        var i = queue.indexOf(row);
        if (i >= 0) {
          queue.splice(i, 1);
          if (row.fileId) setReplacing(row.fileId, false);
        }
        li.remove();
      });
      return row;
    }

    function add(files, fileId, label) {
      Array.prototype.forEach.call(files, function (f) {
        var row = uploadRow(f, fileId, label);
        if (f.size <= 0) row.fail('Файл порожній');
        else if (max && f.size > max) row.fail('Файл більший за ' + panel.dataset.maxText + ' — це максимум для одного макета');
        else {
          if (fileId) setReplacing(fileId, true);   // до кінця завантаження заміни (успіх, помилка, «Скасувати»)
          queue.push(row);
        }
      });
      pump();
    }

    function pump() {
      while (running < PARALLEL && queue.length) start(queue.shift());
    }

    function start(row) {
      running++;
      row.controller = new AbortController();
      row.progress(0, row.file.size);
      var fields = { purpose: row.fileId ? 'replace' : 'layout' };
      if (row.fileId) fields.file_id = row.fileId;
      window.DesignUpload.send(row.file, {
        url: '/orders/' + orderId + '/upload',
        csrf: panel.dataset.csrf,
        chunk: +panel.dataset.chunk || 0,
        signal: row.controller.signal,
        fields: fields,
        onProgress: row.progress
      }).then(function () {
        row.li.remove();
        refresh();
        window.DesignChat.poll(root);
      }, function (e) {
        if (e.name === 'AbortError') row.li.remove();
        else row.fail(e.message);
      }).then(function () {
        row.controller = null;
        row.done = true;
        running--;
        if (row.fileId) setReplacing(row.fileId, false);
        pump();
      });
    }

    if (edit) {
      pickBtn.addEventListener('click', function () { fileInput.click(); });
      fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files.length) add(fileInput.files, null, '');
        fileInput.value = '';
      });
      replaceInput.addEventListener('change', function () {
        if (replaceInput.files && replaceInput.files[0] && replaceId) add([replaceInput.files[0]], replaceId, replaceLabel);
        replaceInput.value = '';
        replaceId = null;
      });
      ['dragenter', 'dragover'].forEach(function (type) {
        drop.addEventListener(type, function (e) {
          if (!e.dataTransfer || Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') < 0) return;
          e.preventDefault();
          e.dataTransfer.dropEffect = 'copy';
          drop.classList.add('is-over');
        });
      });
      drop.addEventListener('dragleave', function (e) {
        if (!drop.contains(e.relatedTarget)) drop.classList.remove('is-over');
      });
      drop.addEventListener('drop', function (e) {
        e.preventDefault();
        drop.classList.remove('is-over');
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) add(e.dataTransfer.files, null, '');
      });
      // Незавершені завантаження — попередити перед виходом зі сторінки
      window.addEventListener('beforeunload', function (e) {
        if (running || queue.length) { e.preventDefault(); e.returnValue = ''; }
      });
    }

    return {
      refresh: refresh,
      rev: function () { return panel.dataset.rev; },
      // «Завантажити макети» у шапці: панель у поле зору, фокус на «Обрати файли»
      pick: function () {
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        var target = edit ? pickBtn : panel;
        if (target) target.focus({ preventScroll: true });
      }
    };
  }

  function init(root) {
    if (root.dataset.wsReady || !root.dataset.order) return;
    root.dataset.wsReady = '1';
    var orderId = root.dataset.order;
    var csrf = root.dataset.csrf;
    var canHide = root.dataset.canHide === '1';
    var items = [];
    try { items = JSON.parse(root.dataset.previews || '[]'); } catch (e) { items = []; }

    function byPreview(id) {
      for (var i = 0; i < items.length; i++) if (items[i].preview.id === +id) return i;
      return -1;
    }
    function visible() { return items.filter(function (it) { return !it.preview.hidden; }); }
    function card(it) { return (it.preview.urls[0] && it.preview.urls[0].card) || ''; }
    // Повідомлення стрічки з елемента перегляду (для DesignChat.replace)
    function message(it) {
      return { id: it.msg, mine: it.mine, system: false, author: it.author, text: it.preview.caption, time: it.time, preview: it.preview, reply: null };
    }
    function comment(it) {
      if (!it || it.preview.hidden) return;
      close();
      window.DesignChat.reply(root, { id: it.msg, title: 'Коментар до v' + it.preview.num, text: it.preview.caption, thumb: card(it) });
    }

    // Шапка, вкладки й панель «Макети» цього замовлення
    var tabs = wsTabs();
    var lpNode = document.querySelector('[data-layouts][data-order="' + orderId + '"]');
    var layouts = lpNode ? layoutsPanel(lpNode, root) : null;
    var uploadBtn = document.querySelector('[data-ws-upload]');
    if (uploadBtn && layouts) {
      uploadBtn.addEventListener('click', function () {
        if (tabs) tabs.show('layouts');
        layouts.pick();
      });
    }

    // Опитування листування: «чий хід», зміни макетів, прев'ю, які приховав співрозмовник
    root.addEventListener('chat:poll', function (e) {
      var w = e.detail && e.detail.workspace;
      if (!w) return;
      setTurn(w.turn);
      if (layouts && w.layouts_rev && w.layouts_rev !== layouts.rev()) layouts.refresh();
      (w.hidden_previews || []).forEach(function (id) {
        var i = byPreview(id);
        if (i < 0 || items[i].preview.hidden) return;
        var it = items[i];
        it.preview.hidden = true;
        it.preview.urls = [];
        window.DesignChat.replace(root, message(it));
        if (!viewer.hidden) {
          if (compare.hidden) renderSingle(); else renderCompare();
        }
        if (composer) composer.refresh();
      });
    });

    root.addEventListener('chat:message', function (e) {
      var m = e.detail;
      if (!m || !m.preview) return;
      var it = { msg: m.id, author: m.author, time: m.time, mine: m.mine, preview: m.preview };
      var i = byPreview(m.preview.id);
      if (i >= 0) items[i] = it; else items.push(it);
      items.sort(function (a, b) { return a.preview.num - b.preview.num; });
      if (composer) composer.refresh();
    });

    // ---------- Перегляд ----------
    var viewer = root.querySelector('[data-pv-viewer]');
    // Поза листуванням: липка колонка (position: sticky) — окремий контекст накладання, шапка сайту перекривала б перегляд
    document.body.appendChild(viewer);
    var single = viewer.querySelector('[data-pvv-single]');
    var compare = viewer.querySelector('[data-pvv-compare]');
    var stageImg = viewer.querySelector('[data-pvv-img]');
    var gone = viewer.querySelector('[data-pvv-gone]');
    var pageLabel = viewer.querySelector('[data-pvv-page]');
    var strip = viewer.querySelector('[data-pvv-strip]');
    var hideBtn = viewer.querySelector('[data-pv-hide]');
    var toCompare = viewer.querySelector('[data-pvv-to-compare]');
    var cur = 0, page = 0, opener = null;
    var panes = [{ idx: 0, page: 0 }, { idx: 0, page: 0 }];

    function open(previewId, from) {
      var i = byPreview(previewId);
      if (i < 0) return;
      opener = from || null;
      cur = i;
      page = 0;
      viewer.hidden = false;
      document.body.classList.add('pvv-open');
      showSingle();
      viewer.focus();
    }

    function close() {
      if (viewer.hidden) return;
      viewer.hidden = true;
      document.body.classList.remove('pvv-open');
      if (opener && opener.isConnected) opener.focus();
    }

    function showSingle() {
      compare.hidden = true;
      single.hidden = false;
      renderSingle();
    }

    function renderSingle() {
      var it = items[cur];
      if (!it) { close(); return; }
      var p = it.preview;
      viewer.querySelector('[data-pvv-title]').textContent = 'v' + p.num + (p.based_on_num ? ' · на основі v' + p.based_on_num : '');
      var who = (it.mine ? 'Ви' : it.author) + ', ' + when(it.time);
      viewer.querySelector('[data-pvv-sub]').textContent = (p.caption ? p.caption + ' · ' : '') + who;
      viewer.querySelector('[data-pvv-caption]').textContent = p.caption;
      var url = p.hidden ? '' : ((p.urls[page] && p.urls[page].view) || '');
      stageImg.hidden = !url;
      if (url) {
        stageImg.src = url;
        stageImg.alt = 'v' + p.num + (p.pages > 1 ? ', сторінка ' + (page + 1) : '');
      } else {
        stageImg.removeAttribute('src');
      }
      gone.hidden = !p.hidden;
      pageLabel.hidden = p.hidden || p.pages < 2;
      pageLabel.textContent = 'Сторінка ' + (page + 1) + ' з ' + p.pages;
      viewer.querySelector('[data-pvv-prev]').disabled = cur === 0 && page === 0;
      viewer.querySelector('[data-pvv-next]').disabled = cur === items.length - 1 && (p.hidden || page >= p.pages - 1);
      if (hideBtn) hideBtn.hidden = p.hidden;
      viewer.querySelectorAll('[data-pvv-comment]').forEach(function (b) { b.disabled = p.hidden; });
      toCompare.hidden = visible().length < 2;
      renderStrip();
    }

    function renderStrip() {
      strip.textContent = '';
      items.forEach(function (it, i) {
        var p = it.preview;
        var b = el('button', 'pvv-thumb' + (i === cur ? ' active' : '') + (p.hidden ? ' is-hidden' : ''));
        b.type = 'button';
        b.setAttribute('role', 'option');
        b.setAttribute('aria-selected', i === cur ? 'true' : 'false');
        var box = el('span', 'pvv-thumb-img');
        if (p.hidden || !card(it)) {
          box.appendChild(icon(p.hidden ? 'fa-eye-slash' : 'fa-image'));
        } else {
          var img = el('img');
          img.src = card(it);
          img.alt = '';
          img.loading = 'lazy';
          box.appendChild(img);
        }
        b.appendChild(box);
        b.appendChild(el('span', 'pvv-thumb-num', 'v' + p.num + (p.hidden ? ' · прихов.' : '')));
        b.addEventListener('click', function () { cur = i; page = 0; renderSingle(); });
        strip.appendChild(b);
      });
      var active = strip.querySelector('.active');
      if (active && active.scrollIntoView) active.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }

    // Гортання: сторінки поточної версії, далі — сусідня версія
    function step(dir) {
      var p = items[cur].preview;
      if (dir > 0) {
        if (!p.hidden && page < p.pages - 1) page++;
        else if (cur < items.length - 1) { cur++; page = 0; }
      } else if (page > 0) {
        page--;
      } else if (cur > 0) {
        cur--;
        var q = items[cur].preview;
        page = q.hidden ? 0 : q.pages - 1;
      }
      renderSingle();
    }

    function showCompare() {
      var vis = visible();
      if (vis.length < 2) return;
      var right = items[cur].preview.hidden ? items.indexOf(vis[vis.length - 1]) : cur;
      var rp = items[right].preview;
      var left = rp.based_on_num ? items.findIndex(function (it) { return it.preview.num === rp.based_on_num && !it.preview.hidden; }) : -1;
      if (left < 0 || left === right) {
        var pos = vis.indexOf(items[right]);
        left = items.indexOf(vis[pos > 0 ? pos - 1 : 1]);
      }
      panes = [{ idx: left, page: 0 }, { idx: right, page: 0 }];
      single.hidden = true;
      compare.hidden = false;
      renderCompare();
    }

    function renderCompare() {
      var vis = visible();
      compare.querySelectorAll('[data-pvv-pane]').forEach(function (pane, side) {
        var st = panes[side];
        var it = items[st.idx];
        if (!it || it.preview.hidden) { showSingle(); return; }
        var p = it.preview;
        var sel = pane.querySelector('[data-pvv-select]');
        sel.textContent = '';
        vis.forEach(function (v) {
          var o = el('option', '', 'v' + v.preview.num);
          o.value = v.preview.id;
          if (v === it) o.selected = true;
          sel.appendChild(o);
        });
        pane.querySelector('[data-pvv-pane-cap]').textContent = p.caption;
        var img = pane.querySelector('[data-pvv-pane-img]');
        var url = (p.urls[st.page] && p.urls[st.page].view) || '';
        img.hidden = !url;
        if (url) { img.src = url; img.alt = 'v' + p.num; } else img.removeAttribute('src');
        var pl = pane.querySelector('[data-pvv-pane-page]');
        pl.hidden = p.pages < 2;
        pl.textContent = (st.page + 1) + ' / ' + p.pages;
        pane.querySelector('[data-pvv-pane-pages]').hidden = p.pages < 2;
        var t = pane.querySelector('[data-pvv-pane-comment-text]');
        if (t) t.textContent = 'Коментувати v' + p.num;
      });
      var a = items[panes[0].idx], b = items[panes[1].idx];
      if (a && b) compare.querySelector('[data-pvv-pair]').textContent = 'v' + a.preview.num + ' і v' + b.preview.num;
    }

    compare.querySelectorAll('[data-pvv-pane]').forEach(function (pane, side) {
      pane.querySelector('[data-pvv-select]').addEventListener('change', function () {
        panes[side] = { idx: byPreview(this.value), page: 0 };
        renderCompare();
      });
      pane.querySelector('[data-pvv-pane-prev]').addEventListener('click', function () {
        if (panes[side].page > 0) { panes[side].page--; renderCompare(); }
      });
      pane.querySelector('[data-pvv-pane-next]').addEventListener('click', function () {
        if (panes[side].page < items[panes[side].idx].preview.pages - 1) { panes[side].page++; renderCompare(); }
      });
      var c = pane.querySelector('[data-pvv-pane-comment]');
      if (c) c.addEventListener('click', function () { comment(items[panes[side].idx]); });
    });

    viewer.querySelectorAll('[data-pvv-close]').forEach(function (b) { b.addEventListener('click', close); });
    viewer.querySelector('[data-pvv-prev]').addEventListener('click', function () { step(-1); });
    viewer.querySelector('[data-pvv-next]').addEventListener('click', function () { step(1); });
    toCompare.addEventListener('click', showCompare);
    viewer.querySelector('[data-pvv-to-single]').addEventListener('click', function () {
      cur = panes[1].idx;
      page = 0;
      showSingle();
    });
    viewer.querySelectorAll('[data-pvv-comment]').forEach(function (b) {
      b.addEventListener('click', function () { comment(items[cur]); });
    });
    viewer.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.preventDefault(); close(); return; }
      if (!single.hidden && e.target.tagName !== 'SELECT') {
        if (e.key === 'ArrowLeft') step(-1);
        if (e.key === 'ArrowRight') step(1);
      }
    });
    // Свайп по картинці (телефон)
    var touchX = null;
    var stage = viewer.querySelector('[data-pvv-stage]');
    stage.addEventListener('touchstart', function (e) { touchX = e.touches.length === 1 ? e.touches[0].clientX : null; }, { passive: true });
    stage.addEventListener('touchend', function (e) {
      if (touchX === null) return;
      var dx = e.changedTouches[0].clientX - touchX;
      touchX = null;
      if (Math.abs(dx) > 50) step(dx < 0 ? 1 : -1);
    });

    if (hideBtn) {
      hideBtn.addEventListener('click', function () {
        var it = items[cur];
        if (!it || it.preview.hidden) return;
        if (!window.confirm('Приховати v' + it.preview.num + '? Клієнт побачить замість картинки «Прев\'ю приховано дизайнером» з підписом. Повернути не можна.')) return;
        hideBtn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'hide');
        fd.append('csrf', csrf);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/orders/' + orderId + '/previews/' + it.preview.id);
        xhr.onload = function () {
          hideBtn.disabled = false;
          var json = null;
          try { json = JSON.parse(xhr.responseText); } catch (e) { /* не JSON */ }
          if (xhr.status === 200 && json && json.preview) {
            it.preview = json.preview;
            window.DesignChat.replace(root, message(it));
            renderSingle();
            if (composer) composer.refresh();
            return;
          }
          window.alert(json && json.error ? json.error : 'Не вдалося приховати прев\'ю — спробуйте ще раз');
        };
        xhr.onerror = function () {
          hideBtn.disabled = false;
          window.alert('Немає звʼязку з сервером — спробуйте ще раз');
        };
        xhr.send(fd);
      });
    }

    root.addEventListener('click', function (e) {
      var b = e.target.closest('[data-pv-open]');
      if (b && root.contains(b) && !viewer.contains(b)) open(b.dataset.pvOpen, b);
    });

    // ---------- Дизайнер: нове прев'ю ----------
    var composer = null;
    var panel = root.querySelector('[data-pv-compose]');
    if (panel && window.DesignUpload) composer = (function () {
      var addBtn = root.querySelector('[data-pv-add]');
      var fileInput = root.querySelector('[data-pv-file]');
      var caption = panel.querySelector('[data-pv-caption]');
      var chips = panel.querySelector('[data-pv-based]');
      var sendBtn = panel.querySelector('[data-pv-send]');
      var progress = panel.querySelector('[data-pv-progress]');
      var bar = panel.querySelector('[data-pv-bar]');
      var progressText = panel.querySelector('[data-pv-progress-text]');
      var errorBox = panel.querySelector('[data-pv-error]');
      var thumb = panel.querySelector('[data-pv-thumb]');
      var file = null, basedOn = '', busy = null, objectUrl = null;

      function error(msg) {
        errorBox.textContent = msg || '';
        errorBox.hidden = !msg;
      }

      function refresh() {
        var max = items.reduce(function (n, it) { return Math.max(n, it.preview.num); }, 0);
        panel.querySelector('[data-pv-next]').textContent = 'v' + (max + 1);
        var vis = visible();
        if (basedOn && !vis.some(function (it) { return String(it.preview.id) === basedOn; })) basedOn = '';
        chips.textContent = '';
        panel.querySelector('[data-pv-based-wrap]').hidden = vis.length === 0;
        [{ id: '', label: '—' }].concat(vis.map(function (it) { return { id: String(it.preview.id), label: 'v' + it.preview.num }; }))
          .forEach(function (o) {
            var b = el('button', 'pv-chip' + (o.id === basedOn ? ' active' : ''), o.label);
            b.type = 'button';
            b.setAttribute('role', 'radio');
            b.setAttribute('aria-checked', o.id === basedOn ? 'true' : 'false');
            if (o.id === '') b.setAttribute('aria-label', 'Ні на чому');
            b.disabled = !!busy;
            b.addEventListener('click', function () { basedOn = o.id; refresh(); });
            chips.appendChild(b);
          });
      }

      function reset() {
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = null;
        file = null;
        caption.value = '';
        fileInput.value = '';
        error('');
        progress.hidden = true;
        panel.hidden = true;
        addBtn.disabled = false;
      }

      function setBusy(on) {
        caption.disabled = on;
        sendBtn.disabled = on;
        addBtn.disabled = true;
        sendBtn.textContent = on ? 'Надсилаємо…' : 'Надіслати прев\'ю';
        chips.querySelectorAll('button').forEach(function (b) { b.disabled = on; });
      }

      addBtn.addEventListener('click', function () { if (!busy) fileInput.click(); });
      fileInput.addEventListener('change', function () {
        var f = fileInput.files && fileInput.files[0];
        if (!f) return;
        if (!PREVIEW_EXT.test(f.name)) {
          fileInput.value = '';
          window.alert('Прев\'ю — JPG, PNG, TIFF або PDF');
          return;
        }
        file = f;
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = null;
        thumb.textContent = '';
        if (/\.(jpe?g|png)$/i.test(f.name)) {
          objectUrl = URL.createObjectURL(f);
          var img = el('img');
          img.src = objectUrl;
          img.alt = '';
          thumb.appendChild(img);
        } else {
          thumb.appendChild(icon(/\.pdf$/i.test(f.name) ? 'fa-file-pdf' : 'fa-file-image'));
        }
        panel.querySelector('[data-pv-name]').textContent = f.name + ' · ' + bytes(f.size);
        var vis = visible();
        basedOn = vis.length ? String(vis[vis.length - 1].preview.id) : '';
        error('');
        progress.hidden = true;
        panel.hidden = false;
        addBtn.disabled = true;
        refresh();
        caption.focus();
      });

      panel.querySelector('[data-pv-cancel]').addEventListener('click', function () {
        if (busy) busy.abort();
        else reset();
      });

      sendBtn.addEventListener('click', function () {
        if (!file || busy) return;
        error('');
        busy = new AbortController();
        setBusy(true);
        progress.hidden = false;
        bar.style.width = '0%';
        progressText.textContent = 'Завантаження… 0%';
        window.DesignUpload.send(file, {
          url: '/orders/' + orderId + '/upload',
          csrf: csrf,
          chunk: +root.dataset.chunk || 0,
          signal: busy.signal,
          fields: { purpose: 'preview', caption: caption.value.trim(), based_on: basedOn },
          onProgress: function (sent, total) {
            var pct = total ? Math.round(sent * 100 / total) : 0;
            bar.style.width = pct + '%';
            progressText.textContent = pct < 100 ? 'Завантаження… ' + pct + '%' : 'Готуємо прев\'ю з водяним знаком…';
          }
        }).then(function (json) {
          busy = null;
          setBusy(false);
          // Без message (сервер не зібрав його після збереження) — стрічку добере опитування
          if (json.message) window.DesignChat.append(root, json.message);
          else window.DesignChat.poll(root);
          reset();
        }, function (e) {
          busy = null;
          setBusy(false);
          addBtn.disabled = true;
          progress.hidden = true;
          if (e.name === 'AbortError') { reset(); return; }
          error(e.message);
        });
      });

      refresh();
      return { refresh: refresh };
    })();
  }

  document.querySelectorAll('[data-chat][data-order]').forEach(init);
  window.DesignWorkspace = { init: init };
})();
