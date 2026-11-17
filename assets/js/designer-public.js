// Профіль дизайнера для клієнта: збільшення роботи (Bootstrap modal), стрілки й клавіші ←/→ між роботами
(function () {
  var root = document.querySelector('[data-gallery]');
  var modalEl = document.getElementById('work-modal');
  if (!root || !modalEl) return;

  var tiles = Array.prototype.slice.call(root.querySelectorAll('.pub-tile'));
  var img = modalEl.querySelector('[data-pm-img]');
  var title = modalEl.querySelector('[data-pm-title]');
  var sub = modalEl.querySelector('[data-pm-sub]');
  var owner = root.dataset.owner || '';
  var cur = 0;

  function show(i) {
    cur = (i + tiles.length) % tiles.length;
    var t = tiles[cur];
    img.src = t.dataset.view;
    img.alt = t.dataset.title || owner;
    title.textContent = t.dataset.title || '';
    sub.textContent = (cur + 1) + ' з ' + tiles.length + ' · ' + owner;
    // Наступну картинку підвантажуємо наперед
    if (tiles.length > 1) new Image().src = tiles[(cur + 1) % tiles.length].dataset.view;
  }

  modalEl.addEventListener('show.bs.modal', function (e) {
    var t = e.relatedTarget && e.relatedTarget.closest('.pub-tile');
    show(t ? tiles.indexOf(t) : 0);
  });
  modalEl.addEventListener('hidden.bs.modal', function () { img.removeAttribute('src'); });
  modalEl.querySelector('[data-pm-prev]').addEventListener('click', function () { show(cur - 1); });
  modalEl.querySelector('[data-pm-next]').addEventListener('click', function () { show(cur + 1); });
  // Клік поруч із картинкою закриває вікно
  modalEl.querySelector('[data-pm-stage]').addEventListener('click', function (e) {
    if (e.target === this) bootstrap.Modal.getInstance(modalEl).hide();
  });
  modalEl.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowLeft') show(cur - 1);
    else if (e.key === 'ArrowRight') show(cur + 1);
  });
})();
