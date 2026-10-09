/* The writing assistant's interface, shared by its own page (/writer/) and the Writing assistant tab in the admin.
 *   WriterUI.mount(rootElement, { getCsrf: () => token, onAuthLost: () => void })
 * Two modes: improve a message you wrote, or draft a reply to a customer's email. It builds its own markup (static text,
 * no user content) and uses WriterLib for all text handling. */
(function (global) {
  'use strict';
  var L = global.WriterLib;

  var AUDIENCES = '<option value="">Not sure</option><option value="existing_customer">An existing customer</option><option value="new_customer">A new customer</option>' +
    '<option value="supplier">A supplier</option><option value="colleague">A colleague</option><option value="senior_management">Senior management</option>' +
    '<option value="technical">Technical correspondence</option><option value="complaint">A complaint</option><option value="personal">A personal message</option>';

  var TEMPLATE =
    '<div class="wr-modes" role="tablist" aria-label="What would you like to do?">' +
      '<button type="button" role="tab" class="wr-mode wr-on" data-w="modeImprove" aria-selected="true">Improve my message</button>' +
      '<button type="button" role="tab" class="wr-mode" data-w="modeReply" aria-selected="false">Reply to a customer email</button>' +
    '</div>' +

    /* ---- improve ---- */
    '<div data-w="improvePanel">' +
    '<div class="wr-card">' +
      '<h2>Check and improve a message</h2>' +
      '<p class="wr-hint">Paste an email or message below. You will get an improved version in British English that still sounds like you, and a list of what was changed and why. Formatting such as bold, bullets and links is kept.</p>' +
      '<div class="wr-row" style="margin-top:0"><div><label>Who is it for?</label><select data-w="audience">' + AUDIENCES + '</select></div></div>' +
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
    '</div></div>' +
    '</div>' +

    /* ---- reply ---- */
    '<div data-w="replyPanel" hidden>' +
    '<div class="wr-card">' +
      '<h2>Reply to a customer email</h2>' +
      '<p class="wr-hint">Paste the email you have received, then tell us what you want to say. You will get a draft reply in British English, in your voice. Nothing is made up: anything the email asks that your points do not cover is left for you to fill in, in [square brackets].</p>' +
      '<div class="wr-row" style="margin-top:0"><div><label>Who is it from?</label><select data-w="rAudience">' + AUDIENCES + '</select></div>' +
        '<div><label>Your name, for the sign-off</label><input type="text" data-w="rName" maxlength="60" placeholder="e.g. Dan" autocomplete="off"></div></div>' +
      '<label style="margin-top:.8rem">The email you received</label>' +
      '<div data-w="rEmail" class="wr-editor" contenteditable="true" role="textbox" aria-multiline="true" aria-label="The email you received" data-placeholder="Paste the customer\'s email here"></div>' +
      '<label style="margin-top:.8rem">What do you want to say? <span class="wr-small" style="font-weight:400">(your points)</span></label>' +
      '<textarea data-w="rPoints" rows="4" maxlength="2000" placeholder="e.g. Yes, in stock. £39.95 plus VAT each. Can ship tomorrow if ordered before 3pm."></textarea>' +
      '<p class="wr-small" style="margin:.3rem 0 0">The more you put here, the less you will need to fill in. Prices, dates and promises only ever come from you.</p>' +
      '<div class="wr-row"><button type="button" data-w="rGo">Write a reply</button><button type="button" class="wr-alt" data-w="rClear">Clear</button><span class="wr-small" data-w="rCount"></span></div>' +
      '<p class="wr-small" style="margin-bottom:0">The email and your points are sent to Google Gemini to draft the reply, and this tool does not keep them. Please do not paste passwords or payment card numbers. Always read the draft before you send it.</p>' +
    '</div>' +
    '<div class="wr-note wr-err" role="alert" data-w="rError" hidden></div>' +
    '<div data-w="rResult" hidden aria-live="polite"><div class="wr-card">' +
      '<div class="wr-row" style="margin-top:0"><h2 style="margin:0">Draft reply</h2></div>' +
      '<div class="wr-note wr-warn" data-w="rWarnings" hidden></div>' +
      '<div class="wr-note wr-warn" data-w="rFill" hidden></div>' +
      '<div class="wr-grid"><div>' +
        '<div class="wr-out" data-w="rOut"></div>' +
        '<div class="wr-row"><button type="button" data-w="rCopy">Copy reply</button><button type="button" class="wr-alt" data-w="rCopyPlain">Copy as plain text</button><button type="button" class="wr-alt" data-w="rEdit">Edit and check this</button></div>' +
        '<div class="wr-note wr-ok" data-w="rCopied" hidden role="status"></div>' +
        '<p class="wr-small">Copying keeps the formatting. Fill in any highlighted [brackets] before you send. <em>Edit and check this</em> moves the draft to the other tab, so you can change it and have it checked.</p>' +
      '</div><div>' +
        '<h2 style="margin:0 0 .3rem">How this was written</h2>' +
        '<ol class="wr-changes" data-w="rNotes"></ol>' +
        '<div data-w="rCheckBox" hidden><h2 style="margin:.8rem 0 .3rem">Please check before you send</h2><ul class="wr-checks" data-w="rChecks"></ul></div>' +
      '</div></div>' +
    '</div></div>' +
    '</div>';

  var LEVELS = { none: 'No changes needed', light: 'Light edit', moderate: 'Moderate edit', full: 'Restructured' };

  function mount(root, opts) {
    root.classList.add('wr');
    root.innerHTML = TEMPLATE;
    var $ = function (n) { return root.querySelector('[data-w="' + n + '"]'); };
    var last = null, originalText = '', lastReply = null;
    function show(el, on) { el.hidden = !on; }
    function note(id, msg) { var e = $(id); e.textContent = msg || ''; show(e, !!msg); }
    function textOf(id) { return L.htmlToText($(id)); }

    /* ---- the two modes ---- */
    function setMode(m) {
      var reply = m === 'reply';
      show($('improvePanel'), !reply); show($('replyPanel'), reply);
      $('modeImprove').classList.toggle('wr-on', !reply); $('modeReply').classList.toggle('wr-on', reply);
      $('modeImprove').setAttribute('aria-selected', String(!reply)); $('modeReply').setAttribute('aria-selected', String(reply));
    }
    $('modeImprove').addEventListener('click', function () { setMode('improve'); $('input').focus(); });
    $('modeReply').addEventListener('click', function () { setMode('reply'); $('rEmail').focus(); });

    /* ---- pasting keeps bold, lists and links, but only as markup this tool builds itself ---- */
    function pasteInto(el, after) {
      el.addEventListener('paste', function (e) {
        var cd = e.clipboardData; if (!cd) return;
        var html = cd.getData('text/html'), txt = cd.getData('text/plain');
        e.preventDefault();
        var safe = html ? L.mdToHtml(L.htmlToText(new DOMParser().parseFromString(html, 'text/html').body)) : L.plainToHtml(txt);
        document.execCommand('insertHTML', false, safe);
        after();
      });
    }
    function count(id, outId, max) {
      var n = (id === 'rPoints' ? $(id).value : textOf(id)).length;
      $(outId).textContent = n ? n.toLocaleString('en-GB') + ' of ' + max.toLocaleString('en-GB') + ' characters' : '';
      $(outId).style.color = n > max ? '#a11' : '';
    }
    var cI = function () { count('input', 'count', 6000); }, cR = function () { count('rEmail', 'rCount', 6000); };
    pasteInto($('input'), cI); pasteInto($('rEmail'), cR);
    $('input').addEventListener('input', cI); $('rEmail').addEventListener('input', cR);

    function api(body) {
      return fetch('/api/writer.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, data: j }; }); });
    }
    function copyTools(richBtn, plainBtn, noteId, getMd) {
      function say(msg) { var c = $(noteId); c.textContent = msg; show(c, true); setTimeout(function () { show(c, false); }, 4000); }
      $(richBtn).addEventListener('click', function () {
        var md = getMd(); if (!md) return;
        var html = '<div>' + L.mdToHtml(md) + '</div>', plain = L.mdToPlain(md);
        function fallback() {
          var tmp = document.createElement('div'); tmp.contentEditable = 'true'; tmp.style.cssText = 'position:fixed;left:-9999px;top:0;white-space:pre-wrap';
          tmp.innerHTML = html; document.body.appendChild(tmp);
          var range = document.createRange(); range.selectNodeContents(tmp);
          var sel = global.getSelection(); sel.removeAllRanges(); sel.addRange(range);
          var ok = false; try { ok = document.execCommand('copy'); } catch (x) {}
          sel.removeAllRanges(); document.body.removeChild(tmp);
          say(ok ? 'Copied, with its formatting.' : 'Your browser would not copy. Select the message and press Ctrl+C.');
        }
        if (navigator.clipboard && global.ClipboardItem) {
          navigator.clipboard.write([new ClipboardItem({ 'text/html': new Blob([html], { type: 'text/html' }), 'text/plain': new Blob([plain], { type: 'text/plain' }) })])
            .then(function () { say('Copied, with its formatting.'); }, fallback);
        } else fallback();
      });
      $(plainBtn).addEventListener('click', function () {
        var md = getMd(); if (!md) return;
        (navigator.clipboard ? navigator.clipboard.writeText(L.mdToPlain(md)) : Promise.reject()).then(function () { say('Copied as plain text.'); }, function () { say('Your browser would not copy. Select the message and press Ctrl+C.'); });
      });
    }
    function busy(btn, on, idle) { btn.disabled = on; btn.textContent = on ? 'Working...' : idle; if (on) btn.setAttribute('aria-busy', 'true'); else btn.removeAttribute('aria-busy'); }
    function lostSession(errId) { if (opts.onAuthLost) opts.onAuthLost(); note(errId, 'Your session has ended. Please sign in again.'); }

    /* ================= improve ================= */
    function drawImproved() {
      var box = $('improved');
      if ($('showdiff').checked) {
        var h = L.diffHtml(L.mdToPlain(originalText), L.mdToPlain(last.improved));
        box.innerHTML = h === null ? '<p>This message is too long to mark up. Untick the box to see the improved version.</p>' : '<p style="margin:0">' + h + '</p>';
      } else box.innerHTML = L.mdToHtml(last.improved);
    }
    $('showdiff').addEventListener('change', drawImproved);
    $('clear').addEventListener('click', function () { $('input').innerHTML = ''; show($('result'), false); note('error', ''); cI(); $('input').focus(); });

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
      note('error', ''); show($('copied'), false);
      originalText = textOf('input');
      if (!originalText.trim()) { note('error', 'Paste or type a message first.'); return; }
      var btn = $('go'); busy(btn, true, 'Check and improve');
      api({ csrf: opts.getCsrf(), text: originalText, audience: $('audience').value }).then(function (r) {
        if (r.status === 401) { lostSession('error'); return; }
        if (r.status !== 200 || !r.data.improved) { note('error', r.data.error || 'Something went wrong. Please try again.'); return; }
        render(r.data);
      }).catch(function () { note('error', 'Could not reach the server. Please try again.'); }).then(function () { busy(btn, false, 'Check and improve'); });
    }
    $('go').addEventListener('click', go);
    $('input').addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); go(); } });
    copyTools('copy', 'copyplain', 'copied', function () { return last && last.improved; });
    $('again').addEventListener('click', function () { $('input').innerHTML = L.mdToHtml(last.improved); cI(); show($('result'), false); root.scrollIntoView({ behavior: 'smooth', block: 'start' }); $('input').focus(); });

    /* ================= reply ================= */
    try { $('rName').value = localStorage.getItem('wr_name') || ''; } catch (e) {}
    $('rName').addEventListener('change', function () { try { localStorage.setItem('wr_name', $('rName').value.trim()); } catch (e) {} });
    $('rPoints').addEventListener('input', function () { count('rPoints', 'rCount', 2000); });
    $('rClear').addEventListener('click', function () { $('rEmail').innerHTML = ''; $('rPoints').value = ''; show($('rResult'), false); note('rError', ''); cR(); $('rEmail').focus(); });

    function markPlaceholders(html) { return html.replace(/\[([^\[\]<>]{1,80})\]/g, '<mark>[$1]</mark>'); }
    function renderReply(d) {
      lastReply = d;
      var w = $('rWarnings');
      if (d.warnings && d.warnings.length) {
        w.innerHTML = '<strong>Please check before you send:</strong><ul>' + d.warnings.map(function (t) { return '<li>' + L.esc(t) + '</li>'; }).join('') + '</ul>';
        show(w, true);
      } else show(w, false);
      var f = $('rFill');
      if (d.placeholders && d.placeholders.length) {
        f.innerHTML = '<strong>Fill in before you send:</strong> ' + d.placeholders.map(function (t) { return '<mark>' + L.esc(t) + '</mark>'; }).join(' ');
        show(f, true);
      } else show(f, false);
      $('rOut').innerHTML = markPlaceholders(L.mdToHtml(d.reply));
      var list = $('rNotes'); list.innerHTML = '';
      (d.notes || []).forEach(function (c) { var li = document.createElement('li'); li.innerHTML = '<strong>' + L.esc(c.point) + '</strong><span class="wr-why">' + L.esc(c.why) + '</span>'; list.appendChild(li); });
      var checks = $('rChecks'); checks.innerHTML = '';
      (d.check || []).forEach(function (t) { var li = document.createElement('li'); li.textContent = t; checks.appendChild(li); });
      show($('rCheckBox'), (d.check || []).length > 0);
      show($('rResult'), true);
      $('rResult').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    function goReply() {
      note('rError', ''); show($('rCopied'), false);
      var email = textOf('rEmail');
      if (!email.trim()) { note('rError', 'Paste the customer\'s email first.'); return; }
      var btn = $('rGo'); busy(btn, true, 'Write a reply');
      api({ csrf: opts.getCsrf(), mode: 'reply', email: email, points: $('rPoints').value, name: $('rName').value.trim(), audience: $('rAudience').value }).then(function (r) {
        if (r.status === 401) { lostSession('rError'); return; }
        if (r.status !== 200 || !r.data.reply) { note('rError', r.data.error || 'Something went wrong. Please try again.'); return; }
        renderReply(r.data);
      }).catch(function () { note('rError', 'Could not reach the server. Please try again.'); }).then(function () { busy(btn, false, 'Write a reply'); });
    }
    $('rGo').addEventListener('click', goReply);
    $('rEmail').addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); goReply(); } });
    copyTools('rCopy', 'rCopyPlain', 'rCopied', function () { return lastReply && lastReply.reply; });
    $('rEdit').addEventListener('click', function () {
      $('input').innerHTML = L.mdToHtml(lastReply.reply); $('audience').value = $('rAudience').value; cI();
      setMode('improve'); show($('result'), false); root.scrollIntoView({ behavior: 'smooth', block: 'start' }); $('input').focus();
    });

    return { focus: function () { $('input').focus(); } };
  }

  global.WriterUI = { mount: mount };
})(window);
