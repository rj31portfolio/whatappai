# Phase 1 validation

Validated locally on PHP 8.2.12, Laravel 12.69.3, Livewire 4.4.7 and XAMPP MariaDB 10.4.32 using the MySQL driver. MySQL 8 is the production target; a MySQL 8 server has not been exercised in this workspace.

Results: 19 PHP tests / 134 assertions passed on both SQLite and MariaDB; 2 Chrome browser tests passed; Pint, Composer validation and Blade view caching passed.

Automated coverage includes authentication/logout/throttling, tenant-scoped pages, cross-tenant URLs and forged session selection, explicit agency/business switching and revoked membership, six-role permissions, Super Admin without private business access, contact create/update/delete, deterministic scoring and consent defaults, duplicate email and phone format validation, cross-tenant company/contact relationships, database composite foreign keys, deals and won dashboard totals, task timezone conversion and idempotent completion, audit writes, isolated CSV exports and spreadsheet formula safety, fail-closed queries and context cleanup, escaped Livewire search, real Livewire HTTP membership checks, and repeatable demo seeding.

Browser coverage in installed Chrome checks real Livewire POST updates, filtering, business switching, a 390px viewport without page overflow, analyst control visibility, and CSRF rejection. Desktop and mobile screenshots are generated under `test-results/`. UI was visually inspected from both screenshots. Browser tests require local demo data.

Commands: `php artisan test`; `php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml`; `php vendor/bin/pint --test`; `npm run test:e2e`; `php artisan view:cache`; `composer validate --no-check-publish`. Composer dependency installation reported no security vulnerability advisories after upgrading to Livewire 4.4.7.

No external API tests were run because provider implementations are deferred. Production integration, load, restore, full accessibility and security reviews remain release gates in the architecture plan.
