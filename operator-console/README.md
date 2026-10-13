# Blake UK Operator Console

Desktop app (Windows + Debian/Linux) for support staff: get notified when
the chatbot escalates a conversation, review it, route it to sales/support/
accounts, add notes. Built with [Tauri](https://tauri.app) - a small native
binary wrapping a plain HTML/CSS/JS UI (no React, no bundler, no Node.js in
the shipped app), talking directly to the existing admin API
(`chat.blakegroup.uk/api/admin/...`) with the same login your team already
uses on the website.

## What's here

**Version 0.22.0: threads and @ai in team chat.** Replies are grouped into threads: a reply quotes the line and its author with an "N replies" link that opens a thread panel (first message, every reply, its own reply box). Typing **@ai** in any chat or thread makes Max read the conversation (the thread, when asked inside one) plus Blake UK knowledge and products, and post one answer that quotes the question; Max says nothing unless @ai is typed, and customer card numbers and similar are masked before anything goes to Gemini.

**Version 0.21.0: team chat.** The Team page opens on a Telegram style **Chats** tab (chat list with avatars, last message, time and unread badges; conversation with bubbles, seen ticks, replies and @mentions; group info; New chat and New group), with the old staff cards on a **Staff** tab. The floating chat button opens the same chats in a panel (it hides on the Team page, where it would sit on the Send button). Both are views of one shared store (`dist/teamchat.js`, `dist/teamchat.css`) that polls every 3 seconds. All rules are on the server (`Chat\Channels`): owner or administrator manage a group, members leave and mute, one to one chats are fixed. Notifications: every message unless muted or in view, mentions and replies always, and being added, removed or a group deleted. Tested with four people at once by `tests/e2e/teamchat_ui.py` (68 checks).

**Version 0.20.0:** the left column has four entries under Admin, each opening a tab of the admin page in the centre and highlighted while you are on it: **Writing assistant**, **File sharing**, **QR codes & links** and **Newsroom**. The QR tool is now shown in the centre, signed in automatically (the admin's QR tab asks the server for a one-time code), and the separate QR window code was removed from the app. "Open in its own window" hands a fresh signed-in address to `openExternal`, which opens the computer's browser. No new Rust code and no new permissions were needed, so the app change is only the screen (`dist/index.html`).

**Version 0.19.0 moves the app from Tauri 1 to Tauri 2.** The reason is Debian 13: Tauri 1 needs the `libwebkit2gtk-4.0` library, which Debian 13 and Ubuntu 24.04 no longer provide (only 4.1), so the 0.18.0 `.deb` could not be installed there. Tauri 2 uses 4.1. Nothing the staff see changes.

* **Windows** keeps the same `https://tauri.localhost` address (`useHttpsScheme`), so stored settings and the server's list of trusted app origins are unchanged. An installed 0.18.0 offers the update as usual.
* **Linux (Debian 13):** `sudo apt install ./blake-uk-operator-console.deb`. It depends on `libwebkit2gtk-4.1-0`, `libgtk-3-0` and `libayatana-appindicator3-1`. A stock GNOME has no tray, so **closing the window minimises it** on Linux (on Windows it hides to the tray) and a missing tray never stops it starting.
* **Checked on every build:** the workflow installs the new `.deb` in a clean Debian 13 container, checks every library it needs is present, starts it under a virtual screen and checks it opened its window (screenshot kept as the `debian13-screenshot` artifact). Publishing waits for this.
* The screen talks to the native shell through one adapter (`NATIVE` in `dist/index.html`) that supports both Tauri 1 and 2, and `tests/e2e/console_ui.py` runs against both (`TAURI_SHAPE=v1|v2`).
* What is **not** tested: the Windows build has not been run on a real Windows machine, and nothing here has run the app's buttons through the real native shell (only the window opening and staying up on Debian 13, and the screen's logic against a simulated shell).

**Version 0.18.0** adds, in the sidebar and the ticket panel:

- **Customer conversation in tickets.** Open a ticket to read the whole conversation with the customer, reply (the customer is emailed with their private link) and reset their link. Admins and editors can reply; other roles can read.
- **Writing assistant.** A sidebar entry that opens the admin on its *Writing assistant* tab: improve a message, or draft a reply to a customer email.
- **QR codes & links.** A sidebar entry that opens `https://qr.blakegroup.uk/admin/` in its own app window (label `qrtool`); pressing it again focuses that window. If the app cannot make a window it opens the normal browser. The QR tool has its own sign-in, so it is deliberately not embedded: it would need to be allowed to be framed by the app and to send cookies across origins.
- **Guides.** The admin Dashboard's *Guides* box posts `{openExternal: url}` to the app, which opens it in the computer's PDF viewer. Only `https://blakegroup.uk/...` and `https://qr.blakegroup.uk/...` addresses are accepted, and only from the admin frame.

`tests/e2e/console_ui.py` (with `console_server.php`) runs this screen in a browser against a real server with the Tauri shell stubbed and recorded: the sidebar, both new entries, the conversation (read, reply, reset, read-only roles), the guide opener and its refusals. **It cannot show the native window itself**, and the desktop build has not been run on a real Windows machine by the person who wrote this: install the build and press *QR codes & links* to confirm the window opens.


- `dist/index.html` - the entire UI. Login, ticket list with polling +
  notifications, ticket detail with department routing and notes. Plain
  HTML/CSS/JS, no build step - open it directly in a browser to work on the
  UI without touching Rust at all (native notifications just no-op outside
  Tauri, everything else works normally).
- `src-tauri/` - the native shell: window, system tray, notification
  permission handling. Deliberately thin - almost everything lives in
  `dist/index.html` and talks to the backend directly.

## A known limitation in how this was built

This was developed in a sandboxed Linux environment without access to
`rustup` (its usual distribution servers were blocked). The only Rust
available was Ubuntu's own apt package, version 1.75.0 - and the current
Tauri v1 dependency tree needs a newer one (several transitive dependencies
require Rust's `edition2024`, which 1.75 doesn't support). Everything in
`src-tauri/` was written carefully against Tauri's actual v1 API and syntax-
checked (`rustfmt --check` successfully parsed it), but **it has not been
compiled end-to-end**. The `dist/index.html` frontend, by contrast, *was*
fully tested end-to-end - real backend, real browser, real login - and
that testing is what caught and fixed the real bugs during development.

This should not be an issue on a normal setup: rustup installs a current
Rust with none of the dependency conflicts this sandbox hit. But the first
build is the one point where something in `src-tauri/` might need a small
fix that a real compiler will catch immediately and clearly.

## Setting up a build environment

### Windows
1. Install [Rust via rustup](https://rustup.rs) (downloads and runs
   `rustup-init.exe`, use the default options).
2. Install [Microsoft Visual Studio C++ Build Tools](https://visualstudio.microsoft.com/visual-cpp-build-tools/)
   (Tauri needs the "Desktop development with C++" workload).
3. Install [WebView2](https://developer.microsoft.com/microsoft-edge/webview2/)
   if not already present - Windows 10/11 usually already has it.

### Debian / Ubuntu
```bash
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh
sudo apt update
sudo apt install -y libwebkit2gtk-4.1-dev libgtk-3-dev libayatana-appindicator3-dev \
  librsvg2-dev build-essential curl wget file libssl-dev pkg-config
```

### Both platforms
```bash
cargo install tauri-cli --version "^1"
```

## Building

From the `operator-console/` directory:

```bash
# Development (hot-reloads dist/index.html on change)
cargo tauri dev

# Production build - produces a .deb on Linux, or a .exe (NSIS) on Windows,
# in src-tauri/target/release/bundle/
cargo tauri build
```

## Before your first real build

- **Icons**: `src-tauri/icons/` currently has simple placeholder squares
  (generated programmatically, not real branding). Replace them with actual
  Blake UK icons before distributing - Tauri's
  [icon docs](https://tauri.app/v1/guides/features/icons/) cover the exact
  sizes needed, or `cargo tauri icon path/to/logo.png` generates the full
  set from one source image.
- **Server address**: defaults to `https://chat.blakegroup.uk`, changeable
  from the login screen ("change" link next to "Server") - stored locally
  per install, so this doesn't need to be rebuilt into the app for
  different environments (e.g. a staging server).
- **CSP**: currently disabled (`"csp": null` in `tauri.conf.json`) for v1
  simplicity. Worth tightening to an explicit `connect-src` allowlist
  before wide distribution, once the server address is settled.

## What's deliberately not in this version

Person-level ticket assignment (vs. department), internal group chat,
staff reminders, an AI copilot for staff, and the ability to interrupt an
in-progress AI response were all part of the original request but scoped
out of this first version in favour of shipping the core notify-and-route
flow completely. Each is a genuinely separate chunk of work - some need
new backend data models (groups, reminders), one needs backend support for
cancelling an in-flight Gemini call - and are natural next phases once this
one's in use.
