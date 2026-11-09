# DayPilot 2.4 — Connected Work Dashboard

The new **Overview** and **Connections** screens connect GitHub project health and the existing DSA, SQL, web, and cloud trackers. See [CONNECTED-DASHBOARD.md](CONNECTED-DASHBOARD.md) for Hostinger deployment, scoped tracker connections, graphs, and scheduled alerts.

DayPilot is an offline-first PWA for daily work capture, tasks, planning, calendar, notes, reminders, analytics, AI assistance and personal work memory. The 2.1 release hardens a RAG-oriented memory layer so daily brain dumps, work logs, decisions, blockers, learning notes, projects and daily reviews can be retrieved as context for future planning and questions.

## What 2.0 adds

- Responsive desktop + mobile workspace with a mobile bottom navigation.
- Brain Dump / Daily Capture for unstructured updates.
- Work logs with types: work log, brain dump, blocker, decision, learning and daily summary.
- Project-aware memory and project workspace.
- Daily review with local deterministic fallback plus AI generation when an online provider is available.
- RAG memory index using chunked personal records.
- Keyword + recency retrieval by default, with optional OpenRouter free embeddings for semantic retrieval.
- Resume context: recent work is surfaced as the "where I stopped" starting point.
- AI assistant receives current tasks/events/notes plus relevant memory context.
- AI tools for creating work logs and searching memory in addition to task/calendar actions.
- Offline IndexedDB caching and sync for tasks and work logs.
- Cross-device server storage through PHP + MySQL.
- OpenRouter multi-model routing → local deterministic fallback orchestration.
- PWA installability, ICS calendar export and optional Web Push reminders.

## Architecture

```text
Browser / PWA
    │
    ├── IndexedDB (offline cache + outbox)
    │
    └── api.php
          │
          ├── PHP session/auth + CSRF
          ├── MySQL (tasks, events, notes, projects, work_logs, reviews, memory)
          └── AI orchestrator
                ├── OpenRouter
                ├── OpenRouter free path
                └── local deterministic fallback

Memory flow
    Capture → chunk → optional embedding → store → retrieve → AI context → plan/review
```

## Local Docker development

1. Install Docker Desktop.
2. Copy `.env.example` to `.env` and add AI keys only when needed.
3. Run `docker compose up --build`.
4. Open `http://localhost:8081`.

MySQL initializes from `database.sql` on the first container boot.

## Production on Hostinger

The intended production path is:

```text
GitHub main
   ↓
Hostinger native Git deployment
   ↓
public_html
   ↓
https://projects.bhavyagupta.space
```

FTP credentials are not required for the production deployment path.

### Database migration

`database.sql` contains the additive 2.0 tables:

- `projects`
- `work_logs`
- `daily_reviews`
- `memory_chunks`

The API also has a small first-run schema check for authenticated requests, but importing `database.sql` through Hostinger phpMyAdmin is still the preferred deployment step.

### Server-only configuration

Create `config.local.php` on Hostinger and keep it out of Git. Configure the existing DB credentials and any AI/OpenRouter/VAPID values there. Do not put secrets into frontend JavaScript.

PHP 8.2+ is recommended, with `pdo_mysql`, `curl`, `openssl` and `mbstring`. Composer dependencies are required for Web Push.

### Hostinger checklist

1. Import `database.sql` in phpMyAdmin.
2. Configure `config.local.php` on the server.
3. Point Hostinger Git deployment at `PRITHVIBO/daypilot-main`, branch `main`, deployment root `public_html`.
4. Make sure Composer dependencies are installed on the Hostinger environment or included by your deployment process.
5. Keep HTTPS enabled.
6. Configure the reminder cron for `cron/reminders.php` when using Web Push.
7. Log in once on desktop and on the phone/PWA so each device establishes its own offline cache.

## AI and RAG

DayPilot's AI calls go through PHP. The browser does not call the provider directly.

The assistant gets:

- current open tasks;
- upcoming events;
- projects;
- recent work logs;
- recent activity;
- notes;
- retrieved memory relevant to the user's request.

Semantic retrieval is opt-in because embedding requests consume provider quota. The Memory screen has **Build semantic memory** to explicitly create embeddings. Keyword + recency retrieval works without an embedding call.

The default embedding model is `liquid/lfm-2.5-embedding-350m:free` at 768 dimensions. The text generation model defaults to `OpenRouter free models`.

## Munder Difflin development workflow

Munder Difflin is a local development/orchestration layer for the DayPilot source tree; it is not part of the Hostinger runtime.

```text
Munder Difflin
  ├── Product / planning
  ├── Frontend
  ├── Backend
  ├── AI / RAG
  ├── QA / Security
  └── DevOps
             ↓
         GitHub main
             ↓
       Hostinger Git
```

## Security reminders

Never commit:

- `config.local.php`
- `.env`
- database passwords
- OpenRouter API key (optional; local fallback still works)
- VAPID private keys
- other provider secrets
