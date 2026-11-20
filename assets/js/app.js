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

// Повторне надсилання відсікаємо прапорцем, а кнопки не вимикаємо: вимкнена кнопка не передає свій name/value,
// а без SubmitEvent.submitter (Safari до 15.4) його нема чим відновити
document.querySelectorAll('form[data-lock]').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    if (form.dataset.sending) { e.preventDefault(); return; }
    form.dataset.sending = '1';
    form.querySelectorAll('button').forEach(function (b) { b.classList.add('is-sending'); b.setAttribute('aria-disabled', 'true'); });
  });
});
// Повернення «Назад» зі збереженої сторінки (bfcache): форма знову доступна
window.addEventListener('pageshow', function () {
  document.querySelectorAll('form[data-lock]').forEach(function (form) {
    delete form.dataset.sending;
    form.querySelectorAll('button').forEach(function (b) { b.classList.remove('is-sending'); b.removeAttribute('aria-disabled'); });
  });
});

// Мобільне меню прокручується вбік: активний пункт (наприклад «Заробіток») не має ховатися за краєм
document.querySelectorAll('.mobile-nav').forEach(function (nav) {
  var a = nav.querySelector('a.active');
  if (!a || nav.scrollWidth <= nav.clientWidth) return;
  var n = nav.getBoundingClientRect(), r = a.getBoundingClientRect();
  nav.scrollLeft += r.left - n.left - (n.width - r.width) / 2;
});
