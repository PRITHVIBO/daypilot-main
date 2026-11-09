# DayPilot 2.4 — connected project dashboard

This update targets `PRITHVIBO/daypilot-main`, the repository selected in Hostinger. It upgrades the older 1.0.3 repository to the connected 2.4 interface and includes the missing work-memory runtime used by the live 2.3.3 site. Existing Gemini settings and server-only configuration remain supported.

This update runs on the existing PHP + MySQL host. The interface uses plain JavaScript, CSS and HTML; Node is used only for development checks, not on Hostinger.

## Deployment

1. Keep the existing `config.local.php`, database, users, tasks and notes. Deploy this version through the existing Hostinger Git connection. Do not import `database.sql` over production or replace server secrets.
2. Import only `migrations/003_connections.sql` using phpMyAdmin. It creates four new tables without changing existing ones. The API also creates missing integration tables when the database user has CREATE permission.
3. Sign in and open **Connections**. Save the GitHub owner and repository watchlist, then run the first refresh. The PHP connection reads public repository metadata, default-branch commits and the latest Actions run. GitHub credentials connected to ChatGPT are not exported to this server, and this version does not read private repositories.
4. Add an hourly Hostinger cron job: `php /FULL/PATH/TO/DAYPILOT/cron/connections.php`. Replace the placeholder with the actual server path. Select **Enable scheduled GitHub refreshes and build alerts** in DayPilot for each account that should be checked.
5. Keep the existing five-minute `cron/reminders.php` job. Device push requires the existing VAPID configuration, Composer dependencies, browser permission and a saved subscription. The new job creates deduplicated reminders for recent failed builds and overdue tasks; it does not prove that push delivery is configured.

The server refresh is limited to once per account per hour, with up to eight selected repositories. Anonymous GitHub API limits are shared by the host. Errors retain prior snapshots and appear in the connection status. A partial commit/build read is labeled unavailable rather than presented as a healthy empty result.

## Connect the existing trackers

Deploy the companion connector changes to DSA Intelligencia, QueryQuest, StackForge and CloudLab. On the same browser and origin where existing progress is saved:

1. Open the tracker over HTTPS or localhost and choose **Connect DayPilot**.
2. Copy its displayed origin into DayPilot’s connection form and choose the matching tracker.
3. Create a connection, then copy its ingestion URL, ID and one-time-displayed token into the tracker.
4. Choose **Save and send summary**. Optional automatic sync checks for changed totals once a minute while the tracker remains open.

If the tracker URL changes, export the existing full backup before moving and import it at the new URL. Browser local storage is specific to its origin/profile; GitHub does not contain that learning history.

Only counts for total/completed/in-progress items, attempts, logged minutes and due reviews are accepted. Tokens are hashed at rest, scoped to an account/source/origin, expire after 90 days, and can be revoked. Saved code, SQL, notes and full question records remain in the tracker. Summary sync is not a full backup or two-way solution synchronization.

## Read the graphs correctly

- Repository activity counts up to 100 recent default-branch commits per repository across 30 days. It is not a time-spent or productivity measure.
- Learning history is made of recorded daily snapshots from connected trackers. Existing historical days are not reconstructed or filled with invented values.
- A saved summary becomes stale after two days; a stored snapshot is distinct from a source that is currently reachable.
- The task queue and project controls use the existing authenticated DayPilot database. Accounts remain isolated.

## Validation

GitHub Actions runs PHP/JavaScript syntax checks, summary/private-field tests, a regression check for production API action URLs, origin/metric/alert validation, and HTTP tests against a disposable MySQL database. The HTTP tests cover authentication, CSRF, token/source/origin checks, cross-account isolation, revocation, and stale-summary rejection. Production database writes and push delivery still require deployment verification on the real host.

## Existing database upgrades

The compatibility tests initialize the original daypilot-main database schema, retain an existing account/task/note, then add the work-memory and integration tables. New tables inherit the account table’s collation so foreign keys remain compatible. No existing table is dropped, replaced, or emptied.
