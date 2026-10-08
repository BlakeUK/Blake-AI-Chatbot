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

// Link page editor: add / move / remove button rows, and keep the preview frame in step with the form.
(function () {
  var form = document.querySelector('form[data-pageform]');
  if (!form) { return; }
  document.documentElement.classList.add('js');
  var holder = form.querySelector('[data-rows]');
  var frame = document.getElementById('page-preview');
  var timer;
  function rows() { return Array.prototype.slice.call(holder.querySelectorAll('[data-row]')).filter(function (r) { return !r.hidden; }); }
  function preview() {
    var p = new URLSearchParams();
    var v = function (n) { var e = form.elements[n]; return e ? e.value : ''; };
    var checked = function (n) { var e = form.elements[n]; return e && e.checked; };
    var brand = form.querySelector('input[name=brand]:checked'), theme = form.querySelector('input[name=theme]:checked');
    p.set('brand', brand ? brand.value : ''); p.set('theme', theme ? theme.value : '');
    p.set('title', v('title')); p.set('subtitle', v('subtitle'));
    if (!checked('accent_same')) { p.set('accent', v('accent')); }
    if (checked('show_urls')) { p.set('show_urls', '1'); }
    if (checked('show_socials')) { p.set('show_socials', '1'); }
    rows().forEach(function (r) {
      var t = r.querySelector('[name=item_title]').value, u = r.querySelector('[name=item_url]').value;
      if (!t.trim() && !u.trim()) { return; }
      p.append('it_title', t); p.append('it_url', u);
      p.append('it_icon', r.querySelector('[name=item_icon]').value); p.append('it_desc', r.querySelector('[name=item_desc]').value);
    });
    frame.src = '/admin/pages/preview?' + p.toString();
  }
  function later() { clearTimeout(timer); timer = setTimeout(preview, 350); }
  form.addEventListener('input', later);
  form.addEventListener('change', later);
  var accent = form.elements['accent'];
  if (accent) { accent.addEventListener('input', function () { form.elements['accent_same'].checked = false; }); }
  holder.addEventListener('click', function (e) {
    var row = e.target.closest ? e.target.closest('[data-row]') : null;
    if (!row) { return; }
    if (e.target.closest('[data-up]')) { var prev = row.previousElementSibling; while (prev && prev.hidden) { prev = prev.previousElementSibling; } if (prev) { holder.insertBefore(row, prev); later(); } }
    else if (e.target.closest('[data-down]')) { var next = row.nextElementSibling; while (next && next.hidden) { next = next.nextElementSibling; } if (next) { holder.insertBefore(next, row); later(); } }
    else if (e.target.closest('[data-remove]')) {
      row.querySelectorAll('input[type=text]').forEach(function (i) { i.value = ''; });
      row.hidden = true; later();
    }
  });
  var add = form.querySelector('[data-add]');
  if (add) {
    add.addEventListener('click', function () {
      var all = holder.querySelectorAll('[data-row]'), copy = all[all.length - 1].cloneNode(true);
      copy.hidden = false;
      copy.querySelectorAll('input[type=text]').forEach(function (i) { i.value = ''; });
      copy.querySelector('[name=item_id]').value = '';
      copy.querySelector('[name=item_icon]').value = 'auto';
      copy.querySelectorAll('.field-error').forEach(function (x) { x.remove(); });
      holder.appendChild(copy);
      copy.querySelector('[name=item_title]').focus();
    });
  }
})();
