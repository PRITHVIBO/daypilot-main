# DayPilot Deployment Checklist

## 1. GitHub
Create a GitHub repository such as `daypilot` and push the project. The repository should contain the PHP/PWA source, Docker local-dev files and `.github/workflows/`.

```bash
git init
git add .
git commit -m "Build DayPilot cross-device AI assistant"
git branch -M main
git remote add origin https://github.com/YOUR_USERNAME/daypilot.git
git push -u origin main
```

## 2. Hostinger database
Create a MySQL database and database user in Hostinger. Import `database.sql` in phpMyAdmin.

## 3. Hostinger config
Copy `config.local.example.php` to `config.local.php` on the server and fill in:
- Hostinger MySQL hostname, database name, username, password.
- Gemini API key.
- VAPID subject/public/private key.
- Production base URL such as `https://daypilot.example.com`.

`config.local.php` is protected by `.htaccess` and ignored by Git.

## 4. Dependencies
DayPilot uses `minishlink/web-push` 11.x. It requires PHP 8.2+ and curl, mbstring and OpenSSL. The included GitHub Actions workflow runs Composer and deploys the resulting `vendor/` directory.

## 5. GitHub repository secrets
Set:
- `FTP_SERVER`
- `FTP_USERNAME`
- `FTP_PASSWORD`
- `FTP_SERVER_DIR`

The deployment workflow runs on pushes to `main`. It uses FTPS and does not overwrite `config.php` or `config.local.php`.

## 6. HTTPS
Enable HTTPS on the DayPilot domain before using the PWA service worker or push notifications.

## 7. Push keys
After Composer has installed dependencies, generate VAPID keys once:

```bash
php -r "require 'vendor/autoload.php'; print_r(Minishlink\\WebPush\\VAPID::createVapidKeys());"
```

Store the values in `config.local.php`. Reuse the same keys.

## 8. Reminder cron
Create a Hostinger Cron Job every 5 minutes:

```bash
php /home/ACCOUNT/domains/YOUR_DOMAIN/public_html/cron/reminders.php
```

This processes due reminders and sends Web Push notifications to subscribed devices.

## 9. Mobile
Open the public HTTPS DayPilot URL on Android/iOS, sign in once, enable notifications, then install the PWA from the browser. Data is synchronized through the server. Offline task changes are stored in IndexedDB and queued for synchronization when connectivity returns.

## 10. Calendar
The built-in calendar, scheduled task blocks and `.ics` export work without external credentials. `.ics` files can be imported into Google Calendar, Apple Calendar or Outlook. A provider-specific OAuth integration can be added later without changing the core task/planning model.


## Production deployment
Hostinger Git deployment is the production deployment path. The GitHub Actions workflow validates the repository only; it does not upload over FTP. Keep `config.local.php` server-only and configure Hostinger MySQL/Gemini/VAPID credentials there.
