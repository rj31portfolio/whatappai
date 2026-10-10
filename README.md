# GrowthDesk

Laravel 12, PHP 8.2+, Blade/Livewire 4 and MySQL tenant-isolated business CRM. The original request is preserved in `txt`. The existing dashboard theme is retained.

## Working panels

| Panel | Implemented behavior |
| --- | --- |
| Overview / CRM | Measured KPIs; contact CRUD, tags, custom fields, assignment, duplicate checks and explainable scoring; company editing; pipeline deals; tasks, appointments and shared reminders |
| Shared inbox | Email/WhatsApp histories, assignment, status, human handover, reviewed drafts, queued replies and delivery states |
| Email campaigns | Draft editing, reusable text templates, consent-aware segments, scheduling, cancellation, CSV import, suppression and signed unsubscribe flows |
| Integrations | Encrypted per-business OpenAI, DeepSeek, Claude, Resend and official WhatsApp Cloud credentials; real connection tests and disconnected states |
| AI knowledge / assistant | Approved FAQs, products, services, policies and text documents; lexical retrieval, unknown-question handover, budget reservations, explicit fallback and usage estimates |
| Automations | Visual step editor with triggers, score conditions, delays, task/message actions; bounded execution, snapshots, replay protection and execution history |
| Analytics | Measured source, score, pipeline and message counts; estimated AI costs; private CSV exports and scheduled report archives |
| Team / account / API | Expiring private invitations, roles, last-owner protection, password changes/recovery and revocable tenant-bound Sanctum bearer tokens |
| Billing | Razorpay test subscriptions, server reconciliation and signed webhooks, payment receipts, cancellation and entitlement enforcement |
| Super Admin | Business provisioning/suspension, user suspension, plan creation, feature flags, maintenance controls and aggregate health/error visibility |

Protected actions check server permissions. Provider requests are real HTTP calls; missing credentials do not produce simulated success. Demo campaigns/workflows are inactive. No external messages or payments were sent during development.

## Run locally

Open **http://127.0.0.1:8000** after starting these processes in separate terminals:

```console
php artisan serve --host=127.0.0.1 --port=8000
php artisan queue:work --tries=3 --timeout=90
php artisan schedule:work
```

This workspace is installed and seeded. Its `.env` connects to **127.0.0.1:3307**. Because C: is full, a separate local MariaDB instance was initialized under `storage/mysql-runtime`, with temporary files under `storage/mysql-temp`. Existing records were copied; the original XAMPP database was preserved. Restart this local database on Windows with:

```powershell
Start-Process 'C:\xampp\mysql\bin\mysqld.exe' -ArgumentList '--defaults-file=D:\aitool\storage\mysql-runtime\my.ini','--bind-address=127.0.0.1','--port=3307','--tmpdir=D:\aitool\storage\mysql-temp' -WindowStyle Hidden
```

For a fresh install: install Composer dependencies, copy `.env.example` to `.env`, configure MySQL and create database `growthdesk` with utf8mb4. Generate `APP_KEY` only for a new installation, then run `php artisan migrate --seed`. Preserve the existing key when retaining encrypted credentials. No frontend build is required.

## Demo access

All demo accounts use **`DemoPass!2026`**.

| Email | Access |
| --- | --- |
| owner@demo.test | Business Owner in both businesses |
| agency@demo.test | Agency Owner in both businesses |
| manager@demo.test | Business Manager in Acme |
| sales@demo.test | Sales Agent in Acme |
| marketing@demo.test | Marketing Manager in Acme |
| analyst@demo.test | Read-only Analyst in Acme |
| education@demo.test | Owner in BrightPath only |
| admin@demo.test | Super Admin; no implicit access to private business data |

Fixtures include two businesses, companies, contacts, deals, follow-ups, fictional approved knowledge, draft campaigns, paused workflows and plans. The guarded seeder refuses production. Use demos only in a local/test database.

## Configure integrations

Open Integrations as an owner/manager, save credentials and run connection tests. Configure AI selection, model prices, positive monthly budget, reply mode, sending hours and limits in Settings. Consent flags alone do not permit sending: record channel-specific evidence in Campaigns. WhatsApp free-text replies require an open customer service window; otherwise an approved supported template is required.

Set Razorpay test environment variables and create matching provider plans through Super Admin. Live payment keys are intentionally rejected. Configure application mail for password recovery; the local default logs reset notifications. Queued actions require the worker and scheduler. Public HTTPS webhooks and sender authentication are described in [Operations](docs/OPERATIONS.md).

## Validation

```console
php artisan test
php vendor/bin/pint --test
php artisan view:cache
```

Fast tests use isolated SQLite. Create `growthdesk_testing` before running `php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml`; this resets only the test database, and a guard rejects other MySQL database names. Browser checks use installed Chrome: `npm ci`, then `npm run test:e2e` against local demo data. See [Validation](docs/VALIDATION.md).

## Scope and release limits

This is a functional development implementation with remaining production work. Billing covers test subscriptions/cancellation; coupons, setup fees, plan changes and tax invoices remain. Email content is text with basic segmentation. WhatsApp sync handles the first 100 templates and blocks parameterized templates. Knowledge supports UTF-8 text/Markdown and lexical retrieval; PDF/OCR, vector search and live crawling remain. Scheduled summaries are downloadable report archives. Advanced attribution, complete privacy deletion/retention, MFA, global provider credentials and a production restore exercise remain release work. Live provider delivery and paid checkout require credentialed sandbox verification.

See [Architecture](docs/ARCHITECTURE.md) and [Operations](docs/OPERATIONS.md).
