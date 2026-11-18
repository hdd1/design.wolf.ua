// Майстерня дизайну: форма пропозиції дизайнера (views/partials/offer-form.php) — живе «Клієнт побачить N грн»;
// підтвердження для форм з data-confirm («Відкликати»)
(function () {
  // 1020 → «1 020 грн» (нерозривні пробіли, як View::price)
  function money(n) {
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' грн';
  }

  // Як Price::client: P = D·(1 + k/100) вгору до кроку; цілі числа (соті частки відсотка) — без похибки float
  function clientPrice(d, k, round) {
    round = Math.max(1, round);
    var num = d * (10000 + Math.round(k * 100));
    var den = 10000 * round;
    return Math.floor((num + den - 1) / den) * round;
  }

  document.querySelectorAll('[data-offer-form]').forEach(function (form) {
    var input = form.querySelector('[data-price]');
    var out = form.querySelector('[data-client-text]');
    var k = parseFloat(form.dataset.k) || 0;
    var round = parseInt(form.dataset.round, 10) || 1;
    var wasD = form.dataset.wasDesigner ? +form.dataset.wasDesigner : null;
    var wasP = form.dataset.wasClient ? +form.dataset.wasClient : null;

    function update() {
      var digits = input.value.replace(/[\s  ]/g, '');
      if (!/^\d{1,7}$/.test(digits) || +digits < 1) {
        out.textContent = 'Вкажіть суму — покажемо, скільки побачить клієнт';
        return;
      }
      // Сума не змінилася — у клієнта лишається та сама ціна (знімок націнки не перераховується)
      var p = wasD !== null && +digits === wasD ? wasP : clientPrice(+digits, k, round);
      out.textContent = 'Клієнт побачить ' + money(p) + (wasP !== null && p !== wasP ? ' (було ' + money(wasP) + ')' : '');
    }

    input.addEventListener('input', update);
    update();
  });

  // Раніше за data-lock з app.js: скасоване підтвердження не має блокувати форму
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.dataset && form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
      e.preventDefault();
      e.stopPropagation();
    }
  }, true);
})();
