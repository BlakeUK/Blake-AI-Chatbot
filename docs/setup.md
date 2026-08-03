# Server Setup Guide

## Prerequisites
- Debian 12 VPS
- Root or sudo access
- Domain DNS: chat.blakegroup.uk → VPS IP

## Steps

### 1. Clone repo to server
```bash
git clone https://github.com/BlakeUK/Blake-AI-Chatbot.git /var/www/chat
```

### 2. Run setup script
```bash
bash /var/www/chat/scripts/setup_server.sh
```
Note the encryption key printed at the end.

### 3. Create config
```bash
cp /var/www/chat/config/config.example.php /var/www/chat/config/config.php
nano /var/www/chat/config/config.php
```
- Set `encrypt_key` to the value from step 2
- Verify paths are correct

### 4. Initialise database
```bash
sqlite3 /var/www/chat/data/chatbot.db < /var/www/chat/scripts/schema.sql
```

### 5. Create admin user
```bash
php /var/www/chat/scripts/create_admin.php admin YourStrongPassword
```

### 6. Store Gemini API key via admin UI
- Visit https://chat.blakegroup.uk/admin/
- Log in
- Go to API Settings → add service `gemini` with your key

### 7. Embed widget on blake-uk.com
Add before `</body>`:
```html
<script src="https://chat.blakegroup.uk/widget/chat.js" defer></script>
```

For product pages, add data attributes for context:
```html
<div data-product-code="BLAUUTP-0025M-WHITE" data-category="Networking">
```

## File permissions
```bash
chown -R www-data:www-data /var/www/chat/data /var/www/chat/uploads /var/www/chat/logs
chmod 750 /var/www/chat/config
```
