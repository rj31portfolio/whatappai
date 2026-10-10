# Deployment and operations

Phase 1 is a foundation, not a completed production SaaS. External APIs, password recovery, team administration, subscription enforcement and retention jobs are deliberately deferred in ARCHITECTURE.md.

## Environment

Use PHP 8.2+ with PDO MySQL, mbstring, OpenSSL, ctype, XML, tokenizer, curl, fileinfo; Composer 2; MySQL 8.0+ (the local validation environment uses compatible MariaDB 10.4). Serve only `public/`. No Node build is needed for the current self-contained dashboard CSS; Livewire serves its own assets. Keep `.env` outside version control; retain `APP_KEY` securely across deployments and restores.

Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-domain`, correct MySQL credentials, `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`. Use a dedicated database user with schema rights only during migration, then reduce rights for runtime. Do not use the local root account in production. Enable HTTPS, trust only your actual proxy, restrict database/Redis network access, and configure web-server security headers. Install dependencies with `composer install --no-dev --optimize-autoloader`; run `php artisan migrate --force`, `php artisan optimize` and `php artisan view:cache` during deployment. Do not seed demo data in production.

## Workers and scheduler

Default queue and cache drivers use the database. For Redis set `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_CLIENT=phpredis` and configure Redis + the PHP extension. Use supervised `php artisan queue:work --tries=3 --timeout=90` processes and restart workers using `php artisan queue:restart` after deployments. Phase 1 does not enqueue external sends. Review `php artisan queue:failed`; retry only after understanding the cause and confirming idempotency. Add cron every minute: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`. No business automation schedules are active yet.

## Webhooks and secrets

There are no active provider webhook endpoints yet. Planned URLs are `/webhooks/whatsapp`, `/webhooks/email/{provider}` and `/webhooks/razorpay`; do not configure them until the implementing phase passes signature/idempotency tests. Future integrations must use server-side encrypted credentials, short timeouts, retry limits, signature verification on the original raw bytes, and explicit disconnected states. Never exempt ordinary web routes from CSRF.

## Backup and restore

Schedule encrypted database backups, store off-host with restricted access and defined retention. Example: `mysqldump --single-transaction --routines --triggers -u backup_user -p growthdesk > growthdesk.sql`. Supply credentials interactively or via a restricted option file, never command history. Back up private storage plus `APP_KEY` through a secrets vault. Restore into an isolated database: create the database, `mysql -u restore_user -p growthdesk_restore < growthdesk.sql`, point a separate app instance to it, run migration status, verify record counts and tenant access tests, then document the restore time. Test restores regularly. Database-only backups will be insufficient once document uploads are implemented.

## Monitoring and security

Use `/up` for process health; it is not an integration or database readiness check. Monitor web errors, database connectivity, failed jobs, storage space, slow queries and backup age. Audit screens show action metadata only. Avoid logging contact contents, passwords, tokens or provider payloads. Sessions expire per Laravel configuration; logout invalidates the session. Membership revocation takes effect on the next request. Phase 1 has no account self-registration; provision production owners through a controlled administrative process until invitation flows are delivered.

Run `php artisan test` for fast SQLite tests, and the documented isolated MySQL run before releases. Never point RefreshDatabase tests at the application database. Disable all demo accounts in production. Release gates include password recovery, MFA policy, retention/deletion, real integration tests, queue replay safety, and a backup restore exercise.
