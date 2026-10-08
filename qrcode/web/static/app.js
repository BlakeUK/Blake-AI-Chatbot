// Two small conveniences; the app works without them.
document.addEventListener('submit', function (e) {
  var msg = e.target && e.target.dataset && e.target.dataset.confirm;
  if (msg && !window.confirm(msg)) { e.preventDefault(); }
});
document.addEventListener('click', function (e) {
  var b = e.target.closest ? e.target.closest('[data-copy]') : null;
  if (!b || !navigator.clipboard) { return; }
  navigator.clipboard.writeText(b.dataset.copy).then(function () {
    var old = b.textContent; b.textContent = 'Copied';
    setTimeout(function () { b.textContent = old; }, 1500);
  });
});
document.addEventListener('change', function (e) {
  if (!e.target || !e.target.hasAttribute || !e.target.hasAttribute('data-show-password')) { return; }
  var form = e.target.form;
  if (!form) { return; }
  var kind = e.target.checked ? 'text' : 'password';
  form.querySelectorAll('input[autocomplete$="password"]').forEach(function (i) { i.type = kind; });
});

// Live preview of the QR design on the create/edit form. The server draws the
// preview and refuses designs that would not scan; we show its reason.
(function () {
  var form = document.querySelector('form[data-preview]');
  if (!form) { return; }
  var img = document.getElementById('qr-preview');
  var err = document.getElementById('qr-preview-error');
  var timer;
  function query() {
    var p = new URLSearchParams();
    ['fg', 'bg', 'pattern', 'eye', 'frame', 'cta', 'qr_ecc'].forEach(function (n) {
      var e = form.elements[n];
      if (e) { p.set(n, e.value); }
    });
    var same = form.elements['eye_same'];
    if (same && same.checked) { p.set('eye_same', '1'); }
    else if (form.elements['eye_color']) { p.set('eye_color', form.elements['eye_color'].value); }
    if (form.dataset.link) { p.set('link', form.dataset.link); }
    else if (form.dataset.template) { p.set('template', form.dataset.template); }
    return p.toString();
  }
  function refresh() {
    var url = '/admin/preview.svg?' + query();
    fetch(url, { credentials: 'same-origin' }).then(function (r) {
      if (r.ok) { err.textContent = ''; img.src = url; return; }
      return r.text().then(function (m) { err.textContent = m; });
    }).catch(function () {});
  }
  function later() { clearTimeout(timer); timer = setTimeout(refresh, 250); }
  form.addEventListener('input', later);
  form.addEventListener('change', later);
})();

// "Save design only" needs a name: ask for it in place rather than losing the page.
document.addEventListener('click', function (e) {
  var b = e.target.closest ? e.target.closest('[data-save-design]') : null;
  if (!b) { return; }
  var name = b.form && b.form.querySelector('[data-design-name]');
  if (name && !name.value.trim()) {
    e.preventDefault();
    name.focus();
    name.setCustomValidity('Give the design a name so you can find it later.');
    name.reportValidity();
    name.addEventListener('input', function () { name.setCustomValidity(''); }, { once: true });
  }
});
