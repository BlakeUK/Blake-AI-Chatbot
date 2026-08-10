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

  // ── Build DOM ────────────────────────────────────────────────────────────────
  const style = document.createElement('link');
  style.rel = 'stylesheet';
  style.href = ENDPOINT + '/widget/chat.css';
  document.head.appendChild(style);

  const btn = document.createElement('button');
  btn.id = 'buk-chat-btn';
  btn.setAttribute('aria-label', 'Open Blake UK chat');
  btn.innerHTML = '💬';

  const panel = document.createElement('div');
  panel.id = 'buk-chat-panel';
  panel.setAttribute('aria-live', 'polite');
  panel.innerHTML = `
    <div id="buk-chat-header">
      <span><img src="${ENDPOINT}/assets/blake-uk-logo.png" alt="Blake UK" id="buk-chat-logo">Support</span>
      <button id="buk-chat-close" aria-label="Close chat">✕</button>
    </div>
    <div id="buk-chat-messages"></div>
    <div id="buk-chat-input-row">
      <input id="buk-chat-input" type="text" placeholder="Ask a question..." autocomplete="off" maxlength="500" />
      <button id="buk-chat-send">Send</button>
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

  function togglePanel(show) {
    open = show;
    panel.style.display = show ? 'flex' : 'none';
    btn.style.display   = show ? 'none' : 'flex';
    if (show && !sessionId) initSession();
    if (show) input.focus();
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
    } catch (e) {
      addMessage('assistant', 'Unable to connect. Please try again shortly.');
    }
  }

  // ── Send ─────────────────────────────────────────────────────────────────────
  sendBtn.addEventListener('click', sendMessage);
  input.addEventListener('keydown', e => { if (e.key === 'Enter') sendMessage(); });

  async function sendMessage() {
    const text = input.value.trim();
    if (!text || !sessionId) return;
    input.value = '';
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
        } else if (d.escalate) {
          addMessage('assistant', 'Would you like me to raise a support ticket? A member of the team will get back to you.');
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
    wrap.innerHTML = `
      <div class="buk-bubble buk-tracking-form">
        <input type="text" class="buk-track-no" placeholder="Tracking number" value="${trackingNo ? esc(trackingNo) : ''}">
        <input type="text" class="buk-track-postcode" placeholder="Delivery postcode">
        <button class="buk-track-submit">Track</button>
      </div>
    `;
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
        const eventLines = (d.events || []).map(e => `• ${e.date || ''} ${e.description || ''}`.trim()).join('\n');
        addMessage('assistant', `${d.carrier} tracking ${d.tracking}: ${d.current}` + (eventLines ? '\n' + eventLines : ''));
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
    wrap.className = 'buk-msg buk-msg-assistant';
    wrap.innerHTML = `
      <div class="buk-bubble buk-tracking-form">
        <input type="email" class="buk-escalate-email" placeholder="Your email (optional)">
        <button class="buk-track-submit buk-escalate-submit">Raise Ticket</button>
      </div>
    `;
    messages.appendChild(wrap);
    messages.scrollTop = messages.scrollHeight;
    wrap.querySelector('.buk-escalate-submit').addEventListener('click', () => submitEscalate(wrap));
  }

  async function submitEscalate(formWrap) {
    const email = formWrap.querySelector('.buk-escalate-email').value.trim();
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
      formWrap.remove();
      addMessage('assistant', d.message || 'Your query has been passed to our support team.');
    } catch (e) {
      formWrap.remove();
      addMessage('assistant', 'Unable to reach the server. Please try again shortly.');
    }
  }

  // ── DOM helpers ───────────────────────────────────────────────────────────────
  function addMessage(role, text, products) {
    const wrap = document.createElement('div');
    wrap.className = 'buk-msg buk-msg-' + role;

    const bubble = document.createElement('div');
    bubble.className = 'buk-bubble';
    bubble.textContent = text;
    wrap.appendChild(bubble);

    if (products && products.length) {
      products.forEach(p => {
        if (!isHttpUrl(p.url)) return;
        const card = document.createElement('a');
        card.className = 'buk-product-card';
        card.href = p.url;
        card.target = '_blank';
        card.rel = 'noopener';
        card.innerHTML = `
          ${p.image ? `<img src="${esc(p.image)}" alt="${esc(p.name)}" />` : ''}
          <div class="buk-product-info">
            <strong>${esc(p.name)}</strong>
            <span class="buk-product-code">${esc(p.code)}</span>
            ${p.price ? `<span class="buk-product-price">£${parseFloat(p.price).toFixed(2)} inc VAT</span>` : ''}
          </div>
        `;
        wrap.appendChild(card);
      });
    }

    messages.appendChild(wrap);
    messages.scrollTop = messages.scrollHeight;
  }

  function setLoading(on) {
    sendBtn.disabled = on;
    input.disabled   = on;
    if (on) {
      const el = document.createElement('div');
      el.id = 'buk-loading';
      el.className = 'buk-msg buk-msg-assistant';
      el.innerHTML = '<div class="buk-bubble buk-typing"><span></span><span></span><span></span></div>';
      messages.appendChild(el);
      messages.scrollTop = messages.scrollHeight;
    } else {
      document.getElementById('buk-loading')?.remove();
    }
  }

  function esc(str) {
    return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  // Product data is normally admin-curated, but if the product import
  // pipeline ever ingests an untrusted feed, a javascript: URL landing in
  // p.url and getting set as card.href would execute in this page's
  // context. Cheap defence in depth: only ever link http(s) URLs.
  function isHttpUrl(url) {
    return typeof url === 'string' && /^https?:\/\//i.test(url);
  }
})();
