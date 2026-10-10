# Deployment and operations

## Runtime and environment

Use PHP 8.2+ with PDO MySQL, mbstring, OpenSSL, XML, curl and fileinfo; Composer 2; MySQL 8+ (local validation also uses MariaDB 10.4). Serve only public/. Keep .env, APP_KEY, dumps and storage private. Preserve APP_KEY for encrypted credentials and webhook payloads.

Production requires APP_ENV=production, APP_DEBUG=false, public HTTPS APP_URL, dedicated MySQL credentials, SESSION_SECURE_COOKIE=true and real SMTP/application mail. Configure trusted proxies, security headers and restricted database/Redis networking. Never seed demo accounts or use local root credentials in production. Configure RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET, RAZORPAY_WEBHOOK_SECRET, BILLING_GRACE_DAYS and supported WHATSAPP_GRAPH_VERSION. Current billing accepts test keys only.

Deploy using composer install --no-dev --optimize-autoloader, php artisan migrate --force, php artisan optimize and php artisan queue:restart. Back up before migrations. No Node build is required.

## Queue and scheduler

Run php artisan queue:work --tries=3 --timeout=90 and php artisan schedule:work locally. Production needs supervised workers and minute cron:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Defaults use database queues/cache. Redis is supported by setting QUEUE_CONNECTION=redis, CACHE_STORE=redis and REDIS_CLIENT=phpredis, with the service/extension configured.

Growth:tick creates due reminders, dispatches scheduled campaigns/workflows and generates cadence-based reports. Scheduler overlap is locked. Processed webhook payloads expire after 30 days; broader retention/deletion remains pending. Inspect php artisan queue:failed before retrying. Outbound message jobs have one attempt: ambiguous provider acceptance must not cause duplicate sends. Confirm remote state before recovering unknown messages.

## Provider setup and webhooks

Owners/managers configure encrypted credentials in Integrations. AI requires an available model, positive input/output price estimates and a positive monthly budget. Exact approved FAQ auto-replies are opt-in; generated responses remain drafts.

Resend requires a verified sender domain and permissions for sending and required domain-read/receiving operations. Set SPF/DKIM through the provider and a suitable DMARC policy. Imports do not grant consent. Ensure unsubscribe URLs use public HTTPS APP_URL.

WhatsApp requires official Cloud API business/phone IDs, token, Meta app secret and verification token. Sync approved templates. Parameterized templates are blocked pending mapping support. Human and automated replies share consent/window checks.

Configure URLs displayed on each integration:

- https://your-domain/webhooks/integration/{publicId} for that business's WhatsApp or Resend integration. WhatsApp GET verifies connection; POST checks Meta HMAC. Resend checks Svix signatures and timestamp tolerance.
- https://your-domain/webhooks/razorpay for Razorpay subscription/payment events. Use a separate webhook secret. Plans need matching real Razorpay test plan IDs; interval, amount and currency are verified server-side.

Only webhooks/* is CSRF-exempt. Preserve raw request bodies through proxies. Payloads are encrypted, failures record safe errors and event IDs are processed once. Never log credentials or full customer payloads.

Team/Admin generate private invitation URLs for authorized operators to deliver; automatic invitation email is not implemented. Password recovery uses Laravel's broker. Local MAIL_MAILER=log writes reset links to local logs; replace it before public deployment.

## Backup, restore and local database

Back up MySQL transactionally, private documents/reports and encryption keys. Encrypt backups, restrict access and keep off-host copies with defined retention:

```console
mysqldump --single-transaction --routines --triggers -u backup_user -p growthdesk > growthdesk.sql
```

Use interactive passwords or restricted option files. Restore into an isolated app/database, check migrations, counts, tenant permissions, private downloads and decryption. Document restore duration. A production restore exercise remains a release gate.

This workspace runs a separate local MariaDB on port 3307 under storage/mysql-runtime because C: is full, with temporary files under storage/mysql-temp. Existing records were copied without deleting the XAMPP database. Runtime files and dumps are ignored by version control. README contains the restart command.

## Monitoring and release

/up is process health, not provider readiness. Monitor database/storage, queue age/failures, webhook errors, provider connections, subscription reconciliation and backup age. Reconcile ambiguous subscriptions before retrying checkout. Payment documents are receipts, not tax invoices.

Before production: validate real sandbox sends/payments and replay, disable demos, configure mail/HTTPS, complete privacy/retention and remaining billing flows, review credential privileges, test restores and establish incident monitoring. README lists scope limits.
