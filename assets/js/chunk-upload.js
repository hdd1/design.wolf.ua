// Майстерня дизайну: завантаження файлу частинами по 5 МБ (сервер — Design\ChunkUpload::receive через \UppyChunkGlobal)
//
// DesignUpload.send(file, {url, csrf, fields, chunk, onProgress, signal}) → Promise<JSON відповіді на останню частину>
//   chunk      — розмір частини, байти: data-chunk сторінки (ChunkUpload::partSize — ліміти PHP сервера); за замовчуванням 5 МБ
//   fields     — додаткові поля форми (purpose, caption…), ідуть з кожною частиною
//   onProgress — function(sentBytes, totalBytes)
//   signal     — AbortSignal (AbortController) для скасування
// Частини — послідовно; частина повторюється до 3 разів при збої мережі, тайм-ауті або 5xx (пауза 1, 2, 4 с); на 4xx — зупинка.
// Відповідь з final: true (помилка обробки вже склеєного файлу; повтор отримав би ту саму запамʼятовану) не повторюється.
// Тайм-аут частини — DesignUpload.timeouts: 120 с, останньої — 300 с (на ній сервер склеює й обробляє файл).
// Повтор останньої частини безпечний: сервер повертає запамʼятовану відповідь (ChunkUpload::recall), а не обробляє вдруге.
// Помилка → reject(Error): message — текст для людини (error із JSON сервера або загальний), .status, .json;
// скасування — Error з name 'AbortError'.
(function () {
  var CHUNK = 5 * 1024 * 1024;
  var RETRIES = 3;
  var BACKOFF_MS = 1000;
  var timeouts = { part: 120000, last: 300000 };

  function randomId() {
    var bytes = new Uint8Array(16);
    if (window.crypto && window.crypto.getRandomValues) window.crypto.getRandomValues(bytes);
    else for (var i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
    return Array.prototype.map.call(bytes, function (b) { return (b < 16 ? '0' : '') + b.toString(16); }).join('');
  }

  function httpError(status) {
    if (status === 401) return 'Ви вийшли з акаунта wolf.ua — увійдіть знову';
    if (status === 413) return 'Файл завеликий';
    if (status === 0) return 'Немає звʼязку з сервером — спробуйте ще раз';
    return 'Не вдалося завантажити файл — спробуйте ще раз';
  }

  function failure(message, status, json) {
    var e = new Error(message);
    e.status = status;
    e.json = json || null;
    return e;
  }

  function aborted() {
    var e = new Error('Завантаження скасовано');
    e.name = 'AbortError';
    return e;
  }

  // Одна спроба: resolve({status, json}) — status 0 при збої мережі; reject лише при скасуванні
  function postOnce(url, fd, timeout, signal, onPart) {
    return new Promise(function (resolve, reject) {
      if (signal && signal.aborted) return reject(aborted());
      var xhr = new XMLHttpRequest();
      function onAbort() { xhr.abort(); }
      function finish() { if (signal) signal.removeEventListener('abort', onAbort); }
      xhr.open('POST', url);
      xhr.timeout = timeout;
      xhr.upload.onprogress = function (e) { if (e.lengthComputable) onPart(e.loaded / e.total); };
      xhr.onload = function () {
        finish();
        var json = null;
        try { json = JSON.parse(xhr.responseText); } catch (e) { /* не JSON: 413 від nginx, 502 тощо */ }
        resolve({ status: xhr.status, json: json });
      };
      xhr.onerror = xhr.ontimeout = function () { finish(); resolve({ status: 0, json: null }); };
      xhr.onabort = function () { finish(); reject(aborted()); };
      if (signal) signal.addEventListener('abort', onAbort);
      xhr.send(fd);
    });
  }

  function pause(ms, signal) {
    return new Promise(function (resolve, reject) {
      if (signal && signal.aborted) return reject(aborted());
      var timer = setTimeout(function () {
        if (signal) signal.removeEventListener('abort', stop);
        resolve();
      }, ms);
      function stop() { clearTimeout(timer); reject(aborted()); }
      if (signal) signal.addEventListener('abort', stop);
    });
  }

  function send(file, opts) {
    opts = opts || {};
    var signal = opts.signal;
    var fields = opts.fields || {};
    var size = opts.chunk > 0 ? Math.min(CHUNK, Math.floor(opts.chunk)) : CHUNK;
    var total = Math.max(1, Math.ceil(file.size / size));
    var fileId = randomId();
    var index = 0;

    function progress(sentInPart) {
      if (opts.onProgress) opts.onProgress(Math.min(file.size, index * size + sentInPart), file.size);
    }

    function part(attempt) {
      var blob = file.slice(index * size, (index + 1) * size);
      var fd = new FormData();
      Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
      fd.append('csrf', opts.csrf);
      fd.append('meta[fileId]', fileId);
      fd.append('meta[chunkIndex]', String(index));
      fd.append('meta[totalChunks]', String(total));
      fd.append('meta[totalSize]', String(file.size));
      fd.append('meta[originalName]', file.name);
      fd.append('file', blob, 'blob');
      var timeout = index === total - 1 ? timeouts.last : timeouts.part;
      return postOnce(opts.url, fd, timeout, signal, function (share) { progress(share * blob.size); }).then(function (r) {
        if ((r.status === 0 || r.status >= 500) && attempt < RETRIES && !(r.json && r.json.final)) {
          progress(0);
          return pause(BACKOFF_MS * Math.pow(2, attempt), signal).then(function () { return part(attempt + 1); });
        }
        if (r.status !== 200 || !r.json || r.json.error) {
          throw failure(r.json && r.json.error ? r.json.error : httpError(r.status), r.status, r.json);
        }
        return r.json;
      });
    }

    function next() {
      return part(0).then(function (json) {
        index++;
        progress(0);
        if (index < total) return next();
        if (!json.done) throw failure('Сервер не зібрав файл — спробуйте ще раз', 200, json);
        return json;
      });
    }

    return next();
  }

  window.DesignUpload = { send: send, CHUNK: CHUNK, timeouts: timeouts };
})();
