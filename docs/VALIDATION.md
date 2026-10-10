# Validation

The suite covers tenant/database isolation, permission revocation, Livewire, CRM persistence, bearer token abilities, encryption, provider contracts, grounding/budgets/fallback, consent/suppression, send-time checks, messaging windows, signed webhook replay, campaigns, workflow snapshots/delays, invitations, password recovery, reminders, test billing and entitlement enforcement.

```console
php artisan test
php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml
php vendor/bin/pint --test
php artisan view:cache
composer validate --strict
npm run test:e2e
```

MySQL tests reset growthdesk_testing only; TestCase rejects other MySQL database names. Never use application or production databases. Provider contract tests fake HTTP responses and prevent stray requests. Runtime integrations use real adapters; automated checks do not prove live credentials, delivery or checkout.

Chrome checks cover login, Livewire search, tenant switching, analyst controls, CSRF, mobile overflow, owner panels, workflow controls and Super Admin. Screenshots are stored under ignored test-results/.

On this Windows machine C: is full; temporary/cache directories on D: are required for some commands:

```powershell
$env:TEMP='D:\aitool\storage\mysql-temp'
$env:TMP='D:\aitool\storage\mysql-temp'
$env:NPM_CONFIG_CACHE='D:\aitool\storage\npm-cache'
php -d sys_temp_dir=D:\aitool\storage\mysql-temp vendor\bin\pint --test
node node_modules\@playwright\test\cli.js test
```

Use installed Chrome or set PLAYWRIGHT_CHANNEL=msedge. Fresh CI requires a browser installation. Development verification: 44 automated tests with 272 assertions; four Chrome browser scenarios.
