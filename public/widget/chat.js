/**
 * Blake UK Chat Widget
 * Embed: <script src="https://chat.blake-uk.com/widget/chat.js" defer></script>
 */
(function () {
  'use strict';

  const API = 'https://chat.blake-uk.com/api/chat';
  const STORAGE_KEY = 'buk_session';

  // ── State ────────────────────────────────────────────────────────────────────
  let sessionId = sessionStorage.getItem(STORAGE_KEY) || null;
  let open = false;

  // ── Build DOM ────────────────────────────────────────────────────────────────
  const style = document.createElement('link');
  style.rel = 'stylesheet';
  style.href = 'https://chat.blake-uk.com/widget/chat.css';
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
      <span><img src="/assets/blake-uk-logo.png" alt="Blake UK" id="buk-chat-logo">Support</span>
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
  async function initSession() {
    const payload = {
      page_url:     window.location.href,
      product_code: document.querySelector('[data-product-code]')?.dataset.productCode || null,
      category:     document.querySelector('[data-category]')?.dataset.category || null,
    };

    try {
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
        body: JSON.stringify({ session_id: sessionId, message: text }),
      });
      const d = await r.json();
      if (d.error) {
        addMessage('assistant', 'Sorry, something went wrong. Please try again.');
      } else {
        addMessage('assistant', d.answer, d.products || []);
        if (d.escalate) {
          addMessage('assistant', 'Would you like me to raise a support ticket? A member of the team will get back to you.');
        }
      }
    } catch (e) {
      addMessage('assistant', 'Unable to reach the server. Please check your connection.');
    } finally {
      setLoading(false);
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
})();
