/* The file manager, shared by the File sharing tab in the admin (and so the Windows app).
 *   FilesUI.mount(rootElement, { getCsrf: () => token, onAuthLost: () => void, isAdmin: bool })
 * Builds its own markup; every name that comes from a user or from a file is escaped before it is shown. */
(function (global) {
  'use strict';
  var API = '/api/admin/files.php', CHUNK = 1024 * 1024;

  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
  function fmtSize(n) { if (n < 1024) return n + ' B'; var u = ['KB', 'MB', 'GB'], i = -1; do { n /= 1024; i++; } while (n >= 1024 && i < 2); return (n >= 100 ? Math.round(n) : n.toFixed(1)) + ' ' + u[i]; }
  function fmtDate(ts) { return ts ? new Date(ts * 1000).toLocaleString('en-GB', { timeZone: 'Europe/London', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : ''; }
  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function ico(n) { return n.kind === 'folder' ? '&#128193;' : (/\.zip$/i.test(n.name) ? '&#128476;' : (/^image\//.test(n.mime || '') ? '&#128444;' : '&#128196;')); }
  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) return navigator.clipboard.writeText(text);
    return new Promise(function (ok, no) {
      var t = document.createElement('textarea'); t.value = text; t.style.cssText = 'position:fixed;left:-9999px'; document.body.appendChild(t); t.select();
      var done = false; try { done = document.execCommand('copy'); } catch (e) {} document.body.removeChild(t); done ? ok() : no();
    });
  }
  var STATE = { open: ['Active', 'fs-open'], not_yet: ['Not yet open', 'fs-warn'], expired: ['Expired', 'fs-off'], revoked: ['Withdrawn', 'fs-off'] };

  var TEMPLATE =
    '<div class="fs-modes"><button type="button" class="fs-mode fs-on" data-f="tabMine">My files</button><button type="button" class="fs-mode" data-f="tabWith">Shared with me</button><button type="button" class="fs-mode" data-f="tabShares">My shares</button></div>' +
    '<div data-f="viewMine">' +
      '<div class="fs-bar"><button type="button" data-f="bNew">New folder</button><button type="button" data-f="bUpload">Upload files</button><button type="button" class="fs-alt" data-f="bUploadDir">Upload folder</button><span class="fs-sp"></span><span class="fs-small" data-f="usage"></span></div>' +
      '<div class="fs-bar" data-f="selbar" style="visibility:hidden"><span class="fs-small" data-f="selcount"></span><button type="button" data-f="bShare">Share&hellip;</button><button type="button" class="fs-alt" data-f="bCopy">Copy link</button><button type="button" class="fs-alt" data-f="bZip">Zip</button><button type="button" class="fs-alt" data-f="bDownload">Download</button><button type="button" class="fs-danger" data-f="bDelete">Delete</button></div>' +
      '<div class="fs-crumbs" data-f="crumbs"></div>' +
      '<div class="fs-list" data-f="drop" tabindex="0"><table><thead><tr><th class="fs-chk"><input type="checkbox" data-f="all" aria-label="Select all"></th><th>Name</th><th style="text-align:right">Size</th><th style="text-align:right">Modified</th></tr></thead><tbody data-f="rows"></tbody></table><div class="fs-empty" data-f="empty" hidden>This folder is empty.<br>Drag files here, or press <strong>Upload files</strong>.</div></div>' +
      '<div class="fs-uploads" data-f="uploads"></div>' +
      '<input type="file" multiple hidden data-f="fileIn"><input type="file" webkitdirectory hidden data-f="dirIn">' +
      '<p class="fs-small" style="margin:.6rem 0 0">Right-click a file or folder for more: share, copy link, send by email, zip, unzip, rename and move. Drag items onto a folder to move them.</p>' +
    '</div>' +
    '<div data-f="viewWith" hidden><div class="fs-crumbs" data-f="withCrumbs"></div><div class="fs-list" data-f="withList"></div></div>' +
    '<div data-f="viewShares" hidden><div class="fs-bar"><span class="fs-small">Everything you have shared. Copy a link again at any time, or withdraw it.</span><span class="fs-sp"></span><label class="fs-inline" data-f="allWrap" hidden style="margin:0"><input type="checkbox" data-f="allShares"> Show everyone&rsquo;s</label></div><div class="fs-list" data-f="shareList"></div></div>' +
    '<div class="fs-menu" data-f="menu" hidden></div><div data-f="modalHost"></div>';

  function mount(root, opts) {
    root.classList.add('fs');
    root.innerHTML = TEMPLATE;
    var $ = function (n) { return root.querySelector('[data-f="' + n + '"]'); };
    var cur = { parent: null, path: [], items: [] }, sel = new Set(), lastIdx = -1, view = 'mine', staffCache = null;
    var withState = { share: null, parent: null };

    /* ---------- talking to the server ---------- */
    function authLost() { if (opts.onAuthLost) opts.onAuthLost(); }
    function handle(r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (r.status === 401) { authLost(); throw new Error('Your session has ended. Please sign in again.'); }
        if (!r.ok) { var e = new Error(j.error || 'Something went wrong. Please try again.'); e.status = r.status; e.data = j; throw e; }
        return j;
      });
    }
    function get(action, params) {
      var q = ['action=' + encodeURIComponent(action)]; Object.keys(params || {}).forEach(function (k) { if (params[k] != null && params[k] !== '') q.push(k + '=' + encodeURIComponent(params[k])); });
      return fetch(API + '?' + q.join('&'), { credentials: 'same-origin' }).then(handle);
    }
    function post(body) {
      body.csrf = opts.getCsrf();
      return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(handle);
    }
    var toastTimer;
    function toast(msg) {
      var t = root.querySelector('.fs-toast'); if (!t) { t = document.createElement('div'); t.className = 'fs-toast'; t.setAttribute('role', 'status'); root.appendChild(t); }
      t.textContent = msg; t.hidden = false; clearTimeout(toastTimer); toastTimer = setTimeout(function () { t.hidden = true; }, 3500);
    }
    function fail(e) { toast(e && e.message ? e.message : 'Something went wrong.'); }

    /* ---------- dialogs ---------- */
    function modal(html, wire) {
      var host = $('modalHost'), shade = document.createElement('div');
      shade.className = 'fs-shade'; shade.innerHTML = '<div class="fs-modal" role="dialog" aria-modal="true">' + html + '</div>';
      host.appendChild(shade);
      var box = shade.firstChild, m = function (n) { return box.querySelector('[data-m="' + n + '"]'); };
      var api = { el: box, m: m, close: function () { if (shade.parentNode) shade.parentNode.removeChild(shade); document.removeEventListener('keydown', onKey); } };
      function onKey(e) { if (e.key === 'Escape') api.close(); }
      document.addEventListener('keydown', onKey);
      shade.addEventListener('mousedown', function (e) { if (e.target === shade) api.close(); });
      wire(api);
      var first = box.querySelector('input[type=text],textarea'); if (first) first.focus();
      return api;
    }
    function ask(title, label, value, okText) {
      return new Promise(function (resolve) {
        modal('<h3>' + esc(title) + '</h3><label>' + esc(label) + '</label><input type="text" data-m="v" maxlength="180" value="' + esc(value || '') + '"><div class="fs-actions"><button type="button" class="fs-alt" data-m="no">Cancel</button><button type="button" data-m="ok">' + esc(okText || 'OK') + '</button></div>', function (d) {
          var done = function (v) { d.close(); resolve(v); };
          d.m('no').onclick = function () { done(null); }; d.m('ok').onclick = function () { done(d.m('v').value); };
          d.m('v').addEventListener('keydown', function (e) { if (e.key === 'Enter') done(d.m('v').value); }); d.m('v').select();
        });
      });
    }
    function confirmBox(title, text, okText) {
      return new Promise(function (resolve) {
        modal('<h3>' + esc(title) + '</h3><p>' + esc(text) + '</p><div class="fs-actions"><button type="button" class="fs-alt" data-m="no">Cancel</button><button type="button" class="fs-danger" data-m="ok">' + esc(okText || 'OK') + '</button></div>', function (d) {
          d.m('no').onclick = function () { d.close(); resolve(false); }; d.m('ok').onclick = function () { d.close(); resolve(true); };
        });
      });
    }

    /* ---------- the file list ---------- */
    function selNodes() { return cur.items.filter(function (n) { return sel.has(n.id); }); }
    function load(parent) {
      if (parent !== undefined) cur.parent = parent;
      return get('list', { parent: cur.parent || '' }).then(function (d) {
        cur.path = d.path; cur.items = d.items; sel.clear(); lastIdx = -1; render();
        $('usage').textContent = 'Using ' + fmtSize(d.usage.total) + ' of ' + fmtSize(d.usage.quota) + ' in total';
      }).catch(fail);
    }
    function render() {
      var c = $('crumbs'); c.innerHTML = '<a data-go="0">My files</a>' + cur.path.map(function (p) { return '<span>/</span><a data-go="' + p.id + '">' + esc(p.name) + '</a>'; }).join('');
      $('rows').innerHTML = cur.items.map(function (n, i) {
        var badges = (n.link_shares ? '<span class="fs-badge" title="Shared by link">&#128279; link</span>' : '') + (n.staff_shares ? '<span class="fs-badge" title="Shared with colleagues">&#128101; colleagues</span>' : '');
        return '<tr class="fs-row' + (sel.has(n.id) ? ' fs-sel' : '') + '" data-i="' + i + '" data-id="' + n.id + '" draggable="true"><td class="fs-chk"><input type="checkbox" ' + (sel.has(n.id) ? 'checked' : '') + ' aria-label="Select ' + esc(n.name) + '"></td>' +
          '<td class="fs-name"><span class="fs-ico">' + ico(n) + '</span>' + esc(n.name) + badges + '</td><td class="fs-n">' + (n.kind === 'file' ? fmtSize(n.size) : '') + '</td><td class="fs-n">' + fmtDate(n.updated_at) + '</td></tr>';
      }).join('');
      $('empty').hidden = cur.items.length > 0; paintSel();
    }
    // selection only changes how rows look; the rows themselves are not rebuilt, so a double-click lands on the row that was clicked
    function paintSel() {
      Array.prototype.forEach.call($('rows').children, function (tr, i) { var on = sel.has(cur.items[i].id); tr.classList.toggle('fs-sel', on); tr.querySelector('input').checked = on; });
      $('all').checked = cur.items.length > 0 && sel.size === cur.items.length;
      var n = sel.size; $('selbar').style.visibility = n === 0 ? 'hidden' : 'visible'; $('selcount').textContent = n + (n === 1 ? ' item selected' : ' items selected');
    }
    function setSel(ids) { sel = new Set(ids); paintSel(); }

    $('crumbs').addEventListener('click', function (e) { var a = e.target.closest('a[data-go]'); if (a) load(+a.dataset.go || null); });
    $('crumbs').addEventListener('dragover', function (e) { if (e.target.closest('a[data-go]') && e.dataTransfer.types.indexOf('text/fs-ids') >= 0) e.preventDefault(); });
    $('crumbs').addEventListener('drop', function (e) { var a = e.target.closest('a[data-go]'); var raw = e.dataTransfer.getData('text/fs-ids'); if (a && raw) { e.preventDefault(); moveTo(JSON.parse(raw), +a.dataset.go || null); } });
    $('all').addEventListener('change', function () { setSel(this.checked ? cur.items.map(function (n) { return n.id; }) : []); });
    $('rows').addEventListener('click', function (e) {
      var tr = e.target.closest('tr.fs-row'); if (!tr) return; var i = +tr.dataset.i, n = cur.items[i];
      if (e.target.type === 'checkbox') { sel.has(n.id) ? sel.delete(n.id) : sel.add(n.id); lastIdx = i; paintSel(); return; }
      if (e.shiftKey && lastIdx >= 0) { var a = Math.min(lastIdx, i), b = Math.max(lastIdx, i); setSel(cur.items.slice(a, b + 1).map(function (x) { return x.id; })); return; }
      if (e.ctrlKey || e.metaKey) { sel.has(n.id) ? sel.delete(n.id) : sel.add(n.id); } else { sel = new Set([n.id]); }
      lastIdx = i; paintSel();
    });
    $('rows').addEventListener('dblclick', function (e) { var tr = e.target.closest('tr.fs-row'); if (!tr) return; var n = cur.items[+tr.dataset.i]; n.kind === 'folder' ? load(n.id) : downloadNodes([n]); });
    $('drop').addEventListener('contextmenu', function (e) {
      e.preventDefault(); var tr = e.target.closest('tr.fs-row');
      if (tr) { var n = cur.items[+tr.dataset.i]; if (!sel.has(n.id)) { sel = new Set([n.id]); paintSel(); } showMenu(e.clientX, e.clientY, itemMenu()); }
      else { sel.clear(); paintSel(); showMenu(e.clientX, e.clientY, [['New folder', newFolder], ['Upload files', function () { $('fileIn').click(); }], ['Upload folder', function () { $('dirIn').click(); }]]); }
    });
    $('drop').addEventListener('keydown', function (e) {
      if (e.key === 'Delete' && sel.size) { e.preventDefault(); del(); }
      else if (e.key === 'F2' && sel.size === 1) { e.preventDefault(); rename(); }
      else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a') { e.preventDefault(); setSel(cur.items.map(function (n) { return n.id; })); }
      else if (e.key === 'Enter' && sel.size === 1) { var n = selNodes()[0]; n.kind === 'folder' ? load(n.id) : downloadNodes([n]); }
    });

    /* ---------- right-click menu ---------- */
    function showMenu(x, y, entries) {
      var m = $('menu'); m.innerHTML = '';
      entries.forEach(function (en) {
        if (en === '-') { m.appendChild(document.createElement('hr')); return; }
        var b = document.createElement('button'); b.type = 'button'; b.textContent = en[0]; if (en[2]) b.className = 'fs-danger-i';
        b.onclick = function () { hideMenu(); en[1](); }; m.appendChild(b);
      });
      m.hidden = false; var w = m.offsetWidth, h = m.offsetHeight;
      m.style.left = Math.max(4, Math.min(x, window.innerWidth - w - 8)) + 'px'; m.style.top = Math.max(4, Math.min(y, window.innerHeight - h - 8)) + 'px';
    }
    function hideMenu() { $('menu').hidden = true; }
    document.addEventListener('click', hideMenu); document.addEventListener('scroll', hideMenu, true);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hideMenu(); });
    function itemMenu() {
      var ns = selNodes(), one = ns.length === 1 ? ns[0] : null, e = [];
      if (one && one.kind === 'folder') e.push(['Open', function () { load(one.id); }]);
      e.push([one && one.kind === 'file' ? 'Download' : 'Download as zip', function () { downloadNodes(ns); }], '-',
        ['Share\u2026', function () { shareDialog(ns); }], ['Copy link', copyLink], ['Send link by email\u2026', emailLink], '-', ['Zip', zip]);
      if (one && /\.zip$/i.test(one.name)) e.push(['Unzip here', unzip]);
      e.push('-'); if (one) e.push(['Rename\u2026', rename]);
      e.push(['Move to\u2026', moveDialog], ['Delete', del, true]);
      return e;
    }

    /* ---------- actions on files ---------- */
    function newFolder() { ask('New folder', 'Name', '', 'Create').then(function (v) { if (v && v.trim()) post({ action: 'mkdir', parent: cur.parent || 0, name: v }).then(function () { load(); }).catch(fail); }); }
    function rename() { var n = selNodes()[0]; if (!n) return; ask('Rename', 'New name', n.name, 'Rename').then(function (v) { if (v && v.trim() && v !== n.name) post({ action: 'rename', id: n.id, name: v }).then(function () { load(); }).catch(fail); }); }
    function del() {
      var ns = selNodes(); if (!ns.length) return;
      confirmBox('Delete', ns.length === 1 ? 'Delete "' + ns[0].name + '"' + (ns[0].kind === 'folder' ? ' and everything in it' : '') + '? This cannot be undone, and any links to it will stop working.' : 'Delete these ' + ns.length + ' items, and everything in any folders? This cannot be undone, and any links to them will stop working.', 'Delete').then(function (ok) {
        if (ok) post({ action: 'delete', ids: ns.map(function (n) { return n.id; }) }).then(function (d) { toast('Deleted ' + d.deleted + (d.deleted === 1 ? ' item' : ' items')); load(); }).catch(fail);
      });
    }
    function zip() { var ns = selNodes(); if (!ns.length) return; toast('Making the zip\u2026'); post({ action: 'zip', ids: ns.map(function (n) { return n.id; }), parent: cur.parent || 0 }).then(function (d) { toast('Made ' + d.node.name); load().then(function () { setSel([d.node.id]); }); }).catch(fail); }
    function unzip() { var n = selNodes()[0]; if (!n) return; toast('Unzipping\u2026'); post({ action: 'unzip', id: n.id }).then(function (d) { toast('Unzipped into "' + d.node.name + '"'); load().then(function () { setSel([d.node.id]); }); }).catch(fail); }
    function downloadNodes(ns) {
      var a = document.createElement('a'); a.style.display = 'none';
      a.href = API + '?action=download&' + (ns.length === 1 ? 'id=' + ns[0].id : 'ids=' + ns.map(function (n) { return n.id; }).join(','));
      document.body.appendChild(a); a.click(); document.body.removeChild(a);
    }
    function moveTo(ids, parent) { post({ action: 'move', ids: ids, parent: parent || 0 }).then(function () { toast('Moved'); load(); }).catch(fail); }
    function moveDialog() {
      var ids = selNodes().map(function (n) { return n.id; }); if (!ids.length) return;
      get('tree').then(function (d) {
        var byParent = {}; d.folders.forEach(function (f) { (byParent[f.parent || 0] = byParent[f.parent || 0] || []).push(f); });
        var bad = new Set(ids); var rows = '<label class="fs-inline"><input type="radio" name="dest" value="0" checked> My files (top level)</label>';
        (function walk(p, depth) { (byParent[p] || []).forEach(function (f) { if (bad.has(f.id)) return; rows += '<label class="fs-inline" style="padding-left:' + (depth * 18) + 'px"><input type="radio" name="dest" value="' + f.id + '"> &#128193; ' + esc(f.name) + '</label>'; walk(f.id, depth + 1); }); })(0, 1);
        modal('<h3>Move ' + ids.length + (ids.length === 1 ? ' item' : ' items') + ' to&hellip;</h3><div class="fs-staff" style="max-height:300px">' + rows + '</div><div class="fs-actions"><button type="button" class="fs-alt" data-m="no">Cancel</button><button type="button" data-m="ok">Move here</button></div>', function (m) {
          m.m('no').onclick = m.close; m.m('ok').onclick = function () { var v = m.el.querySelector('input[name=dest]:checked').value; m.close(); moveTo(ids, +v || null); };
        });
      }).catch(fail);
    }
    function onRowDrag(e) {
      var tr = e.target.closest && e.target.closest('tr.fs-row'); if (!tr) return;
      var n = cur.items[+tr.dataset.i]; if (!sel.has(n.id)) { sel = new Set([n.id]); paintSel(); }
      e.dataTransfer.setData('text/fs-ids', JSON.stringify(Array.from(sel))); e.dataTransfer.effectAllowed = 'move';
    }
    $('rows').addEventListener('dragstart', onRowDrag);
    $('rows').addEventListener('dragover', function (e) {
      var tr = e.target.closest('tr.fs-row'); var internal = e.dataTransfer.types.indexOf('text/fs-ids') >= 0;
      Array.prototype.forEach.call(root.querySelectorAll('tr.fs-drop'), function (x) { x.classList.remove('fs-drop'); });
      if (tr && internal && cur.items[+tr.dataset.i].kind === 'folder' && !sel.has(cur.items[+tr.dataset.i].id)) { e.preventDefault(); tr.classList.add('fs-drop'); }
    });
    $('rows').addEventListener('drop', function (e) {
      var tr = e.target.closest('tr.fs-row'), raw = e.dataTransfer.getData('text/fs-ids');
      Array.prototype.forEach.call(root.querySelectorAll('tr.fs-drop'), function (x) { x.classList.remove('fs-drop'); });
      if (tr && raw && cur.items[+tr.dataset.i].kind === 'folder') { e.preventDefault(); e.stopPropagation(); moveTo(JSON.parse(raw), cur.items[+tr.dataset.i].id); }
    });

    /* ---------- uploading ---------- */
    function uploadRow(name) {
      var el = document.createElement('div'); el.className = 'fs-up'; el.innerHTML = '<span></span><div class="fs-bar-o"><div class="fs-bar-i"></div></div>';
      el.firstChild.textContent = name; $('uploads').appendChild(el); var bar = el.querySelector('.fs-bar-i');
      return { progress: function (f) { bar.style.width = Math.round(f * 100) + '%'; }, done: function () { bar.style.width = '100%'; setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 1500); },
               fail: function (m) { el.firstChild.textContent = name + ' \u2014 ' + m; el.style.borderColor = '#d99'; bar.style.background = '#b3261e'; setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 8000); } };
    }
    function chunkPost(upload, off, blob) {
      return fetch(API + '?action=upload_chunk&upload=' + upload + '&offset=' + off, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': opts.getCsrf(), 'Content-Type': 'application/octet-stream' }, body: blob });
    }
    async function uploadOne(file, parent, row) {
      var b = await post({ action: 'upload_begin', parent: parent || 0, name: file.name, size: file.size }), off = 0;
      while (off < file.size) {
        var blob = file.slice(off, Math.min(off + CHUNK, file.size)), tries = 0;
        for (;;) {
          var r = null, j = {};
          try { r = await chunkPost(b.upload, off, blob); j = await r.json().catch(function () { return {}; }); } catch (e) { r = null; }
          if (r && r.ok) { off = j.received; break; }
          if (r && r.status === 409 && typeof j.received === 'number') { off = j.received; break; }
          if (r && r.status === 401) { authLost(); throw new Error('Your session has ended.'); }
          if (r && r.status >= 400 && r.status < 500 && r.status !== 429) throw new Error(j.error || 'The upload was refused.');
          if (++tries >= 4) throw new Error('The connection failed.');
          await sleep(700 * tries);
        }
        row.progress(off / file.size);
      }
      await post({ action: 'upload_finish', upload: b.upload });
    }
    async function runUploads(list, parent) {
      var dirs = {};
      async function ensure(segs) {
        var pid = parent, key = '';
        for (var i = 0; i < segs.length; i++) { key += '/' + segs[i]; if (!dirs[key]) dirs[key] = (await post({ action: 'mkdir', parent: pid || 0, name: segs[i], reuse: true })).id; pid = dirs[key]; }
        return pid;
      }
      for (var i = 0; i < list.length; i++) {
        var it = list[i], row = uploadRow((it.dir && it.dir.length ? it.dir.join('/') + '/' : '') + it.file.name);
        try { await uploadOne(it.file, it.dir && it.dir.length ? await ensure(it.dir) : parent, row); row.done(); } catch (e) { row.fail(e.message || 'failed'); }
      }
      if (cur.parent === parent) load();
    }
    $('bUpload').onclick = function () { $('fileIn').click(); }; $('bUploadDir').onclick = function () { $('dirIn').click(); }; $('bNew').onclick = newFolder;
    $('fileIn').onchange = function () { var l = Array.prototype.map.call(this.files, function (f) { return { file: f, dir: [] }; }); this.value = ''; if (l.length) runUploads(l, cur.parent); };
    $('dirIn').onchange = function () {
      var l = Array.prototype.map.call(this.files, function (f) { var p = (f.webkitRelativePath || f.name).split('/'); p.pop(); return { file: f, dir: p }; }); this.value = ''; if (l.length) runUploads(l, cur.parent);
    };
    function collectDrop(dt) {
      return new Promise(function (resolve) {
        var out = [], items = Array.prototype.slice.call(dt.items || []), pending = 0;
        if (!items.length || !items[0].webkitGetAsEntry) { Array.prototype.forEach.call(dt.files, function (f) { out.push({ file: f, dir: [] }); }); return resolve(out); }
        function fin() { if (--pending === 0) resolve(out); }
        function walk(entry, path) {
          pending++;
          if (entry.isFile) entry.file(function (f) { out.push({ file: f, dir: path }); fin(); }, fin);
          else if (entry.isDirectory) {
            var rd = entry.createReader(), all = [];
            (function read() { rd.readEntries(function (es) { if (es.length) { all = all.concat(Array.prototype.slice.call(es)); read(); } else { all.forEach(function (e) { walk(e, path.concat([entry.name])); }); fin(); } }, fin); })();
          } else fin();
        }
        items.forEach(function (it) { var e = it.webkitGetAsEntry(); if (e) walk(e, []); });
        if (pending === 0) resolve(out);
      });
    }
    var dropBox = $('drop');
    dropBox.addEventListener('dragover', function (e) { if (e.dataTransfer.types.indexOf('Files') >= 0) { e.preventDefault(); dropBox.classList.add('fs-drop-zone'); } });
    dropBox.addEventListener('dragleave', function () { dropBox.classList.remove('fs-drop-zone'); });
    dropBox.addEventListener('drop', function (e) {
      dropBox.classList.remove('fs-drop-zone');
      if (e.dataTransfer.types.indexOf('Files') < 0) return;
      e.preventDefault(); var parent = cur.parent; collectDrop(e.dataTransfer).then(function (l) { if (l.length) runUploads(l, parent); });
    });

    /* ---------- sharing ---------- */
    function loadStaff() { return staffCache ? Promise.resolve(staffCache) : get('staff').then(function (d) { staffCache = d.staff; return staffCache; }); }
    function genPassword() { var c = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789', a = new Uint32Array(10); (global.crypto || global.msCrypto).getRandomValues(a); return Array.prototype.map.call(a, function (x) { return c[x % c.length]; }).join(''); }
    function localIn(d) { var p = function (n) { return (n < 10 ? '0' : '') + n; }; return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()); }

    function shareDialog(nodes, existing) {
      if (!nodes.length && !existing) return;
      var editing = !!existing, kind = editing ? existing.kind : 'link';
      var title = editing ? existing.title : (nodes.length === 1 ? nodes[0].name : nodes.length + ' items');
      loadStaff().then(function (staff) {
        var staffHtml = staff.map(function (s) { return '<label class="fs-inline"><input type="checkbox" data-staff="' + s.id + '"' + (editing && existing.users && existing.users.indexOf(s.name) >= 0 ? ' checked' : '') + '> ' + esc(s.name) + '</label>'; }).join('') || '<span class="fs-small">There are no other staff accounts.</span>';
        modal('<h3>' + (editing ? 'Edit share' : 'Share ' + (nodes.length === 1 ? '"' + esc(nodes[0].name) + '"' : nodes.length + ' items')) + '</h3>' +
          (editing ? '' : '<div class="fs-modes" style="margin-bottom:4px"><button type="button" class="fs-mode fs-on" data-m="kLink">Anyone with the link</button><button type="button" class="fs-mode" data-m="kStaff">Colleagues</button></div><div class="fs-small" data-m="kHint">For customers and anyone outside the company. They need no account.</div>') +
          '<label>Name of this share</label><input type="text" data-m="title" maxlength="120" value="' + esc(title) + '">' +
          '<label>Message <span class="fs-small" style="font-weight:400">(shown on the page, and in the email)</span></label><textarea data-m="message" maxlength="2000">' + esc(editing ? existing.message : '') + '</textarea>' +
          '<div data-m="linkOpts">' +
            (editing && existing.has_password ? '<label>Password</label><div><label class="fs-inline"><input type="radio" name="pwmode" value="keep" checked> Keep the current password</label><label class="fs-inline"><input type="radio" name="pwmode" value="change"> Set a new password</label><label class="fs-inline"><input type="radio" name="pwmode" value="remove"> Remove the password</label></div><div data-m="pwRow" hidden>' :
              '<label class="fs-inline"><input type="checkbox" data-m="usePw"> Protect with a password</label><div data-m="pwRow" hidden>') +
              '<div class="fs-link-box"><input type="text" data-m="pw" placeholder="At least 6 characters" autocomplete="off"><button type="button" class="fs-alt" data-m="gen">Generate</button></div><div class="fs-small">Send the password to them separately, for example by phone. It is not put in the email.</div></div>' +
            '<div class="fs-grid2"><div><label>Available from</label><input type="datetime-local" data-m="from" value="' + esc(editing ? existing.from_input : '') + '"></div><div><label>Available until</label><input type="datetime-local" data-m="to" value="' + esc(editing ? existing.to_input : '') + '"></div></div>' +
            '<div class="fs-small">UK time. Leave a box empty for no limit. Until: <a href="#" data-q="1">+1 day</a> &middot; <a href="#" data-q="7">+1 week</a> &middot; <a href="#" data-q="30">+1 month</a></div>' +
          '</div>' +
          '<div data-m="staffOpts" hidden><label class="fs-inline"><input type="checkbox" data-m="all"' + (editing && existing.all_staff ? ' checked' : '') + '> All staff</label><div class="fs-staff" data-m="staffList">' + staffHtml + '</div><div class="fs-small">They will see it under <strong>Shared with me</strong>. They can read and download, not change.</div></div>' +
          '<div class="fs-note fs-err" data-m="err" hidden></div><div class="fs-actions"><button type="button" class="fs-alt" data-m="no">Cancel</button><button type="button" data-m="ok">' + (editing ? 'Save changes' : 'Create share') + '</button></div>', function (d) {
          var m = d.m, err = function (t) { m('err').textContent = t; m('err').hidden = !t; };
          function setKind(k) {
            kind = k; m('linkOpts').hidden = k !== 'link'; m('staffOpts').hidden = k !== 'staff';
            if (!editing) { m('kLink').classList.toggle('fs-on', k === 'link'); m('kStaff').classList.toggle('fs-on', k === 'staff'); m('kHint').textContent = k === 'link' ? 'For customers and anyone outside the company. They need no account.' : 'Only the colleagues you choose, once they are signed in.'; }
          }
          setKind(kind);
          if (!editing) { m('kLink').onclick = function () { setKind('link'); }; m('kStaff').onclick = function () { setKind('staff'); }; }
          var up = m('usePw'); if (up) up.onchange = function () { m('pwRow').hidden = !up.checked; };
          Array.prototype.forEach.call(d.el.querySelectorAll('input[name=pwmode]'), function (r) { r.onchange = function () { m('pwRow').hidden = d.el.querySelector('input[name=pwmode]:checked').value !== 'change'; }; });
          m('gen').onclick = function () { m('pw').value = genPassword(); };
          Array.prototype.forEach.call(d.el.querySelectorAll('[data-q]'), function (a) { a.onclick = function (e) { e.preventDefault(); var t = new Date(); t.setDate(t.getDate() + (+a.dataset.q)); m('to').value = localIn(t); }; });
          m('no').onclick = d.close;
          m('ok').onclick = function () {
            err(''); var body = { title: m('title').value, message: m('message').value };
            if (kind === 'link') {
              body.from = m('from').value; body.to = m('to').value;
              if (editing) { var pm = d.el.querySelector('input[name=pwmode]:checked'); if (existing.has_password) { if (pm && pm.value === 'remove') body.clear_password = true; else if (pm && pm.value === 'change') body.password = m('pw').value; } else if (up && up.checked) body.password = m('pw').value; }
              else if (up && up.checked) { body.password = m('pw').value; if (!body.password) return err('Type a password, or press Generate.'); }
            } else {
              body.all_staff = m('all').checked; body.staff = Array.prototype.map.call(d.el.querySelectorAll('[data-staff]:checked'), function (x) { return +x.dataset.staff; });
              if (!editing && !body.all_staff && !body.staff.length) return err('Choose which colleagues to share with, or tick All staff.');
            }
            m('ok').disabled = true;
            var req = editing ? post(Object.assign({ action: 'share_update', id: existing.id }, body)) : post(Object.assign({ action: 'share_create', kind: kind, ids: nodes.map(function (n) { return n.id; }) }, body));
            req.then(function (r) {
              if (editing) { d.close(); toast('Saved'); if (view === 'shares') loadShares(); return; }
              load(); showCreated(d, r.share, body.password || '');
            }).catch(function (e) { m('ok').disabled = false; err(e.message); });
          };
        });
      }).catch(fail);
    }
    function showCreated(d, share, password) {
      var box = d.el;
      if (share.kind !== 'link') { box.innerHTML = '<h3>Shared</h3><div class="fs-note fs-ok">Shared with ' + (share.all_staff ? 'all staff' : esc(share.users.join(', '))) + '. They will see it under <strong>Shared with me</strong>.</div><div class="fs-actions"><button type="button" data-m="done">Done</button></div>'; box.querySelector('[data-m=done]').onclick = d.close; return; }
      box.innerHTML = '<h3>Your link is ready</h3><div class="fs-link-box"><input type="text" readonly data-m="url" value="' + esc(share.url) + '"><button type="button" data-m="copy">Copy link</button></div>' +
        (share.has_password ? '<div class="fs-note fs-warnbox"><strong>Password: <span data-m="pwv"></span></strong><br>This is the only time it is shown. Send it to them separately, for example by phone. It is not in the email.</div>' : '') +
        '<div class="fs-small">' + (share.available_from || share.available_to ? 'Available ' + (share.available_from ? 'from ' + fmtDate(share.available_from) + ' ' : '') + (share.available_to ? 'until ' + fmtDate(share.available_to) : '') + ' (UK time).' : 'No time limit.') + '</div>' +
        '<div class="fs-actions"><button type="button" class="fs-alt" data-m="mail">Send by email&hellip;</button><button type="button" data-m="done">Done</button></div>';
      var q = function (n) { return box.querySelector('[data-m=' + n + ']'); };
      if (share.has_password) q('pwv').textContent = password;
      q('copy').onclick = function () { copyText(share.url).then(function () { toast('Link copied'); }, function () { q('url').select(); toast('Press Ctrl+C to copy'); }); };
      q('mail').onclick = function () { d.close(); mailDialog(share); }; q('done').onclick = d.close; q('url').onfocus = function () { this.select(); };
    }
    function mailDialog(share) {
      modal('<h3>Send the link by email</h3><p class="fs-small">"' + esc(share.title) + '"' + (share.has_password ? ' &middot; password protected: send the password separately' : '') + '</p>' +
        '<label>To <span class="fs-small" style="font-weight:400">(up to 5 addresses, separated by commas)</span></label><textarea data-m="to" style="min-height:54px" placeholder="name@example.com"></textarea>' +
        '<label>Note <span class="fs-small" style="font-weight:400">(optional, added to the email)</span></label><textarea data-m="note" maxlength="1000"></textarea>' +
        '<div class="fs-note fs-err" data-m="err" hidden></div><div class="fs-actions"><button type="button" class="fs-alt" data-m="no">Cancel</button><button type="button" data-m="ok">Send email</button></div>', function (d) {
        var m = d.m; m('no').onclick = d.close;
        m('ok').onclick = function () {
          m('err').hidden = true; m('ok').disabled = true;
          post({ action: 'share_email', id: share.id, to: m('to').value, note: m('note').value }).then(function (r) { d.close(); toast('Email sent to ' + r.sent + (r.sent === 1 ? ' person' : ' people')); })
            .catch(function (e) { m('ok').disabled = false; m('err').textContent = e.message; m('err').hidden = false; });
        };
      });
    }
    function existingLink(then) {
      var ns = selNodes();
      if (ns.length !== 1) { toast(ns.length ? 'Choose one file or folder, or use Share to make one link for several.' : 'Choose a file or folder first.'); return; }
      get('links', { id: ns[0].id }).then(function (d) { d.shares.length ? then(d.shares[0], d.shares.length) : (toast('There is no link for this yet. Create one.'), shareDialog(ns)); }).catch(fail);
    }
    function copyLink() { existingLink(function (s, n) { copyText(s.url).then(function () { toast('Link copied' + (s.has_password ? ' (it has a password)' : '') + (n > 1 ? ' \u00b7 ' + n + ' links exist: see My shares' : '')); }, function () { toast('Your browser would not copy. Open My shares to copy it.'); }); }); }
    function emailLink() { existingLink(function (s) { mailDialog(s); }); }
    $('bShare').onclick = function () { shareDialog(selNodes()); }; $('bCopy').onclick = copyLink; $('bZip').onclick = zip; $('bDelete').onclick = del; $('bDownload').onclick = function () { downloadNodes(selNodes()); };

    /* ---------- my shares ---------- */
    function loadShares() {
      get('shares', { all: $('allShares').checked ? 1 : '' }).then(function (d) {
        var box = $('shareList');
        if (!d.shares.length) { box.innerHTML = '<div class="fs-empty">You have not shared anything yet.<br>Right-click a file or folder in <strong>My files</strong> and choose <strong>Share</strong>.</div>'; return; }
        box.innerHTML = '<table><thead><tr><th>Share</th><th>Type</th><th>Status</th><th>Available</th><th style="text-align:right">Downloads</th><th></th></tr></thead><tbody>' + d.shares.map(function (s) {
          var st = STATE[s.state] || ['', ''];
          return '<tr data-id="' + s.id + '"><td class="fs-name"><strong>' + esc(s.title) + '</strong><div class="fs-small">' + s.items.slice(0, 3).map(function (i) { return esc(i.name); }).join(', ') + (s.items.length > 3 ? ' +' + (s.items.length - 3) + ' more' : '') + (s.owner_name && $('allShares').checked ? ' \u00b7 by ' + esc(s.owner_name) : '') + '</div></td>' +
            '<td>' + (s.kind === 'link' ? '&#128279; Link' + (s.has_password ? ' &#128274;' : '') : '&#128101; ' + (s.all_staff ? 'All staff' : esc(s.users.join(', ')))) + '</td><td><span class="fs-badge ' + st[1] + '">' + st[0] + '</span></td>' +
            '<td class="fs-small">' + (s.available_from ? 'from ' + fmtDate(s.available_from) + '<br>' : '') + (s.available_to ? 'until ' + fmtDate(s.available_to) : (s.available_from ? '' : 'No limit')) + '</td><td class="fs-n">' + s.downloads + '</td>' +
            '<td class="fs-actions-cell">' + (s.kind === 'link' && s.state !== 'revoked' ? '<button type="button" data-a="copy">Copy link</button><button type="button" class="fs-alt" data-a="mail">Email</button>' : '') +
            (s.state !== 'revoked' ? '<button type="button" class="fs-alt" data-a="edit">Edit</button>' + (s.kind === 'link' ? '<button type="button" class="fs-alt" data-a="reset" title="Gives it a new address. Every link already sent stops working.">New link</button>' : '') + '<button type="button" class="fs-danger" data-a="revoke">Withdraw</button>' : '') + '</td></tr>';
        }).join('') + '</tbody></table>';
        box.onclick = function (e) {
          var b = e.target.closest('button[data-a]'); if (!b) return; var id = +b.closest('tr').dataset.id, s = d.shares.filter(function (x) { return x.id === id; })[0], a = b.dataset.a;
          if (a === 'copy') copyText(s.url).then(function () { toast('Link copied' + (s.has_password ? ' (it has a password)' : '')); }, function () { toast('Your browser would not copy.'); });
          else if (a === 'mail') mailDialog(s); else if (a === 'edit') shareDialog([], s);
          else if (a === 'reset') confirmBox('Make a new link?', 'The link you have already sent will stop working. You can copy the new one straight away.', 'Make new link').then(function (ok) { if (ok) post({ action: 'share_reset', id: id }).then(function () { toast('New link made'); loadShares(); }).catch(fail); });
          else if (a === 'revoke') confirmBox('Withdraw this share?', 'People will no longer be able to open it. Your files are not deleted.', 'Withdraw').then(function (ok) { if (ok) post({ action: 'share_revoke', id: id }).then(function () { toast('Withdrawn'); loadShares(); }).catch(fail); });
        };
      }).catch(fail);
    }
    $('allShares').onchange = loadShares; if (opts.isAdmin) $('allWrap').hidden = false;

    /* ---------- shared with me ---------- */
    function loadWith(shareId, parent) {
      if (!shareId) {
        withState = { share: null, parent: null }; $('withCrumbs').innerHTML = '';
        get('shared_with_me').then(function (d) {
          $('withList').innerHTML = d.shares.length ? '<table><thead><tr><th>Share</th><th>From</th><th>Available until</th></tr></thead><tbody>' + d.shares.map(function (s) {
            return '<tr class="fs-row" data-s="' + s.id + '" style="cursor:pointer"><td class="fs-name"><span class="fs-ico">&#128101;</span><strong>' + esc(s.title) + '</strong><div class="fs-small">' + s.items.slice(0, 3).map(function (i) { return esc(i.name); }).join(', ') + '</div></td><td>' + esc(s.owner_name || '') + '</td><td class="fs-small">' + (s.available_to ? fmtDate(s.available_to) : 'No limit') + '</td></tr>';
          }).join('') + '</tbody></table>' : '<div class="fs-empty">Nothing has been shared with you.</div>';
        }).catch(fail); return;
      }
      get('shared_view', { share: shareId, parent: parent || '' }).then(function (d) {
        withState = { share: shareId, parent: parent || null };
        $('withCrumbs').innerHTML = '<a data-w="0">Shared with me</a><span>/</span><a data-w="' + shareId + '">' + esc(d.share.title) + '</a>' + d.path.map(function (p) { return '<span>/</span><a data-w="' + shareId + ':' + p.id + '">' + esc(p.name) + '</a>'; }).join('');
        var msg = d.share.message ? '<div class="fs-note fs-ok" style="margin:8px">' + esc(d.share.message) + '</div>' : '';
        $('withList').innerHTML = msg + (d.items.length ? '<table><tbody>' + d.items.map(function (n) {
          return '<tr class="fs-row"><td class="fs-name"><span class="fs-ico">' + ico(n) + '</span>' + (n.kind === 'folder' ? '<a style="cursor:pointer;color:#1c2766;font-weight:600" data-open="' + n.id + '">' + esc(n.name) + '</a>' : esc(n.name)) + '</td><td class="fs-n">' + (n.kind === 'file' ? fmtSize(n.size) : '') + '</td><td class="fs-n"><a href="' + API + '?action=download&id=' + n.id + '&share=' + shareId + '"><button type="button">' + (n.kind === 'folder' ? 'Download zip' : 'Download') + '</button></a></td></tr>';
        }).join('') + '</tbody></table>' : '<div class="fs-empty">Nothing in here.</div>');
      }).catch(fail);
    }
    $('withList').addEventListener('click', function (e) {
      var r = e.target.closest('tr[data-s]'); if (r) return loadWith(+r.dataset.s);
      var o = e.target.closest('a[data-open]'); if (o) loadWith(withState.share, +o.dataset.open);
    });
    $('withCrumbs').addEventListener('click', function (e) { var a = e.target.closest('a[data-w]'); if (!a) return; var p = a.dataset.w.split(':'); p[0] === '0' ? loadWith(0) : loadWith(+p[0], p[1] ? +p[1] : null); });

    /* ---------- the three tabs ---------- */
    function setView(v) {
      view = v; ['Mine', 'With', 'Shares'].forEach(function (n) { $('view' + n).hidden = ('view' + n) !== 'view' + ({ mine: 'Mine', with: 'With', shares: 'Shares' }[v]); $('tab' + n).classList.toggle('fs-on', ('tab' + n) === 'tab' + ({ mine: 'Mine', with: 'With', shares: 'Shares' }[v])); });
      if (v === 'mine') load(); else if (v === 'with') loadWith(0); else loadShares();
    }
    $('tabMine').onclick = function () { setView('mine'); }; $('tabWith').onclick = function () { setView('with'); }; $('tabShares').onclick = function () { setView('shares'); };
    load(null);
    return { refresh: function () { setView(view); } };
  }

  global.FilesUI = { mount: mount };
})(window);
