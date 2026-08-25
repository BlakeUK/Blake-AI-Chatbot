/**
 * Blake UK Chat Widget
 * Embed: <script src="https://chat.blakegroup.uk/widget/chat.js" defer></script>
 */
(function () {
  'use strict';

  // window.BlakeUKWidget = { apiKey, endpoint } — set by external sites
  // embedding via the API key method (see README "External website" section).
  // First-party (blake-uk.com) embeds omit this and just get the defaults.
  const CONFIG   = window.BlakeUKWidget || {};
  const ENDPOINT = String(CONFIG.endpoint || 'https://chat.blakegroup.uk').replace(/\/$/, '');
  const API_KEY  = CONFIG.apiKey || null;

  const API = ENDPOINT + '/api/chat';
  const STORAGE_KEY = 'buk_session';

  // ── State ────────────────────────────────────────────────────────────────────
  let sessionId = sessionStorage.getItem(STORAGE_KEY) || null;
  let open = false;
  // Set once a ticket has actually been raised this session, so a later
  // low-confidence answer doesn't prompt for email all over again -
  // support already has a way to reach this customer.
  let ticketRaised = false;

  // ── Build DOM ────────────────────────────────────────────────────────────────
  const style = document.createElement('link');
  style.rel = 'stylesheet';
  style.href = ENDPOINT + '/widget/chat.css';
  document.head.appendChild(style);

  const btn = document.createElement('button');
  btn.id = 'buk-chat-btn';
  btn.setAttribute('aria-label', 'Open Blake UK chat');
  btn.innerHTML = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 12a8 8 0 1 1 3.2 6.4L4 20l1.1-3.5A7.96 7.96 0 0 1 4 12Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';

  const panel = document.createElement('div');
  panel.id = 'buk-chat-panel';
  panel.setAttribute('aria-live', 'polite');
  panel.innerHTML = `
    <div id="buk-chat-header">
      <div id="buk-chat-header-info">
        <div id="buk-chat-avatar" aria-hidden="true">UK</div>
        <div id="buk-chat-header-text">
          <div id="buk-chat-title">Blake AI Support</div>
          <div id="buk-chat-status"><span id="buk-status-dot" aria-hidden="true"></span>Online</div>
        </div>
      </div>
      <div id="buk-chat-header-actions">
        <button id="buk-chat-refresh" class="buk-icon-btn" type="button" aria-label="Start new conversation" title="Start new conversation">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 4v5h5M20 20v-5h-5M4.5 15a8 8 0 0 0 14.1 3.4M19.5 9A8 8 0 0 0 5.4 5.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <button id="buk-chat-close" class="buk-icon-btn" type="button" aria-label="Close chat">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
        </button>
      </div>
    </div>
    <div id="buk-chat-messages"></div>
    <div id="buk-chat-input-row">
      <input id="buk-chat-input" type="text" placeholder="Ask a question..." autocomplete="off" maxlength="500" />
      <button id="buk-chat-send" type="button" aria-label="Send message">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3.4 20.6 22 12 3.4 3.4 3 10l12 2-12 2 .4 6.6Z"/></svg>
      </button>
    </div>
  `;

  document.body.appendChild(btn);
  document.body.appendChild(panel);

  const messages = panel.querySelector('#buk-chat-messages');
  const input    = panel.querySelector('#buk-chat-input');
  const sendBtn  = panel.querySelector('#buk-chat-send');

  // ── Toggle ───────────────────────────────────────────────────────────────────
  btn.addEventListener('click', () => togglePanel(true));
  panel.querySelector('#buk-chat-close').addEventListener('click', () => togglePanel(false));
  panel.querySelector('#buk-chat-refresh').addEventListener('click', startNewConversation);

  function togglePanel(show) {
    open = show;
    panel.style.display = show ? 'flex' : 'none';
    btn.style.display   = show ? 'none' : 'flex';
    if (show && !sessionId) initSession();
    if (show) input.focus();
  }

  // Clears the visible thread and opens a fresh session — the old session
  // and its messages stay exactly as logged server-side (chat_sessions
  // rows are never deleted from here), this only affects what this browser
  // tab is currently looking at.
  function startNewConversation() {
    sessionId = null;
    ticketRaised = false;
    sessionStorage.removeItem(STORAGE_KEY);
    messages.innerHTML = '';
    initSession();
  }

  // ── Session ──────────────────────────────────────────────────────────────────
  // External embeds (API key configured) exchange the key for a short-lived,
  // single-use token before creating a session — session.php requires one for
  // any origin it doesn't already recognise as first-party.
  async function fetchWidgetToken() {
    const r = await fetch(ENDPOINT + '/api/widget/init.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ api_key: API_KEY }),
    });
    const d = await r.json();
    if (!r.ok || !d.token) throw new Error(d.error || 'Widget authentication failed');
    return d.token;
  }

  async function initSession() {
    const payload = {
      page_url:     window.location.href,
      product_code: document.querySelector('[data-product-code]')?.dataset.productCode || null,
      category:     document.querySelector('[data-category]')?.dataset.category || null,
    };

    try {
      if (API_KEY) {
        payload.token = await fetchWidgetToken();
      }
      const r = await fetch(API + '/session.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const d = await r.json();
      sessionId = d.session_id;
      sessionStorage.setItem(STORAGE_KEY, sessionId);
      addMessage('assistant', 'Hello! How can I help you today?');
      loadFaqSuggestions();
    } catch (e) {
      addMessage('assistant', 'Unable to connect. Please try again shortly.');
    }
  }

  // Quick-question chips under the greeting, built from the auto-generated
  // FAQ list (src/Faq/Builder.php). Best-effort: no FAQ entries yet, or the
  // request failing outright, just means no chips - never blocks the chat
  // itself from being usable.
  async function loadFaqSuggestions() {
    try {
      const r = await fetch(API + '/faq.php?limit=4');
      const items = await r.json();
      if (!Array.isArray(items) || !items.length) return;

      const wrap = document.createElement('div');
      wrap.className = 'buk-faq-suggestions';
      wrap.innerHTML = '<div class="buk-faq-label">Popular questions</div>' +
        items.map(f => `<button type="button" class="buk-faq-chip" data-q="${esc(f.question)}">${esc(f.question)}</button>`).join('');
      messages.appendChild(wrap);
      messages.scrollTop = messages.scrollHeight;

      wrap.querySelectorAll('.buk-faq-chip').forEach(chip => {
        chip.addEventListener('click', () => {
          wrap.remove();
          input.value = chip.dataset.q;
          sendMessage();
        });
      });
    } catch (e) {
      // Non-critical - chat works fine without suggestions.
    }
  }

  // ── Send ─────────────────────────────────────────────────────────────────────
  sendBtn.addEventListener('click', sendMessage);
  input.addEventListener('keydown', e => { if (e.key === 'Enter') sendMessage(); });

  async function sendMessage() {
    const text = input.value.trim();
    if (!text || !sessionId) return;
    input.value = '';
    document.querySelector('.buk-faq-suggestions')?.remove();
    addMessage('user', text);
    setLoading(true);

    try {
      const r = await fetch(API + '/send.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          session_id: sessionId,
          message: text,
          page_url: window.location.href,
          product_code: document.querySelector('[data-product-code]')?.dataset.productCode || null,
          category: document.querySelector('[data-category]')?.dataset.category || null,
        }),
      });
      const d = await r.json();
      if (d.error) {
        addMessage('assistant', 'Sorry, something went wrong. Please try again.');
      } else {
        addMessage('assistant', d.answer, d.products || []);
        if (d.action === 'show_tracking_form') {
          showTrackingForm(d.tracking_no, d.carrier);
        } else if (d.escalate && !ticketRaised && !messages.querySelector('.buk-escalate-form')) {
          addMessage('assistant', "I don't want to guess on this one, so I'm passing it to our support team. What's your email address? They'll reply there.");
          showEscalateForm();
        }
      }
    } catch (e) {
      addMessage('assistant', 'Unable to reach the server. Please check your connection.');
    } finally {
      setLoading(false);
    }
  }

  // ── Tracking ─────────────────────────────────────────────────────────────────
  function showTrackingForm(trackingNo, carrier) {
    const wrap = document.createElement('div');
    wrap.className = 'buk-msg buk-msg-assistant';
    wrap.innerHTML = assistantRowHtml(`
      <div class="buk-tracking-form">
        <input type="text" class="buk-track-no" placeholder="Order or tracking number" value="${trackingNo ? esc(trackingNo) : ''}">
        <input type="text" class="buk-track-postcode" placeholder="Delivery postcode">
        <button class="buk-track-submit" type="button">Track</button>
      </div>
    `);
    messages.appendChild(wrap);
    messages.scrollTop = messages.scrollHeight;
    wrap.querySelector('.buk-track-submit').addEventListener('click', () => submitTracking(wrap, carrier));
  }

  async function submitTracking(formWrap, carrier) {
    const trackingNo = formWrap.querySelector('.buk-track-no').value.trim();
    const postcode    = formWrap.querySelector('.buk-track-postcode').value.trim();
    if (!trackingNo || !postcode) return;

    const btn = formWrap.querySelector('.buk-track-submit');
    btn.disabled = true;
    btn.textContent = 'Checking...';

    try {
      const r = await fetch(API + '/track.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ session_id: sessionId, tracking_no: trackingNo, postcode, carrier: carrier || '' }),
      });
      const d = await r.json();
      formWrap.remove();

      if (d.status === 'found') {
        if (d.link_only) {
          addMessage('assistant', d.current);
        } else {
          const eventLines = (d.events || []).map(e => `• ${e.date || ''} ${e.description || ''}`.trim()).join('\n');
          addMessage('assistant', `${d.carrier} tracking ${d.tracking}: ${d.current}` + (eventLines ? '\n' + eventLines : ''));
        }
      } else {
        addMessage('assistant', d.message || 'Unable to retrieve tracking information.');
      }
    } catch (e) {
      formWrap.remove();
      addMessage('assistant', 'Unable to reach the tracking service. Please try again shortly.');
    }
  }

  // ── Escalation ───────────────────────────────────────────────────────────────
  function showEscalateForm() {
    const wrap = document.createElement('div');
    wrap.className = 'buk-msg buk-msg-assistant buk-escalate-form';
    wrap.innerHTML = assistantRowHtml(`
      <div class="buk-tracking-form">
        <input type="email" class="buk-escalate-email" placeholder="Your email address" required>
        <div class="buk-form-error" hidden></div>
        <button class="buk-track-submit buk-escalate-submit" type="button">Raise Ticket</button>
      </div>
    `);
    messages.appendChild(wrap);
    messages.scrollTop = messages.scrollHeight;

    const emailInput = wrap.querySelector('.buk-escalate-email');
    wrap.querySelector('.buk-escalate-submit').addEventListener('click', () => submitEscalate(wrap));
    emailInput.addEventListener('keydown', e => { if (e.key === 'Enter') submitEscalate(wrap); });
  }

  function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  async function submitEscalate(formWrap) {
    const emailInput = formWrap.querySelector('.buk-escalate-email');
    const errorEl     = formWrap.querySelector('.buk-form-error');
    const email       = emailInput.value.trim();

    // Required, not optional - support has no way to reply without it.
    // Checked client-side for instant feedback; escalate.php enforces the
    // same rule server-side regardless.
    if (!isValidEmail(email)) {
      errorEl.textContent = 'Please enter a valid email address so support can reply.';
      errorEl.hidden = false;
      emailInput.focus();
      return;
    }
    errorEl.hidden = true;

    const btn = formWrap.querySelector('.buk-escalate-submit');
    btn.disabled = true;
    btn.textContent = 'Raising...';

    try {
      const r = await fetch(API + '/escalate.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ session_id: sessionId, email }),
      });
      const d = await r.json();
      if (d.error) {
        errorEl.textContent = d.error;
        errorEl.hidden = false;
        btn.disabled = false;
        btn.textContent = 'Raise Ticket';
        return;
      }
      ticketRaised = true;
      formWrap.remove();
      addMessage('assistant', d.message || 'Your query has been passed to our support team.');
    } catch (e) {
      errorEl.textContent = 'Unable to reach the server. Please try again shortly.';
      errorEl.hidden = false;
      btn.disabled = false;
      btn.textContent = 'Raise Ticket';
    }
  }

  // ── DOM helpers ───────────────────────────────────────────────────────────────
  // Shared avatar+bubble row markup for every assistant-side message
  // (regular replies, the typing indicator, tracking/escalate forms) so the
  // "UK" avatar appears consistently rather than only on plain text replies.
  function assistantRowHtml(bubbleInnerHtml) {
    return `<div class="buk-msg-row">
      <div class="buk-avatar-sm" aria-hidden="true">UK</div>
      <div class="buk-bubble">${bubbleInnerHtml}</div>
    </div>`;
  }

  function formatTime(date) {
    return date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: true });
  }

  function addMessage(role, text, products) {
    const wrap = document.createElement('div');
    wrap.className = 'buk-msg buk-msg-' + role;

    const time = formatTime(new Date());
    const productsHtml = productsToHtml(products);

    if (role === 'assistant') {
      wrap.innerHTML = assistantRowHtml(linkify(esc(text))) + productsHtml + `<div class="buk-meta">${time}</div>`;
    } else {
      wrap.innerHTML = `<div class="buk-bubble">${esc(text)}</div>` + productsHtml
        + `<div class="buk-meta">${time}<span class="buk-tick" aria-hidden="true">✓</span></div>`;
    }

    messages.appendChild(wrap);
    messages.scrollTop = messages.scrollHeight;
  }

  // Turns a bare URL (e.g. the DX tracking link in a link_only tracking
  // reply) into a clickable link. Runs AFTER esc(), so an "&" already
  // reads as the escaped "&amp;" at this point - correct either way, since
  // that's exactly how it needs to appear inside the href attribute too.
  function linkify(escapedHtml) {
    return escapedHtml.replace(/https?:\/\/[^\s<]+/g, url => {
      const trail = url.match(/[.,;:!?)]+$/);
      const clean = trail ? url.slice(0, -trail[0].length) : url;
      const rest  = trail ? trail[0] : '';
      return `<a href="${clean}" target="_blank" rel="noopener">${clean}</a>${rest}`;
    });
  }

  // Product data is normally admin-curated, but if the product import
  // pipeline ever ingests an untrusted feed, a javascript: URL landing in
  // p.url and getting set as the card's href would execute in this page's
  // context. Cheap defence in depth: only ever link http(s) URLs - the
  // filter() below runs before esc() ever sees the value.
  function productsToHtml(products) {
    if (!products || !products.length) return '';
    return products.filter(p => isHttpUrl(p.url)).map(p => `
      <a class="buk-product-card" href="${esc(p.url)}" target="_blank" rel="noopener">
        ${p.image ? `<img src="${esc(p.image)}" alt="${esc(p.name)}" />` : ''}
        <div class="buk-product-info">
          <strong>${esc(p.name)}</strong>
          <span class="buk-product-code">${esc(p.code)}</span>
          ${p.price ? `<span class="buk-product-price">£${parseFloat(p.price).toFixed(2)} inc VAT</span>` : ''}
        </div>
      </a>
    `).join('');
  }

  function setLoading(on) {
    sendBtn.disabled = on;
    input.disabled   = on;
    if (on) {
      const el = document.createElement('div');
      el.id = 'buk-loading';
      el.className = 'buk-msg buk-msg-assistant';
      el.innerHTML = assistantRowHtml('<div class="buk-typing"><span></span><span></span><span></span></div>');
      messages.appendChild(el);
      messages.scrollTop = messages.scrollHeight;
    } else {
      document.getElementById('buk-loading')?.remove();
    }
  }

  function esc(str) {
    return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  // See productsToHtml() above for why this matters.
  function isHttpUrl(url) {
    return typeof url === 'string' && /^https?:\/\//i.test(url);
  }
})();
