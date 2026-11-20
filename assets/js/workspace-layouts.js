// Майстерня дизайну: панель «Макети для друку» робочого простору і спільні дрібниці для workspace.js / workspace-buy.js.
// - Панель (views/partials/layouts-panel.php, макети 6.5): у дизайнера — перетягування / «Обрати файли», кілька файлів
//   паралельно (до 3 одночасно, решта чекає), у кожного прогрес і «Скасувати»; підпис олівцем, «Замінити», «Видалити» —
//   лише в некуплених, куплений — «Куплено». У клієнта — список; купленому — «Завантажити» (адреса з сервера, лише клієнту)
//   і «Скопіювати в хмару» (data-lp-cloud, стан cloud_status): натиск — POST …/layouts/{id}/cloud (лише черга, копіює крон),
//   далі «Копіюємо…» і опитування GET /orders/{id}/layouts раз на 10 с, поки є макети в черзі → «У хмарі ✓» або
//   «Не вдалося, спробуйте ще» (натиск — повтор). Некупленим посилань немає ніде.
// - Після кожного перемальовування — подія 'ws:layouts' на document (detail — дані панелі): workspace-buy.js вмикає «Купую».
// - window.DesignWs: el, icon, bytes, iconButton, request, failText, setTurn («чий хід» у шапці), layouts(panel, root) —
//   панель {refresh, render, rev, pick} і panel(orderId) — уже створена панель замовлення. Підключається перед workspace.js.
// Увесь текст користувачів — лише через textContent.
(function () {
  var panels = {};

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }

  function icon(cls) { return el('i', 'fal ' + cls); }

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

  // ---------- Панель «Макети для друку» ----------
  // panel — [data-layouts]; root — листування (DesignChat.poll добирає системне повідомлення про макет). Повертає {refresh, rev, pick}
  function layoutsPanel(panel, root) {
    var orderId = panel.dataset.order;
    var edit = panel.dataset.edit === '1' && !!window.DesignUpload;
    var list = panel.querySelector('[data-lp-list]');
    var errorBox = panel.querySelector('[data-lp-error]');
    var loading = false, again = false, renaming = null;
    // Опитування стану копій у хмару: раз на 10 с, поки є макет у черзі (data-cloud-poll — інший інтервал для тестів)
    var cloudTimer = null;
    var CLOUD_POLL = +panel.dataset.cloudPoll || 10000;
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
      var li = el('li', 'lp-item' + (l.purchased ? ' is-bought' : ''));
      li.dataset.lpId = l.id;
      li.appendChild(el('span', 'lp-ext', extLabel(l.ext)));
      var info = el('div', 'lp-info');
      var label = el('div', 'lp-label');
      var text = el('span', '', l.label);
      text.dataset.lpLabel = '';
      label.appendChild(text);
      if (edit && !l.purchased) {
        var pen = iconButton('lp-pencil', 'fa-pen', 'Змінити підпис');
        pen.dataset.lpRename = '';
        label.appendChild(pen);
      }
      info.appendChild(label);
      info.appendChild(el('div', 'lp-meta', l.original_name + ' · ' + l.size_text));
      if (l.download) {
        var acts = el('div', 'lp-acts');
        var a = el('a', 'btn btn-outline-blue btn-sm lp-download');
        a.href = l.download;
        a.appendChild(icon('fa-download'));
        a.appendChild(document.createTextNode(' Завантажити'));
        acts.appendChild(a);
        acts.appendChild(cloudBox(l.cloud_status || 0));
        info.appendChild(acts);
      }
      li.appendChild(info);
      if (l.purchased) {
        if (!l.download) {
          var bought = el('span', 'lp-bought');
          bought.appendChild(icon('fa-check-circle'));
          bought.appendChild(document.createTextNode(' Куплено'));
          li.appendChild(bought);
        }
      } else if (edit) {
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

    // «Скопіювати в хмару» (як у layouts-panel.php): 0 кнопка, 1 «Копіюємо…», 2 «У хмарі ✓», 3 «Не вдалося, спробуйте ще»
    function cloudBox(status) {
      var box = el('span', 'lp-cloud');
      box.dataset.lpCloud = status;
      if (status === 1 || status === 2) {
        var st = el('span', 'lp-cloud-state' + (status === 2 ? ' is-done' : ''));
        st.appendChild(icon(status === 2 ? 'fa-cloud' : 'fa-spinner fa-spin'));
        st.appendChild(document.createTextNode(status === 2 ? ' У хмарі ✓' : ' Копіюємо…'));
        box.appendChild(st);
      } else {
        var b = el('button', status === 3 ? 'btn btn-link btn-sm lp-cloud-go is-error' : 'btn btn-outline-blue btn-sm lp-cloud-go');
        b.type = 'button';
        b.dataset.lpCloudGo = '';
        b.appendChild(icon(status === 3 ? 'fa-redo' : 'fa-cloud-upload'));
        b.appendChild(document.createTextNode(status === 3 ? ' Не вдалося, спробуйте ще' : ' Скопіювати в хмару'));
        box.appendChild(b);
      }
      return box;
    }

    // Поки хоч один макет у черзі хмари — перечитати панель за CLOUD_POLL (один таймер на панель)
    function cloudPoll() {
      clearTimeout(cloudTimer);
      cloudTimer = null;
      if (list.querySelector('[data-lp-cloud="1"]')) cloudTimer = setTimeout(refresh, CLOUD_POLL);
    }

    // Натиск «Скопіювати в хмару» / «Спробуйте ще»: сервер лише ставить у чергу → панель з відповіді, далі опитування
    function cloudRequest(li, button) {
      button.disabled = true;
      error('');
      request('POST', '/orders/' + orderId + '/layouts/' + li.dataset.lpId + '/cloud', { csrf: panel.dataset.csrf }, function (status, json) {
        if (status === 200 && json && json.layouts) { render(json); return; }
        button.disabled = false;
        error(failText(status, json, 'Не вдалося поставити копію в чергу — спробуйте ще раз'));
        if (status === 422) refresh();
      });
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
      // «Відкриються після покупки» — поки є некуплені або ще жодного
      var lock = panel.querySelector('[data-lp-lock]');
      if (lock) lock.hidden = data.count > 0 && data.layouts.every(function (l) { return l.purchased; });
      if (data.turn) setTurn(data.turn);
      cloudPoll();
      document.dispatchEvent(new CustomEvent('ws:layouts', { detail: { order: orderId, layouts: data.layouts } }));
    }

    // Перечитати панель; поки підпис редагується — після нього
    function refresh() {
      if (loading || renaming) { again = true; return; }
      loading = true;
      request('GET', '/orders/' + orderId + '/layouts', null, function (status, json) {
        loading = false;
        if (status === 200 && json && json.layouts) render(json);
        else cloudPoll();   // збій звʼязку — опитування копій у хмару не зупиняється
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
      var go = e.target.closest('[data-lp-cloud-go]');
      if (li && go && !go.disabled) { cloudRequest(li, go); return; }
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

    cloudPoll();   // сторінка відкрилась, коли копія ще в черзі

    return panels[orderId] = {
      refresh: refresh,
      render: render,
      rev: function () { return panel.dataset.rev; },
      // «Завантажити макети» у шапці: панель у поле зору, фокус на «Обрати файли»
      pick: function () {
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        var target = edit ? pickBtn : panel;
        if (target) target.focus({ preventScroll: true });
      }
    };
  }

  window.DesignWs = { el: el, icon: icon, bytes: bytes, iconButton: iconButton, request: request, failText: failText,
    setTurn: setTurn, layouts: layoutsPanel, panel: function (orderId) { return panels[orderId] || null; } };
})();
