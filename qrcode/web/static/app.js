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
