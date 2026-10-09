/* The writing assistant's interface, shared by its own page (/writer/) and the Writing assistant tab in the admin.
 *   WriterUI.mount(rootElement, { getCsrf: () => token, onAuthLost: () => void })
 * It builds its own markup (static text, no user content) and uses WriterLib for all text handling. */
(function (global) {
  'use strict';
  var L = global.WriterLib;

  var TEMPLATE =
    '<div class="wr-card">' +
      '<h2>Check and improve a message</h2>' +
      '<p class="wr-hint">Paste an email or message below. You will get an improved version in British English that still sounds like you, and a list of what was changed and why. Formatting such as bold, bullets and links is kept.</p>' +
      '<div class="wr-row" style="margin-top:0"><div><label>Who is it for?</label>' +
        '<select data-w="audience"><option value="">Not sure</option><option value="existing_customer">An existing customer</option><option value="new_customer">A new customer</option>' +
        '<option value="supplier">A supplier</option><option value="colleague">A colleague</option><option value="senior_management">Senior management</option>' +
        '<option value="technical">Technical correspondence</option><option value="complaint">A complaint</option><option value="personal">A personal message</option></select></div></div>' +
      '<label style="margin-top:.8rem">Your message</label>' +
      '<div data-w="input" class="wr-editor" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Your message" data-placeholder="Paste or type your message here"></div>' +
      '<div class="wr-row"><button type="button" data-w="go">Check and improve</button><button type="button" class="wr-alt" data-w="clear">Clear</button><span class="wr-small" data-w="count"></span></div>' +
      '<p class="wr-small" style="margin-bottom:0">Your message is sent to Google Gemini to be edited, and this tool does not keep it. Please do not paste passwords or payment card numbers. Always read the result before you send it.</p>' +
    '</div>' +
    '<div class="wr-note wr-err" role="alert" data-w="error" hidden></div>' +
    '<div data-w="result" hidden aria-live="polite"><div class="wr-card">' +
      '<div class="wr-row" style="margin-top:0"><h2 style="margin:0">Improved message</h2><span class="wr-pill" data-w="level"></span></div>' +
      '<div class="wr-note wr-warn" data-w="warnings" hidden></div>' +
      '<div class="wr-grid"><div>' +
        '<div class="wr-out" data-w="improved"></div>' +
        '<div class="wr-row"><button type="button" data-w="copy">Copy improved message</button><button type="button" class="wr-alt" data-w="copyplain">Copy as plain text</button><button type="button" class="wr-alt" data-w="again">Edit this version</button></div>' +
        '<div class="wr-note wr-ok" data-w="copied" hidden role="status"></div>' +
        '<p class="wr-small">Copying keeps the formatting, so you can paste it straight into Outlook, Word or a web mail message.</p>' +
      '</div><div>' +
        '<div class="wr-row" style="margin-top:0"><h2 style="margin:0">What changed and why</h2></div>' +
        '<div class="wr-note wr-ok" data-w="nochange" hidden>Nothing needed changing. Your message already reads well.</div>' +
        '<div class="wr-note wr-warn" data-w="smallchange" hidden>Small changes were made, such as capital letters or punctuation. Tick the box below to see each one.</div>' +
        '<ol class="wr-changes" data-w="changes"></ol>' +
        '<div class="wr-row"><label style="margin:0;font-weight:400"><input type="checkbox" data-w="showdiff"> Mark every change in the message</label></div>' +
        '<p class="wr-small" style="margin-top:.4rem">The list above covers the main changes. Tick the box to see all of them, including small ones.</p>' +
      '</div></div>' +
    '</div></div>';

  var LEVELS = { none: 'No changes needed', light: 'Light edit', moderate: 'Moderate edit', full: 'Restructured' };

  function mount(root, opts) {
    root.classList.add('wr');
    root.innerHTML = TEMPLATE;
    var $ = function (n) { return root.querySelector('[data-w="' + n + '"]'); };
    var last = null, originalText = '';
    function show(el, on) { el.hidden = !on; }
    function showError(msg) { var e = $('error'); e.textContent = msg || ''; show(e, !!msg); }
    function currentText() { return L.htmlToText($('input')); }
    function updateCount() {
      var n = currentText().length;
      $('count').textContent = n ? n.toLocaleString('en-GB') + ' of 6,000 characters' : '';
      $('count').style.color = n > 6000 ? '#a11' : '';
    }

    // Pasting keeps bold, lists and links, but only as markup this tool builds itself.
    $('input').addEventListener('paste', function (e) {
      var cd = e.clipboardData; if (!cd) return;
      var html = cd.getData('text/html'), txt = cd.getData('text/plain');
      e.preventDefault();
      var safe = html ? L.mdToHtml(L.htmlToText(new DOMParser().parseFromString(html, 'text/html').body)) : L.plainToHtml(txt);
      document.execCommand('insertHTML', false, safe);
      updateCount();
    });
    $('input').addEventListener('input', updateCount);
    $('input').addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); go(); } });
    $('clear').addEventListener('click', function () { $('input').innerHTML = ''; show($('result'), false); showError(''); updateCount(); $('input').focus(); });

    function api(body) {
      return fetch('/api/writer.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, data: j }; }); });
    }

    function drawImproved() {
      var box = $('improved');
      if ($('showdiff').checked) {
        var h = L.diffHtml(L.mdToPlain(originalText), L.mdToPlain(last.improved));
        box.innerHTML = h === null ? '<p>This message is too long to mark up. Untick the box to see the improved version.</p>' : '<p style="margin:0">' + h + '</p>';
      } else box.innerHTML = L.mdToHtml(last.improved);
    }
    $('showdiff').addEventListener('change', drawImproved);

    function render(d) {
      last = d;
      var pill = $('level'); pill.textContent = LEVELS[d.level] || 'Edited'; pill.className = 'wr-pill' + (d.level === 'none' ? ' wr-none' : '');
      var w = $('warnings');
      if (d.warnings && d.warnings.length) {
        w.innerHTML = '<strong>Please check before you send:</strong><ul>' + d.warnings.map(function (t) { return '<li>' + L.esc(t) + '</li>'; }).join('') + '</ul>';
        show(w, true);
      } else show(w, false);
      $('showdiff').checked = false; drawImproved();
      var list = $('changes'); list.innerHTML = '';
      d.changes.forEach(function (c) { var li = document.createElement('li'); li.innerHTML = '<strong>' + L.esc(c.change) + '</strong><span class="wr-why">' + L.esc(c.why) + '</span>'; list.appendChild(li); });
      var changed = d.changed !== false;
      show($('nochange'), !changed); show($('smallchange'), changed && d.changes.length === 0); show($('result'), true);
      $('result').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function go() {
      showError(''); show($('copied'), false);
      originalText = currentText();
      if (!originalText.trim()) { showError('Paste or type a message first.'); return; }
      var btn = $('go'); btn.disabled = true; btn.textContent = 'Checking...'; btn.setAttribute('aria-busy', 'true');
      api({ csrf: opts.getCsrf(), text: originalText, audience: $('audience').value }).then(function (r) {
        if (r.status === 401) { if (opts.onAuthLost) opts.onAuthLost(); showError('Your session has ended. Please sign in again.'); return; }
        if (r.status !== 200 || !r.data.improved) { showError(r.data.error || 'Something went wrong. Please try again.'); return; }
        render(r.data);
      }).catch(function () { showError('Could not reach the server. Please try again.'); })
        .then(function () { btn.disabled = false; btn.textContent = 'Check and improve'; btn.removeAttribute('aria-busy'); });
    }
    $('go').addEventListener('click', go);

    function copied(msg) { var c = $('copied'); c.textContent = msg; show(c, true); setTimeout(function () { show(c, false); }, 4000); }

    // Rich copy puts real formatting on the clipboard along with the plain text, so pasting keeps bold, lists and links.
    $('copy').addEventListener('click', function () {
      if (!last) return;
      var html = '<div>' + L.mdToHtml(last.improved) + '</div>', plain = L.mdToPlain(last.improved);
      function fallback() {
        var tmp = document.createElement('div'); tmp.contentEditable = 'true'; tmp.style.cssText = 'position:fixed;left:-9999px;top:0;white-space:pre-wrap';
        tmp.innerHTML = html; document.body.appendChild(tmp);
        var range = document.createRange(); range.selectNodeContents(tmp);
        var sel = global.getSelection(); sel.removeAllRanges(); sel.addRange(range);
        var ok = false; try { ok = document.execCommand('copy'); } catch (x) {}
        sel.removeAllRanges(); document.body.removeChild(tmp);
        copied(ok ? 'Copied, with its formatting.' : 'Your browser would not copy. Select the message and press Ctrl+C.');
      }
      if (navigator.clipboard && global.ClipboardItem) {
        navigator.clipboard.write([new ClipboardItem({ 'text/html': new Blob([html], { type: 'text/html' }), 'text/plain': new Blob([plain], { type: 'text/plain' }) })])
          .then(function () { copied('Copied, with its formatting.'); }, fallback);
      } else fallback();
    });
    $('copyplain').addEventListener('click', function () {
      if (!last) return;
      var plain = L.mdToPlain(last.improved);
      (navigator.clipboard ? navigator.clipboard.writeText(plain) : Promise.reject()).then(function () { copied('Copied as plain text.'); }, function () { copied('Your browser would not copy. Select the message and press Ctrl+C.'); });
    });
    $('again').addEventListener('click', function () { $('input').innerHTML = L.mdToHtml(last.improved); updateCount(); show($('result'), false); root.scrollIntoView({ behavior: 'smooth', block: 'start' }); $('input').focus(); });

    return { focus: function () { $('input').focus(); } };
  }

  global.WriterUI = { mount: mount };
})(window);
