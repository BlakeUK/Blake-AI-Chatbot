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
  // active: currently requested or claimed - while true, typed messages
  // go to live_send.php instead of send.php (see sendMessage()).
  // pollTimer: the live_poll.php interval handle, running only while active.
  // lastMessageId: high-water mark so polling only ever asks for what's new.
  const liveChatState = { active: false, pollTimer: null, lastMessageId: 0 };

  // ── Build DOM ────────────────────────────────────────────────────────────────
  const style = document.createElement('link');
  style.rel = 'stylesheet';
  style.href = ENDPOINT + '/widget/chat.css';
  document.head.appendChild(style);

  // Animated robot mascot launcher (inline SVG, no external assets).
  // All ids are buk-prefixed so they cannot collide with the host page.
  const ROBOT_SVG = `
<svg class="buk-bot" viewBox="0 0 170 200" width="136" height="160" aria-hidden="true" focusable="false">
  <defs>
    <linearGradient id="buk-bot-shell" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="#d9dfeb"/>
    </linearGradient>
    <linearGradient id="buk-bot-fade" gradientUnits="userSpaceOnUse" x1="0" y1="0" x2="0" y2="200">
      <stop offset=".82" stop-color="#fff"/><stop offset="1" stop-color="#000"/>
    </linearGradient>
    <mask id="buk-bot-mask" maskUnits="userSpaceOnUse" x="-20" y="-20" width="210" height="220">
      <rect x="-20" y="-20" width="210" height="220" fill="url(#buk-bot-fade)"/>
    </mask>
    <filter id="buk-bot-glow" x="-50%" y="-50%" width="200%" height="200%">
      <feGaussianBlur stdDeviation="1.6" result="b"/>
      <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
    </filter>
  </defs>
  <g class="buk-bot-body" mask="url(#buk-bot-mask)">
    <!-- left arm (hanging) -->
    <path d="M36 140 30 198" stroke="url(#buk-bot-shell)" stroke-width="14" stroke-linecap="round"/>
    <circle cx="32" cy="172" r="5.5" fill="#2a2f3a" stroke="#3d63ff" stroke-width="1.5" filter="url(#buk-bot-glow)"/>
    <!-- right arm (waving) -->
    <g class="buk-bot-arm">
      <path d="M128 138 141 164" stroke="#e4e9f2" stroke-width="13" stroke-linecap="round"/>
      <g class="buk-bot-forearm">
        <path d="M141 164 151 124" stroke="#eef1f7" stroke-width="11" stroke-linecap="round"/>
        <ellipse cx="151.5" cy="120" rx="6.5" ry="2.6" fill="#2a2f3a" stroke="#56a8ff" stroke-width="1.4" filter="url(#buk-bot-glow)"/>
        <g fill="#fff" stroke="#2a2f3a" stroke-width="1.3">
          <rect x="142.5" y="92" width="4.4" height="14" rx="2.2"/>
          <rect x="147.6" y="89" width="4.4" height="16" rx="2.2"/>
          <rect x="152.7" y="90" width="4.4" height="15" rx="2.2"/>
          <rect x="157.6" y="94" width="4.2" height="12" rx="2.1"/>
          <rect x="136" y="103" width="4.2" height="11" rx="2.1" transform="rotate(-40 138 108)"/>
          <path d="M141 104h20v6c0 6-4 9-10 9s-10-3-10-9Z"/>
        </g>
      </g>
      <circle cx="141" cy="164" r="5.5" fill="#2a2f3a" stroke="#3d63ff" stroke-width="1.5" filter="url(#buk-bot-glow)"/>
    </g>
    <!-- torso -->
    <path d="M46 120c0-8 12-12 39-12s39 4 39 12l-5 50c-2 16-14 30-34 30s-32-14-34-30Z" fill="url(#buk-bot-shell)" stroke="#c9d1e0"/>
    <g filter="url(#buk-bot-glow)" fill="none" stroke-linecap="round">
      <path d="M53 124c3 26 6 46 13 66M117 124c-3 26-6 46-13 66" stroke="#3d63ff" stroke-width="2"/>
      <path d="M62 164q23 7 46 0" stroke="#f5a623" stroke-width="1.4" opacity=".9"/>
    </g>
    <rect x="57" y="126" width="56" height="26" rx="7" fill="#fff" stroke="#c9d1e0"/>
    <text x="78" y="143.5" text-anchor="middle" font-family="Arial,sans-serif" font-weight="700" font-size="11" fill="#2f4fd6" letter-spacing="-.4">blake</text>
    <circle cx="100" cy="139.5" r="7.4" fill="#2f4fd6"/>
    <text x="100" y="142.6" text-anchor="middle" font-family="Arial,sans-serif" font-weight="700" font-size="7.6" fill="#fff">UK</text>
    <g fill="#2a2f3a"><rect x="72" y="172" width="26" height="5" rx="2.5"/><rect x="74" y="180" width="22" height="5" rx="2.5"/><rect x="76" y="188" width="18" height="5" rx="2.5"/></g>
    <!-- neck -->
    <g fill="#2a2f3a"><rect x="72" y="92" width="26" height="20" rx="4"/></g>
    <g fill="#454c5c"><rect x="68" y="97" width="34" height="4" rx="2"/><rect x="68" y="104" width="34" height="4" rx="2"/></g>
    <!-- shoulders -->
    <ellipse cx="42" cy="126" rx="18" ry="16" fill="url(#buk-bot-shell)" stroke="#c9d1e0"/>
    <circle cx="40" cy="128" r="9" fill="#2f4fd6" stroke="#fff" stroke-width="1.5"/>
    <text x="40" y="131" text-anchor="middle" font-family="Arial,sans-serif" font-weight="700" font-size="8" fill="#fff">UK</text>
    <ellipse cx="128" cy="126" rx="18" ry="16" fill="url(#buk-bot-shell)" stroke="#c9d1e0"/>
    <path d="M116 118q12-8 25 2" fill="none" stroke="#f5a623" stroke-width="1.4" stroke-linecap="round"/>
    <!-- head -->
    <g class="buk-bot-head">
      <circle class="buk-bot-ear" cx="40" cy="54" r="11" fill="#1f3fd1" stroke="#f5a623" stroke-width="2.6"/>
      <circle class="buk-bot-ear" cx="130" cy="54" r="11" fill="#1f3fd1" stroke="#f5a623" stroke-width="2.6"/>
      <circle cx="40" cy="54" r="4.4" fill="#8fb4ff"/>
      <circle cx="130" cy="54" r="4.4" fill="#8fb4ff"/>
      <rect x="42" y="10" width="86" height="86" rx="40" fill="url(#buk-bot-shell)" stroke="#c9d1e0"/>
      <path d="M64 15q21-6 42 0M46 40q-2 16 2 30M124 40q2 16-2 30" fill="none" stroke="#c9d1e0" stroke-width="1.2" stroke-linecap="round"/>
      <rect x="50" y="26" width="70" height="58" rx="27" fill="#0a0e1a"/>
      <path d="M58 40c5-8 14-11 26-11" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" opacity=".16"/>
      <g filter="url(#buk-bot-glow)" fill="none" stroke-linecap="round">
        <path d="M59 43q7-4 13-1M98 42q7-3 13 1" stroke="#f5a623" stroke-width="2"/>
        <g class="buk-bot-eyes" stroke="#56a8ff" stroke-width="4.4">
          <path d="M59 58q7-11 14 0"/>
          <path d="M97 58q7-11 14 0"/>
        </g>
        <path class="buk-bot-mouth" d="M73 70q12 9 24 0" stroke="#56a8ff" stroke-width="3"/>
      </g>
    </g>
  </g>
</svg>`;

  const btn = document.createElement('button');
  btn.id = 'buk-chat-btn';
  btn.type = 'button';
  btn.setAttribute('aria-label', 'Open Blake UK chat');
  btn.innerHTML = ROBOT_SVG;

  // Speech bubble beside the robot. Pops up on every page load and
  // repeats a few times with rotating prompts while the chat is closed.
  // Dismissing it (x) or opening the chat stops it for the browser session.
  const GREET_KEY = 'buk_greeted';
  const GREET_LINES = [
    'Hi! \u{1F44B}<br>Can I help you?',
    'Need help choosing<br>the right aerial?',
    'Looking for a product?<br>I can find it for you.',
    'Question about an order?<br>Just ask me.'
  ];
  const greet = document.createElement('div');
  greet.id = 'buk-chat-greet';
  greet.setAttribute('role', 'status');
  greet.innerHTML = '<button type="button" id="buk-greet-open">' + GREET_LINES[0] + '</button>'
    + '<button type="button" id="buk-greet-close" aria-label="Dismiss">\u00D7</button>';

  const panel = document.createElement('div');
  panel.id = 'buk-chat-panel';
  panel.setAttribute('aria-live', 'polite');
  panel.innerHTML = `
    <div id="buk-chat-header">
      <div id="buk-chat-header-info">
        <img id="buk-chat-logo" src="${ENDPOINT}/widget/img/blake-uk-logo.png" alt="Blake UK" width="91" height="30">
        <div id="buk-chat-header-text">
          <div id="buk-chat-title">Blake AI Support</div>
          <div id="buk-chat-status"><span id="buk-status-dot" aria-hidden="true"></span><span id="buk-status-text">Online</span></div>
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
  document.body.appendChild(greet);
  document.body.appendChild(panel);

  const messages = panel.querySelector('#buk-chat-messages');
  const input    = panel.querySelector('#buk-chat-input');
  const sendBtn  = panel.querySelector('#buk-chat-send');

  // ── Toggle ───────────────────────────────────────────────────────────────────
  btn.addEventListener('click', () => togglePanel(true));
  greet.querySelector('#buk-greet-open').addEventListener('click', () => togglePanel(true));
  greet.querySelector('#buk-greet-close').addEventListener('click', hideGreeting);

  const greetTimers = [];
  function stopGreeting() {
    greetTimers.forEach(clearTimeout);
    greetTimers.length = 0;
    greet.classList.remove('buk-show');
  }
  function hideGreeting() {
    stopGreeting();
    try { sessionStorage.setItem(GREET_KEY, '1'); } catch (e) {}
  }
  function popGreeting(i) {
    if (open) return;
    greet.querySelector('#buk-greet-open').innerHTML = GREET_LINES[i % GREET_LINES.length];
    greet.classList.add('buk-show');
    btn.classList.add('buk-talk');
    greetTimers.push(setTimeout(() => btn.classList.remove('buk-talk'), 1200));
    greetTimers.push(setTimeout(() => greet.classList.remove('buk-show'), 9000));
  }
  let greeted = false;
  try { greeted = sessionStorage.getItem(GREET_KEY) === '1'; } catch (e) {}
  if (!greeted) {
    // 2.5 s after load, then every 40 s, 4 times in total per page.
    for (let i = 0; i < 4; i++) {
      greetTimers.push(setTimeout(() => popGreeting(i), 2500 + i * 40000));
    }
  }
  panel.querySelector('#buk-chat-close').addEventListener('click', () => togglePanel(false));
  panel.querySelector('#buk-chat-refresh').addEventListener('click', startNewConversation);

  function togglePanel(show) {
    open = show;
    panel.style.display = show ? 'flex' : 'none';
    btn.style.display   = show ? 'none' : 'block';
    if (show) hideGreeting();
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
    stopLivePolling();
    liveChatState.active = false;
    liveChatState.lastMessageId = 0;
    updateHeaderForLiveChat(false);
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

    if (liveChatState.active) {
      sendLiveMessage(text);
      return;
    }

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
        if (d.mode && d.mode !== 'ai') {
          // The session moved into live chat some other way (another tab,
          // or after a page refresh reset this tab's own JS state) -
          // recover into live mode instead of showing a confusing error.
          enterLiveMode();
          addMessage('assistant', d.mode === 'live_ended' ? 'This chat has ended.' : "You're now connected with a member of our team.");
        } else {
          addMessage('assistant', 'Sorry, something went wrong. Please try again.');
        }
      } else {
        addMessage('assistant', d.answer, d.products || []);
        if (d.action === 'show_tracking_form') {
          showTrackingForm(d.tracking_no, d.carrier);
        } else if (d.escalate && !ticketRaised && !messages.querySelector('.buk-escalate-form') && !messages.querySelector('.buk-live-choice')) {
          if (d.agent_available) {
            addMessage('assistant', "I don't want to guess on this one. Would you like to raise a support ticket, or talk to someone now?");
            showEscalateChoice();
          } else {
            addMessage('assistant', "I don't want to guess on this one, so I'm passing it to our support team. What's your email address? They'll reply there.");
            showEscalateForm();
          }
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

  // ── Live chat (human handoff) ─────────────────────────────────────────────────
  function showEscalateChoice() {
    const wrap = document.createElement('div');
    wrap.className = 'buk-msg buk-msg-assistant buk-live-choice';
    wrap.innerHTML = assistantRowHtml(`
      <div class="buk-choice-row">
        <button type="button" class="buk-choice-btn" data-choice="ticket">Raise a support ticket</button>
        <button type="button" class="buk-choice-btn" data-choice="live">Talk to someone now</button>
      </div>
    `);
    messages.appendChild(wrap);
    messages.scrollTop = messages.scrollHeight;

    wrap.querySelectorAll('.buk-choice-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        wrap.remove();
        if (btn.dataset.choice === 'live') {
          startLiveChat();
        } else {
          addMessage('assistant', "Sure - what's your email address? Our team will reply there.");
          showEscalateForm();
        }
      });
    });
  }

  async function startLiveChat() {
    addMessage('assistant', 'One moment, connecting you with a member of our team...');
    try {
      const r = await fetch(API + '/live_request.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ session_id: sessionId }),
      });
      const d = await r.json();
      if (!d.ok) {
        addMessage('assistant', d.error || 'Unable to start a live chat right now. Please try raising a support ticket instead.');
        return;
      }
      ticketRaised = true; // a ticket was created for this too - don't also offer the ticket flow again
      enterLiveMode();
    } catch (e) {
      addMessage('assistant', 'Unable to reach the server. Please try again shortly.');
    }
  }

  function enterLiveMode() {
    liveChatState.active = true;
    updateHeaderForLiveChat(true);
    startLivePolling();
  }

  async function sendLiveMessage(text) {
    try {
      const r = await fetch(API + '/live_send.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ session_id: sessionId, message: text }),
      });
      const d = await r.json();
      if (!d.ok) {
        addMessage('assistant', d.error || 'Unable to send that. Please try again.');
      }
    } catch (e) {
      addMessage('assistant', 'Unable to reach the server. Please check your connection.');
    }
  }

  function startLivePolling() {
    stopLivePolling();
    liveChatState.pollTimer = setInterval(pollLiveChat, 4000);
    pollLiveChat();
  }

  function stopLivePolling() {
    if (liveChatState.pollTimer) {
      clearInterval(liveChatState.pollTimer);
      liveChatState.pollTimer = null;
    }
  }

  async function pollLiveChat() {
    try {
      const r = await fetch(API + '/live_poll.php?session_id=' + encodeURIComponent(sessionId) + '&after_id=' + liveChatState.lastMessageId);
      const d = await r.json();
      if (!d.ok) return;

      (d.messages || []).forEach(m => {
        liveChatState.lastMessageId = Math.max(liveChatState.lastMessageId, m.id);
        if (m.role === 'system') {
          addSystemMessage(m.content);
        } else {
          addMessage('assistant', m.content);
        }
      });

      if (d.mode === 'live_ended') {
        stopLivePolling();
        liveChatState.active = false;
        updateHeaderForLiveChat(false);
      }
    } catch (e) {
      // Silent - the next tick just tries again.
    }
  }

  function updateHeaderForLiveChat(active) {
    const statusText = panel.querySelector('#buk-status-text');
    if (statusText) statusText.textContent = active ? 'Live agent' : 'Online';
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
  // Blake UK badge appears consistently rather than only on plain text replies.
  function assistantRowHtml(bubbleInnerHtml) {
    return `<div class="buk-msg-row">
      <img class="buk-avatar-sm" src="${ENDPOINT}/widget/img/blake-uk-badge.png" alt="" aria-hidden="true" width="24" height="24">
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

  // A quiet centred status line (agent joined / chat ended) rather than a
  // full bubble - it's an event about the conversation, not a message
  // within it.
  function addSystemMessage(text) {
    const el = document.createElement('div');
    el.className = 'buk-system-note';
    el.textContent = text;
    messages.appendChild(el);
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
