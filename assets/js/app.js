// Майстерня дизайну: тост, вибір продукту, блокування кнопок під час відправки
document.addEventListener('click', function (e) {
  var close = e.target.closest('[data-toast-close]');
  if (close) close.closest('[data-toast]').remove();
});
setTimeout(function () { var t = document.querySelector('[data-toast]'); if (t) t.remove(); }, 6000);

document.querySelectorAll('[data-products]').forEach(function (form) {
  var next = form.querySelector('[data-next]');
  var chosen = form.querySelector('[data-chosen]');
  form.addEventListener('change', function (e) {
    if (!e.target.matches('input[name="product"]')) return;
    form.querySelectorAll('.product-card').forEach(function (c) { c.classList.toggle('selected', c.contains(e.target)); });
    next.disabled = false;
    chosen.textContent = 'Обрано: ' + e.target.dataset.name;
  });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var p = form.querySelector('input[name="product"]:checked');
    if (p) location.href = '/brief/' + p.value + '/';
  });
});

document.querySelectorAll('form[data-lock]').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    var by = e.submitter;
    if (by && by.name) { var h = document.createElement('input'); h.type = 'hidden'; h.name = by.name; h.value = by.value; form.appendChild(h); }
    form.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
  });
});
