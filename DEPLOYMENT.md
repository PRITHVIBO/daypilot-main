# Deploy DayPilot on the existing Hostinger website

The production repository is `PRITHVIBO/daypilot-main`, branch `main`, as confirmed by the owner. The application uses PHP, JavaScript, CSS, HTML, and the existing MySQL database. No Node.js server or new hosting plan is required.

Follow [CONNECTED-DASHBOARD.md](CONNECTED-DASHBOARD.md) for the upgrade, tracker connections and background jobs. Keep the existing `config.local.php`, MySQL data, VAPID keys and configured AI provider. The upgrade supports the original Gemini configuration and the optional OpenRouter configuration; it does not require replacing either provider's secret.

Use the existing Hostinger Git deployment. The repository workflows validate code; they do not upload over FTP. Confirm that the deployed version reads **2.4.0**, sign in, save the Connections watchlist, and verify the first GitHub refresh.

Do not import `database.sql` over a live database. It is a schema for fresh installations and tests. Existing installations receive additive work-memory and integration tables without replacing existing users, tasks or notes. If automatic CREATE is unavailable, run the additive migrations through the hosting environment before opening the new dashboard.

Run `cron/connections.php` hourly and keep `cron/reminders.php` on its existing five-minute schedule, using the actual document-root path. Enable scheduled refreshes in DayPilot and device notifications in Settings. A saved cron entry is not proof of delivery: verify a fresh repository snapshot and a test reminder after deployment.
