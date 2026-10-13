/* Team chat for the Blake UK console: one-to-one chats and groups, styled after Telegram Desktop.
   createStore(deps) holds the data and talks to the server; mountView(store, element, options) draws it. The Team page and the floating panel
   each mount a view on the same store, so they always agree. Everything from other people is put on the page as text, never as markup. */
(function () {
  'use strict';
  var API = '/api/admin/channels.php', NOTIFY = '/api/admin/channel_notify.php';
  var PALETTE = ['#e17076', '#7bc862', '#e5ca77', '#65aadd', '#a695e7', '#ee7aae', '#6ec9cb', '#faa774'];

  function colour(s) { var h = 7; s = String(s || ''); for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0; return PALETTE[h % PALETTE.length]; }
  function initials(n) { var p = String(n || '?').replace(/[^A-Za-z0-9 ._-]/g, ' ').split(/[ ._-]+/).filter(Boolean); return ((p[0] || '?')[0] + (p[1] ? p[1][0] : '')).toUpperCase(); }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function clock(ts) { var d = new Date(ts * 1000); return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
  function dayStart(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime(); }
  var DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  function listTime(ts, now) { var d = new Date(ts * 1000), n = new Date((now || Date.now())), diff = (dayStart(n) - dayStart(d)) / 864e5; if (diff <= 0) return clock(ts); if (diff === 1) return 'Yesterday'; if (diff < 7) return DAYS[d.getDay()]; return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + String(d.getFullYear()).slice(2); }
  function dayLabel(ts, now) { var d = new Date(ts * 1000), n = new Date((now || Date.now())), diff = (dayStart(n) - dayStart(d)) / 864e5; if (diff <= 0) return 'Today'; if (diff === 1) return 'Yesterday'; return d.getDate() + ' ' + MONTHS[d.getMonth()] + (d.getFullYear() !== n.getFullYear() ? ' ' + d.getFullYear() : ''); }

  // ---------------------------------------------------------------- the store
  function createStore(deps) {
    var S = { chats: [], activeId: null, active: null, filter: 'all', query: '', replyTo: null, drafts: {}, unread: 0, loaded: false, toast: '' };
    var subs = [], views = {}, msgMark = null, evMark = 0, busy = false, timer = null;
    function emit(what) { subs.forEach(function (f) { try { f(what); } catch (e) { if (window.console) console.error(e); } }); }
    function me() { return deps.me() || {}; }
    function send(method, path, body) { return deps.send(method, path, Object.assign({ csrf: deps.csrf() }, body || {})); }
    function fail(r, fallback) { if (!r || !r.ok) throw new Error((r && r.error) || fallback || 'That did not work. Please try again.'); return r; }
    function visible() { return Object.keys(views).some(function (k) { return views[k]; }); }
    function focused() { return typeof document.hasFocus === 'function' ? document.hasFocus() : true; }
    function staff() { return (deps.staff() || []).map(function (a) { return { id: a.id, username: a.username, role: a.role, status: a.effective_status || a.status || 'offline' }; }); }
    function statusOf(id) { var a = staff().filter(function (x) { return x.id === id; })[0]; return a ? a.status : 'offline'; }

    function refreshList() {
      return Promise.resolve(deps.get(API)).then(function (r) {
        if (!Array.isArray(r)) return;
        S.chats = r; S.loaded = true;
        S.unread = r.reduce(function (n, c) { return n + (c.muted ? 0 : c.unread_count); }, 0);
        if (S.activeId && !r.some(function (c) { return c.id === S.activeId; })) closeActive(true);
        if (deps.onUnread) deps.onUnread(S.unread);
        emit('list');
      }).catch(function () {});
    }
    function closeActive(gone) { S.activeId = null; S.active = null; S.replyTo = null; if (gone) toast('That chat is no longer available.'); emit('closed'); }
    function toast(t) { S.toast = t; emit('toast'); }
    function open(id) { S.activeId = id; S.active = null; S.replyTo = null; emit('open'); return loadActive(true); }
    function loadActive(full) {
      var id = S.activeId; if (!id) return Promise.resolve();
      var after = (!full && S.active && S.active.id === id) ? S.active.max_id : 0;
      return Promise.resolve(deps.get(API + '?id=' + id + (after ? '&after=' + after : ''))).then(function (d) {
        if (S.activeId !== id || !d) return;
        if (d.error) { if (/not in that chat|no longer exists/i.test(d.error)) return refreshList().then(function () { if (S.activeId === id) closeActive(true); }); return; }
        if (after && S.active) { var have = {}; S.active.messages.forEach(function (m) { have[m.id] = 1; }); d.messages = S.active.messages.concat(d.messages.filter(function (m) { return !have[m.id]; })).slice(-400); }
        S.active = d; emit('active'); return markRead();
      }).catch(function () {});
    }
    function markRead() {
      var id = S.activeId; if (!id || !visible() || !focused()) return Promise.resolve();
      var c = S.chats.filter(function (x) { return x.id === id; })[0], a = S.active;
      var mine = a && a.members.filter(function (m) { return m.id === me().id; })[0];
      var needs = (c && c.unread_count > 0) || (a && mine && a.max_id > mine.last_read_id && a.messages.some(function (m) { return m.id > mine.last_read_id && m.admin_id !== me().id && m.kind === 'message'; }));
      if (!needs) return Promise.resolve();
      if (c) c.unread_count = 0;
      return send('PUT', API, { id: id, mark_read: true }).then(function () { if (mine) mine.last_read_id = a.max_id; return refreshList(); }).catch(function () {});
    }
    function sendMessage(text) {
      var id = S.activeId, body = { channel_id: id, content: text }; if (S.replyTo) body.reply_to_id = S.replyTo.id;
      return send('POST', API, body).then(function (r) { fail(r, 'The message could not be sent.'); S.replyTo = null; S.drafts[id] = ''; return loadActive(false); }).then(function () { return refreshList(); });
    }
    function createGroup(o) { return send('POST', API, { name: o.name, description: o.description || '', member_ids: o.memberIds || [] }).then(function (r) { fail(r); return refreshList().then(function () { return open(r.id); }).then(function () { return r.id; }); }); }
    function openDM(adminId) { return send('POST', API, { dm_with: adminId }).then(function (r) { fail(r, 'Could not open that chat.'); return refreshList().then(function () { return open(r.id); }); }); }
    function change(body) { return send('PUT', API, Object.assign({ id: S.activeId }, body)).then(function (r) { fail(r); return Promise.all([loadActive(false), refreshList()]); }); }
    function addMembers(ids) { return change({ add_member_ids: ids }); }
    function removeMember(id) { return change({ remove_member_id: id }); }
    function rename(name) { return change({ rename: name }); }
    function describe(text) { return change({ description: text }); }
    function mute(on) { return change({ mute: !!on }); }
    function leave() { var id = S.activeId; return send('PUT', API, { id: id, leave: true }).then(function (r) { fail(r); closeActive(false); return refreshList(); }); }
    function deleteGroup() { var id = S.activeId; return send('DELETE', API, { id: id }).then(function (r) { fail(r); closeActive(false); return refreshList(); }); }

    function popup(title, body) { if (deps.notify) { try { deps.notify(title, body); } catch (e) {} } }
    function pollNotifications() {
      var first = msgMark === null;
      return Promise.resolve(deps.get(NOTIFY + (first ? '?baseline=1' : '?since=' + msgMark + '&events_since=' + evMark))).then(function (r) {
        if (!r || r.error) return;
        msgMark = r.max_id != null ? r.max_id : msgMark; evMark = r.event_max_id != null ? r.event_max_id : evMark;
        if (first) return;
        var watching = focused() && visible();
        (r.notifications || []).forEach(function (n) {
          if (watching && n.channel_id === S.activeId) return;
          var title = n.reason === 'dm' ? n.username : n.reason === 'mention' ? n.username + ' mentioned you in ' + n.channel_name : n.reason === 'reply' ? n.username + ' replied to you in ' + n.channel_name : n.username + ' in ' + n.channel_name;
          popup(title, n.content);
        });
        (r.events || []).forEach(function (e) {
          if (e.kind === 'added') popup('You were added to ' + e.channel_name, 'by ' + e.actor_name);
          else if (e.kind === 'removed') popup('You were removed from ' + e.channel_name, 'by ' + e.actor_name);
          else if (e.kind === 'deleted') popup(e.channel_name + ' was deleted', 'by ' + e.actor_name);
        });
      }).catch(function () {});
    }
    function tick() {
      if (busy) return Promise.resolve(); busy = true;
      return pollNotifications().then(refreshList).then(function () { return visible() ? loadActive(false) : null; }).then(function () { busy = false; }, function () { busy = false; });
    }
    function start(ms) { if (timer) return; tick(); timer = setInterval(tick, ms || 3000); }
    function stop() { clearInterval(timer); timer = null; }

    return { state: S, subscribe: function (f) { subs.push(f); return function () { subs = subs.filter(function (x) { return x !== f; }); }; }, emit: emit, setVisible: function (k, v) { views[k] = !!v; if (v) markRead(); },
      refreshList: refreshList, open: open, loadActive: loadActive, markRead: markRead, send: sendMessage, createGroup: createGroup, openDM: openDM, addMembers: addMembers, removeMember: removeMember,
      rename: rename, describe: describe, mute: mute, leave: leave, deleteGroup: deleteGroup, closeActive: function () { closeActive(false); }, pollNotifications: pollNotifications, tick: tick, start: start, stop: stop,
      staff: staff, statusOf: statusOf, me: me, toast: toast, setFilter: function (f) { S.filter = f; emit('list'); }, setQuery: function (q) { S.query = q; emit('list'); } };
  }

  // ---------------------------------------------------------------- the screen
  function h(tag, props) {
    var el = document.createElement(tag); props = props || {};
    Object.keys(props).forEach(function (k) {
      var v = props[k]; if (v == null || v === false) return;
      if (k === 'class') el.className = v; else if (k === 'text') el.textContent = v; else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
      else if (k === 'style') el.style.cssText = v; else el.setAttribute(k, v === true ? '' : v);
    });
    for (var i = 2; i < arguments.length; i++) { var c = arguments[i]; if (c == null || c === false) continue; el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); }
    return el;
  }
  var ICON = {
    pen: '<svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
    dots: '<svg viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="12" cy="19" r="1.2"/></svg>',
    back: '<svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>',
    close: '<svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    send: '<svg viewBox="0 0 24 24"><path d="M2.5 11.2L21 3l-3.2 18-5.6-5.2-3.1 3.2.4-5.2z"/></svg>',
    down: '<svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>'
  };
  function svg(name, cls) { var b = h('button', { class: cls || 'tc-iconbtn', type: 'button' }); b.innerHTML = ICON[name]; return b; }   // fixed icons only: never anything from a person
  function avatar(name, size, status) { var a = h('div', { class: 'tc-avatar' + (size ? ' ' + size : ''), style: 'background:' + colour(name) }, initials(name)); if (status) a.appendChild(h('span', { class: 'tc-dot ' + status })); return a; }

  function linkify(text, members, openExternal) {
    var frag = document.createDocumentFragment(), names = (members || []).map(function (m) { return m.username; }).sort(function (a, b) { return b.length - a.length; });
    var esc = names.map(function (n) { return n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); });
    var re = new RegExp('(https?:\\/\\/[^\\s<>"\']+)' + (esc.length ? '|(@(?:' + esc.join('|') + ')(?![A-Za-z0-9_]))' : ''), 'gi'), last = 0, m;
    while ((m = re.exec(text))) {
      if (m.index > last) frag.appendChild(document.createTextNode(text.slice(last, m.index)));
      if (m[1]) { var url = m[1].replace(/[.,;:!?)]+$/, ''); var a = h('a', { href: url, rel: 'noopener noreferrer', target: '_blank', text: url }); a.addEventListener('click', function (e) { e.preventDefault(); openExternal(this.href); }); frag.appendChild(a); re.lastIndex = m.index + url.length; last = re.lastIndex; continue; }
      frag.appendChild(h('span', { class: 'tc-mention', text: m[2] })); last = re.lastIndex;
    }
    if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
    return frag;
  }

  function mountView(store, root, opts) {
    opts = opts || {}; var S = store.state, key = opts.key || ('v' + Math.random()), isDock = opts.mode === 'dock';
    var openExternal = opts.openExternal || function (u) { window.open(u, '_blank', 'noopener'); };
    var searchEl = h('input', { class: 'tc-search', type: 'search', placeholder: 'Search', 'aria-label': 'Search chats', 'data-tc': 'search', autocomplete: 'off' });
    var newBtn = svg('pen'); newBtn.title = 'New chat or group'; newBtn.setAttribute('data-tc', 'new'); newBtn.setAttribute('aria-label', 'New chat or group');
    var closeBtn = isDock ? svg('close') : null; if (closeBtn) { closeBtn.title = 'Close'; closeBtn.setAttribute('data-tc', 'close'); closeBtn.addEventListener('click', function () { if (opts.onClose) opts.onClose(); }); }
    var filtersEl = h('div', { class: 'tc-filters' }), itemsEl = h('div', { class: 'tc-items', 'data-tc': 'items' });
    var listEl = h('div', { class: 'tc-list', 'data-tc': 'list' }, h('div', { class: 'tc-top' }, searchEl, newBtn, closeBtn), filtersEl, itemsEl);
    var backBtn = svg('back', 'tc-iconbtn tc-back'); backBtn.setAttribute('data-tc', 'back'); backBtn.setAttribute('aria-label', 'Back to chats');
    var headAvatar = h('div'), titleEl = h('div', { class: 'tc-title', 'data-tc': 'title' }), statusEl = h('div', { class: 'tc-status', 'data-tc': 'status' });
    var headInfo = h('div', { class: 'tc-headinfo', 'data-tc': 'head' }, titleEl, statusEl), menuBtn = svg('dots'); menuBtn.title = 'More'; menuBtn.setAttribute('data-tc', 'menu'); menuBtn.setAttribute('aria-label', 'Chat options');
    var headEl = h('div', { class: 'tc-head' }, backBtn, headAvatar, headInfo, menuBtn);
    var msgsEl = h('div', { class: 'tc-msgs', 'data-tc': 'msgs' }), downBtn = svg('down', 'tc-down'); downBtn.setAttribute('data-tc', 'down');
    var replyEl = h('div', { class: 'tc-reply', 'data-tc': 'reply' });
    var inputEl = h('textarea', { class: 'tc-input', rows: '1', placeholder: 'Write a message...', 'aria-label': 'Message', 'data-tc': 'input', maxlength: '4000' });
    var sendBtn = svg('send', 'tc-send'); sendBtn.title = 'Send'; sendBtn.setAttribute('data-tc', 'send'); sendBtn.setAttribute('aria-label', 'Send'); sendBtn.disabled = true;
    var composeEl = h('div', { class: 'tc-compose' }, inputEl, sendBtn), noteEl = h('div', { class: 'tc-note', style: 'display:none' });
    var infoEl = h('div', { class: 'tc-info', 'data-tc': 'info' });
    var convEl = h('div', { class: 'tc-conv', 'data-tc': 'conv' }, headEl, msgsEl, downBtn, replyEl, composeEl, noteEl, infoEl);
    var tc = h('div', { class: 'tc' }, listEl, convEl); root.innerHTML = ''; root.appendChild(tc);

    var layer = null, menuEl = null, sig = '', lastChat = null, newBelow = 0, toastTimer = null, infoOpen = false;
    function me() { return store.me(); }
    function chatById(id) { return S.chats.filter(function (c) { return c.id === id; })[0]; }
    function closeMenu() { if (menuEl) { menuEl.remove(); menuEl = null; } }
    function closeLayer() { if (layer) { layer.remove(); layer = null; } }
    function showToast(t) { var old = convEl.querySelector('.tc-toast'); if (old) old.remove(); if (!t) return; var el = h('div', { class: 'tc-toast', 'data-tc': 'toast', text: t }); (S.activeId ? convEl : listEl).appendChild(el); clearTimeout(toastTimer); toastTimer = setTimeout(function () { el.remove(); }, 3500); }

    // ---- narrow or wide
    function fit() { var narrow = root.clientWidth > 0 && root.clientWidth < 720; tc.classList.toggle('tc-narrow', narrow); tc.classList.toggle('tc-show-conv', narrow && !!S.activeId); }
    if (window.ResizeObserver) new ResizeObserver(fit).observe(root);

    // ---- the list
    function renderList() {
      var q = S.query.trim().toLowerCase(), chats = S.chats.filter(function (c) {
        if (S.filter === 'unread' && !c.unread_count) return false; if (S.filter === 'groups' && c.is_dm) return false; if (S.filter === 'direct' && !c.is_dm) return false;
        return !q || (c.display_name || '').toLowerCase().indexOf(q) >= 0 || (c.last_message && c.last_message.text.toLowerCase().indexOf(q) >= 0);
      });
      var unreadChats = S.chats.filter(function (c) { return c.unread_count > 0; }).length;
      filtersEl.innerHTML = '';
      [['all', 'All'], ['unread', 'Unread'], ['groups', 'Groups'], ['direct', 'Direct']].forEach(function (f) {
        var b = h('button', { class: 'tc-chip' + (S.filter === f[0] ? ' on' : ''), type: 'button', 'data-tc': 'filter-' + f[0] }, f[1], f[0] === 'unread' && unreadChats ? h('b', { text: String(unreadChats) }) : null);
        b.addEventListener('click', function () { store.setFilter(f[0]); }); filtersEl.appendChild(b);
      });
      itemsEl.innerHTML = '';
      if (!chats.length) { itemsEl.appendChild(h('div', { class: 'tc-empty', 'data-tc': 'empty' }, S.chats.length ? 'Nothing matches.' : (S.loaded ? 'No chats yet. Press the pencil to start a chat or make a group.' : 'Loading...'))); return; }
      chats.forEach(function (c) {
        var lm = c.last_message, sub;
        if (!lm) sub = h('span', { class: 'tc-sub' }, h('i', { text: 'No messages yet' }));
        else if (lm.kind === 'system') sub = h('span', { class: 'tc-sub' }, h('i', { text: lm.text }));
        else sub = h('span', { class: 'tc-sub' }, (lm.mine || !c.is_dm) ? h('span', { class: 'tc-prefix', text: (lm.mine ? 'You' : lm.user) + ': ' }) : null, lm.text.replace(/\s+/g, ' '));
        var el = h('div', { class: 'tc-item' + (c.id === S.activeId ? ' active' : ''), 'data-tc': 'item', 'data-id': String(c.id), 'data-name': c.display_name },
          avatar(c.display_name, '', c.is_dm ? store.statusOf(c.other_id) : null),
          h('div', { class: 'tc-body' },
            h('div', { class: 'tc-row' }, h('div', { class: 'tc-name', text: c.display_name || 'Chat' }), c.muted ? h('span', { class: 'tc-mute', title: 'Muted', text: '\uD83D\uDD07' }) : null, h('div', { class: 'tc-time', text: c.last_at ? listTime(c.last_at) : '' })),
            h('div', { class: 'tc-row' }, sub, c.unread_count ? h('span', { class: 'tc-badge' + (c.muted ? ' muted' : ''), 'data-tc': 'badge', text: String(c.unread_count) }) : null)));
        el.addEventListener('click', function () { infoOpen = false; store.open(c.id); });
        itemsEl.appendChild(el);
      });
    }

    // ---- the conversation
    function statusText(a) {
      if (a.is_dm) { var st = store.statusOf(a.other_id); return { text: a.other_id === me().id ? 'Notes to yourself' : (st === 'online' ? 'online' : st === 'busy' ? 'busy' : 'offline'), on: st === 'online' }; }
      var on = a.members.filter(function (m) { return store.statusOf(m.id) === 'online'; }).length;
      return { text: a.members.length + (a.members.length === 1 ? ' member' : ' members') + (on ? ', ' + on + ' online' : ''), on: false };
    }
    function renderHead() {
      var a = S.active, c = chatById(S.activeId), name = a ? a.display_name : (c ? c.display_name : '');
      headAvatar.replaceWith(headAvatar = avatar(name || '?', 'sm', a && a.is_dm ? store.statusOf(a.other_id) : null)); titleEl.textContent = name || '';
      var st = a ? statusText(a) : { text: '', on: false }; statusEl.textContent = st.text; statusEl.classList.toggle('on', st.on);
    }
    function tickFor(m, a) {
      var others = a.members.filter(function (x) { return x.id !== me().id; }), seen = others.filter(function (x) { return x.last_read_id >= m.id; }).length;
      var t = h('span', { class: 'tc-tick' + (others.length && seen === others.length ? ' seen' : ''), 'data-tc': 'tick', title: others.length ? (seen === others.length ? 'Seen' : seen ? 'Seen by ' + seen + ' of ' + others.length : 'Sent') : 'Sent' }, seen ? '\u2713\u2713' : '\u2713');
      return t;
    }
    function renderMsgs() {
      var a = S.active; if (!a) { msgsEl.innerHTML = ''; return; }
      var s = [a.id, a.max_id, a.messages.length, a.members.map(function (m) { return m.id + ':' + m.last_read_id; }).join(','), a.display_name].join('|'); if (s === sig) return; sig = s;
      var near = msgsEl.scrollHeight - msgsEl.scrollTop - msgsEl.clientHeight < 90, changed = lastChat !== a.id, prevLast = msgsEl.getAttribute('data-last') || '0';
      msgsEl.innerHTML = '';
      if (!a.messages.length) msgsEl.appendChild(h('div', { class: 'tc-blank' }, h('b', { text: 'No messages here yet' }), h('div', { text: 'Say hello.' })));
      var prev = null, lastDay = '';
      a.messages.forEach(function (m) {
        var d = new Date(m.created_at * 1000), dk = d.getFullYear() + '-' + d.getMonth() + '-' + d.getDate();
        if (dk !== lastDay) { msgsEl.appendChild(h('div', { class: 'tc-day', text: dayLabel(m.created_at) })); lastDay = dk; prev = null; }
        if (m.kind === 'system') { msgsEl.appendChild(h('div', { class: 'tc-sys', 'data-tc': 'system', text: m.content })); prev = null; return; }
        var mine = m.admin_id === me().id, first = !prev || prev.admin_id !== m.admin_id || m.created_at - prev.created_at > 300, lastOfRun = true;
        var bubble = h('div', { class: 'tc-bubble' });
        if (first && !mine && !a.is_dm) bubble.appendChild(h('div', { class: 'tc-who', style: 'color:' + colour(m.username), text: m.username }));
        if (m.reply_to) { var q = h('div', { class: 'tc-quote' }, h('b', { text: m.reply_to.username }), h('span', { text: m.reply_to.text })); q.addEventListener('click', function () { var t = msgsEl.querySelector('[data-id="' + m.reply_to.id + '"]'); if (t) { t.scrollIntoView({ block: 'center' }); t.classList.add('tc-flash'); setTimeout(function () { t.classList.remove('tc-flash'); }, 1300); } }); bubble.appendChild(q); }
        var text = h('span', { class: 'tc-text', 'data-tc': 'text' }); text.appendChild(linkify(m.content, a.members, openExternal)); bubble.appendChild(text);
        bubble.appendChild(h('span', { class: 'tc-meta' }, h('span', { text: clock(m.created_at) }), mine ? tickFor(m, a) : null));
        var rb = h('button', { class: 'tc-replybtn', type: 'button', title: 'Reply', 'aria-label': 'Reply', 'data-tc': 'reply-btn', text: '\u21A9' });
        rb.addEventListener('click', function () { S.replyTo = { id: m.id, username: m.username, text: m.content.slice(0, 120) }; renderReply(); inputEl.focus(); });
        var row = h('div', { class: 'tc-msg' + (mine ? ' mine' : '') + (first ? ' first' : ' mid'), 'data-tc': 'msg', 'data-id': String(m.id), 'data-mine': mine ? '1' : '0' }, mine ? rb : null, bubble, mine ? null : rb);
        msgsEl.appendChild(row); prev = m;
      });
      msgsEl.setAttribute('data-last', String(a.max_id));
      var fresh = a.messages.filter(function (m) { return m.id > Number(prevLast) && m.admin_id !== me().id && m.kind === 'message'; }).length;
      if (changed || near) { msgsEl.scrollTop = msgsEl.scrollHeight; newBelow = 0; } else if (fresh) newBelow += fresh;
      downBtn.classList.toggle('show', !near && !changed); downBtn.innerHTML = ICON.down + (newBelow ? '<b>' + newBelow + '</b>' : ''); lastChat = a.id;
    }
    function renderReply() {
      replyEl.innerHTML = ''; replyEl.classList.toggle('show', !!S.replyTo); if (!S.replyTo) return;
      var x = svg('close', 'tc-iconbtn'); x.setAttribute('aria-label', 'Cancel reply'); x.setAttribute('data-tc', 'reply-cancel'); x.addEventListener('click', function () { S.replyTo = null; renderReply(); });
      replyEl.appendChild(h('div', { class: 'tc-quote' }, h('b', { text: 'Reply to ' + S.replyTo.username }), h('span', { text: S.replyTo.text }))); replyEl.appendChild(x);
    }
    function renderConv() {
      var none = !S.activeId; headEl.style.display = none ? 'none' : ''; composeEl.style.display = none ? 'none' : ''; if (none) { replyEl.classList.remove('show'); noteEl.style.display = 'none'; infoEl.classList.remove('show'); }
      if (none) { msgsEl.innerHTML = ''; msgsEl.appendChild(h('div', { class: 'tc-blank', 'data-tc': 'blank' }, h('b', { text: 'Select a chat to start messaging' }), h('div', { text: 'Or press the pencil to start a chat or make a group.' }))); sig = ''; lastChat = null; downBtn.classList.remove('show'); return; }
      renderHead(); renderMsgs(); renderReply(); if (infoOpen) renderInfo();
    }

    // ---- dialogs
    function dialog(title, build) {
      closeLayer(); closeMenu(); var box = h('div', { class: 'tc-dialog', role: 'dialog', 'aria-label': title, 'data-tc': 'dialog' }, h('h3', { text: title }));
      layer = h('div', { class: 'tc-overlay' }, box); layer.addEventListener('mousedown', function (e) { if (e.target === layer) closeLayer(); }); tc.appendChild(layer); build(box); return box;
    }
    function actions(box, buttons) { var row = h('div', { class: 'tc-actions' }); buttons.forEach(function (b) { row.appendChild(b); }); box.appendChild(row); }
    function btn(text, cls, fn, tcname) { var b = h('button', { class: 'tc-btn ' + (cls || ''), type: 'button', 'data-tc': tcname || null, text: text }); b.addEventListener('click', fn); return b; }
    function confirmBox(title, text, okText, danger, onOk) {
      dialog(title, function (box) {
        var err = h('div', { class: 'tc-err', 'data-tc': 'dialog-error' }), ok = btn(okText, danger ? 'danger' : 'primary', function () { ok.disabled = true; Promise.resolve(onOk()).then(closeLayer, function (e) { err.textContent = e.message; ok.disabled = false; }); }, 'confirm');
        box.appendChild(h('p', { text: text })); box.appendChild(err); actions(box, [btn('Cancel', '', closeLayer, 'cancel'), ok]);
      });
    }
    function pickList(people, preset) {
      var chosen = {}, wrap = h('div', { class: 'tc-pick', 'data-tc': 'pick' }), q = h('input', { type: 'search', placeholder: 'Search people', 'data-tc': 'pick-search', autocomplete: 'off' });
      function draw() {
        var t = q.value.trim().toLowerCase(); wrap.innerHTML = '';
        people.filter(function (p) { return !t || p.username.toLowerCase().indexOf(t) >= 0; }).forEach(function (p) {
          var cb = h('input', { type: 'checkbox', 'data-tc': 'pick-box', 'data-name': p.username }); cb.checked = !!chosen[p.id]; cb.addEventListener('change', function () { if (cb.checked) chosen[p.id] = 1; else delete chosen[p.id]; if (preset) preset(); });
          var row = h('label', { class: 'tc-pickrow', 'data-tc': 'pick-row' }, cb, avatar(p.username, 'sm', p.status), h('div', { class: 'tc-body' }, h('div', { class: 'tc-name', text: p.username }), h('div', { class: 'tc-sub', text: p.role + ' · ' + p.status })));
          wrap.appendChild(row);
        });
        if (!wrap.children.length) wrap.appendChild(h('div', { class: 'tc-empty', text: 'Nobody found.' }));
      }
      q.addEventListener('input', draw); draw();
      return { search: h('div', { class: 'tc-field' }, q), list: wrap, ids: function () { return Object.keys(chosen).map(Number); } };
    }
    function newGroupDialog() {
      dialog('New group', function (box) {
        var name = h('input', { type: 'text', maxlength: '60', placeholder: 'Group name', 'data-tc': 'group-name' }), desc = h('textarea', { rows: '2', maxlength: '300', placeholder: 'What is it for? (optional)', 'data-tc': 'group-desc' });
        var err = h('div', { class: 'tc-err', 'data-tc': 'dialog-error' }), create;
        var pick = pickList(store.staff().filter(function (s) { return s.id !== me().id; }), function () { create.textContent = 'Create' + (pick.ids().length ? ' (' + (pick.ids().length + 1) + ' people)' : ''); });
        create = btn('Create', 'primary', function () {
          if (!name.value.trim()) { err.textContent = 'Give the group a name.'; name.focus(); return; }
          create.disabled = true; err.textContent = '';
          store.createGroup({ name: name.value, description: desc.value, memberIds: pick.ids() }).then(closeLayer, function (e) { err.textContent = e.message; create.disabled = false; });
        }, 'create-group');
        box.appendChild(h('div', { class: 'tc-field' }, h('label', { text: 'Name' }), name)); box.appendChild(h('div', { class: 'tc-field' }, h('label', { text: 'Description' }), desc));
        box.appendChild(h('div', { class: 'tc-field' }, h('label', { text: 'Members' }))); box.appendChild(pick.search); box.appendChild(pick.list); box.appendChild(err);
        actions(box, [btn('Cancel', '', closeLayer, 'cancel'), create]); name.focus();
      });
    }
    function addMembersDialog() {
      var a = S.active; if (!a) return; var have = {}; a.members.forEach(function (m) { have[m.id] = 1; });
      var people = store.staff().filter(function (s) { return !have[s.id]; });
      dialog('Add members', function (box) {
        var err = h('div', { class: 'tc-err', 'data-tc': 'dialog-error' }), go;
        if (!people.length) { box.appendChild(h('p', { text: 'Everyone is already in this group.' })); actions(box, [btn('Close', '', closeLayer, 'cancel')]); return; }
        var pick = pickList(people, function () { go.disabled = !pick.ids().length; });
        go = btn('Add', 'primary', function () { go.disabled = true; store.addMembers(pick.ids()).then(closeLayer, function (e) { err.textContent = e.message; go.disabled = false; }); }, 'add-members'); go.disabled = true;
        box.appendChild(pick.search); box.appendChild(pick.list); box.appendChild(err); actions(box, [btn('Cancel', '', closeLayer, 'cancel'), go]);
      });
    }
    function newChatDialog() {
      dialog('New chat', function (box) {
        var people = store.staff().filter(function (s) { return s.id !== me().id; }), q = h('input', { type: 'search', placeholder: 'Search people', 'data-tc': 'chat-search', autocomplete: 'off' }), wrap = h('div', { class: 'tc-pick', 'data-tc': 'pick' });
        function draw() { var t = q.value.trim().toLowerCase(); wrap.innerHTML = ''; people.filter(function (p) { return !t || p.username.toLowerCase().indexOf(t) >= 0; }).forEach(function (p) {
          var row = h('div', { class: 'tc-pickrow', 'data-tc': 'chat-row', 'data-name': p.username }, avatar(p.username, 'sm', p.status), h('div', { class: 'tc-body' }, h('div', { class: 'tc-name', text: p.username }), h('div', { class: 'tc-sub', text: p.role + ' · ' + p.status })));
          row.addEventListener('click', function () { infoOpen = false; store.openDM(p.id).then(closeLayer, function (e) { showToast(e.message); closeLayer(); }); }); wrap.appendChild(row); });
          if (!wrap.children.length) wrap.appendChild(h('div', { class: 'tc-empty', text: 'Nobody found.' })); }
        q.addEventListener('input', draw); draw(); box.appendChild(h('div', { class: 'tc-field' }, q)); box.appendChild(wrap);
        actions(box, [btn('Cancel', '', closeLayer, 'cancel')]); q.focus();
      });
    }

    // ---- menus and the info panel
    function popMenu(anchor, items) {
      closeMenu(); var m = h('div', { class: 'tc-menu', role: 'menu', 'data-tc': 'menu-list' });
      items.forEach(function (it) { if (it === '-') { m.appendChild(h('hr')); return; } var b = h('button', { type: 'button', role: 'menuitem', class: it.danger ? 'danger' : '', 'data-tc': it.id, text: it.label }); b.addEventListener('click', function () { closeMenu(); it.run(); }); m.appendChild(b); });
      var host = anchor.closest('.tc-list') ? listEl : convEl; host.appendChild(m); var r = anchor.getBoundingClientRect(), hr = host.getBoundingClientRect();
      m.style.top = (r.bottom - hr.top + 4) + 'px'; m.style.left = Math.max(6, Math.min(r.right - hr.left - 210, hr.width - 220)) + 'px'; menuEl = m;
      setTimeout(function () { document.addEventListener('mousedown', function off(e) { if (!m.contains(e.target)) { closeMenu(); document.removeEventListener('mousedown', off); } }); }, 0);
    }
    function chatActions() {
      var a = S.active; if (!a) return []; var it = [{ id: 'm-info', label: a.is_dm ? 'Contact info' : 'Group info', run: function () { infoOpen = true; renderInfo(); } }, { id: 'm-mute', label: a.muted ? 'Unmute notifications' : 'Mute notifications', run: function () { store.mute(!a.muted).catch(function (e) { showToast(e.message); }); } }];
      if (!a.is_dm && a.can_manage) it.push({ id: 'm-add', label: 'Add members', run: addMembersDialog });
      if (!a.is_dm) { it.push('-'); it.push({ id: 'm-leave', label: 'Leave group', danger: true, run: leaveDialog }); if (a.can_manage) it.push({ id: 'm-delete', label: 'Delete group', danger: true, run: deleteDialog }); }
      return it;
    }
    function leaveDialog() { var a = S.active; confirmBox('Leave group?', 'You will stop getting messages from ' + a.display_name + '.' + (a.owner_id === me().id && a.members.length > 1 ? ' The group will pass to the person who joined first.' : '') + (a.members.length === 1 ? ' You are the last person, so the group will be deleted.' : ''), 'Leave', true, function () { infoOpen = false; return store.leave(); }); }
    function deleteDialog() { var a = S.active; confirmBox('Delete group?', 'This deletes ' + a.display_name + ' and all of its messages for everyone. It cannot be undone.', 'Delete group', true, function () { infoOpen = false; return store.deleteGroup(); }); }
    function renderInfo() {
      var a = S.active; infoEl.classList.toggle('show', infoOpen && !!a); if (!infoOpen || !a) return; infoEl.innerHTML = '';
      var close = svg('close'); close.setAttribute('data-tc', 'info-close'); close.setAttribute('aria-label', 'Close'); close.addEventListener('click', function () { infoOpen = false; renderInfo(); });
      infoEl.appendChild(h('div', { class: 'tc-infohead' }, h('span', { text: a.is_dm ? 'Contact info' : 'Group info' }), close));
      var sc = h('div', { class: 'tc-infoscroll' }); infoEl.appendChild(sc);
      var st = statusText(a); sc.appendChild(h('div', { class: 'tc-hero' }, avatar(a.display_name, 'lg'), h('div', { class: 'tc-title', 'data-tc': 'info-title', text: a.display_name }), h('div', { class: 'tc-status' + (st.on ? ' on' : ''), text: st.text })));
      if (!a.is_dm && a.description) sc.appendChild(h('div', { class: 'tc-desc', 'data-tc': 'info-desc', text: a.description }));
      if (!a.is_dm && a.can_manage) {
        var nameIn = h('input', { type: 'text', maxlength: '60', value: a.name, 'data-tc': 'edit-name', 'aria-label': 'Group name' }), saveName = btn('Save', 'primary', function () { saveName.disabled = true; store.rename(nameIn.value).catch(function (e) { showToast(e.message); }).then(function () { saveName.disabled = false; }); }, 'save-name');
        var descIn = h('textarea', { rows: '2', maxlength: '300', 'data-tc': 'edit-desc', 'aria-label': 'Group description', placeholder: 'Description' }); descIn.value = a.description || '';
        var saveDesc = btn('Save', 'primary', function () { saveDesc.disabled = true; store.describe(descIn.value).catch(function (e) { showToast(e.message); }).then(function () { saveDesc.disabled = false; }); }, 'save-desc');
        sc.appendChild(h('div', { class: 'tc-edit' }, nameIn, saveName)); sc.appendChild(h('div', { class: 'tc-edit' }, descIn, saveDesc));
      }
      var sw = h('button', { class: 'tc-switch' + (a.muted ? '' : ' on'), type: 'button', role: 'switch', 'aria-checked': a.muted ? 'false' : 'true', 'aria-label': 'Notifications', 'data-tc': 'switch-notify' }); sw.addEventListener('click', function () { store.mute(!a.muted).catch(function (e) { showToast(e.message); }); });
      sc.appendChild(h('div', { class: 'tc-line' }, h('span', { text: 'Notifications' }), sw));
      if (!a.is_dm) {
        sc.appendChild(h('div', { class: 'tc-section', text: a.members.length + (a.members.length === 1 ? ' member' : ' members') }));
        if (a.can_manage) { var add = h('button', { class: 'tc-linkbtn', type: 'button', 'data-tc': 'info-add', text: '+ Add members' }); add.addEventListener('click', addMembersDialog); sc.appendChild(add); }
        a.members.forEach(function (m) {
          var row = h('div', { class: 'tc-member', 'data-tc': 'member', 'data-name': m.username }, avatar(m.username, 'sm', store.statusOf(m.id)), h('div', { class: 'tc-body' }, h('div', { class: 'tc-name' }, m.username, m.is_owner ? h('span', { class: 'tc-tag', text: 'owner' }) : null, m.id === me().id ? h('span', { class: 'tc-tag', text: 'you' }) : null), h('div', { class: 'tc-sub', text: store.statusOf(m.id) })));
          if (a.can_manage && !m.is_owner && m.id !== me().id) { var x = h('button', { class: 'tc-x', type: 'button', title: 'Remove ' + m.username, 'aria-label': 'Remove ' + m.username, 'data-tc': 'remove-member', text: '\u2715' }); x.addEventListener('click', function () { confirmBox('Remove ' + m.username + '?', m.username + ' will no longer see this group, and will be told they were removed.', 'Remove', true, function () { return store.removeMember(m.id); }); }); row.appendChild(x); }
          sc.appendChild(row);
        });
        var lv = h('button', { class: 'tc-linkbtn danger', type: 'button', 'data-tc': 'info-leave', text: 'Leave group' }); lv.addEventListener('click', leaveDialog); sc.appendChild(lv);
        if (a.can_manage) { var del = h('button', { class: 'tc-linkbtn danger', type: 'button', 'data-tc': 'info-delete', text: 'Delete group' }); del.addEventListener('click', deleteDialog); sc.appendChild(del); }
      }
    }

    // ---- the composer
    function grow() { inputEl.style.height = 'auto'; inputEl.style.height = Math.min(inputEl.scrollHeight, 140) + 'px'; sendBtn.disabled = !inputEl.value.trim(); }
    function doSend() {
      var text = inputEl.value.trim(); if (!text || !S.activeId) return; sendBtn.disabled = true; var id = S.activeId;
      store.send(text).then(function () { if (S.activeId === id) { inputEl.value = ''; grow(); msgsEl.scrollTop = msgsEl.scrollHeight; } }, function (e) { showToast(e.message); grow(); });
    }
    inputEl.addEventListener('input', function () { grow(); if (S.activeId) S.drafts[S.activeId] = inputEl.value; });
    inputEl.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); doSend(); } });
    sendBtn.addEventListener('click', doSend);
    searchEl.addEventListener('input', function () { store.setQuery(searchEl.value); });
    newBtn.addEventListener('click', function () { popMenu(newBtn, [{ id: 'n-group', label: 'New group', run: newGroupDialog }, { id: 'n-chat', label: 'New chat', run: newChatDialog }]); });
    menuBtn.addEventListener('click', function () { popMenu(menuBtn, chatActions()); });
    headInfo.addEventListener('click', function () { infoOpen = true; renderInfo(); });
    backBtn.addEventListener('click', function () { store.closeActive(); });
    downBtn.addEventListener('click', function () { msgsEl.scrollTop = msgsEl.scrollHeight; newBelow = 0; downBtn.classList.remove('show'); });
    msgsEl.addEventListener('scroll', function () { if (msgsEl.scrollHeight - msgsEl.scrollTop - msgsEl.clientHeight < 90) { newBelow = 0; downBtn.classList.remove('show'); } });
    tc.addEventListener('keydown', function (e) { if (e.key === 'Escape') { if (layer) closeLayer(); else if (menuEl) closeMenu(); else if (infoOpen) { infoOpen = false; renderInfo(); } } });
    window.addEventListener('focus', function () { store.markRead(); });

    var lastActive = null;
    var off = store.subscribe(function (what) {
      if (what === 'open' || what === 'closed') { sig = ''; infoOpen = false; closeLayer(); closeMenu(); }
      if (what === 'toast') { showToast(S.toast); return; }
      if (what === 'open' || (what === 'active' && lastActive !== S.activeId)) { inputEl.value = (S.activeId && S.drafts[S.activeId]) || ''; grow(); if (S.activeId) setTimeout(function () { if (!tc.classList.contains('tc-narrow')) inputEl.focus(); }, 0); }
      lastActive = S.activeId; renderList(); renderConv(); fit();
      var a = S.active; var notMember = a && !a.members.some(function (m) { return m.id === me().id; }); noteEl.style.display = notMember ? '' : 'none'; composeEl.style.display = (!S.activeId || notMember) ? 'none' : '';
    });
    renderList(); renderConv(); fit(); store.refreshList();
    return { destroy: function () { off(); root.innerHTML = ''; }, setVisible: function (v) { store.setVisible(key, v); if (v) { fit(); renderList(); } }, focusSearch: function () { searchEl.focus(); }, el: tc };
  }

  window.TeamChat = { createStore: createStore, mountView: mountView, fmt: { listTime: listTime, dayLabel: dayLabel, clock: clock, initials: initials, colour: colour } };
})();
