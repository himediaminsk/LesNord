/**
 * NW Admin — правка страницы на месте.
 * Для обычного посетителя скрипт молча выходит на второй строке
 * и не делает ни одного запроса.
 */
(function () {
  'use strict';
  if (!/(^|;\s*)nw_edit=1/.test(document.cookie)) return;

  var API = 'cms/api.php';
  var csrf = '';
  var editing = false;
  var changed = { text: {}, img: {}, bg: {} };
  var bar, counter, saveBtn, toggleBtn, fileInput, target = null;

  /* ---------------------------------------------------------------- стили */
  var css = document.createElement('link');
  css.rel = 'stylesheet';
  css.href = 'cms/editor.css';
  document.head.appendChild(css);

  /* ------------------------------------------------------------- проверка */
  fetch(API + '?a=state', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) { if (d && d.ok) { csrf = d.csrf; build(); } })
    .catch(function () {});

  /* -------------------------------------------------------------- панель */
  function build() {
    bar = document.createElement('div');
    bar.className = 'nwadm';
    bar.innerHTML =
      '<button class="nwadm__btn nwadm__btn--main" data-act="toggle">Включить правку</button>' +
      '<button class="nwadm__btn" data-act="save" disabled>Сохранить</button>' +
      '<span class="nwadm__count">без изменений</span>' +
      '<a class="nwadm__link" href="cms/">Панель</a>';
    document.body.appendChild(bar);

    toggleBtn = bar.querySelector('[data-act="toggle"]');
    saveBtn = bar.querySelector('[data-act="save"]');
    counter = bar.querySelector('.nwadm__count');

    toggleBtn.addEventListener('click', function () { setEditing(!editing); });
    saveBtn.addEventListener('click', save);

    fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.accept = 'image/*';
    fileInput.style.display = 'none';
    fileInput.addEventListener('change', upload);
    document.body.appendChild(fileInput);

    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(); }
      if (e.key === 'Escape' && editing) setEditing(false);
    });

    window.addEventListener('beforeunload', function (e) {
      if (count()) { e.preventDefault(); e.returnValue = ''; }
    });
  }

  function count() {
    return Object.keys(changed.text).length + Object.keys(changed.img).length + Object.keys(changed.bg).length;
  }

  function refresh() {
    var n = count();
    counter.textContent = n ? 'изменений: ' + n : 'без изменений';
    saveBtn.disabled = !n;
    bar.classList.toggle('is-dirty', !!n);
  }

  /* ------------------------------------------------------- режим правки */
  function setEditing(on) {
    editing = on;
    document.body.classList.toggle('nwadm-on', on);
    toggleBtn.textContent = on ? 'Выключить правку' : 'Включить правку';

    document.querySelectorAll('[data-nw]').forEach(function (el) {
      el.contentEditable = on ? 'true' : 'false';
      if (on) {
        if (!el.dataset.nwOrig) el.dataset.nwOrig = el.innerHTML;
        el.addEventListener('input', onInput);
        el.addEventListener('paste', onPaste);
      } else {
        el.removeEventListener('input', onInput);
        el.removeEventListener('paste', onPaste);
      }
    });

    document.querySelectorAll('[data-nw-img], [data-nw-bg]').forEach(function (el) {
      if (on) el.addEventListener('click', onImgClick);
      else el.removeEventListener('click', onImgClick);
    });

    if (on) document.addEventListener('click', blockLinks, true);
    else document.removeEventListener('click', blockLinks, true);
  }

  function blockLinks(e) {
    var a = e.target.closest('a');
    if (a && !a.closest('.nwadm')) e.preventDefault();
  }

  function onInput(e) {
    var el = e.currentTarget;
    var key = el.getAttribute('data-nw');
    if (el.innerHTML === el.dataset.nwOrig) delete changed.text[key];
    else changed.text[key] = el.innerHTML;
    refresh();
  }

  /* Вставка — только текстом, чтобы не тащить чужое оформление. */
  function onPaste(e) {
    e.preventDefault();
    var text = (e.clipboardData || window.clipboardData).getData('text/plain');
    document.execCommand('insertText', false, text);
  }

  /* ------------------------------------------------------------ картинки */
  function onImgClick(e) {
    e.preventDefault();
    e.stopPropagation();
    target = e.currentTarget;
    fileInput.value = '';
    fileInput.click();
  }

  function upload() {
    if (!fileInput.files.length || !target) return;
    var el = target;
    var fd = new FormData();
    fd.append('a', 'upload');
    fd.append('csrf', csrf);
    fd.append('file', fileInput.files[0]);

    el.classList.add('nwadm-loading');
    fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        el.classList.remove('nwadm-loading');
        if (!d.ok) { alert(d.error || 'Картинка не загрузилась.'); return; }
        if (el.hasAttribute('data-nw-img')) {
          el.src = d.src;
          el.removeAttribute('data-src');
          changed.img[el.getAttribute('data-nw-img')] = d.src;
        } else {
          el.style.setProperty('--hero-photo', "url('" + d.src + "')");
          changed.bg[el.getAttribute('data-nw-bg')] = d.src;
        }
        refresh();
      })
      .catch(function () {
        el.classList.remove('nwadm-loading');
        alert('Сеть не ответила. Проверьте соединение и повторите.');
      });
  }

  /* ------------------------------------------------------------ сохранение */
  function save() {
    if (!count()) return;
    saveBtn.disabled = true;
    saveBtn.textContent = 'Сохраняю…';

    fetch(API + '?a=save', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf: csrf, text: changed.text, img: changed.img, bg: changed.bg })
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        saveBtn.textContent = 'Сохранить';
        if (!d.ok) { alert(d.error || 'Сохранить не получилось.'); saveBtn.disabled = false; return; }
        document.querySelectorAll('[data-nw]').forEach(function (el) { el.dataset.nwOrig = el.innerHTML; });
        changed = { text: {}, img: {}, bg: {} };
        refresh();
        flash('Сохранено');
      })
      .catch(function () {
        saveBtn.textContent = 'Сохранить';
        saveBtn.disabled = false;
        alert('Сеть не ответила. Изменения остались на странице — повторите сохранение.');
      });
  }

  function flash(text) {
    var t = document.createElement('div');
    t.className = 'nwadm__toast';
    t.textContent = text;
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 2200);
  }
})();
