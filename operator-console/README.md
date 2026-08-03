# Blake UK Operator Console

Desktop app (Windows + Debian/Linux) for support staff: get notified when
the chatbot escalates a conversation, review it, route it to sales/support/
accounts, add notes. Built with [Tauri](https://tauri.app) - a small native
binary wrapping a plain HTML/CSS/JS UI (no React, no bundler, no Node.js in
the shipped app), talking directly to the existing admin API
(`chat.blakegroup.uk/api/admin/...`) with the same login your team already
uses on the website.

## What's here

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

# Production build - produces a .deb on Linux, or .msi/.exe on Windows,
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
