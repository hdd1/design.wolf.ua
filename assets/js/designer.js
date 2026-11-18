// Майстерня дизайну: заявка і «Мій профіль» дизайнера — завантаження робіт, видалення, порядок, назви, приховання, фото
(function () {
  var root = document.querySelector('[data-designer]');
  if (!root) return;
  var csrf = root.dataset.csrf;
  var PARALLEL = 3;

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }

  function icon(cls) { return el('i', 'fal ' + cls); }

  function httpError(status) {
    if (status === 401) return 'Ви вийшли з акаунта wolf.ua — увійдіть знову';
    if (status === 413) return 'Файл завеликий';
    if (status === 0) return 'Немає звʼязку з сервером — спробуйте ще раз';
    return 'Не вдалося зберегти — спробуйте ще раз';
  }

  // POST з csrf; done(помилка або null, json). progress(0..1) — лише для файлів
  function post(url, data, done, progress) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) {
      if (Array.isArray(data[k])) data[k].forEach(function (v) { fd.append(k + '[]', v); });
      else fd.append(k, data[k]);
    });
    fd.append('csrf', csrf);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', url);
    if (progress) xhr.upload.onprogress = function (e) { if (e.lengthComputable) progress(e.loaded / e.total); };
    xhr.onload = function () {
      var json = null;
      try { json = JSON.parse(xhr.responseText); } catch (e) { /* не JSON: 413 від nginx, 502 тощо */ }
      if (xhr.status === 200 && json && !json.error) done(null, json);
      else done(json && json.error ? json.error : httpError(xhr.status), json);
    };
    xhr.onerror = function () { done(httpError(0)); };
    xhr.send(fd);
  }

  function tooBig(size, max) {
    return 'Файл завеликий: ' + Math.ceil(size / 1048576) + ' МБ. Максимум — ' + max / 1048576 + ' МБ';
  }

  function plural(n, one, few, many) {
    var d = n % 10, h = n % 100;
    return n + ' ' + (d === 1 && h !== 11 ? one : (d >= 2 && d <= 4 && (h < 12 || h > 14) ? few : many));
  }

  var box = root.querySelector('[data-works]');
  var maxBytes = box ? +box.dataset.maxBytes : 20 * 1048576;
  if (box) works(box);
  photo();

  function works(box) {
    var profile = box.dataset.mode === 'profile';
    var max = +box.dataset.max, min = +box.dataset.min;
    var list = box.querySelector('[data-list]');
    var errors = box.querySelector('[data-errors]');
    var input = box.querySelector('[data-file]');
    var alertBox = box.querySelector('[data-works-error]');
    var addTile = list.querySelector('[data-pick]');
    var about = root.querySelector('[data-about]');
    var submit = root.querySelector('[data-submit]');
    var hint = root.querySelector('[data-submit-hint]');
    var queue = [], active = 0;

    function ready() { return list.querySelectorAll('[data-work]').length; }
    function pending() { return list.querySelectorAll('[data-pending]').length; }

    function showError(msg) {
      if (!alertBox) return;
      alertBox.textContent = msg;
      alertBox.hidden = !msg;
    }

    function update() {
      var n = ready();
      if (profile) {
        list.querySelectorAll('[data-work]').forEach(function (row, i) { row.querySelector('[data-n]').textContent = i + 1; });
        var hidden = list.querySelectorAll('[data-work].is-hidden').length;
        box.querySelector('[data-summary]').textContent = plural(n, 'робота', 'роботи', 'робіт')
          + (hidden ? ', ' + plural(hidden, 'прихована', 'приховані', 'прихованих') : '');
        box.querySelector('[data-empty]').hidden = n + pending() > 0;
      } else {
        box.querySelector('[data-count]').textContent = n;
        addTile.hidden = n + pending() >= max;
        var ok = n >= min && about && about.value.trim() !== '';
        if (submit) submit.disabled = !ok;
        if (hint) hint.hidden = ok;
      }
    }

    function errorRow(name, msg) {
      var row = el('div', 'err-row');
      row.setAttribute('role', 'alert');
      var ext = (name.match(/\.([a-z0-9]{1,4})$/i) || [])[1];
      row.appendChild(el('span', 'ext', ext ? ext.toUpperCase() : 'ФАЙЛ'));
      var info = el('div', 'info');
      info.appendChild(el('div', 't', 'Не завантажено'));
      info.appendChild(el('div', 'fname', name));
      info.appendChild(el('div', 'msg', msg));
      row.appendChild(info);
      var del = el('button', 'icon-btn del');
      del.type = 'button';
      del.setAttribute('data-dismiss', '');
      del.setAttribute('aria-label', 'Прибрати');
      del.appendChild(icon('fa-trash-alt'));
      row.appendChild(del);
      errors.appendChild(row);
    }

    function pendingItem(name) {
      var item = el('div', profile ? 'work-row is-loading' : 'work-tile is-loading');
      item.setAttribute('data-pending', '');
      var pct = el('div', 'pct', '0%');
      var bar = el('div', 'upload-bar');
      bar.appendChild(el('span'));
      if (profile) {
        item.appendChild(el('span', 'drag'));
        item.appendChild(el('span', 'n'));
        item.appendChild(el('span', 'ph'));
        var info = el('div', 'info');
        info.appendChild(el('div', 'fname', name));
        info.appendChild(bar);
        item.appendChild(info);
        item.appendChild(pct);
        list.appendChild(item);
      } else {
        item.appendChild(pct);
        item.appendChild(bar);
        list.insertBefore(item, addTile);
      }
      return item;
    }

    function setProgress(item, p) {
      item.querySelector('.pct').textContent = p >= 1 ? 'Обробка…' : Math.round(p * 100) + '%';
      item.querySelector('.upload-bar span').style.width = Math.round(p * 100) + '%';
    }

    function button(cls, iconCls, title, attr) {
      var b = el('button', cls);
      b.type = 'button';
      b.title = title;
      b.setAttribute('aria-label', title);
      b.setAttribute(attr, '');
      b.appendChild(icon(iconCls));
      return b;
    }

    function workItem(j) {
      var img = el('img');
      img.src = j.card;
      img.alt = '';
      var item;
      if (profile) {
        item = el('div', 'work-row');
        var drag = el('span', 'drag');
        drag.setAttribute('data-handle', '');
        drag.title = 'Перетягніть, щоб змінити порядок';
        drag.appendChild(el('i', 'fas fa-grip-vertical'));
        item.appendChild(drag);
        var n = el('span', 'n');
        n.setAttribute('data-n', '');
        item.appendChild(n);
        var a = el('a', 'ph');
        a.href = j.view;
        a.target = '_blank';
        a.rel = 'noopener';
        a.appendChild(img);
        item.appendChild(a);
        var info = el('div', 'info');
        var title = el('textarea', 'title-input');
        title.setAttribute('data-title', '');
        title.rows = 1;
        title.maxLength = +box.dataset.titleMax;
        title.placeholder = 'Додайте назву роботи';
        title.setAttribute('aria-label', 'Назва роботи');
        info.appendChild(title);
        var sub = el('div', 'sub');
        sub.setAttribute('data-sub', '');
        info.appendChild(sub);
        item.appendChild(info);
        var shw = button('icon-btn', 'fa-eye', 'Приховати від клієнтів', 'data-shw');
        shw.setAttribute('aria-pressed', 'false');
        item.appendChild(shw);
        item.appendChild(button('icon-btn del', 'fa-trash-alt', 'Видалити роботу', 'data-delete'));
      } else {
        item = el('div', 'work-tile');
        item.appendChild(img);
        item.appendChild(button('tile-x', 'fa-times', 'Видалити роботу', 'data-delete'));
      }
      item.setAttribute('data-work', '');
      item.dataset.id = j.id;
      return item;
    }

    function addFiles(files) {
      Array.prototype.forEach.call(files, function (file) {
        if (ready() + pending() >= max) { errorRow(file.name, 'Уже ' + max + ' робіт — це максимум'); return; }
        if (file.size > maxBytes) { errorRow(file.name, tooBig(file.size, maxBytes)); return; }
        queue.push({ file: file, item: pendingItem(file.name) });
      });
      update();
      pump();
    }

    function pump() {
      while (active < PARALLEL && queue.length) start(queue.shift());
    }

    function start(job) {
      active++;
      post('/designer/works', { file: job.file }, function (err, j) {
        active--;
        if (err) {
          job.item.remove();
          errorRow(job.file.name, err);
        } else {
          list.replaceChild(workItem(j), job.item);
        }
        update();
        pump();
      }, function (p) { setProgress(job.item, p); });
    }

    function remove(item) {
      if (!confirm('Видалити роботу? Файл буде видалено назавжди')) return;
      item.classList.add('is-busy');
      post('/designer/works/' + item.dataset.id, { action: 'delete' }, function (err) {
        item.classList.remove('is-busy');
        if (err) { showError(err); return; }
        showError('');
        item.remove();
        update();
      });
    }

    function toggle(item, btn) {
      var hide = !item.classList.contains('is-hidden');
      btn.disabled = true;
      post('/designer/works/' + item.dataset.id, { action: 'shw', on: hide ? '0' : '1' }, function (err) {
        btn.disabled = false;
        if (err) { showError(err); return; }
        showError('');
        item.classList.toggle('is-hidden', hide);
        btn.setAttribute('aria-pressed', hide ? 'true' : 'false');
        btn.title = hide ? 'Показати клієнтам' : 'Приховати від клієнтів';
        btn.setAttribute('aria-label', btn.title);
        btn.querySelector('i').className = 'fal ' + (hide ? 'fa-eye-slash' : 'fa-eye');
        item.querySelector('[data-sub]').textContent = hide ? 'Приховано — клієнти не бачать' : '';
        update();
      });
    }

    // Назва — textarea в один рядок, що росте під текст: довгі назви переносяться, а не обрізаються
    function fit(t) {
      t.style.height = 'auto';
      t.style.height = t.scrollHeight + t.offsetHeight - t.clientHeight + 'px';   // + рамка (box-sizing: border-box)
    }

    function fitAll() { box.querySelectorAll('[data-title]').forEach(fit); }

    function saveTitle(input) {
      var item = input.closest('[data-work]');
      var was = input.dataset.saved !== undefined ? input.dataset.saved : input.defaultValue;
      var now = input.value.replace(/\s+/g, ' ').trim();   // вставлений текст із переносами — в один рядок
      if (now === was) { input.value = was; fit(input); return; }
      post('/designer/works/' + item.dataset.id, { action: 'title', title: now }, function (err, j) {
        if (err) { showError(err); input.value = was; fit(input); return; }
        showError('');
        input.value = j.title;
        fit(input);
        input.dataset.saved = j.title;
      });
    }

    box.addEventListener('click', function (e) {
      var t = e.target;
      if (t.closest('[data-pick]')) { input.click(); return; }
      var dismiss = t.closest('[data-dismiss]');
      if (dismiss) { dismiss.closest('.err-row').remove(); return; }
      var item = t.closest('[data-work]');
      if (!item) return;
      if (t.closest('[data-delete]')) remove(item);
      else if (t.closest('[data-shw]')) toggle(item, t.closest('[data-shw]'));
    });
    input.addEventListener('change', function () {
      addFiles(input.files);
      input.value = '';
    });

    if (profile) {
      box.addEventListener('focusout', function (e) { if (e.target.matches('[data-title]')) saveTitle(e.target); });
      box.addEventListener('input', function (e) { if (e.target.matches('[data-title]')) fit(e.target); });
      window.addEventListener('resize', fitAll);
      fitAll();
      box.addEventListener('keydown', function (e) {
        if (!e.target.matches('[data-title]')) return;
        if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
        if (e.key === 'Escape') {
          var t = e.target;
          t.value = t.dataset.saved !== undefined ? t.dataset.saved : t.defaultValue;
          fit(t);
          t.blur();
        }
      });
      if (window.Sortable) {
        Sortable.create(list, {
          handle: '[data-handle]',
          draggable: '[data-work]',
          animation: 150,
          ghostClass: 'is-ghost',
          chosenClass: 'is-moving',
          onEnd: function (e) {
            if (e.oldIndex === e.newIndex) return;
            update();
            var ids = Array.prototype.map.call(list.querySelectorAll('[data-work]'), function (r) { return r.dataset.id; });
            post('/designer/works/order', { ids: ids }, function (err) { showError(err || ''); });
          }
        });
      }
    } else if (about) {
      about.addEventListener('input', update);
    }

    // Форма заявки: не надсилати й не йти зі сторінки, поки файли ще вантажаться.
    // Захоплення на document — раніше за блокування кнопок у app.js (data-lock)
    document.addEventListener('submit', function (e) {
      if (!box.contains(e.target) && !e.target.contains(box)) return;
      if (!active && !queue.length) return;
      e.preventDefault();
      e.stopPropagation();
      showError('Зачекайте, поки завантажаться всі роботи');
    }, true);
    window.addEventListener('beforeunload', function (e) {
      if (!active && !queue.length) return;
      e.preventDefault();
      e.returnValue = '';
    });
    update();
  }

  function photo() {
    var input = root.querySelector('[data-photo]');
    if (!input) return;
    var avatar = root.querySelector('[data-avatar]');
    var error = root.querySelector('[data-photo-error]');
    function fail(msg) { error.textContent = msg; error.hidden = !msg; }
    root.querySelector('[data-photo-pick]').addEventListener('click', function () { input.click(); });
    input.addEventListener('change', function () {
      var file = input.files[0];
      input.value = '';
      if (!file) return;
      if (file.size > maxBytes) { fail(tooBig(file.size, maxBytes)); return; }
      fail('');
      avatar.classList.add('is-loading');
      post('/designer/photo', { file: file }, function (err, j) {
        avatar.classList.remove('is-loading');
        if (err) { fail(err); return; }
        var img = el('img');
        img.src = j.photo;
        img.alt = '';
        avatar.textContent = '';
        avatar.appendChild(img);
      });
    });
  }
})();
