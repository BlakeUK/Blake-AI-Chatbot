# Blake UK AI Support Chatbot

> **© 2026 Blake UK Ltd — Proprietary Software. All Rights Reserved.**
> Unauthorised copying, modification or distribution is strictly prohibited.
> See [LICENCE](./LICENCE) for full terms including GDPR (UK) compliance statement.

A self-hosted, RAG-powered customer support and product assistant for [blake-uk.com](https://www.blake-uk.com).
Built on **Caddy + PHP 8.2 + SQLite**. Powered by **Google Gemini**. No Node.js, no React, no pip.

---

## Table of Contents

- [Screenshots](#screenshots)
- [Features — Full Roadmap](#features--full-roadmap)
- [Tech Stack](#tech-stack)
- [One-Line Install](#one-line-install)
- [Manual Setup](#manual-setup)
- [Admin Interface](#admin-interface)
- [Mobile Apps (Android)](#mobile-apps-android)
- [Embedding the Widget](#embedding-the-widget)
- [External Widget API](#external-widget-api)
- [Product Feed Import](#product-feed-import)
- [Carrier Tracking](#carrier-tracking)
- [Support Tickets](#support-tickets)
- [Security](#security)
- [GDPR Compliance](#gdpr-uk-compliance)
- [Build Phases](#build-phases)
- [Licence](#licence)

---

## Screenshots

### Admin — Dashboard
> The Dashboard gives you an at-a-glance overview of the entire system. Four live counters show knowledge entries, indexed files, imported products and total chat sessions. The recent sessions table shows what customers are asking about, which page they were on, and when they last interacted.

![Admin dashboard with live stats counters and recent chat sessions table](docs/img/admin-dashboard.png)

---

### Admin — Knowledge Manager
> Build the bot's information base manually. Paste any support content — delivery policies, warranty terms, FAQs, installation notes — give it a title, optional category, URL and product codes, then click Save. It is indexed into full-text search within seconds and immediately used to ground bot answers.

![Admin knowledge tab showing entry form with title, content, category, URL fields and knowledge entries table](docs/img/admin-knowledge.png)

---

### Admin — Files / RAG Indexing
> Upload product datasheets, installation guides, warranty documents, CCTV leaflets and images. Each file is sent to Google Gemini which reads it natively — PDFs, Word documents, images and more — extracts all usable text, splits it into chunks and indexes it. You can view individual chunks, edit any that extracted incorrectly, and re-index a file at any time without re-uploading.

![Admin files tab showing upload zone, indexed file list with Chunks, Re-index and Delete buttons per file](docs/img/admin-files.png)

---

### Admin — Product Feed Import
> Upload your full product catalogue as a JSON or XML feed. Every product is upserted — new ones created, existing ones updated — and the full-text search index is refreshed automatically. The import summary shows exactly how many products were created, updated, skipped or errored. The searchable table lets you verify any product in the database instantly.

![Admin products tab showing drag-drop import zone, import result grid with created/updated/skipped/error counts, and searchable product table](docs/img/admin-products.png)

---

### Admin — API Keys & Model Settings
> Stores all credentials the system needs to operate. Your Google Gemini key powers every AI answer and document extraction. Royal Mail, DPD and DX keys enable order tracking. All keys are encrypted at rest using AES-256-GCM — the full key value is never returned to the browser after saving. The model dropdown is populated live from the Google API so you always see every available model.

![Admin API keys tab showing Gemini key input, carrier key fields for Royal Mail, DPD and DX, and stored services table](docs/img/admin-apikeys.png)

---

### Admin — External Widget Clients
> Give external websites their own API key to embed the Blake UK chat widget. Each client can be locked to specific server IP addresses — including CIDR ranges — and to specific origin domains. The full API key is shown only once at creation, then masked permanently. Clients can be revoked instantly and every token request is recorded in the audit log.

![Admin widget clients tab showing client creation form with IP and origin fields, one-time key reveal alert, and clients table with masked keys and Active/Revoked status badges](docs/img/admin-clients.png)

---

### Chat Widget — Product Page
> The chat widget floats in the bottom-right corner of any blake-uk.com page. When a customer asks about products the bot searches the catalogue and returns clickable product cards with name, code and price linking directly to the product page. If the bot's confidence is low it shows an escalation prompt offering to raise a support ticket. The widget automatically reads the current product page context so answers are relevant to what the customer is already viewing.

![Blake UK CAT6 product page with chat widget open showing product recommendations as cards with code and price, plus an escalation prompt at the bottom](docs/img/widget-product-page.png)

---

### Chat Widget — Mobile
> On mobile the widget expands to fill the full screen width with touch-optimised inputs and buttons. Product cards stack vertically with large tap targets. The tracking form — shown here — appears automatically when the bot detects a delivery or order enquiry, asking for the tracking number and postcode before querying Royal Mail, DPD or DX. The escalation button is equally accessible on mobile.

![Mobile phone showing full-width Blake UK chat widget with tracking form displayed, asking for tracking number and postcode after the customer asked where their order is](docs/img/widget-mobile.png)

## Features — Full Roadmap

### Phase 1 — Core (Complete ✅)

| Feature | Detail |
|---|---|
| Embeddable chat widget | Single `<script>` tag, vanilla JS, no dependencies |
| Gemini RAG pipeline | Retrieves knowledge chunks → builds grounded prompt → Gemini answers |
| Manual knowledge base | Admin text-box entries, instantly FTS5-indexed |
| PDF / image / document upload | Gemini multimodal extraction → chunked → indexed |
| Encrypted API key storage | AES-256-GCM, IV + auth tag stored separately, never returned to browser |
| Live Gemini model list | Fetched from Google API, dropdown to select chat + extraction model |
| External widget API | Per-client keys, IP allowlist (CIDR supported), origin allowlist, 5-min tokens |
| Admin login | bcrypt, CSRF, rate-limited, secure session cookies |
| Chat session logging | Page URL, product code, messages, confidence, escalation flag |
| Confidence scoring | Heuristic based on RAG hits; low-confidence triggers escalation prompt |
| CORS lockdown | Configurable origin allowlist in config |
| Rate limiting | SQLite-backed sliding window, per-endpoint |
| Audit logging | All admin actions logged with timestamp and admin ID |

### Phase 2 — Products (In Progress 🔄)

| Feature | Detail |
|---|---|
| JSON product feed import | Upsert products, variants, documents; FTS5-indexed |
| XML product feed import | Supports FUSE/bespoke XML; flexible field normalisation |
| Product-aware chat | Current page URL + product code sent with each message |
| Inline product cards | Widget renders product name, code, price, image, direct link |
| Product search | FTS5 across name, code, description, search terms |
| Variant handling | Length, colour, size variants linked by product code |
| Related products | Cross-sell and accessory suggestions in RAG context |
| Admin product browser | Searchable table of imported products |
| Import result summary | Created / updated / skipped / error counts per import |

### Phase 3 — Documents (Mostly Complete ✅)

| Feature | Detail |
|---|---|
| Re-extraction on update | ✅ Re-index existing file without re-upload |
| Chunk viewer | ✅ Admin UI to inspect and edit extracted chunks |
| Manual chunk override | ✅ Correct bad Gemini extractions in admin |
| Scheduled re-indexing | ✅ Cron-driven queue (`process_pending_files.php`) picks up any file left `pending`, including bulk URL imports |
| Document tagging | ⏳ Not yet — tag files to specific categories or product codes |
| Datasheet matching | ⏳ Not yet — auto-link product documents to product codes via filename |

### Phase 4 — Carrier Tracking (Live ✅)

| Feature | Detail |
|---|---|
| Carrier auto-detection | ✅ Detects tracking-intent messages and carrier format from the number itself |
| Royal Mail / Post Office | ✅ Live via Royal Mail Tracking API |
| DPD | ⚠️ Wired up, but the endpoint is an unverified placeholder — confirm against DPD's real REST API before relying on it |
| DX | ⚠️ Wired up, but the endpoint is an unverified placeholder — confirm against DX's real API before relying on it |
| Tracking intent detection | ✅ Keyword + pattern matching (not full NLP) short-circuits the chat flow into the tracking form |
| Verification gate | ⚠️ Postcode is collected in the chat form but not yet validated against order data before querying the carrier |
| Tracking history log | ✅ Every query and result stored in `tracking_requests` |

### Phase 5 — Support Tickets (In Progress 🔄)

| Feature | Detail |
|---|---|
| Escalation to ticket | ✅ Low-confidence or customer-requested escalation creates a ticket |
| Staff notes | ✅ Internal notes on tickets, not visible to the customer |
| Ticket status | ✅ Open / pending / closed with timestamps |
| Correction workflow | ✅ Staff corrects a bot answer; correction can be promoted straight to the knowledge base |
| Admin ticket queue | ✅ Filtered by status; assigned-agent/priority filtering not yet implemented |
| Human takeover | ⏳ Not yet — staff cannot join a live chat session and respond directly |
| Suggested replies | ⏳ Not yet — no Gemini-drafted reply for staff to approve |
| Customer email replies | ⏳ Not yet — no SMTP/IMAP integration |

### Phase 6 — Analytics (In Progress 🔄)

| Feature | Detail |
|---|---|
| Dashboard metrics | ✅ Sessions, messages, escalations, answer rate, average confidence |
| Daily session volume | ✅ Sessions per day over a configurable window |
| Unanswered question report | ✅ Questions followed by a low-confidence bot answer |
| Top pages | ✅ Pages customers were viewing when they opened chat |
| Top topics | ⏳ Not yet — clustering questions into topics |
| Product click tracking | ⏳ Not yet — which product cards customers click from chat |
| Knowledge gap detection | ⏳ Not yet — cluster unanswered questions to suggest new entries |
| Retention controls | ⏳ Not yet — admin UI to delete old chat logs and tracking data |
| Export | ⏳ Not yet — CSV export of chat logs, unanswered questions, clicks |

---

## Tech Stack

| Layer | Choice | Reason |
|---|---|---|
| Web server | Caddy 2 | Automatic HTTPS (Let's Encrypt), zero-config TLS, PHP-FPM proxy |
| Language | PHP 8.2 | No runtime install, fast FPM, native cURL, OpenSSL, PDO |
| Database | SQLite 3 (FTS5) | Zero-config, WAL mode, built-in full-text search, no server process |
| AI — chat | Gemini 1.5 Flash (default) | Fast, cheap, 1M token context, configurable |
| AI — extraction | Gemini 1.5 Pro (default) | Multimodal: reads PDF, DOCX, images natively via API |
| Frontend | Vanilla HTML/CSS/JS | No build toolchain, no npm, embeds with one script tag |
| Deployment | Debian 12 VPS | Stable LTS, `apt` packages, simple systemd services |

**Deliberately excluded:** Node.js, React, pip/Python, npm, Docker, Redis, Elasticsearch.

---

## One-Line Install

On a **fresh Debian 12 VPS** as root, with DNS for `chat.blake-uk.com` pointing to the server:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/BlakeUK/Blake-AI-Chatbot/main/install.sh)
```

Custom domain:

```bash
DOMAIN=chat.example.com bash <(curl -fsSL https://raw.githubusercontent.com/BlakeUK/Blake-AI-Chatbot/main/install.sh)
```

**What the installer does:**
1. Updates system packages
2. Installs PHP 8.2-FPM + extensions (sqlite3, curl, mbstring, xml, intl, fileinfo)
3. Installs SQLite3, Git, Caddy (via official Cloudsmith repo)
4. Clones this repo to `/var/www/chat`
5. Initialises SQLite database (schema + widget tables)
6. Auto-generates a 32-byte AES encryption key and writes `config/config.php`
7. Configures Caddy with HTTPS
8. Starts `caddy` and `php8.2-fpm` via systemd

**Idempotent** — safe to re-run on an existing install (pulls latest code, skips DB/config if they exist).

---

## Manual Setup

### Prerequisites
- Debian 12 VPS (1 vCPU / 1 GB RAM minimum recommended)
- Root SSH access
- DNS A record: `chat.blake-uk.com` → VPS IP address

### Step 1 — Clone
```bash
git clone https://github.com/BlakeUK/Blake-AI-Chatbot.git /var/www/chat
```

### Step 2 — Install dependencies
```bash
apt-get update
apt-get install -y php8.2-fpm php8.2-sqlite3 php8.2-curl php8.2-mbstring \
    php8.2-xml php8.2-intl php8.2-fileinfo sqlite3 git curl gnupg \
    debian-keyring debian-archive-keyring apt-transport-https

# Caddy
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | \
    gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | \
    tee /etc/apt/sources.list.d/caddy-stable.list
apt-get update && apt-get install -y caddy
```

### Step 3 — Initialise database
```bash
mkdir -p /var/www/chat/{data,uploads,logs,config}
sqlite3 /var/www/chat/data/chatbot.db < /var/www/chat/scripts/schema.sql
sqlite3 /var/www/chat/data/chatbot.db < /var/www/chat/scripts/schema_widget.sql
sqlite3 /var/www/chat/data/chatbot.db < /var/www/chat/scripts/schema_append.sql
sqlite3 /var/www/chat/data/chatbot.db < /var/www/chat/scripts/schema_fts_triggers.sql
sqlite3 /var/www/chat/data/chatbot.db < /var/www/chat/scripts/schema_2fa.sql
sqlite3 /var/www/chat/data/chatbot.db < /var/www/chat/scripts/schema_import_queue.sql
chown -R www-data:www-data /var/www/chat
chmod 750 /var/www/chat/config
chmod 770 /var/www/chat/{data,uploads,logs}
```
All six files must run, in this order — `schema_append.sql` adds the `settings`
table (model selection breaks without it), `schema_fts_triggers.sql` keeps the
full-text search index in sync (knowledge/product search returns nothing
without it), and `schema_2fa.sql` adds the columns `login.php` queries on
every login attempt (login breaks without it). `install.sh` and
`scripts/setup_server.sh` already run all six automatically — this manual
list only matters if you're initialising the database by hand.

### Step 4 — Configure
```bash
cp /var/www/chat/config/config.example.php /var/www/chat/config/config.php

# Generate encryption key
php -r "echo bin2hex(random_bytes(32));"
# Paste output into config.php encrypt_key value

nano /var/www/chat/config/config.php
```

### Step 5 — Caddy
```bash
cp /var/www/chat/Caddyfile /etc/caddy/Caddyfile
systemctl enable caddy php8.2-fpm
systemctl restart caddy php8.2-fpm
```

### Step 6 — Create admin user
```bash
php /var/www/chat/scripts/create_admin.php admin 'YourStrongPassword'
```

---

## Admin Interface

Visit `https://chat.blake-uk.com/admin/` and log in.

### Tabs

| Tab | Purpose |
|---|---|
| **Dashboard** | Live counts: knowledge entries, indexed files, products, sessions. Recent chat table. |
| **Knowledge** | Create / delete manual text knowledge entries. Instantly FTS5-indexed into RAG. |
| **Files / RAG** | Upload PDFs, images, DOCX, CSV etc. Gemini extracts text, chunks it, indexes it. |
| **Products** | Import JSON or XML product feed. Searchable product table. Import result summary. |
| **API Keys** | Store Gemini and carrier keys (AES-256-GCM encrypted, never shown after save). |
| **Model Settings** | Fetch live Gemini model list from Google API. Set chat and extraction models. |
| **Widget Clients** | Create external API clients. Lock to IP addresses and/or origin domains. |
| **Users** | Add staff accounts, assign admin/editor/user roles, reset a user's 2FA. |
| **Chat Logs** | Browse all customer sessions; tap into any conversation to review it and correct bad bot answers. |
| **Support Tickets** | Escalated conversations customers asked to raise a ticket for — filter by status, add notes, mark resolved. |
| **My Account** | Change your own password; enable/disable 2FA with a scannable QR code and backup codes. |

### First-run checklist

- [ ] Log in at `/admin/`
- [ ] **API Keys** → paste Gemini key → Save
- [ ] **Model Settings** → Refresh → select models → Save
- [ ] **Knowledge** → add your first entry (e.g. delivery policy)
- [ ] **Files / RAG** → upload product PDFs, datasheets, manuals
- [ ] **Products** → upload your JSON/XML product feed

---

## Mobile Apps (Android)

Two native Android apps (Flutter, not a WebView wrapper) talk to the same backend as the web admin panel and widget — no separate setup required.

| App | What it's for |
|---|---|
| **Blake UK Admin** | The full admin panel on your phone — login with 2FA, dashboard, knowledge base, files, products, API keys, model settings, widget clients, users, chat logs, support tickets, my account. |
| **Blake UK Support** | The customer-facing chat widget as a standalone app — chat, product cards, order tracking, support ticket escalation. |

**Download the latest build:**

- 📱 [Blake UK Admin (`.apk`)](https://github.com/BlakeUK/Blake-AI-Chatbot/releases/download/apk-latest/blake-uk-admin-app.apk)
- 📱 [Blake UK Support (`.apk`)](https://github.com/BlakeUK/Blake-AI-Chatbot/releases/download/apk-latest/blake-uk-customer-app.apk)

These links always point to the most recently built APKs — see the [`apk-latest` release](https://github.com/BlakeUK/Blake-AI-Chatbot/releases/tag/apk-latest) for build details. Since these aren't distributed via the Play Store, Android will ask you to allow "install from unknown sources" the first time.

Rebuilding: run the **Build Android APKs** workflow from the [Actions tab](https://github.com/BlakeUK/Blake-AI-Chatbot/actions/workflows/build-apk.yml) — it compiles both apps and updates the release above in place.

Source: [`mobile/admin_app`](mobile/admin_app) and [`mobile/customer_app`](mobile/customer_app).

---

## Embedding the Widget

### On blake-uk.com (direct embed)

Add before `</body>` on every page:

```html
<script src="https://chat.blake-uk.com/widget/chat.js" defer></script>
```

### Product page context

Add `data-` attributes near the product content so the widget knows which product the customer is viewing:

```html
<div data-product-code="BLAUUTP-0025M-WHITE" data-category="Networking"></div>
```

The widget automatically picks these up and sends them with every message, enabling product-aware answers.

### On an external website (API key method)

1. Admin → **Widget Clients** → create a client, set allowed IPs and allowed origin
2. Copy the API key shown **once** at creation
3. On the external site:

```html
<script>
window.BlakeUKWidget = {
  apiKey: 'buk_yourkey',
  endpoint: 'https://chat.blake-uk.com'
};
</script>
<script src="https://chat.blake-uk.com/widget/chat.js" defer></script>
```

---

## External Widget API

For server-side integrations or custom widget implementations.

### Token flow

```
POST /api/widget/init.php
Content-Type: application/json

{ "api_key": "buk_yourkey" }
```

Response:
```json
{ "token": "abc123...", "expires_in": 300 }
```

Use the token within 5 minutes to open a chat session at `/api/chat/session.php`.

### Security controls

| Control | Detail |
|---|---|
| IP allowlist | Per-client list of permitted server IPs. Supports CIDR notation (e.g. `192.168.0.0/24`) |
| Origin allowlist | Per-client list of permitted HTTP `Origin` header values |
| Rate limiting | 10 token requests per minute per IP |
| Token expiry | 5-minute expiry, single-use |
| Audit log | Every issuance, IP block, origin block and invalid key logged to `widget_access_log` |
| Key masking | API keys masked in all list endpoints — full key shown once at creation only |
| Key revocation | Instant revocation from admin UI; existing tokens expire naturally |

---

## Product Feed Import

### Via Admin UI

Admin → **Products** tab → drag-and-drop JSON or XML file.

Import result shows:

| Count | Meaning |
|---|---|
| Created | New products added to database |
| Updated | Existing products updated |
| Skipped | Records missing required fields (product_code, name) |
| Errors | Records that threw an exception (logged with product code) |

### Via API

```
POST /api/admin/import.php
X-CSRF-Token: <token from login>
Content-Type: multipart/form-data

feed=<file.json or file.xml>
```

### Required JSON format

```json
{
  "products": [
    {
      "product_code": "BLAUUTP-0025M-WHITE",
      "name": "0.25m White Slimline CAT6 Patch Lead",
      "url": "https://www.blake-uk.com/0-25m-white-slimline-cat6-patch-lead.html",
      "category_path": ["Networking", "Patch Leads", "CAT6"],
      "price": { "inc_vat": 1.72, "exc_vat": 1.43 },
      "stock": { "status": "in_stock" },
      "images": [{ "url": "https://www.blake-uk.com/images/products/BLAUUTP-0025M-WHITE.jpg", "alt": "White CAT6 patch lead" }],
      "summary_bullets": ["Efficient CAT6 networking", "Slim for tight spaces", "Gold-plated connectors"],
      "tech_specs": { "cable_category": "CAT6", "shielding": "U/UTP", "length_m": 0.25, "colour": "White" },
      "variants": [
        { "product_code": "BLAUUTP-0025M-BLACK", "attributes": { "colour": "Black" }, "url": "https://www.blake-uk.com/..." }
      ],
      "documents": [
        { "type": "manual", "title": "Instruction Manual", "url": "https://www.blake-uk.com/instruction-manuals.html" }
      ],
      "search_terms": ["cat6", "patch lead", "ethernet", "slimline", "network cable"]
    }
  ]
}
```

The importer tolerates alternative field naming conventions (`productCode`, `categoryPath`, `incVat`, etc.) and XML feeds from bespoke systems including FUSE-based e-commerce backends.

---

## Carrier Tracking

The bot detects tracking intent from natural language ("where is my order", "has it shipped") or a recognised tracking-number format, and:

1. Shows a form asking for the tracking number and delivery postcode
2. Auto-detects the carrier from the tracking number format if not already known
3. Queries the appropriate carrier API (Royal Mail, DPD, DX)
4. Returns delivery status and recent events in the chat

The postcode is currently collected but not yet cross-checked against order
data before the carrier is queried — see [Build Phases](#build-phases).

### Supported carriers

| Carrier | API | Status |
|---|---|---|
| Royal Mail / Post Office | Royal Mail Tracking API | ✅ Live |
| DPD | DPD Tracking API | ⚠️ Wired up on an unverified placeholder endpoint |
| DX | DX API | ⚠️ Wired up on an unverified placeholder endpoint |

API credentials stored encrypted in admin → API Keys.

---

## Support Tickets

When the bot cannot answer with sufficient confidence, or the customer asks to speak to a person, the conversation escalates to a support ticket:

- Ticket created from the chat session with full conversation history
- Appears in the admin ticket queue, filterable by status (open/pending/closed)
- Staff can add internal notes and correct bot answers, optionally promoting the correction straight to the knowledge base

Human takeover (staff replying to the customer directly), Gemini-drafted
suggested replies, and email-based ticket replies are not built yet — see
[Build Phases](#build-phases).

---

## File Structure

```
/
├── Caddyfile                   Web server config
├── LICENCE                     Proprietary licence + GDPR statement
├── README.md                   This file
├── install.sh                  One-line VPS installer
├── config/
│   └── config.example.php      Config template (copy to config.php)
├── data/                       SQLite database (gitignored)
├── docs/
│   ├── img/                    Screenshots for README
│   └── setup.md                Detailed setup guide
├── logs/                       Application logs (gitignored)
├── mobile/                     Native Android apps (Flutter)
│   ├── admin_app/               Full admin panel on your phone
│   └── customer_app/            Standalone customer chat app
├── public/                     Caddy web root
│   ├── admin/
│   │   ├── index.html           Admin single-page UI
│   │   └── vendor/qrcode.js     2FA QR code rendering
│   ├── api/
│   │   ├── admin/               Admin API endpoints (all require login; most require CSRF)
│   │   │   ├── account.php       Change own password
│   │   │   ├── analytics.php     Dashboard metrics, unanswered questions, top pages
│   │   │   ├── apikeys.php       Encrypted key storage
│   │   │   ├── chats.php         Chat session log + transcripts
│   │   │   ├── chunks.php        View/edit/delete extracted knowledge chunks
│   │   │   ├── clients.php       Widget client management
│   │   │   ├── corrections.php   Correct a bot answer; optionally promote to knowledge base
│   │   │   ├── discover_urls.php Find PDF links on a page/sitemap ahead of a bulk import
│   │   │   ├── files.php         File upload + delete
│   │   │   ├── import.php        Product feed import (JSON/XML)
│   │   │   ├── import_urls.php   Bulk file import by URL (queued, extracted by cron)
│   │   │   ├── knowledge.php     Knowledge CRUD + FTS index
│   │   │   ├── login.php         Admin auth (+ 2FA challenge)
│   │   │   ├── models.php        Live Gemini model list
│   │   │   ├── products.php      Product search/list
│   │   │   ├── reindex.php       Re-extract and re-index an existing file
│   │   │   ├── resolve.php       Resolve a hostname to an IP (widget client form helper)
│   │   │   ├── session.php       Check whether the browser already holds a login
│   │   │   ├── settings.php      Key-value settings (model selection)
│   │   │   ├── tickets.php       Support ticket queue, notes, status
│   │   │   ├── twofactor.php     Self-service 2FA enrol/confirm/disable
│   │   │   └── users.php         Manage admin panel users and roles
│   │   ├── chat/                 Public customer-facing chat endpoints
│   │   │   ├── escalate.php      Create a support ticket from a session
│   │   │   ├── send.php          Main RAG chat endpoint
│   │   │   ├── session.php       Session create/resume
│   │   │   └── track.php         Query a carrier tracking API
│   │   └── widget/
│   │       └── init.php          External widget token issuance
│   └── widget/
│       ├── chat.css            Widget styles
│       └── chat.js             Embeddable widget JS
├── scripts/
│   ├── create_admin.php        CLI: create admin user
│   ├── deploy_remote.sh        Runs on the VPS as part of the GitHub Actions deploy
│   ├── process_pending_files.php  Cron: extract any file left in 'pending' status
│   ├── schema.sql              Core SQLite schema
│   ├── schema_2fa.sql          Migration: 2FA columns on admin_users
│   ├── schema_append.sql       Migration: settings table
│   ├── schema_fts_triggers.sql Migration: FTS5 sync triggers
│   ├── schema_import_queue.sql Migration: source_url column for bulk URL imports
│   ├── schema_widget.sql       Migration: widget client tables
│   └── setup_server.sh         Manual server setup script
├── src/
│   ├── Auth/
│   │   ├── Admin.php            Session, CSRF, bcrypt login, roles
│   │   └── Totp.php             RFC 6238 TOTP (2FA), pure PHP
│   ├── Gemini/Client.php       Gemini API (flash + pro)
│   ├── Knowledge/
│   │   ├── FileExtractor.php   Gemini multimodal file extraction
│   │   └── Search.php          FTS5 search (knowledge + products)
│   ├── Products/
│   │   └── Importer.php        JSON/XML feed importer
│   ├── Tracking/
│   │   ├── Detector.php        Tracking intent + carrier detection from message text
│   │   └── Dispatcher.php      Routes a tracking query to the right carrier API
│   └── bootstrap.php           DB, autoload, CORS, rate limiting
└── uploads/                    Uploaded knowledge files (gitignored)
```

---

## Security

| Measure | Implementation |
|---|---|
| Admin passwords | bcrypt, cost factor 12 (`password_hash` / `password_verify`) |
| API key encryption | AES-256-GCM, random 12-byte IV, auth tag stored separately |
| CSRF protection | Token per session, validated on all state-changing admin operations |
| Rate limiting | SQLite sliding window: 20 req/min chat, 5 req/min admin login, 10 req/min widget |
| IP hashing | Customer IPs stored as SHA-256 hashes only — not reversible |
| Session cookies | `HttpOnly`, `Secure`, `SameSite=Strict` |
| File uploads | MIME-type validated server-side, 20 MB limit, random stored filename |
| CORS | Configurable origin allowlist; blocks all unlisted origins |
| Widget access | IP + origin allowlist per client, short-lived tokens, full audit trail |
| HTTPS | Caddy handles TLS automatically via Let's Encrypt |
| Headers | `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `X-Frame-Options` |

---

## GDPR (UK) Compliance

See [LICENCE](./LICENCE) Section 6 for the complete compliance statement.

| Obligation | How it is met |
|---|---|
| **Lawful basis** | Legitimate interests (Article 6(1)(f)) for customer support; consent where customer initiates chat |
| **Data minimisation** | Widget collects only: hashed IP, page URL, chat messages. No names/emails unless customer provides them. |
| **Retention** | Configurable retention controls (Phase 6). Admin can delete sessions and tracking data. |
| **Security** | AES-256-GCM keys, bcrypt passwords, CSRF, rate limiting, IP hashing, secure cookies |
| **No 3rd-party tracking** | `sessionStorage` only. No analytics, no ad pixels, no third-party scripts. |
| **International transfers** | Google Gemini API (USA). Blake UK Ltd must maintain appropriate transfer mechanism (SCCs / UK-US Data Bridge). |
| **Data subject rights** | Blake UK Ltd (Data Controller) handles access, erasure, rectification requests manually. |
| **Processor agreement** | Any third-party deployer must execute a DPA with Blake UK Ltd (UK GDPR Article 28). |
| **Privacy notice** | Blake UK Ltd must publish a Privacy Notice covering this processing: [blake-uk.com/gdpr.html](https://www.blake-uk.com/gdpr.html) |

---

## Build Phases

| Phase | Status | Summary |
|---|---|---|
| **1 — Core** | ✅ Complete | Chat widget, Gemini RAG, admin UI (11 tabs), file upload, API key storage, model selection, external widget API with IP locking |
| **2 — Products** | 🔄 In progress | JSON/XML feed import, product-aware chat, inline product cards, variant handling, admin product browser |
| **3 — Documents** | ✅ Mostly complete | Re-extraction, chunk viewer, manual overrides, scheduled queue processing. Not yet: document tagging, filename-based datasheet linking |
| **4 — Tracking** | ✅ Live | Royal Mail, DPD*, DX*; carrier auto-detect; tracking log. *DPD/DX use unverified placeholder endpoints. Postcode is collected but not yet verified against order data |
| **5 — Support** | 🔄 In progress | Ticket queue, correction workflow, staff notes, status tracking. Not yet: human takeover, suggested replies, email integration |
| **6 — Analytics** | 🔄 In progress | Dashboard metrics, daily volume, unanswered questions, top pages. Not yet: top topics, product click tracking, knowledge gap detection, retention controls, CSV export |

---

## Licence

**Proprietary — All Rights Reserved.**

© 2026 Blake UK Ltd. This software may not be copied, modified, distributed or used without the express written permission of Blake UK Ltd.

See [LICENCE](./LICENCE) for full terms and GDPR (UK) compliance documentation.

**Contact:** [blake-uk.com/contact-us.html](https://www.blake-uk.com/contact-us.html)
