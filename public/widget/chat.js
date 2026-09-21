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

  // Max: animated robot mascot launcher. A single cut-out image
  // (widget/img/max2.webp, PNG fallback; headset support robot with the
  // Blake UK "UK" chest badge) with the edge fade baked in; the motion
  // (gentle bob and sway, eye glow and blink, shine sweep) is CSS.
  const MAX_IMG = ENDPOINT + '/widget/img/max2';
  const ROBOT_SVG = `
<span class="buk-max" aria-hidden="true">
  <span class="buk-max-body">
    <picture>
      <source srcset="${MAX_IMG}.webp" type="image/webp">
      <img src="${MAX_IMG}.png" alt="" width="150" height="138" draggable="false">
    </picture>
    <span class="buk-max-shine" style="-webkit-mask-image:url('${MAX_IMG}.png');mask-image:url('${MAX_IMG}.png')"></span>
    <span class="buk-max-eye buk-max-eye-l"></span>
    <span class="buk-max-eye buk-max-eye-r"></span>
    <span class="buk-max-lid buk-max-eye-l"></span>
    <span class="buk-max-lid buk-max-eye-r"></span>
  </span>
</span>`;

  const btn = document.createElement('button');
  btn.id = 'buk-chat-btn';
  btn.type = 'button';
  btn.setAttribute('aria-label', 'Chat with Max, Blake UK support');
  btn.innerHTML = ROBOT_SVG;

  // Speech bubble beside the robot. Schedule per browser session:
  // appears 10 s after first load, stays 5 s, hides 60 s, appears once
  // more for 5 s, then stops for the session. The schedule is kept in
  // sessionStorage so it carries across page navigations. Dismissing (x)
  // or opening the chat stops it immediately for the session.
  const GREET_KEY = 'buk_greet_v2';
  const GREET_FIRST_MS = 10000;
  const GREET_SHOW_MS  = 5000;
  const GREET_GAP_MS   = 60000;
  const GREET_MAX      = 2;
  const GREET_LINES = [
    'Hi! I\'m Max \u{1F44B}<br>Can I help you?',
    'Max here again.<br>Still looking? Just ask.'
  ];
  const greet = document.createElement('div');
  greet.id = 'buk-chat-greet';
  greet.setAttribute('role', 'status');
  // Inline hidden so it cannot flash before chat.css has loaded.
  greet.style.display = 'none';
  greet.innerHTML = '<button type="button" id="buk-greet-open">' + GREET_LINES[0] + '</button>'
    + '<button type="button" id="buk-greet-close" aria-label="Dismiss">\u00D7</button>';

  const panel = document.createElement('div');
  panel.id = 'buk-chat-panel';
  panel.setAttribute('aria-live', 'polite');
  panel.innerHTML = `
    <div id="buk-chat-header">
      <div id="buk-chat-header-info">
        <span id="buk-max-avatar" aria-hidden="true">
          <picture><source srcset="${MAX_IMG}-head.webp" type="image/webp"><img src="${MAX_IMG}-head.png" alt="" width="52" height="48" draggable="false"></picture>
          <span class="buk-av-eye buk-av-eye-l"></span><span class="buk-av-eye buk-av-eye-r"></span>
          <span class="buk-av-lid buk-av-eye-l"></span><span class="buk-av-lid buk-av-eye-r"></span>
          <span id="buk-max-smile-cover"></span>
          <span id="buk-max-mouth"></span>
        </span>
        <div id="buk-chat-header-text">
          <div id="buk-chat-title">Max, AI Support</div>
          <div id="buk-chat-status"><span id="buk-status-dot" aria-hidden="true"></span><span id="buk-status-text">Online</span></div>
        </div>
      </div>
      <div id="buk-chat-header-actions">
        <button id="buk-chat-voice" class="buk-icon-btn" type="button" aria-label="Mute Max's voice" title="Mute Max's voice" aria-pressed="false">
          <svg class="buk-voice-on" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 9v6h4l5 4V5L8 9H4Z" fill="currentColor"/><path d="M16.5 8.5a5 5 0 0 1 0 7M19 6a8.5 8.5 0 0 1 0 12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
          <svg class="buk-voice-off" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 9v6h4l5 4V5L8 9H4Z" fill="currentColor"/><path d="M17 9.5l5 5M22 9.5l-5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
        </button>
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

  // Keep everything hidden until chat.css has loaded, otherwise the
  // unstyled panel and robot flash onto the host page for a moment.
  panel.style.display = 'none';
  btn.style.visibility = 'hidden';
  const revealLauncher = () => { btn.style.visibility = ''; };
  style.addEventListener('load', revealLauncher);
  style.addEventListener('error', revealLauncher);
  if (style.sheet) revealLauncher();
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
  function greetState() {
    try { return JSON.parse(sessionStorage.getItem(GREET_KEY)) || {}; } catch (e) { return {}; }
  }
  function saveGreetState(st) {
    try { sessionStorage.setItem(GREET_KEY, JSON.stringify(st)); } catch (e) {}
  }
  function hideBubble() {
    greet.classList.remove('buk-show');
    greetTimers.push(setTimeout(() => { greet.style.display = 'none'; }, 300));
  }
  function hideGreeting() {
    greetTimers.forEach(clearTimeout);
    greetTimers.length = 0;
    greet.classList.remove('buk-show');
    greet.style.display = 'none';
    const st = greetState();
    st.done = true;
    saveGreetState(st);
  }
  function scheduleGreeting() {
    const st = greetState();
    if (st.done || (st.shown || 0) >= GREET_MAX) return;
    const now = Date.now();
    if (!st.next) { st.next = now + GREET_FIRST_MS; saveGreetState(st); }
    // Never pop instantly on a fresh page even if the slot passed mid-navigation.
    greetTimers.push(setTimeout(popGreeting, Math.max(st.next - now, 3000)));
  }
  function popGreeting() {
    if (open) return;
    const st = greetState();
    if (st.done) return;
    const n = st.shown || 0;
    st.shown = n + 1;
    st.next = Date.now() + GREET_SHOW_MS + GREET_GAP_MS;
    if (st.shown >= GREET_MAX) st.done = true;
    saveGreetState(st);
    greet.querySelector('#buk-greet-open').innerHTML = GREET_LINES[Math.min(n, GREET_LINES.length - 1)];
    greet.style.display = 'flex';
    void greet.offsetWidth; // restart the pop transition
    greet.classList.add('buk-show');
    btn.classList.add('buk-talk');
    greetTimers.push(setTimeout(() => btn.classList.remove('buk-talk'), 2600));
    greetTimers.push(setTimeout(() => {
      hideBubble();
      if (!st.done) scheduleGreeting();
    }, GREET_SHOW_MS));
  }
  scheduleGreeting();
  panel.querySelector('#buk-chat-close').addEventListener('click', () => togglePanel(false));
  panel.querySelector('#buk-chat-refresh').addEventListener('click', startNewConversation);

  function togglePanel(show) {
    open = show;
    panel.style.display = show ? 'flex' : 'none';
    btn.style.display   = show ? 'none' : 'block';
    if (show) { hideGreeting(); unlockAudio(); } else { stopSpeech(); }
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
    updateHeaderForLiveChat('ai');
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
      addMessage('assistant', "Hi, I'm Max, Blake UK's support assistant. How can I help you today?");
      speak({ kind: 'welcome' });
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
  // ── Max's voice ────────────────────────────────────────────────────────
  // Replies are spoken as a short summary (server: api/chat/speak.php).
  // Browsers only allow audio once the visitor has interacted with the
  // page, so the AudioContext is created/resumed inside real user gestures
  // (opening the chat, sending a message). That is the browser's normal
  // autoplay rule, not a permission prompt - nothing is shown to the user.
  const VOICE_KEY = 'buk_voice_muted';
  let voiceMuted = false;
  try { voiceMuted = localStorage.getItem(VOICE_KEY) === '1'; } catch (e) {}
  let audioCtx = null, speechSrc = null, speechSeq = 0, mouthRaf = 0;
  const avatar = panel.querySelector('#buk-max-avatar');
  const voiceBtn = panel.querySelector('#buk-chat-voice');

  function renderVoiceBtn() {
    voiceBtn.classList.toggle('buk-muted', voiceMuted);
    voiceBtn.setAttribute('aria-pressed', voiceMuted ? 'true' : 'false');
    const label = voiceMuted ? "Unmute Max's voice" : "Mute Max's voice";
    voiceBtn.setAttribute('aria-label', label);
    voiceBtn.title = label;
  }
  renderVoiceBtn();
  voiceBtn.addEventListener('click', () => {
    voiceMuted = !voiceMuted;
    try { localStorage.setItem(VOICE_KEY, voiceMuted ? '1' : '0'); } catch (e) {}
    renderVoiceBtn();
    if (voiceMuted) stopSpeech(); else unlockAudio();
  });

  function unlockAudio() {
    if (voiceMuted) return;
    try {
      if (!audioCtx) {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return;
        audioCtx = new Ctx();
      }
      if (audioCtx.state === 'suspended') audioCtx.resume();
      // iOS Safari only fully unlocks after a sound starts inside the gesture.
      const b = audioCtx.createBuffer(1, 1, 22050);
      const src = audioCtx.createBufferSource();
      src.buffer = b; src.connect(audioCtx.destination); src.start(0);
    } catch (e) {}
  }

  function setMouth(level) {
    avatar.style.setProperty('--buk-mouth', level.toFixed(3));
  }

  function stopSpeech() {
    speechSeq++;
    if (speechSrc) { try { speechSrc.stop(); } catch (e) {} speechSrc = null; }
    stopElement();
    avatar.classList.remove('buk-voice-loading');
    cancelAnimationFrame(mouthRaf);
    avatar.classList.remove('buk-speaking');
    setMouth(0);
  }

  // Report what happened to speech back to the server log (fire and
  // forget) so "I hear nothing" can be diagnosed from the server side.
  function reportSpeech(event, detail) {
    try {
      fetch(API + '/speak.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ session_id: sessionId, client_event: event, detail: String(detail || '').slice(0, 200) }),
        keepalive: true,
      }).catch(() => {});
    } catch (e) {}
  }

  let speechEl = null;
  function stopElement() {
    if (speechEl) { try { speechEl.pause(); } catch (e) {} URL.revokeObjectURL(speechEl.src); speechEl = null; }
  }

  // Resolve within ms even if the browser leaves resume() pending.
  function resumeCtx(ms) {
    if (!audioCtx || audioCtx.state === 'running') return Promise.resolve();
    return Promise.race([audioCtx.resume().catch(() => {}), new Promise(r => setTimeout(r, ms))]);
  }

  function startMouth(readLevel, isDone) {
    avatar.classList.add('buk-speaking');
    let level = 0;
    const tick = () => {
      if (isDone()) { avatar.classList.remove('buk-speaking'); setMouth(0); return; }
      // Fast open, slower close: reads as syllables rather than flicker.
      const target = Math.min(1, readLevel() * 5);
      level = target > level ? target : level * 0.8 + target * 0.2;
      setMouth(level);
      mouthRaf = requestAnimationFrame(tick);
    };
    tick();
  }

  // what: { message_id } or { kind: 'welcome' }
  async function speak(what) {
    if (voiceMuted || !sessionId) return;
    stopSpeech();
    const seq = speechSeq;
    avatar.classList.add('buk-voice-loading');
    try {
      const r = await fetch(API + '/speak.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ session_id: sessionId }, what)),
      });
      if (seq !== speechSeq) return;
      if (r.status !== 200) { if (r.status !== 204) reportSpeech('http_error', r.status); return; }
      const buf = await r.arrayBuffer();
      if (seq !== speechSeq || !open || voiceMuted) return;

      // Preferred path: Web Audio, which also drives the mouth from the
      // real speech level.
      await resumeCtx(400);
      if (audioCtx && audioCtx.state === 'running') {
        const audio = await new Promise((res, rej) => audioCtx.decodeAudioData(buf.slice(0), res, rej));
        if (seq !== speechSeq || !open || voiceMuted) return;
        const src = audioCtx.createBufferSource();
        const analyser = audioCtx.createAnalyser();
        analyser.fftSize = 512;
        src.buffer = audio;
        src.connect(analyser);
        analyser.connect(audioCtx.destination);
        speechSrc = src;
        const data = new Uint8Array(analyser.fftSize);
        let done = false;
        src.onended = () => { done = true; if (speechSrc === src) speechSrc = null; };
        src.start();
        startMouth(() => {
          analyser.getByteTimeDomainData(data);
          let sum = 0;
          for (let i = 0; i < data.length; i++) { const v = (data[i] - 128) / 128; sum += v * v; }
          return Math.sqrt(sum / data.length);
        }, () => done || seq !== speechSeq);
        reportSpeech('played', 'webaudio');
        return;
      }

      // Fallback: a plain audio element (no Web Audio, or the context
      // could not be started). The mouth is animated without level data.
      const el = new Audio(URL.createObjectURL(new Blob([buf], { type: 'audio/wav' })));
      speechEl = el;
      try {
        await el.play();
      } catch (e) {
        reportSpeech('blocked', (e && e.name) + ' ctx=' + (audioCtx ? audioCtx.state : 'none'));
        stopElement();
        return;
      }
      const t0 = performance.now();
      startMouth(() => 0.12 + 0.1 * Math.abs(Math.sin((performance.now() - t0) / 90)),
                 () => el.ended || el.paused || seq !== speechSeq);
      el.onended = () => { if (speechEl === el) stopElement(); };
      reportSpeech('played', 'element ctx=' + (audioCtx ? audioCtx.state : 'none'));
    } catch (e) {
      // Speech is an extra: any failure leaves the text reply as it is.
      reportSpeech('error', (e && (e.name + ': ' + e.message)) || e);
    } finally {
      if (seq === speechSeq) avatar.classList.remove('buk-voice-loading');
    }
  }

  sendBtn.addEventListener('click', () => { unlockAudio(); sendMessage(); });
  input.addEventListener('keydown', e => { if (e.key === 'Enter') { unlockAudio(); sendMessage(); } });

  async function sendMessage() {
    const text = input.value.trim();
    if (!text || !sessionId) return;
    input.value = '';
    stopSpeech();
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
          enterLiveMode(d.mode);
        } else {
          addMessage('assistant', 'Sorry, something went wrong. Please try again.');
        }
      } else {
        if (d.answer) {
          addMessage('assistant', d.answer, d.products || []);
          if (d.message_id && !d.handoff) speak({ message_id: d.message_id });
        }
        if (d.action === 'show_postcode_form') {
          showPostcodeForm(d.band || 'tv');
        } else if (d.action === 'show_tracking_form') {
          showTrackingForm(d.tracking_no, d.carrier);
        } else if (d.handoff && d.mode && d.mode !== 'ai') {
          // Max has passed the chat to the team (or is taking ticket
          // details). Notices arrive via live_poll.php.
          enterLiveMode(d.mode);
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

  // Postcode box for aerial / reception questions (TV, FM or DAB). The
  // postcode goes back as an ordinary chat message worded for the band, so
  // the reception predictor runs on it and it shows in the transcript.
  const UK_POSTCODE = /^([A-Z]{1,2}\d[A-Z\d]?)\s*(\d[A-Z]{2})$/i;
  function showPostcodeForm(band) {
    messages.querySelectorAll('.buk-postcode-wrap').forEach(n => n.remove());
    const label = band === 'dab' ? 'Check my DAB signal' : band === 'fm' ? 'Check my FM signal' : 'Check my TV signal';
    const wrap = document.createElement('div');
    wrap.className = 'buk-msg buk-msg-assistant buk-postcode-wrap';
    wrap.innerHTML = assistantRowHtml(`
      <div class="buk-tracking-form buk-postcode-form">
        <input type="text" class="buk-postcode-input" placeholder="Your postcode, e.g. S3 9PT" autocomplete="postal-code" maxlength="8" aria-label="Your postcode">
        <div class="buk-postcode-err" role="alert"></div>
        <button class="buk-track-submit buk-postcode-submit" type="button">${label}</button>
      </div>
    `);
    messages.appendChild(wrap);
    messages.scrollTop = messages.scrollHeight;
    const inp = wrap.querySelector('.buk-postcode-input');
    const err = wrap.querySelector('.buk-postcode-err');
    const go = () => {
      const m = inp.value.trim().toUpperCase().match(UK_POSTCODE);
      if (!m) { err.textContent = 'Please enter a full UK postcode, e.g. S3 9PT.'; inp.focus(); return; }
      const pc = m[1] + ' ' + m[2];
      wrap.remove();
      const what = band === 'dab' ? 'DAB radio reception' : band === 'fm' ? 'FM radio reception' : 'TV aerial reception';
      input.value = `${what} for ${pc}`;
      sendMessage();
    };
    wrap.querySelector('.buk-postcode-submit').addEventListener('click', () => { unlockAudio(); go(); });
    inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); unlockAudio(); go(); } });
    setTimeout(() => inp.focus(), 50);
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
      enterLiveMode(d.mode);
    } catch (e) {
      addMessage('assistant', 'Unable to reach the server. Please try again shortly.');
    }
  }

  function enterLiveMode(mode) {
    liveChatState.active = true;
    liveChatState.mode = mode || 'live_requested';
    updateHeaderForLiveChat(liveChatState.mode);
    startLivePolling();
  }

  function exitLiveMode() {
    stopLivePolling();
    liveChatState.active = false;
    liveChatState.mode = 'ai';
    updateHeaderForLiveChat('ai');
  }

  // While Max is handling the chat, check now and then whether a member of
  // staff has joined from the Operator Console, so the customer sees them
  // straight away rather than on their next message.
  setInterval(async () => {
    if (!open || liveChatState.active || !sessionId) return;
    try {
      const r = await fetch(API + '/live_poll.php?session_id=' + encodeURIComponent(sessionId) + '&after_id=' + liveChatState.lastMessageId);
      const d = await r.json();
      if (d.ok && d.mode && d.mode !== 'ai') enterLiveMode(d.mode);
    } catch (e) {}
  }, 10000);

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

      if (d.mode === 'ai' || d.mode === 'live_ended') {
        exitLiveMode();
      } else if (d.mode && d.mode !== liveChatState.mode) {
        liveChatState.mode = d.mode;
        updateHeaderForLiveChat(d.mode);
      }
    } catch (e) {
      // Silent - the next tick just tries again.
    }
  }

  function updateHeaderForLiveChat(mode) {
    const statusText = panel.querySelector('#buk-status-text');
    if (!statusText) return;
    statusText.textContent = mode === 'live_active' ? 'Live agent'
      : mode === 'live_requested' ? 'Connecting you to our team…'
      : 'Online';
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
      wrap.innerHTML = assistantRowHtml(linkify(basicMarkdown(esc(text)))) + productsHtml + `<div class="buk-meta">${time}</div>`;
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

  // Gemini replies use light markdown (**bold**, "* " bullets), which was
  // showing as literal asterisks. Runs on already-escaped text and only
  // emits <strong> and a bullet character, so it adds no injection surface.
  function basicMarkdown(escaped) {
    return escaped
      .replace(/\*\*([^*\n]{1,200})\*\*/g, '<strong>$1</strong>')
      .replace(/^[ \t]*[*-][ \t]+/gm, '• ');
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
